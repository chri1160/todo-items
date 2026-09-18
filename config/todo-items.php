<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where the items live
    |--------------------------------------------------------------------------
    |
    | One markdown file per item in `directory`, with `index` generated over
    | them. Both stay in the consuming repository: only the code is shared, so
    | that an item's whole history is `git log todo/047-*.md` in the project it
    | belongs to. Section order and per-section preamble prose are the one
    | hand-maintained part, in `_index.md` inside the directory.
    |
    */

    'directory' => base_path('todo'),

    'index' => base_path('TODO.md'),

    /*
    |--------------------------------------------------------------------------
    | Who regenerates the index
    |--------------------------------------------------------------------------
    |
    | `todo:new` and `todo:done` rewrite `index` as they go, which is what keeps
    | it honest in a checkout. It is also a generated file under version control,
    | so in a repository whose rule is one branch per item, every branch rewrites
    | the same few lines of `## Done` and every second pull request conflicts.
    |
    | `TODO.md merge=union` in `.gitattributes` settles that for git itself —
    | merge, rebase, pull — and does nothing for GitHub, whose mergeability check
    | and merge button do not apply the attribute: a branch that `git merge-tree`
    | resolves cleanly is still reported CONFLICTING on the pull request, and no
    | amount of local hygiene clears it.
    |
    | So a project can take the job off branches altogether. Set this false and
    | the commands stop writing the index; `todo:index` still writes it when
    | asked, and the default branch regenerates and commits after each merge —
    | a CI job running that one command. No branch then touches the file, so
    | there is nothing for two branches to conflict over.
    |
    | The cost, and it is real: a working copy's index is only as fresh as the
    | last merge. `todo:list` reads the item files rather than the index, so what
    | goes stale is the rendered file, not what the commands tell you. Leave this
    | true in a repository where that trade isn't worth making.
    |
    | Deliberately not an `env()` read. The setting has to hold for every clone
    | and every agent at once, and a `.env` one machine is missing puts exactly
    | that machine back to writing the file nobody else writes — which is the
    | conflict again, from the one checkout least likely to notice.
    |
    */

    'auto_index' => true,

    /*
    |--------------------------------------------------------------------------
    | Coordination registries
    |--------------------------------------------------------------------------
    |
    | The id high-water mark and the claim registry are runtime state shared by
    | every agent, so they are resolved against the *main* working tree rather
    | than the one that happens to be running — see the Worktree class for why
    | `storage_path()` is the wrong answer here. Leave these null to get that;
    | set an absolute path only to point several checkouts at one registry
    | deliberately.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Where these commands may run
    |--------------------------------------------------------------------------
    |
    | Every command writes to the repository's working tree, so there is no
    | deployed environment where running one can do anything: the tree is
    | read-only at best, and where it isn't, the edit is discarded by the next
    | deploy while the operator is told it worked. Outside these environments the
    | commands are not hidden but absent — dropped during registration, so
    | `artisan todo:done` reports an undefined command. See the TodoCommand base
    | class.
    |
    | This gate is why plain `require` is the right install rather than
    | `require-dev`: the package also ships a small library an application may
    | read at runtime, and both known consumers render a page over the items.
    |
    */

    'environments' => ['local', 'testing'],

    'ids' => null,

    'claims' => null,

];
