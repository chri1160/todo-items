<?php

namespace Timot\TodoItems\Tests;

use Timot\TodoItems\Support\Worktree;
use Timot\TodoItems\TodoClaims;
use Timot\TodoItems\TodoIds;
use Timot\TodoItems\TodoItem;
use Timot\TodoItems\TodoRepository;
use Timot\TodoItems\TodoWorktrees;

/**
 * An item with a worktree on it is being worked on, and does not expire.
 *
 * This is the half of the coordination problem a claim cannot do. A claim is stamped
 * once and never refreshed, so at `STALE_HOURS` it drops whether or not anyone is still
 * working — and it is keyed on a session, so it cannot simply be made to last longer
 * without leaving a hold nobody can account for. A worktree is the opposite on both
 * counts: shared across sessions because it is bookkeeping in the main tree's `.git`,
 * and self-clearing because it goes when the branch lands.
 *
 * Built from real directories, like {@see WorktreeTest}, and for the same reason: every
 * claim this makes is a claim about what is on disk. A mocked reader would agree with
 * an implementation that could never find a worktree at all — which is precisely the
 * failure being guarded against, because a reader that finds nothing is indistinguishable
 * from one that correctly found nothing. No `git` binary is involved here and none is in
 * the implementation: `git worktree list` prints the host's absolute paths, which is the
 * thing {@see Worktree} exists to avoid trusting.
 *
 * So the negatives are asserted as hard as the positives, and in the same fixture: a
 * project whose worktrees carry no ids — which is one of the two consumers today — must
 * get exactly the behaviour it had before any of this existed.
 */
class TodoWorktreeTest extends TestCase
{
    private string $dir;

    private string $index;

    private string $idsPath;

    private string $registry;

    private string $main;

    private ?string $originalSession = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = $this->tempPath('todo-wt-'.uniqid());
        $this->index = $this->dir.'-INDEX.md';
        $this->idsPath = $this->dir.'-ids.json';
        $this->registry = $this->dir.'-claims.json';
        $this->main = $this->dir.'-main';

        mkdir($this->dir, 0755, true);
        file_put_contents($this->dir.'/_index.md', "## High Priority\n\n## Ideas\n");

        // A main working tree: `.git` is a directory, and the linked worktrees are
        // recorded inside it. Nothing reads the checkouts themselves.
        mkdir($this->main.'/.git', 0755, true);

        $this->originalSession = getenv('CLAUDE_CODE_SESSION_ID') ?: null;
        putenv('CLAUDE_CODE_SESSION_ID=aaaa1111');

        $this->app->instance(TodoRepository::class, new TodoRepository($this->dir, $this->index, new TodoIds($this->idsPath)));
        $this->app->instance(TodoClaims::class, new TodoClaims($this->registry));
        $this->app->instance(TodoWorktrees::class, new TodoWorktrees($this->main));
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
        @unlink($this->registry);
        @unlink($this->registry.'.lock');
        exec('rm -rf '.escapeshellarg($this->main));

        $this->originalSession === null
            ? putenv('CLAUDE_CODE_SESSION_ID')
            : putenv('CLAUDE_CODE_SESSION_ID='.$this->originalSession);

