<?php

namespace Timot\TodoItems\Tests;

use Timot\TodoItems\TodoIds;
use Timot\TodoItems\TodoItem;
use Timot\TodoItems\TodoParser;
use Timot\TodoItems\TodoRepository;

/**
 * The TODO list is file-backed: one item per file under `todo/`, with `TODO.md`
 * generated over them.
 *
 * Two behaviours here are load-bearing rather than convenient. **Adoption** —
 * a hand-written bullet in the generated index becomes a real item file instead
 * of being erased on the next regenerate; without it, a generated file in a
 * checkout where several agents work at once silently eats their edits. And
 * **closing keeps the item in place** — `todo:done` flips frontmatter and lets
 * the index move the row to `Done`, which is the entire reason the list is not
 * one file nobody can edit concurrently.
 */
class TodoListTest extends TestCase
{
    private string $dir;

    private string $index;

    private string $idsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = $this->tempPath('todo-'.uniqid());
        $this->index = $this->dir.'-INDEX.md';
        $this->idsPath = $this->dir.'-ids.json';

        mkdir($this->dir, 0755, true);
        file_put_contents($this->dir.'/_index.md', "## High Priority\n\n## Ideas\n\nSomething to note.\n");

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

    public function test_frontmatter_round_trips_including_markdown_and_quotes(): void
    {
        // Titles carry backticks, bold, em dashes and quotes; the hand-rolled
        // frontmatter writer has to survive all of them.
        $title = 'Award `bonus` on a <20% result — **not** the "exact score" one \\ here';

        $original = $this->item(7, ['title' => $title, 'body' => "Line one\n\n- a bullet\n"]);
        $path = $this->repository()->save($original);

        $reloaded = TodoItem::fromFile($path);

        $this->assertSame($title, $reloaded->title);
        $this->assertSame(7, $reloaded->id);
        $this->assertSame('High Priority', $reloaded->section);
        $this->assertSame("Line one\n\n- a bullet", $reloaded->body);
        $this->assertNull($reloaded->closed);
    }

    public function test_index_groups_open_items_by_section_and_done_items_newest_first(): void
    {
        $repository = $this->repository();

        $repository->save($this->item(1, ['section' => 'High Priority', 'position' => 1, 'title' => 'Alpha']));
        $repository->save($this->item(2, ['section' => 'Ideas', 'position' => 1, 'title' => 'Beta']));
        $repository->save($this->item(3, [
            'section' => 'High Priority', 'position' => 2, 'title' => 'Gamma',
            'status' => TodoItem::STATUS_DONE, 'closed' => '2026-01-01',
        ]));
        $repository->save($this->item(4, [
            'section' => 'Ideas', 'position' => 2, 'title' => 'Delta',
            'status' => TodoItem::STATUS_DONE, 'closed' => '2026-06-01',
        ]));

        $index = $repository->renderIndex();

        // A done item leaves its section entirely and lands under Done, newest
        // first, whatever section it was closed out of.
        $this->assertMatchesRegularExpression(
            '/## High Priority.*Alpha.*## Ideas.*Something to note\..*Beta.*## Done.*Delta.*Gamma/s',
            $index,
        );
        $this->assertStringNotContainsString('Gamma', explode('## Done', $index)[0]);
        $this->assertStringContainsString('*(2026-06-01)*', $index);
        $this->assertStringContainsString('](todo-', $index);
    }

    public function test_index_notes_an_empty_section_rather_than_omitting_it(): void
    {
        $index = $this->repository()->renderIndex();

        $this->assertStringContainsString('## High Priority', $index);
        $this->assertStringContainsString('*Nothing here yet.*', $index);
    }

    public function test_todo_done_moves_the_row_without_moving_the_file(): void
    {
        $repository = $this->repository();
        $path = $repository->save($this->item(5, ['section' => 'High Priority', 'title' => 'Ship the standings sweep']));
        $repository->writeIndex();

        $this->artisan('todo:done', ['id' => ['5'], '--date' => '2026-08-08'])->assertSuccessful();

        $this->assertFileExists($path);

        $reloaded = TodoItem::fromFile($path);
        $this->assertSame(TodoItem::STATUS_DONE, $reloaded->status);
        $this->assertSame('2026-08-08', $reloaded->closed);

        // Section is preserved on close, so what was closed out of which area
        // stays on the record even though the index files it under Done.
        $this->assertSame('High Priority', $reloaded->section);

        $index = (string) file_get_contents($this->index);
        $this->assertStringNotContainsString('Ship the standings sweep', explode('## Done', $index)[0]);
    }

    public function test_todo_done_accepts_a_padded_reference_and_reopens(): void
    {
        $repository = $this->repository();
        $repository->save($this->item(6, ['status' => TodoItem::STATUS_DONE, 'closed' => '2026-08-01']));

        $this->artisan('todo:done', ['id' => ['#006'], '--reopen' => true])->assertSuccessful();

        $reloaded = $repository->find(6);
        $this->assertSame(TodoItem::STATUS_OPEN, $reloaded->status);
        $this->assertNull($reloaded->closed);
    }

