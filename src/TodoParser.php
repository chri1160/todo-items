<?php

namespace Timot\TodoItems;

use RuntimeException;

/**
 * Parses a markdown TODO list into sections and item blocks.
 *
 * `todo:index` runs it over the *generated* TODO.md on every regenerate, to
 * adopt anything a human or an agent hand-appended.
 *
 * Adoption is the load-bearing use. TODO.md is generated, and a generated file
 * that silently discards edits is a data-loss trap — someone appends an item,
 * the next regenerate erases it, and nobody finds out. Rather than guard the
 * file, adoption makes the loss unrepresentable: a stray bullet is not an
 * error, it is an item that hasn't been given a file yet.
 */
class TodoParser
{
    /**
     * A row the index generator wrote: `- **[#019](todo/019-slug.md)** Title`.
     * Anything else opening with `- **` is a stray awaiting adoption.
     */
    private const GENERATED_ROW = '/^- \*\*\[#(\d+)\]\([^)]*\)\*\*\s*(.*)$/u';

    /**
     * @return array{sections: array<string, string>, items: list<array{section: string, title: string, body: string, closed: ?string, order: int, id: ?int}>}
     */
    public function parse(string $markdown): array
    {
        // Drop the generator's banner comment before splitting into lines.
        // Skipping its lines by shape instead (a leading run of spaces) would
        // also swallow any deeply indented body line, silently truncating an
        // item mid-list.
        $markdown = (string) preg_replace('/<!--.*?-->/s', '', str_replace("\r\n", "\n", $markdown));

        $lines = explode("\n", rtrim($markdown, "\n"));

        $sections = [];
        $raw = [];
        $section = null;
        $current = null;

        foreach ($lines as $line) {
            if (preg_match('/^## (.+)$/', $line, $m)) {
                if ($current !== null) {
                    $raw[] = $current;
                    $current = null;
                }

                $section = trim($m[1]);
                $sections[$section] = '';

                continue;
            }

            // The document title is chrome, not content.
            if (str_starts_with($line, '# ')) {
                continue;
            }

            if (str_starts_with($line, '- **')) {
                if ($current !== null) {
                    $raw[] = $current;
                }

                $current = ['section' => $section ?? 'Ideas', 'lines' => [$line]];

                continue;
            }

            if ($current !== null) {
                $current['lines'][] = $line;

                continue;
            }

            if ($section !== null) {
                $sections[$section] .= $line."\n";
            }
        }

        if ($current !== null) {
            $raw[] = $current;
        }

        $items = [];

        foreach ($raw as $order => $block) {
            $items[] = $this->toItem($block['section'], $block['lines'], $order);
        }

        return ['sections' => array_map('trim', $sections), 'items' => $items];
    }

    /**
     * @param  list<string>  $lines
     * @return array{section: string, title: string, body: string, closed: ?string, order: int, id: ?int}
     */
    private function toItem(string $section, array $lines, int $order): array
    {
        $first = (string) array_shift($lines);

        if (preg_match(self::GENERATED_ROW, $first, $m)) {
            return [
                'section' => $section,
                'title' => trim(preg_replace('/\s*\*\(\d{4}-\d{2}-\d{2}\)\*\s*$/u', '', $m[2]) ?? $m[2]),
                'body' => '',
                'closed' => null,
                'order' => $order,
                'id' => (int) $m[1],
            ];
        }

        // Continuation lines were nested under the bullet; they are top-level
        // content once the item owns a file, so shed the indent nesting needed.
        $dedented = array_map(
            fn (string $line) => str_starts_with($line, '  ') ? substr($line, 2) : $line,
            $lines,
        );

        $block = implode("\n", [$first, ...$dedented]);

        // Every top-level bullet opens with its title in bold; the first `**`
        // pair is the title, everything after it is the body. A few titles wrap
        // onto the next line, so match across the block, not the lead line.
        if (! preg_match('/^- \*\*(.+?)\*\*/su', $block, $m)) {
            throw new RuntimeException('Unparseable item lead: '.substr($first, 0, 120));
        }

        $title = trim((string) preg_replace('/\s+/u', ' ', $m[1]));
        $rest = substr($block, strlen($m[0]));

        $closed = null;

        // Drop the separator that joined the bold title to its body, then the
        // leading `(YYYY-MM-DD)` completion stamp if there is one — once it is
        // lifted into `closed:` frontmatter, leaving it in the prose duplicates
        // it, and the two copies would drift the first time one is corrected.
        $rest = preg_replace('/^[ \t]*[—–-][ \t]*/u', '', $rest) ?? $rest;

        if (preg_match('/^\s*\((\d{4}-\d{2}-\d{2})\)\s*/u', $rest, $d)) {
            $closed = $d[1];
            $rest = substr($rest, strlen($d[0]));
        } elseif (preg_match('/(\d{4}-\d{2}-\d{2})/', $title, $d)) {
            $closed = $d[1];
        }

        return [
            'section' => $section,
            'title' => $title,
            'body' => trim($rest),
            'closed' => $section === TodoRepository::DONE_SECTION ? $closed : null,
            'order' => $order,
            'id' => null,
        ];
    }
}
