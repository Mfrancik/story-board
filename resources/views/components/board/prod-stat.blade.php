{{--
    One production metric on /prod (SB-18, option B): value, change against yesterday and 7 days ago, 30-day trend
    and when it was read. `layout="cell"` stacks it in a table cell (768 px and up); `layout="line"` is a card's
    line below 768 px, trend beside the numbers. A failed read keeps the last good value, greyed
    (`data-prod-stale`), and a metric that failed on its own says why under it.
    `stat` is one of ListProductionDashboard's metrics; `project` names the row; `tone` is its series colour class.
--}}
@props(['stat', 'project', 'tone', 'layout' => 'cell', 'showLabel' => false])
@php
    $id = $project.':'.$stat['key'];
    $muted = 'text-zinc-500 dark:text-zinc-400';
@endphp
@if ($layout === 'line')
    <div class="grid grid-cols-[minmax(0,1fr)_6rem] items-center gap-3 py-3">
        <div class="min-w-0">
            <p class="flex items-center gap-1.5 text-xs {{ $muted }}">
                <span class="truncate">{{ $stat['label'] }}</span>
                @if ($stat['custom'])
                    <span class="rounded border border-zinc-300 px-1 text-xs font-medium tracking-wide text-zinc-500 uppercase dark:border-zinc-700">custom</span>
                @endif
            </p>
            <p data-prod-value="{{ $id }}" @if ($stat['stale']) data-prod-stale="{{ $id }}" @endif
                @class(['text-xl font-semibold tabular-nums', 'text-zinc-400 dark:text-zinc-500' => $stat['stale']])>{{ $stat['text'] }}</p>
            <p class="text-xs leading-5">
                <x-board.prod-change :change="$stat['day']" suffix="vs yest." :stale="$stat['stale']" :id="$id.':day'" />
                <span class="text-zinc-300 dark:text-zinc-600" aria-hidden="true">·</span>
                <x-board.prod-change :change="$stat['week']" suffix="vs 7 d ago" :stale="$stat['stale']" :id="$id.':week'" />
            </p>
            @if ($stat['readAgo'])
                <p class="text-xs {{ $muted }}">read {{ $stat['readAgo'] }}</p>
            @endif
            @if ($stat['error'])
                <p class="text-xs break-words text-danger">{{ $stat['error'] }}</p>
            @endif
        </div>
        <x-board.sparkline :series="$stat['series']" :tone="$stat['stale'] ? 'text-zinc-400' : $tone" :label="$project.' '.$stat['label']" />
    </div>
@else
    <div class="min-w-0">
        @if ($showLabel)
            <p class="truncate text-xs {{ $muted }}">{{ $stat['label'] }}</p>
        @endif
        <p data-prod-value="{{ $id }}" @if ($stat['stale']) data-prod-stale="{{ $id }}" @endif
            @class(['text-xl font-semibold tabular-nums', 'text-zinc-400 dark:text-zinc-500' => $stat['stale']])>{{ $stat['text'] }}</p>
        <p class="mt-0.5 text-xs leading-5"><x-board.prod-change :change="$stat['day']" suffix="vs yest." :stale="$stat['stale']" :id="$id.':day'" /></p>
        <p class="text-xs leading-5"><x-board.prod-change :change="$stat['week']" suffix="vs 7 d ago" :stale="$stat['stale']" :id="$id.':week'" /></p>
        <x-board.sparkline class="mt-1.5" :series="$stat['series']" :tone="$stat['stale'] ? 'text-zinc-400' : $tone" :label="$project.' '.$stat['label']" />
        @if ($stat['readAgo'])
            <p class="mt-1 text-xs {{ $muted }}">read {{ $stat['readAgo'] }}</p>
        @endif
        @if ($stat['error'])
            <p class="mt-1 text-xs break-words text-danger">{{ $stat['error'] }}</p>
        @endif
    </div>
@endif
