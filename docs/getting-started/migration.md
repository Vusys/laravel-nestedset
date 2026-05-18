# Migration

The `$table->nestedSet()` Blueprint macro adds the four maintained columns
and a composite index that covers the common ancestor/descendant range
lookups.

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

For a scoped (multi-tree) table, declare the scope column **first** in the
composite index so each tree gets its own index slice:

```php
$table->index(['post_id', 'lft', 'rgt', 'parent_id']);
```

To remove the columns later: `$table->dropNestedSet()`.
