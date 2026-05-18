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
$nav        = parseSummary($docsDir . '/summary.md');

$converter = makeConverter();

resetDir($siteDir);
copyPublic($publicSrc, $siteDir);

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
    $result   = $converter->convert($raw);
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
            'symbol'    => '#',
            'html_class' => 'heading-anchor',
            'id_prefix'  => '',
            'fragment_prefix' => '',
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

/**
 * Parse docs/summary.md into a section/pages tree.
 *
 *   # Section Title
 *
 *   - [Page Title](page.md)
 *
 * A leading "# Summary" header is treated as the document title and
 * skipped. Everything outside a section (prose, format notes, etc.)
 * is ignored — only `#` headers and list-item links matter.
 */
function parseSummary(string $path): array
{
    if (!is_file($path)) {
        fwrite(STDERR, "fatal: nav summary missing at {$path}\n");
        exit(1);
    }

    $sections    = [];
    $sectionIdx  = -1;

    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^#\s+(.+?)\s*$/', $line, $m)) {
            if ($sectionIdx === -1 && strcasecmp(trim($m[1]), 'Summary') === 0) {
                continue;
            }
            $sections[]  = ['title' => $m[1], 'pages' => []];
            $sectionIdx  = count($sections) - 1;
            continue;
        }

        if ($sectionIdx !== -1 && preg_match('/^\s*-\s*\[(.+?)\]\((.+?)\)\s*$/', $line, $m)) {
            $sections[$sectionIdx]['pages'][] = ['title' => $m[1], 'file' => $m[2]];
        }
    }

    if ($sections === []) {
        fwrite(STDERR, "fatal: summary.md parsed but no sections found\n");
        exit(1);
    }

    return $sections;
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
