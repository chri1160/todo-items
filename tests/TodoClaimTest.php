<?php

namespace Timot\TodoItems\Tests;

use Timot\TodoItems\Support\AgentSession;
use Timot\TodoItems\Support\Worktree;
use Timot\TodoItems\TodoClaims;
use Timot\TodoItems\TodoIds;
use Timot\TodoItems\TodoItem;
use Timot\TodoItems\TodoRepository;

/**
 * Claims exist so two agents don't start the same item.
 *
 * The load-bearing behaviour is that picking and taking are **one** operation:
 * a `todo:list` followed by a `todo:claim` is the same race one step removed,
 * because both agents read the same free item before either writes. So the
 * id-less form is tested hardest.
 *
 * Everything else follows from claims being runtime state rather than part of
 * the item: they live in one registry under `storage/framework/`, not in
 * frontmatter, so a claim is visible to every agent the moment it is taken
 * rather than when a branch merges — and they expire, because an agent that is
 * killed cannot release anything.
 */
class TodoClaimTest extends TestCase
{
    private string $dir;

    private string $index;

    private string $idsPath;

    private string $registry;

    private ?string $originalSession = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = $this->tempPath('todo-'.uniqid());
        $this->index = $this->dir.'-INDEX.md';
        $this->idsPath = $this->dir.'-ids.json';
        $this->registry = $this->dir.'-claims.json';

        mkdir($this->dir, 0755, true);
        file_put_contents($this->dir.'/_index.md', "## High Priority\n\n## Ideas\n");

        $this->originalSession = getenv('CLAUDE_CODE_SESSION_ID') ?: null;
        $this->actAsAgent('aaaa1111');

        $this->app->instance(TodoRepository::class, new TodoRepository($this->dir, $this->index, new TodoIds($this->idsPath)));
        $this->app->instance(TodoClaims::class, new TodoClaims($this->registry));
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

        $this->originalSession === null
            ? putenv('CLAUDE_CODE_SESSION_ID')
            : putenv('CLAUDE_CODE_SESSION_ID='.$this->originalSession);

