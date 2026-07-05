<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Exceptions;

use LogicException;
use Vusys\NestedSet\NodeTrait;

/**
 * Thrown when a model composes {@see NodeTrait} but
 * omits `implements MaintainsTreeAggregates` from its class declaration.
 *
 * The trait's `saving` lifecycle listener gates on `$node instanceof
 * MaintainsTreeAggregates` (the guard both narrows the type for the
 * aggregate calls and distinguishes NodeTrait users from bare structural
 * fixtures). Without the interface that gate used to silently return
 * early — so `callPendingAction()` never ran, `appendToNode()` /
 * `makeRoot()` / `saveAsRoot()` placed nothing, and the INSERT landed
 * with `lft = rgt = 0` (an `invalid_bounds` corruption) and no
 * {@see UnplacedNodeException}. `bulkInsertTree()` (and `treeFromShape()`
 * on top of it) still works because it sets the bounds attributes
 * directly, which masks the misconfiguration.
 *
 * The listener now throws this instead of returning early, converting
 * that silent corruption into an immediate, actionable error at the
 * first save. `@phpstan-require-implements` on the trait catches the
 * same mistake statically during `composer analyse`.
 *
 * Extends LogicException because a missing interface declaration is a
 * programmer error, fixed by editing the class — not a runtime condition.
 */
final class MisconfiguredNodeException extends LogicException implements NestedSetException {}
