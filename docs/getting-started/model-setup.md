# Model Setup

```php
use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;

class Category extends Model implements HasNestedSet
{
    use NodeTrait;

    protected $fillable = ['name'];

    protected $casts = [
        'lft'       => 'integer',
        'rgt'       => 'integer',
        'depth'     => 'integer',
        'parent_id' => 'integer',
    ];
}
```

The trait satisfies the `HasNestedSet` interface out of the box — you
only need to implement methods yourself if you're storing nested-set
data on columns the trait can't derive from your `protected $casts`.
