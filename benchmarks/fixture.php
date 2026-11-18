<?php

declare(strict_types=1);

/*
 * Generates the deterministic Sculpin benchmark fixture: a site with
 * N markdown posts (default 400), a layout and an index page looping
 * the whole collection, with no dynamic dates, so every generation of
 * the same fixture produces byte-identical output -- which the
 * write-skipping and caching of the performance series rely on.
 *
 * Standalone usage: php benchmarks/fixture.php <target-dir> [count]
 */

function sculpin_bench_fixture(string $target, int $count): void
{
    $source = $target . '/source';

    foreach ([
        $source . '/_posts',
        $source . '/_layouts',
        $target . '/app/config',
    ] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            throw new RuntimeException("Cannot create {$directory}");
        }
    }

    file_put_contents(
        $target . '/app/config/sculpin_site.yaml',
        <<<YAML
            title: Sculpin Benchmark Site
            url: https://bench.example.com
            YAML,
    );

    file_put_contents(
        $source . '/_layouts/default.html.twig',
        <<<'TWIG'
            <!DOCTYPE html>
            <html>
              <head>
                <meta charset="utf-8">
                <title>{% block title %}{{ page.title }} | {{ site.title }}{% endblock %}</title>
              </head>
              <body>
                <header><a href="{{ site.url }}">{{ site.title }}</a></header>
                <main>{% block content %}{% endblock %}</main>
                <footer>Sculpin Benchmark Site</footer>
              </body>
            </html>
            TWIG,
    );

    file_put_contents(
        $source . '/index.html.twig',
        <<<'TWIG'
            ---
            layout: default
            title: Home
            ---
            <ul>
            {% for post in data.posts %}
              <li><a href="{{ post.url }}">{{ post.title }}</a></li>
            {% endfor %}
            </ul>
            TWIG,
    );

    for ($i = 1; $i <= $count; $i++) {
        $n = sprintf('%03d', $i);
        $month = (intdiv($i - 1, 100) % 12) + 1;
        $day = ($i % 28) + 1;

        $body = <<<MD
            ---
            title: Benchmark Post {$n}
            layout: default
            ---

            ## Section one for post {$n}

            This benchmark post exercises the converter and the formatter with
            **bold text**, *italic text*, [a link](https://example.com/post/{$n})
            and some `inline code`, so every run does realistic markdown work.

            - first list item for post {$n}
            - second list item mentioning post {$n} again
            - third list item with [another link](https://example.com/posts/{$n})

            ### A code block for post {$n}

            ```php
            function calculatePost{$n}Value(int \$multiplier): int
            {
                return {$n} * \$multiplier;
            }
            ```

            > A block quote for post {$n}: Lorem ipsum dolor sit amet,
            > consectetur adipiscing elit, sed do eiusmod tempor incididunt ut
            > labore et dolore magna aliqua.

            Second paragraph for post {$n}: ut enim ad minim veniam, quis
            nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo
            consequat. Duis aute irure dolor in reprehenderit in voluptate
            velit esse cillum dolore eu fugiat nulla pariatur.

            | Column one | Column two |
            | --- | --- |
            | value for post {$n} | another value for post {$n} |

            Final line of benchmark post {$n}, with a closing paragraph to pad
            the body of the file for a realistic per-source size.
            MD;

        file_put_contents(
            sprintf('%s/_posts/2026-%02d-%02d-post-%s.md', $source, $month, $day, $n),
            $body,
        );
    }
}

if (isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    if ($argc < 2) {
        fwrite(STDERR, "Usage: php benchmarks/fixture.php <target-dir> [count]\n");
        exit(1);
    }

    sculpin_bench_fixture($argv[1], (int) ($argv[2] ?? '400'));
}
