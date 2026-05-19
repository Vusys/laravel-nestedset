<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Mutations are wrapped in an auto-transaction by default
 * (`config('nestedset.auto_transaction', true)`); set to `false` only
 * if the caller is managing transactions itself.
 *
 * Scope: the package's auto-transaction wraps `callPendingAction`'s
 * structural-SQL block — `makeGap` / `moveNode` plus the bracketing
 * aggregate hooks. Failures inside that window roll the gap back
 * (delegating to Laravel's `DB::transaction()` contract). The
 * subsequent Eloquent `INSERT` runs OUTSIDE that wrap — atomicity
 * between gap and row insert is the caller's responsibility, via an
 * outer `DB::transaction()`.
 *
 * Behaviours pinned by this file:
 *  - User-wrapped `DB::transaction()` rollback restores pre-mutation
 *    state.
 *  - auto_transaction = true opens a transaction inside
 *    `callPendingAction` (proven by the begin/commit event counter).
 *  - auto_transaction = false opens NO transaction inside
 *    `callPendingAction` (the caller has opted out).
 *  - Both config values produce identical results on the happy
 *    path.
 */
final class TransactionTest extends TestCase
{
    public function test_explicit_transaction_rollback_restores_tree(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();

        $a = new Category(['name' => 'A']);
        $a->appendToNode($root->refresh())->save();

        $snapshot = DB::table('categories')->orderBy('id')->get()->toArray();

        try {
            DB::transaction(function () use ($root): never {
                $b = new Category(['name' => 'B']);
                $b->appendToNode($root->refresh())->save();

                throw new RuntimeException('forced rollback');
            });
        } catch (RuntimeException) {
            // expected
        }

        $after = DB::table('categories')->orderBy('id')->get()->toArray();

        $this->assertEquals($snapshot, $after, 'Transaction rollback should restore the tree');
        $this->assertFalse(Category::isBroken());
    }

    public function test_failure_after_save_under_outer_transaction_is_rolled_back(): void
    {
        // Pins the recommended pattern for atomicity that spans the
        // package's auto-tx AND the subsequent INSERT: wrap the save
        // chain in your own DB::transaction(). The rollback proven
        // here is the OUTER transaction's, not the package's — the
        // package's auto-tx (which only wraps the makeGap/aggregate
        // window inside callPendingAction) has already committed by
        // the time this throw fires.
        $this->assertTrue(Config::get('nestedset.auto_transaction'));

        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();
        $rootBefore = $root->refresh();

        try {
            DB::transaction(function () use ($rootBefore): never {
                $b = new Category(['name' => 'B']);
                $b->appendToNode($rootBefore)->save();
                throw new RuntimeException('failure after save() — outer tx rolls back');
            });
        } catch (RuntimeException) {
            // expected
        }

        $rootAfter = Category::query()->findOrFail(1);
        $this->assertSame($rootBefore->lft, $rootAfter->lft);
        $this->assertSame($rootBefore->rgt, $rootAfter->rgt);
        $this->assertFalse(Category::isBroken());
    }

    public function test_auto_transaction_wraps_call_pending_action(): void
    {
        // The auto-transaction boundary wraps `callPendingAction`'s
        // structural-SQL block (`makeGap` / `moveNode` plus the
        // bracketing aggregate hooks). Verify by counting
        // `TransactionBeginning` events fired against the connection —
        // when auto_transaction is on, the package opens a transaction
        // for each call.
        $this->assertTrue(Config::get('nestedset.auto_transaction'));

        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();
        $rootBefore = $root->refresh();

        $beginCount = 0;
        $commitCount = 0;
        Event::listen(TransactionBeginning::class, static function () use (&$beginCount): void {
            $beginCount++;
        });
        Event::listen(TransactionCommitted::class, static function () use (&$commitCount): void {
            $commitCount++;
        });

        $a = new Category(['name' => 'A']);
        $a->appendToNode($rootBefore)->save();

        $this->assertGreaterThanOrEqual(1, $beginCount, 'expected the package to open a transaction inside callPendingAction');
        $this->assertGreaterThanOrEqual(1, $commitCount, 'expected the package to commit the transaction');
        $this->assertFalse(Category::isBroken());
    }

    public function test_auto_transaction_false_skips_the_wrap(): void
    {
        // With auto_transaction disabled, the package emits no
        // transaction-control statement around callPendingAction —
        // the caller has opted out of the safety net.
        Config::set('nestedset.auto_transaction', false);

        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();

        $beginCount = 0;
        Event::listen(TransactionBeginning::class, static function () use (&$beginCount): void {
            $beginCount++;
        });

        $a = new Category(['name' => 'A']);
        $a->appendToNode($root->refresh())->save();

        $this->assertSame(
            0,
            $beginCount,
            'auto_transaction = false should not open a transaction inside callPendingAction',
        );
        $this->assertFalse(Category::isBroken());
    }

    public function test_auto_transaction_false_under_user_wrapped_transaction_still_rolls_back(): void
    {
        // The recommended pattern when auto_transaction is off: wrap
        // the work yourself. The user-wrapped transaction handles
        // rollback; the package adds no second one.
        Config::set('nestedset.auto_transaction', false);

        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();
        $rootBefore = $root->refresh();
        $snapshot = DB::table('categories')->orderBy('id')->get()->toArray();

        try {
            DB::transaction(function () use ($rootBefore): never {
                $b = new Category(['name' => 'B']);
                $b->appendToNode($rootBefore)->save();
                throw new RuntimeException('forced');
            });
        } catch (RuntimeException) {
            // expected
        }

        $after = DB::table('categories')->orderBy('id')->get()->toArray();
        $this->assertEquals($snapshot, $after);
        $this->assertFalse(Category::isBroken());
    }

    public function test_auto_transaction_can_be_disabled_for_successful_mutations(): void
    {
        // Happy-path sanity check: auto_transaction = false does NOT
        // break a successful mutation. (It used to be the only mode.)
        Config::set('nestedset.auto_transaction', false);

        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();
        $root = $root->refresh();

        $a = new Category(['name' => 'A']);
        $a->appendToNode($root)->save();
        $a = $a->refresh();

        $this->assertSame($root->id, $a->parent_id);
        $this->assertFalse(Category::isBroken());
    }
}
