<?php

declare(strict_types=1);

namespace Vusys\NestedSet\PHPStan;

use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\PropertiesClassReflectionExtension;
use PHPStan\Reflection\PropertyReflection;
use PHPStan\Type\IntegerType;
use PHPStan\Type\NullType;
use PHPStan\Type\TypeCombinator;
use Vusys\NestedSet\NodeTrait;

/**
 * Tells PHPStan about the four columns `$table->nestedSet()` adds, on every
 * model that uses NodeTrait. Larastan infers model properties from
 * migrations but can't see what a Blueprint macro adds, so without this
 * `$node->parent_id` is an undefined property at level 9.
 *
 * Column names default to the package's; override them to match a changed
 * `nestedset.columns` config:
 *
 * ```neon
 * parameters:
 *     nestedset:
 *         columns: {lft: _lft, rgt: _rgt, parent_id: parent_id, depth: depth}
 * ```
 */
final readonly class NodeColumnsExtension implements PropertiesClassReflectionExtension
{
    /**
     * @param  array{lft?: string, rgt?: string, parent_id?: string, depth?: string}  $columns
     */
    public function __construct(private array $columns = []) {}

    public function hasProperty(ClassReflection $classReflection, string $propertyName): bool
    {
        return $this->column($propertyName) !== null && $this->usesNodeTrait($classReflection);
    }

    public function getProperty(ClassReflection $classReflection, string $propertyName): PropertyReflection
    {
        $type = $this->column($propertyName) === 'parent_id'
            ? TypeCombinator::union(new IntegerType, new NullType)
            : new IntegerType;

        return new NodeColumnProperty($classReflection, $type);
    }

    /** Which of the four columns $propertyName is, if any. */
    private function column(string $propertyName): ?string
    {
        foreach (['lft', 'rgt', 'parent_id', 'depth'] as $column) {
            if (($this->columns[$column] ?? $column) === $propertyName) {
                return $column;
            }
        }

        return null;
    }

    private function usesNodeTrait(ClassReflection $classReflection): bool
    {
        for ($class = $classReflection; $class instanceof ClassReflection; $class = $class->getParentClass()) {
            if (array_key_exists(NodeTrait::class, $class->getTraits(true))) {
                return true;
            }
        }

        return false;
    }
}
