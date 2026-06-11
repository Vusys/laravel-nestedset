<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Exceptions;

use LogicException;
use Vusys\NestedSet\Concerns\HasTreeMutation;

/**
 * Thrown when the `$idsInOrder` argument to
 * {@see HasTreeMutation::reorderChildren()}
 * does not match the parent's current direct-child membership exactly:
 *
 *  - a key in `$idsInOrder` is not a direct child of the parent,
 *  - a direct child of the parent is missing from `$idsInOrder`,
 *  - `$idsInOrder` contains duplicate keys.
 *
 * The reorder is strict by design (no partial / sparse reorders) so
 * callers can't silently leave siblings in the wrong place.
 *
 * Use the named constructors so the failure message points at the
 * specific offending keys.
 *
 * Extends LogicException because a reorder call with the wrong
 * membership is a programmer error, not a runtime/data problem.
 */
final class InvalidSiblingOrderException extends LogicException implements NestedSetException
{
    /**
     * @param  list<int|string>  $keys
     */
    public static function unknownChildren(array $keys): self
    {
        return new self(sprintf(
            'reorderChildren() received key(s) [%s] that are not direct children of the parent.',
            implode(', ', array_map(static fn (int|string $k): string => (string) $k, $keys)),
        ));
    }

    /**
     * @param  list<int|string>  $keys
     */
    public static function missingChildren(array $keys): self
    {
        return new self(sprintf(
            'reorderChildren() is missing direct child key(s) [%s]; the supplied order must include every child.',
            implode(', ', array_map(static fn (int|string $k): string => (string) $k, $keys)),
        ));
    }

    /**
     * @param  list<int|string>  $keys
     */
    public static function duplicateChildren(array $keys): self
    {
        return new self(sprintf(
            'reorderChildren() received duplicate key(s) [%s]; each direct child must appear exactly once.',
            implode(', ', array_map(static fn (int|string $k): string => (string) $k, $keys)),
        ));
    }
}
