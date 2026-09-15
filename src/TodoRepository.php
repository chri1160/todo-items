<?php

namespace Timot\TodoItems;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Reads and writes the file-backed TODO list.
 *
 * The arrangement, and why it isn't a database table: a dev database gets
 * rebuilt as a matter of routine — in the project this grew in, `migrate:fresh
 * --seed` against live CSV exports — so a `todos` table is destroyed by the
 * normal workflow; and the items are amended prose, whose evolution the git
 * history records better than an `updated_at` column ever could. A table would
 * also put the list behind a running database, where the whole point is that an
 * agent with a checkout and nothing else can read and write it.
 *
 * So: one file per item under `todo/`, and `TODO.md` is a *generated* index
 * over them. Marking an item done is a frontmatter flip plus a regenerate —
 * nobody ever cuts a 2KB block out of one file and pastes it into another
 * section again. Section order and per-section preamble prose are the one
 * hand-maintained part, in `todo/_index.md`.
 *
 * Both the base directory and the index path are injectable so tests operate
 * on a temp tree rather than the repository's real list.
 */
class TodoRepository
{
    public const DONE_SECTION = 'Done';

    public function __construct(
        private ?string $dir = null,
        private ?string $indexPath = null,
        private ?TodoIds $ids = null,
    ) {}

    private function ids(): TodoIds
    {
        return $this->ids ??= new TodoIds;
    }

    public function dir(): string
    {
        return $this->dir ?? base_path('todo');
    }

    public function indexPath(): string
    {
        return $this->indexPath ?? base_path('TODO.md');
    }

    public function sectionsPath(): string
    {
        return $this->dir().'/_index.md';
    }

    /**
     * Every item, ordered by section position then id.
     *
     * @return Collection<int, TodoItem>
     */
    public function all(): Collection
    {
        $files = glob($this->dir().'/[0-9]*.md') ?: [];

        return collect($files)
            ->map(fn (string $path) => TodoItem::fromFile($path))
            ->sortBy([['position', 'asc'], ['id', 'asc']])
            ->values();
    }

    /**
     * Every item in the order the list is *worked*: section priority first, then
     * position within it.
     *
     * {@see all()} sorts by position then id, which reads correctly inside one
     * section and interleaves across them — position 1 of `Ideas` sorts above
     * position 2 of `High Priority`. That is harmless for rendering, where each
     * section is filtered out separately, and wrong for "what should I pick up
     * next", where the section order in `_index.md` *is* the priority.
     *
     * @return Collection<int, TodoItem>
     */
    public function prioritized(): Collection
    {
        $order = array_flip(array_keys($this->sections()));

        return $this->all()->sortBy([
            fn (TodoItem $a, TodoItem $b) => ($order[$a->section] ?? PHP_INT_MAX) <=> ($order[$b->section] ?? PHP_INT_MAX),
            fn (TodoItem $a, TodoItem $b) => $a->position <=> $b->position,
            fn (TodoItem $a, TodoItem $b) => $a->id <=> $b->id,
        ])->values();
    }

    /**
     * The next open item nobody is holding, in priority order.
     *
     * Claims are passed in rather than resolved here: the repository's whole
     * subject is the files on disk, and a claim is deliberately not one of them
     * ({@see TodoClaims}).
     */
    public function nextAvailable(TodoClaims $claims, ?string $section = null): ?TodoItem
    {
        $held = $claims->all();

        return $this->prioritized()
            ->where('status', TodoItem::STATUS_OPEN)
            ->when(
                $section !== null && $section !== '',
                fn (Collection $items) => $items->filter(
                    fn (TodoItem $item) => Str::contains($item->section, $section, ignoreCase: true),
                ),
            )
            ->first(fn (TodoItem $item) => ! isset($held[$item->id]));
    }

    public function find(int $id): TodoItem
    {
        $item = $this->all()->firstWhere('id', $id);

        if (! $item) {
            throw new RuntimeException("No TODO item with id {$id}.");
        }

        return $item;
    }

    /**
     * Write an item, removing any stale file left by a renamed slug.
     */
    public function save(TodoItem $item): string
    {
        if (! is_dir($this->dir())) {
            mkdir($this->dir(), 0755, true);
        }

        foreach (glob($this->dir().'/'.sprintf('%03d', $item->id).'-*.md') ?: [] as $existing) {
            if (basename($existing) !== $item->filename()) {
                unlink($existing);
            }
        }

        $path = $this->dir().'/'.$item->filename();
        file_put_contents($path, $item->render());

        return $path;
    }

    /**
     * Take the next id, above both this tree's highest and every other branch's.
     *
     * Named for what it does: this CONSUMES an id, so calling it twice yields two.
     * It was `nextId()`, a query name on a consuming method, and that is what let
     * `TodoListTest` assert a deleted id would be handed back — reuse, asserted
     * beneath a comment forbidding reuse, green for as long as the test existed.
     */
    public function allocateId(): int
    {
        return $this->ids()->allocate((int) $this->all()->max('id'));
    }

