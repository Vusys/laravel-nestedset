<?php

declare(strict_types=1);

namespace Vusys\NestedSet;

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
