<?php

namespace Timot\TodoItems\Tests;

use Timot\TodoItems\TodoIds;
use Timot\TodoItems\TodoItem;
use Timot\TodoItems\TodoRepository;

/**
 * `todo-items.auto_index` decides whether a branch may rewrite the generated
 * index, and it exists because of a conflict git cannot be asked to settle.
 *
 * The index is derived, committed, and rewritten by every close. Where the
 * project's rule is one branch per item, that makes `## Done` the one block
 * every branch edits, so two branches conflict on it as a matter of structure
 * rather than luck. `TODO.md merge=union` resolves that for git — merge, rebase,
 * pull — and not for GitHub, which reports the pull request CONFLICTING anyway;
 * a branch `git merge-tree` calls clean is still unmergeable through the button.
 * So the fix cannot be a better merge. It has to be that branches stop writing
 * the file, and the default branch regenerates after the merge.
 *
 * What the flag must therefore hold, and what these tests pin: the incidental
 * writes stop, the explicit one does not, and adoption — the documented
 * fallback where a hand-appended bullet becomes a real item — keeps working
 * against an index that is now, by design, out of date.
 */
class AutoIndexTest extends TestCase
{
    private string $dir;

    private string $index;

    private string $idsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = $this->tempPath('auto-index-'.uniqid());
        $this->index = $this->dir.'-INDEX.md';
        $this->idsPath = $this->dir.'-ids.json';

        mkdir($this->dir, 0755, true);
        file_put_contents($this->dir.'/_index.md', "## High Priority\n\n## Ideas\n");

        $this->app->instance(TodoRepository::class, $this->repository());
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

    private function repository(): TodoRepository
    {
        return new TodoRepository($this->dir, $this->index, new TodoIds($this->idsPath));
    }

    private function item(int $id, array $overrides = []): TodoItem
    {
        return new TodoItem(
            id: $id,
            slug: $overrides['slug'] ?? 'item-'.$id,
            title: $overrides['title'] ?? 'Item '.$id,
            section: $overrides['section'] ?? 'High Priority',
            position: $overrides['position'] ?? $id,
            status: $overrides['status'] ?? TodoItem::STATUS_OPEN,
            closed: $overrides['closed'] ?? null,
            body: $overrides['body'] ?? 'Body of item '.$id,
        );
    }

    private function switchOff(): void
    {
        $this->app->make('config')->set('todo-items.auto_index', false);
    }

    public function test_the_default_is_unchanged_so_an_existing_consumer_sees_nothing(): void
    {
        $repository = $this->repository();
        $repository->save($this->item(1, ['title' => 'Ship the sweep']));
        $repository->writeIndex();

        $this->assertTrue($this->app->make('config')->get('todo-items.auto_index'));

        $this->artisan('todo:done', ['id' => ['1'], '--date' => '2026-09-18'])->assertSuccessful();

        $this->assertStringContainsString('*(2026-09-18)*', (string) file_get_contents($this->index));
    }

    public function test_todo_done_closes_the_item_without_touching_the_index(): void
    {
        $repository = $this->repository();
        $path = $repository->save($this->item(1, ['title' => 'Ship the sweep']));
        $repository->writeIndex();

        $before = (string) file_get_contents($this->index);
        $this->switchOff();

        $this->artisan('todo:done', ['id' => ['1'], '--date' => '2026-09-18'])
            ->expectsOutputToContain('Closed #001')
            ->assertSuccessful();

        // The close landed in the item file, which is the truth; the derived
        // file is byte-identical, so the branch carries no diff on it at all.
        $reloaded = TodoItem::fromFile($path);
        $this->assertSame(TodoItem::STATUS_DONE, $reloaded->status);
        $this->assertSame('2026-09-18', $reloaded->closed);
        $this->assertSame($before, (string) file_get_contents($this->index));
    }

    public function test_todo_new_says_it_left_the_index_alone(): void
    {
        $this->repository()->writeIndex();
        $before = (string) file_get_contents($this->index);

        $this->switchOff();

        // A silent skip reads as the command not having worked: the item file is
        // already written by then, so the note is part of the behaviour.
        $this->artisan('todo:new', ['title' => 'A fresh item', '--section' => 'Ideas'])
            ->expectsOutputToContain('auto_index')
            ->assertSuccessful();

        $this->assertNotNull($this->repository()->all()->firstWhere('title', 'A fresh item'));
        $this->assertSame($before, (string) file_get_contents($this->index));
    }

    public function test_todo_index_still_writes_because_it_is_what_the_default_branch_runs(): void
    {
        $repository = $this->repository();
        $repository->save($this->item(1, ['title' => 'Ship the sweep', 'status' => TodoItem::STATUS_DONE, 'closed' => '2026-09-18']));

        $this->switchOff();

        $this->artisan('todo:index')->assertSuccessful();

        $this->assertStringContainsString('Ship the sweep', (string) file_get_contents($this->index));
    }

    public function test_adoption_survives_an_index_that_is_deliberately_out_of_date(): void
    {
        $repository = $this->repository();
        $repository->save($this->item(1, ['title' => 'Already filed']));
        $repository->writeIndex();

        $this->switchOff();

        // The index now falls behind on purpose: #002 gets a file and no row.
        $this->artisan('todo:new', ['title' => 'Made on a branch', '--section' => 'Ideas'])->assertSuccessful();
        $this->assertStringNotContainsString('Made on a branch', (string) file_get_contents($this->index));

        // And a bullet is appended by hand, the fallback the consuming project
        // documents for an agent whose context predates the tooling.
        file_put_contents($this->index, (string) file_get_contents($this->index)."- **Jotted down** — by hand.\n");

        $this->artisan('todo:index')->assertSuccessful();

        // Staleness can only ever *omit* generated rows, never invent id-less
        // ones, so a missing row is not mistaken for a hand-written item: the
        // bullet is adopted, and nothing already on disk is adopted a second
        // time under a fresh id.
        $all = $repository->all();
        $adopted = $all->firstWhere('title', 'Jotted down');

        $this->assertNotNull($adopted);
        $this->assertSame('by hand.', $adopted->body);
        $this->assertSame(3, $all->count());
        $this->assertSame(1, $all->where('title', 'Already filed')->count());
        $this->assertSame(1, $all->where('title', 'Made on a branch')->count());
    }
}
