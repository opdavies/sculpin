<?php

declare(strict_types=1);

/*
 * Generates the deterministic Sculpin benchmark fixture: a site with
 * N markdown posts (default 400), a layout with an "about me" partial
 * included from it and an index page looping the whole collection,
 * with no dynamic dates, so every generation of the same fixture
 * produces byte-identical output -- which the write-skipping and
 * caching of the performance series rely on.
 *
 * Standalone usage: php benchmarks/fixture.php <target-dir> [count]
 */

function sculpin_bench_fixture(string $target, int $count): void
{
    $source = $target . '/source';

    foreach ([
        $source . '/_posts',
        $source . '/_layouts',
        $source . '/_partials',
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
            menu:
              - title: Home
                url: /
              - title: Blog
                url: /blog
              - title: Talks
                url: /talks
              - title: About
                url: /about
            social:
              github: https://github.com/opdavies
              mastodon: https://fosstodon.org/@opdavies
              drupal: https://drupal.org/u/opdavies
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
                <header>
                  <a href="{{ site.url }}">{{ site.title }}</a>
                  <nav>
                    {% for item in site.menu %}
                      <a href="{{ site.url }}{{ item.url }}">{{ item.title }}</a>
                    {% endfor %}
                  </nav>
                </header>
                <aside>{% include 'about-me.html.twig' %}</aside>
                <main>{% block body %}{% block content %}{% endblock %}{% endblock %}</main>
                <footer>
                  <ul>
                    {% for network, url in site.social %}
                      <li><a href="{{ url }}">{{ network }}</a></li>
                    {% endfor %}
                  </ul>
                </footer>
              </body>
            </html>
            TWIG,
    );

    // The post layout extends the base so every post page exercises
    // the Twig template inheritance chain, and its front-matter
    // condition ({% if page.featured %}) and the date filter.
    file_put_contents(
        $source . '/_layouts/post.html.twig',
        <<<'TWIG'
            {% extends 'default.html.twig' %}
            {% block body %}
              <article class="post">
                {% if page.featured %}<p class="featured">Featured post</p>{% endif %}
                <header class="byline">
                  <h1>{{ page.title }}</h1>
                  <time>{{ page.date|date('jS F Y') }}</time>
                </header>
                {% block content %}{% endblock %}
              </article>
            {% endblock %}
            TWIG,
    );

    // The "about me" block, included from the layout on every page so
    // the benchmarks exercise the Twig include machinery too.
    file_put_contents(
        $source . '/_partials/about-me.html.twig',
        <<<'TWIG'
            <div class="about-me">
              <h2>About me</h2>
              <p>Oliver Davies is a PHP developer and architect specialising
              in Drupal and Sculpin, building static sites and content
              platforms. He writes about PHP, Nix and web architecture, and
              maintains several open-source projects.</p>
              <ul>
                <li><a href="{{ site.url }}/blog">Read the blog</a></li>
                <li><a href="{{ site.url }}/about">More about me</a></li>
                <li><a href="{{ site.url }}/talks">Watch the talks</a></li>
              </ul>
            </div>
            TWIG,
    );

    // The index page loops the whole posts collection (the `use` front
    // matter is what provides `data.posts`), extending the base
    // template explicitly and marking the featured posts.
    file_put_contents(
        $source . '/index.html.twig',
        <<<'TWIG'
            ---
            title: Home
            use:
              - posts
            ---
            {% extends 'default.html.twig' %}
            {% block content %}
            <ul>
            {% for post in data.posts %}
              <li><a href="{{ post.url }}">{{ post.title }}</a>{% if post.featured %} (featured){% endif %}</li>
            {% endfor %}
            </ul>
            {% endblock %}
            TWIG,
    );

    for ($i = 1; $i <= $count; $i++) {
        $n = sprintf('%03d', $i);
        $month = (intdiv($i - 1, 100) % 12) + 1;
        $day = ($i % 28) + 1;

        $frontMatter = "layout: post\n";

        if ($i % 10 === 0) {
            $frontMatter .= "featured: true\n";
        }

        $body = <<<MD
            ---
            title: Benchmark Post {$n}
            {$frontMatter}---

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
