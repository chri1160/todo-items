<?php

namespace Timot\TodoItems\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Timot\TodoItems\Support\Worktree;

/**
 * Finding the main tree from inside a linked one.
 *
 * This is what stops every runtime registry — `todo-ids.json`, `todo-claims.json` —
 * from becoming per-worktree the moment agents stop sharing one checkout. The failure
 * it prevents is silent in both directions: resolve to a worktree's own storage and
 * each agent reads a file that is real, well formed and entirely its own; resolve to a
 * host path a container cannot see and each agent reads nothing at all, which looks
 * identical to an empty registry. Ids collide and claims coordinate nothing while every
 * command reports success.
 *
 * Climbing to the nearest ancestor with a `.git` directory is what holds in both views,
 * because it only ever takes relative steps. The pointer file is the fallback, for a
 * worktree that is not nested under its main tree.
 *
 * Plain PHPUnit rather than the package's Testbench case: every path here is passed in,
 * so there is no application to boot.
 */
class WorktreeTest extends PHPUnitTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/worktree-'.uniqid();
        mkdir($this->root.'/main/.git/worktrees/wt', 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));

        parent::tearDown();
    }

    public function test_a_main_tree_is_its_own_main_tree(): void
    {
        // A main tree's `.git` is a directory, which is the whole of the test.
        $this->assertSame($this->root.'/main', Worktree::mainPath($this->root.'/main'));
    }

    public function test_a_worktree_beside_the_main_tree_falls_back_to_the_pointer(): void
    {
        // A sibling layout: nothing to climb to, so the pointer is all there is — and
        // it is correct on the host, which is the only place such a tree is visible.
        $worktree = $this->linkedWorktree($this->root.'/main/.git/worktrees/wt');

        $this->assertSame($this->root.'/main', Worktree::mainPath($worktree));
    }

    public function test_a_worktree_nested_inside_the_main_tree_resolves_the_same_way(): void
    {
        $worktree = $this->linkedWorktree(
            $this->root.'/main/.git/worktrees/wt',
            $this->root.'/main/.claude/worktrees/nested'
        );

        $this->assertSame($this->root.'/main', Worktree::mainPath($worktree));
    }

    public function test_a_nested_worktree_resolves_without_trusting_the_pointer(): void
    {
        // The case that matters, and the one that broke. Where commands run in a
        // container the checkout is mounted at a path like /var/www/html, so the host
        // path the pointer was written with names nothing at all, and a registry
        // resolved from it reads empty rather than failing. Climbing uses only relative
        // steps, so it holds in either view.
        $worktree = $this->linkedWorktree(
            '/var/www/html/.git/worktrees/wt',
            $this->root.'/main/.claude/worktrees/nested'
        );

        $this->assertSame($this->root.'/main', Worktree::mainPath($worktree));
    }

    public function test_an_unreadable_pointer_falls_back_to_the_tree_it_was_given(): void
    {
        // Wrong, but local: the caller gets its own tree's registry rather than an
        // exception out of a path lookup. Every alternative is worse than degrading.
        $worktree = $this->root.'/broken';
        mkdir($worktree);
        file_put_contents($worktree.'/.git', "not a gitdir pointer\n");

        $this->assertSame($worktree, Worktree::mainPath($worktree));
    }

    public function test_the_shared_path_hangs_off_whatever_the_main_tree_resolved_to(): void
    {
        // sharedPath() is the only caller the registries use, so a correct mainPath()
        // with a wrong join would still hand every worktree its own file.
        $main = $this->root.'/main';

        $this->assertSame(
            $main.'/storage/framework/todo-claims.json',
            rtrim(Worktree::mainPath($main), '/').'/storage/framework/todo-claims.json',
        );
    }

    /** A directory whose `.git` is a file pointing at the main tree's bookkeeping. */
    private function linkedWorktree(string $gitDir, ?string $path = null): string
    {
        $path ??= $this->root.'/wt';

        if (! is_dir($path)) {
            mkdir($path, 0o777, true);
        }

        file_put_contents($path.'/.git', 'gitdir: '.$gitDir."\n");

        return $path;
    }
}
