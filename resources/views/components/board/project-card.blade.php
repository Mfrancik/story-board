{{--
    One project's tile on the dashboard (SB-9, design A; SB-3's health card extended, not duplicated).
    The whole tile is a link to /p/{project}. It shows the on-ref story total, a stacked status bar and
    a chip per raw status (out-of-vocabulary values in the danger tone), the not-on-main count, when the
    snapshot was read, a parse-error warning, and — when the snapshot is not ok — its state and the
    first line of last_error. A project with no stories gets a designed empty state, not a blank tile.
--}}
@props(['card'])
@php
    $total = array_sum($card['counts']);
    // Only the first line: a git error runs to several, and the tile is a summary.
    $error = $card['last_error'] !== null && $card['state'] !== 'ok' ? Str::before($card['last_error'], "\n") : null;
@endphp
<a href="{{ route('projects.show', ['project' => $card['name']]) }}" wire:navigate
    data-project-card="{{ $card['name'] }}" data-story-count="{{ $total }}"
    class="block rounded-xl border border-zinc-200 bg-white p-5 hover:border-zinc-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-zinc-700">
    <span class="flex items-center justify-between gap-2">
        <span class="flex min-w-0 items-center gap-2 font-medium">
            <x-board.state :state="$card['state']" />
            <span class="truncate">{{ $card['name'] }}</span>
        </span>
        <x-board.state :state="$card['state']" part="label" />
    </span>

    @if ($total > 0)
        <span class="mt-2 block text-3xl font-semibold tabular-nums">{{ number_format($total) }}</span>
        <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ Str::plural('story', $total) }} on {{ $card['ref'] }}</span>
        <x-board.status-bar :counts="$card['counts']" class="mt-4" />
        <span class="mt-3 flex flex-wrap gap-1.5">
            @foreach ($card['counts'] as $status => $n)
                <x-board.status-chip :status="$status" :count="$n" />
            @endforeach
        </span>
    @else
        <span class="mt-4 block rounded-lg border border-dashed border-zinc-200 px-3 py-3 text-sm text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
            No stories on {{ $card['ref'] }} yet.
            {{-- A failed read explains itself in the error line below; only the other two states need a next step. --}}
            @if ($card['state'] === 'pending') Press Refresh to read it. @elseif ($card['state'] === 'ok') Stories appear here once they are pushed to it. @endif
        </span>
    @endif

    <span class="mt-4 flex flex-wrap gap-x-4 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
        <span data-offmain="{{ $card['offmain'] }}">{{ number_format($card['offmain']) }} not on main</span>
        <span>{{ $card['indexed_at'] ? 'Refreshed '.$card['indexed_at']->diffForHumans() : 'Never read' }}</span>
    </span>

    @if ($card['parse_errors'] > 0)
        <span data-parse-errors="{{ $card['parse_errors'] }}" class="mt-3 flex items-center gap-1.5 rounded bg-danger/10 px-2 py-1 text-xs text-danger">
            <span aria-hidden="true">⚠</span>
            {{ $card['parse_errors'] }} {{ $card['parse_errors'] === 1 ? 'story has a parse error' : 'stories have parse errors' }}
        </span>
    @endif
    @if ($error)
        <span class="mt-2 block break-words text-xs text-zinc-500 dark:text-zinc-400">{{ $error }}</span>
    @endif

    <span class="mt-2 block font-mono text-xs text-zinc-500 dark:text-zinc-400">
        {{ $card['sha'] ? $card['ref'].' @ '.substr($card['sha'], 0, 8) : 'no snapshot' }}
    </span>
</a>
