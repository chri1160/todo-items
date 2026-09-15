<?php

namespace Timot\TodoItems\Filament;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Timot\TodoItems\Support\AgentSession;
use Timot\TodoItems\TodoClaims;
use Timot\TodoItems\TodoItem;
use Timot\TodoItems\TodoRepository;
use UnitEnum;

/**
 * The TODO list as a read-only Filament page. Subclass it; the package does not
 * register it.
 *
 * Registration is the consumer's job on purpose. `canAccess()` differs per
 * project, and a page the package registered could not be made conditional by
 * the project — while a subclass is the project's own file, so it can live in a
 * directory the panel discovers only in development. That is what lets this
 * package be a `require-dev` dependency: absent in production rather than
 * present and disabled. A page shipped ready-registered would forfeit it.
 *
 * Discovery must be the conditional part, not access. `canAccess()` gates
 * access while discovery still *loads* the class, and loading is exactly what
 * fails when the package is absent — a gate that runs after the fatal is not a
 * gate. Note also that `Panel::discoverPages()` is typed `string $in`, so the
 * obvious "pass null in production" is a TypeError; use `Panel::when()`.
 *
 * The list is file-backed rather than a table ({@see TodoRepository} for why),
 * so this is a Filament table over custom data: `records()` is handed the
 * parsed items instead of a query, and searching, filtering and sorting happen
 * here in PHP rather than in SQL. A couple of hundred prose files parse in a few
 * milliseconds, so there is nothing to cache and nothing to index.
 *
 * Deliberately read-only. Every write to the list — closing, claiming, adding —
 * regenerates the index and produces a git diff that belongs in the same commit
 * as the work it describes, which is a terminal's job, not a browser's. Worse,
 * a claim taken from a browser would be attributed to a session that isn't
 * doing the work ({@see AgentSession}), so the one coordination signal the
 * claim registry carries would start lying. This page answers "what is on the
 * list, what state is it in, and what does it actually say" without anyone
 * having to run `todo:list --body` and scroll.
 *
 * Two seams a subclass will want: {@see canAccess()}, which is abstract, and
 * {@see icons()}, which defaults to Heroicons and can be mapped onto a
 * project's own icon registry.
 */
