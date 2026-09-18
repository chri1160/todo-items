<?php

namespace Timot\TodoItems\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Application;
use Timot\TodoItems\TodoRepository;

/**
 * Base for every `todo:*` command. Development-only, structurally.
 *
 * The list is a set of files in the consuming repository — the item files, the
 * generated index, and the registries under the main tree's `storage/`. Every
 * command here writes to them, which means there is no environment other than a
 * developer's checkout where running one can *do* anything: a deployed
 * artifact's tree is read-only at best, and where it isn't, the edit lives until
 * the next deploy replaces the image and is then gone. The failure mode that
 * matters is not a crash — it is `todo:done` reporting success in production and
 * the item silently un-closing itself on the next deploy.
 *
 * So the commands are not hidden, they are **absent**. `isEnabled()` is
 * Symfony's own hook for "this command cannot run in this environment", and a
 * command returning false from it is dropped during registration rather than
 * merely filtered out of `artisan list`
 * ({@see Application::add()}) — `artisan todo:done`
 * in production reports an undefined command. That is the difference between
 * making a dangerous state unrepresentable and merely guarding it: a hidden
 * command still runs for anyone who types its name.
 *
 * `testing` is in the default list because a consuming project's suite drives
 * these through `$this->artisan('todo:claim')` against a temp tree, never the
 * real list. Override `todo-items.environments` if a project genuinely needs a
 * different set; the default is the conservative one.
 *
 * This gate is what makes plain `require` safe, and plain `require` is the
 * right install: the package is five development-only commands *and* a small
 * library an application may legitimately read at runtime. Both known consumers
 * render a Filament page over the items, discovered in production — under
 * `require-dev` that fatals on a missing class. So the commands are gated here
 * rather than the whole package being kept out of production.
 */
abstract class TodoCommand extends Command
{
    /** Environments where the repository's working tree is the real one. */
    public const DEFAULT_ENVIRONMENTS = ['local', 'testing'];

    /**
     * Rewrite the index after a command changed an item — unless the project has
     * taken that job off its branches.
     *
     * This write is *incidental*: the command was asked to create or close an
     * item, and the index follows because a generated file that lags its source
     * is worse than no file. That is also exactly why it is the write worth
     * making optional. The item file a branch touches is its own; the index is
     * the one file every branch rewrites, so it is the only place two unrelated
     * branches can collide, and they collide on `## Done` because that is where
     * every close inserts a row. Dropping the incidental write leaves branches
     * with nothing in common ({@see config/todo-items.php}).
     *
     * `todo:index` is untouched by the flag and calls the repository directly.
     * A command named for the index that declined to write one would be the
     * wrong kind of obedience, and it is what the default branch runs after a
     * merge to put the file back in step.
     *
     * The skip announces itself. The item file is already written by the time
     * this runs, so a silent no-op reads as the close not having taken.
     */
    protected function regenerateIndex(TodoRepository $repository): void
    {
        $app = $this->getLaravel();

        if ($app === null || $app->make('config')->get('todo-items.auto_index', true)) {
            $repository->writeIndex();

            return;
        }

        $this->line(
            '  <fg=gray>Left '.basename($repository->indexPath()).' alone — `todo-items.auto_index` is off, '
            .'so only the default branch regenerates it. `todo:index` rebuilds it here if you need to read it.</>',
        );
    }

    public function isEnabled(): bool
    {
        $app = $this->getLaravel();

        // Outside a Laravel application there is no environment to gate on, and
        // nothing has registered the command anyway.
        if ($app === null) {
            return true;
        }

        $environments = $app->make('config')->get('todo-items.environments', self::DEFAULT_ENVIRONMENTS);

        return $app->environment((array) $environments);
    }
}
