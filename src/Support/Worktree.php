<?php

namespace Timot\TodoItems\Support;

use Timot\TodoItems\TodoIds;

/**
 * Where the main working tree is, seen from any of them.
 *
 * Runtime coordination state — {@see TodoIds}, and the claims
 * registry — only coordinates if every agent reads the same file. `storage_path()`
 * resolves inside whichever tree is running, so in a worktree it names that
 * worktree's own storage and the shared registry quietly stops being shared.
 *
 * Found by **climbing**, and the reason is the container. A linked worktree's `.git`
 * is a file holding `gitdir: /abs/path/.git/worktrees/<name>`, and that path is
 * absolute *as the host saw it when the worktree was made*. Where commands run in a
 * container the checkout is bind-mounted — `/var/www/html` for Laravel Sail — and the
 * host's `/home/…` does not exist there, so the pointer names nothing: reads come back
 * empty and the first write throws out of a path lookup.
 *
 * Climbing works in both views because it only ever uses relative steps. A main tree's
 * `.git` is a directory and a linked worktree's is a file, so the first ancestor with a
 * `.git` **directory** is the tree that owns this one, wherever the mount point is and
 * with no agreement between host and container about absolute paths.
 *
 * What climbing needs is for worktrees to sit *under* the main tree — the
 * `.claude/worktrees/<name>` layout — which is also the only one a single bind mount
 * can see. The pointer is kept as a fallback for siblings, which are still correct on
 * the host, so this is a strict superset of reading the pointer alone.
 *
 * **The silent half is why this is worth the docblock.** A pointer that resolves to
 * nothing does not announce itself on read: `TodoClaims` sees no file and reports no
 * claims, `TodoIds` sees no file and reports a high-water mark of zero. Every agent
 * then believes every item is free and allocates ids from its own branch — which is
 * both of the failures this package exists to prevent, arriving together, with every
 * command still reporting success. Measured in a real container worktree: the shared
 * registry held a high-water mark in the hundreds and the worktree read 0.
 *
 * This is the exact inverse of the reasoning the class was written with. The pointer
 * was chosen *over* a path convention because one project nested its worktrees and the
 * other did not, so a convention correct in one was silently wrong in the other. Both
 * nest now, deliberately — and the pointer turns out to be the half that breaks.
 */
class Worktree
{
    /**
     * The main tree's path, or this one's when it is already the main tree.
     */
    public static function mainPath(?string $path = null): string
    {
        $path ??= base_path();

        return self::byClimbing($path) ?? self::byPointer($path) ?? $path;
    }

    /**
     * A coordination file every agent reaches, whichever tree it is running in.
     *
     * The counterpart to `storage_path()`, and the reason to reach for this instead:
     * every caller here is coordinating between agents, so resolving inside the running
     * tree is always the bug rather than sometimes one.
     */
    public static function sharedPath(string $file): string
    {
        return self::mainPath().'/storage/framework/'.$file;
    }

    /**
     * The nearest ancestor whose `.git` is a directory, starting with this one.
     *
     * Capped at the filesystem root by the parent check rather than a depth count: a
     * relative walk that reaches `/` has nowhere else to go.
     */
    private static function byClimbing(string $path): ?string
    {
        $current = $path;

        while (true) {
            if (is_dir($current.'/.git')) {
                return $current;
            }

            $parent = dirname($current);

            if ($parent === $current) {
                return null;
            }

            $current = $parent;
        }
    }

    /**
     * The tree named by a linked worktree's `.git` pointer file.
     *
     * Only reached for a worktree that is not nested under its main tree, and only
     * correct in the view that wrote it — see the class docblock.
     */
    private static function byPointer(string $path): ?string
    {
        $pointer = $path.'/.git';

        if (! is_file($pointer)) {
            return null;
        }

        if (! preg_match('/^gitdir:\s*(.+)$/m', (string) file_get_contents($pointer), $matches)) {
            return null;
        }

        $gitDir = trim($matches[1]);
        $position = strpos($gitDir, '/.git/');

        return $position === false ? null : substr($gitDir, 0, $position);
    }
}
