<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\MaterialisedPath;

use Illuminate\Database\Eloquent\Model;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\MaterialisedPath\MaterialisedPath;
use Vusys\NestedSet\MaterialisedPath\MaterialisedPathRegistry;
use Vusys\NestedSet\NodeTrait;

final class FunctionNameColumnFixture extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

    /** @return array<string, string> */
    protected static function materialisedPaths(): array
    {
        // The string 'count' is also the name of a global function — the
        // registry must treat it as an attribute name, not as a callable.
        return ['p' => 'count'];
    }
}

final class MaterialisedPathStringCallableAmbiguityTest extends OrchestraTestCase
{
    #[Test]
    public function string_entry_resolves_to_attribute_even_when_a_global_function_shares_the_name(): void
    {
        MaterialisedPathRegistry::forgetCache();

        $paths = MaterialisedPathRegistry::for(FunctionNameColumnFixture::class);
        $this->assertArrayHasKey('p', $paths);
        $this->assertSame(MaterialisedPath::SOURCE_ATTRIBUTE, $paths['p']->sourceKind());
        $this->assertSame('count', $paths['p']->sourceColumn());
    }
}
