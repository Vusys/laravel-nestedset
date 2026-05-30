<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Vusys\NestedSet\Testing\BuildsNestedSetTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\MenuItem;

/**
 * @extends Factory<MenuItem>
 */
final class MenuItemFactory extends Factory
{
    /** @use BuildsNestedSetTrees<MenuItem> */
    use BuildsNestedSetTrees;

    /** @var class-string<MenuItem> */
    protected $model = MenuItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
        ];
    }
}
