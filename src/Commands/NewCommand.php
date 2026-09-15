<?php

namespace Timot\TodoItems\Commands;

use Timot\TodoItems\TodoItem;
use Timot\TodoItems\TodoRepository;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Creates a TODO item file and regenerates the index.
 *
 * The id is allocated here and never reused, which is the whole point of the
 * arrangement: `#047` is a stable handle a doc, a commit message, or a
 * conversation can cite, where "the promotion-cutoff item" was only ever a
 * description that drifted as the item was reworded.
 */
class NewCommand extends TodoCommand
{
    protected $signature = 'todo:new
        {title? : The item title (markdown allowed)}
        {--section= : Section to file it under}
        {--body= : Item body; omit to scaffold a placeholder}';

    protected $description = 'Create a new TODO item under todo/ and regenerate TODO.md';

    public function handle(TodoRepository $repository): int
    {
        $title = $this->argument('title') ?: text(
            label: 'Item title',
            required: true,
        );

        $sections = array_keys($repository->sections());
        $open = array_values(array_filter($sections, fn (string $s) => $s !== TodoRepository::DONE_SECTION));

        $section = $this->option('section') ?: select(
            label: 'Section',
            options: $open,
            default: $open[0] ?? 'Ideas',
        );

        if (! in_array($section, $sections, true)) {
            $this->components->warn("`{$section}` isn't in todo/_index.md — it will render at the end of the index.");
        }

        $item = new TodoItem(
            id: $repository->allocateId(),
            slug: $repository->slugify($title),
            title: $title,
            section: $section,
            position: $repository->nextPosition($section),
            body: $this->option('body') ?: '_Not written up yet._',
        );

        $path = $repository->save($item);
        $repository->writeIndex();

        $this->components->info("Created {$item->reference()} — {$path}");

        return self::SUCCESS;
    }
}
