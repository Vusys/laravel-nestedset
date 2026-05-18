# laravel-nestedset docs site

A small, dependency-light static site generator for the project
documentation. Pure PHP. Reads Markdown from `../docs`, writes HTML to
`../site`. Third-party CSS and JS (normalize.css, Prism) are loaded
from a CDN at runtime, so the only build dep is `league/commonmark`.

## One-time setup

```bash
cd docs-site
composer install
```

## Build the site

```bash
composer build
# or: php build.php
```

Output goes to `../site/`. It's safe to delete — `composer clean` does it
for you.

## Preview locally with live reload

```bash
composer serve
# or: php serve.php --port=8000
```

This:

1. Builds the site once.
2. Starts PHP's built-in dev server on http://localhost:8000.
3. Watches `docs/` and `docs-site/` (templates, CSS, JS, `build.php`) for
   changes and rebuilds on save. Your browser auto-reloads.

Pass `--no-watch` to skip the watcher and just serve. Pass
`--port=N` to use a different port.

## Authoring

- Pages live in `../docs/` as plain Markdown.
- Navigation is defined in `../docs/summary.md` — top-level `#` headings
  are sections, list items are pages: `- [Title](path/to/file.md)`. Add
  a new page by adding a line there.
- Pages listed in `summary.md` that don't yet exist render as placeholders,
  so you can plan the site upfront and fill it in incrementally.

## Layout

```text
docs-site/
├── build.php           # Markdown → HTML, applies layout, expands snippets
├── serve.php           # Build + dev server + file watcher
├── composer.json       # league/commonmark only
├── templates/
│   └── layout.php      # Single page template — links normalize.css and
│                       # Prism from jsdelivr CDN
└── public/
    ├── style.css       # All site styles
    └── app.js          # Theme toggle + live-reload polling
```

Output lives in `../site/` (gitignored). Static files in `public/` are
copied as-is at build time.
