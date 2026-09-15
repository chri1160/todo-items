<?php

namespace Timot\TodoItems;

use RuntimeException;

/**
 * One TODO item — the frontmatter contract plus its markdown body.
 *
 * Items live one-per-file in `todo/` so each has its own git history
 * (`git log todo/047-*.md` is that item's whole story) and so concurrent
 * agents editing different items don't collide on one enormous file.
 *
 * The frontmatter is deliberately parsed by hand rather than through a YAML
 * library: the contract is six single-line scalars, and a parser for that is
 * shorter than the argument for taking the dependency. Values are emitted
 * double-quoted and escaped, so the block is still valid YAML for anything
 * else that reads it.
 */
class TodoItem
{
    public const STATUS_OPEN = 'open';

    public const STATUS_DONE = 'done';

    public function __construct(
        public int $id,
        public string $slug,
        public string $title,
        public string $section,
        public int $position,
        public string $status = self::STATUS_OPEN,
        public ?string $closed = null,
        public string $body = '',
    ) {}

    public function filename(): string
    {
        return sprintf('%03d-%s.md', $this->id, $this->slug);
    }

    public function reference(): string
    {
        return sprintf('#%03d', $this->id);
    }

    public function isDone(): bool
    {
        return $this->status === self::STATUS_DONE;
    }

    public static function fromFile(string $path): self
    {
        $raw = str_replace("\r\n", "\n", (string) file_get_contents($path));

        if (! preg_match('/\A---\n(.*?)\n---\n?(.*)\z/s', $raw, $m)) {
            throw new RuntimeException("Missing or malformed frontmatter: {$path}");
        }

        $meta = self::parseFrontmatter($m[1]);

        foreach (['id', 'slug', 'title', 'section', 'position'] as $required) {
            if (! isset($meta[$required])) {
                throw new RuntimeException("Frontmatter key `{$required}` missing: {$path}");
            }
        }

        return new self(
            id: (int) $meta['id'],
            slug: $meta['slug'],
            title: $meta['title'],
            section: $meta['section'],
            position: (int) $meta['position'],
            status: $meta['status'] ?? self::STATUS_OPEN,
            closed: $meta['closed'] ?? null,
            body: trim($m[2]),
        );
    }

    public function render(): string
    {
        $lines = [
            '---',
            'id: '.$this->id,
            'slug: '.self::quote($this->slug),
            'title: '.self::quote($this->title),
            'section: '.self::quote($this->section),
            'position: '.$this->position,
            'status: '.$this->status,
        ];

        if ($this->closed !== null) {
            $lines[] = 'closed: '.$this->closed;
        }

        $lines[] = '---';

        return implode("\n", $lines)."\n\n".trim($this->body)."\n";
    }

    /**
     * @return array<string, string>
     */
    private static function parseFrontmatter(string $block): array
    {
        $meta = [];

        foreach (explode("\n", $block) as $line) {
            if (trim($line) === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = explode(':', $line, 2);
            $meta[trim($key)] = self::unquote(trim($value));
        }

        return $meta;
    }

    private static function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
            return str_replace(['\\"', '\\\\'], ['"', '\\'], substr($value, 1, -1));
        }

        return $value;
    }
}
