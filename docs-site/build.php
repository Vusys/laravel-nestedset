<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Attributes\AttributesExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\TableOfContents\TableOfContentsExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Query;

$repoRoot   = realpath(__DIR__ . '/..');
$docsDir    = $repoRoot . '/docs';
$siteRoot   = $repoRoot . '/site';
$siteDir    = $siteRoot;
$publicSrc  = __DIR__ . '/public';
$layoutFile = __DIR__ . '/templates/layout.php';
$nav        = require $docsDir . '/nav.php';

$converter = makeConverter();

resetDir($siteDir);
copyPublic($publicSrc, $siteDir);
copyNpmAssets(__DIR__ . '/node_modules', $siteDir, [
    'normalize.css/normalize.css' => 'normalize.css',
]);

$pages    = flattenNav($nav);
$builtAt  = time();

foreach ($pages as $i => $page) {
    $sourcePath = $docsDir . '/' . $page['file'];
    if (!is_file($sourcePath)) {
        fwrite(STDERR, "warn: missing source {$page['file']}\n");
        renderMissing($page, $nav, $siteDir, $layoutFile, $builtAt, $pages, $i);
        continue;
    }

    $raw      = file_get_contents($sourcePath);
    $expanded = expandSnippets($raw, $repoRoot, $sourcePath);
    $result   = $converter->convert($expanded);
    $html     = (string) $result;
    $toc      = extractToc($html);
    $bodyHtml = stripFirstToc($html);

    $title  = $page['title'];
    $outRel = relativeOutputPath($page['file'], $i === 0);
    $outAbs = $siteDir . '/' . $outRel;

    @mkdir(dirname($outAbs), 0777, true);

    $rendered = renderLayout($layoutFile, [
        'title'    => $title,
        'siteName' => 'laravel-nestedset',
        'content'  => $bodyHtml,
        'toc'      => $toc,
        'nav'      => $nav,
        'current'  => $page['file'],
        'prev'     => $pages[$i - 1] ?? null,
        'next'     => $pages[$i + 1] ?? null,
        'baseUrl'  => baseUrlFor($outRel),
        'builtAt'  => $builtAt,
    ]);

    file_put_contents($outAbs, $rendered);
    echo "  wrote {$outRel}\n";
}

file_put_contents($siteDir . '/_build.json', json_encode(['builtAt' => $builtAt]));

echo "Built " . count($pages) . " pages to {$siteDir}\n";

// ---------------------------------------------------------------------------

function makeConverter(): MarkdownConverter
{
    $env = new Environment([
        'heading_permalink' => [
            'symbol'   => '#',
            'html_class' => 'heading-anchor',
        ],
        'table_of_contents' => [
            'html_class' => 'toc',
            'position'   => 'top',
            'min_heading_level' => 2,
            'max_heading_level' => 3,
            'normalize'  => 'relative',
        ],
    ]);

    $env->addExtension(new CommonMarkCoreExtension());
    $env->addExtension(new GithubFlavoredMarkdownExtension());
    $env->addExtension(new AttributesExtension());
    $env->addExtension(new HeadingPermalinkExtension());
    $env->addExtension(new TableOfContentsExtension());

    return new MarkdownConverter($env);
}

function flattenNav(array $nav): array
{
    $out = [];
    foreach ($nav as $section) {
        foreach ($section['pages'] as $page) {
            $out[] = $page + ['section' => $section['title']];
        }
    }
    return $out;
}

function relativeOutputPath(string $file, bool $isHome): string
{
    if ($isHome) {
        return 'index.html';
    }
    $noExt = preg_replace('/\.md$/i', '', $file);
    return $noExt . '.html';
}

function baseUrlFor(string $outRel): string
{
    $depth = substr_count($outRel, '/');
    return $depth === 0 ? './' : str_repeat('../', $depth);
}

function resetDir(string $dir): void
{
    if (is_dir($dir)) {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
    } else {
        mkdir($dir, 0777, true);
    }
}

