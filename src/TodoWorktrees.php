<?php

namespace Timot\TodoItems;

use Timot\TodoItems\Support\Worktree;

/**
 * Which items are being worked on right now, read from the worktrees themselves.
 *
 * {@see TodoClaims} covers the minutes between `todo:claim` and the work actually
 * starting, and it cannot cover more than that. A claim is stamped once and nothing
 * refreshes it, so at `STALE_HOURS` the item returns to the pool whether or not anyone
 * is still on it — and that expiry is not a tunable mistake. It is what a registry with
 * no liveness signal has to do: an agent that is killed mid-item cannot release
 * anything, so age is the only evidence there is. Raising the number trades the gap for
 * a ghost — the item reads `held by a3f9c1d2 (26h)` by a session that ended yesterday,
 * blocking it for days instead of surfacing it. The window is short *because* the
 * evidence is weak.
 *
 * There is stronger evidence, and it isn't in the registry. Every agent works in a git
 * worktree under `.claude/worktrees/<name>`: made when the work starts, recorded in the
 * **main** tree's `.git` so every tree and every session sees the same set, and gone
 * when the branch lands. That is exactly what a session key is not — shared rather than
 * per-session, and self-clearing rather than expiring. So an item with a worktree
 * carrying its id is in progress, with no expiry at all, and the registry keeps the job
 * it is good at.
 *
 * **Read off the filesystem, never `git worktree list`.** The bookkeeping is
 * `<mainPath>/.git/worktrees/<name>/`, one directory per linked worktree, each with a
 * `HEAD` holding `ref: refs/heads/<branch>`. Both halves of what we need — the
 * directory name and the branch — are there in relative terms, which is the same
 * property {@see Worktree::mainPath()} is built on and for the same reason: that
 * class's docblock explains at length that the absolute paths git records are the
 * host's, so under a container bind mount they name nothing and every read comes back
 * empty while every command reports success. `git worktree list` prints those same
 * absolute paths, and adds a binary and an allowlisted subprocess to the cost. The
 * directory listing has neither problem.
 *
 * **Matching is liberal because the package cannot dictate the name.** Worktrees are
 * made by the agent harness from a name the agent picks, so all this can do is look for
 * the id: any run of digits in the directory name or the branch, `#` and leading zeros
 * and all, so `todo-141-fix`, `#141-fix`, `0141` and `worktree-todo-141` all name item
 * #141. Which leaves the two ways to be wrong, and they are not symmetric. A miss —
 * `has-interacted`, a real worktree here holding item #141 — is today's behaviour
 * exactly: the item is offered while somebody works it, which is the bug this is
 * chipping away at rather than one it adds. A false hit — `php-8-upgrade` reading as
 * item #8 — withholds an item, but never silently: `todo:list` prints the worktree's
 * own name beside it, so the wrong answer arrives with the reason attached. Hence
 * {@see suggestedName()}, printed by `todo:claim`: it closes the loop on the misses
 * without the package depending on a convention it has no way to enforce.
 */
class TodoWorktrees
{
    /** Where the harness puts checkouts. Used for the suggestion; nothing is read from it. */
    public const DIRECTORY = '.claude/worktrees';

    public function __construct(private ?string $mainPath = null) {}

    /**
     * The tree whose `.git` holds the bookkeeping — the main one, from wherever we run.
     */
    public function mainPath(): string
    {
        return $this->mainPath ?? Worktree::mainPath();
    }

    /**
     * Every linked worktree, directory name => branch (null when detached).
     *
     * @return array<string, string|null>
     */
    public function all(): array
    {
        $worktrees = [];

        foreach (glob($this->mainPath().'/.git/worktrees/*', GLOB_ONLYDIR) ?: [] as $path) {
            $worktrees[basename($path)] = $this->branchIn($path);
        }

        return $worktrees;
    }

    /**
     * Items a worktree is working on, id => the worktree naming it.
     *
     * Ids are read out of both names, so a worktree matches on either. The first
     * worktree to name an id keeps it, which only matters for display: two worktrees on
     * one item is a collision to report, not a tie to resolve.
     *
     * @return array<int, string>
     */
    public function inProgress(): array
    {
        $ids = [];

        foreach ($this->all() as $name => $branch) {
            foreach ($this->idsIn($name.' '.$branch) as $id) {
                $ids[$id] ??= $name;
            }
        }

        ksort($ids);

        return $ids;
    }

    /** The worktree working on this item, or null. */
    public function nameFor(int $id): ?string
    {
        return $this->inProgress()[$id] ?? null;
    }

    public function has(int $id): bool
    {
        return $this->nameFor($id) !== null;
    }

    /**
     * A worktree name that this class will match back to the item.
     *
     * Zero-padded like the item's own filename, so a directory listing of the
     * worktrees sorts and reads the way the list does.
     */
    public static function suggestedName(TodoItem $item): string
    {
        return sprintf('%03d-%s', $item->id, $item->slug);
    }

    /**
     * The branch a worktree has checked out, from its own `HEAD`.
     *
     * A detached HEAD holds a sha instead, which names no item — the directory name is
     * then the only evidence, and it is still read.
     */
    private function branchIn(string $path): ?string
    {
        $head = $path.'/HEAD';

        if (! is_file($head)) {
            return null;
        }

        return preg_match('#^ref:\s*refs/heads/(.+)$#m', (string) file_get_contents($head), $matches)
            ? trim($matches[1])
            : null;
    }

    /**
     * Every id a name could be carrying: each run of digits, leading zeros dropped.
     *
     * @return list<int>
     */
    private function idsIn(string $name): array
    {
        preg_match_all('/\d+/', $name, $matches);

        return array_map(intval(...), $matches[0]);
    }
}
