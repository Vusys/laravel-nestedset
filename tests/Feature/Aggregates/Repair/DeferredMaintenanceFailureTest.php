<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates\Repair;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Vusys\NestedSet\Tests\Fixtures\Models\DeferredRepairBoom;
use Vusys\NestedSet\Tests\TestCase;

/**
 * The deferred-maintenance runner swallows a failure in its trailing
 * fixAggregates() pass only when the user's closure already threw (so the
 * original exception wins). On the SUCCESS path there is no primary
 * exception — a swallowed repair failure would hide permanent drift, so
 * it must propagate.
 */
final class DeferredMaintenanceFailureTest extends TestCase
{
    #[Test]
    public function repair_failure_propagates_when_the_closure_succeeded(): void
    {
        $root = new DeferredRepairBoom(['name' => 'Root']);
        $root->saveAsRoot();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('repair boom');

        DeferredRepairBoom::withDeferredAggregateMaintenance(
            static fn (): bool => true,
            $root->refresh(),
        );
    }

    #[Test]
    public function repair_failure_is_swallowed_when_the_closure_threw(): void
    {
        $root = new DeferredRepairBoom(['name' => 'Root']);
        $root->saveAsRoot();

        // The closure's own exception must win — the repair failure
        // ('repair boom') is swallowed so the original bug isn't masked.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('primary closure failure');

        DeferredRepairBoom::withDeferredAggregateMaintenance(
            static function (): bool {
                throw new RuntimeException('primary closure failure');
            },
            $root->refresh(),
        );
    }
}
