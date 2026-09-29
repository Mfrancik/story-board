{{-- SB-3 home — mockup A (Inbox), rows expand in place, Built / Parked drafts collapsible. --}}
@php
    $groups = [
        'approval' => ['title' => 'Awaiting approval', 'hint' => 'draft stories', 'empty' => 'Nothing waiting for approval.', 'accent' => 'border-l-draft',
            'note' => $parked ? $parked.' '.Str::plural('draft', $parked).' in parked groups '.($parked === 1 ? 'is' : 'are').' hidden' : null],
        'pick' => ['title' => 'Awaiting a mockup pick', 'hint' => 'mockups on main, no Chosen option', 'empty' => 'No mockups waiting on a pick.', 'accent' => 'border-l-pick', 'note' => null],
        'build' => ['title' => 'Ready to build', 'hint' => 'approved, oldest first', 'empty' => 'Nothing approved and unbuilt.', 'accent' => 'border-l-approved', 'note' => null],
    ];
    $select = 'rounded-md border border-zinc-300 bg-white px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900';
@endphp
<main class="mx-auto max-w-6xl px-4 py-6">
    <header class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">What needs me</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Across {{ count($projects) }} {{ Str::plural('project', count($projects)) }} · read from origin/main</p>
        </div>
        <div class="flex items-center gap-2">
            <button type="button" x-data x-on:click="$flux.dark = ! $flux.dark" aria-label="Toggle dark mode"
                class="rounded-md border border-zinc-300 px-2 py-1.5 text-sm dark:border-zinc-700">◐</button>
            <button type="button" wire:click="refresh" wire:loading.attr="disabled" wire:target="refresh"
                class="rounded-md bg-zinc-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-60 dark:bg-white dark:text-zinc-900">
                <span wire:loading.remove wire:target="refresh">↻ Refresh</span>
                <span wire:loading wire:target="refresh">Refreshing…</span>
            </button>
        </div>
    </header>

    <div class="mt-4 flex flex-wrap items-center gap-2" role="search">
        <label class="sr-only" for="f-project">Project</label>
        <select id="f-project" wire:model.live="project" class="{{ $select }}">
            <option value="">All projects</option>
            @foreach ($projectNames as $name)<option value="{{ $name }}">{{ $name }}</option>@endforeach
        </select>
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
            <x-board.status-chip :status="$story->status" :errors="count($story->parse_errors)" />, so it is in none of the groups below.
            @if (Route::has('stories.show'))
                <a href="{{ route('stories.show', ['project' => $story->project->name, 'storyId' => $story->story_id]) }}" wire:navigate class="font-medium underline">Go to {{ $story->story_id }} →</a>
            @endif
        </p>
    @endforeach

    <div class="mt-4 space-y-4" wire:loading.class="opacity-60" wire:target="project,initiative,q">
        @foreach ($groups as $key => $g)
            @php $rows = $$key; $all = in_array($key, $expandedGroups, true); @endphp
            <x-board.section :key="$key" :title="$g['title']" :count="$rows->count()" :hint="$g['hint']" :note="$g['note']" :accent="$g['accent']">
                @forelse ($all ? $rows : $rows->take(\App\Livewire\Board\Home::PAGE) as $story)
                    <x-board.story-row :story="$story" :body="$bodies[$story->id] ?? null" :group="$key" />
                @empty
                    <p class="px-3 py-3 text-sm text-zinc-500 dark:text-zinc-400">{{ $g['empty'] }}</p>
                @endforelse
                @if (! $all && $rows->count() > \App\Livewire\Board\Home::PAGE)
                    <button type="button" wire:click="showAll('{{ $key }}')" class="w-full px-3 py-2 text-left text-sm text-zinc-500 hover:text-zinc-900 dark:hover:text-white">
                        Show {{ $rows->count() - \App\Livewire\Board\Home::PAGE }} more
                    </button>
                @endif
            </x-board.section>
        @endforeach

        @foreach (['built' => ['Built', $built, 'border-l-built', 'on origin/main'], 'parked' => ['Parked drafts', $parked, 'border-l-cancelled', 'in a draft group, not in the build queue']] as $key => [$title, $count, $accent, $hint])
            @php $open = in_array($key, $openSections, true); $rows = $sections[$key] ?? collect(); $all = in_array($key, $expandedGroups, true); @endphp
            <x-board.section :key="$key" :title="$title" :count="$count" :hint="$hint" :accent="$accent" collapsible :open="$open">
                @forelse ($all ? $rows : $rows->take(50) as $story)
                    <x-board.story-row :story="$story" :body="$bodies[$story->id] ?? null" :group="$key" />
                @empty
                    <p class="px-3 py-3 text-sm text-zinc-500 dark:text-zinc-400">None{{ $filtered ? ' match the filters' : '' }}.</p>
                @endforelse
                @if (! $all && $rows->count() > 50)
                    <button type="button" wire:click="showAll('{{ $key }}')" class="w-full px-3 py-2 text-left text-sm text-zinc-500 hover:text-zinc-900 dark:hover:text-white">
                        Show {{ $rows->count() - 50 }} more
                    </button>
                @endif
            </x-board.section>
        @endforeach
    </div>

    <h2 class="mt-8 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Projects</h2>
    <div class="mt-2 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @forelse ($projects as $card)
            <x-board.project-card :card="$card" />
        @empty
            <p class="text-sm text-zinc-500">No projects registered. Run <code>php artisan board:project add &lt;path&gt;</code>.</p>
        @endforelse
    </div>
</main>
