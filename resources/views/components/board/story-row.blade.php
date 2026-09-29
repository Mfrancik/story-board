{{--
    One story in a home-page list. The row expands in place (owner ruling, SB-3 gate):
    open/closed is Alpine state; the story text is fetched from the ref once, on first open.
--}}
@props(['story', 'body' => null, 'group' => null])
@php
    $options = $story->mockups['options'] ?? [];
    $servable = ($story->mockups['dir'] ?? null) === "docs/mockups/{$story->story_id}" && $options !== [] && $story->isInGit();
    $errors = count($story->parse_errors);
    // Mockup-only off-main rows have no status of their own; that is not an error.
    $showStatus = ! $story->isMockupOnly() && ($errors > 0 || ! in_array($story->status, ['draft', 'approved', 'built'], true));
@endphp
<div wire:key="row-{{ $story->id }}" data-row="{{ $story->story_id }}" @if ($group === 'pick') data-pick-row="{{ $story->story_id }}" @endif
    x-data="{ open: false }">
    <button type="button" x-on:click="open = ! open; if (open && {{ $body === null ? 'true' : 'false' }}) $wire.expand({{ $story->id }})"
        x-bind:aria-expanded="open.toString()"
        class="grid w-full grid-cols-[auto_1fr] items-baseline gap-x-3 gap-y-0.5 px-3 py-2 text-left hover:bg-zinc-50 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-accent sm:grid-cols-[8rem_6rem_1fr_auto] dark:hover:bg-zinc-800/50">
        <span class="flex items-baseline gap-1.5 font-mono text-sm font-medium sm:order-2">
            <span aria-hidden="true" class="text-xs text-zinc-400" x-text="open ? '▾' : '▸'">▸</span>{{ $story->story_id ?? '—' }}
        </span>
        <span class="truncate text-right text-xs text-zinc-500 sm:order-1 sm:text-left dark:text-zinc-400">{{ $story->project->name }}</span>
        <span class="col-span-2 min-w-0 truncate text-sm sm:order-3 sm:col-span-1">
            {{ $story->title ?? $story->path }}
            @if ($group === 'pick')<span class="text-xs text-zinc-500 dark:text-zinc-400">· options {{ implode(', ', $options) }}</span>@endif
            @if ($showStatus)<x-board.status-chip :status="$story->status" :errors="$errors" class="ml-1" />@endif
        </span>
        <span class="hidden text-xs text-zinc-500 sm:order-4 sm:inline dark:text-zinc-400">{{ $story->initiative }}</span>
        @if ($story->location)
            {{-- SB-5: where this version lives, and what it says there. --}}
            <span data-offmain-row="{{ $story->story_id }}" class="col-span-2 flex flex-wrap items-center gap-1.5 text-xs text-warning sm:order-5 sm:col-span-4">
                <span class="break-all font-mono">{{ $story->location }}</span>
                @if ($story->isMockupOnly())
                    <span class="rounded bg-zinc-100 px-1.5 py-0.5 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">mockups only</span>
                @elseif ($story->status)
                    <x-board.status-chip :status="$story->status" :errors="$errors" />
                @endif
                @if ($story->mockups['chosen'] ?? null)<span class="rounded bg-zinc-100 px-1.5 py-0.5 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">picked {{ strtoupper($story->mockups['chosen']) }}</span>@endif
            </span>
        @endif
    </button>

    <div x-show="open" x-cloak class="space-y-3 border-t border-zinc-100 bg-zinc-50/60 px-3 py-3 dark:border-zinc-800 dark:bg-zinc-950/40">
        <div class="flex flex-wrap items-center gap-1.5 text-xs">
            @if ($story->status !== null || ! $story->isMockupOnly())<x-board.status-chip :status="$story->status" :errors="$errors" />@endif
            @if ($story->initiative)<span class="rounded bg-zinc-100 px-1.5 py-0.5 dark:bg-zinc-800">initiative: {{ $story->initiative }}</span>@endif
            @if ($story->journey)<span class="rounded bg-zinc-100 px-1.5 py-0.5 dark:bg-zinc-800">journey: {{ $story->journey }}</span>@endif
            @if ($story->depends_on !== [])<span class="rounded bg-zinc-100 px-1.5 py-0.5 dark:bg-zinc-800">depends on {{ implode(', ', $story->depends_on) }}</span>@endif
            <span class="font-mono text-zinc-500 dark:text-zinc-400">{{ $story->path }} @ {{ substr($story->sha, 0, 8) }}</span>
        </div>

        @if ($errors > 0)
            <ul class="rounded border border-danger/30 bg-danger/5 px-3 py-2 text-xs text-danger">
                @foreach ($story->parse_errors as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        @endif

        @if (! $story->isInGit())
            <p class="text-sm text-zinc-500 dark:text-zinc-400">An untracked file, {{ $story->location }} — not in git, so the board does not read it. Open it in that checkout.</p>
        @elseif ($body === null)
            <p class="text-sm text-zinc-500" wire:loading.remove wire:target="expand({{ $story->id }})">Opening story…</p>
            <p class="text-sm text-zinc-500" wire:loading wire:target="expand({{ $story->id }})">Reading the story from git…</p>
        @elseif ($body === '')
            <p class="text-sm text-danger">This story could not be read from git at {{ substr($story->sha, 0, 8) }}.</p>
        @else
            <div class="prose prose-sm prose-zinc prose-h1:hidden prose-h2:text-xs prose-h2:font-semibold prose-h2:uppercase prose-h2:tracking-wide prose-h2:text-zinc-500 prose-code:before:content-none prose-code:after:content-none max-h-96 max-w-none overflow-y-auto rounded border border-zinc-200 bg-white p-3 dark:prose-invert dark:border-zinc-800 dark:bg-zinc-900">{!! $body !!}</div>
        @endif

        @if ($servable)
            <div>
                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Mockups{{ $story->mockups['chosen'] ? ' · chosen '.strtoupper($story->mockups['chosen']) : '' }}</p>
                <div class="mt-1 flex gap-3 overflow-x-auto pb-1">
                    @foreach ($options as $option)
                        @php $src = $story->mockupUrl("option-{$option}.html"); @endphp
                        <a href="{{ $src }}" target="_blank" rel="noopener" class="group shrink-0" aria-label="Open option {{ $option }} in a new tab">
                            <div class="relative h-28 w-44 overflow-hidden rounded border bg-white {{ $story->mockups['chosen'] === $option ? 'border-built ring-2 ring-built' : 'border-zinc-200 dark:border-zinc-700' }}">
                                {{-- Scaled-down live render; the frame is inert here (pointer-events off) and sandboxed like every mockup. --}}
                                <template x-if="open">
                                    <iframe src="{{ $src }}" sandbox="allow-scripts" loading="lazy" tabindex="-1" title="Option {{ $option }}"
                                        class="mockup-thumb"></iframe>
                                </template>
                            </div>
                            <span class="mt-0.5 block font-mono text-xs text-zinc-600 group-hover:underline dark:text-zinc-400">option-{{ $option }} ↗</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($story->hasPage())
            <a href="{{ route('stories.show', ['project' => $story->project->name, 'storyId' => $story->story_id, ...($story->location_kind ? ['v' => $story->id] : [])]) }}" wire:navigate
                class="inline-block text-sm font-medium underline">Open full page →</a>
        @endif
    </div>
</div>
