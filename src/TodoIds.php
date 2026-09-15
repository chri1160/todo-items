<?php

namespace Timot\TodoItems;

use Timot\TodoItems\Support\Worktree;

/**
 * The highest TODO id ever allocated, across every branch.
 *
 * {@see TodoRepository::allocateId()} was `max(id in todo/) + 1`, read from the
 * working tree, so it cannot see an id allocated on a branch this checkout does
 * not hold. Two agents both read the highest as 150, both allocate 151, and
 * neither finds out until the second pull request merges. That is not a corner
 * case: it happened three times in one project before the registry existed, twice
 * needing a pull request whose only purpose was to renumber an item, and once
 * caught by hand because the colliding item sat unmerged on its own branch where
 * nothing could see it.
 *
 * So the high-water mark is runtime coordination state, exactly like
 * {@see TodoClaims}: one JSON file in the *main* tree's storage, which every
 * worktree reaches through the same bind mount.
 *
 * Two things differ from a claim, and both are load-bearing.
 *
 * **The mark never expires.** A claim goes stale after 8 hours because an agent
 * killed mid-item cannot release anything, and an item nobody can reclaim is
 * worse than an unclaimed one. Here the opposite holds: an expiring mark would
 * hand out an id that has already been used, which is the bug it exists to
 * prevent. Do not add a TTL to this file by analogy with that one.
 *
 * **The mark only ever raises a floor; it is never the answer on its own.** The
 * file is gitignored, so a fresh clone, a cleared storage directory or a second
 * machine has no mark at all — and an allocator trusting it alone would restart
 * low and reissue retired ids. That is worse than a collision: a collision is
 * caught and renumbered by a pull request that exists for nothing else, whereas a
 * reissued id silently repoints every existing citation ("TODO #047") at a
 * different item, destroying the one property ids exist to guarantee. So the
 * working tree is always the floor, and the stored mark only lifts it —
 * recording ids allocated on branches this checkout cannot see.
 *
 * The same bug was hit independently in two projects running the same allocator,
 * which is half of why this tooling is a package. The other half is {@see Worktree}:
 * each of them resolved the main tree from its own worktree layout, so the copy
 * that fixed one was silently wrong in the other.
 */
class TodoIds
{
    public function __construct(private ?string $path = null) {}

    /**
     * The registry lives in the main tree's storage, never the worktree's — a
     * mark only coordinates if every agent reads the same file.
     * {@see Worktree::sharedPath()} for why that is resolved from git's pointer file.
     */
    public function path(): string
    {
        return $this->path ?? Worktree::sharedPath('todo-ids.json');
    }

    /** The highest id this registry has recorded, or 0 when it has none. */
    public function mark(): int
    {
        if (! is_file($this->path())) {
            return 0;
        }

        $decoded = json_decode((string) file_get_contents($this->path()), true);

        // Guard the shape rather than trusting the file: it is hand-editable,
        // and a malformed mark here would blow up every `todo:new` until
        // someone worked out why. Falling back to 0 is safe because the
        // caller's tree floor still bounds the answer.
        return is_array($decoded) && is_int($decoded['highest'] ?? null)
            ? max(0, $decoded['highest'])
            : 0;
    }

    /**
     * Take the next id, guaranteed to be above both $floor and the stored mark.
     *
     * $floor is the highest id present in the caller's working tree. Passing it
     * on every call is what keeps this registry advisory: lose the file and the
     * tree still bounds the answer correctly for this branch.
     *
     * Read-modify-write under a lock, because two agents running `todo:new` in
     * the same second is the case this exists for, and losing one of the two
     * writes would hand both of them the same id. An id is recorded as taken
     * the moment it is handed out, so a command that fails afterwards burns it.
     * That is the intended trade: ids are free and burning one is invisible,
     * where reusing one is the failure this whole class is about.
     */
    public function allocate(int $floor): int
    {
        $directory = dirname($this->path());

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $handle = fopen($this->path().'.lock', 'c');

        if ($handle === false) {
            // No lock means no safe write. The tree's own floor is still correct
            // for this branch, so allocate from it rather than refusing to work.
            return $floor + 1;
        }

        try {
            flock($handle, LOCK_EX);

            $next = max($floor, $this->mark()) + 1;

            file_put_contents(
                $this->path(),
                json_encode(['highest' => $next], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
            );

            return $next;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
