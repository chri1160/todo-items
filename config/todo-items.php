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

    'ids' => null,

    'claims' => null,

];
