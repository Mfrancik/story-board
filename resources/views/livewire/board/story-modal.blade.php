{{--
    SB-8 story modal, design A (docs/mockups/SB-7/option-a.html, view 3): centered, max-w-5xl, ~90vh,
    scrolling inside; story text on the left, a details rail on the right from 768px, one column below.

    Open/closed is Alpine (pure UI) over server state: a row or a dependency chip dispatches
    `board-story` with `<project>/<ID>`; the modal shows at once with a skeleton, and $wire.open()
    fills it. Each open pushes a history entry here (Livewire only keeps the current entry's
    `?story=` in step), and `depth` counts them: Back pops one and re-reads `?story=`, and closing
    steps back through all of them, so Escape then Back never re-opens the story. A modal opened
    straight from a link closes with $wire.close() instead, which never leaves the page.
    x-trap moves focus in (to Close, via autofocus), keeps it there, and hands it back to the row.
--}}
@php
    $chip = 'rounded-md border border-zinc-200 bg-white px-1.5 py-0.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-900';
    $label = 'text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400';
    $loading = 'open, story';
    if ($shown) {
        $project = $shown->project;
        $link = $project->name.'/'.$shown->story_id;
        $options = $shown->mockups['options'] ?? [];
        $servable = ($shown->mockups['dir'] ?? null) === "docs/mockups/{$shown->story_id}" && $options !== [] && $shown->isInGit();
        $chosen = $shown->mockups['chosen'] ?? null;
        $fullPage = route('stories.show', ['project' => $project->name, 'storyId' => $shown->story_id, ...($shown->location_kind ? ['v' => $shown->id] : [])]);
        $page = fn (int $id, bool $onRef) => route('stories.show', ['project' => $project->name, 'storyId' => $shown->story_id, ...($onRef ? [] : ['v' => $id])]);
    }
