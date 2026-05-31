<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\MaterialisedPath;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Exceptions\MaterialisedPathConfigurationException;
use Vusys\NestedSet\MaterialisedPath\MaterialisedPath;

final class MaterialisedPathValueObjectAdvancedTest extends TestCase
{
    public function test_attribute_factory_rejects_empty_column(): void
    {
        $this->expectException(MaterialisedPathConfigurationException::class);
        MaterialisedPath::attribute('');
    }

    public function test_slug_factory_rejects_empty_column(): void
    {
        $this->expectException(MaterialisedPathConfigurationException::class);
        MaterialisedPath::slug('');
    }

    public function test_reject_separator_in_segment_setter_returns_new_instance(): void
    {
        $a = MaterialisedPath::slug('name');
        $b = $a->rejectSeparatorInSegment(false);
        $this->assertTrue($a->getRejectSeparatorInSegment());
        $this->assertFalse($b->getRejectSeparatorInSegment());
        $this->assertNotSame($a, $b);
    }

    public function test_unique_per_parent_setter_returns_new_instance(): void
    {
        $a = MaterialisedPath::slug('name');
        $b = $a->uniquePerParent(false);
        $this->assertTrue($a->getUniquePerParent());
        $this->assertFalse($b->getUniquePerParent());
        $this->assertNotSame($a, $b);
    }

    public function test_segment_for_handles_stringable_objects(): void
    {
        $path = MaterialisedPath::from(static fn (Model $m): \Stringable => new class implements \Stringable
        {
            public function __toString(): string
            {
                return 'stringable-segment';
            }
        });
        $node = new class extends Model
        {
            protected $guarded = [];
        };
        $this->assertSame('stringable-segment', $path->segmentFor($node));
    }

    public function test_segment_for_throws_when_closure_returns_non_stringable_object(): void
    {
        $path = MaterialisedPath::from(static fn (Model $m): object => new \stdClass);
        $node = new class extends Model
        {
            protected $guarded = [];
        };

        $this->expectException(MaterialisedPathConfigurationException::class);
        $this->expectExceptionMessageMatches('/non-stringable/');
        $path->segmentFor($node);
    }

    public function test_segment_for_coerces_numeric_attribute(): void
    {
        $path = MaterialisedPath::attribute('weight');
        $node = new class extends Model
        {
            protected $guarded = [];
        };
        $node->forceFill(['weight' => 42]);
        $this->assertSame('42', $path->segmentFor($node));
    }

    public function test_segment_for_returns_empty_string_for_null_attribute(): void
    {
        $path = MaterialisedPath::attribute('missing');
        $node = new class extends Model
        {
            protected $guarded = [];
        };
        $this->assertSame('', $path->segmentFor($node));
    }

    public function test_depends_on_key_off_when_passed_false(): void
    {
        $a = MaterialisedPath::key();
        $this->assertTrue($a->isDependentOnKey());
        $b = $a->dependsOnKey(false);
        $this->assertFalse($b->isDependentOnKey());
    }

    public function test_with_resolved_defaults_ignores_unset_keys(): void
    {
        $a = MaterialisedPath::slug('name');
        $b = $a->withResolvedDefaults([]);
        $this->assertSame('/', $b->getSeparator(), 'falls back to package default');
        $this->assertTrue($b->getWrap());
    }
}
