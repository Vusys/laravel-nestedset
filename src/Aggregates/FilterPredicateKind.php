<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates;

enum FilterPredicateKind: string
{
    case Equality = 'equality';
    case NotNull = 'not_null';
    case Raw = 'raw';
}
