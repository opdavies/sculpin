<?php

declare(strict_types=1);

/*
 * PHPBench subjects for the performance series: skip-unchanged output
 * writes, parallel processing (--jobs) and the persistent per-source
 * cache (--cache-dir).
 *
 * Every subject runs a real `sculpin generate` as a subprocess over a
 * throwaway fixture site, set up in before methods so the setup is
 * not measured. The fixture is generated per run (deterministic, no
 * dynamic dates), or copied from BENCH_FIXTURE when set.
 *
 * Both binaries default to this repository's bin/sculpin, so the
 * plain run measures the branch:
 *
 *     vendor/bin/phpbench run benchmarks --report=aggregate
 *
 * For comparison runs against a build without the series, point
 * BENCH_BASELINE at another sculpin binary (and BENCH_PATCHED at
 * this one); the matrix then measures both:
 *
 * - boot*: process + kernel startup (`--version`), the constant the
 *   generate subjects carry.
 * - firstRun*: a generate into a clean site, all output written.
 * - repeatRun*: a generate over an existing, identical build: the
 *   baseline rewrites every output, the patched run skips the
 *   unchanged writes.
 * - changedRun*: the development loop -- one post edited, then a
 *   regenerate.
 * - jobsRun*: a first run with --jobs=4. The baseline has no --jobs
 *   option.
 * - cacheRun*: a generate with a warm --cache-dir and a clean output
 *   directory, so every output is written but the converts are served
 *   from the persistent per-source cache.
 * - bestRun*: a repeat run with --jobs=4 and a warm --cache-dir: the
 *   series combined.
 */

use PhpBench\Attributes\AfterMethods;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\OutputTimeUnit;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Subject;

final class SculpinBench
{
    private string $siteDir = '';

    private function binary(string $environment): string
    {
        $value = getenv($environment);

        if ($value === false || $value === '') {
            return dirname(__DIR__) . '/bin/sculpin';
        }

        return $value;
    }

    private function baseline(): string
    {
        return $this->binary('BENCH_BASELINE');
    }

    private function patched(): string
    {
        return $this->binary('BENCH_PATCHED');
    }

    private function cacheDir(): string
    {
        return $this->siteDir . '/.bench-cache';
    }

    private function generate(string $bin, array $extraArgs = []): void
    {
        $args = array_merge(
            ['generate', '--env=prod', '-q', '--project-dir=' . $this->siteDir],
            $extraArgs,
        );

        $this->execSculpin($bin, $args);
    }

    private function execSculpin(string $bin, array $args): void
    {
        $command = implode(' ', array_map('escapeshellarg', array_merge([PHP_BINARY, $bin], $args)));

        exec($command . ' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException(implode("\n", $output));
        }
    }

    private function copyTree(string $from, string $to): void
    {
        if (!mkdir($to) && !is_dir($to)) {
            throw new RuntimeException("Cannot create {$to}");
        }

        $items = new FilesystemIterator($from, FilesystemIterator::SKIP_DOTS);

        foreach ($items as $item) {
            $destination = $to . '/' . $item->getBasename();

            if ($item->isDir()) {
                $this->copyTree($item->getPathname(), $destination);
            } else {
                copy($item->getPathname(), $destination);
            }
        }
    }

