<?php

declare(strict_types=1);

namespace Vusys\NestedSet\MaterialisedPath;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Vusys\NestedSet\Exceptions\MaterialisedPathConfigurationException;

/**
 * Per-path declaration: a segment builder plus per-column formatting
 * options. Immutable — every fluent setter returns a new instance.
 *
 * The value object is built once per (class, column) pair by
 * {@see MaterialisedPathRegistry} and cached for the process lifetime.
 *
 * Fields that the user never touched stay `null`, which signals "fall
 * through to the defaults chain" — the registry calls
 * {@see self::withResolvedDefaults()} to fill them before handing the
 * instance to the lifecycle concern.
 */
final class MaterialisedPath
{
    public const string SOURCE_KEY = 'key';

    public const string SOURCE_ATTRIBUTE = 'attribute';

    public const string SOURCE_SLUG = 'slug';

    public const string SOURCE_CLOSURE = 'closure';

    private function __construct(
        private readonly string $sourceKind,
        private readonly ?string $sourceColumn,
        private readonly ?Closure $builder,
        private ?string $separator = null,
        private ?bool $wrap = null,
        private ?int $maxLength = null,
        private ?bool $rejectSeparatorInSegment = null,
        private ?bool $uniquePerParent = null,
        private bool $dependsOnKey = false,
    ) {}

    public static function key(): self
    {
        return new self(
            sourceKind: self::SOURCE_KEY,
            sourceColumn: null,
            builder: null,
            dependsOnKey: true,
        );
    }

    public static function attribute(string $column): self
    {
        if ($column === '') {
            throw new MaterialisedPathConfigurationException(
                'MaterialisedPath::attribute() requires a non-empty column name.',
            );
        }

        return new self(
            sourceKind: self::SOURCE_ATTRIBUTE,
            sourceColumn: $column,
            builder: null,
        );
    }

    public static function slug(string $column): self
    {
        if ($column === '') {
            throw new MaterialisedPathConfigurationException(
                'MaterialisedPath::slug() requires a non-empty column name.',
            );
        }

        return new self(
            sourceKind: self::SOURCE_SLUG,
            sourceColumn: $column,
            builder: null,
        );
    }

    public static function from(callable $builder): self
    {
        return new self(
            sourceKind: self::SOURCE_CLOSURE,
            sourceColumn: null,
            builder: $builder instanceof Closure ? $builder : Closure::fromCallable($builder),
        );
    }

    public function separator(string $sep): self
    {
        $copy = clone $this;
        $copy->separator = $sep;

        return $copy;
    }

    public function wrap(bool $wrap): self
    {
        $copy = clone $this;
        $copy->wrap = $wrap;

        return $copy;
    }

    public function maxLength(int $max): self
    {
        $copy = clone $this;
        $copy->maxLength = $max;

        return $copy;
    }

    public function rejectSeparatorInSegment(bool $reject): self
    {
        $copy = clone $this;
        $copy->rejectSeparatorInSegment = $reject;

        return $copy;
    }

    public function uniquePerParent(bool $unique): self
    {
        $copy = clone $this;
        $copy->uniquePerParent = $unique;

        return $copy;
    }

    public function dependsOnKey(bool $depends = true): self
    {
        $copy = clone $this;
        $copy->dependsOnKey = $depends;

        return $copy;
    }

    public function isDependentOnKey(): bool
    {
        return $this->dependsOnKey;
    }

    public function sourceKind(): string
    {
        return $this->sourceKind;
    }

    public function sourceColumn(): ?string
    {
        return $this->sourceColumn;
    }

    public function getSeparator(): string
    {
        return $this->separator ?? '/';
    }

    public function getWrap(): bool
    {
        return $this->wrap ?? true;
    }

    public function getMaxLength(): int
    {
        return $this->maxLength ?? 1024;
    }

    public function getRejectSeparatorInSegment(): bool
    {
        return $this->rejectSeparatorInSegment ?? true;
    }

    public function getUniquePerParent(): bool
    {
        return $this->uniquePerParent ?? true;
    }

    /**
     * Computes the raw segment string for a node. Pure function of the
     * node's persisted attributes — the determinism guard checks this in
     * dev.
     */
    public function segmentFor(Model $node): string
    {
        return match ($this->sourceKind) {
            self::SOURCE_KEY => $this->stringify($node->getKey()),
            self::SOURCE_ATTRIBUTE => $this->stringify($node->getAttribute($this->sourceColumn ?? '')),
            self::SOURCE_SLUG => Str::slug($this->stringify($node->getAttribute($this->sourceColumn ?? ''))),
            self::SOURCE_CLOSURE => $this->invokeBuilder($node),
            default => throw new MaterialisedPathConfigurationException(
                "Unknown segment source kind '{$this->sourceKind}'.",
            ),
        };
    }

    private function invokeBuilder(Model $node): string
    {
        if (! $this->builder instanceof Closure) {
            throw new MaterialisedPathConfigurationException(
                'MaterialisedPath closure source declared without a builder.',
            );
        }

        return $this->stringify(($this->builder)($node));
    }

    /**
     * Fills in null fields from a defaults array. Used by the registry
     * after merging the five-layer defaults chain.
     *
     * @param  array{separator?: ?string, wrap?: ?bool, maxLength?: ?int, rejectSeparatorInSegment?: ?bool, uniquePerParent?: ?bool}  $defaults
     */
    public function withResolvedDefaults(array $defaults): self
    {
        $copy = clone $this;
        if ($copy->separator === null && isset($defaults['separator'])) {
            $copy->separator = $defaults['separator'];
        }
        if ($copy->wrap === null && isset($defaults['wrap'])) {
            $copy->wrap = $defaults['wrap'];
        }
        if ($copy->maxLength === null && isset($defaults['maxLength'])) {
            $copy->maxLength = $defaults['maxLength'];
        }
        if ($copy->rejectSeparatorInSegment === null && isset($defaults['rejectSeparatorInSegment'])) {
            $copy->rejectSeparatorInSegment = $defaults['rejectSeparatorInSegment'];
        }
        if ($copy->uniquePerParent === null && isset($defaults['uniquePerParent'])) {
            $copy->uniquePerParent = $defaults['uniquePerParent'];
        }

        return $copy;
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        throw new MaterialisedPathConfigurationException(
            'Materialised path segment builder returned a non-stringable value of type '.get_debug_type($value).'.',
        );
    }
}