    public function test_todo_done_rejects_a_malformed_date(): void
    {
        $this->repository()->save($this->item(8));

        $this->artisan('todo:done', ['id' => ['8'], '--date' => 'yesterday'])->assertFailed();

        $this->assertSame(TodoItem::STATUS_OPEN, $this->repository()->find(8)->status);
    }

    public function test_todo_index_adopts_a_hand_written_bullet(): void
    {
        $repository = $this->repository();
        $repository->save($this->item(1, ['title' => 'Existing item']));
        $repository->writeIndex();

        // Exactly what an agent holding pre-split instructions would do, or a
        // person in a hurry: append a bullet to the generated file.
        file_put_contents(
            $this->index,
            (string) file_get_contents($this->index)
                ."- **Brand new thing** — with a body that must survive.\n",
        );

        $this->artisan('todo:index')->assertSuccessful();

        $adopted = $repository->all()->firstWhere('title', 'Brand new thing');

        $this->assertNotNull($adopted);
        $this->assertSame(2, $adopted->id);
        $this->assertSame('with a body that must survive.', $adopted->body);
        $this->assertSame(TodoItem::STATUS_OPEN, $adopted->status);
        $this->assertStringContainsString('Brand new thing', (string) file_get_contents($this->index));
    }

    public function test_a_dateless_bullet_appended_below_done_is_adopted_as_open_work(): void
    {
        // `Done` is the last heading the generator writes, so appending to the
        // bottom of the file lands under it. Reading that position as "shipped"
        // would file new work as already finished — the one wrong answer that
        // hides itself, since nobody re-reads the Done list.
        $repository = $this->repository();
        $repository->writeIndex();

        file_put_contents(
            $this->index,
            (string) file_get_contents($this->index)."- **Jotted at the bottom** — no date on this one.\n",
        );

        $this->artisan('todo:index')->assertSuccessful();

        $adopted = $repository->all()->firstWhere('title', 'Jotted at the bottom');

        $this->assertNotNull($adopted);
        $this->assertSame(TodoItem::STATUS_OPEN, $adopted->status);
        $this->assertNull($adopted->closed);
        $this->assertSame('Ideas', $adopted->section);
    }

    public function test_todo_index_does_not_fork_an_item_whose_title_already_has_a_file(): void
    {
        $repository = $this->repository();
        $repository->save($this->item(1, ['title' => 'Existing item']));

        // A generated row rewritten by hand into bullet form: same item, so
        // adopting it would produce two files for one piece of work.
        file_put_contents($this->index, "# TODO\n\n## High Priority\n\n- **Existing item** — reworded body.\n");

        $this->artisan('todo:index')->assertSuccessful();

        $this->assertCount(1, $repository->all());
    }

    public function test_a_hand_written_bullet_under_done_is_adopted_as_closed(): void
    {
        $repository = $this->repository();
        file_put_contents($this->index, "# TODO\n\n## Done\n\n- **Finished thing** — (2026-07-04) it shipped.\n");

        $this->artisan('todo:index')->assertSuccessful();

        $adopted = $repository->all()->firstWhere('title', 'Finished thing');

        $this->assertNotNull($adopted);
        $this->assertSame(TodoItem::STATUS_DONE, $adopted->status);
        $this->assertSame('2026-07-04', $adopted->closed);

        // The stamp moves into frontmatter rather than being kept in both
        // places, where the two copies would drift.
        $this->assertSame('it shipped.', $adopted->body);
    }

    public function test_todo_new_allocates_the_next_id_and_never_reuses_one(): void
    {
        $repository = $this->repository();
        $repository->save($this->item(4));

        $this->artisan('todo:new', ['title' => 'A fresh item', '--section' => 'Ideas'])->assertSuccessful();

        $created = $repository->find(5);
        $this->assertSame('Ideas', $created->section);
        $this->assertSame('a-fresh-item', $created->slug);

        // Deleting the highest item must not hand its id to the next one — the
        // id is a citable handle, so reuse would repoint an existing reference.
        //
        // This asserted 5 — the id just deleted — which is reuse,
        // asserted directly beneath a comment forbidding it. The old allocator did
        // hand it back, because the tree forgets a deleted item, so the test
        // enshrined the bug rather than catching it. The high-water mark remembers
        // what the tree cannot, so 6 is now both correct and what the comment asks
        // for.
        unlink($this->dir.'/'.$created->filename());
        $this->assertSame(6, $repository->allocateId());
    }

    public function test_saving_a_renamed_slug_leaves_no_stale_file(): void
    {
        $repository = $this->repository();
        $repository->save($this->item(9, ['slug' => 'old-name']));

        $repository->save($this->item(9, ['slug' => 'new-name']));

        $this->assertFileDoesNotExist($this->dir.'/009-old-name.md');
        $this->assertFileExists($this->dir.'/009-new-name.md');
    }

