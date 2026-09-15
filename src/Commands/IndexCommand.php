<?php

namespace Timot\TodoItems\Commands;

use Illuminate\Console\Command;
use Timot\TodoItems\TodoItem;
use Timot\TodoItems\TodoParser;
use Timot\TodoItems\TodoRepository;

/**
 * Regenerates TODO.md from the item files in `todo/`.
 *
 * Adoption runs first: any bullet in TODO.md that isn't one of the generator's
 * own rows is a hand-written item, and it gets its own file before the file is
 * overwritten. So "just append a bullet to TODO.md" remains a valid way to add
 * an item — for a person in a hurry, and for an agent whose context predates
 * this tooling — and no edit is ever lost to a regenerate.
 */
class IndexCommand extends Command
{
    protected $signature = 'todo:index {--dry-run}';

    protected $description = 'Regenerate TODO.md from todo/*.md, adopting any hand-written items';

    public function handle(TodoRepository $repository, TodoParser $parser): int
    {
        $adopted = $this->adopt($repository, $parser);

        if ($this->option('dry-run')) {
            $this->line($repository->renderIndex());

            return self::SUCCESS;
        }

        foreach ($adopted as $item) {
            $repository->save($item);
            $this->components->info("Adopted {$item->reference()} — {$item->title}");
        }

        $this->reconcileFilenames($repository);

        $repository->writeIndex();

        $open = $repository->all()->where('status', TodoItem::STATUS_OPEN)->count();
        $done = $repository->all()->where('status', TodoItem::STATUS_DONE)->count();

        $this->components->info("Wrote {$repository->indexPath()} — {$open} open, {$done} done.");

        return self::SUCCESS;
    }

    /**
     * Rewrite any item whose file no longer matches its slug.
     *
     * Editing `slug:` in an item's frontmatter is the obvious way to rename it,
     * and on its own it renames nothing — the index would then link to a path
     * that doesn't exist, which reads as a missing item rather than a stale
     * filename. {@see TodoRepository::save()} already drops the old file, so the
     * fix is to route every item back through it before the index is written.
     */
    private function reconcileFilenames(TodoRepository $repository): void
    {
        foreach ($repository->all() as $item) {
            $expected = $repository->dir().'/'.$item->filename();

            if (is_file($expected)) {
                continue;
            }

            $repository->save($item);
            $this->components->info("Renamed {$item->reference()} — {$item->filename()}");
        }
    }

    /**
     * Hand-written bullets in the current index, as items awaiting a file.
     *
     * @return list<TodoItem>
     */
    private function adopt(TodoRepository $repository, TodoParser $parser): array
    {
        if (! is_file($repository->indexPath())) {
            return [];
        }

        $parsed = $parser->parse((string) file_get_contents($repository->indexPath()));

        $strays = array_values(array_filter(
            $parsed['items'],
            fn (array $block) => $block['id'] === null && trim($block['title']) !== '',
        ));

        if ($strays === []) {
            return [];
        }

        // Titles already on disk. A stray repeating one is an edit to an item
        // that has a file, not a new item — adopting it would fork the item in
        // two, so leave it alone and let the regenerate drop the duplicate row.
        $known = $repository->all()->map(fn (TodoItem $i) => mb_strtolower($i->title))->all();

        $id = $repository->allocateId();
        $positions = [];
        $adopted = [];

        foreach ($strays as $block) {
            if (in_array(mb_strtolower($block['title']), $known, true)) {
                $this->components->warn('Skipped a bullet whose title already has a file: '.$block['title']);

                continue;
            }

            [$section, $closed] = $this->fileStray($repository, $block);

            $positions[$section] ??= $repository->nextPosition($section);

            $adopted[] = new TodoItem(
                id: $id++,
                slug: $repository->slugify($block['title']),
                title: $block['title'],
                section: $section,
                position: $positions[$section]++,
                status: $closed !== null ? TodoItem::STATUS_DONE : TodoItem::STATUS_OPEN,
                closed: $closed,
                body: $block['body'],
            );
        }

        return $adopted;
    }

    /**
     * Decide the section and completion date for a stray.
     *
     * `Done` is the last section the generator writes, so a bullet appended to
     * the bottom of TODO.md — the natural way to jot something down — lands
     * under it. Inferring "done" from that position would file new work as
     * already shipped, which is the one wrong answer that hides itself. So a
     * completion date is required to claim done, and a dateless stray is open
     * work filed under the last open section.
     *
     * @param  array{section: string, title: string, closed: ?string}  $block
     * @return array{0: string, 1: ?string}
     */
    private function fileStray(TodoRepository $repository, array $block): array
    {
        if ($block['section'] !== TodoRepository::DONE_SECTION) {
            return [$block['section'], null];
        }

        if ($block['closed'] !== null) {
            return [$block['section'], $block['closed']];
        }

        $open = array_values(array_filter(
            array_keys($repository->sections()),
            fn (string $s) => $s !== TodoRepository::DONE_SECTION,
        ));

        $section = end($open) ?: 'Ideas';

        $this->components->warn(
            "Adopted under `{$section}`, not Done — a bullet below the Done heading needs a "
            ."(YYYY-MM-DD) stamp to count as finished: {$block['title']}",
        );

        return [$section, null];
    }
}
