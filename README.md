# timot/todo-items

A TODO list for a repository worked by several agents at once.

One markdown file per item under `todo/`, `TODO.md` generated over them, ids that never
collide across branches and never get reused, and claims so two agents don't start the same
thing. Five `todo:*` artisan commands over it.

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
| `todo:claim [id] [--release] [--force] [--section=]` | Take the highest-priority unheld item, or a named one |
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

**A generated file that discards edits is a data-loss trap.** So `todo:index` adopts: any
bullet in `TODO.md` that isn't one of the generator's own rows becomes a real item file
before the file is overwritten. Appending a bullet by hand stays a valid way to add an item,
for a person in a hurry and for an agent whose context predates this tooling.

Both coordination registries resolve against the **main** working tree rather than the one
that happens to be running — `storage_path()` names the current worktree's storage, so under
`git worktree` each agent would coordinate only with itself. See `Worktree`, which reads
git's own pointer file rather than matching a path convention, because the two projects this
grew in lay their worktrees out differently and a convention correct in one is silently wrong
in the other.

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
(`TodoRepository`, `TodoItem`, `TodoClaims`, `TodoIds`, `AgentSession`) that an application may
legitimately read at runtime. A page listing your own items and who holds what is an obvious
thing to build, and both consumers of this package built one — discovered by Filament in
production, which fatals if the classes aren't installed. `require-dev` is a tightening
available only when the commands are the sole consumer; it is actively wrong the moment
anything in the app reads the items.

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

The gating tests assert *membership* as well as behaviour: a sixth command that lands in
`src/Commands` without extending `TodoCommand` fails the suite, because nothing about adding a
file to a directory announces that the directory has a contract.

The suite works entirely on temp trees — these classes write and delete files, and a test
that reached the real list would rewrite the items it was run to protect.
