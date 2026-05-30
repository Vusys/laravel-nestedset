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
if ($repoRoot === false) {
    fwrite(STDERR, 'fatal: failed to resolve repository root from '.__DIR__."\n");
    exit(1);
}
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

    $result = $converter->convert(expandCallouts($raw));
    $html = (string) $result;
    [$bodyHtml, $toc] = autoNumberHeadingsAndToc($html);

    $title = $page['title'];
    $outRel = relativeOutputPath($page['file'], $i === 0);
    $outAbs = $siteDir.'/'.$outRel;

    mustMakeDir(dirname($outAbs));
    mustWriteFile($outAbs, renderLayout($layoutFile, [
        'title' => $title,
        'siteName' => 'vusys/laravel-nestedset',
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
            'max_heading_level' => 4,
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

/**
 * Expand GitHub-style alert blockquotes into callout markup before the
 * Markdown is converted:
 *
 *   > [!NOTE]
 *   > Body text, parsed as Markdown.
 *
 * becomes a <div class="callout callout-note"> whose body is still rendered
 * as Markdown (the surrounding blank lines re-open the Markdown parser inside
 * the raw-HTML block, per the CommonMark HTML-block rules). An optional title
 * may follow the marker: `> [!NOTE] Heads up`.
 */
function expandCallouts(string $markdown): string
{
    $types = [
        'NOTE' => ['note', 'Note'],
        'TIP' => ['tip', 'Tip'],
        'IMPORTANT' => ['important', 'Important'],
        'WARNING' => ['warning', 'Warning'],
        'CAUTION' => ['caution', 'Caution'],
    ];

    $lines = explode("\n", $markdown);
    $out = [];
    $count = count($lines);
    $i = 0;

    while ($i < $count) {
        if (preg_match('/^>\s*\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION)\]\s*(.*)$/', $lines[$i], $m)) {
            [$cls, $defaultTitle] = $types[strtoupper($m[1])];
            $title = trim($m[2]) !== '' ? trim($m[2]) : $defaultTitle;

            $body = [];
            $i++;
            while ($i < $count && preg_match('/^>\s?(.*)$/', $lines[$i], $bm)) {
                $body[] = $bm[1];
                $i++;
            }
            while ($body !== [] && trim($body[0]) === '') {
                array_shift($body);
            }
            while ($body !== [] && trim((string) end($body)) === '') {
                array_pop($body);
            }

            $out[] = '<div class="callout callout-'.$cls.'">';
            $out[] = '<p class="callout-title">'.htmlspecialchars($title).'</p>';
            $out[] = '';
            foreach ($body as $b) {
                $out[] = $b;
            }
            $out[] = '';
            $out[] = '</div>';

            continue;
        }

        $out[] = $lines[$i];
        $i++;
    }

    return implode("\n", $out);
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
 * Reject summary entries that try to escape docs/ via absolute paths
 * or traversal segments. summary.md is hand-written, so this catches
 * typos as much as anything malicious.
 */
function safeDocPath(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    if ($path === '' || str_starts_with($path, '/') || preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
        fwrite(STDERR, "fatal: invalid summary path {$path}\n");
        exit(1);
    }

    return $path;
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

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        fwrite(STDERR, "fatal: failed to read summary at {$path}\n");
        exit(1);
    }

    $sections = [];
    $sectionIdx = -1;

    foreach ($lines as $line) {
        if (preg_match('/^#\s+(.+?)\s*$/', $line, $m)) {
            if ($sectionIdx === -1 && strcasecmp(trim($m[1]), 'Summary') === 0) {
                continue;
            }
            $sections[] = ['title' => $m[1], 'pages' => []];
            $sectionIdx = count($sections) - 1;

            continue;
        }

        if ($sectionIdx !== -1 && preg_match('/^\s*-\s*\[(.+?)\]\((.+?)\)\s*$/', $line, $m)) {
            $sections[$sectionIdx]['pages'][] = ['title' => $m[1], 'file' => safeDocPath($m[2])];
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

/**
 * Auto-number H2/H3 headings ("1.", "1.1", "2.", "2.1", "2.2", "3."…), apply
 * the same numbers to the auto-generated TOC entries, move the TOC just below
 * the first H1, and return [bodyHtml, tocHtmlForSidebar]. Pages without an H2
 * are returned unchanged with an empty TOC.
 */
function autoNumberHeadingsAndToc(string $html): array
{
    if (trim($html) === '') {
        return [$html, ''];
    }

    $numbers = [];
    $h2 = 0;
    $h3 = 0;
    $h4 = 0;

    $html = preg_replace_callback(
        '#<h([234])([^>]*?)>(.*?)</h\1>#s',
        function (array $m) use (&$h2, &$h3, &$h4, &$numbers): string {
            $level = (int) $m[1];
            $attrs = $m[2];
            $inner = $m[3];

            if ($level === 2) {
                $h2++;
                $h3 = 0;
                $h4 = 0;
                $number = $h2.'.';
            } elseif ($level === 3) {
                if ($h2 === 0) {
                    return $m[0];
                }
                $h3++;
                $h4 = 0;
                $number = "{$h2}.{$h3}";
            } else {
                if ($h2 === 0 || $h3 === 0) {
                    return $m[0];
                }
                $h4++;
                $number = "{$h2}.{$h3}.{$h4}";
            }

            if (preg_match('/\bid="([^"]+)"/', $attrs.' '.$inner, $idm) === 1) {
                $numbers[$idm[1]] = $number;
            }

            $prefix = '<span class="heading-number">'.$number.'</span> ';
            if (preg_match('#^(\s*<a\b[^>]*class="heading-anchor"[^>]*>.*?</a>)(.*)$#s', $inner, $am) === 1) {
                $newInner = $am[1].$prefix.$am[2];
            } else {
                $newInner = $prefix.$inner;
            }

            return "<h{$level}{$attrs}>{$newInner}</h{$level}>";
        },
        $html
    );

    $span = findTocSpan($html);
    if ($span !== null && $numbers !== []) {
        $tocHtml = substr($html, $span[0], $span[1]);
        $tocHtml = preg_replace_callback(
            '~(<a\s+href="\#)([^"]+)("\s*>)~',
            function (array $m) use ($numbers): string {
                if (! isset($numbers[$m[2]])) {
                    return $m[0];
                }

                return $m[0].'<span class="toc-number">'.$numbers[$m[2]].'</span> ';
            },
            $tocHtml
        );
        $html = substr($html, 0, $span[0]).$tocHtml.substr($html, $span[0] + $span[1]);
    }

    $sidebarToc = extractToc($html);
    $html = stripFirstToc($html);

    return [$html, $sidebarToc];
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
        'siteName' => 'vusys/laravel-nestedset',
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
