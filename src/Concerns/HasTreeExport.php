<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Concerns;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Exceptions\NestedSetInvalidArgumentException;
use Vusys\NestedSet\Exceptions\NestedSetLogicException;
use Vusys\NestedSet\Export\AsciiOptions;
use Vusys\NestedSet\Export\DotOptions;
use Vusys\NestedSet\Export\JsonOptions;
use Vusys\NestedSet\Export\MermaidOptions;
use Vusys\NestedSet\Export\TreeExporter;
use Vusys\NestedSet\Import\JsonImportOptions;
use Vusys\NestedSet\Import\JsonTreeImporter;
use Vusys\NestedSet\NodeCollection;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;

/**
 * Read-only formatters that render a node (or whole forest) as
 * Mermaid / Graphviz DOT / ASCII / nested JSON. Designed for
 * debugging, docs, and frontend handoff — not analytics pipelines.
 *
 * Each instance method fetches `$this` + descendants in a single
 * lft-ordered query and folds in PHP. Static `*Forest` methods walk
 * every root in the table; static `*Scope` methods walk one tree of a
 * scoped model.
 *
 * @mixin Model
 * @mixin HasNestedSet
 */
trait HasTreeExport
{
    public function toMermaid(?MermaidOptions $opts = null): string
    {
        $opts ??= new MermaidOptions;

        return TreeExporter::fromOrderedNodes(
            $this->loadSubtreeForExport($opts->withTrashed),
        )->toMermaid($opts);
    }

    public function toDot(?DotOptions $opts = null): string
    {
        $opts ??= new DotOptions;

        return TreeExporter::fromOrderedNodes(
            $this->loadSubtreeForExport($opts->withTrashed),
        )->toDot($opts);
    }

    public function toAsciiTree(?AsciiOptions $opts = null): string
    {
        $opts ??= new AsciiOptions;

        return TreeExporter::fromOrderedNodes(
            $this->loadSubtreeForExport($opts->withTrashed),
        )->toAsciiTree($opts);
    }

    /**
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public function toJsonTree(?JsonOptions $opts = null): array
    {
        $opts ??= new JsonOptions;

        return TreeExporter::fromOrderedNodes(
            $this->loadSubtreeForExport($opts->withTrashed),
        )->toJson($opts);
    }

    public static function toMermaidForest(?MermaidOptions $opts = null): string
    {
        $opts ??= new MermaidOptions;

        return TreeExporter::fromOrderedNodes(
            self::loadForestForExport($opts->withTrashed),
        )->toMermaid($opts);
    }

    public static function toDotForest(?DotOptions $opts = null): string
    {
        $opts ??= new DotOptions;

        return TreeExporter::fromOrderedNodes(
            self::loadForestForExport($opts->withTrashed),
        )->toDot($opts);
    }

    public static function toAsciiTreeForest(?AsciiOptions $opts = null): string
    {
        $opts ??= new AsciiOptions;

        return TreeExporter::fromOrderedNodes(
            self::loadForestForExport($opts->withTrashed),
        )->toAsciiTree($opts);
    }

    /**
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public static function toJsonTreeForest(?JsonOptions $opts = null): array
    {
        $opts ??= new JsonOptions;

        return TreeExporter::fromOrderedNodes(
            self::loadForestForExport($opts->withTrashed),
        )->toJson($opts);
    }

    public static function toMermaidScope(mixed $scopeValue, ?MermaidOptions $opts = null): string
    {
        $opts ??= new MermaidOptions;

        return TreeExporter::fromOrderedNodes(
            self::loadScopeForExport($scopeValue, $opts->withTrashed),
        )->toMermaid($opts);
    }

    public static function toDotScope(mixed $scopeValue, ?DotOptions $opts = null): string
    {
        $opts ??= new DotOptions;

        return TreeExporter::fromOrderedNodes(
            self::loadScopeForExport($scopeValue, $opts->withTrashed),
        )->toDot($opts);
    }

    public static function toAsciiTreeScope(mixed $scopeValue, ?AsciiOptions $opts = null): string
    {
        $opts ??= new AsciiOptions;

        return TreeExporter::fromOrderedNodes(
            self::loadScopeForExport($scopeValue, $opts->withTrashed),
        )->toAsciiTree($opts);
    }

    /**
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public static function toJsonTreeScope(mixed $scopeValue, ?JsonOptions $opts = null): array
    {
        $opts ??= new JsonOptions;

        return TreeExporter::fromOrderedNodes(
            self::loadScopeForExport($scopeValue, $opts->withTrashed),
        )->toJson($opts);
    }

    /**
     * Inverse of {@see self::toJsonTree()} — accepts the exporter's
     * shape (or a decoded JSON string in the same shape) and inserts
     * the payload under `$parent` or as new roots.
     *
     * @param  array<int|string, mixed>|string  $json
     * @return NodeCollection<int, Model&HasNestedSet>
     */
    public static function fromJsonTree(
        array|string $json,
        ?HasNestedSet $parent = null,
        ?JsonImportOptions $options = null,
    ): NodeCollection {
        $options ??= new JsonImportOptions;

        /** @var class-string<Model&HasNestedSet> $modelClass */
        $modelClass = static::class;

        return JsonTreeImporter::import($modelClass, $json, $parent, $options);
    }

