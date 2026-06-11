<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Exceptions;

use RuntimeException;

/**
 * Internal control-flow signal: a `saving` / `creating` / `updating`
 * listener cancelled the save by returning `false`, the standard Laravel
 * idiom. The trait's own `saving` listener has by then already run the
 * structural SQL (makeGap / moveNode) inside the auto-transaction, which
 * only rolls back on an exception — so {@see HasTreeMutation::save()}
 * throws this to force the rollback, then catches it and returns `false`
 * to the caller exactly as a cancelled Eloquent save would.
 *
 * Never escapes save(); not part of the public API.
 *
 * @internal
 */
final class SaveCancelledException extends RuntimeException implements NestedSetException {}
