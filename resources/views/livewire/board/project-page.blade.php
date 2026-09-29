{{-- SB-10 single-project dashboard, design A (docs/mockups/SB-7/option-a.html, view 2 "Project: coins").
     Header with a one-project refresh, What needs me (the SB-9 cards, scoped), then Progress by initiative
     beside Not on main. Every story row opens the SB-8 modal. Live now is SB-11's panel. --}}
@php
    $ref = $model->ref;
    $stories = array_sum($counts);
    $offTotal = array_sum($offmain);
    $kindLabels = [
        \App\Models\Story::KIND_BRANCH => ['On branches', 'unmerged into '.$ref],
        \App\Models\Story::KIND_WORKTREE => ['In worktrees', 'checked out beside the main checkout'],
        \App\Models\Story::KIND_UNTRACKED => ['Untracked', 'files git does not know yet'],
    ];
    // Shades for the where-it-lives bar and its key: neutrals, since a location is not a status.
    $kindTones = [
        \App\Models\Story::KIND_BRANCH => 'bg-zinc-800 dark:bg-white',
        \App\Models\Story::KIND_WORKTREE => 'bg-zinc-500',
        \App\Models\Story::KIND_UNTRACKED => 'bg-zinc-300 dark:bg-zinc-600',
    ];
    // Any status outside the kit's four gets the danger tone in the bars, so the key names it too.
    $other = array_diff(array_keys($counts), ['draft', 'approved', 'built', 'cancelled']) !== [];
    $error = $model->last_error !== null && $model->state !== \App\Models\Project::STATE_OK ? Str::before($model->last_error, "\n") : null;
    $label = 'text-sm font-semibold tracking-wider text-zinc-500 uppercase dark:text-zinc-400';
    $panel = 'rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900';
    $page = \App\Livewire\Board\ProjectPage::INITIATIVE_PAGE;
@endphp
<main class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-10 lg:py-10">
    <header class="flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0">
            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                <a href="{{ route('home') }}" wire:navigate class="hover:underline">All projects</a> <span aria-hidden="true">/</span> {{ $model->name }}
            </p>
            <h1 class="mt-1 flex items-center gap-2 text-2xl font-semibold tracking-tight">
                <span class="truncate">{{ $model->name }}</span>
                <x-board.state :state="$model->state" title="State: {{ $model->state }}" />
                <span class="sr-only">, state {{ $model->state }}</span>
                <x-board.state :state="$model->state" part="label" />
            </h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                <span class="font-mono text-xs">{{ $model->sha ? $ref.' @ '.substr($model->sha, 0, 8) : $ref.' · no snapshot yet' }}</span>
                · {{ number_format($stories) }} {{ Str::plural('story', $stories) }}
                · {{ count($initiatives) }} {{ Str::plural('initiative', count($initiatives)) }}
            </p>
        </div>
        <div class="flex items-center gap-2 text-sm text-zinc-500 dark:text-zinc-400">
            <span data-refreshed>{{ $model->indexed_at ? 'Refreshed '.$model->indexed_at->diffForHumans() : 'Not read yet' }}</span>
            <span aria-hidden="true">·</span>
            <button type="button" data-refresh-project wire:click="refresh" wire:loading.attr="disabled" wire:target="refresh"
                class="rounded-md bg-zinc-900 px-3 py-1.5 font-medium text-white disabled:opacity-60 dark:bg-white dark:text-zinc-900">
                <span wire:loading.remove wire:target="refresh">↻ Refresh this project</span>
                <span wire:loading wire:target="refresh">Refreshing…</span>
            </button>
            <button type="button" x-data x-on:click="$flux.dark = ! $flux.dark" aria-label="Toggle dark mode"
                class="rounded-md border border-zinc-300 px-2 py-1.5 dark:border-zinc-700">◐</button>
        </div>
    </header>

    @if ($notice)
        <p role="status" class="mt-3 rounded-md border border-built/50 bg-built/10 px-3 py-2 text-sm">{{ $notice }}</p>
    @endif
    @if ($error)
        <p class="mt-3 break-words rounded-md border border-warning/50 bg-warning/10 px-3 py-2 text-sm">{{ $error }}</p>
    @endif

    {{-- The project's stories by raw status; out-of-vocabulary values in the danger tone. --}}
    @if ($stories > 0)
        <div class="mt-5 flex flex-wrap gap-2">
            @foreach ($counts as $status => $n)
                <x-board.status-chip :status="$status" :count="$n" />
            @endforeach
            @if ($parseErrors > 0)
                <span data-parse-errors="{{ $parseErrors }}" class="inline-flex items-center gap-1.5 rounded bg-danger/10 px-2 py-0.5 text-xs text-danger">
                    <span aria-hidden="true">⚠</span>{{ $parseErrors }} {{ $parseErrors === 1 ? 'story has a parse error' : 'stories have parse errors' }}
                </span>
            @endif
        </div>
    @endif

    {{-- SB-8: `?story=<project>/<ID>` opens here too. --}}
    <livewire:board.story-modal />

    {{-- 1. What needs me: the SB-9 cards, scoped to this project. --}}
    <h2 class="mt-8 {{ $label }}">What needs me</h2>
    <x-board.needs-me-cards :pick="$pick" :approval="$approval" :build="$build" :parked="$parked" :expanded="$expandedGroups"
        :page="\App\Livewire\Board\Home::PAGE" class="mt-3" />

    {{-- 2. Live now (SB-11): this project's live Claude Code sessions. It polls itself; this page does not. --}}
    <livewire:board.live-sessions :project="$model->name" :key="'live-'.$model->name" />

    <div class="mt-10 grid items-start gap-4 lg:grid-cols-3">
        {{-- 3. Progress by initiative: most open work first; the first rows show, the rest behind "Show all"
             — pure UI, every row is already on the page, so Alpine and no round trip. --}}
        <section data-initiatives aria-labelledby="progress-title" class="{{ $panel }} lg:col-span-2" x-data="{ all: false }">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="progress-title" class="font-medium">Progress by initiative</h2>
                <div class="flex flex-wrap gap-3 text-xs text-zinc-500 dark:text-zinc-400" aria-hidden="true">
                    @foreach (['built' => 'bg-built', 'approved' => 'bg-approved', 'draft' => 'bg-draft', 'cancelled' => 'bg-cancelled'] as $status => $tone)
                        <span class="flex items-center gap-1"><span class="size-2 rounded-sm {{ $tone }}"></span>{{ $status }}</span>
                    @endforeach
                    @if ($other)
                        <span class="flex items-center gap-1 text-danger"><span class="size-2 rounded-sm bg-danger"></span>other</span>
                    @endif
                </div>
            </div>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Ordered by open work — drafts and approved stories</p>

            @if ($initiatives === [])
                <p class="mt-4 rounded-lg border border-dashed border-zinc-200 px-3 py-4 text-center text-sm text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                    No stories on {{ $ref }} yet. Initiatives appear here once stories are pushed to it.
                </p>
            @else
                <ul class="mt-4 space-y-1">
                    @foreach ($initiatives as $i => $row)
                        <li data-initiative="{{ $row['name'] }}" data-open="{{ $row['open'] }}"
                            @if ($i >= $page) data-beyond x-show="all" x-cloak @endif
                            title="{{ $row['open'] }} open · {{ collect($row['counts'])->map(fn ($n, $s) => "{$s} {$n}")->join(', ') }}"
                            class="grid grid-cols-[6.5rem_1fr_4.5rem] items-center gap-3 rounded-lg px-2 py-1.5 sm:grid-cols-[10rem_1fr_7rem]">
                            <span class="flex min-w-0 items-center gap-1.5 text-sm">
                                @if ($row['name'] === null)
                                    <span class="truncate text-zinc-500 italic dark:text-zinc-400">No initiative</span>
                                @else
                                    <span class="truncate">{{ $row['name'] }}</span>
                                @endif
                                @if ($row['parked'])
                                    <span data-parked title="Its README says Status: draft group" class="shrink-0 rounded bg-cancelled/15 px-1.5 text-xs text-zinc-700 ring-1 ring-cancelled/40 ring-inset dark:text-zinc-300">parked</span>
                                @endif
                            </span>
                            <x-board.status-bar :counts="$row['counts']" />
                            <span class="text-right text-xs text-zinc-500 tabular-nums dark:text-zinc-400">
                                {{ $row['built'] }}/{{ $row['total'] }}<span class="hidden sm:inline"> built</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
                @if (count($initiatives) > $page)
                    <button type="button" data-show-all="initiatives" x-show="! all" x-on:click="all = true"
                        class="mt-3 -ml-3 rounded-lg px-3 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800">
                        Show all {{ count($initiatives) }} →
                    </button>
                @endif
            @endif
        </section>

        {{-- 4. Not on main: counted by where the work lives; a kind opens its rows below (SB-5's list, scoped). --}}
        <section data-offmain-panel aria-labelledby="offmain-title" class="{{ $panel }}">
            <h2 id="offmain-title" class="font-medium">Not on main</h2>
            @if ($offTotal === 0)
                <p class="mt-4 rounded-lg border border-dashed border-zinc-200 px-3 py-4 text-center text-sm text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                    Nothing off main. Every story version is on {{ $ref }}.
                </p>
            @else
                <p class="mt-2 text-3xl font-semibold tabular-nums">{{ number_format($offTotal) }}</p>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">story {{ Str::plural('version', $offTotal) }} outside {{ $ref }}</p>
                <div class="mt-4 flex h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800" aria-hidden="true">
                    @foreach (\App\Livewire\Board\ProjectPage::KINDS as $kind)
                        @if (($offmain[$kind] ?? 0) > 0)
                            <span class="{{ $kindTones[$kind] }}" style="width: {{ round($offmain[$kind] / $offTotal * 100, 2) }}%"></span>
                        @endif
                    @endforeach
                </div>
                <ul class="-mx-2 mt-4 space-y-1 text-sm">
                    @foreach (\App\Livewire\Board\ProjectPage::KINDS as $kind)
                        @php $n = $offmain[$kind] ?? 0; $open = in_array($kind, $openKinds, true); @endphp
                        <li>
                            <button type="button" data-offmain-kind="{{ $kind }}" data-count="{{ $n }}" @disabled($n === 0)
                                wire:click="toggleKind('{{ $kind }}')" wire:loading.attr="disabled" wire:target="toggleKind('{{ $kind }}')"
                                aria-expanded="{{ $open ? 'true' : 'false' }}" aria-controls="offmain-rows-{{ $kind }}"
                                class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left hover:bg-zinc-50 disabled:cursor-default disabled:opacity-60 disabled:hover:bg-transparent dark:hover:bg-zinc-800/50">
                                <span class="size-2 shrink-0 rounded-sm {{ $kindTones[$kind] }}" aria-hidden="true"></span>
                                <span class="flex-1">{{ $kindLabels[$kind][0] }}</span>
                                <span wire:loading wire:target="toggleKind('{{ $kind }}')" class="text-xs text-zinc-400">Loading…</span>
                                <span class="tabular-nums">{{ number_format($n) }}</span>
                                <span aria-hidden="true" class="w-3 text-zinc-400">{{ $n === 0 ? '' : ($open ? '▾' : '▸') }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    {{-- The open kinds' rows, full width so a branch name and a title both fit. --}}
    @foreach (\App\Livewire\Board\ProjectPage::KINDS as $kind)
        @if (in_array($kind, $openKinds, true))
            @php $rows = $offmainRows->get($kind, collect()); @endphp
            <x-board.section :key="'offmain-'.$kind" :title="$kindLabels[$kind][0]" :count="$rows->count()" :hint="$kindLabels[$kind][1]"
                accent="border-l-warning" id="offmain-rows-{{ $kind }}" data-offmain-rows="{{ $kind }}" class="mt-4">
                @forelse ($rows as $story)
                    <x-board.story-row :story="$story" :group="'offmain-'.$kind" />
                @empty
                    <p class="px-3 py-3 text-sm text-zinc-500 dark:text-zinc-400">None left: the last refresh moved them.</p>
                @endforelse
            </x-board.section>
        @endif
    @endforeach
</main>