    /**
     * @return EloquentCollection<int, Model&HasNestedSet>
     */
    private function loadSubtreeForExport(bool $withTrashed): EloquentCollection
    {
        $bounds = $this->getBounds();
        $lft = $this->getLftName();
        $rgt = $this->getRgtName();

        $query = static::query()
            ->where($lft, '>', $bounds->lft)
            ->where($rgt, '<', $bounds->rgt)
            ->orderBy($lft);

        foreach (NestedSetScopeResolver::valuesFor($this) as $col => $value) {
            $query->where($col, '=', $value);
        }

        if ($withTrashed && in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        /** @var EloquentCollection<int, Model&HasNestedSet> $descendants */
        $descendants = $query->get();

        $descendants->prepend($this);

        return $descendants;
    }

    /**
     * @return EloquentCollection<int, Model&HasNestedSet>
     */
    private static function loadForestForExport(bool $withTrashed): EloquentCollection
    {
        $prototype = new static;
        $query = static::query();

        foreach (NestedSetScopeResolver::columns(static::class) as $col) {
            $query->orderBy($col);
        }
        $query->orderBy($prototype->getLftName());

        if ($withTrashed && in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        /** @var EloquentCollection<int, Model&HasNestedSet> $result */
        $result = $query->get();

        return $result;
    }

    /**
     * @return EloquentCollection<int, Model&HasNestedSet>
     */
    private static function loadScopeForExport(mixed $scopeValue, bool $withTrashed): EloquentCollection
    {
        $columns = NestedSetScopeResolver::columns(static::class);

        if ($columns === []) {
            throw new NestedSetLogicException(sprintf(
                '%s::toMermaidScope/toDotScope/toAsciiTreeScope/toJsonTreeScope are only valid on scoped models. '
                .'Use the Forest variants for single-tree-per-class models.',
                static::class,
            ));
        }

        $prototype = new static;
        $query = static::query();

        $values = is_array($scopeValue)
            ? $scopeValue
            : [$columns[0] => $scopeValue];

        foreach ($columns as $col) {
            if (! array_key_exists($col, $values)) {
                throw new NestedSetInvalidArgumentException(sprintf(
                    'Missing scope value for column "%s" on %s.',
                    $col,
                    static::class,
                ));
            }
            $query->where($col, '=', $values[$col]);
        }

        $query->orderBy($prototype->getLftName());

        if ($withTrashed && in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        /** @var EloquentCollection<int, Model&HasNestedSet> $result */
        $result = $query->get();

        return $result;
    }
}
