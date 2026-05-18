<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Attributes\AttributesExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\TableOfContents\TableOfContentsExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Block\Document;

$repoRoot = realpath(__DIR__.'/..');
$docsDir = $repoRoot.'/docs';
$siteRoot = $repoRoot.'/site';
$siteDir = $siteRoot;
$publicSrc = __DIR__.'/public';
$layoutFile = __DIR__.'/templates/layout.php';
$nav = parseSummary($docsDir.'/summary.md');

$converter = makeConverter();

resetDir($siteDir);
copyPublic($publicSrc, $siteDir);

$pages = flattenNav($nav);
$builtAt = time();

foreach ($pages as $i => $page) {
    $sourcePath = $docsDir.'/'.$page['file'];
    if (! is_file($sourcePath)) {
        fwrite(STDERR, "warn: missing source {$page['file']}\n");
        renderMissing($page, $nav, $siteDir, $layoutFile, $builtAt, $pages, $i);

        continue;
    }

    $raw = file_get_contents($sourcePath);
    if ($raw === false) {
        fwrite(STDERR, "fatal: failed to read {$sourcePath}\n");
        exit(1);
    }

    $result = $converter->convert($raw);
    $html = (string) $result;
    $toc = extractToc($html);
    $bodyHtml = stripFirstToc($html);

    $title = $page['title'];
    $outRel = relativeOutputPath($page['file'], $i === 0);
    $outAbs = $siteDir.'/'.$outRel;

    mustMakeDir(dirname($outAbs));
    mustWriteFile($outAbs, renderLayout($layoutFile, [
        'title' => $title,
        'siteName' => 'laravel-nestedset',
        'content' => $bodyHtml,
        'toc' => $toc,
        'nav' => $nav,
        'current' => $page['file'],
        'prev' => $pages[$i - 1] ?? null,
        'next' => $pages[$i + 1] ?? null,
        'baseUrl' => baseUrlFor($outRel),
        'builtAt' => $builtAt,
    ]));

    echo "  wrote {$outRel}\n";
}

mustWriteFile($siteDir.'/_build.json', json_encode(['builtAt' => $builtAt]));

echo 'Built '.count($pages)." pages to {$siteDir}\n";

// ---------------------------------------------------------------------------

function makeConverter(): MarkdownConverter
{
    $env = new Environment([
        'heading_permalink' => [
            'symbol' => '#',
            'html_class' => 'heading-anchor',
            'id_prefix' => '',
            'fragment_prefix' => '',
        ],
        'table_of_contents' => [
            'html_class' => 'toc',
            'position' => 'top',
            'min_heading_level' => 2,
            'max_heading_level' => 3,
            'normalize' => 'relative',
        ],
    ]);

    $env->addExtension(new CommonMarkCoreExtension);
    $env->addExtension(new GithubFlavoredMarkdownExtension);
    $env->addExtension(new AttributesExtension);
    $env->addExtension(new HeadingPermalinkExtension);
    $env->addExtension(new TableOfContentsExtension);

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
    if (! is_file($path)) {
        fwrite(STDERR, "fatal: nav summary missing at {$path}\n");
        exit(1);
    }

    $sections = [];
    $sectionIdx = -1;

    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^#\s+(.+?)\s*$/', $line, $m)) {
            if ($sectionIdx === -1 && strcasecmp(trim($m[1]), 'Summary') === 0) {
                continue;
            }
            $sections[] = ['title' => $m[1], 'pages' => []];
            $sectionIdx = count($sections) - 1;

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

    return $noExt.'.html';
}

function baseUrlFor(string $outRel): string
{
    $depth = substr_count($outRel, '/');

    return $depth === 0 ? './' : str_repeat('../', $depth);
}

function mustMakeDir(string $dir): void
{
    if (is_dir($dir)) {
        return;
    }
    if (! mkdir($dir, 0777, true) && ! is_dir($dir)) {
        fwrite(STDERR, "fatal: failed to create directory {$dir}\n");
        exit(1);
    }
}

function mustWriteFile(string $path, string $contents): void
{
    if (file_put_contents($path, $contents) === false) {
        fwrite(STDERR, "fatal: failed to write {$path}\n");
        exit(1);
    }
}

function mustCopyFile(string $src, string $dest): void
{
    if (! copy($src, $dest)) {
        fwrite(STDERR, "fatal: failed to copy {$src} → {$dest}\n");
        exit(1);
    }
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

        return;
    }

    mustMakeDir($dir);
}

function copyPublic(string $src, string $dest): void
{
    if (! is_dir($src)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($items as $item) {
        $rel = substr($item->getPathname(), strlen($src) + 1);
        $target = $dest.'/'.$rel;
        if ($item->isDir()) {
            mustMakeDir($target);
        } else {
            mustCopyFile($item->getPathname(), $target);
        }
    }
}

function findTocSpan(string $html): ?array
{
    $start = strpos($html, '<ul class="toc">');
    if ($start === false) {
        return null;
    }

    $depth = 0;
    $cursor = $start;
    $length = strlen($html);

    while ($cursor < $length) {
        $nextOpen = strpos($html, '<ul', $cursor);
        $nextClose = strpos($html, '</ul>', $cursor);

        if ($nextClose === false) {
            return null;
        }

        if ($nextOpen !== false && $nextOpen < $nextClose) {
            $depth++;
            $cursor = $nextOpen + 3;

            continue;
        }

        $depth--;
        if ($depth === 0) {
            $end = $nextClose + strlen('</ul>');

            return [$start, $end - $start];
        }

        $cursor = $nextClose + strlen('</ul>');
    }

    return null;
}

function extractToc(string $html): string
{
    $span = findTocSpan($html);

    return $span === null ? '' : substr($html, $span[0], $span[1]);
}

function stripFirstToc(string $html): string
{
    $span = findTocSpan($html);

    return $span === null ? $html : substr($html, 0, $span[0]).substr($html, $span[0] + $span[1]);
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
    $outAbs = $siteDir.'/'.$outRel;
    mustMakeDir(dirname($outAbs));

    $bodyHtml = "<h1>{$page['title']}</h1><p><em>This page hasn't been written yet.</em></p>";

    mustWriteFile($outAbs, renderLayout($layoutFile, [
        'title' => $page['title'].' (placeholder)',
        'siteName' => 'laravel-nestedset',
        'content' => $bodyHtml,
        'toc' => '',
        'nav' => $nav,
        'current' => $page['file'],
        'prev' => $pages[$i - 1] ?? null,
        'next' => $pages[$i + 1] ?? null,
        'baseUrl' => baseUrlFor($outRel),
        'builtAt' => $builtAt,
    ]));
    echo "  wrote {$outRel} (placeholder)\n";
}
