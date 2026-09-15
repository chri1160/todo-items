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
