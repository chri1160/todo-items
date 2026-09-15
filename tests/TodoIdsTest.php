<?php

namespace Timot\TodoItems\Tests;

use Timot\TodoItems\TodoIds;
use Timot\TodoItems\TodoItem;
use Timot\TodoItems\TodoRepository;

/**
 * Id allocation has to hold two properties at once, and they pull opposite ways:
 * never hand the same id to two branches, and never hand back an id that has
 * already been retired. One store alone gets one of them and loses the other,
 * which is what these tests pin down.
 */
class TodoIdsTest extends TestCase
{
    private string $dir;

    private string $index;

    private string $idsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = $this->tempPath('todo-ids-'.uniqid());
        $this->index = $this->dir.'-INDEX.md';
        $this->idsPath = $this->dir.'-ids.json';

        mkdir($this->dir, 0755, true);
        file_put_contents($this->dir.'/_index.md', "## Ideas\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($this->dir);
        @unlink($this->index);
        @unlink($this->idsPath);
        @unlink($this->idsPath.'.lock');

        parent::tearDown();
    }

    public function test_it_allocates_from_the_tree_when_the_registry_is_empty(): void
    {
        $this->seedItems(1, 2, 3);

        $this->assertSame(4, $this->repository($this->dir)->allocateId());
    }

    public function test_it_starts_at_one_for_an_empty_tree(): void
    {
        $this->assertSame(1, $this->repository($this->dir)->allocateId());
    }

    /**
     * The case this class was written for: two agents on two branches, each with
     * its own checkout, both reading the same highest id. Before the shared mark
     * they both got 147, and it took a third pull request to renumber one.
     */
    public function test_two_branches_sharing_a_registry_never_get_the_same_id(): void
    {
        $other = $this->dir.'-other-branch';
        mkdir($other, 0755, true);

        foreach ([$this->dir, $other] as $tree) {
            $this->seedInto($tree, 146);
        }

        $first = $this->repository($this->dir)->allocateId();
        $second = $this->repository($other)->allocateId();

        $this->assertSame(147, $first);
        $this->assertSame(148, $second, 'the second branch must not reissue the first branch\'s id');

        foreach (glob($other.'/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($other);
    }

    /**
     * The registry is gitignored, so this is the fresh-clone case: no mark at
     * all, and the tree alone must still bound the answer. An allocator that
     * trusted the store would restart at 1 and reissue every retired id.
     */
    public function test_the_tree_is_the_floor_when_the_registry_is_missing(): void
    {
        $this->seedItems(150);

        $this->assertFileDoesNotExist($this->idsPath);
        $this->assertSame(151, $this->repository($this->dir)->allocateId());
    }

    /** A mark below the tree must not drag ids back down over existing items. */
    public function test_a_stale_low_mark_cannot_lower_the_tree_floor(): void
    {
        $this->seedItems(150);
        file_put_contents($this->idsPath, json_encode(['highest' => 12])."\n");

        $this->assertSame(151, $this->repository($this->dir)->allocateId());
    }

    /** And a mark above the tree lifts it — that is the whole point of the file. */
    public function test_a_higher_mark_lifts_the_tree_floor(): void
    {
        $this->seedItems(3);
        file_put_contents($this->idsPath, json_encode(['highest' => 300])."\n");

        $this->assertSame(301, $this->repository($this->dir)->allocateId());
    }

    /**
     * The mark has no TTL and must never grow one. TodoClaims expires at 8
     * hours because an unreleasable claim is worse than none; expiring a mark
     * would reissue a live id, so the analogy does not carry.
     */
    public function test_the_mark_does_not_expire(): void
    {
        $ids = new TodoIds($this->idsPath);
        $ids->allocate(150);

        touch($this->idsPath, now()->subYear()->getTimestamp());

        $this->assertSame(151, $ids->mark(), 'an aged mark is still authoritative');
        $this->assertSame(152, $ids->allocate(0));
    }

    /** Allocation advances even when the caller's tree never changes. */
    public function test_repeated_allocation_is_monotonic(): void
    {
        $ids = new TodoIds($this->idsPath);

        $this->assertSame([5, 6, 7], [$ids->allocate(4), $ids->allocate(4), $ids->allocate(4)]);
    }

    /** The file is hand-editable, so a mangled one must not take out todo:new. */
    public function test_a_corrupt_registry_falls_back_to_the_tree(): void
    {
        $this->seedItems(40);
        file_put_contents($this->idsPath, 'not json at all');

        $this->assertSame(0, (new TodoIds($this->idsPath))->mark());
        $this->assertSame(41, $this->repository($this->dir)->allocateId());
    }

    public function test_it_records_the_id_it_handed_out(): void
    {
        (new TodoIds($this->idsPath))->allocate(99);

        $this->assertSame(['highest' => 100], json_decode((string) file_get_contents($this->idsPath), true));
    }

    private function repository(string $dir): TodoRepository
    {
        return new TodoRepository($dir, $this->index, new TodoIds($this->idsPath));
    }

    private function seedItems(int ...$ids): void
    {
        $this->seedInto($this->dir, ...$ids);
    }

    private function seedInto(string $dir, int ...$ids): void
    {
        foreach ($ids as $id) {
            $item = new TodoItem(
                id: $id,
                slug: 'item-'.$id,
                title: 'Item '.$id,
                section: 'Ideas',
                position: $id,
                body: 'Body.',
            );

            file_put_contents($dir.'/'.$item->filename(), $item->render());
        }
    }
}
