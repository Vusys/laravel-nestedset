<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Query;

use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Query\TreeBaseQueryBuilder;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * The custom base builder exists for one job today: prepend
 * `SET STATEMENT optimizer_switch='split_materialized=off' FOR …` to
 * the next `runSelect()` when MariaDB's planner is about to
 * re-lateralise our derived JOIN. The flag is opt-in and only
 * consulted inside `runSelect()`.
 *
 * Two contracts to pin:
 *  - Default builder runs SQL unchanged (no SET STATEMENT prefix).
 *  - `withMariaDbSplitMaterializedOff()` makes `runSelect()` dispatch
 *    SQL with the prefix. Asserted against `Connection::pretend()`,
 *    which captures the SQL the builder *would* execute without
 *    actually executing it — letting the contract run on every
 *    backend (SQLite would otherwise parse-error on the SET
 *    STATEMENT syntax).
 */
final class TreeBaseQueryBuilderTest extends TestCase
{
    public function test_default_run_select_emits_no_set_statement_prefix(): void
    {
        $root = new Category(['name' => 'root']);
        $root->saveAsRoot();

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            Category::query()->get();
        } finally {
            DB::disableQueryLog();
        }

        foreach (DB::getQueryLog() as $entry) {
            $sql = strtolower((string) $entry['query']);
            $this->assertStringNotContainsString(
                'set statement',
                $sql,
                'Default builder must not inject SET STATEMENT into the SQL.',
            );
        }
    }

    public function test_flag_injects_set_statement_prefix_into_dispatched_sql(): void
    {
        $connection = DB::connection();
        // Compile against the MySQL grammar so the inner SQL surfaces
        // with the backticks the prefix is paired with in production.
        $grammar = new MySqlGrammar($connection);

        $captured = $connection->pretend(function () use ($connection, $grammar): void {
            $builder = new TreeBaseQueryBuilder(
                $connection,
                $grammar,
                $connection->getPostProcessor(),
            );
            $builder->from('categories')->select('id');
            $builder->withMariaDbSplitMaterializedOff();
            $builder->get();
        });

        $this->assertNotEmpty($captured, 'Expected runSelect() to dispatch a query.');
        $last = $captured[count($captured) - 1];
        $lastSql = (string) $last['query'];

        $this->assertStringStartsWith(
            "SET STATEMENT optimizer_switch='split_materialized=off' FOR ",
            $lastSql,
        );
        $this->assertStringContainsString('select `id` from `categories`', $lastSql);
    }
}
