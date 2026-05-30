<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Vusys\NestedSet\Testing\BuildsNestedSetTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;

/**
 * @extends Factory<Area>
 */
final class AreaFactory extends Factory
{
    /** @use BuildsNestedSetTrees<Area> */
    use BuildsNestedSetTrees;

    /** @var class-string<Area> */
    protected $model = Area::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'tickets' => fake()->numberBetween(1, 100),
        ];
    }
}
