<?php

namespace Timot\TodoItems\Commands;

use Timot\TodoItems\TodoClaims;
use Timot\TodoItems\TodoItem;
use Timot\TodoItems\TodoRepository;

/**
 * Closes a TODO item: flips its status, stamps the date, regenerates.
 *
 * This is the whole reason the list isn't one file. Closing an item would
 * otherwise mean cutting a 2KB block out of one section and pasting it into
 * another; instead the item stays exactly where it lives and the index moves it
 * to `Done` on its own. The item keeps its original `section`, so what was
 * closed out of which area stays on the record.
 */
class DoneCommand extends TodoCommand
{
    protected $signature = 'todo:done
        {id* : Item id(s), with or without leading zeros}
        {--date= : Completion date (defaults to today)}
        {--reopen : Reopen instead — clears status and date}';

    protected $description = 'Mark TODO item(s) done (or reopen) and regenerate TODO.md';

    public function handle(TodoRepository $repository, TodoClaims $claims): int
    {
        $date = $this->option('date') ?: now()->toDateString();

        if (! $this->option('reopen') && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->components->error("`{$date}` isn't a YYYY-MM-DD date.");

            return self::FAILURE;
        }

        foreach ($this->argument('id') as $raw) {
            $item = $repository->find((int) preg_replace('/\D/', '', (string) $raw));

            if ($this->option('reopen')) {
                $item->status = TodoItem::STATUS_OPEN;
                $item->closed = null;
                $repository->save($item);
                $this->components->info("Reopened {$item->reference()} — {$item->title}");

                continue;
            }

            $item->status = TodoItem::STATUS_DONE;
            $item->closed = $date;
            $repository->save($item);

            // Finishing an item is the commonest way to stop working on it, so
            // releasing here is what keeps the claim registry honest without
            // anyone having to remember a second command.
            $claims->release($item->id);

            $this->components->info("Closed {$item->reference()} ({$date}) — {$item->title}");
        }

        $this->regenerateIndex($repository);

        return self::SUCCESS;
    }
}
