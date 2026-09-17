# timot/todo-items

A TODO list for a repository worked by several agents at once.

One markdown file per item under `todo/`, `TODO.md` generated over them, ids that never
collide across branches and never get reused, and claims — backed by the worktrees agents
actually work in — so two agents don't start the same thing. Five `todo:*` artisan commands
over it.

```
composer require timot/todo-items
```

Then create `todo/_index.md` with the sections you want, in priority order:

```markdown
## High Priority

## Ideas

Anything not yet worth prioritising.
```

That file and the items are all that lives in the consuming repository. Everything else is here.

## Commands

| | |
|---|---|
| `todo:new "<title>" --section="Ideas"` | Allocate an id, scaffold `todo/NNN-slug.md`, regenerate the index |
| `todo:list --section=High --status=open --limit=15 --body` | Read the list. `--status` is `open`, `claimed`, `available`, `done` or `all` |
| `todo:claim [id] [--release] [--force] [--section=]` | Take the highest-priority item nobody holds and no worktree is on, or a named one |
| `todo:done 47 [--date=] [--reopen]` | Flip status, stamp the date, release the claim, regenerate |
| `todo:index [--dry-run]` | Regenerate `TODO.md`, adopting any hand-written bullet |

## Why files

A dev database gets rebuilt as a matter of routine, so a `todos` table is destroyed by the
normal workflow — and it would put the list behind a running database when the point is that
an agent with a checkout and nothing else can read and write it. Items are amended prose,
whose evolution `git log todo/047-*.md` records better than an `updated_at` column. One file
per item is also what lets two agents edit two items without colliding on one enormous file,
and what makes closing an item a frontmatter flip rather than cutting a 2KB block out of one
section and pasting it into another.

## Three things that are less obvious than they look

**An id is a citable handle, so it is never reused.** `#047` is what a commit message, a doc
or a conversation cites; "the promotion-cutoff item" is a description that drifts every time
the item is reworded. Reuse is worse than collision — a collision is caught and renumbered,
where a reissued id silently repoints every existing citation at a different item. So the
allocator reads the working tree as a floor and a shared high-water mark only ever *raises*
it: lose the mark and the tree still bounds the answer correctly, which is what keeps it
advisory rather than authoritative. It exists because `max(id) + 1` cannot see an id
allocated on a branch this checkout does not hold, which handed out the same id three times
in one project before anyone noticed.

**A claim is runtime state, not part of the item.** Written into the item's frontmatter it
would put a commit's worth of churn on a prose file for something true for an afternoon —
and the moment an agent works on a branch, a claim committed to `todo/047-*.md` is invisible
to everyone else until it merges, which is exactly the failure a claim exists to prevent,
arriving silently. So claims live in one JSON registry, keyed on the agent session rather
than a pid, and they expire after 8 hours because an agent that is killed mid-item cannot
release anything. Picking and taking are deliberately one command: reading the list and then
claiming what you saw is the same race one step removed.

**A claim covers the minutes; the worktree covers the work.** Nothing refreshes a claim's
timestamp, so an item worked across two days outlives its claim by construction: at eight
hours it drops out of the registry and is handed to the next agent that asks, with nothing
anywhere to say it was ever held. Raising the eight is not the fix, because it trades that
gap for a ghost — the key names a *session*, so the same agent resuming tomorrow is a
different holder, and a longer window leaves items reading `held by a3f9c1d2 (26h)` by
somebody who no longer exists, blocked for days instead of surfaced. The window is short
because the evidence is weak.

So the durable half is read from somewhere else. Agents work in git worktrees under
`.claude/worktrees/<name>`, and a worktree is everything a session key is not: recorded in
the **main** tree's `.git`, so every tree and every session sees the same set, and gone the
moment the branch lands. An item whose id appears in a live worktree's branch or directory
name is in progress — marked as such in `todo:list`, skipped by an id-less `todo:claim`, and
with no expiry at all. `TodoWorktrees` reads `.git/worktrees/*/HEAD` directly rather than
shelling out to `git worktree list`, which prints the host's absolute paths and so reports
nothing usable from inside a container — the same trap `Worktree` exists to avoid, plus a
binary and a subprocess.

Matching is deliberately liberal, because the package cannot dictate the name: worktrees are
created by the agent harness from a name the agent picks, so any run of digits in either name
counts, `#` and leading zeros and all. Being wrong is not symmetric. A miss is exactly
today's behaviour — the item is offered while somebody works it — while a false hit withholds
an item but never silently, because `todo:list` prints the worktree's own name beside it. And
a project whose worktrees carry no ids, which is where both consumers started, sees no change
whatsoever: no id in a name means nothing is read, and claims behave as they always have.
That is also why `todo:claim` prints a suggested worktree name containing the id — it closes
the loop without the package depending on a convention it has no way to enforce.

**A generated file that discards edits is a data-loss trap.** So `todo:index` adopts: any
bullet in `TODO.md` that isn't one of the generator's own rows becomes a real item file
before the file is overwritten. Appending a bullet by hand stays a valid way to add an item,
for a person in a hurry and for an agent whose context predates this tooling.

