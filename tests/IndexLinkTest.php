<?php

namespace Timot\TodoItems\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use ReflectionMethod;
use Timot\TodoItems\TodoItem;
use Timot\TodoItems\TodoRepository;

/**
 * Where the generated index points, given that `directory` and `index` are
 * configured separately.
 *
 * The link was a prefix strip: correct whenever the items sit under the index's
 * own directory, and an absolute host path whenever they do not. Nothing raised
 * an error — the wrong path went into a generated markdown file that people read
 * and click, which is the worst shape a bug can take here. Found against a real
 * 208-item tree by the second project to adopt this package, which is the kind of
 * thing only a second consumer finds.
 */
class IndexLinkTest extends PHPUnitTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/index-links-'.uniqid();
        mkdir($this->root.'/docs', 0o777, true);
        mkdir($this->root.'/todo', 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));

        parent::tearDown();
    }

    public function test_items_under_the_index_are_linked_by_a_plain_relative_path(): void
    {
        // The ordinary layout: TODO.md at the root, items in todo/ beside it.
        $this->assertSame(
            '- **[#007](todo/007-a-thing.md)** A thing',
            $this->rowFor($this->root.'/todo', $this->root.'/TODO.md'),
        );
    }

    public function test_items_beside_the_index_are_linked_by_filename_alone(): void
    {
        // Index inside the item directory. `./` would work too, but the bare
        // filename is what a person would write.
        $this->assertSame(
            '- **[#007](007-a-thing.md)** A thing',
            $this->rowFor($this->root.'/todo', $this->root.'/todo/TODO.md'),
        );
    }

    public function test_an_index_in_a_sibling_directory_climbs_rather_than_going_absolute(): void
    {
        // The case that was broken: `docs/TODO.md` over a root `todo/`. The old
        // prefix strip found no prefix to remove and emitted the absolute host
        // path — committed, and wrong on every machine but the one that wrote it.
        $row = $this->rowFor($this->root.'/todo', $this->root.'/docs/TODO.md');

        $this->assertSame('- **[#007](../todo/007-a-thing.md)** A thing', $row);
        $this->assertStringNotContainsString($this->root, $row);
    }

    public function test_a_deeper_index_climbs_as_far_as_it_needs_to(): void
    {
        mkdir($this->root.'/docs/generated/deep', 0o777, true);

        $this->assertSame(
            '- **[#007](../../../todo/007-a-thing.md)** A thing',
            $this->rowFor($this->root.'/todo', $this->root.'/docs/generated/deep/TODO.md'),
        );
    }

    public function test_trailing_slashes_and_dot_segments_do_not_change_the_answer(): void
    {
        // Paths arrive from config, where a trailing slash or a `./` is a typo
        // nobody should have to notice.
        $this->assertSame(
            '- **[#007](todo/007-a-thing.md)** A thing',
            $this->rowFor($this->root.'/./todo/', $this->root.'/TODO.md'),
        );
    }

    /** The index row a repository renders for one item, with the sections stripped. */
    private function rowFor(string $directory, string $indexPath): string
    {
        $repository = new TodoRepository($directory, $indexPath);

        $item = new TodoItem(
            id: 7,
            slug: 'a-thing',
            title: 'A thing',
            section: 'Ideas',
            position: 1,
        );

        // linkFor() is private and deliberately so; the rendered row is the
        // observable behaviour, and rendering the whole index needs the item on
        // disk. Reaching for the one method keeps the test about the link.
        $method = new ReflectionMethod($repository, 'linkFor');

        return '- **['.$item->reference().']('.$method->invoke($repository, $item).')** '.$item->title;
    }
}
