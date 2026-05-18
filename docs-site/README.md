# laravel-nestedset docs site

A small, dependency-light static site generator for the project
documentation. Pure PHP. Reads Markdown from `../docs`, writes HTML to
`../site`.

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
- Navigation is defined in `../docs/nav.php` — an ordered list of sections
  and pages. Add a new page by adding it to that file.
- Pages listed in `nav.php` that don't yet exist render as placeholders, so
  you can plan the site upfront and fill it in incrementally.

## Embedding code from tests

Examples in the docs should be backed by real tests. Mark a region in a
test file:

```php
public function test_filtered_aggregate(): void
{
    // [docs:filtered-aggregate]
    $count = Category::root()
        ->descendants()
        ->where('active', true)
        ->count();
    // [/docs:filtered-aggregate]

    $this->assertSame(3, $count);
}
```

Then pull it into Markdown:

```markdown
<!-- include: tests/Documentation/AggregatesTest.php:filtered-aggregate -->
```

The build inserts a fenced PHP code block with the lines between the
markers. If a tag goes missing, the build prints a warning and renders a
visible placeholder so it can't be silently lost.

## Layout

```
docs-site/
├── build.php           # Markdown → HTML, applies layout, expands snippets
├── serve.php           # Build + dev server + file watcher
├── composer.json       # league/commonmark only
├── templates/
│   └── layout.php      # Single page template
└── public/
    ├── style.css       # All site styles
    └── app.js          # Theme toggle + live-reload polling
```

Output lives in `../site/` (gitignored). Static files in `public/` are
copied as-is at build time.
