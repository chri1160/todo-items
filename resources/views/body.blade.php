{{--
    An item body, with everything past the first few paragraphs hidden behind a
    "Show more" button.

    The hidden half is rendered and then display:none-d rather than clipped by a
    max-height: a clamped, overflowing box paints its excess over whatever sits
    below it if anything upsets the containing block — which, inside a modal
    that transitions in, it does. Nothing can escape display:none. It is also
    still one request: both halves are in the DOM, so expanding is instant.

    The split is on a paragraph boundary so each half is valid markdown on its
    own, and never inside a fenced code block.
--}}
@php
    $body = trim((string) ($record['body'] ?? ''));

    $paragraphs = preg_split('/\n{2,}/', $body) ?: [];
    $head = [];
    $tail = [];

    foreach ($paragraphs as $paragraph) {
        // Keep filling the visible half until it is long enough, then never
        // stop mid-fence — a half-open ``` would render the rest as code.
        $isOpenFence = substr_count(implode("\n\n", $head), '```') % 2 === 1;

        if ($tail === [] && ($isOpenFence || strlen(implode("\n\n", $head)) < 900)) {
            $head[] = $paragraph;

            continue;
        }

        $tail[] = $paragraph;
    }

    $head = implode("\n\n", $head);
    $tail = implode("\n\n", $tail);
@endphp

@if ($body === '')
    <p class="text-sm text-gray-500 dark:text-gray-400">
        This item has no body.
    </p>
@elseif ($tail === '')
    <div class="fi-prose">
        {!! str($head)->markdown() !!}
    </div>
@else
    <div x-data="{ expanded: false }">
        <div class="fi-prose">
            {!! str($head)->markdown() !!}
        </div>

        <div class="fi-prose" x-show="expanded" style="display: none">
            {!! str($tail)->markdown() !!}
        </div>

        <button
            type="button"
            x-on:click="expanded = ! expanded"
            class="mt-3 text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
        >
            {{-- Server-rendered as the collapsed label so there is no flash of
                 the wrong one before Alpine takes over. --}}
            <span x-text="expanded ? 'Show less' : 'Show more'">Show more</span>
        </button>
    </div>
@endif
