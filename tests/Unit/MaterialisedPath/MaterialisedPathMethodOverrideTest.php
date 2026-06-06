<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\MaterialisedPath;

use Illuminate\Database\Eloquent\Model;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Attributes\NestedSetMaterialisedPath;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\Exceptions\MaterialisedPathConfigurationException;
use Vusys\NestedSet\MaterialisedPath\MaterialisedPath;
use Vusys\NestedSet\MaterialisedPath\MaterialisedPathRegistry;
use Vusys\NestedSet\NodeTrait;

#[NestedSetMaterialisedPath(column: 'a_path', slug: 'name')]
final class MethodOverrideMixed extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

    /** @return array<string, MaterialisedPath|callable|string> */
    protected static function materialisedPaths(): array
    {
        return [
            'b_path' => 'title',                               // string -> attribute()
            'c_path' => static fn (Model $m): string => 'cc', // closure -> from()
            'd_path' => MaterialisedPath::key()->separator('.'),
            // Override the attribute-declared a_path with a value object — method wins.
            'a_path' => MaterialisedPath::attribute('display_name'),
        ];
    }
}

final class MethodOverrideInstanceMethod extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

    /** @return array<string, MaterialisedPath|callable|string> */
    protected function materialisedPaths(): array
    {
        return ['x' => 'name'];
    }
}

final class MethodOverrideNonArray extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

    protected static function materialisedPaths(): string
    {
        return 'oops';
    }
}

final class MethodOverrideEmptyKey extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

    /** @return array<int|string, mixed> */
    protected static function materialisedPaths(): array
    {
        return [0 => MaterialisedPath::attribute('name')];
    }
}

final class MethodOverrideBadEntry extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

    /** @return array<string, mixed> */
    protected static function materialisedPaths(): array
    {
        return ['x' => 42];
    }
}

final class MaterialisedPathMethodOverrideTest extends OrchestraTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        MaterialisedPathRegistry::forgetCache();
    }

    #[Test]
    public function method_form_merges_with_attribute_form(): void
    {
        $paths = MaterialisedPathRegistry::for(MethodOverrideMixed::class);
        $this->assertSame(['a_path', 'b_path', 'c_path', 'd_path'], array_keys($paths));
    }

    #[Test]
    public function method_form_wins_on_column_collision(): void
    {
        $paths = MaterialisedPathRegistry::for(MethodOverrideMixed::class);
        // The attribute declared slug:'name'; the method-form override sets attribute:'display_name'.
        $this->assertSame(MaterialisedPath::SOURCE_ATTRIBUTE, $paths['a_path']->sourceKind());
        $this->assertSame('display_name', $paths['a_path']->sourceColumn());
    }

    #[Test]
    public function string_auto_wraps_to_attribute(): void
    {
        $paths = MaterialisedPathRegistry::for(MethodOverrideMixed::class);
        $this->assertSame(MaterialisedPath::SOURCE_ATTRIBUTE, $paths['b_path']->sourceKind());
        $this->assertSame('title', $paths['b_path']->sourceColumn());
    }

    #[Test]
    public function closure_auto_wraps_to_from(): void
    {
        $paths = MaterialisedPathRegistry::for(MethodOverrideMixed::class);
        $this->assertSame(MaterialisedPath::SOURCE_CLOSURE, $paths['c_path']->sourceKind());
    }

    #[Test]
    public function value_object_passes_through(): void
    {
        $paths = MaterialisedPathRegistry::for(MethodOverrideMixed::class);
        $this->assertSame(MaterialisedPath::SOURCE_KEY, $paths['d_path']->sourceKind());
        $this->assertSame('.', $paths['d_path']->getSeparator());
    }

    #[Test]
    public function non_static_method_throws(): void
    {
        $this->expectException(MaterialisedPathConfigurationException::class);
        $this->expectExceptionMessageMatches('/protected static/');
        MaterialisedPathRegistry::for(MethodOverrideInstanceMethod::class);
    }

    #[Test]
    public function method_returning_non_array_throws(): void
    {
        $this->expectException(MaterialisedPathConfigurationException::class);
        $this->expectExceptionMessageMatches('/must return array/');
        MaterialisedPathRegistry::for(MethodOverrideNonArray::class);
    }

    #[Test]
    public function method_with_non_string_column_key_throws(): void
    {
        $this->expectException(MaterialisedPathConfigurationException::class);
        $this->expectExceptionMessageMatches('/non-empty column name/');
        MaterialisedPathRegistry::for(MethodOverrideEmptyKey::class);
    }

    #[Test]
    public function method_with_bad_entry_type_throws(): void
    {
        $this->expectException(MaterialisedPathConfigurationException::class);
        $this->expectExceptionMessageMatches('/MaterialisedPath, a callable, or a string/');
        MaterialisedPathRegistry::for(MethodOverrideBadEntry::class);
    }
}
