<?php

declare(strict_types=1);

/** @var string $title */
/** @var string $siteName */
/** @var string $content */
/** @var string $toc */
/** @var array $nav */
/** @var string $current */
/** @var ?array $prev */
/** @var ?array $next */
/** @var string $baseUrl */
/** @var int $builtAt */
if (! function_exists('navLink')) {
    function navLink(string $baseUrl, string $file): string
    {
        if ($file === 'index.md') {
            return $baseUrl.'index.html';
        }

        return $baseUrl.preg_replace('/\.md$/', '.html', $file);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title) ?> · <?= htmlspecialchars($siteName) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/normalize.css@8.0.1/normalize.css">
    <link rel="stylesheet" href="<?= $baseUrl ?>style.css">
    <link rel="stylesheet" href="<?= $baseUrl ?>tree-widget.css">
</head>
<body data-built-at="<?= $builtAt ?>">
<?php
    /*
     * Block applying any dark-mode preference before paint to avoid a flash
     * of light-themed content.
     */
?>
<script>(function () {
    if (localStorage.getItem('theme') === 'dark') {
        document.documentElement.setAttribute('data-theme', 'dark');
    }
})();</script>
<aside class="sidebar" aria-label="Documentation navigation">
    <a href="<?= $baseUrl ?>index.html" class="sidebar-brand"><?= htmlspecialchars($siteName) ?></a>
    <nav class="sidebar-nav">
        <?php foreach ($nav as $section) { ?>
            <div class="nav-section">
                <h4><?= htmlspecialchars($section['title']) ?></h4>
                <ul>
                    <?php foreach ($section['pages'] as $page) { ?>
                        <li>
                            <a
                                href="<?= navLink($baseUrl, $page['file']) ?>"
                                class="<?= $page['file'] === $current ? 'active' : '' ?>"
                            ><?= htmlspecialchars($page['title']) ?></a>
                        </li>
                    <?php } ?>
                </ul>
            </div>
        <?php } ?>
    </nav>
</aside>

<button class="theme-toggle" type="button" aria-label="Toggle theme">◐</button>

<div class="layout">
    <main class="content">
        <article class="prose">
            <?= $content ?>
        </article>

        <nav class="page-nav" aria-label="Previous and next page">
            <?php if ($prev) { ?>
                <a class="page-nav-prev" href="<?= navLink($baseUrl, $prev['file']) ?>">
                    <span class="page-nav-label">Previous</span>
                    <span class="page-nav-title"><?= htmlspecialchars($prev['title']) ?></span>
                </a>
            <?php } else { ?><span></span><?php } ?>
            <?php if ($next) { ?>
                <a class="page-nav-next" href="<?= navLink($baseUrl, $next['file']) ?>">
                    <span class="page-nav-label">Next</span>
                    <span class="page-nav-title"><?= htmlspecialchars($next['title']) ?></span>
                </a>
            <?php } else { ?><span></span><?php } ?>
        </nav>
    </main>

    <?php if ($toc !== '') { ?>
        <aside class="toc-sidebar" aria-label="On this page">
            <h4>On this page</h4>
            <?= $toc ?>
        </aside>
    <?php } ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/prismjs@1.29.0/components/prism-core.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/prismjs@1.29.0/plugins/autoloader/prism-autoloader.min.js"></script>
<script src="<?= $baseUrl ?>tree-widget.js"></script>
<script src="<?= $baseUrl ?>app.js"></script>
</body>
</html>