Both coordination registries resolve against the **main** working tree rather than the one
that happens to be running — `storage_path()` names the current worktree's storage, so under
`git worktree` each agent would coordinate only with itself. See `Worktree`, which finds it by
climbing to the nearest ancestor whose `.git` is a directory. Only relative steps, which is
what makes it hold in a container: the paths git records in its own pointer file are the
host's, and under a bind mount they name nothing — so the registry reads back empty rather
than failing, and every agent believes every item is free while every command reports success.
The pointer is kept as a fallback for a worktree that is not nested under its main tree.

**The commands are absent outside development, not hidden.** Every command writes to the
repository's working tree, so there is no deployed environment where running one can do
anything: the tree is read-only at best, and where it isn't, the edit is discarded by the next
deploy while the operator is told it worked. The failure that matters isn't a crash — it's
`todo:done` reporting success in production and the item silently un-closing itself on the
next deploy. So the gate is Symfony's `isEnabled()`, which drops a command during
*registration*: `artisan todo:done` in production reports an undefined command rather than
running. Hiding it would leave it runnable by anyone who types the name. The environments are
`local` and `testing` by default (`testing` because a consuming project's suite drives these
against a temp tree) and configurable via `todo-items.environments`.

**Install under `require`, not `require-dev`** — the gate above is what makes that safe. This
package is two things: five commands, which are development-only, and a small library
(`TodoRepository`, `TodoItem`, `TodoClaims`, `TodoWorktrees`, `TodoIds`, `AgentSession`) that an application may
legitimately read at runtime. A page listing your own items and who holds what is an obvious
thing to build, and both consumers of this package built one — discovered by Filament in
production, which fatals if the classes aren't installed. `require-dev` is a tightening
available only when the commands are the sole consumer; it is actively wrong the moment
anything in the app reads the items.

## The admin page (optional)

`Timot\TodoItems\Filament\TodoListPage` is a read-only Filament page over the list — search,
filter by section and status, and read an item's body without leaving the browser. It is
**suggested, not required**: `filament/filament` appears in `suggest`, so a command-only
consumer's dependency graph is unchanged.

**Subclass it; the package does not register it.** That is deliberate, and it is what keeps the
`require-dev` install available:

```php
namespace App\Filament\Admin\Dev;          // a directory discovered only in development

use Timot\TodoItems\Filament\TodoListPage;

class Todos extends TodoListPage
{
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }
}
```

Then discover that directory only where the package exists:

```php
->when(
    app()->environment(['local', 'testing']),
    fn (Panel $panel) => $panel->discoverPages(
        in: app_path('Filament/Admin/Dev'),
        for: 'App\Filament\Admin\Dev',
    ),
)
```

**Discovery is what must be conditional, not access.** `canAccess()` gates access while discovery
still *loads* the class, and loading is exactly what fails when the package is absent from a
production install — a gate that runs after the fatal is not a gate. Note also that
`discoverPages()` is typed `string $in`, so the obvious "pass null in production" is a TypeError
rather than a no-op; `Panel::when()` is the seam.

The subclass also owns its placement in the panel. The base sets a label, a title and
`slug = 'todos'` — the slug is fixed rather than derived, because Filament would otherwise
slugify the subclass name and a project that called its subclass anything but `Todos` would
silently get a different URL. It sets **no** navigation group: group names mean nothing outside
the panel that defines them, so that belongs in the subclass.

```php
protected static string|UnitEnum|null $navigationGroup = 'Other Tools';
protected static ?int $navigationSort = 100;
```

Two hooks:

- **`canAccess()`** has no sensible default, so the base returns **false**. Forget it and the page
  simply does not appear — noticed at once in development, harmless if not. The opposite default
  fails silently and in the dangerous direction. (Abstract would be better; PHP forbids
  re-declaring an inherited concrete method as abstract.)
- **`icons()`** returns five roles — `page`, `available`, `claimed`, `done`, `view` — defaulting to
  Heroicons. Override it to map them onto a project's own icon registry, or spread the defaults to
  change just one:

  ```php
  protected static function icons(): array
  {
      return [...parent::icons(), 'view' => Heroicon::OutlinedMagnifyingGlass];
  }
  ```

Views publish with `--tag=todo-items-views` if a project wants to change the markup.

## Configuration

Defaults to `todo/` and `TODO.md` at the project root, with registries under the main tree's
`storage/framework/`. Publish to change any of it:

```
php artisan vendor:publish --tag=todo-items-config
```

## Tests

```
composer install
vendor/bin/phpunit
```

Filament is a dev dependency here so the page's tests can run; those tests skip if it is absent.
Everything resolves from Packagist, so a clone needs no credentials.

The gating tests assert *membership* as well as behaviour: a sixth command that lands in
`src/Commands` without extending `TodoCommand` fails the suite, because nothing about adding a
file to a directory announces that the directory has a contract.

The suite works entirely on temp trees — these classes write and delete files, and a test
that reached the real list would rewrite the items it was run to protect.