    public function test_todo_index_renames_a_file_whose_slug_was_edited_by_hand(): void
    {
        // Editing `slug:` in frontmatter is the obvious way to rename an item,
        // and on its own it renames nothing — the index would then link to a
        // path that doesn't exist, which reads as a missing item rather than a
        // stale filename.
        $repository = $this->repository();
        $path = $repository->save($this->item(1, ['slug' => 'old-name', 'title' => 'Renamed thing']));

        file_put_contents($path, str_replace('slug: "old-name"', 'slug: "new-name"', (string) file_get_contents($path)));

        $this->artisan('todo:index')->assertSuccessful();

        $this->assertFileDoesNotExist($this->dir.'/001-old-name.md');
        $this->assertFileExists($this->dir.'/001-new-name.md');
        $this->assertStringContainsString('001-new-name.md', (string) file_get_contents($this->index));
    }

    public function test_slugify_strips_markdown_and_cuts_at_a_word_boundary(): void
    {
        $repository = $this->repository();

        $this->assertSame(
            'backfill-the-bbc-ids-before-the-fixture-import-runs',
            $repository->slugify('Backfill the `BBC` ids — **before** the fixture import runs'),
        );

        $long = $repository->slugify(str_repeat('alpha beta ', 20));
        $this->assertLessThanOrEqual(60, strlen($long));
        $this->assertFalse(str_ends_with($long, '-'), 'Slug should be cut at a word boundary.');
    }

    public function test_parser_reads_a_title_that_wraps_onto_the_next_line(): void
    {
        $parsed = (new TodoParser)->parse(
            "## Ideas\n\n- **A title that wraps\n  across two lines:**\n  - a nested bullet\n",
        );

        $this->assertCount(1, $parsed['items']);
        $this->assertSame('A title that wraps across two lines:', $parsed['items'][0]['title']);

        // Nesting was a consequence of living under a bullet; the item owns a
        // file now, so its content sits at the top level.
        $this->assertSame('- a nested bullet', $parsed['items'][0]['body']);
    }

    public function test_parser_keeps_deeply_indented_body_lines_and_drops_the_generated_banner(): void
    {
        // The banner is chrome and must go; a deeply indented line is content
        // and must stay. Recognising the banner by its shape — a leading run of
        // spaces — conflates the two and truncates the item mid-list, which is
        // invisible in the result because what's left still reads as prose.
        $parsed = (new TodoParser)->parse(
            "# TODO\n\n<!-- Generated by `todo:index`.\n     Do not edit. -->\n\n## Ideas\n"
            ."\n- **Deep item** — lead.\n  - a nested bullet\n    - deeper still\n      - deeper again\n",
        );

        $this->assertCount(1, $parsed['items']);

        $body = $parsed['items'][0]['body'];

        $this->assertStringContainsString('deeper still', $body);
        $this->assertStringContainsString('deeper again', $body);
        $this->assertStringNotContainsString('Do not edit', $body);
        $this->assertStringNotContainsString('Generated by', $body);
    }

    public function test_an_item_claiming_an_unlisted_section_still_reaches_the_index(): void
    {
        $repository = $this->repository();
        $repository->save($this->item(1, ['section' => 'Nowhere', 'title' => 'Orphan']));

        $this->assertStringContainsString('## Nowhere', $repository->renderIndex());
        $this->assertStringContainsString('Orphan', $repository->renderIndex());
    }

    public function test_todo_list_filters_to_open_items_in_one_section(): void
    {
        $repository = $this->repository();
        $repository->save($this->item(1, ['section' => 'High Priority', 'title' => 'Still open']));
        $repository->save($this->item(2, ['section' => 'Ideas', 'title' => 'Another section']));
        $repository->save($this->item(3, [
            'section' => 'High Priority', 'title' => 'Already shipped',
            'status' => TodoItem::STATUS_DONE, 'closed' => '2026-01-01',
        ]));

        // Partial, case-insensitive: typing the whole section name exactly is
        // the friction the shell loop had.
        $this->artisan('todo:list', ['--section' => 'high'])
            ->expectsOutputToContain('Still open')
            ->doesntExpectOutputToContain('Another section')
            ->doesntExpectOutputToContain('Already shipped')
            ->assertSuccessful();
    }

    public function test_todo_list_reports_what_the_limit_dropped(): void
    {
        $repository = $this->repository();

        foreach (range(1, 4) as $id) {
            $repository->save($this->item($id, ['title' => 'Item number '.$id]));
        }

        // A cap that prints nothing about truncating reads as the whole list.
        $this->artisan('todo:list', ['--limit' => 2])
            ->expectsOutputToContain('2 shown, 2 more matched')
            ->assertSuccessful();
    }

    public function test_todo_list_rejects_an_unknown_status(): void
    {
        $this->repository()->save($this->item(1));

        $this->artisan('todo:list', ['--status' => 'pending'])->assertFailed();
    }
}
