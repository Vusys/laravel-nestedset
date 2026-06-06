<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Performance\Investigations;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Performance\Fixtures\TreeShapes;
use Vusys\NestedSet\Tests\Performance\PerformanceTestCase;

/**
 * Investigation harness for the MySQL/MariaDB fixAggregates plateau.
 *
 * Skipped unless `INVESTIGATE=1` so it doesn't bloat the perf suite.
 * Designed to run once and have a human read the printed plan + timings.
 *
 * First round identified the win: wrapping the JOIN+GROUP BY in a derived
 * table changes MySQL's plan from nested-loop-with-temporary-table-aggregate
 * to hash-join + filter + aggregate — 18.3s → 3.6s at N=10K.
 *
 * Round 2 verifies the win with:
 *   - All 7 inclusive aggregates (the real workload, including AVG companions)
 *   - The BETWEEN-on-lft-only simplification (equivalent predicate, single index column)
 *   - PostgreSQL + SQLite (no regression)
 */
final class MysqlFixAggregatesInvestigationTest extends PerformanceTestCase
{
    #[Test]
    public function explore_query_shapes(): void
    {
        if (getenv('INVESTIGATE') !== '1') {
            $this->markTestSkipped('Set INVESTIGATE=1 to run this investigation.');
        }

        $driver = DB::connection()->getDriverName();
        $n = (int) (getenv('INVESTIGATE_N') ?: '10000');

        DB::table('areas')->delete();
        TreeShapes::balancedFanout('areas', nodes: $n, fanout: 10);

        fwrite(STDOUT, "\n=== Investigation R2: fixAggregates SELECT shape ({$driver}, N={$n}) ===\n");

        $this->dumpIndexes($driver);

        // --- 1. Baseline: current shape, full 7-aggregate inclusive set ------
        $currentSql = $this->currentFullShape();
        fwrite(STDOUT, "\n--- Current shape, full 7 aggregates ---\n");
        $this->timeAndExplain('current_full', $currentSql, $driver);

        // --- 2. Derived-table, full 7 aggregates, two-inequality join ---------
        $derivedTwoIneqSql = $this->derivedFullShape(joinPredicate: 'i.lft >= o.lft AND i.rgt <= o.rgt');
        fwrite(STDOUT, "\n--- Derived table, full 7 agg, two-inequality predicate ---\n");
        $this->timeAndExplain('derived_full_two_ineq', $derivedTwoIneqSql, $driver);

        // --- 3. Derived-table, full 7 aggregates, BETWEEN on lft only --------
        $derivedBetweenSql = $this->derivedFullShape(joinPredicate: 'i.lft BETWEEN o.lft AND o.rgt');
        fwrite(STDOUT, "\n--- Derived table, full 7 agg, BETWEEN on lft only ---\n");
        $this->timeAndExplain('derived_full_between_lft', $derivedBetweenSql, $driver);

        // ------------------------------------------------------------------
        // Round 3 (MySQL only): the derived shape's inner aggregation
        // picks an `Inner hash join (no condition)` that produces a full
        // 10K×10K cross-join (100M rows) then filters by lft BETWEEN.
        // We want an indexed nested-loop on the inner i.lft instead.
        // ------------------------------------------------------------------
        if ($driver === 'mysql' && stripos($this->serverVersion(), 'mariadb') === false) {
            // Candidate I: NO_BNL/NO_HASH_JOIN — disable batched/hash
            // nested-loop joins inside the derived, forcing index lookups.
            $sql = $this->derivedFullShapeWithInnerHint('/*+ NO_BNL(o,i) NO_HASH_JOIN(o,i) */');
            fwrite(STDOUT, "\n--- MySQL: derived + NO_BNL + NO_HASH_JOIN on inner ---\n");
            $this->timeAndExplain('derived_no_bnl_no_hash', $sql, $driver);

            // Candidate J: JOIN_INDEX hint pinning the inner to the
            // composite index. Tells the optimizer "use index i.lft for
            // the join, not a full scan".
            $sql = $this->derivedFullShapeWithInnerHint(
                '/*+ JOIN_INDEX(i areas_lft_rgt_parent_id_tickets_index) NO_HASH_JOIN(o,i) */'
            );
            fwrite(STDOUT, "\n--- MySQL: derived + JOIN_INDEX + NO_HASH_JOIN ---\n");
            $this->timeAndExplain('derived_join_index', $sql, $driver);

            // Candidate K: STRAIGHT_JOIN — forces o before i in the inner.
            $sql = $this->derivedFullShapeWithStraightJoin();
            fwrite(STDOUT, "\n--- MySQL: derived + STRAIGHT_JOIN on inner ---\n");
            $this->timeAndExplain('derived_straight_join', $sql, $driver);

            // Candidate L: LATERAL inner — single-row inner aggregation
            // per outer row. MySQL 8.0.14+ supports LATERAL; this is the
            // same shape we already use for `withFreshAggregates`.
            $sql = $this->lateralShape();
            fwrite(STDOUT, "\n--- MySQL: LATERAL inner aggregation per outer ---\n");
            $this->timeAndExplain('lateral', $sql, $driver);
        }

        // ------------------------------------------------------------------
        // MariaDB-specific candidates. The v0.5.0 derived form regresses on
        // MariaDB because its planner picks "split_materialized" / LATERAL
        // DERIVED: the derived sub-query is re-executed once per outer row
        // instead of being materialised once.
        // ------------------------------------------------------------------
        $isMariaDb = stripos($this->serverVersion(), 'mariadb') !== false;
        if ($isMariaDb) {
            // Candidate F: same derived shape but session-toggle off the
            // split_materialized optimization that lateralizes our derived.
            DB::statement("SET SESSION optimizer_switch='split_materialized=off'");
            fwrite(STDOUT, "\n--- MariaDB: derived BETWEEN with split_materialized=off ---\n");
            $this->timeAndExplain('derived_split_off', $derivedBetweenSql, $driver);

            // Candidate G: also turn off derived_merge (more aggressive)
            DB::statement("SET SESSION optimizer_switch='derived_merge=off,split_materialized=off'");
            fwrite(STDOUT, "\n--- MariaDB: derived BETWEEN, derived_merge=off + split_materialized=off ---\n");
            $this->timeAndExplain('derived_no_merge', $derivedBetweenSql, $driver);

            // Reset
            DB::statement('SET SESSION optimizer_switch=DEFAULT');

            // Candidate H: explicit temp-table materialisation in two stmts.
            fwrite(STDOUT, "\n--- MariaDB: explicit temp-table materialisation ---\n");
            $this->timeTwoStatementTempTable('temp_table_explicit');
        }

        $this->addToAssertionCount(1);
    }

