<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events;

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
}
