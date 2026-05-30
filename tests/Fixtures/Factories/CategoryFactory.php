<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Vusys\NestedSet\Testing\BuildsNestedSetTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;

/**
 * @extends Factory<Category>
 */
final class CategoryFactory extends Factory
{
    /** @use BuildsNestedSetTrees<Category> */
    use BuildsNestedSetTrees;

    /** @var class-string<Category> */
    protected $model = Category::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
        ];
    }
}