    private function serverVersion(): string
    {
        $rows = DB::select('SELECT VERSION() AS v');

        return isset($rows[0]) ? (string) ((array) $rows[0])['v'] : '';
    }

    private function dumpIndexes(string $driver): void
    {
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $rows = DB::select('SHOW INDEX FROM areas');
            fwrite(STDOUT, "\n--- Indexes ---\n");
            foreach ($rows as $row) {
                $arr = (array) $row;
                fwrite(STDOUT, sprintf(
                    "  %-40s seq=%d col=%-22s card=%s\n",
                    $arr['Key_name'],
                    $arr['Seq_in_index'],
                    $arr['Column_name'],
                    (string) ($arr['Cardinality'] ?? 'null'),
                ));
            }
        } elseif ($driver === 'pgsql') {
            $rows = DB::select("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'areas'");
            fwrite(STDOUT, "\n--- Indexes ---\n");
            foreach ($rows as $row) {
                $arr = (array) $row;
                fwrite(STDOUT, "  {$arr['indexname']}\n    {$arr['indexdef']}\n");
            }
        }
    }

    private function timeAndExplain(string $label, string $sql, string $driver): void
    {
        // Warmup
        DB::select($sql);

        $t0 = microtime(true);
        $rows = DB::select($sql);
        $elapsedMs = (microtime(true) - $t0) * 1000;

        fwrite(STDOUT, sprintf("  [%s] %d rows, %.1f ms\n", $label, count($rows), $elapsedMs));

        // EXPLAIN ANALYZE syntax differs across backends:
        //   MySQL 8.0.18+: `EXPLAIN ANALYZE <stmt>`
        //   MariaDB 10.1+: `ANALYZE <stmt>` (no EXPLAIN prefix; or FORMAT=JSON)
        //   PostgreSQL:    `EXPLAIN (ANALYZE, BUFFERS) <stmt>`
        $version = $this->serverVersion();
        if ($driver === 'mysql' && stripos($version, 'mariadb') !== false) {
            $plan = DB::select('ANALYZE '.$sql);
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            $plan = DB::select('EXPLAIN ANALYZE '.$sql);
        } elseif ($driver === 'pgsql') {
            $plan = DB::select('EXPLAIN (ANALYZE, BUFFERS) '.$sql);
        } else {
            return;
        }

        fwrite(STDOUT, "  Plan:\n");
        foreach ($plan as $row) {
            $arr = (array) $row;
            foreach ($arr as $v) {
                foreach (explode("\n", (string) $v) as $line) {
                    fwrite(STDOUT, "    {$line}\n");
                }
            }
        }
    }

    /**
     * Full inclusive 7-aggregate set as fixAggregates() actually issues:
     * tickets_total (SUM), tickets_count_all (COUNT), tickets_avg (AVG),
     * tickets_min (MIN), tickets_max (MAX), tickets_avg__sum (SUM),
     * tickets_avg__count (COUNT) — every one of these is inclusive by
     * default, so this is what the package emits today.
     */
    private function currentFullShape(): string
    {
        return 'SELECT outer_a.id AS id,'
            .' COALESCE(SUM(inner_a.tickets), 0) AS computed_tickets_total,'
            .' outer_a.tickets_total AS stored_tickets_total,'
            .' COUNT(inner_a.tickets) AS computed_tickets_count_all,'
            .' outer_a.tickets_count_all AS stored_tickets_count_all,'
            .' AVG(inner_a.tickets) AS computed_tickets_avg,'
            .' outer_a.tickets_avg AS stored_tickets_avg,'
            .' MIN(inner_a.tickets) AS computed_tickets_min,'
            .' outer_a.tickets_min AS stored_tickets_min,'
            .' MAX(inner_a.tickets) AS computed_tickets_max,'
            .' outer_a.tickets_max AS stored_tickets_max,'
            .' COALESCE(SUM(inner_a.tickets), 0) AS computed_tickets_avg__sum,'
            .' outer_a.tickets_avg__sum AS stored_tickets_avg__sum,'
            .' COUNT(inner_a.tickets) AS computed_tickets_avg__count,'
            .' outer_a.tickets_avg__count AS stored_tickets_avg__count'
            .' FROM areas AS outer_a'
            .' LEFT JOIN areas AS inner_a'
            .' ON inner_a.lft >= outer_a.lft AND inner_a.rgt <= outer_a.rgt'
            .' WHERE 1 = 1'
            .' GROUP BY outer_a.id, outer_a.tickets_total, outer_a.tickets_count_all,'
            .' outer_a.tickets_avg, outer_a.tickets_min, outer_a.tickets_max,'
            .' outer_a.tickets_avg__sum, outer_a.tickets_avg__count';
    }

    private function timeTwoStatementTempTable(string $label): void
    {
        // Create the aggregate-only result as a session temp table, then
        // SELECT the join. This forces a single materialisation up-front
        // and a single index-lookup pass on the outer join.
        DB::statement('DROP TEMPORARY TABLE IF EXISTS agg_temp');
        $t0 = microtime(true);
        DB::statement(
            'CREATE TEMPORARY TABLE agg_temp AS '
            .' SELECT o.id AS outer_id,'
            .' COALESCE(SUM(i.tickets), 0) AS computed_tickets_total,'
            .' COUNT(i.tickets) AS computed_tickets_count_all,'
            .' AVG(i.tickets) AS computed_tickets_avg,'
            .' MIN(i.tickets) AS computed_tickets_min,'
            .' MAX(i.tickets) AS computed_tickets_max,'
            .' COALESCE(SUM(i.tickets), 0) AS computed_tickets_avg__sum,'
            .' COUNT(i.tickets) AS computed_tickets_avg__count'
            .' FROM areas o INNER JOIN areas i ON i.lft BETWEEN o.lft AND o.rgt'
            .' GROUP BY o.id'
        );
        DB::statement('CREATE INDEX agg_temp_outer_id ON agg_temp(outer_id)');
        $rows = DB::select(
            'SELECT outer_a.id AS id, agg.computed_tickets_total,'
            .' outer_a.tickets_total AS stored_tickets_total,'
            .' agg.computed_tickets_count_all, outer_a.tickets_count_all AS stored_tickets_count_all,'
            .' agg.computed_tickets_avg, outer_a.tickets_avg AS stored_tickets_avg,'
            .' agg.computed_tickets_min, outer_a.tickets_min AS stored_tickets_min,'
            .' agg.computed_tickets_max, outer_a.tickets_max AS stored_tickets_max,'
            .' agg.computed_tickets_avg__sum, outer_a.tickets_avg__sum AS stored_tickets_avg__sum,'
            .' agg.computed_tickets_avg__count, outer_a.tickets_avg__count AS stored_tickets_avg__count'
            .' FROM areas AS outer_a LEFT JOIN agg_temp agg ON agg.outer_id = outer_a.id'
        );
        $elapsedMs = (microtime(true) - $t0) * 1000;
        DB::statement('DROP TEMPORARY TABLE agg_temp');
        fwrite(STDOUT, sprintf("  [%s] %d rows, %.1f ms (create+index+select)\n",
            $label, count($rows), $elapsedMs));
    }

    /**
     * Same derived shape used by the package, but with an optimizer
     * hint injected immediately after the inner `SELECT`. MySQL
     * applies hints scoped to the query block they appear in, so the
     * hint controls only the inner aggregation's join strategy.
     */
    private function derivedFullShapeWithInnerHint(string $hint): string
    {
        return 'SELECT outer_a.id AS id, agg.computed_tickets_total,'
            .' outer_a.tickets_total AS stored_tickets_total,'
            .' agg.computed_tickets_count_all, outer_a.tickets_count_all AS stored_tickets_count_all,'
            .' agg.computed_tickets_avg, outer_a.tickets_avg AS stored_tickets_avg,'
            .' agg.computed_tickets_min, outer_a.tickets_min AS stored_tickets_min,'
            .' agg.computed_tickets_max, outer_a.tickets_max AS stored_tickets_max,'
            .' agg.computed_tickets_avg__sum, outer_a.tickets_avg__sum AS stored_tickets_avg__sum,'
            .' agg.computed_tickets_avg__count, outer_a.tickets_avg__count AS stored_tickets_avg__count'
            .' FROM areas AS outer_a INNER JOIN ('
            ."   SELECT {$hint} o.id AS outer_id,"
            .'   COALESCE(SUM(i.tickets), 0) AS computed_tickets_total,'
            .'   COUNT(i.tickets) AS computed_tickets_count_all,'
            .'   AVG(i.tickets) AS computed_tickets_avg,'
            .'   MIN(i.tickets) AS computed_tickets_min,'
            .'   MAX(i.tickets) AS computed_tickets_max,'
            .'   COALESCE(SUM(i.tickets), 0) AS computed_tickets_avg__sum,'
            .'   COUNT(i.tickets) AS computed_tickets_avg__count'
            .'   FROM areas o INNER JOIN areas i ON i.lft BETWEEN o.lft AND o.rgt'
            .'   GROUP BY o.id'
            .' ) agg ON agg.outer_id = outer_a.id';
    }

    private function derivedFullShapeWithStraightJoin(): string
    {
        return 'SELECT outer_a.id AS id, agg.computed_tickets_total,'
            .' outer_a.tickets_total AS stored_tickets_total,'
            .' agg.computed_tickets_count_all, outer_a.tickets_count_all AS stored_tickets_count_all,'
            .' agg.computed_tickets_avg, outer_a.tickets_avg AS stored_tickets_avg,'
            .' agg.computed_tickets_min, outer_a.tickets_min AS stored_tickets_min,'
            .' agg.computed_tickets_max, outer_a.tickets_max AS stored_tickets_max,'
            .' agg.computed_tickets_avg__sum, outer_a.tickets_avg__sum AS stored_tickets_avg__sum,'
            .' agg.computed_tickets_avg__count, outer_a.tickets_avg__count AS stored_tickets_avg__count'
            .' FROM areas AS outer_a INNER JOIN ('
            .'   SELECT o.id AS outer_id,'
            .'   COALESCE(SUM(i.tickets), 0) AS computed_tickets_total,'
            .'   COUNT(i.tickets) AS computed_tickets_count_all,'
            .'   AVG(i.tickets) AS computed_tickets_avg,'
            .'   MIN(i.tickets) AS computed_tickets_min,'
            .'   MAX(i.tickets) AS computed_tickets_max,'
            .'   COALESCE(SUM(i.tickets), 0) AS computed_tickets_avg__sum,'
            .'   COUNT(i.tickets) AS computed_tickets_avg__count'
            .'   FROM areas o STRAIGHT_JOIN areas i ON i.lft BETWEEN o.lft AND o.rgt'
            .'   GROUP BY o.id'
            .' ) agg ON agg.outer_id = outer_a.id';
    }

    /**
     * MySQL 8.0.14+ LATERAL: one correlated inner aggregation per
     * outer row. Equivalent shape to what the package uses for
     * `withFreshAggregates` on PG/MySQL.
     */
    private function lateralShape(): string
    {
        return 'SELECT outer_a.id AS id, lat.computed_tickets_total,'
            .' outer_a.tickets_total AS stored_tickets_total,'
            .' lat.computed_tickets_count_all, outer_a.tickets_count_all AS stored_tickets_count_all,'
            .' lat.computed_tickets_avg, outer_a.tickets_avg AS stored_tickets_avg,'
            .' lat.computed_tickets_min, outer_a.tickets_min AS stored_tickets_min,'
            .' lat.computed_tickets_max, outer_a.tickets_max AS stored_tickets_max,'
            .' lat.computed_tickets_avg__sum, outer_a.tickets_avg__sum AS stored_tickets_avg__sum,'
            .' lat.computed_tickets_avg__count, outer_a.tickets_avg__count AS stored_tickets_avg__count'
            .' FROM areas AS outer_a LEFT JOIN LATERAL ('
            .'   SELECT'
            .'   COALESCE(SUM(i.tickets), 0) AS computed_tickets_total,'
            .'   COUNT(i.tickets) AS computed_tickets_count_all,'
            .'   AVG(i.tickets) AS computed_tickets_avg,'
            .'   MIN(i.tickets) AS computed_tickets_min,'
            .'   MAX(i.tickets) AS computed_tickets_max,'
            .'   COALESCE(SUM(i.tickets), 0) AS computed_tickets_avg__sum,'
            .'   COUNT(i.tickets) AS computed_tickets_avg__count'
            .'   FROM areas i WHERE i.lft BETWEEN outer_a.lft AND outer_a.rgt'
            .' ) lat ON TRUE';
    }

    private function derivedFullShape(string $joinPredicate): string
    {
        return 'SELECT outer_a.id AS id,'
            .' agg.computed_tickets_total,'
            .' outer_a.tickets_total AS stored_tickets_total,'
            .' agg.computed_tickets_count_all,'
            .' outer_a.tickets_count_all AS stored_tickets_count_all,'
            .' agg.computed_tickets_avg,'
            .' outer_a.tickets_avg AS stored_tickets_avg,'
            .' agg.computed_tickets_min,'
            .' outer_a.tickets_min AS stored_tickets_min,'
            .' agg.computed_tickets_max,'
            .' outer_a.tickets_max AS stored_tickets_max,'
            .' agg.computed_tickets_avg__sum,'
            .' outer_a.tickets_avg__sum AS stored_tickets_avg__sum,'
            .' agg.computed_tickets_avg__count,'
            .' outer_a.tickets_avg__count AS stored_tickets_avg__count'
            .' FROM areas AS outer_a'
            .' INNER JOIN ('
            .'   SELECT o.id AS outer_id,'
            .'   COALESCE(SUM(i.tickets), 0) AS computed_tickets_total,'
            .'   COUNT(i.tickets) AS computed_tickets_count_all,'
            .'   AVG(i.tickets) AS computed_tickets_avg,'
            .'   MIN(i.tickets) AS computed_tickets_min,'
            .'   MAX(i.tickets) AS computed_tickets_max,'
            .'   COALESCE(SUM(i.tickets), 0) AS computed_tickets_avg__sum,'
            .'   COUNT(i.tickets) AS computed_tickets_avg__count'
            .'   FROM areas o'
            ."   INNER JOIN areas i ON {$joinPredicate}"
            .'   GROUP BY o.id'
            .' ) agg ON agg.outer_id = outer_a.id';
    }
}
