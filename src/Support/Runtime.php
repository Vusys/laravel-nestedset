<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Thin accessor for the few framework services the package needs (config
 * repository, event dispatcher, container bindings).
 *
 * Why this exists: the package previously reached for the global
 * `config()` / `event()` / `resolve()` helpers, which are defined by
 * `laravel/framework` — a package the composer constraints don't (and
 * shouldn't, for a library) require. Routing through the container
 * (`illuminate/container`) and the config/events contracts keeps the
 * footprint at the `illuminate/*` level the manifest actually declares,
 * and degrades gracefully (returning the default / no-op) when a service
 * isn't bound rather than fataling on an undefined function.
 */
final class Runtime
{
    /**
     * Reads a config value, returning `$default` when the config service
     * isn't bound (e.g. the package is exercised outside a booted app).
     */
    public static function config(string $key, mixed $default = null): mixed
    {
        $config = self::bound('config');

        return $config instanceof ConfigRepository ? $config->get($key, $default) : $default;
    }

    /**
     * The event dispatcher, or null when events aren't bound.
     */
    public static function events(): ?Dispatcher
    {
        $events = self::bound('events');

        return $events instanceof Dispatcher ? $events : null;
    }

    private static function bound(string $abstract): mixed
    {
        $container = Container::getInstance();

        return $container->bound($abstract) ? $container->make($abstract) : null;
    }
}
