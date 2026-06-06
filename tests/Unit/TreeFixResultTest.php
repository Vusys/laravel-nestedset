<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\TreeFixResult;

final class TreeFixResultTest extends TestCase
{
    #[Test]
    public function stores_nodes_updated_and_errors(): void
    {
        $result = new TreeFixResult(
            nodesUpdated: 5,
            errors: ['oddness' => 2, 'duplicates' => 0],
        );

        $this->assertSame(5, $result->nodesUpdated);
        $this->assertSame(['oddness' => 2, 'duplicates' => 0], $result->errors);
    }

    #[Test]
    public function has_errors_is_true_when_any_error_count_is_nonzero(): void
    {
        $result = new TreeFixResult(nodesUpdated: 3, errors: ['oddness' => 0, 'duplicates' => 1]);

        $this->assertTrue($result->hasErrors());
    }

    #[Test]
    public function has_errors_is_false_when_all_error_counts_are_zero(): void
    {
        $result = new TreeFixResult(nodesUpdated: 3, errors: ['oddness' => 0, 'duplicates' => 0]);

        $this->assertFalse($result->hasErrors());
    }

    #[Test]
    public function has_errors_is_false_for_empty_errors_array(): void
    {
        $result = new TreeFixResult(nodesUpdated: 0, errors: []);

        $this->assertFalse($result->hasErrors());
    }
}
