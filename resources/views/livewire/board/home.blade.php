{{-- SB-9 all-projects dashboard, design A (docs/mockups/SB-7/option-a.html, view 1). Order is the owner's
     ruling: What needs me first (three cards), then In flight, the project tiles, and the collapsible
     Not on main / Built / Parked drafts sections from SB-3 and SB-5. A row opens the SB-8 story modal.
     On /p/{project} (SB-7, interim until SB-10) the same page is pinned to one project: its name heads
     the page, and every figure, card and tile is that project's. --}}
@php
    $cards = [
        'pick' => ['title' => 'Awaiting a pick', 'hint' => 'Mockups on main with no Chosen option', 'empty' => 'No mockups waiting on a pick.', 'dot' => 'bg-pick'],
        'approval' => ['title' => 'Drafts to approve', 'hint' => $parked
            ? $parked.' '.Str::plural('draft', $parked).' in parked groups '.($parked === 1 ? 'is' : 'are').' hidden'
            : 'Draft stories, by project', 'empty' => 'Nothing to approve.', 'dot' => 'bg-draft'],
        'build' => ['title' => 'Ready to build', 'hint' => 'Approved, oldest first', 'empty' => 'Nothing approved and unbuilt.', 'dot' => 'bg-approved'],
    ];
    $stories = array_sum(array_map(fn ($p) => array_sum($p['counts']), $projects));
    $notOk = $in_flight['not_ok'];
    $select = 'rounded-md border border-zinc-300 bg-white px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900';
    $label = 'text-sm font-semibold tracking-wider text-zinc-500 uppercase dark:text-zinc-400';
@endphp
<main class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-10 lg:py-10">
    <header class="flex flex-wrap items-end justify-between gap-3">
        @if ($pinned)
            <div>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                    <a href="{{ route('home') }}" wire:navigate class="hover:underline">All projects</a> <span aria-hidden="true">/</span> {{ $pinned }}
                </p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight">{{ $pinned }}</h1>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">What needs me in this project · read from its ref</p>
            </div>
        @else
            <div>
                {{-- The first heading in <main> (owner ruling, SB-9): the page leads with what needs a decision. --}}
                <h1 class="text-2xl font-semibold tracking-tight">What needs me</h1>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    All projects · {{ count($projects) }} {{ Str::plural('project', count($projects)) }} · {{ number_format($stories) }} {{ Str::plural('story', $stories) }} on each project's ref
                </p>
            </div>
        @endif
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

    {{-- SB-8: `?story=<project>/<ID>` opens here, on / and on /p/{project}. --}}
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
    <div class="mt-4 grid items-start gap-4 md:grid-cols-3" wire:loading.class="opacity-60" wire:target="initiative,q" data-cards>
        @foreach ($cards as $key => $c)
            @php $rows = $$key; $all = in_array($key, $expandedGroups, true); @endphp
            <section data-card="{{ $key }}" data-group="{{ $key }}" aria-labelledby="card-{{ $key }}"
                class="flex flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between gap-2 px-4 pt-4">
                    <h2 id="card-{{ $key }}" class="flex items-center gap-2 font-medium">
                        <span class="size-2 rounded-full {{ $c['dot'] }}" aria-hidden="true"></span>{{ $c['title'] }}
                    </h2>
                    <span data-count="{{ $rows->count() }}" class="text-2xl font-semibold tabular-nums">{{ $rows->count() }}</span>
                </div>
                <p class="px-4 text-xs text-zinc-500 dark:text-zinc-400">{{ $c['hint'] }}</p>
                @if ($rows->isEmpty())
                    <p class="m-4 rounded-lg border border-dashed border-zinc-200 px-3 py-4 text-center text-sm text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                        {{ $c['empty'] }}@if ($filtered) <span class="block text-xs">None match the filters.</span>@endif
                    </p>
                @else
                    <div class="mt-3 divide-y divide-zinc-100 border-t border-zinc-100 dark:divide-zinc-800 dark:border-zinc-800">
                        @foreach ($all ? $rows : $rows->take(\App\Livewire\Board\Home::PAGE) as $story)
                            <x-board.story-row :story="$story" :group="$key" variant="card" />
                        @endforeach
                    </div>
                    @if (! $all && $rows->count() > \App\Livewire\Board\Home::PAGE)
                        <button type="button" data-show-all="{{ $key }}" wire:click="showAll('{{ $key }}')" wire:loading.attr="disabled" wire:target="showAll('{{ $key }}')"
                            class="m-3 mt-1 rounded-lg px-3 py-2 text-left text-sm font-medium text-zinc-700 hover:bg-zinc-100 disabled:opacity-60 dark:text-zinc-300 dark:hover:bg-zinc-800">
                            Show all {{ $rows->count() }} →
                        </button>
                    @endif
                @endif
            </section>
        @endforeach
    </div>

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
