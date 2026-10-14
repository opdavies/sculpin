<?php

declare(strict_types=1);

/*
 * This file is a part of Sculpin.
 *
 * (c) Dragonfly Development Inc.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sculpin\Core;

use Sculpin\Core\Cache\SourceCache;
use Sculpin\Core\Converter\ConverterManager;
use Sculpin\Core\Event\SourceSetEvent;
use Sculpin\Core\Formatter\FormatterManager;
use Sculpin\Core\Generator\GeneratorManager;
use Sculpin\Core\Io\IoInterface;
use Sculpin\Core\Io\NullIo;
use Sculpin\Core\Output\SourceOutput;
use Sculpin\Core\Output\WriterInterface;
use Sculpin\Core\Permalink\SourcePermalinkFactoryInterface;
use Sculpin\Core\Source\DataSourceInterface;
use Sculpin\Core\Source\SourceInterface;
use Sculpin\Core\Source\SourceSet;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Main entry point to interact with the Sculpin system.
 *
 * @author Beau Simensen <beau@dflydev.com>
 */
final readonly class Sculpin
{
    public const string EVENT_BEFORE_RUN = 'sculpin.core.before_run';
    public const string EVENT_AFTER_RUN = 'sculpin.core.after_run';
    public const string EVENT_AFTER_GENERATE = 'sculpin.core.after_generate';
    public const string EVENT_BEFORE_CONVERT = 'sculpin.core.before_convert';
    public const string EVENT_AFTER_CONVERT = 'sculpin.core.after_convert';
    public const string EVENT_BEFORE_FORMAT = 'sculpin.core.before_format';
    public const string EVENT_AFTER_FORMAT = 'sculpin.core.after_format';

    /**
     * Sources below this count are always processed serially; the fork
     * overhead would outweigh the parallelism.
     */
    private const int PARALLEL_MINIMUM_SOURCES = 64;

    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private SourcePermalinkFactoryInterface $permalinkFactory,
        private WriterInterface $writer,
        private GeneratorManager $generatorManager,
        private FormatterManager $formatterManager,
        private ConverterManager $converterManager
    ) {
    }

    public function run(DataSourceInterface $dataSource, SourceSet $sourceSet, ?IoInterface $io = null, ?int $jobs = null, ?string $cacheDir = null): void
    {
        $io ??= new NullIo();
        $startTime = microtime(true);
        $dataSource->refresh($sourceSet);

        $sourceCache = $cacheDir !== null
            ? new SourceCache($cacheDir, fn (): string => $this->cacheGeneration())
            : null;

        $found = false;

        $this
            ->sendEvent($sourceSet, self::EVENT_BEFORE_RUN)
            ->generatePhase($sourceSet, $io, $found)
            ->permalinkPhase($sourceSet)
            ->sendEvent($sourceSet, self::EVENT_AFTER_GENERATE)
            ->convertPhase($sourceSet, $io, $found, $jobs, $sourceCache)
            ->formatPhase($sourceSet, $io, $found, $jobs, $sourceCache)
            ->writeOutputPhase($sourceSet, $io)
            ->sendEvent($sourceSet, self::EVENT_AFTER_RUN)
        ;

        $sourceCache?->persist();

        if ($found) {
            $io->write(
                sprintf(
                    'Processing completed in %4.2f seconds',
                    microtime(true) - $startTime
                )
            );
        }
    }

    /**
     * @param SourceSet $sourceSet
     * @param string $eventName
     * @return Sculpin
     */
    protected function sendEvent(SourceSet $sourceSet, string $eventName): self
    {
        $this->eventDispatcher->dispatch(new SourceSetEvent($sourceSet), $eventName);

        return $this;
    }

    /**
     * @param SourceSet $sourceSet
     * @param NullIo|IoInterface|null $io
     * @param bool $found
     * @return Sculpin
     */
    protected function generatePhase(SourceSet $sourceSet, NullIo|IoInterface|null $io, bool &$found): self
    {
        $updatedSources = array_filter(
            $sourceSet->updatedSources(),
            fn(SourceInterface $source): bool => !$source->isGenerated()
        );

        if (!$updatedSources) {
            return $this;
        }

        $found = true;

        $io->write('Detected new or updated files (Generate Phase)');
        $total = count($updatedSources);

        $io->write('Generating: ', false);
        $io->write('', false);
        $counter = 0;
        $timer = microtime(true);

        foreach ($updatedSources as $source) {
            $this->generatorManager->generate($source, $sourceSet);
            $io->overwrite(sprintf('%3d%%', 100 * ((++$counter) / $total)), false);
        }

        $io->write(sprintf(' (%d sources / %4.2f seconds)', $total, microtime(true) - $timer));

        return $this;
    }

    /**
     * @param SourceSet $sourceSet
     * @return Sculpin
     */
    protected function permalinkPhase(SourceSet $sourceSet): self
    {
        foreach ($sourceSet->updatedSources() as $source) {
            $permalink = $this->permalinkFactory->create($source);
            $source->setPermalink($permalink);
            $source->data()->set('url', $permalink->relativeUrlPath());
            $source->data()->set('relative_pathname', $source->relativePathname());
            $source->data()->set('filename', $source->filename());
        }

        return $this;
    }

    /**
     * @param SourceSet $sourceSet
     * @param NullIo|IoInterface|null $io
     * @param bool $found
     * @param int|null $jobs
     * @return Sculpin
     */
    protected function convertPhase(SourceSet $sourceSet, NullIo|IoInterface|null $io, bool &$found, ?int $jobs, ?SourceCache $sourceCache = null): self
    {
        $updatedSources = $sourceSet->updatedSources();

        if (!$updatedSources) {
            return $this;
        }

        if (!$found) {
            $io->write('Detected new or updated files (Convert Phase)');
            $found = true;
        }

        $total = count($updatedSources);

        $io->write('Converting: ', false);
        $io->write('', false);
        $timer = microtime(true);

        // Load the previous run's entries in the parent process so that
        // workers inherit them through copy-on-write.
        $sourceCache?->generation();

        // Cached blocks reuse a previous run's converted content and block
        // rendering. Only hand back content when a converter changed it, so
        // static assets keep their useFileReference behaviour untouched.
        // Misses carry their cache key back so the parent can remember them:
        // worker processes cannot persist cache state.
        $convert = function (SourceInterface $source) use ($sourceCache): array {
            $key = $sourceCache?->keyFor($source, $source->content());

            $cached = $key !== null ? $sourceCache->blocks($source->sourceId(), $key) : null;

            if (is_array($cached)) {
                return $cached;
            }

            $originalContent = $source->content();

            $this->converterManager->convertSource($source);

            $result = [];

            if ($source->content() !== $originalContent) {
                $result['content'] = $source->content();
            }

            if ($source->canBeFormatted()) {
                $result['blocks'] = $this->formatterManager->formatSourceBlocks($source);
            }

            if ($key !== null) {
                $result['cacheKey'] = $key;
            }

            return $result;
        };

        $results = $this->forEachSource($sourceSet, $convert, $io, $total, $jobs);
        $this->applyResults($sourceSet, $results, 'blocks');

        // Workers cannot persist cache state, so misses are remembered here.
        if ($sourceCache !== null) {
            foreach ($results as $result) {
                if (isset($result['cacheKey'])) {
                    $sourceCache->rememberBlocks(
                        $result['sourceId'],
                        $result['cacheKey'],
                        $result['content'] ?? null,
                        $result['blocks'] ?? null,
                    );
                }
            }
        }

        $io->write(sprintf(' (%d sources / %4.2f seconds)', $total, microtime(true) - $timer));

        return $this;
    }

    /**
     * After formatting, dispatches the 'AFTER_FORMAT' event.
     *
     * @param SourceSet $sourceSet
     * @param NullIo|IoInterface|null $io
     * @param bool $found
     * @param int|null $jobs
     * @return Sculpin
     */
    protected function formatPhase(SourceSet $sourceSet, NullIo|IoInterface|null $io, bool &$found, ?int $jobs, ?SourceCache $sourceCache = null): self
    {
        $updatedSources = $sourceSet->updatedSources();

        if (!$updatedSources) {
            return $this;
        }

        if (!$found) {
            $io->write('Detected new or updated files (Format Phase');
            $found = true;
        }

        $total = count($updatedSources);

        $io->write('Formatting: ', false);
        $io->write('', false);
        $timer = microtime(true);

        $sourceCache?->generation();

        $format = function (SourceInterface $source) use ($sourceCache): array {
            $key = $sourceCache?->keyFor($source, $source->content());

            if ($key !== null) {
                $cached = $sourceCache->formatted($source->sourceId(), $key);

                if ($cached !== false) {
                    return ['formatted' => $cached];
                }
            }

            $formatted = $source->canBeFormatted()
                ? $this->formatterManager->formatSourcePage($source)
                : $source->content();

            $result = ['formatted' => $formatted];

            if ($key !== null) {
                $result['cacheKey'] = $key;
            }

            return $result;
        };

        $results = $this->forEachSource($sourceSet, $format, $io, $total, $jobs);
        $this->applyResults($sourceSet, $results, 'formatted');

        if ($sourceCache !== null) {
            foreach ($results as $result) {
                if (isset($result['cacheKey'])) {
                    $sourceCache->rememberFormatted($result['sourceId'], $result['cacheKey'], $result['formatted']);
                }
            }
        }

        $this->eventDispatcher->dispatch(new SourceSetEvent($sourceSet), self::EVENT_AFTER_FORMAT);
        $io->write(sprintf(' (%d sources / %4.2f seconds)', $total, microtime(true) - $timer));

        return $this;
    }

    /**
     * Apply per-source results back onto the source set, keyed by source ID.
     *
     * @param array<string, array<string, mixed>> $results
     */
    private function applyResults(SourceSet $sourceSet, array $results, string $mode): void
    {
        $sources = [];

        foreach ($sourceSet->updatedSources() as $source) {
            $sources[$source->sourceId()] = $source;
        }

        foreach ($results as $result) {
            $source = $sources[$result['sourceId']] ?? null;

            if (!$source instanceof SourceInterface) {
                continue;
            }

            if (isset($result['content'])) {
                $source->setContent($result['content']);
            }

            if ($mode === 'blocks' && array_key_exists('blocks', $result) && $result['blocks'] !== null) {
                $source->data()->set('blocks', $result['blocks']);
            }

            if ($mode === 'formatted' && array_key_exists('formatted', $result)) {
                $source->setFormattedContent($result['formatted']);
            }
        }
    }

    /**
     * Process every updated source with the given per-source callable,
     * optionally across worker processes.
     *
     * Workers receive copies of the in-memory source set (copy on write) and
     * return their per-source results, so any state that would normally be
     * mutated on the source must be included in the result. This means event
     * listeners that mutate sources during convert events are not reflected
     * in parallel mode.
     */
    private function forEachSource(SourceSet $sourceSet, callable $worker, NullIo|IoInterface|null $io, int $total, ?int $jobs): array
    {
        $sources = array_values($sourceSet->updatedSources());

        if (($jobs ?? 1) <= 1 || count($sources) < self::PARALLEL_MINIMUM_SOURCES) {
            $results = [];

            foreach ($sources as $source) {
                $results[$source->sourceId()] = ['sourceId' => $source->sourceId()] + $worker($source);
            }

            return $results;
        }

        return $this->forEachSourceInWorkers($sources, $worker, $io, $total, (int) $jobs);
    }

    /**
     * @param list<SourceInterface> $sources
     */
    private function forEachSourceInWorkers(array $sources, callable $worker, NullIo|IoInterface|null $io, int $total, int $jobs): array
    {
        $chunks = array_chunk($sources, max(1, (int) ceil(count($sources) / $jobs)));

        // Fork all workers first so they run concurrently, then collect.
        $workers = [];

        foreach (array_keys($chunks) as $index) {
            $workers[$index] = $this->spawnWorker($chunks[$index], $worker);
        }

        $results = [];

        foreach ($workers as $index => $worker) {
            $results += $this->collectWorker($worker['pid'], $worker['stream']);
        }

        $this->reapWorkers();

        return $results;
    }

    /**
     * Spawn a worker for the given chunk of sources and return its process
     * ID and the parent-side stream.
     *
     * @param list<SourceInterface> $chunk
     * @return array{pid: int, stream: resource}
     */
    private function spawnWorker(array $chunk, callable $worker): array
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            throw new \RuntimeException(
                'Parallel processing requires the pcntl and posix extensions.'
            );
        }

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($pair === false) {
            throw new \RuntimeException('Unable to create stream pair for parallel processing.');
        }

        [$parentRead, $childWrite] = $pair;

        $pid = pcntl_fork();

        if ($pid === -1) {
            fclose($parentRead);
            fclose($childWrite);
            throw new \RuntimeException('Unable to fork for parallel processing.');
        }

        if ($pid === 0) {
            // Child: process the chunk and stream the results to the parent.
            fclose($parentRead);
            $results = [];
            $failed = false;

            try {
                foreach ($chunk as $source) {
                    $results[$source->sourceId()] = ['sourceId' => $source->sourceId()] + $worker($source);
                }
            } catch (\Throwable) {
                $failed = true;
            }

            if (!$failed) {
                $payload = serialize($results);

                fwrite($childWrite, pack('J', strlen($payload)));

                $offset = 0;

                while ($offset < strlen($payload)) {
                    $written = fwrite($childWrite, substr($payload, $offset));

                    if ($written === false || $written === 0) {
                        $failed = true;

                        break;
                    }

                    $offset += $written;
                }
            }

            fclose($childWrite);
            posix_kill(posix_getpid(), SIGKILL);
        }

        // Parent side.
        fclose($childWrite);

        return ['pid' => $pid, 'stream' => $parentRead];
    }

    /**
     * Read a worker's results and verify the worker exited cleanly.
     *
     * @return array<string, array<string, mixed>>
     */
    private function collectWorker(int $pid, $stream): array
    {
        $size = self::readExactly($stream, 8);
        $payload = self::readExactly($stream, unpack('J', $size)[1]);
        fclose($stream);

        pcntl_waitpid($pid, $status);

        if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
            // Workers kill themselves with SIGKILL once their payload is
            // handed over, which shows up as a non-zero exit.
            if (!pcntl_wifsignaled($status) || pcntl_wtermsig($status) !== SIGKILL) {
                throw new \RuntimeException('A parallel worker failed unexpectedly.');
            }
        }

        /** @var array<string, array<string, mixed>>|false $results */
        $results = unserialize($payload);

        if (!is_array($results)) {
            throw new \RuntimeException('Worker returned an unexpected result payload.');
        }

        return $results;
    }

    private static function readExactly($socket, int $length): string
    {
        $buffer = '';

        while (strlen($buffer) < $length) {
            $data = fread($socket, min(65536, $length - strlen($buffer)));

            if ($data === false || $data === '') {
                throw new \RuntimeException('Worker terminated without returning results.');
            }

            $buffer .= $data;
        }

        return $buffer;
    }

    /**
     * Workers kill themselves with SIGKILL after handing over their results;
     * every child must be reaped so no zombies are left behind.
     */
    private function reapWorkers(): void
    {
        while (pcntl_waitpid(-1, $status) > 0) {
            // Collect workers until no children remain.
        }
    }

    /**
     * @param SourceSet $sourceSet
     * @param NullIo|IoInterface|null $io
     * @return Sculpin
     */
    /**
     * A token covering everything shared between sources: site configuration,
     * Twig template files, and the data provider collections.
     */
    private function cacheGeneration(): string
    {
        $stats = [];

        $formatter = $this->formatterManager->formatter($this->formatterManager->defaultFormatter());

        $twig = $formatter instanceof \Sculpin\Bundle\TwigBundle\TwigFormatter ? $formatter->twig() : null;

        if ($twig !== null && $twig->getLoader() instanceof \Twig\Loader\FilesystemLoader) {
            foreach ($twig->getLoader()->getPaths() as $path) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));

                foreach ($it as $file) {
                    /** @var \SplFileInfo $file */
                    if (str_ends_with((string) $file->getPathname(), '.twig')) {
                        $stats[] = [$file->getPathname(), $file->getMTime(), $file->getSize()];
                    }
                }
            }
        }

        $providers = [];

        if ($this->formatterManager->dataProviderManager() !== null) {
            foreach ($this->formatterManager->dataProviderManager()->dataProviders() as $name) {
                foreach ($this->formatterManager->dataProviderManager()->dataProvider($name)->provideData() as $index => $item) {
                    $fingerprint = [$name, $index];

                    if ($item instanceof SourceInterface) {
                        $fingerprint[] = $item->sourceId();
                        $fingerprint[] = md5(SourceCache::canonicalize($item->data()->export()));
                        $fingerprint[] = md5((string) $item->content());
                    } else {
                        $fingerprint[] = md5(SourceCache::canonicalize($item));
                    }

                    $providers[] = $fingerprint;
                }
            }
        }

        return hash('sha256', serialize([
            SourceCache::canonicalize($this->formatterManager->siteConfiguration()->export()),
            $stats,
            $providers,
        ]));
    }

    protected function writeOutputPhase(SourceSet $sourceSet, NullIo|IoInterface|null $io): self
    {
        foreach ($sourceSet->updatedSources() as $source) {
            if ($source->isGenerator()) {
                continue;
            }

            if ($source->shouldBeSkipped()) {
                continue;
            }

            $this->writer->write(new SourceOutput($source));

            if ($io->isVerbose()) {
                $io->write(' + ' . $source->sourceId());
            }
        }

        return $this;
    }
}