function copyPublic(string $src, string $dest): void
{
    if (!is_dir($src)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($items as $item) {
        $rel = substr($item->getPathname(), strlen($src) + 1);
        $target = $dest . '/' . $rel;
        if ($item->isDir()) {
            @mkdir($target, 0777, true);
        } else {
            copy($item->getPathname(), $target);
        }
    }
}

/**
 * Copy specific files out of node_modules into the build output.
 *
 * The map is {package-relative source path} => {output path inside site/}.
 * Missing files emit a loud warning so a forgotten `pnpm install` doesn't
 * silently ship a broken stylesheet reference.
 */
function copyNpmAssets(string $nodeModulesDir, string $siteDir, array $map): void
{
    if (!is_dir($nodeModulesDir)) {
        fwrite(STDERR, "warn: docs-site/node_modules missing — run `pnpm install` in docs-site/\n");
        return;
    }
    foreach ($map as $source => $target) {
        $sourcePath = $nodeModulesDir . '/' . $source;
        $targetPath = $siteDir . '/' . $target;
        if (!is_file($sourcePath)) {
            fwrite(STDERR, "warn: npm asset missing: {$source}\n");
            continue;
        }
        @mkdir(dirname($targetPath), 0777, true);
        copy($sourcePath, $targetPath);
    }
}

/**
 * Expand <!-- include: path/to/file.php:tag --> directives.
 *
 * Source files mark snippet regions with comments:
 *   // [docs:tag-name]
 *   ...code...
 *   // [/docs:tag-name]
 *
 * The directive pulls the lines between the markers (exclusive) and
 * inserts them as a fenced code block in the rendered markdown.
 */
function expandSnippets(string $markdown, string $repoRoot, string $sourceFile): string
{
    return preg_replace_callback(
        '/<!--\s*include:\s*([^\s:]+):([A-Za-z0-9_\-]+)(?:\s+lang=([A-Za-z0-9_+\-]+))?\s*-->/',
        function (array $m) use ($repoRoot, $sourceFile): string {
            $path = $repoRoot . '/' . $m[1];
            $tag  = $m[2];
            $lang = $m[3] ?? guessLang($m[1]);

            if (!is_file($path)) {
                fwrite(STDERR, "warn: snippet source not found {$m[1]} (in {$sourceFile})\n");
                return "```\nMISSING SNIPPET: {$m[1]}\n```";
            }

            $content = file_get_contents($path);
            $pattern = '/\[docs:' . preg_quote($tag, '/') . '\](.*?)\[\/docs:' . preg_quote($tag, '/') . '\]/s';
            if (!preg_match($pattern, $content, $match)) {
                fwrite(STDERR, "warn: snippet tag '{$tag}' not found in {$m[1]} (in {$sourceFile})\n");
                return "```\nMISSING SNIPPET TAG: {$m[1]}:{$tag}\n```";
            }

            $body = trim($match[1], "\r\n");
            $body = stripCommentLine($body);
            $body = dedent($body);

            return "```{$lang}\n{$body}\n```";
        },
        $markdown
    );
}

function guessLang(string $path): string
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return match ($ext) {
        'php'              => 'php',
        'js', 'mjs', 'cjs' => 'js',
        'ts'               => 'ts',
        'json'             => 'json',
        'sh', 'bash'       => 'bash',
        'yml', 'yaml'      => 'yaml',
        'sql'              => 'sql',
        default            => '',
    };
}

function stripCommentLine(string $body): string
{
    $lines = explode("\n", $body);
    $first = $lines[0] ?? '';
    if (preg_match('~^\s*(//|#)~', $first)) {
        array_shift($lines);
    }
    return implode("\n", $lines);
}

function dedent(string $body): string
{
    $lines = explode("\n", $body);
    $min = PHP_INT_MAX;
    foreach ($lines as $line) {
        if ($line === '' || ctype_space($line)) {
            continue;
        }
        $min = min($min, strlen($line) - strlen(ltrim($line, ' ')));
    }
    if ($min === PHP_INT_MAX || $min === 0) {
        return $body;
    }
    return implode("\n", array_map(
        fn ($l) => substr($l, $min) === false ? $l : (strlen($l) >= $min ? substr($l, $min) : $l),
        $lines
    ));
}

function extractToc(string $html): string
{
    if (preg_match('/<ul class="toc">.*?<\/ul>/s', $html, $m)) {
        return $m[0];
    }
    return '';
}

function stripFirstToc(string $html): string
{
    return preg_replace('/<ul class="toc">.*?<\/ul>/s', '', $html, 1);
}

function renderLayout(string $file, array $vars): string
{
    extract($vars, EXTR_SKIP);
    ob_start();
    include $file;
    return ob_get_clean();
}

function renderMissing(array $page, array $nav, string $siteDir, string $layoutFile, int $builtAt, array $pages, int $i): void
{
    $outRel = relativeOutputPath($page['file'], $i === 0);
    $outAbs = $siteDir . '/' . $outRel;
    @mkdir(dirname($outAbs), 0777, true);

    $bodyHtml = "<h1>{$page['title']}</h1><p><em>This page hasn't been written yet.</em></p>";

    $rendered = renderLayout($layoutFile, [
        'title'    => $page['title'] . ' (placeholder)',
        'siteName' => 'laravel-nestedset',
        'content'  => $bodyHtml,
        'toc'      => '',
        'nav'      => $nav,
        'current'  => $page['file'],
        'prev'     => $pages[$i - 1] ?? null,
        'next'     => $pages[$i + 1] ?? null,
        'baseUrl'  => baseUrlFor($outRel),
        'builtAt'  => $builtAt,
    ]);

    file_put_contents($outAbs, $rendered);
    echo "  wrote {$outRel} (placeholder)\n";
}
