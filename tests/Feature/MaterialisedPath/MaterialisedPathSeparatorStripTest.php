<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\MaterialisedPath;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\Exceptions\EmptyPathSegmentException;
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
final class StripSepNode extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

    public $timestamps = false;

    protected $table = 'strip_sep_nodes';

    /** @var list<string> */
    protected $fillable = ['name'];

    /** @return array<string, MaterialisedPath> */
    protected static function materialisedPaths(): array
    {
        return [
            'p' => MaterialisedPath::attribute('name')
                ->separator('-')
                ->wrap(false)
                ->rejectSeparatorInSegment(false),
        ];
    }
}

final class MaterialisedPathSeparatorStripTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        MaterialisedPathRegistry::forgetCache();
        Schema::create('strip_sep_nodes', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('p', 255)->nullable();
            $t->nestedSet();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('strip_sep_nodes');
        parent::tearDown();
    }

    #[Test]
    public function separators_are_stripped_silently_when_rejection_is_off(): void
    {
        $node = new StripSepNode(['name' => 'a-b-c']);
        $node->makeRoot()->save();
        $node->refresh();
        $this->assertSame('abc', $node->p);
    }

    #[Test]
    public function segment_made_empty_by_strip_still_throws(): void
    {
        $this->expectException(EmptyPathSegmentException::class);
        $this->expectExceptionMessageMatches('/empty after stripping the separator/');

        $node = new StripSepNode(['name' => '---']);
        $node->makeRoot()->save();
    }
}
