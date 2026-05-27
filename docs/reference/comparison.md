# vs. kalnoy/nestedset

This package is a modern reimplementation — not a fork. Key differences:

| Dimension | `kalnoy/nestedset` v7 | `vusys/laravel-nestedset` |
|---|---|---|
| PHP minimum | 8.0 | **8.3** |
| Laravel | 13 | **11, 12, 13** |
| `declare(strict_types=1)` | No | Yes |
| Required interface | None | `HasNestedSet` |
| Static analysis | None | **Larastan level 9, no baseline** |
| Pint / Rector | None | Yes |
| Test coverage | 86 tests | 340+ tests, performance bench harness, cross-backend matrix |
| Auto transactions | No (footgun) | **On by default, opt-out via config** |
| Depth column | Computed subquery | **Stored, maintained on mutation** |
| Scoping API | Method-based | Attribute (`#[NestedSetScope]`) + method |
| Scoped `fixTree()` | Walks whole table | **Refuses without anchor** |
| Bounds | Untyped tuple | `readonly class NodeBounds` |
| Repair result | `int` | `readonly class TreeFixResult` |
