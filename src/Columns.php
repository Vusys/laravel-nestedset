<?php

declare(strict_types=1);

namespace Vusys\NestedSet;

/**
 * Default column names for the four nested-set values.
 *
 * Used as fallbacks when no `config('nestedset.columns.*')` override is
 * present. To change column names project-wide, edit the published
 * config file rather than these constants.
 */
final class Columns
{
    const string LFT = 'lft';

    const string RGT = 'rgt';

    const string PARENT_ID = 'parent_id';

    const string DEPTH = 'depth';

    private function __construct() {}

    /** @return list<string> */
    public static function all(): array
    {
        return [self::LFT, self::RGT, self::PARENT_ID, self::DEPTH];
    }
}