        parent::tearDown();
    }

    /**
     * A linked worktree as git records it: a directory named for the checkout, with a
     * `HEAD` naming the branch. A `$branch` of null stands for a detached HEAD.
     */
    private function worktree(string $name, ?string $branch = null): void
    {
        $path = $this->main.'/.git/worktrees/'.$name;
        mkdir($path, 0755, true);

        file_put_contents($path.'/HEAD', $branch === null
            ? "9c1d2a3f4b5c6d7e8f90112233445566778899aa\n"
            : 'ref: refs/heads/'.$branch."\n");
    }

    private function worktrees(): TodoWorktrees
    {
        return $this->app->make(TodoWorktrees::class);
    }

    private function claims(): TodoClaims
    {
        return $this->app->make(TodoClaims::class);
    }

    private function item(int $id, array $overrides = []): TodoItem
    {
        $item = new TodoItem(
            id: $id,
            slug: $overrides['slug'] ?? 'item-'.$id,
            title: $overrides['title'] ?? 'Item '.$id,
            section: $overrides['section'] ?? 'High Priority',
            position: $overrides['position'] ?? $id,
            status: $overrides['status'] ?? TodoItem::STATUS_OPEN,
            closed: $overrides['closed'] ?? null,
            body: $overrides['body'] ?? 'Body of item '.$id,
        );

        $this->app->make(TodoRepository::class)->save($item);

        return $item;
    }

    public function test_a_worktree_naming_an_item_puts_it_in_progress(): void
    {
        $this->worktree('141-promotion-cutoff', 'worktree-141-promotion-cutoff');

        $this->assertSame([141 => '141-promotion-cutoff'], $this->worktrees()->inProgress());
        $this->assertSame('141-promotion-cutoff', $this->worktrees()->nameFor(141));
        $this->assertTrue($this->worktrees()->has(141));
        $this->assertFalse($this->worktrees()->has(142));
    }

    public function test_the_branch_is_read_when_the_directory_name_carries_no_id(): void
    {
        // The harness names the checkout and the branch separately, and only one of
        // them has to carry the id for the item to be accounted for.
        $this->worktree('has-interacted', 'worktree-todo-119-has-interacted');

        $this->assertSame([119 => 'has-interacted'], $this->worktrees()->inProgress());
    }

    public function test_leading_zeros_and_a_hash_name_the_same_item(): void
    {
        // Nobody agrees on how an id is written down: `#141`, `141`, `0141`, all of
        // them the same item. Matching on runs of digits reads every spelling.
        $this->worktree('fix-#0141-cutoff', 'chore/007-early');

        $this->assertSame(
            [7 => 'fix-#0141-cutoff', 141 => 'fix-#0141-cutoff'],
            $this->worktrees()->inProgress(),
        );
    }

    public function test_a_detached_worktree_still_matches_on_its_directory_name(): void
    {
        // A detached HEAD holds a sha, which names no item — and a sha is hex, so the
        // digit runs in it must not be read as ids either.
        $this->worktree('141-promotion-cutoff');

        $this->assertSame([141 => '141-promotion-cutoff'], $this->worktrees()->inProgress());
    }

    public function test_a_worktree_whose_names_carry_no_id_marks_nothing(): void
    {
        // The state of the consuming project the day this was written: three live
        // worktrees, two of them on items, none of them named after one. The package
        // cannot dictate the name, so this has to read as "nothing known", not as a
        // guess — an id invented here would withhold an item nobody is working on.
        $this->worktree('has-interacted', 'worktree-has-interacted');
        $this->worktree('rds-database', 'worktree-rds-database');
        $this->worktree('todo-claim-expiry', 'worktree-todo-claim-expiry');

        $this->assertSame([], $this->worktrees()->inProgress());
        $this->assertNull($this->worktrees()->nameFor(141));
    }

    public function test_a_tree_with_no_worktrees_at_all_reads_as_none(): void
    {
        // No `.git/worktrees` directory, which is every repository nobody has run
        // `git worktree add` in. The reader must be uneventful about it.
        $this->assertSame([], $this->worktrees()->inProgress());
        $this->assertSame([], $this->worktrees()->all());
    }

    public function test_an_item_a_worktree_is_on_is_not_handed_to_the_next_agent(): void
    {
        $this->item(1, ['position' => 1, 'title' => 'Being worked on']);
        $this->item(2, ['position' => 2, 'title' => 'Genuinely free']);

        // First, with no worktrees: the highest-priority item, exactly as before. The
        // two halves are one test on purpose — a reader that silently found nothing
        // would pass the first assertion and fail the second, and they are otherwise
        // the same assertion.
        $this->artisan('todo:claim')
            ->expectsOutputToContain('Being worked on')
            ->assertSuccessful();

        $this->artisan('todo:claim', ['id' => '1', '--release' => true])->assertSuccessful();

        $this->worktree('001-being-worked-on', 'worktree-001-being-worked-on');

        $this->artisan('todo:claim')
            ->expectsOutputToContain('Genuinely free')
            ->assertSuccessful();

        $this->assertNull($this->claims()->holder(1));
    }

    public function test_a_worktree_holds_an_item_long_after_a_claim_would_have_gone_stale(): void
    {
        // The whole point. A claim is stamped once, so an item worked over several days
        // on one branch outlives its claim by construction and is handed to somebody
        // else; the worktree is still there, and does not age.
        $this->item(1, ['position' => 1, 'title' => 'A week of work']);
        $this->item(2, ['position' => 2, 'title' => 'Something else']);

        $this->artisan('todo:claim', ['id' => '1'])->assertSuccessful();
        $this->worktree('001-a-week-of-work', 'worktree-001-a-week-of-work');

        $this->travel(TodoClaims::STALE_HOURS * 10)->hours();

        // The claim is gone, as it always was...
        $this->assertNull($this->claims()->holder(1));

        // ...and the item is still not offered to anyone.
        $this->assertSame([1 => '001-a-week-of-work'], $this->worktrees()->inProgress());

        $this->artisan('todo:claim')
            ->expectsOutputToContain('Something else')
            ->assertSuccessful();
    }

    public function test_without_a_worktree_a_stale_claim_returns_the_item_exactly_as_before(): void
    {
        // The same fixture without the worktree, so the assertion above is about the
        // worktree rather than about anything else that changed.
        $this->item(1, ['position' => 1, 'title' => 'Abandoned']);
        $this->item(2, ['position' => 2, 'title' => 'Something else']);

        $this->artisan('todo:claim', ['id' => '1'])->assertSuccessful();

        $this->travel(TodoClaims::STALE_HOURS * 10)->hours();

        $this->artisan('todo:claim')
            ->expectsOutputToContain('Abandoned')
            ->assertSuccessful();
    }

    public function test_the_list_marks_in_progress_separately_from_a_claim(): void
    {
        $this->item(1, ['position' => 1, 'title' => 'On a branch']);
        $this->item(2, ['position' => 2, 'title' => 'Merely claimed']);

        $this->worktree('001-on-a-branch', 'worktree-001-on-a-branch');
        $this->artisan('todo:claim', ['id' => '2'])->assertSuccessful();

        $this->artisan('todo:list')
            ->expectsOutputToContain('in progress: 001-on-a-branch')
            ->expectsOutputToContain('held by you')
            ->assertSuccessful();
    }

    public function test_an_in_progress_item_is_not_available_and_reads_as_taken(): void
    {
        $this->item(1, ['position' => 1, 'title' => 'On a branch']);
        $this->item(2, ['position' => 2, 'title' => 'Genuinely free']);

        $this->worktree('001-on-a-branch', 'worktree-001-on-a-branch');

        // `available` is what the next agent would be offered, so it has to agree with
        // what `todo:claim` actually hands out...
        $this->artisan('todo:list', ['--status' => 'available'])
            ->expectsOutputToContain('Genuinely free')
            ->doesntExpectOutputToContain('On a branch')
            ->assertSuccessful();

        // ...and `claimed` is its complement, or an item falls between the two filters
        // and reads as though it had been deleted.
        $this->artisan('todo:list', ['--status' => 'claimed'])
            ->expectsOutputToContain('On a branch')
            ->doesntExpectOutputToContain('Genuinely free')
            ->assertSuccessful();
    }

    public function test_the_list_is_untouched_where_no_worktree_names_an_item(): void
    {
        $this->item(1, ['position' => 1, 'title' => 'Claimed thing']);
        $this->item(2, ['position' => 2, 'title' => 'Free thing']);

        $this->worktree('rds-database', 'worktree-rds-database');
        $this->artisan('todo:claim', ['id' => '1'])->assertSuccessful();

        $this->artisan('todo:list')
            ->expectsOutputToContain('held by you')
            ->doesntExpectOutputToContain('in progress')
            ->assertSuccessful();

        $this->artisan('todo:list', ['--status' => 'available'])
            ->expectsOutputToContain('Free thing')
            ->doesntExpectOutputToContain('Claimed thing')
            ->assertSuccessful();
    }

    public function test_claiming_suggests_a_worktree_name_carrying_the_id(): void
    {
        // The loop-closer. The package cannot name the worktree, so it asks for the one
        // name it will be able to read back, at the moment the id is on screen anyway.
        $item = $this->item(141, ['slug' => 'promotion-cutoff', 'title' => 'Promotion cutoff']);

        $this->artisan('todo:claim', ['id' => '141'])
            ->expectsOutputToContain('141-promotion-cutoff')
            ->assertSuccessful();

        $this->assertSame('141-promotion-cutoff', TodoWorktrees::suggestedName($item));
    }

    public function test_the_suggested_name_is_one_this_reader_matches_back(): void
    {
        // A suggestion the matcher would not read back is worse than no suggestion:
        // everyone follows it and nothing is ever marked in progress.
        $item = $this->item(7, ['slug' => 'early-item']);

        $this->worktree(TodoWorktrees::suggestedName($item), 'worktree-'.TodoWorktrees::suggestedName($item));

        $this->assertSame('007-early-item', $this->worktrees()->nameFor(7));
    }

    public function test_claiming_an_item_a_worktree_is_already_on_says_so(): void
    {
        // Reachable only by naming the id — and the worktree may well be this agent's
        // own, so it is a caution rather than a refusal.
        $this->item(1, ['title' => 'Contested']);
        $this->worktree('001-contested', 'worktree-001-contested');

        $this->artisan('todo:claim', ['id' => '1'])
            ->expectsOutputToContain('A worktree is already on this one: `001-contested`')
            ->assertSuccessful();
    }

    public function test_nothing_available_names_worktrees_only_when_there_are_some(): void
    {
        $this->item(1, ['title' => 'The only one']);

        $this->worktree('001-the-only-one', 'worktree-001-the-only-one');

        $this->artisan('todo:claim')
            ->expectsOutputToContain('claimed or in a worktree')
            ->assertSuccessful();

        exec('rm -rf '.escapeshellarg($this->main.'/.git/worktrees'));
        $this->artisan('todo:claim', ['id' => '1'])->assertSuccessful();

        // With nothing skipped by a worktree, the sentence is the one it always was.
        $this->actAsAnotherAgent();

        $this->artisan('todo:claim')
            ->expectsOutputToContain('every open item is claimed.')
            ->assertSuccessful();
    }

    private function actAsAnotherAgent(): void
    {
        putenv('CLAUDE_CODE_SESSION_ID=bbbb2222');
    }
}