    private function removeTree(string $dir): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }

    /**
     * Before hooks.
     */

    public function makeSite(): void
    {
        $this->siteDir = sys_get_temp_dir() . '/sculpin-bench-' . uniqid('', false);

        $fixture = getenv('BENCH_FIXTURE');

        if ($fixture !== false && $fixture !== '') {
            $this->copyTree($fixture, $this->siteDir);

            return;
        }

        require_once __DIR__ . '/fixture.php';

        sculpin_bench_fixture($this->siteDir, 400);
    }

    public function makeSiteAndPrimeBaseline(): void
    {
        $this->makeSite();
        $this->generate($this->baseline());
    }

    public function makeSiteAndPrimePatched(): void
    {
        $this->makeSite();
        $this->generate($this->patched());
    }

    public function makeSitePrimeBaselineAndEdit(): void
    {
        $this->makeSiteAndPrimeBaseline();
        $this->editOnePost();
    }

    public function makeSitePrimePatchedAndEdit(): void
    {
        $this->makeSiteAndPrimePatched();
        $this->editOnePost();
    }

    public function makeSiteAndPrimeCache(): void
    {
        $this->makeSite();
        $this->generate($this->patched(), ['--cache-dir=' . $this->cacheDir()]);

        // Every output is rewritten on the measured run; only the
        // converts come from the cache.
        $this->removeTree($this->siteDir . '/output_prod');
    }

    public function makeSiteAndPrimeBest(): void
    {
        $this->makeSite();
        $this->generate($this->patched(), ['--jobs=4', '--cache-dir=' . $this->cacheDir()]);
    }

    private function editOnePost(): void
    {
        $posts = glob($this->siteDir . '/source/_posts/*post-200.md');

        if ($posts === false || $posts === []) {
            throw new RuntimeException('Cannot find the post to edit.');
        }

        file_put_contents(
            $posts[0],
            file_get_contents($posts[0]) . "\nAn edit for the incremental run.\n",
        );
    }

    /**
     * After hook.
     */

    public function removeSite(): void
    {
        if ($this->siteDir !== '' && is_dir($this->siteDir)) {
            $this->removeTree($this->siteDir);
        }

        $this->siteDir = '';
    }

    /**
     * Subjects.
     */

    #[Subject]
    #[Revs(5)]
    #[Iterations(3)]
    #[OutputTimeUnit('seconds', 3)]
    public function bootBaseline(): void
    {
        $this->execSculpin($this->baseline(), ['--version']);
    }

    #[Subject]
    #[Revs(5)]
    #[Iterations(3)]
    #[OutputTimeUnit('seconds', 3)]
    public function bootPatched(): void
    {
        $this->execSculpin($this->patched(), ['--version']);
    }

    #[Subject]
    #[BeforeMethods(['makeSite'])]
    #[AfterMethods(['removeSite'])]
    #[Revs(1)]
    #[Iterations(3)]
    #[OutputTimeUnit('seconds', 3)]
    public function firstRunBaseline(): void
    {
        $this->generate($this->baseline());
    }

    #[Subject]
    #[BeforeMethods(['makeSite'])]
    #[AfterMethods(['removeSite'])]
    #[Revs(1)]
    #[Iterations(3)]
    #[OutputTimeUnit('seconds', 3)]
    public function firstRunPatched(): void
    {
        $this->generate($this->patched());
    }

    #[Subject]
    #[BeforeMethods(['makeSiteAndPrimeBaseline'])]
    #[AfterMethods(['removeSite'])]
    #[Revs(1)]
    #[Iterations(5)]
    #[OutputTimeUnit('seconds', 3)]
    public function repeatRunBaseline(): void
    {
        $this->generate($this->baseline());
    }

    #[Subject]
    #[BeforeMethods(['makeSiteAndPrimePatched'])]
    #[AfterMethods(['removeSite'])]
    #[Revs(1)]
    #[Iterations(5)]
    #[OutputTimeUnit('seconds', 3)]
    public function repeatRunPatched(): void
    {
        $this->generate($this->patched());
    }

    #[Subject]
    #[BeforeMethods(['makeSitePrimeBaselineAndEdit'])]
    #[AfterMethods(['removeSite'])]
    #[Revs(1)]
    #[Iterations(5)]
    #[OutputTimeUnit('seconds', 3)]
    public function changedRunBaseline(): void
    {
        $this->generate($this->baseline());
    }

    #[Subject]
    #[BeforeMethods(['makeSitePrimePatchedAndEdit'])]
    #[AfterMethods(['removeSite'])]
    #[Revs(1)]
    #[Iterations(5)]
    #[OutputTimeUnit('seconds', 3)]
    public function changedRunPatched(): void
    {
        $this->generate($this->patched());
    }

    #[Subject]
    #[BeforeMethods(['makeSite'])]
    #[AfterMethods(['removeSite'])]
    #[Revs(1)]
    #[Iterations(3)]
    #[OutputTimeUnit('seconds', 3)]
    public function jobsRunPatched(): void
    {
        $this->generate($this->patched(), ['--jobs=4']);
    }

    #[Subject]
    #[BeforeMethods(['makeSiteAndPrimeCache'])]
    #[AfterMethods(['removeSite'])]
    #[Revs(1)]
    #[Iterations(5)]
    #[OutputTimeUnit('seconds', 3)]
    public function cacheRunPatched(): void
    {
        $this->generate($this->patched(), ['--cache-dir=' . $this->cacheDir()]);
    }

    #[Subject]
    #[BeforeMethods(['makeSiteAndPrimeBest'])]
    #[AfterMethods(['removeSite'])]
    #[Revs(1)]
    #[Iterations(5)]
    #[OutputTimeUnit('seconds', 3)]
    public function bestRunPatched(): void
    {
        $this->generate($this->patched(), ['--jobs=4', '--cache-dir=' . $this->cacheDir()]);
    }
}