        parent::tearDown();
    }

    private function actAsAgent(string $key): void
    {
        // AgentSession reads the session id straight from the environment, which
        // is what makes one agent distinguishable from another here.
        putenv('CLAUDE_CODE_SESSION_ID='.$key);
    }

    private function claims(): TodoClaims
    {
        return $this->app->make(TodoClaims::class);
    }

    private function repository(): TodoRepository
    {
        return $this->app->make(TodoRepository::class);
    }

    private function item(int $id, array $overrides = []): TodoItem
    {
        $item = new TodoItem(
            id: $id,
            slug: 'item-'.$id,
            title: $overrides['title'] ?? 'Item '.$id,
            section: $overrides['section'] ?? 'High Priority',
            position: $overrides['position'] ?? $id,
            status: $overrides['status'] ?? TodoItem::STATUS_OPEN,
            closed: $overrides['closed'] ?? null,
            body: $overrides['body'] ?? 'Body of item '.$id,
        );

        $this->repository()->save($item);

        return $item;
    }

    public function test_an_id_less_claim_takes_the_highest_priority_open_item(): void
    {
        // Section order in `_index.md` is the priority, so an Ideas item at
        // position 1 must not outrank a High Priority item at position 2 — the
        // trap in sorting items by position alone.
        $this->item(1, ['section' => 'Ideas', 'position' => 1, 'title' => 'Someday']);
        $this->item(2, ['section' => 'High Priority', 'position' => 2, 'title' => 'Urgent']);

        $this->artisan('todo:claim')
            ->expectsOutputToContain('Urgent')
            ->assertSuccessful();

        $this->assertSame('aaaa1111', $this->claims()->holder(2)['by']);
    }

    public function test_a_second_agent_is_handed_the_next_item_not_the_claimed_one(): void
    {
        $this->item(1, ['position' => 1, 'title' => 'First']);
        $this->item(2, ['position' => 2, 'title' => 'Second']);

        $this->artisan('todo:claim')->assertSuccessful();

        $this->actAsAgent('bbbb2222');

        // The whole point: the next agent needs no coordination beyond the list.
        $this->artisan('todo:claim')
            ->expectsOutputToContain('Second')
            ->assertSuccessful();

        $this->assertSame('aaaa1111', $this->claims()->holder(1)['by']);
        $this->assertSame('bbbb2222', $this->claims()->holder(2)['by']);
    }

    public function test_claiming_an_item_another_agent_holds_fails_until_forced(): void
    {
        $this->item(1, ['title' => 'Contested']);

        $this->artisan('todo:claim', ['id' => '1'])->assertSuccessful();

        $this->actAsAgent('bbbb2222');

        $this->artisan('todo:claim', ['id' => '1'])
            ->expectsOutputToContain('held by aaaa1111')
            ->assertFailed();

        $this->assertSame('aaaa1111', $this->claims()->holder(1)['by']);

        $this->artisan('todo:claim', ['id' => '1', '--force' => true])->assertSuccessful();

        $this->assertSame('bbbb2222', $this->claims()->holder(1)['by']);
    }

    public function test_a_claim_expires_so_a_killed_agent_cannot_hold_an_item_forever(): void
    {
        $this->item(1, ['title' => 'Abandoned']);

        $this->artisan('todo:claim', ['id' => '1'])->assertSuccessful();

        // An agent that is killed releases nothing, and there is no liveness
        // signal for a session — so an item held past the staleness window has
        // to return to the pool on its own, or it is lost to everyone.
        $this->travel(TodoClaims::STALE_HOURS + 1)->hours();

        $this->actAsAgent('bbbb2222');

        $this->assertNull($this->claims()->holder(1));

        $this->artisan('todo:claim')
            ->expectsOutputToContain('Abandoned')
            ->assertSuccessful();
    }

    public function test_releasing_hands_the_item_back_and_needs_force_for_another_agents_claim(): void
    {
        $this->item(1, ['title' => 'Mine']);

        $this->artisan('todo:claim', ['id' => '1'])->assertSuccessful();

        $this->actAsAgent('bbbb2222');

        $this->artisan('todo:claim', ['id' => '1', '--release' => true])
            ->expectsOutputToContain('held by aaaa1111')
            ->assertFailed();

        $this->actAsAgent('aaaa1111');

        $this->artisan('todo:claim', ['id' => '1', '--release' => true])->assertSuccessful();

        $this->assertNull($this->claims()->holder(1));
    }

    public function test_an_id_less_release_hands_back_everything_this_agent_holds(): void
    {
        $this->item(1);
        $this->item(2);
        $this->item(3);

        $this->artisan('todo:claim', ['id' => '1'])->assertSuccessful();
        $this->artisan('todo:claim', ['id' => '2'])->assertSuccessful();

        $this->actAsAgent('bbbb2222');
        $this->artisan('todo:claim', ['id' => '3'])->assertSuccessful();

        $this->actAsAgent('aaaa1111');
        $this->artisan('todo:claim', ['--release' => true])->assertSuccessful();

        $this->assertNull($this->claims()->holder(1));
        $this->assertNull($this->claims()->holder(2));

        // Another agent's claim is untouched by my tidy-up.
        $this->assertSame('bbbb2222', $this->claims()->holder(3)['by']);
    }

    public function test_closing_an_item_releases_its_claim(): void
    {
        $this->item(1, ['title' => 'Shipped']);

        $this->artisan('todo:claim', ['id' => '1'])->assertSuccessful();
        $this->artisan('todo:done', ['id' => ['1'], '--date' => '2026-08-13'])->assertSuccessful();

        $this->assertNull($this->claims()->holder(1));
    }

    public function test_a_done_item_is_never_offered_or_claimable(): void
    {
        $this->item(1, [
            'title' => 'Already shipped', 'position' => 1,
            'status' => TodoItem::STATUS_DONE, 'closed' => '2026-01-01',
        ]);
        $this->item(2, ['title' => 'Still open', 'position' => 2]);

        $this->artisan('todo:claim', ['id' => '1'])
            ->expectsOutputToContain('already done')
            ->assertFailed();

        $this->artisan('todo:claim')
            ->expectsOutputToContain('Still open')
            ->assertSuccessful();
    }

    public function test_claims_are_scoped_to_a_section_when_asked(): void
    {
        $this->item(1, ['section' => 'High Priority', 'position' => 1, 'title' => 'Top of the list']);
        $this->item(2, ['section' => 'Ideas', 'position' => 1, 'title' => 'An idea']);

        $this->artisan('todo:claim', ['--section' => 'idea'])
            ->expectsOutputToContain('An idea')
            ->assertSuccessful();

        $this->assertNull($this->claims()->holder(1));
    }

    public function test_the_list_marks_who_holds_what_and_filters_by_it(): void
    {
        $this->item(1, ['position' => 1, 'title' => 'Taken thing']);
        $this->item(2, ['position' => 2, 'title' => 'Free thing']);

        $this->artisan('todo:claim', ['id' => '1'])->assertSuccessful();

        // `open` still means "not done" — an item disappearing from the default
        // listing the moment somebody took it would read as deleted.
        $this->artisan('todo:list')
            ->expectsOutputToContain('held by you')
            ->expectsOutputToContain('Free thing')
            ->assertSuccessful();

        $this->artisan('todo:list', ['--status' => 'available'])
            ->expectsOutputToContain('Free thing')
            ->doesntExpectOutputToContain('Taken thing')
            ->assertSuccessful();

        $this->actAsAgent('bbbb2222');

        $this->artisan('todo:list', ['--status' => 'claimed'])
            ->expectsOutputToContain('held by aaaa1111')
            ->doesntExpectOutputToContain('Free thing')
            ->assertSuccessful();
    }

    public function test_nothing_available_is_reported_rather_than_silently_succeeding(): void
    {
        $this->item(1);

        $this->artisan('todo:claim', ['id' => '1'])->assertSuccessful();

        $this->actAsAgent('bbbb2222');

        $this->artisan('todo:claim')
            ->expectsOutputToContain('every open item is claimed')
            ->assertSuccessful();
    }

    public function test_an_unknown_id_is_reported_rather_than_thrown(): void
    {
        $this->artisan('todo:claim', ['id' => '9999'])
            ->expectsOutputToContain('No TODO item with id 9999')
            ->assertFailed();
    }

    public function test_the_registry_defaults_to_one_shared_file_in_the_main_tree(): void
    {
        // Every agent has to read the same file for a claim to coordinate
        // anything, so the default path is fixed rather than per-process — and
        // resolved against the main working tree, not the running one. This
        // assertion read `storage_path()` for as long as the code did, which is
        // the same file only while nobody works in a worktree; the comment above
        // it was already the argument against that, one line up.
        $this->assertSame(Worktree::sharedPath('todo-claims.json'), (new TodoClaims)->path());
    }

    public function test_a_worktree_resolves_the_registry_to_the_main_tree(): void
    {
        // The property the line above only implies. A linked worktree's `.git`
        // is a file pointing into the main tree's `.git/worktrees/<name>`, and
        // everything before `/.git/` in it is the main tree — so an agent
        // working in one claims into the same registry as everyone else.
        $main = $this->tempPath('main-'.uniqid());
        $linked = $this->tempPath('linked-'.uniqid());

        mkdir($main.'/.git', 0755, true);
        mkdir($linked, 0755, true);
        file_put_contents($linked.'/.git', "gitdir: {$main}/.git/worktrees/agent\n");

        $this->assertSame($main, Worktree::mainPath($linked));

        // And a main tree resolves to itself, so the shared path is the same
        // string from either side — which is the whole property.
        $this->assertSame($main, Worktree::mainPath($main));

        unlink($linked.'/.git');
        rmdir($linked);
        rmdir($main.'/.git');
        rmdir($main);
    }

    public function test_a_malformed_registry_does_not_break_the_list(): void
    {
        // The file is hand-editable, and a broken entry that blew up every
        // `todo:list` would be a puzzle for whoever hit it next.
        file_put_contents($this->registry, '{"7": "not a claim", "8": {"by": "cccc3333", "at": "'.now()->toIso8601String().'"}}');

        $this->item(7);
        $this->item(8);

        $this->artisan('todo:list')->assertSuccessful();

        $this->assertNull($this->claims()->holder(7));
        $this->assertSame('cccc3333', $this->claims()->holder(8)['by']);
    }

    public function test_agent_session_falls_back_to_manual_without_a_session_id(): void
    {
        putenv('CLAUDE_CODE_SESSION_ID');

        $this->assertSame(AgentSession::MANUAL, AgentSession::key());
        $this->assertTrue(AgentSession::isSelf(AgentSession::MANUAL));
        $this->assertFalse(AgentSession::isSelf(null));
    }
}
