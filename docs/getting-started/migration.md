# Migration

The `$table->nestedSet()` Blueprint macro adds the four maintained columns and a composite index that covers the common ancestor/descendant range lookups.

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->nestedSet();   // lft, rgt, parent_id (nullable), depth + index
            $table->softDeletes(); // optional — see Soft Deletes
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
```

For a scoped (multi-tree) table, pass the scope column(s) to the macro so each tree gets its own index slice — the scope columns are placed at the head of the same composite index, no separate index needed:

```php
$table->nestedSet(scope: 'post_id');
// or for a multi-column scope:
$table->nestedSet(scope: ['tenant_id', 'post_id']);
```

`cover: [...]` appends columns to the tail of the index — useful when aggregate source columns benefit from covering range scans (see [Filtered Aggregates](../aggregates/filtered.html#index-tuning)).

To remove the columns later: `$table->dropNestedSet()` (pass the same `scope` / `cover` arguments you used in `nestedSet()`).

## Non-integer primary keys

`parent_id` defaults to `unsignedBigInteger` to match Laravel's auto-incrementing `id()` column. UUID, ULID, and string PKs are supported — pass `parentIdType:` to match:

```php
$table->nestedSet(parentIdType: 'uuid');
```

Accepted values: `'bigint'` (default), `'uuid'`, `'ulid'`, `'string'`, or a closure `function (Blueprint $table, string $column): void { … }` for custom shapes (nanoid, fixed-width char, FK constraints, etc.). See [Primary Keys](primary-keys.html) for the monotonicity rule that governs chunked aggregate repair.
