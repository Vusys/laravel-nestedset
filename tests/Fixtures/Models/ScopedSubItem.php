<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

/**
 * Subclass of a scoped model that declares no scope of its own — it must
 * inherit {@see ScopedBaseItem}'s `#[NestedSetScope('menu_id')]`.
 */
final class ScopedSubItem extends ScopedBaseItem {}
