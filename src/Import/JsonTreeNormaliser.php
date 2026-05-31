<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Import;

use Vusys\NestedSet\Exceptions\InvalidJsonTreeException;

/**
 * Pure shape-detection + flat→nested conversion for the JSON tree
 * importer. No database, no model — input goes in, normalised nested
 * structure comes out.
 *
 * Normalised shape: a `list<NormalisedNode>` where each node is
 * `{attributes: array<string, mixed>, children: list<NormalisedNode>}`.
 * Attributes carry every column from the payload (including `id` when
 * present); structural reshaping happens later in the importer.
 *
 * @phpstan-type NormalisedNode array{attributes: array<string, mixed>, children: list<mixed>}
 */
final class JsonTreeNormaliser
{
    /**
     * @return list<NormalisedNode>
     */
    public static function normalise(mixed $input, JsonImportOptions $options): array
    {
        $decoded = self::decode($input);
        $rows = self::wrap($decoded);
        $shape = self::detectShape($rows);

        if ($shape === 'nested') {
            return self::normaliseNested($rows, $options->childrenKey);
        }

        return self::normaliseFlat($rows);
    }

    private static function decode(mixed $input): mixed
    {
        if (is_string($input)) {
            try {
                $decoded = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new InvalidJsonTreeException(
                    'fromJsonTree: input string is not valid JSON — '.$e->getMessage(),
                    0,
                    $e,
                );
            }

            return $decoded;
        }

        return $input;
    }

    /**
     * @return list<mixed>
     */
    private static function wrap(mixed $decoded): array
    {
        if (! is_array($decoded)) {
            throw new InvalidJsonTreeException(sprintf(
                'fromJsonTree: payload must be an array or object; got %s.',
                get_debug_type($decoded),
            ));
        }

        if ($decoded === []) {
            return [];
        }

        if (array_is_list($decoded)) {
            return $decoded;
        }

        return [$decoded];
    }

    /**
     * @param  list<mixed>  $rows
     */
    private static function detectShape(array $rows): string
    {
        $hasChildren = false;
        $hasFlat = false;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new InvalidJsonTreeException(sprintf(
                    'fromJsonTree: every row must be an object; got %s.',
                    get_debug_type($row),
                ));
            }
            if (array_key_exists('children', $row)) {
                $hasChildren = true;
            } elseif (array_key_exists('parent_id', $row)) {
                $hasFlat = true;
            }
        }

        if ($hasChildren && $hasFlat) {
            throw new InvalidJsonTreeException(
                'fromJsonTree: payload shape is ambiguous — some rows have "children", others have "parent_id".',
            );
        }

        return $hasChildren ? 'nested' : 'flat';
    }

    /**
     * @param  list<mixed>  $rows
     * @return list<NormalisedNode>
     */
    private static function normaliseNested(array $rows, string $childrenKey): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::nestedRow($row, $childrenKey);
        }

        return $out;
    }

    /**
     * @return NormalisedNode
     */
    private static function nestedRow(mixed $row, string $childrenKey): array
    {
        if (! is_array($row)) {
            throw new InvalidJsonTreeException(sprintf(
                'fromJsonTree: every row must be an object; got %s.',
                get_debug_type($row),
            ));
        }

        $attrs = $row;
        $children = [];
        if (array_key_exists($childrenKey, $attrs)) {
            $raw = $attrs[$childrenKey];
            unset($attrs[$childrenKey]);
            if (! is_array($raw)) {
                throw new InvalidJsonTreeException(sprintf(
                    'fromJsonTree: "%s" must be an array of further nodes.',
                    $childrenKey,
                ));
            }
            $childList = [];
            foreach ($raw as $child) {
                $childList[] = self::nestedRow($child, $childrenKey);
            }
            $children = $childList;
        }

        /** @var array<string, mixed> $attrs */
        return ['attributes' => $attrs, 'children' => $children];
    }

    /**
     * @param  list<mixed>  $rows
     * @return list<NormalisedNode>
     */
    private static function normaliseFlat(array $rows): array
    {
        /** @var array<int|string, array{attributes: array<string, mixed>, parent: int|string|null}> $byId */
        $byId = [];
        /** @var list<int|string> $order */
        $order = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new InvalidJsonTreeException(sprintf(
                    'fromJsonTree: every row must be an object; got %s.',
                    get_debug_type($row),
                ));
            }
            if (! array_key_exists('id', $row)) {
                throw new InvalidJsonTreeException(
                    'fromJsonTree: flat-shape rows require an "id" so children can reference them.',
                );
            }
            $id = $row['id'];
            if (! is_int($id) && ! is_string($id)) {
                throw new InvalidJsonTreeException(sprintf(
                    'fromJsonTree: flat-shape "id" must be int|string; got %s.',
                    get_debug_type($id),
                ));
            }
            if (isset($byId[$id])) {
                throw new InvalidJsonTreeException(sprintf(
                    'fromJsonTree: flat-shape id %s appears more than once.',
                    self::formatScalar($id),
                ));
            }

            $parent = $row['parent_id'] ?? null;
            if ($parent !== null && ! is_int($parent) && ! is_string($parent)) {
                throw new InvalidJsonTreeException(sprintf(
                    'fromJsonTree: flat-shape parent_id on row %s must be int|string|null; got %s.',
                    self::formatScalar($id),
                    get_debug_type($parent),
                ));
            }

            /** @var array<string, mixed> $row */
            $byId[$id] = ['attributes' => $row, 'parent' => $parent];
            $order[] = $id;
        }

        /** @var array<int|string, list<int|string>> $childrenByParent */
        $childrenByParent = [];
        /** @var list<int|string> $roots */
        $roots = [];

        foreach ($order as $id) {
            $parent = $byId[$id]['parent'];
            if ($parent === null) {
                $roots[] = $id;

                continue;
            }
            if (! isset($byId[$parent])) {
                throw new InvalidJsonTreeException(sprintf(
                    'fromJsonTree: flat-shape row %s references parent_id %s which is not in the payload.',
                    self::formatScalar($id),
                    self::formatScalar($parent),
                ));
            }
            $childrenByParent[$parent][] = $id;
        }

        self::assertNoFlatCycle($byId, $order);

        $assemble = static function (int|string $id) use (&$assemble, $byId, $childrenByParent): array {
            $node = $byId[$id];
            $children = [];
            foreach ($childrenByParent[$id] ?? [] as $cid) {
                $children[] = $assemble($cid);
            }

            return ['attributes' => $node['attributes'], 'children' => $children];
        };

        $out = [];
        foreach ($roots as $rootId) {
            $out[] = $assemble($rootId);
        }

        return $out;
    }

    /**
     * @param  array<int|string, array{attributes: array<string, mixed>, parent: int|string|null}>  $byId
     * @param  list<int|string>  $order
     */
    private static function assertNoFlatCycle(array $byId, array $order): void
    {
        foreach ($order as $id) {
            $cursor = $id;
            $seen = [self::formatScalar($cursor) => true];
            while (true) {
                $parent = $byId[$cursor]['parent'];
                if ($parent === null) {
                    break;
                }
                if (! isset($byId[$parent])) {
                    break;
                }
                if (isset($seen[self::formatScalar($parent)])) {
                    throw new InvalidJsonTreeException(sprintf(
                        'fromJsonTree: cycle detected in flat shape at id %s.',
                        self::formatScalar($parent),
                    ));
                }
                $seen[self::formatScalar($parent)] = true;
                $cursor = $parent;
            }
        }
    }

    private static function formatScalar(int|string $v): string
    {
        return is_int($v) ? (string) $v : '"'.$v.'"';
    }
}
