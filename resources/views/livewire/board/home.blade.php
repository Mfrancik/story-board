{{-- SB-9 all-projects dashboard, design A (docs/mockups/SB-7/option-a.html, view 1). Order is the owner's
     ruling: What needs me first (three cards), then In flight, the project tiles, and the collapsible
     Not on main / Built / Parked drafts sections from SB-3 and SB-5. A row opens the SB-8 story modal.
     One project's page is its own component since SB-10 (livewire/board/project-page). --}}
@php
    $stories = array_sum(array_map(fn ($p) => array_sum($p['counts']), $projects));
    $notOk = $in_flight['not_ok'];
    $select = 'rounded-md border border-zinc-300 bg-white px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900';
    $label = 'text-sm font-semibold tracking-wider text-zinc-500 uppercase dark:text-zinc-400';
@endphp
<main class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-10 lg:py-10">
    <header class="flex flex-wrap items-end justify-between gap-3">
        <div>
            {{-- The first heading in <main> (owner ruling, SB-9): the page leads with what needs a decision. --}}
            <h1 class="text-2xl font-semibold tracking-tight">What needs me</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                All projects · {{ count($projects) }} {{ Str::plural('project', count($projects)) }} · {{ number_format($stories) }} {{ Str::plural('story', $stories) }} on each project's ref
            </p>
        </div>
        <div class="flex items-center gap-2 text-sm text-zinc-500 dark:text-zinc-400">
            <span data-refreshed>{{ $refreshedAt ? 'Refreshed '.$refreshedAt->diffForHumans() : 'Not read yet' }}</span>
            <span aria-hidden="true">·</span>
            <button type="button" wire:click="refresh" wire:loading.attr="disabled" wire:target="refresh"
                class="rounded-md bg-zinc-900 px-3 py-1.5 font-medium text-white disabled:opacity-60 dark:bg-white dark:text-zinc-900">
                <span wire:loading.remove wire:target="refresh">↻ Refresh</span>
                <span wire:loading wire:target="refresh">Refreshing…</span>
            </button>
            <button type="button" x-data x-on:click="$flux.dark = ! $flux.dark" aria-label="Toggle dark mode"
                class="rounded-md border border-zinc-300 px-2 py-1.5 dark:border-zinc-700">◐</button>
        </div>
    </header>

    @if ($notice)
        <p role="status" class="mt-3 rounded-md border border-built/50 bg-built/10 px-3 py-2 text-sm">{{ $notice }}</p>
    @endif

    {{-- SB-8: `?story=<project>/<ID>` opens here (and on the project page, SB-10). --}}
    <livewire:board.story-modal />

    {{-- The project filter is gone (SB-9): the sidebar switches project. These narrow the cards and sections. --}}
    <div class="mt-4 flex flex-wrap items-center gap-2" role="search">
        <label class="sr-only" for="f-initiative">Initiative</label>
        <select id="f-initiative" wire:model.live="initiative" class="{{ $select }} max-w-48">
            <option value="">All initiatives</option>
            @foreach ($initiatives as $name)<option value="{{ $name }}">{{ $name }}</option>@endforeach
        </select>
        <label class="sr-only" for="f-q">Search ID or title</label>
        <input id="f-q" type="search" wire:model.live.debounce.300ms="q" placeholder="Search ID or title"
            class="{{ $select }} min-w-0 basis-full sm:basis-56">
        @if ($filtered)
            <button type="button" wire:click="clearFilters" class="text-sm text-zinc-500 underline hover:text-zinc-900 dark:hover:text-white">Clear filters</button>
        @endif
    </div>

    @foreach ($goto as $story)
        <p data-goto="{{ $story->story_id }}" class="mt-3 rounded-md border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-800 dark:bg-zinc-900">
            <span class="font-mono font-medium">{{ $story->story_id }}</span> in {{ $story->project->name }} is
            <x-board.status-chip :status="$story->status" :errors="count($story->parse_errors)" />, so it is in none of the cards below.
            <a href="{{ route('stories.show', ['project' => $story->project->name, 'storyId' => $story->story_id]) }}" wire:navigate class="font-medium underline">Go to {{ $story->story_id }} →</a>
        </p>
    @endforeach

    {{-- 1. What needs me: three cards, stacked below 768px. --}}
    <x-board.needs-me-cards :pick="$pick" :approval="$approval" :build="$build" :parked="$parked" :expanded="$expandedGroups"
        :filtered="$filtered" :page="\App\Livewire\Board\Home::PAGE" class="mt-4" wire:loading.class="opacity-60" wire:target="initiative,q" />

    {{-- 2. Live now: SB-11 renders its slot here. Not rendered until then. --}}

    {{-- 3. In flight: summed from the project tiles below, so it follows the same enabled-only rule. --}}
    <h2 class="mt-10 {{ $label }}">In flight</h2>
    <dl class="mt-3 grid gap-4 sm:grid-cols-3">
        @foreach ([
            'approved' => ['Approved, not built', $in_flight['approved'], null],
            'offmain' => ['Versions not on main', $in_flight['offmain'], 'on branches, in worktrees or untracked'],
            'not-ok' => ['Projects not ok', count($notOk), $notOk ? implode(', ', $notOk) : 'every snapshot is current'],
        ] as $key => [$title, $n, $sub])
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ $title }}</dt>
                <dd data-figure="{{ $key }}" class="mt-1 text-3xl font-semibold tabular-nums {{ $key === 'not-ok' && $n > 0 ? 'text-danger' : '' }}">{{ number_format($n) }}</dd>
                @if ($sub)<dd class="mt-1 truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $sub }}</dd>@endif
            </div>
        @endforeach
    </dl>

    {{-- 4. Projects: one tile per enabled project, each a link to its page. --}}
    <h2 class="mt-10 {{ $label }}">Projects</h2>
    <div class="mt-3 grid gap-4 sm:grid-cols-2">
        @forelse ($projects as $card)
            <x-board.project-card :card="$card" />
        @empty
            <p class="rounded-xl border border-dashed border-zinc-200 p-5 text-sm text-zinc-500 sm:col-span-2 dark:border-zinc-800 dark:text-zinc-400">
                No projects registered. Run <code>php artisan board:project add &lt;path&gt;</code>.
            </p>
        @endforelse
    </div>

    {{-- 5. The collapsible sections from SB-3 and SB-5, unchanged. --}}
    <div class="mt-10 space-y-4" wire:loading.class="opacity-60" wire:target="initiative,q">
        @foreach ([
            'offmain' => ['Not on main', $offmain, 'border-l-warning', 'on unmerged branches, in worktrees, or untracked — each labelled with where it lives'],
            'built' => ['Built', $built, 'border-l-built', "on each project's ref"],
            'parked' => ['Parked drafts', $parked, 'border-l-cancelled', 'in a draft group, not in the build queue'],
        ] as $key => [$title, $count, $accent, $hint])
            @php $open = in_array($key, $openSections, true); $rows = $sections[$key] ?? collect(); $all = in_array($key, $expandedGroups, true); @endphp
            <x-board.section :key="$key" :title="$title" :count="$count" :hint="$hint" :accent="$accent" collapsible :open="$open">
                @forelse ($all ? $rows : $rows->take(\App\Livewire\Board\Home::SECTION_PAGE) as $story)
                    <x-board.story-row :story="$story" :group="$key" />
                @empty
                    <p class="px-3 py-3 text-sm text-zinc-500 dark:text-zinc-400">None{{ $filtered ? ' match the filters' : '' }}.</p>
                @endforelse
                @if (! $all && $rows->count() > \App\Livewire\Board\Home::SECTION_PAGE)
                    <button type="button" wire:click="showAll('{{ $key }}')" class="w-full px-3 py-2 text-left text-sm text-zinc-500 hover:text-zinc-900 dark:hover:text-white">
                        Show {{ $rows->count() - \App\Livewire\Board\Home::SECTION_PAGE }} more
                    </button>
                @endif
            </x-board.section>
        @endforeach
    </div>
</main>
