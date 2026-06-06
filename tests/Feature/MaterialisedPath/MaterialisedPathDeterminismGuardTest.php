<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\MaterialisedPath;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\Exceptions\NonDeterministicPathSegment;
use Vusys\NestedSet\MaterialisedPath\MaterialisedPath;
use Vusys\NestedSet\MaterialisedPath\MaterialisedPathRegistry;
use Vusys\NestedSet\NodeTrait;
use Vusys\NestedSet\Tests\TestCase;

/**
 * @property int $id
 * @property string $name
 * @property string|null $p
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 */
final class NonDeterministicNode extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

    public $timestamps = false;

    protected $table = 'non_det_nodes';

    /** @var list<string> */
    protected $fillable = ['name'];

    /** @return array<string, MaterialisedPath> */
    protected static function materialisedPaths(): array
    {
        return [
            'p' => MaterialisedPath::from(static fn (self $n): string => 'v'.random_int(1, 1_000_000)),
        ];
    }
}

final class MaterialisedPathDeterminismGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        MaterialisedPathRegistry::forgetCache();
        Schema::create('non_det_nodes', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('p', 255)->nullable();
            $t->nestedSet();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('non_det_nodes');
        parent::tearDown();
    }

    #[Test]
    public function non_deterministic_builder_throws_in_debug_mode(): void
    {
        config(['app.debug' => true]);

        $this->expectException(NonDeterministicPathSegment::class);
        $this->expectExceptionMessageMatches('/different values on repeated calls/');

        $node = new NonDeterministicNode(['name' => 'r']);
        $node->makeRoot()->save();
    }

    #[Test]
    public function non_deterministic_builder_does_not_throw_when_debug_is_false(): void
    {
        config(['app.debug' => false]);

        $node = new NonDeterministicNode(['name' => 'r']);
        $node->makeRoot()->save();

        $this->assertNotNull($node->refresh()->p);
    }
}
