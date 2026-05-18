<?php

/**
 * Navigation tree for the docs site.
 *
 * Each entry is a section with a title and a list of pages.
 * Pages reference markdown files relative to the docs/ directory.
 * The first page in the first section is the home page.
 */

return [
    [
        'title' => 'Getting Started',
        'pages' => [
            ['file' => 'index.md', 'title' => 'Introduction'],
            ['file' => 'installation.md', 'title' => 'Installation'],
            ['file' => 'getting-started.md', 'title' => 'Your First Tree'],
        ],
    ],
    [
        'title' => 'Tree Operations',
        'pages' => [
            ['file' => 'inserting.md', 'title' => 'Inserting Nodes'],
            ['file' => 'moving.md', 'title' => 'Moving Nodes'],
            ['file' => 'deleting.md', 'title' => 'Deleting Nodes'],
        ],
    ],
    [
        'title' => 'Querying',
        'pages' => [
            ['file' => 'ancestors-descendants.md', 'title' => 'Ancestors & Descendants'],
            ['file' => 'siblings.md', 'title' => 'Siblings'],
            ['file' => 'depth.md', 'title' => 'Depth & Levels'],
            ['file' => 'scoped-trees.md', 'title' => 'Scoped Trees'],
        ],
    ],
    [
        'title' => 'Aggregates',
        'pages' => [
            ['file' => 'aggregates/overview.md', 'title' => 'Overview'],
            ['file' => 'aggregates/built-in.md', 'title' => 'Built-in Aggregates'],
            ['file' => 'aggregates/custom.md', 'title' => 'Custom Aggregates'],
            ['file' => 'aggregates/filtered.md', 'title' => 'Filtered Aggregates'],
            ['file' => 'aggregates/listeners.md', 'title' => 'Listener Aggregates'],
        ],
    ],
    [
        'title' => 'Maintenance',
        'pages' => [
            ['file' => 'CORRUPTION.md', 'title' => 'Detecting & Fixing Corruption'],
            ['file' => 'fix-tree.md', 'title' => 'fixTree'],
            ['file' => 'fix-aggregates.md', 'title' => 'fixAggregates'],
        ],
    ],
    [
        'title' => 'Reference',
        'pages' => [
            ['file' => 'reference/api.md', 'title' => 'API Index'],
            ['file' => 'reference/config.md', 'title' => 'Configuration'],
        ],
    ],
];
