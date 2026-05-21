<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events;

use Illuminate\Contracts\Events\Dispatcher;

/**
 * Single funnel for every package-emitted event. Gates on the
 * `nestedset.events_enabled` config flag (default `true`) so users
 * who want zero overhead can disable the entire telemetry surface
 * with one line of config.
 *
 * Kept as a static helper rather than a service because the call
 * sites are scattered across traits — a service would require
 * resolving the dispatcher from the container at every firing site,
 * which is more ceremony than the saving warrants.
 */
final class EventDispatcher
{
    /**
     * Dispatches `$event` via Laravel's global `event()` helper iff
     * telemetry is enabled. Short-circuits before constructing the
     * event when the flag is false — but the caller has usually
     * already built the value object; this is the safety net, not
     * the optimisation. Hot-path firing sites should also gate on
     * {@see self::enabled()} before constructing the event.
     *
     * @template T of object
     *
     * @param  T  $event
     */
    public static function dispatch(object $event): void
    {
        if (! self::enabled()) {
            return;
        }

        event($event);
    }

    /**
     * True when the package's telemetry events should fire. Reads
     * `nestedset.events_enabled`; defaults to true if the config
     * key is missing (e.g. user hasn't published the config file).
     */
    public static function enabled(): bool
    {
        $value = config('nestedset.events_enabled', true);

        return $value !== false;
    }

    /**
     * True when telemetry is enabled AND at least one listener is
     * registered for `$event` (or its abstract).
     *
     * Used by firing sites that have to do extra work *before*
     * dispatch (e.g. running a SELECT to gather descendant ids for
     * a cascade event) so the cost is paid only when someone is
     * actually listening. Without this gate, every mutation pays
     * the SELECT regardless of listener presence — a measurable
     * regression on query-count budgets.
     *
     * @param  class-string  $event
     */
    public static function hasListeners(string $event): bool
    {
        if (! self::enabled()) {
            return false;
        }

        return resolve(Dispatcher::class)->hasListeners($event);
    }
}
