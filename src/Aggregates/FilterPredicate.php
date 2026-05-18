<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates;

use Vusys\NestedSet\Exceptions\AggregateConfigurationException;

/**
 * Immutable value object describing a row-level filter applied when
 * computing an aggregate over the subtree.
 *
 * Construct via the three factory methods; the constructor is private.
 */
final readonly class FilterPredicate
{
    /**
     * @param  array<string,mixed>  $conditions
     * @param  list<string>         $watches
     */
    private function __construct(
        private FilterPredicateKind $kind,
        private array $conditions,
        private ?string $notNullColumn,
        private ?string $rawSql,
        private array $watches,
    ) {}

    /**
     * Filter rows by equality on the given column/value pairs.
     * At least one condition must be provided.
     *
     * @param  array<string,mixed>  $conditions
     * @throws AggregateConfigurationException when $conditions is empty.
     */
    public static function equality(array $conditions): self
    {
        if ($conditions === []) {
            throw new AggregateConfigurationException(
                'FilterPredicate::equality() requires at least one condition.',
            );
        }

        return new self(
            kind: FilterPredicateKind::Equality,
            conditions: $conditions,
            notNullColumn: null,
            rawSql: null,
            watches: array_keys($conditions),
        );
    }

    /**
     * Filter rows where $column IS NOT NULL.
     */
    public static function notNull(string $column): self
    {
        return new self(
            kind: FilterPredicateKind::NotNull,
            conditions: [],
            notNullColumn: $column,
            rawSql: null,
            watches: [$column],
        );
    }

    /**
     * Filter rows by a raw SQL expression. Watch columns must be passed
     * explicitly; an empty list is valid for expressions with no column
     * dependencies.
     *
     * @param  list<string>  $watches
     */
    public static function raw(string $sql, array $watches = []): self
    {
        return new self(
            kind: FilterPredicateKind::Raw,
            conditions: [],
            notNullColumn: null,
            rawSql: $sql,
            watches: $watches,
        );
    }

    public function getKind(): FilterPredicateKind
    {
        return $this->kind;
    }

    /** @return array<string,mixed> */
    public function getConditions(): array
    {
        return $this->conditions;
    }

    public function getNotNullColumn(): ?string
    {
        return $this->notNullColumn;
    }

    public function getRawSql(): ?string
    {
        return $this->rawSql;
    }

    /** @return list<string> */
    public function watchColumns(): array
    {
        return $this->watches;
    }

    /**
     * Evaluates the predicate against a set of model attributes.
     * Returns null for Raw predicates (cannot be evaluated in PHP).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function evaluateFor(array $attributes): ?bool
    {
        return match ($this->kind) {
            FilterPredicateKind::Equality => (function () use ($attributes): bool {
                foreach ($this->conditions as $col => $value) {
                    if (($attributes[$col] ?? null) != $value) {
                        return false;
                    }
                }

                return true;
            })(),
            FilterPredicateKind::NotNull => ($attributes[$this->notNullColumn ?? ''] ?? null) !== null,
            FilterPredicateKind::Raw => null,
        };
    }
}