abstract class TodoListPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationLabel = 'TODO List';

    /**
     * No group by default.
     *
     * This said `Miscellaneous` until v1.2.1, which was the navigation group of
     * the project the page was extracted from — a name that means nothing in
     * another panel, and that every consumer therefore had to override. A
     * default that every consumer must replace is not a default. Set it in the
     * subclass, where the panel's own group names are known.
     */
    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?string $title = 'TODO List';

    /**
     * The page's URL, fixed rather than derived.
     *
     * Filament would otherwise slugify the subclass name, so a project that
     * called its subclass anything but `Todos` would silently get a different
     * URL from the one it had. Declaring it here makes `/todos` the answer for
     * every consumer regardless of naming; override it to move the page.
     */
    protected static ?string $slug = 'todos';

    protected string $view = 'todo-items::page';

    /**
     * The three states an item can be looked at in, and what to call them.
     *
     * `open` is the frontmatter status; whether anybody is holding it is what
     * separates the first two ({@see TodoClaims}).
     */
    protected const STATUS_LABELS = [
        'open' => 'Available',
        'claimed' => 'Claimed',
        TodoItem::STATUS_DONE => 'Done',
    ];

    /**
     * Parsed items, memoised for the length of one request — the heading and
     * the table would otherwise each re-read every file on disk.
     *
     * @var Collection<int, array<string, mixed>>|null
     */
    protected ?Collection $cachedRows = null;

    /**
     * Section names in `_index.md` order, memoised alongside the rows.
     *
     * {@see TodoRepository::sections()} re-parses every item file to catch a
     * section no `_index.md` lists, so calling it once for the row's priority
     * order and again for the filter's options would parse the whole list twice
     * more. Two hundred-odd files is a few tens of milliseconds a time — cheap
     * enough to leave uncached, not cheap enough to pay for three times.
     *
     * @var array<string, string>|null
     */
    protected ?array $cachedSections = null;

    /**
     * Icons by role, Heroicons by default.
     *
     * A project with its own icon registry overrides this and maps the five
     * roles onto it, rather than the page reaching for a config key that only
     * one consumer has. Returning null for a role simply omits that icon.
     *
     * @return array<string, string|BackedEnum|null>
     */
    protected static function icons(): array
    {
        return [
            'page' => Heroicon::OutlinedClipboardDocumentCheck,
            'available' => Heroicon::OutlinedInbox,
            'claimed' => Heroicon::OutlinedLockClosed,
            'done' => Heroicon::OutlinedCheckCircle,
            'view' => Heroicon::OutlinedEye,
        ];
    }

    protected static function icon(string $role): string|BackedEnum|null
    {
        return static::icons()[$role] ?? null;
    }

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return static::icon('page');
    }

    /**
     * Who may reach the page. Deliberately false, so a subclass must decide.
     *
     * Filament's own default returns true, and inheriting that would publish
     * the roadmap to every panel user of a consumer that never thought about
     * it. Abstract would be better still — a subclass that forgot would not
     * load at all — but PHP forbids re-declaring an inherited concrete method
     * as abstract, so the strongest available option is to fail closed.
     *
     * The failure is loud enough in practice: forget it and the page simply
     * does not appear, which is noticed immediately in development and is
     * harmless if it is not. The opposite default fails silently and in the
     * dangerous direction.
     */
    public static function canAccess(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        // No `defaultSort()`, against the table floor's usual rule: the default
        // order is a composite no single column expresses (open before done,
        // then section priority, then position; done newest-first), and it is
        // the order the list is actually worked in. Sorting any column takes
        // over from it — see records().
        return $table
            ->records(fn (?string $search, array $filters, ?string $sortColumn, ?string $sortDirection, int $page, int|string $recordsPerPage): LengthAwarePaginator => $this->records($search, $filters, $sortColumn, $sortDirection, $page, $recordsPerPage))
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->formatStateUsing(fn (int $state): string => sprintf('#%03d', $state))
                    ->sortable(),
                TextColumn::make('title')
                    ->label('Title')
                    // Titles are written as prose and carry markdown — `code`,
                    // *emphasis*, **bold**. Rendered literally that is
                    // punctuation noise down two hundred rows.
                    ->formatStateUsing(fn (string $state): string => $this->inlineTitle($state))
                    ->html()
                    ->wrap()
                    ->sortable(),
                TextColumn::make('section')
                    ->label('Section')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state, array $record): string => match ($state) {
                        TodoItem::STATUS_DONE => 'Done',
                        'claimed' => 'Claimed by '.$record['holder'],
                        default => 'Available',
                    })
                    ->icon(fn (string $state): string|BackedEnum|null => match ($state) {
                        TodoItem::STATUS_DONE => static::icon('done'),
                        'claimed' => static::icon('claimed'),
                        default => static::icon('available'),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        TodoItem::STATUS_DONE => 'success',
                        'claimed' => 'warning',
                        default => 'info',
                    })
                    ->sortable(),
                TextColumn::make('closed')
                    ->label('Closed date')
                    ->date()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('file')
                    ->label('File')
                    ->fontFamily(FontFamily::Mono)
                    ->size(TextSize::ExtraSmall)
                    ->color('gray')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->reorderableColumns()
            ->filters([
                Filter::make('status')
                    ->schema([
                        CheckboxList::make('statuses')
                            ->hiddenLabel()
                            ->options(self::STATUS_LABELS)
                            // The CLI's default, as two boxes: everything that
                            // isn't done, claimed work included, because an item
                            // that vanished the moment somebody took it would
                            // read as deleted.
                            ->default(['open', 'claimed']),
                    ])
                    ->indicateUsing(function (array $data): ?Indicator {
                        $statuses = array_filter((array) ($data['statuses'] ?? []));

                        if ($statuses === [] || count($statuses) === count(self::STATUS_LABELS)) {
                            return null;
                        }

                        return Indicator::make('Status: '.collect($statuses)
                            ->map(fn (string $status): string => self::STATUS_LABELS[$status] ?? $status)
                            ->join(', ', ' or '))
                            ->removeField('statuses');
                    }),
                SelectFilter::make('section')
                    ->label('Section')
                    ->multiple()
                    ->options(fn (): array => collect(array_keys($this->sections()))
                        ->mapWithKeys(fn (string $section): array => [$section => $section])
                        ->all()),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('view')
                        ->icon(static::icon('view'))
                        ->color('gray')
                        ->modalHeading(fn (array $record): Htmlable => new HtmlString(e($record['reference']).' — '.$this->inlineTitle($record['title'])))
                        ->modalWidth(Width::FourExtraLarge)
                        // Left on, the modal focuses its footer button and the
                        // browser scrolls it into view, so a long item opens at
                        // the bottom of its own text.
                        ->modalAutofocus(false)
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Close')
                        ->schema([
                            Section::make()
                                ->schema([
                                    Grid::make(4)
                                        ->schema([
                                            TextEntry::make('section')
                                                ->label('Section')
                                                ->badge()
                                                ->color('gray'),
                                            TextEntry::make('status_label')
                                                ->label('Status')
                                                ->badge()
                                                ->color(fn (array $record): string => match ($record['status']) {
                                                    TodoItem::STATUS_DONE => 'success',
                                                    'claimed' => 'warning',
                                                    default => 'info',
                                                }),
                                            TextEntry::make('closed')
                                                ->label('Closed date')
                                                ->date(),
                                            TextEntry::make('file')
                                                ->label('File')
                                                ->fontFamily(FontFamily::Mono)
                                                ->size(TextSize::ExtraSmall)
                                                ->copyable(),
                                        ]),
                                ]),
                            Section::make('Detail')
                                ->schema([
                                    View::make('todo-items::body'),
                                ]),
                        ]),
                ]),
            ])
            ->recordAction('view')
            // The counts belong here rather than in a page subheading, which
            // Filament caps at a 42rem reading measure — a line of stats put
            // there wraps to half the page width.
            ->heading(function (): string {
                $rows = $this->rows();

                return $rows->count().' items — '.implode(', ', [
                    $rows->where('status', 'open')->count().' available',
                    $rows->where('status', 'claimed')->count().' claimed',
                    $rows->where('status', TodoItem::STATUS_DONE)->count().' done',
                ]);
            })
            ->description('Read-only — add, claim and close with sail artisan todo:new, todo:claim and todo:done.')
            ->searchable()
            ->searchPlaceholder('Search titles and bodies')
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('Nothing on the list')
            ->emptyStateDescription('No TODO item matches these filters.')
            ->emptyStateIcon(static::icon('page'));
    }

    /**
     * Every item as a table row, keyed by id.
     *
     * `status` is the *display* status rather than the frontmatter one: an open
     * item somebody is holding is a different thing to look at from one that is
     * free to pick up, and the frontmatter deliberately doesn't know about
     * claims ({@see TodoClaims}).
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function rows(): Collection
    {
        if ($this->cachedRows !== null) {
            return $this->cachedRows;
        }

        $claims = app(TodoClaims::class);

        $held = $claims->all();
        $order = array_flip(array_keys($this->sections()));

        return $this->cachedRows = app(TodoRepository::class)->all()
            ->map(function (TodoItem $item) use ($claims, $held, $order): array {
                $claim = $held[$item->id] ?? null;

                // No "you" here, unlike the CLI: the browser is never the agent
                // holding the claim, so a session key — or "the terminal" for a
                // claim taken by hand — is the only honest label.
                $holder = $claim === null
                    ? null
                    : ($claim['by'] === AgentSession::MANUAL ? 'the terminal' : $claim['by']);

                $status = match (true) {
                    $item->isDone() => TodoItem::STATUS_DONE,
                    $claim !== null => 'claimed',
                    default => 'open',
                };

                return [
                    'id' => $item->id,
                    'reference' => $item->reference(),
                    'title' => $item->title,
                    'section' => $item->section,
                    'position' => $item->position,
                    'status' => $status,
                    'status_label' => match ($status) {
                        TodoItem::STATUS_DONE => 'Done',
                        'claimed' => sprintf('Claimed by %s, %dh', $holder, $claims->ageInHours($claim)),
                        default => 'Available',
                    },
                    'holder' => $holder,
                    'closed' => $item->closed,
                    'body' => $item->body,
                    'file' => 'todo/'.$item->filename(),
                    // Section order in `_index.md` *is* the priority order, and
                    // it is what the list sorts by until someone sorts a column.
                    'section_order' => $order[$item->section] ?? PHP_INT_MAX,
                ];
            })
            ->keyBy('id');
    }

    /**
     * A title as inline HTML.
     *
     * Inline-only, so a title can never introduce a block element into a table
     * cell or a modal heading, and raw HTML is stripped rather than trusted
     * even though the source is our own repo.
     */
    protected function inlineTitle(string $title): string
    {
        return Str::inlineMarkdown($title, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function sections(): array
    {
        return $this->cachedSections ??= app(TodoRepository::class)->sections();
    }

    /**
     * The rows for the page being rendered, filtered, searched and sorted here
     * rather than in SQL — there is no query to push any of it into.
     *
     * @param  array<string, array<string, mixed>>  $filters
     */
    protected function records(?string $search, array $filters, ?string $sortColumn, ?string $sortDirection, int $page, int|string $recordsPerPage): LengthAwarePaginator
    {
        // No box ticked filters nothing, the way an empty filter always does —
        // the alternative reads as an empty list rather than an unset filter.
        $statuses = array_filter((array) ($filters['status']['statuses'] ?? []));
        $sections = array_filter((array) ($filters['section']['values'] ?? []));

        $rows = $this->rows()
            ->when($statuses !== [], fn (Collection $rows): Collection => $rows->whereIn('status', $statuses))
            ->when($sections !== [], fn (Collection $rows): Collection => $rows->whereIn('section', $sections))
            ->when(filled($search), fn (Collection $rows): Collection => $rows->filter(
                fn (array $row): bool => Str::contains(
                    $row['reference'].' '.$row['title'].' '.$row['body'],
                    (string) $search,
                    ignoreCase: true,
                ),
            ))
            ->when(
                filled($sortColumn),
                // `section` is a name, but its *order* in `_index.md` is the
                // priority, which is what sorting by it is asking for.
                fn (Collection $rows): Collection => $rows->sortBy(
                    $sortColumn === 'section' ? 'section_order' : $sortColumn,
                    descending: $sortDirection === 'desc',
                ),
                // Unsorted, the list reads the way it is worked: open items in
                // section-priority order, then done items newest-first, which is
                // the only useful order for "what shipped lately".
                fn (Collection $rows): Collection => $rows->sortBy([
                    fn (array $a, array $b): int => ($a['status'] === TodoItem::STATUS_DONE ? 1 : 0) <=> ($b['status'] === TodoItem::STATUS_DONE ? 1 : 0),
                    fn (array $a, array $b): int => $a['status'] === TodoItem::STATUS_DONE
                        ? ($b['closed'] ?? '') <=> ($a['closed'] ?? '')
                        : $a['section_order'] <=> $b['section_order'],
                    fn (array $a, array $b): int => $a['position'] <=> $b['position'],
                    fn (array $a, array $b): int => $a['id'] <=> $b['id'],
                ]),
            );

        $total = $rows->count();
        $perPage = $recordsPerPage === 'all' ? max($total, 1) : (int) $recordsPerPage;

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage),
            total: $total,
            perPage: $perPage,
            currentPage: $page,
        );
    }
}
