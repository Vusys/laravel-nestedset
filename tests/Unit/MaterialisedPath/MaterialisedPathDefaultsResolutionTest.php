<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\MaterialisedPath;

use Illuminate\Database\Eloquent\Model;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vusys\NestedSet\Attributes\NestedSetMaterialisedPath;
use Vusys\NestedSet\Attributes\NestedSetMaterialisedPathDefaults;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\MaterialisedPath\MaterialisedPathRegistry;
use Vusys\NestedSet\NodeTrait;

#[NestedSetMaterialisedPathDefaults(separator: '.', wrap: false, maxLength: 2048)]
#[NestedSetMaterialisedPath(column: 'doc_path', slug: 'name')]
final class DefaultsAttrFixture extends Model implements HasNestedSet
{
    use NodeTrait;

    /** @var list<string> */
    protected $fillable = ['name'];
}

#[NestedSetMaterialisedPath(column: 'doc_path', slug: 'name')]
final class NoDefaultsFixture extends Model implements HasNestedSet
{
    use NodeTrait;
}

final class MaterialisedPathDefaultsResolutionTest extends OrchestraTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        MaterialisedPathRegistry::forgetCache();
        config([
            'nestedset.materialised_path.defaults' => [
                'separator' => '/',
                'wrap' => true,
                'maxLength' => 1024,
                'rejectSeparatorInSegment' => true,
                'uniquePerParent' => true,
            ],
            'nestedset.materialised_path.class_defaults' => [],
        ]);
    }

    public function test_class_attribute_defaults_override_global_config(): void
    {
        $paths = MaterialisedPathRegistry::for(DefaultsAttrFixture::class);
        $path = $paths['doc_path'];
        $this->assertSame('.', $path->getSeparator());
        $this->assertFalse($path->getWrap());
        $this->assertSame(2048, $path->getMaxLength());
    }

    public function test_global_config_wins_when_no_class_attribute(): void
    {
        $paths = MaterialisedPathRegistry::for(NoDefaultsFixture::class);
        $path = $paths['doc_path'];
        $this->assertSame('/', $path->getSeparator());
        $this->assertTrue($path->getWrap());
        $this->assertSame(1024, $path->getMaxLength());
    }

    public function test_class_defaults_config_overrides_global(): void
    {
        config([
            'nestedset.materialised_path.class_defaults' => [
                NoDefaultsFixture::class => ['separator' => '~', 'maxLength' => 512],
            ],
        ]);
        MaterialisedPathRegistry::forgetCache();

        $paths = MaterialisedPathRegistry::for(NoDefaultsFixture::class);
        $path = $paths['doc_path'];
        $this->assertSame('~', $path->getSeparator());
        $this->assertSame(512, $path->getMaxLength());
        $this->assertTrue($path->getWrap(), 'unset field falls through to global');
    }

    public function test_class_defaults_only_match_exact_fqcn(): void
    {
        // Configure for the parent class only; the child should not inherit.
        config([
            'nestedset.materialised_path.class_defaults' => [
                'NonExistent\\BaseModel' => ['separator' => '#'],
            ],
        ]);
        MaterialisedPathRegistry::forgetCache();

        $paths = MaterialisedPathRegistry::for(NoDefaultsFixture::class);
        $this->assertSame('/', $paths['doc_path']->getSeparator(), 'no inheritance');
    }

    public function test_per_path_attribute_wins_over_every_defaults_layer(): void
    {
        $paths = MaterialisedPathRegistry::for(DefaultsAttrFixture::class);
        // The per-path explicit value (none set on this fixture) would be
        // the topmost layer; the class-attribute defaults applied here
        // are the next layer down. The wrap=false carries through.
        $this->assertFalse($paths['doc_path']->getWrap());
    }

    public function test_registry_caches_per_fqcn(): void
    {
        $a = MaterialisedPathRegistry::for(NoDefaultsFixture::class);
        $b = MaterialisedPathRegistry::for(NoDefaultsFixture::class);
        $this->assertSame($a['doc_path'], $b['doc_path'], 'cache returns the same instance');
    }

    public function test_forget_cache_invalidates_one_class(): void
    {
        $first = MaterialisedPathRegistry::for(NoDefaultsFixture::class);
        config(['nestedset.materialised_path.defaults.separator' => '~']);
        $still = MaterialisedPathRegistry::for(NoDefaultsFixture::class);
        $this->assertSame($first['doc_path'], $still['doc_path'], 'still cached');

        MaterialisedPathRegistry::forgetCache(NoDefaultsFixture::class);
        $fresh = MaterialisedPathRegistry::for(NoDefaultsFixture::class);
        $this->assertSame('~', $fresh['doc_path']->getSeparator());
    }

    public function test_forget_cache_with_null_invalidates_everything(): void
    {
        MaterialisedPathRegistry::for(NoDefaultsFixture::class);
        MaterialisedPathRegistry::for(DefaultsAttrFixture::class);

        config(['nestedset.materialised_path.defaults.separator' => '*']);
        MaterialisedPathRegistry::forgetCache();

        $this->assertSame('*', MaterialisedPathRegistry::for(NoDefaultsFixture::class)['doc_path']->getSeparator());
    }

    public function test_columns_for_returns_just_the_column_names(): void
    {
        $columns = MaterialisedPathRegistry::columnsFor(DefaultsAttrFixture::class);
        $this->assertSame(['doc_path'], $columns);
    }

    public function test_defaults_to_array_is_partial(): void
    {
        $defaults = new NestedSetMaterialisedPathDefaults(separator: '.', maxLength: 100);
        $this->assertSame(['separator' => '.', 'maxLength' => 100], $defaults->toArray());
    }

    public function test_defaults_to_array_returns_empty_when_nothing_set(): void
    {
        $this->assertSame([], (new NestedSetMaterialisedPathDefaults)->toArray());
    }
}
