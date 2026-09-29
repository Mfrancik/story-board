{{-- SB-18 Production, option B (docs/mockups/SB-18/option-b.html): one comparison table — projects as rows, metrics
     as columns, each cell value + both changes + trend + read time — from 768 px; a card per project below it.
     A row whose queued read is outstanding is a skeleton of the same size (no layout shift); the component polls
     only while one is. Hover on a trend line is Alpine inside x-board.sparkline. --}}
@php
    $muted = 'text-zinc-500 dark:text-zinc-400';
    // Full class names, so Tailwind sees each one in source.
    $tones = [1 => 'text-series-1', 2 => 'text-series-2', 3 => 'text-series-3'];
    $dots = [1 => 'bg-series-1', 2 => 'bg-series-2', 3 => 'bg-series-3'];
    $manage = route('projects.manage');
    $sk = 'block animate-pulse rounded bg-zinc-200 dark:bg-zinc-800';
    $btn = 'inline-flex items-center gap-1.5 rounded-md border border-zinc-300 bg-white px-2.5 py-1 text-sm font-medium hover:bg-zinc-50 disabled:opacity-60 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800';
@endphp
<main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-10 lg:py-10">
    @if ($polling)
        <div wire:poll.2s="poll" class="hidden" aria-hidden="true"></div>
    @endif

    <header>
        <h1 class="text-2xl font-semibold tracking-tight">Production</h1>
        <p class="mt-1 text-sm {{ $muted }}">Read live when the page opens · a snapshot every day at 23:55 · changes compare with those snapshots</p>
    </header>

    @if ($rows === [])
        <div class="mt-6 max-w-lg rounded-xl border border-dashed border-zinc-300 bg-white px-5 py-8 text-center sm:mx-auto dark:border-zinc-700 dark:bg-zinc-900" data-prod-empty>
            <svg class="mx-auto size-8 text-zinc-300 dark:text-zinc-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/></svg>
            <p class="mt-3 font-medium">No project is connected to production</p>
            <p class="mt-1 text-sm {{ $muted }}">Give a project a read-only database user and pick its metrics. Its numbers then show here, with a daily history.</p>
            <a href="{{ $manage }}" wire:navigate class="mt-4 inline-flex rounded-md bg-accent px-3 py-1.5 text-sm font-medium text-accent-foreground">Set up on /projects</a>
        </div>
    @else
        {{-- 768 px and up: the comparison table. --}}
        <div class="mt-6 hidden overflow-hidden rounded-xl border border-zinc-200 bg-white md:block dark:border-zinc-800 dark:bg-zinc-900">
            <table class="w-full table-fixed text-sm">
                <caption class="sr-only">Production metrics by project. Changes are against the snapshot 1 day and 7 days before.</caption>
                <colgroup><col class="w-48 xl:w-56">@foreach ($columns as $c)<col>@endforeach</colgroup>
                <thead class="border-b border-zinc-200 text-left text-xs {{ $muted }} dark:border-zinc-800">
                    <tr>
                        <th scope="col" class="px-4 py-2.5 font-medium">Project</th>
                        @foreach ($columns as $c)
                            <th scope="col" class="px-3 py-2.5 font-medium">{{ $c['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @foreach ($rows as $row)
                        @php
                            $loading = $row['state'] === 'loading';
                            $failed = in_array($row['state'], ['error', 'stalled'], true);
                        @endphp
                        <tr wire:key="prod-row-{{ $row['id'] }}" data-prod-row="{{ $row['name'] }}" aria-busy="{{ $loading ? 'true' : 'false' }}"
                            @class(['bg-zinc-50/70 dark:bg-zinc-950/40' => $failed])>
                            <th scope="row" class="px-4 py-3 text-left align-top font-normal">
                                <div class="flex items-center gap-2">
                                    <span class="size-2.5 shrink-0 rounded-full {{ $dots[$row['slot']] }}" aria-hidden="true"></span>
                                    <a href="{{ route('projects.show', ['project' => $row['name']]) }}" wire:navigate class="truncate font-semibold underline-offset-4 hover:underline">{{ $row['name'] }}</a>
                                </div>
                                <x-board.prod-row-status :row="$row" />
                                <div class="mt-2">
                                    <button type="button" wire:click="refresh({{ $row['id'] }})" wire:loading.attr="disabled" wire:target="refresh({{ $row['id'] }})"
                                        @disabled($loading) aria-label="Refresh {{ $row['name'] }}" data-prod-refresh="{{ $row['name'] }}" class="{{ $btn }}">
                                        <svg @class(['size-3.5', 'animate-spin' => $loading]) viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 11a8 8 0 1 0-2.3 5.7M20 4v7h-7"/></svg>
                                        <span>{{ $loading ? 'Reading…' : 'Refresh' }}</span>
                                    </button>
                                </div>
                            </th>
                            @if ($row['metrics'] === [])
                                <td colspan="{{ max(1, count($columns)) }}" class="px-3 py-3 text-sm {{ $muted }}">
                                    No metrics switched on — <a href="{{ $manage }}" wire:navigate class="underline">pick them on /projects</a>.
                                </td>
                            @endif
                            @foreach ($row['metrics'] === [] ? [] : $columns as $c)
                                @php
                                    $stats = $c['key'] === 'custom'
                                        ? array_values(array_filter($row['metrics'], fn ($m) => $m['custom']))
                                        : (isset($row['metrics'][$c['key']]) ? [$row['metrics'][$c['key']]] : []);
                                @endphp
                                <td class="px-3 py-3 align-top">
                                    @if ($stats === [])
                                        <span class="text-sm text-zinc-400 dark:text-zinc-500">not tracked</span>
                                    @elseif ($loading)
                                        <div data-prod-skeleton="{{ $row['name'] }}" aria-hidden="true">
                                            <span class="{{ $sk }} h-6 w-14"></span><span class="{{ $sk }} mt-2 h-3 w-20"></span>
                                            <span class="{{ $sk }} mt-2 h-3 w-24"></span><span class="{{ $sk }} mt-2 h-8 w-full"></span>
                                            <span class="{{ $sk }} mt-2 h-3 w-20"></span>
                                        </div>
                                    @else
                                        <div class="space-y-4">
                                            @foreach ($stats as $stat)
                                                <x-board.prod-stat :stat="$stat" :project="$row['name']" :tone="$tones[$row['slot']]" :show-label="$c['key'] === 'custom'" />
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    @if ($notConnected->isNotEmpty())
                        <tr>
                            <th colspan="{{ count($columns) + 1 }}" scope="rowgroup" class="bg-zinc-50 px-4 py-2 text-left text-xs font-semibold tracking-wider uppercase {{ $muted }} dark:bg-zinc-950/50">Not connected</th>
                        </tr>
                        @foreach ($notConnected as $p)
                            <tr wire:key="prod-nc-{{ $p->id }}" data-prod-not-connected="{{ $p->name }}">
                                <th scope="row" class="px-4 py-3 text-left font-medium">{{ $p->name }}</th>
                                <td colspan="{{ max(1, count($columns)) }}" class="px-3 py-3 text-sm {{ $muted }}">
                                    not connected — <a href="{{ $manage }}" wire:navigate class="font-medium text-zinc-800 underline underline-offset-4 dark:text-white">set up on /projects</a>
                                </td>
                            </tr>
                        @endforeach
                    @endif
                </tbody>
            </table>
            <p class="border-t border-zinc-200 px-4 py-2 text-xs {{ $muted }} dark:border-zinc-800">
                Changes compare with the daily snapshot for the day before and 7 days before · — = no snapshot that day · each line is the last 30 days · hover a line for a day's value
            </p>
        </div>

        {{-- Below 768 px: a card per project, one line per metric, so the page never scrolls sideways. --}}
        <div class="mt-6 space-y-4 md:hidden">
            @foreach ($rows as $row)
                @php
                    $loading = $row['state'] === 'loading';
                    $failed = in_array($row['state'], ['error', 'stalled'], true);
                @endphp
                <section wire:key="prod-card-{{ $row['id'] }}" data-prod-card="{{ $row['name'] }}" aria-busy="{{ $loading ? 'true' : 'false' }}" aria-labelledby="prod-card-{{ $row['id'] }}"
                    @class(['rounded-xl border border-zinc-200 px-4 pt-3 dark:border-zinc-800', 'bg-zinc-50 dark:bg-zinc-900/60' => $failed, 'bg-white dark:bg-zinc-900' => ! $failed])>
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex min-w-0 items-center gap-2">
                            <span class="size-2.5 shrink-0 rounded-full {{ $dots[$row['slot']] }}" aria-hidden="true"></span>
                            <h2 id="prod-card-{{ $row['id'] }}" class="truncate font-semibold">{{ $row['name'] }}</h2>
                        </div>
                        <button type="button" wire:click="refresh({{ $row['id'] }})" wire:loading.attr="disabled" wire:target="refresh({{ $row['id'] }})"
                            @disabled($loading) aria-label="Refresh {{ $row['name'] }}" class="{{ $btn }}">
                            <svg @class(['size-3.5', 'animate-spin' => $loading]) viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 11a8 8 0 1 0-2.3 5.7M20 4v7h-7"/></svg>
                            <span>{{ $loading ? 'Reading…' : 'Refresh' }}</span>
                        </button>
                    </div>
                    <x-board.prod-row-status :row="$row" />
                    <ul class="mt-1 divide-y divide-zinc-200 dark:divide-zinc-800">
                        @forelse ($row['metrics'] as $stat)
                            <li>
                                @if ($loading)
                                    <div class="grid grid-cols-[minmax(0,1fr)_6rem] items-center gap-3 py-3" data-prod-skeleton="{{ $row['name'] }}" aria-hidden="true">
                                        <div><span class="{{ $sk }} h-3 w-20"></span><span class="{{ $sk }} mt-2 h-6 w-14"></span><span class="{{ $sk }} mt-2 h-3 w-28"></span></div>
                                        <span class="{{ $sk }} h-8 w-full"></span>
                                    </div>
                                @else
                                    <x-board.prod-stat layout="line" :stat="$stat" :project="$row['name']" :tone="$tones[$row['slot']]" />
                                @endif
                            </li>
                        @empty
                            <li class="py-3 text-sm {{ $muted }}">No metrics switched on — <a href="{{ $manage }}" wire:navigate class="underline">pick them on /projects</a>.</li>
                        @endforelse
                    </ul>
                </section>
            @endforeach

            @if ($notConnected->isNotEmpty())
                <section class="pt-6" aria-labelledby="prod-nc">
                    <h2 id="prod-nc" class="text-sm font-semibold tracking-wider uppercase {{ $muted }}">Not connected</h2>
                    <ul class="mt-3 divide-y divide-zinc-200 rounded-xl border border-dashed border-zinc-300 dark:divide-zinc-800 dark:border-zinc-700">
                        @foreach ($notConnected as $p)
                            <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm">
                                <span class="font-medium">{{ $p->name }}</span>
                                <span class="{{ $muted }}">not connected — <a href="{{ $manage }}" wire:navigate class="font-medium text-zinc-800 underline underline-offset-4 dark:text-white">set up on /projects</a></span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
            <p class="text-xs {{ $muted }}">— = no snapshot that day · each line is the last 30 days</p>
        </div>
    @endif
</main>
