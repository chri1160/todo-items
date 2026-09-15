<?php

namespace Timot\TodoItems\Commands;

use RuntimeException;
use Timot\TodoItems\Support\AgentSession;
use Timot\TodoItems\TodoClaims;
use Timot\TodoItems\TodoItem;
use Timot\TodoItems\TodoRepository;

/**
 * Take an item, so no other agent starts it.
 *
 * The id-less form is the one that matters: `todo:claim` with no argument picks
 * the highest-priority item nobody holds and takes it in a single locked
 * operation. Reading the list and then claiming what you saw is the same race
 * one step removed — two agents read "#047 is free" and both act on it — so
 * pick-and-take is deliberately not two commands.
 *
 * Claiming is not assignment by a human, and it is not a lock on the files: it
 * is an announcement, so the next agent looks further down the list.
 */
class ClaimCommand extends TodoCommand
{
    protected $signature = 'todo:claim
        {id? : Item id, with or without leading zeros; omit to take the next available item}
        {--s|section= : Restrict an id-less claim to a section (partial, case-insensitive)}
        {--release : Hand an item back; with no id, releases everything you hold}
        {--force : Take an item another agent is still holding}
        {--body : Print the item body once claimed}';

    protected $description = 'Claim a TODO item so concurrent agents pick a different one';

    public function handle(TodoRepository $repository, TodoClaims $claims): int
    {
        $id = $this->argument('id') === null
            ? null
            : (int) preg_replace('/\D/', '', (string) $this->argument('id'));

        if ($this->option('release')) {
            return $this->release($repository, $claims, $id);
        }

        try {
            $item = $id === null
                ? $repository->nextAvailable($claims, (string) $this->option('section'))
                : $repository->find($id);
        } catch (RuntimeException $e) {
            // A mistyped id is an ordinary slip, not a crash worth a stack trace.
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($item === null) {
            $this->components->warn('Nothing available — every open item is claimed.');
            $this->line('  <fg=gray>`todo:list --status=claimed` shows who holds what.</>');

            return self::SUCCESS;
        }

        if ($item->isDone()) {
            $this->components->error("{$item->reference()} is already done — {$item->title}");

            return self::FAILURE;
        }

        $blocked = $claims->claim($item->id, force: (bool) $this->option('force'));

        if ($blocked !== null) {
            $this->components->error(sprintf(
                '%s is held by %s (%dh) — %s',
                $item->reference(),
                $blocked['by'],
                $claims->ageInHours($blocked),
                $item->title,
            ));
            $this->line('  <fg=gray>Take it anyway with --force, or run `todo:claim` with no id for the next free one.</>');

            return self::FAILURE;
        }

        $this->components->info("Claimed {$item->reference()} — {$item->title}");
        $this->line('  <fg=gray>'.$repository->dir().'/'.$item->filename().'</>');
        $this->line('  <fg=gray>Release with `todo:claim '.$item->id.' --release`; `todo:done '.$item->id.'` releases it too.</>');

        if ($this->option('body') && $item->body !== '') {
            $this->newLine();
            $this->line($item->body);
        }

        return self::SUCCESS;
    }

    private function release(TodoRepository $repository, TodoClaims $claims, ?int $id): int
    {
        if ($id === null) {
            $released = $claims->releaseAllFor();

            if ($released === []) {
                $this->components->warn('You are holding nothing.');

                return self::SUCCESS;
            }

            foreach ($released as $releasedId) {
                $this->components->info('Released #'.sprintf('%03d', $releasedId));
            }

            return self::SUCCESS;
        }

        $holder = $claims->holder($id);

        if ($holder !== null && ! AgentSession::isSelf($holder['by']) && ! $this->option('force')) {
            $this->components->error("#{$id} is held by {$holder['by']}, not you — pass --force to release it anyway.");

            return self::FAILURE;
        }

        if (! $claims->release($id)) {
            $this->components->warn("#{$id} wasn't claimed.");

            return self::SUCCESS;
        }

        $item = $repository->all()->firstWhere('id', $id);

        $this->components->info(sprintf('Released #%03d%s', $id, $item instanceof TodoItem ? ' — '.$item->title : ''));

        return self::SUCCESS;
    }
}
