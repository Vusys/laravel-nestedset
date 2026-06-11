<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\MaterialisedPath;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\MultiPathCategory;
use Vusys\NestedSet\Tests\TestCase;

/**
 * The subtree path-rewrite offset must be measured in characters, not
 * bytes — the SUBSTRING/SUBSTR functions it feeds are character-indexed
 * on utf8mb4 MySQL, PG and SQLite. A byte offset chops a character off
 * every descendant path the moment the old prefix carries a non-ASCII
 * character.
 */
final class MaterialisedPathMultibyteTest extends TestCase
{
    #[Test]
    public function renaming_a_multibyte_parent_keeps_descendant_paths_intact(): void
    {
        $root = new MultiPathCategory(['name' => 'cafe', 'display_name' => 'Café']);
        $root->makeRoot()->save();

        $child = new MultiPathCategory(['name' => 'livres', 'display_name' => 'Livres']);
        $child->appendToNode($root->refresh())->save();

        $this->assertSame('Café', $root->refresh()->crumb_path);
        $this->assertSame('Café > Livres', $child->refresh()->crumb_path);

        // Rename the multibyte root. The old prefix 'Café > ' is 7
        // characters but 8 bytes (é is two bytes); a byte offset would
        // eat the 'L' from the child segment.
        $root->display_name = 'Bücher';
        $root->save();

        $this->assertSame('Bücher', $root->refresh()->crumb_path);
        $this->assertSame('Bücher > Livres', $child->refresh()->crumb_path);
    }
}