    public function nextPosition(string $section): int
    {
        $inSection = $this->all()->where('section', $section);

        return $inSection->isEmpty() ? 1 : ((int) $inSection->max('position')) + 1;
    }

    /**
     * Ordered section name => preamble prose (empty string when none), read
     * from `todo/_index.md`. The `Done` section is always last and always
     * present, whether or not the file lists it.
     *
     * @return array<string, string>
     */
    public function sections(): array
    {
        $sections = [];

        if (is_file($this->sectionsPath())) {
            $current = null;
            $raw = str_replace("\r\n", "\n", (string) file_get_contents($this->sectionsPath()));

            foreach (explode("\n", $raw) as $line) {
                if (preg_match('/^## (.+)$/', $line, $m)) {
                    $current = trim($m[1]);
                    $sections[$current] = '';

                    continue;
                }

                if ($current !== null) {
                    $sections[$current] .= $line."\n";
                }
            }
        }

        $sections = array_map('trim', $sections);

        unset($sections[self::DONE_SECTION]);

        // Any section a stray item claims but `_index.md` doesn't list still
        // has to render, or the item vanishes from the index silently.
        foreach ($this->all()->where('status', TodoItem::STATUS_OPEN)->pluck('section')->unique() as $claimed) {
            if (! array_key_exists($claimed, $sections)) {
                $sections[$claimed] = '';
            }
        }

        $sections[self::DONE_SECTION] = '';

        return $sections;
    }

    /**
     * Render `TODO.md`: open items under their section, done items collected
     * under `Done` newest-first regardless of the section they were closed in.
     */
    public function renderIndex(): string
    {
        $items = $this->all();
        $out = "# TODO\n\n";
        $out .= "<!-- Generated by `sail artisan todo:index` — do not edit.\n";
        $out .= "     Item bodies live in `todo/`; section order and preambles in `todo/_index.md`. -->\n";

        foreach ($this->sections() as $section => $preamble) {
            $out .= "\n## ".$section."\n";

            if ($preamble !== '') {
                $out .= "\n".$preamble."\n";
            }

            $rows = $section === self::DONE_SECTION
                ? $items->where('status', TodoItem::STATUS_DONE)
                    ->sortByDesc(fn (TodoItem $i) => sprintf('%s-%05d', $i->closed ?? '0000-00-00', $i->id))
                    ->values()
                : $items->where('status', TodoItem::STATUS_OPEN)->where('section', $section)->values();

            if ($rows->isEmpty()) {
                $out .= "\n*Nothing here yet.*\n";

                continue;
            }

            $out .= "\n";

            foreach ($rows as $item) {
                $out .= '- **['.$item->reference().']('.$this->linkFor($item).')** '.$item->title;
                $out .= $item->closed !== null ? ' *('.$item->closed.")*\n" : "\n";
            }
        }

        return $out;
    }

    public function writeIndex(): string
    {
        file_put_contents($this->indexPath(), $this->renderIndex());

        return $this->indexPath();
    }

    /**
     * Link from the index to an item file, relative to the index's own
     * directory so it resolves both on GitHub and in an editor.
     */
    private function linkFor(TodoItem $item): string
    {
        $relative = self::relativePath(dirname($this->indexPath()), $this->dir());

        return ($relative === '' ? '' : $relative.'/').$item->filename();
    }

    /**
     * The path to $target as written from inside $from, with `../` where needed.
     *
     * This was a prefix strip, which is the same answer whenever the items sit
     * under the index's own directory and an **absolute host path** whenever they
     * do not — silently, into a generated markdown file that people read and
     * click. `directory` and `index` are separately configurable, so the config
     * permitted a layout the renderer could not express; keeping a `docs/TODO.md`
     * over a root `todo/` is an ordinary thing to want, so the fix is to express
     * it rather than to forbid it.
     */
    private static function relativePath(string $from, string $target): string
    {
        $from = self::segments($from);
        $target = self::segments($target);

        while ($from !== [] && $target !== [] && $from[0] === $target[0]) {
            array_shift($from);
            array_shift($target);
        }

        return implode('/', [...array_fill(0, count($from), '..'), ...$target]);
    }

    /**
     * A path as its meaningful segments, with `.` dropped and `..` resolved.
     *
     * @return list<string>
     */
    private static function segments(string $path): array
    {
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $part;
        }

        return $segments;
    }

    public function slugify(string $title): string
    {
        // Titles are markdown-rich (`code`, **bold**, *emphasis*, emoji) and
        // long; strip the markup before slugifying so the filename reads as
        // words, then cut at a word boundary rather than mid-word.
        $plain = preg_replace('/[`*_\[\]()"\'#]+/u', '', $title) ?? $title;
        $plain = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $plain) ?? $plain;

        $slug = Str::slug(trim((string) $plain));

        if (strlen($slug) <= 60) {
            return $slug !== '' ? $slug : 'item';
        }

        $cut = substr($slug, 0, 60);
        $lastDash = strrpos($cut, '-');

        return $lastDash !== false && $lastDash > 20 ? substr($cut, 0, $lastDash) : $cut;
    }
}
