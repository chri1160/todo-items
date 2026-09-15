<?php

namespace Timot\TodoItems;

use Carbon\CarbonImmutable;
use Timot\TodoItems\Support\AgentSession;
use Timot\TodoItems\Support\Worktree;

/**
 * Who is working on which TODO item, right now.
 *
 * The problem is assignment: several agents pull from one list, and without a
 * claim two of them start #047 within a minute of each other and neither finds
 * out until the second one opens a file the first has already rewritten.
 *
 * The obvious home for a claim is the item's own frontmatter, and it is the
 * wrong one. A claim is true for an afternoon, so writing it there puts a
 * commit's worth of churn on a prose file for something that isn't part of the
 * item — and the moment an agent works on a branch, a claim committed to
 * `todo/047-*.md` is invisible to everyone else until it merges, which is
 * precisely the failure the claim exists to prevent, arriving silently.
 *
 * So a claim is runtime coordination state, not part of the item: one JSON
 * registry under `storage/framework/`, keyed on {@see AgentSession} rather than
 * a pid, because an agent outlives every process it spawns.
 *
 * Claims expire. An agent that is killed mid-item cannot release anything, and
 * an item nobody can reclaim is worse than an unclaimed one, because it stops
 * being offered to anyone and no one is working on it. There is no liveness
 * signal available here (a session is not a running process), so age is the
 * only one there is.
 */
class TodoClaims
{
    /** After this long, a claim is presumed abandoned and the item returns to the pool. */
    public const STALE_HOURS = 8;

    public function __construct(private ?string $path = null) {}

    /**
     * The registry lives in the main tree's storage, never the worktree's.
     * {@see Worktree::sharedPath()} for why that is resolved from git's pointer
     * file rather than from `storage_path()`, which resolves inside whichever
     * tree is running — so under worktrees each agent held claims only it could
     * see, which is the failure a claim exists to prevent, arriving silently.
     */
    public function path(): string
    {
        return $this->path ?? Worktree::sharedPath('todo-claims.json');
    }

    /**
     * Every live claim, id => claim. Stale entries are filtered on read so a
     * dead agent's hold never has to be cleaned up by hand.
     *
     * @return array<int, array{by: string, at: string}>
     */
    public function all(): array
    {
        $claims = [];

        foreach ($this->read() as $id => $claim) {
            if (! $this->isStale($claim)) {
                $claims[(int) $id] = $claim;
            }
        }

        return $claims;
    }

    /** @return array{by: string, at: string}|null */
    public function holder(int $id): ?array
    {
        return $this->all()[$id] ?? null;
    }

    public function heldByAnother(int $id): bool
    {
        $holder = $this->holder($id);

        return $holder !== null && ! AgentSession::isSelf($holder['by']);
    }

    /**
     * Take an item. Returns the holder that blocked it, or null on success.
     *
     * Read-modify-write under a lock: two agents running `todo:claim` in the
     * same second is the exact case this exists for, so losing one of the two
     * writes would hand both of them the same item.
     *
     * @return array{by: string, at: string}|null
     */
    public function claim(int $id, ?string $by = null, bool $force = false): ?array
    {
        $by ??= AgentSession::key();
        $blocked = null;

        $this->mutate(function (array $claims) use ($id, $by, $force, &$blocked) {
            $existing = $claims[$id] ?? null;

            if ($existing !== null && ! $this->isStale($existing) && $existing['by'] !== $by && ! $force) {
                $blocked = $existing;

                return $claims;
            }

            $claims[$id] = ['by' => $by, 'at' => CarbonImmutable::now()->toIso8601String()];

            return $claims;
        });

        return $blocked;
    }

    /** Hand an item back. Returns true if a claim was actually removed. */
    public function release(int $id): bool
    {
        $removed = false;

        $this->mutate(function (array $claims) use ($id, &$removed) {
            if (isset($claims[$id])) {
                unset($claims[$id]);
                $removed = true;
            }

            return $claims;
        });

        return $removed;
    }

    /**
     * Release every item this agent holds — the tidy-up at the end of a session.
     *
     * @return list<int> the ids released
     */
    public function releaseAllFor(?string $by = null): array
    {
        $by ??= AgentSession::key();
        $released = [];

        $this->mutate(function (array $claims) use ($by, &$released) {
            foreach ($claims as $id => $claim) {
                if ($claim['by'] === $by) {
                    $released[] = (int) $id;
                    unset($claims[$id]);
                }
            }

            return $claims;
        });

        return $released;
    }

    /** Whole hours a claim has been held, for display. */
    public function ageInHours(array $claim): int
    {
        return (int) CarbonImmutable::parse($claim['at'])->diffInHours(CarbonImmutable::now());
    }

    public function isStale(array $claim): bool
    {
        return $this->ageInHours($claim) >= self::STALE_HOURS;
    }

    /** @return array<int, array{by: string, at: string}> */
    private function read(): array
    {
        if (! is_file($this->path())) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->path()), true);

        if (! is_array($decoded)) {
            return [];
        }

        // Guard the shape rather than trusting the file: it is hand-editable,
        // and a malformed entry here would blow up every `todo:list` until
        // someone worked out why.
        return array_filter(
            $decoded,
            fn ($claim) => is_array($claim) && isset($claim['by'], $claim['at']),
        );
    }

    /**
     * @param  callable(array<int, array{by: string, at: string}>): array<int, array{by: string, at: string}>  $mutator
     */
    private function mutate(callable $mutator): void
    {
        $directory = dirname($this->path());

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $handle = fopen($this->path().'.lock', 'c');

        if ($handle === false) {
            return;
        }

        try {
            flock($handle, LOCK_EX);

            $claims = $mutator($this->read());

            // Stale entries are dropped whenever the file is rewritten, so the
            // registry can't accumulate the residue of dead sessions.
            $claims = array_filter($claims, fn (array $claim) => ! $this->isStale($claim));

            ksort($claims);

            file_put_contents($this->path(), json_encode($claims, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
