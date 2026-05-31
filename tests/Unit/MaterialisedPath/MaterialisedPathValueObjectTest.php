<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\MaterialisedPath;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\MaterialisedPath\MaterialisedPath;

final class MaterialisedPathValueObjectTest extends TestCase
{
    public function test_key_factory_marks_path_as_key_dependent(): void
    {
        $path = MaterialisedPath::key();
        $this->assertTrue($path->isDependentOnKey());
        $this->assertSame(MaterialisedPath::SOURCE_KEY, $path->sourceKind());
    }

    public function test_attribute_factory_is_not_key_dependent(): void
    {
        $path = MaterialisedPath::attribute('name');
        $this->assertFalse($path->isDependentOnKey());
        $this->assertSame(MaterialisedPath::SOURCE_ATTRIBUTE, $path->sourceKind());
        $this->assertSame('name', $path->sourceColumn());
    }

    public function test_slug_factory_lowercases_via_str_slug(): void
    {
        $path = MaterialisedPath::slug('title');
        $model = new class extends Model
        {
            protected $guarded = [];
        };
        $model->forceFill(['title' => 'Hello World!']);

        $this->assertSame('hello-world', $path->segmentFor($model));
    }

    public function test_from_closure_is_not_key_dependent_by_default(): void
    {
        $path = MaterialisedPath::from(static fn (Model $m): string => 'static');
        $this->assertFalse($path->isDependentOnKey());
    }

    public function test_depends_on_key_opt_in_is_sticky(): void
    {
        $path = MaterialisedPath::from(static fn (Model $m): string => 'x')->dependsOnKey();
        $this->assertTrue($path->isDependentOnKey());
    }

    public function test_fluent_setters_return_a_new_instance(): void
    {
        $a = MaterialisedPath::slug('name');
        $b = $a->separator('.');
        $c = $b->wrap(false);
        $d = $c->maxLength(42);

        $this->assertNotSame($a, $b);
        $this->assertNotSame($b, $c);
        $this->assertNotSame($c, $d);

        $this->assertSame('/', $a->getSeparator(), 'original is untouched');
        $this->assertSame('.', $b->getSeparator());
        $this->assertTrue($a->getWrap());
        $this->assertFalse($c->getWrap());
        $this->assertSame(42, $d->getMaxLength());
        $this->assertSame(1024, $a->getMaxLength(), 'package fallback default');
    }

    public function test_with_resolved_defaults_only_fills_nulls(): void
    {
        $path = MaterialisedPath::slug('name')->separator('.');
        $resolved = $path->withResolvedDefaults([
            'separator' => '/',
            'wrap' => false,
            'maxLength' => 256,
        ]);

        $this->assertSame('.', $resolved->getSeparator(), 'explicit value sticks');
        $this->assertFalse($resolved->getWrap(), 'null filled from defaults');
        $this->assertSame(256, $resolved->getMaxLength(), 'null filled from defaults');
    }

    public function test_segment_for_handles_int_keys(): void
    {
        $path = MaterialisedPath::key();
        $model = new class extends Model
        {
            protected $guarded = [];

            public function getKey()
            {
                return 17;
            }
        };

        $this->assertSame('17', $path->segmentFor($model));
    }
}
