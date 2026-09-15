<?php

namespace Timot\TodoItems\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Timot\TodoItems\Support\AgentSession;
use Timot\TodoItems\TodoClaims;
use Timot\TodoItems\TodoItem;
use Timot\TodoItems\TodoRepository;

/**
 * Prints the TODO list, filtered.
 *
 * Reading the list any other way means a hand-rolled `for f in todo/*.md` loop
 * with `grep`/`cut` per frontmatter key. That shape can never be allowlisted —
 * a permission rule matches a command prefix, and a loop over `$(...)` command
 * substitution is by construction unmatchable, so every read of the list costs
 * a prompt. A named command is one stable prefix, so `todo:*` covers reading
 * the list forever.
 */
class ListCommand extends Command
{
    protected $signature = 'todo:list
        {--s|section= : Only sections whose name contains this (case-insensitive)}
        {--status=open : open, claimed, available, done, or all}
        {--limit=0 : Cap rows printed (0 = no cap)}
        {--body : Print each item body as well as its title}';

    protected $description = 'List TODO items, filtered by section and status';

    public function handle(TodoRepository $repository, TodoClaims $claims): int
    {
        $status = strtolower((string) $this->option('status'));
        $valid = ['open', 'claimed', 'available', 'done', 'all'];

        if (! in_array($status, $valid, true)) {
            $this->components->error("`{$status}` isn't a status — use ".implode(', ', $valid).'.');

            return self::FAILURE;
        }

        // `open` deliberately still means "not done", claimed items included: an
        // item that vanished from the default listing the moment somebody took
        // it would read as deleted, and the whole point is to see who has what.
        $held = $claims->all();

        $needle = trim((string) $this->option('section'));
        $limit = max(0, (int) $this->option('limit'));

        $items = $repository->all()
            ->when(in_array($status, ['open', 'done'], true), fn ($items) => $items->where('status', $status))
            ->when($status === 'claimed', fn ($items) => $items
                ->where('status', TodoItem::STATUS_OPEN)
                ->filter(fn (TodoItem $item) => isset($held[$item->id])))
            ->when($status === 'available', fn ($items) => $items
                ->where('status', TodoItem::STATUS_OPEN)
                ->filter(fn (TodoItem $item) => ! isset($held[$item->id])))
            ->when($needle !== '', fn ($items) => $items->filter(
                fn (TodoItem $item) => Str::contains($item->section, $needle, ignoreCase: true),
            ));

        if ($items->isEmpty()) {
            $this->components->warn('No matching items.');

            return self::SUCCESS;
        }

        $shown = 0;
        $dropped = 0;

        foreach (array_keys($repository->sections()) as $section) {
            $inSection = $items->where('section', $section);

            if ($inSection->isEmpty()) {
                continue;
            }

            // Done items read newest-first — same order the generated index
            // uses — because the useful question there is "what shipped lately".
            if ($section === TodoRepository::DONE_SECTION || $status === 'done') {
                $inSection = $inSection->sortByDesc('closed');
            }

            $this->newLine();
            $this->line("<fg=cyan;options=bold>{$section}</>");

            foreach ($inSection as $item) {
                if ($limit > 0 && $shown >= $limit) {
                    $dropped++;

                    continue;
                }

                $claim = $held[$item->id] ?? null;

                $this->line(sprintf(
                    '  <fg=gray>%3d</>  <fg=yellow>%s</>  %s%s%s',
                    $item->position,
                    $item->reference(),
                    Str::limit($item->title, 110),
                    $item->closed ? " <fg=gray>({$item->closed})</>" : '',
                    $claim === null ? '' : sprintf(
                        ' <fg=magenta>[held by %s, %dh]</>',
                        AgentSession::isSelf($claim['by']) ? 'you' : $claim['by'],
                        $claims->ageInHours($claim),
                    ),
                ));

                if ($this->option('body') && $item->body !== '') {
                    $this->line('       <fg=gray>'.str_replace("\n", "\n       ", $item->body).'</>');
                }

                $shown++;
            }
        }

        $this->newLine();

        // A cap that hides its own truncation reads as "that's everything".
        $this->components->info($dropped > 0
            ? "{$shown} shown, {$dropped} more matched (raise --limit)."
            : "{$shown} item".($shown === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}
