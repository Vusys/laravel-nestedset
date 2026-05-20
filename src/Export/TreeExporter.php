<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Export;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Stringable;
use Throwable;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Exceptions\CorruptTreeException;
use Vusys\NestedSet\NodeTrait;

/**
 * Folds an ordered set of nested-set nodes into an in-memory parent →
 * children tree and renders it as Mermaid / DOT / ASCII / JSON.
 *
 * The exporter is purely a formatter: no DB writes, no mutation, no
 * recursion in SQL. Callers hand in nodes already loaded (typically the
 * `$node->descendants()->orderBy(lft)->get()` collection plus the root)
 * and the renderer walks the in-memory map.
 *
 * @internal Use the public methods on {@see NodeTrait}.
 *
 * @phpstan-type ExporterNode array{node: Model&HasNestedSet, children: list<int|string>}
 */
final readonly class TreeExporter
{
    /**
     * @param  array<int|string, ExporterNode>  $nodes
     * @param  list<int|string>  $roots
     */
    private function __construct(
        private array $nodes,
        private array $roots,
    ) {}

    /**
     * Builds an exporter from a collection of nodes ordered by `lft`.
     *
     * Roots are inferred: any node whose `parent_id` is null or whose
     * parent is not in the loaded set acts as a top-level entry. This
     * lets the same code path serve subtree exports (single root)
     * and forest exports (many roots) without a flag.
     *
     * @param  iterable<Model&HasNestedSet>  $nodes
     *
     * @throws CorruptTreeException when the loaded set contains a
     *                              parent_id cycle.
     */
    public static function fromOrderedNodes(iterable $nodes): self
    {
        /** @var array<int|string, ExporterNode> $byKey */
        $byKey = [];

        foreach ($nodes as $node) {
            $key = $node->getKey();
            if (! is_int($key) && ! is_string($key)) {
                continue;
            }
            $byKey[$key] = ['node' => $node, 'children' => []];
        }

        /** @var list<int|string> $roots */
        $roots = [];

        foreach ($byKey as $key => $entry) {
            $parentId = $entry['node']->getParentId();
            if ($parentId !== null && isset($byKey[$parentId])) {
                $byKey[$parentId]['children'][] = $key;
            } else {
                $roots[] = $key;
            }
        }

        self::assertNoCycles($byKey, $roots);

        return new self($byKey, $roots);
    }

    public function toMermaid(MermaidOptions $opts): string
    {
        $lines = ['graph '.$opts->direction];

        foreach ($this->nodes as $entry) {
            $id = $this->nodeId($entry['node']);
            $label = $this->mermaidLabel($entry['node'], $opts);
            $lines[] = "    {$id}[\"{$label}\"]";
        }

        foreach ($this->nodes as $entry) {
            $parentId = $this->nodeId($entry['node']);
            foreach ($entry['children'] as $childKey) {
                $childId = $this->nodeId($this->nodes[$childKey]['node']);
                $lines[] = "    {$parentId} --> {$childId}";
            }
        }

        return implode("\n", $lines);
    }

    public function toDot(DotOptions $opts): string
    {
        $lines = [
            'digraph tree {',
            "    rankdir={$opts->direction};",
            '    node [shape=box];',
        ];

        foreach ($this->nodes as $entry) {
            $id = $this->nodeId($entry['node']);
            $label = $this->dotLabel($entry['node'], $opts);
            $lines[] = "    \"{$id}\" [label=\"{$label}\"];";
        }

        foreach ($this->nodes as $entry) {
            $parentId = $this->nodeId($entry['node']);
            foreach ($entry['children'] as $childKey) {
                $childId = $this->nodeId($this->nodes[$childKey]['node']);
                $lines[] = "    \"{$parentId}\" -> \"{$childId}\";";
            }
        }

        $lines[] = '}';

        return implode("\n", $lines);
    }

    public function toAsciiTree(AsciiOptions $opts): string
    {
        /** @var list<string> $lines */
        $lines = [];
        foreach ($this->roots as $rootKey) {
            $this->renderAsciiNode($rootKey, $opts, $lines, 0, '', null);
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public function toJson(JsonOptions $opts): array
    {
        if (count($this->roots) === 1) {
            return $this->jsonNode($this->roots[0], $opts);
        }

        return array_map(
            fn (int|string $r): array => $this->jsonNode($r, $opts),
            $this->roots,
        );
    }

    /**
     * @param  list<string>  $lines
     */
    private function renderAsciiNode(
        int|string $key,
        AsciiOptions $opts,
        array &$lines,
        int $indentLevel,
        string $continuation,
        ?bool $isLastSibling,
    ): void {
        $entry = $this->nodes[$key];
        $node = $entry['node'];

        $branch = '';
        if ($isLastSibling !== null) {
            $branch = $isLastSibling
                ? ($opts->unicode ? '└── ' : '`-- ')
                : ($opts->unicode ? '├── ' : '|-- ');
        }

        $label = $this->labelFor($node, $opts->label);
        if ($opts->showDepth) {
            $label .= ' (depth='.$node->getDepth().')';
        }

        $lines[] = $continuation.$branch.$label;

        if ($opts->maxDepth !== null && $indentLevel >= $opts->maxDepth) {
            return;
        }

        $children = $entry['children'];
        $count = count($children);
        if ($count === 0) {
            return;
        }

        $childContinuation = $continuation;
        if ($isLastSibling !== null) {
            $childContinuation .= $isLastSibling ? '    ' : ($opts->unicode ? '│   ' : '|   ');
        }

        foreach ($children as $i => $childKey) {
            $this->renderAsciiNode(
                $childKey,
                $opts,
                $lines,
                $indentLevel + 1,
                $childContinuation,
                $i === $count - 1,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonNode(int|string $key, JsonOptions $opts): array
    {
        $entry = $this->nodes[$key];
        $node = $entry['node'];

        $payload = [
            'id' => $node->getKey(),
            'label' => $this->labelFor($node, $opts->label),
        ];

        foreach ($opts->extras as $col) {
            $payload[$col] = $node->getAttribute($col);
        }

        $payload[$opts->childrenKey] = array_map(
            fn (int|string $c): array => $this->jsonNode($c, $opts),
            $entry['children'],
        );

        return $payload;
    }

    private function mermaidLabel(Model&HasNestedSet $node, MermaidOptions $opts): string
    {
        $label = $this->labelFor($node, $opts->label);
        if ($opts->showId) {
            $label .= ' (id='.$this->stringifyKey($node->getKey()).')';
        }

        $parts = [$this->escapeMermaid($label)];
        foreach ($opts->showAggregates as $col) {
            $parts[] = $this->escapeMermaid($col.': '.$this->scalarish($node->getAttribute($col)));
        }

        return implode('<br/>', $parts);
    }

    private function dotLabel(Model&HasNestedSet $node, DotOptions $opts): string
    {
        $label = $this->labelFor($node, $opts->label);
        if ($opts->showId) {
            $label .= ' (id='.$this->stringifyKey($node->getKey()).')';
        }

        $parts = [$this->escapeDot($label)];
        foreach ($opts->showAggregates as $col) {
            $parts[] = $this->escapeDot($col.': '.$this->scalarish($node->getAttribute($col)));
        }

        return implode('\\n', $parts);
    }

    private function labelFor(Model $node, ?Closure $label): string
    {
        $closure = $label ?? static fn (Model $n): mixed => $n->getAttribute('name');

        try {
            $value = $closure($node);
        } catch (Throwable) {
            $value = null;
        }

        if ($value === null || $value === '') {
            return $this->stringifyKey($node->getKey());
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        return $this->stringifyKey($node->getKey());
    }

    /**
     * Mermaid identifiers must start with a letter. For string PKs
     * (UUIDs, etc.) we hash to a short alphanumeric prefix so the
     * resulting identifier is both valid and short.
     */
    private function nodeId(Model&HasNestedSet $node): string
    {
        $key = $node->getKey();
        if (is_int($key)) {
            return "n{$key}";
        }

        return 'n'.substr(md5($this->stringifyKey($key)), 0, 8);
    }

    private function stringifyKey(mixed $key): string
    {
        if (is_int($key) || is_string($key)) {
            return (string) $key;
        }

        if ($key instanceof Stringable) {
            return (string) $key;
        }

        return get_debug_type($key);
    }

    private function scalarish(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }
        if ($value instanceof Stringable) {
            return (string) $value;
        }

        return get_debug_type($value);
    }

    private function escapeMermaid(string $s): string
    {
        return strtr($s, [
            '"' => '&quot;',
            '<' => '&lt;',
            '>' => '&gt;',
            "\n" => '<br/>',
        ]);
    }

    private function escapeDot(string $s): string
    {
        return strtr($s, [
            '\\' => '\\\\',
            '"' => '\\"',
            "\n" => '\\n',
        ]);
    }

    /**
     * DFS from every root, asserting each node is visited exactly once.
     * A node that is in `$byKey` but never visited belongs to a
     * parent_id cycle that never reaches a root — the only way to
     * produce one in a NodeTrait table is by hand-rolled SQL or a
     * partially recovered corruption, so we treat it as fatal rather
     * than rendering an infinite-looping subtree.
     *
     * @param  array<int|string, ExporterNode>  $byKey
     * @param  list<int|string>  $roots
     */
    private static function assertNoCycles(array $byKey, array $roots): void
    {
        /** @var array<int|string, true> $visited */
        $visited = [];

        foreach ($roots as $rootKey) {
            /** @var list<int|string> $stack */
            $stack = [$rootKey];

            while ($stack !== []) {
                $key = array_pop($stack);
                if (isset($visited[$key])) {
                    throw new CorruptTreeException(
                        "Tree export aborted: node {$key} is reachable twice from the root set "
                        .'(parent_id cycle).',
                    );
                }
                $visited[$key] = true;
                foreach ($byKey[$key]['children'] as $childKey) {
                    $stack[] = $childKey;
                }
            }
        }

        foreach (array_keys($byKey) as $key) {
            if (! isset($visited[$key])) {
                throw new CorruptTreeException(
                    "Tree export aborted: node {$key} is not reachable from any root "
                    .'(parent_id cycle).',
                );
            }
        }
    }
}
