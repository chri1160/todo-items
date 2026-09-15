<?php

namespace Timot\TodoItems\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Application;

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
