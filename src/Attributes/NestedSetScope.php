<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Attributes;

use Attribute;
use Vusys\NestedSet\Exceptions\ScopeViolationException;

/**
 * Declares the scope column(s) that partition a multi-tree table.
 *
 * Place on the model class:
 *
 *     #[NestedSetScope('menu_id')]
 *     class MenuItem extends Model implements HasNestedSet
 *
 * Multi-column scopes are supported via an array — pass them in the
 * order you'd put them in a composite index:
 *
 *     #[NestedSetScope(['tenant_id', 'menu_id'])]
 *
 * The package reads these via reflection and constrains every internal
 * write (gap creation, move, repair) to rows matching the per-instance
 * values of these columns. Cross-scope writes throw
 * {@see ScopeViolationException}.
 */
#[Attribute(Attribute::TARGET_CLASS)]
readonly class NestedSetScope
{
    /** @param string|list<string> $columns */
    public function __construct(
        public string|array $columns,
    ) {}
}
