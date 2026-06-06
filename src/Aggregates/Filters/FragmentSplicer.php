<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\Filters;

/**
 * Adapter that lets string-based SQL emitters thread a `BoundFragment`
 * predicate without rewriting their internals.
 *
 * The emitter is invoked once with a unique sentinel substituted for
 * the predicate text. The splicer then counts sentinel occurrences in
 * the produced SQL, swaps them for `$filter->sql`, and repeats
 * `$filter->bindings` once per occurrence so positional `?` placeholders
 * stay aligned with their values.
 *
 * Null bytes are not legal in SQL we emit, so they're safe as the
 * sentinel even if the surrounding fragments are subsequently
 * spliced through additional layers — only the textual placeholder
 * the caller injected can match.
 */
final class FragmentSplicer
{
    private const string SENTINEL = "\x00NSFILT\x00";

    /**
     * @param  callable(?string): string  $emit  Receives the sentinel
     *                                           (or null for unfiltered) and returns SQL with the
     *                                           sentinel embedded wherever the predicate should appear.
     */
    public static function splice(?BoundFragment $filter, callable $emit): BoundFragment
    {
        if (! $filter instanceof BoundFragment) {
            return new BoundFragment($emit(null));
        }

        $template = $emit(self::SENTINEL);

        if ($filter->bindings === []) {
            return new BoundFragment(str_replace(self::SENTINEL, $filter->sql, $template));
        }

        $count = substr_count($template, self::SENTINEL);
        $sql = str_replace(self::SENTINEL, $filter->sql, $template);

        $bindings = [];
        for ($i = 0; $i < $count; $i++) {
            foreach ($filter->bindings as $value) {
                $bindings[] = $value;
            }
        }

        return new BoundFragment($sql, $bindings);
    }
}
