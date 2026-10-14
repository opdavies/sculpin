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

    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private SourcePermalinkFactoryInterface $permalinkFactory,
        private WriterInterface $writer,
        private GeneratorManager $generatorManager,
        private FormatterManager $formatterManager,
        private ConverterManager $converterManager
    ) {
    }

    public function run(DataSourceInterface $dataSource, SourceSet $sourceSet, ?IoInterface $io = null, ?string $cacheDir = null): void
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
            ->convertPhase($sourceSet, $io, $found, $sourceCache)
            ->formatPhase($sourceSet, $io, $found, $sourceCache)
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
     * @param SourceSet $sourceSet The list of all sources
     * @param NullIo|IoInterface|null $io Helper for writing/overwriting console output
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
     * @param SourceSet $sourceSet      The list of all sources
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
     * @return Sculpin
     */
    protected function convertPhase(SourceSet $sourceSet, NullIo|IoInterface|null $io, bool &$found, ?SourceCache $sourceCache = null): self
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
        $counter = 0;
        $timer = microtime(true);

        $convert = function (SourceInterface $source) use ($io, &$counter, $total, $sourceCache): array {
            $key = $sourceCache?->keyFor($source, $source->content());

            $cached = $key !== null ? $sourceCache->blocks($source->sourceId(), $key) : null;

            if ($cached !== null) {
                return $cached;
            }

            $rawContent = $source->content();
            $this->converterManager->convertSource($source);

            $blocks = $source->canBeFormatted()
                ? $this->formatterManager->formatSourceBlocks($source)
                : null;

            // Store converted content only when a converter changed it; a
            // null content means the raw content is still authoritative, so
            // static assets keep their useFileReference behaviour.
            $sourceCache?->rememberBlocks(
                $source->sourceId(),
                $key,
                $source->content() !== $rawContent ? $source->content() : null,
                $blocks,
            );

            $result = [];

            // Only hand back content when a converter changed it, so static
            // assets keep their useFileReference behaviour untouched.
            if ($source->content() !== $rawContent) {
                $result['content'] = $source->content();
            }

            $result['blocks'] = $blocks;

            return $result;
        };

        $results = [];

        foreach ($updatedSources as $source) {
            $results[$source->sourceId()] = ['sourceId' => $source->sourceId()] + $convert($source);
            $io->overwrite(sprintf('%3d%%', 100 * ((++$counter) / $total)), false);
        }

        $this->applyResults($sourceSet, $results, 'blocks');

        $io->write(sprintf(' (%d sources / %4.2f seconds)', $total, microtime(true) - $timer));

        return $this;
    }

    /**
     * After formatting, dispatches the 'AFTER_FORMAT' event.
     *
     * @param SourceSet $sourceSet
     * @param NullIo|IoInterface|null $io
     * @param bool $found
     * @return Sculpin
     */
    protected function formatPhase(SourceSet $sourceSet, NullIo|IoInterface|null $io, bool &$found, ?SourceCache $sourceCache = null): self
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
        $counter = 0;
        $timer = microtime(true);

        $formattedResults = [];

        foreach ($updatedSources as $source) {
            $key = $sourceCache?->keyFor($source, $source->content());

            if ($key !== null && ($formatted = $sourceCache->formatted($source->sourceId(), $key)) !== false) {
                $source->setFormattedContent($formatted);
            } else {
                $formatted = $source->canBeFormatted()
                    ? $this->formatterManager->formatSourcePage($source)
                    : $source->content();

                $sourceCache?->rememberFormatted($source->sourceId(), $key, $formatted);
                $source->setFormattedContent($formatted);
            }

            $io->overwrite(sprintf('%3d%%', 100 * ((++$counter) / $total)), false);
        }

        $this->eventDispatcher->dispatch(new SourceSetEvent($sourceSet), self::EVENT_AFTER_FORMAT);
        $io->write(sprintf(' (%d sources / %4.2f seconds)', $total, microtime(true) - $timer));

        return $this;
    }

    /**
     * Apply per-source results (from workers or cache) back onto the source
     * set, keyed by source ID.
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

            if ($mode === 'blocks') {
                if (isset($result['content'])) {
                    $source->setContent($result['content']);
                }

                if ($result['blocks'] !== null) {
                    $source->data()->set('blocks', $result['blocks']);
                }
            }
        }
    }

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
            $dataProviderManager = $this->formatterManager->dataProviderManager();

            foreach ($dataProviderManager->dataProviders() as $name) {
                foreach ($dataProviderManager->dataProvider($name)->provideData() as $index => $item) {
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

    /**
     * @param SourceSet $sourceSet
     * @param NullIo|IoInterface|null $io
     * @return Sculpin
     */
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