@endphp
<div x-data="{
        pending: false,
        hidden: false,
        depth: 0,
        get visible() { return ! this.hidden && (this.pending || this.$wire.rowId !== null) },
        linkIn(url) { return new URL(url).searchParams.get('story') ?? '' },
        async load(link) {
            this.pending = true;
            try { await this.$wire.open(link) } finally { this.pending = false }
        },
        show(link) {
            this.hidden = false;
            if (link === this.$wire.story && this.$wire.rowId !== null) return;
            // The entry is pushed here, before the round trip, so depth always matches history.
            const url = new URL(location.href);
            url.searchParams.set('story', link);
            history.pushState({ storyModal: link }, '', url);
            this.depth++;
            this.load(link);
        },
        close() {
            if (! this.visible) return;
            this.hidden = true;
            if (this.depth > 0) { const n = this.depth; this.depth = 0; history.go(-n) } else { this.$wire.close() }
        },
        popped() {
            this.depth = Math.max(0, this.depth - 1);
            const link = this.linkIn(location.href);
            // Hide at once when Back leaves the story, rather than after the round trip.
            this.hidden = link === '';
            if (link === this.$wire.story) return;
            link === '' ? this.$wire.close() : this.load(link);
        },
    }"
    x-on:board-story.window="show($event.detail)"
    x-on:popstate.window="popped()"
    x-on:keydown.escape.window="close()">

    @if ($refusal)
        <p role="status" data-story-refused class="mt-3 flex flex-wrap items-center gap-x-3 rounded-md border border-warning/50 bg-warning/10 px-3 py-2 text-sm">
            <span>{{ $refusal }}</span>
            <button type="button" wire:click="close" class="text-zinc-600 underline hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">Dismiss</button>
        </p>
    @endif

    <div x-show="visible" x-cloak class="fixed inset-0 z-60 flex items-end justify-center sm:items-center sm:p-6">
        <div data-story-backdrop x-on:click="close()" class="absolute inset-0 bg-zinc-950/50 backdrop-blur-xs" aria-hidden="true"></div>

        <div role="dialog" aria-modal="true" aria-labelledby="story-modal-title" x-trap.noscroll="visible"
            @if ($shown) data-story-open="{{ $link }}" @endif
            class="board-modal relative flex w-full max-w-5xl flex-col rounded-t-2xl bg-white shadow-2xl ring-1 ring-zinc-200 sm:rounded-2xl dark:bg-zinc-900 dark:ring-zinc-800">
            <header class="flex items-start gap-3 border-b border-zinc-200 px-5 py-4 sm:px-6 dark:border-zinc-800">
                <div class="min-w-0 flex-1">
                    @if ($shown)
                        <div wire:loading.remove wire:target="{{ $loading }}">
                            <div class="flex flex-wrap items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                                <span class="font-mono font-medium text-zinc-700 dark:text-zinc-300">{{ $project->name }} · {{ $shown->story_id }}</span>
                                @unless ($shown->isMockupOnly())<x-board.status-chip :status="$shown->status" :errors="count($shown->parse_errors)" />@endunless
                            </div>
                            <h2 id="story-modal-title" class="mt-1.5 text-lg font-semibold tracking-tight sm:text-xl">{{ $shown->title ?? $shown->path }}</h2>
                        </div>
                    @else
                        <h2 id="story-modal-title" class="sr-only">Loading the story</h2>
                    @endif
                    {{-- Same height as the ID line and title, so nothing jumps when the story arrives. --}}
                    <div @if ($shown) wire:loading.block wire:target="{{ $loading }}" class="hidden" @endif aria-hidden="true">
                        <div class="h-4 w-32 animate-pulse rounded bg-zinc-200 dark:bg-zinc-800"></div>
                        <div class="mt-2.5 h-6 w-2/3 animate-pulse rounded bg-zinc-200 dark:bg-zinc-800"></div>
                    </div>
                </div>
                @if ($shown)
                    <a href="{{ $fullPage }}" wire:navigate class="hidden shrink-0 items-center rounded-lg px-3 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-100 sm:inline-flex dark:text-zinc-300 dark:hover:bg-zinc-800">Open full page →</a>
                @endif
                <button type="button" autofocus aria-label="Close" x-on:click="close()" class="shrink-0 rounded-lg p-2 hover:bg-zinc-100 focus-visible:outline-2 focus-visible:outline-accent dark:hover:bg-zinc-800">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
                </button>
            </header>

            <div class="min-h-0 flex-1 overflow-y-auto">
                {{-- Skeleton while the story text is read from git: the two columns' space, reserved. --}}
                <div @if ($shown) wire:loading.grid wire:target="{{ $loading }}" @endif class="{{ $shown ? 'hidden' : 'grid' }} gap-6 px-5 py-6 sm:px-8 md:grid-cols-[1fr_20rem]" aria-hidden="true">
                    <div class="space-y-3">
                        @foreach (['w-1/4', 'w-full', 'w-11/12', 'w-4/5', 'w-1/4', 'w-full', 'w-3/4'] as $w)
                            <div class="h-4 {{ $w }} animate-pulse rounded bg-zinc-200 dark:bg-zinc-800"></div>
                        @endforeach
                    </div>
                    <div class="h-64 animate-pulse rounded-lg bg-zinc-100 dark:bg-zinc-800/60"></div>
                </div>

                @if ($shown)
                    <div wire:loading.remove wire:target="{{ $loading }}">
                        <div class="flex justify-end px-5 pt-3 text-sm sm:hidden">
                            <a href="{{ $fullPage }}" wire:navigate class="font-medium">Open full page →</a>
                        </div>

                        @if ($shown->location)
                            <p data-offmain-shown class="mx-5 mt-4 rounded-lg border border-warning/50 bg-warning/10 px-3 py-2 text-sm sm:mx-8">
                                <b>Not on main — {{ $shown->location }}</b>
                            </p>
                        @endif

                        <div data-story-grid class="grid md:grid-cols-[1fr_20rem]">
                            <article class="min-w-0 px-5 py-6 sm:px-8">
                                @if (! $shown->isInGit())
                                    <p class="text-sm text-zinc-500 dark:text-zinc-400">An untracked file, {{ $shown->location }} — not in git, so the board does not read its text. Open {{ $shown->path }} in that checkout.</p>
                                @elseif ($body === null)
                                    <p class="text-sm text-danger">This story could not be read from git at {{ substr($shown->sha, 0, 8) }}.</p>
                                @else
                                    <div class="prose prose-zinc prose-h1:hidden prose-h2:text-xs prose-h2:font-semibold prose-h2:uppercase prose-h2:tracking-wider prose-h2:text-zinc-500 prose-code:before:content-none prose-code:after:content-none max-w-none dark:prose-invert">{!! $body !!}</div>
                                @endif
                            </article>

                            <aside class="space-y-6 border-t border-zinc-200 bg-zinc-50/60 px-5 py-6 sm:px-6 md:border-t-0 md:border-l dark:border-zinc-800 dark:bg-zinc-950/40">
                                <section>
                                    <h3 class="{{ $label }}">Details</h3>
                                    <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-2.5 text-sm">
                                        <dt class="text-zinc-500 dark:text-zinc-400">Status</dt>
                                        <dd>@if ($shown->isMockupOnly())<span class="text-zinc-400">mockups only</span>@else<x-board.status-chip :status="$shown->status" :errors="count($shown->parse_errors)" />@endif</dd>
                                        <dt class="text-zinc-500 dark:text-zinc-400">Initiative</dt>
                                        <dd class="{{ $shown->initiative ? '' : 'text-zinc-400' }}">{{ $shown->initiative ?? 'none' }}</dd>
                                        <dt class="text-zinc-500 dark:text-zinc-400">Journey</dt>
                                        <dd class="{{ $shown->journey && $shown->journey !== 'none' ? '' : 'text-zinc-400' }}">{{ $shown->journey ?? 'none' }}</dd>
                                        <dt class="text-zinc-500 dark:text-zinc-400">Source date</dt>
                                        <dd class="tabular-nums {{ $shown->dated_on ? '' : 'text-zinc-400' }}">{{ $shown->dated_on?->format('Y-m-d') ?? 'none' }}</dd>
                                        <dt class="text-zinc-500 dark:text-zinc-400">Depends on</dt>
                                        <dd class="flex flex-wrap gap-1.5">
                                            @forelse ($shown->depends_on as $dep)
                                                @if (in_array($dep, $known, true))
                                                    <button type="button" data-dep="{{ $dep }}" x-on:click="show(@js($project->name.'/'.$dep))"
                                                        class="{{ $chip }} hover:border-zinc-400 focus-visible:outline-2 focus-visible:outline-accent">{{ $dep }}</button>
                                                @else
                                                    <span data-dep-missing="{{ $dep }}" class="font-mono text-xs text-zinc-500 dark:text-zinc-400" title="Not in {{ $project->name }}">{{ $dep }}</span>
                                                @endif
                                            @empty
                                                <span class="text-zinc-400">none</span>
                                            @endforelse
                                        </dd>
                                        <dt class="text-zinc-500 dark:text-zinc-400">Parse errors</dt>
                                        <dd>
                                            @if ($shown->parse_errors === [])
                                                <span class="text-zinc-400">none</span>
                                            @else
                                                <ul class="space-y-1 text-xs text-danger">
                                                    @foreach ($shown->parse_errors as $error)<li>{{ $error }}</li>@endforeach
                                                </ul>
                                            @endif
                                        </dd>
                                        <dt class="text-zinc-500 dark:text-zinc-400">Path</dt>
                                        <dd class="font-mono text-xs break-all">{{ $shown->path }}</dd>
                                        <dt class="text-zinc-500 dark:text-zinc-400">Version</dt>
                                        <dd class="font-mono text-xs break-all">{{ $shown->location_kind === null ? $project->ref.' · '.substr($shown->sha, 0, 8) : ($shown->isInGit() ? $shown->branch.' · '.substr($shown->sha, 0, 8) : $shown->location) }}</dd>
                                    </dl>
                                </section>

                                <section data-modal-mockups>
                                    <div class="flex items-baseline justify-between">
                                        <h3 class="{{ $label }}">Mockups</h3>
                                        @if ($chosen)<span class="text-xs text-zinc-500 dark:text-zinc-400">chosen: {{ strtoupper($chosen) }}</span>@endif
                                    </div>
                                    @if (! $servable)
                                        <p class="mt-2 text-sm text-zinc-400">{{ $options === [] ? 'None.' : 'Untracked, so the board cannot show them.' }}</p>
                                    @else
                                        @if (! $chosen && $gate['chosen'])
                                            {{-- ADR-009: the story records a choice the kit parser could not read as a letter (F-1). Quote it; never guess. --}}
                                            <p data-chosen-text class="mt-2 rounded-lg border border-warning/50 bg-warning/10 px-2.5 py-2 text-xs">
                                                <b>Chosen, as written in the story:</b> {{ $gate['chosen'] }}
                                                <span class="mt-1 block text-zinc-600 dark:text-zinc-400">The story parser could not read an option letter from this line, so no thumbnail is marked (backlog F-1).</span>
                                            </p>
                                        @endif
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            @foreach ($options as $option)
                                                @php $src = $shown->mockupUrl("option-{$option}.html"); @endphp
                                                <a href="{{ $src }}" target="_blank" rel="noopener" data-thumb="{{ $option }}" @if ($option === $chosen) data-chosen @endif
                                                    aria-label="Open option {{ $option }} in a new tab{{ $option === $chosen ? ' (chosen)' : '' }}"
                                                    class="block rounded-lg bg-white p-1 dark:bg-zinc-900 {{ $option === $chosen ? 'ring-2 ring-built' : 'ring-1 ring-zinc-200 opacity-80 hover:opacity-100 dark:ring-zinc-800' }}">
                                                    <span class="mockup-thumb-box block rounded bg-white">
                                                        {{-- A scaled-down live render: inert here (pointer-events off) and sandboxed like every mockup. --}}
                                                        <iframe src="{{ $src }}" sandbox="allow-scripts" loading="lazy" tabindex="-1" title="Option {{ $option }}" class="mockup-thumb"></iframe>
                                                    </span>
                                                    <span class="mt-1 flex items-center justify-between gap-1 text-xs">
                                                        <span class="font-medium">{{ strtoupper($option) }}</span>
                                                        @if ($option === $chosen)<span class="rounded-full bg-built px-1.5 text-white">chosen</span>@endif
                                                    </span>
                                                </a>
                                            @endforeach
                                        </div>
                                        <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">Compare side by side on the full page.</p>
                                    @endif
                                </section>

                                <section data-versions>
                                    <h3 class="{{ $label }}">Versions off main</h3>
                                    @if ($versions === [])
                                        <p class="mt-2 text-sm text-zinc-400">None — this is the only version of {{ $shown->story_id }}.</p>
                                    @else
                                        <ul class="mt-2 space-y-1.5 text-sm">
                                            @foreach ($versions as $v)
                                                <li><a href="{{ $page($v['id'], $v['onRef']) }}" wire:navigate class="break-words hover:underline {{ $v['onRef'] ? '' : 'text-warning' }}">{{ $v['text'] }}</a></li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </section>
                            </aside>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
