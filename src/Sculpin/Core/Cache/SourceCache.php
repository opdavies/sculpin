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

namespace Sculpin\Core\Cache;

use Sculpin\Core\Source\SourceInterface;

/**
 * A persistent per-source cache for converted and formatted output.
 *
 * Every entry is keyed by the source's own state (data plus raw content) and
 * by a generation token that covers everything shared across sources: the
 * site configuration, the Twig template files, and a fingerprint of the data
 * providers. When the generation token or a source's key changes, its cache
 * entries stop matching and the source is reprocessed.
 *
 * This means any change to any source (or template, or config) invalidates
 * every entry in the current implementation: a conservative choice that
 * trades granularity for correctness. Even so, rebuilds that changed
 * nothing (continuous integration runs, config or asset-only changes) skip
 * conversion and formatting entirely.
 *
 * The cache is opt in via --cache-dir; with no directory set it does
 * nothing.
 */
final class SourceCache
{
    private const int FORMAT_VERSION = 1;
    private const int GENERATION_LENGTH = 12;

    private ?string $generation = null;
    private ?array $entries = null;
    private bool $dirty = false;

    /**
     * @param ?string $cacheDir Cache directory, null disables the cache.
     * @param \Closure $generationHash Computes the generation token.
     */
    public function __construct(
        private readonly ?string $cacheDir,
        private readonly \Closure $generationHash,
    ) {}

    public function enabled(): bool
    {
        return $this->cacheDir !== null;
    }

    /**
     * The generation token, lazily computed once per run.
     */
    public function generation(): string
    {
        if ($this->generation === null) {
            $hash = ($this->generationHash)();

            $this->generation = hash('sha256', self::FORMAT_VERSION . '|' . $hash);

            if (is_file($this->file())) {
                $contents = unserialize((string) file_get_contents($this->file()));

                if (is_array($contents)
                    && ($contents['version'] ?? 0) === self::FORMAT_VERSION
                    && ($contents['generation'] ?? '') === $this->generation
                    && isset($contents['entries'])
                    && is_array($contents['entries'])
                ) {
                    $this->entries = $contents['entries'];
                }
            }
        }

        return substr($this->generation, 0, self::GENERATION_LENGTH);
    }

    /**
     * The per-source cache key: its data and raw content.
     */
    public function keyFor(SourceInterface $source, ?string $rawContent): string
    {
        return hash('sha256', self::canonicalize($source->data()->export()) . ($rawContent ?? ''));
    }

    /**
     * A deterministic textual form of config/array data that may contain
     * objects. Non-scalar values are represented by their class (and, where
     * meaningful, string form), so hashing never depends on serialization.
     */
    public static function canonicalize(mixed $value, int $depth = 0): string
    {
        if ($depth > 12) {
            return '<max-depth>';
        }

        if (is_array($value)) {
            $parts = [];

            foreach ($value as $k => $v) {
                $parts[] = '[' . var_export($k, true) . '=>' . self::canonicalize($v, $depth + 1) . ']';
            }

            return '[' . implode(',', $parts) . ']';
        }

        if (is_object($value)) {
            $asString = $value instanceof \Stringable ? ':' . (string) $value : '';

            return get_class($value) . $asString;
        }

        if (is_resource($value)) {
            return 'resource';
        }

        return var_export($value, true);
    }

    /**
     * @param mixed $blocks
     * @param mixed $content
     */
    public function rememberBlocks(string $sourceId, string $key, $content, $blocks): void
    {
        if (!$this->enabled()) {
            return;
        }

        $entries = &$this->entries();

        $entry = $entries[$sourceId] ?? [];

        $entry['key'] = $key;
        $entry['content'] = $content;
        $entry['blocks'] = $blocks;

        $entries[$sourceId] = $entry;
        $this->dirty = true;
    }

    /**
     * @param mixed $formatted
     */
    public function rememberFormatted(string $sourceId, string $key, $formatted): void
    {
        if (!$this->enabled()) {
            return;
        }

        $entries = &$this->entries();

        $entry = $entries[$sourceId] ?? [];

        $entry['formatted_key'] = $key;
        $entry['formatted'] = $formatted;

        $entries[$sourceId] = $entry;
        $this->dirty = true;
    }

    /**
     * @return ?array{content: mixed, blocks: mixed}
     */
    public function blocks(string $sourceId, string $key): ?array
    {
        // Ensures the previous run's entries are loaded before looking up.
        $this->generation();

        $entry = $this->entries()[$sourceId] ?? null;

        if (!is_array($entry) || ($entry['key'] ?? null) !== $key) {
            return null;
        }

        if (!array_key_exists('blocks', $entry)) {
            return null;
        }

        return ['content' => $entry['content'], 'blocks' => $entry['blocks']];
    }

    /**
     * @return mixed
     */
    public function formatted(string $sourceId, string $key)
    {
        // Ensures the previous run's entries are loaded before looking up.
        $this->generation();

        $entry = $this->entries()[$sourceId] ?? null;

        if (!is_array($entry) || ($entry['formatted_key'] ?? null) !== $key) {
            return false;
        }

        return array_key_exists('formatted', $entry) ? $entry['formatted'] : false;
    }

    /**
     * Write the cache if anything was stored.
     */
    public function persist(): void
    {
        if (!$this->enabled() || !$this->dirty) {
            return;
        }

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        // The full generation hash, not the display substring, is stored:
        // the load path compares against the full hash.
        $generation = $this->generation();

        file_put_contents(
            $this->file(),
            serialize([
                'version' => self::FORMAT_VERSION,
                'generation' => $this->generation,
                'entries' => $this->entries(),
            ])
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function &entries(): array
    {
        // Returned by reference so that remember*() can append in place.
        $this->entries ??= [];

        return $this->entries;
    }

    private function file(): string
    {
        return $this->cacheDir . '/source-cache.bin';
    }
}