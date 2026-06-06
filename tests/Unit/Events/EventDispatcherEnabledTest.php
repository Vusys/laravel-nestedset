<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Events;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Events\EventDispatcher;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Pins the truthiness contract of {@see EventDispatcher::enabled()}.
 *
 * Implementation: `$value !== false`. Documented in
 * `config/nestedset.php`: "Set to false to short-circuit every firing
 * site." The predicate's specific shape — only literal `false`
 * disables — matters because users who reach for "falsy" values
 * (`0`, `null`, missing config key) get the opposite of what they
 * expect: events stay on.
 *
 * Pins each case so a future refactor that switches to `(bool)
 * $value === true` (which would treat `0` and `null` as disabled)
 * is a deliberate change, not a silent regression.
 */
final class EventDispatcherEnabledTest extends TestCase
{
    #[Test]
    public function default_when_config_missing_is_enabled(): void
    {
        config()->set('nestedset.events_enabled');
        // null != false → enabled. Means a published-but-incomplete
        // config doesn't accidentally silence telemetry.
        $this->assertTrue(EventDispatcher::enabled(), 'missing/null config defaults to enabled');
    }

    #[Test]
    public function explicit_true_is_enabled(): void
    {
        config()->set('nestedset.events_enabled', true);

        $this->assertTrue(EventDispatcher::enabled());
    }

    #[Test]
    public function explicit_false_is_disabled(): void
    {
        config()->set('nestedset.events_enabled', false);

        $this->assertFalse(EventDispatcher::enabled());
    }

    #[Test]
    public function integer_zero_does_not_disable(): void
    {
        // Documents the surprising case: env('NESTEDSET_EVENTS', 0)
        // does NOT disable events because 0 !== false. The user-facing
        // disable is the literal boolean false.
        config()->set('nestedset.events_enabled', 0);

        $this->assertTrue(
            EventDispatcher::enabled(),
            'integer 0 in the config does not disable events — only literal false does',
        );
    }

    #[Test]
    public function empty_string_does_not_disable(): void
    {
        config()->set('nestedset.events_enabled', '');

        $this->assertTrue(
            EventDispatcher::enabled(),
            'empty string in the config does not disable events',
        );
    }

    #[Test]
    public function string_false_does_not_disable(): void
    {
        // env('NESTEDSET_EVENTS', 'false') returns the literal string
        // 'false' (not the boolean). Pinning that this still leaves
        // events on so users who hit this find the bug in *their*
        // config, not their assumption about the library.
        config()->set('nestedset.events_enabled', 'false');

        $this->assertTrue(
            EventDispatcher::enabled(),
            'string "false" in the config does not disable events — convert via env() casting first',
        );
    }
}
