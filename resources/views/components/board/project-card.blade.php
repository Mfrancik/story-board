{{-- One project's health on the home page: counts by raw status, parse errors, and snapshot state (SB-3). --}}
@props(['card'])
@php
    $total = array_sum($card['counts']);
    $bar = ['draft' => 'bg-draft', 'approved' => 'bg-approved', 'built' => 'bg-built', 'cancelled' => 'bg-cancelled'];
@endphp
<div data-project-card="{{ $card['name'] }}" data-story-count="{{ $total }}"
    class="rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900">
    <div class="flex items-baseline justify-between gap-2">
        <h3 class="font-semibold">{{ $card['name'] }}</h3>
        @if ($card['state'] === 'unreachable')
            <span class="text-xs font-medium text-danger">Unreachable</span>
        @elseif ($card['state'] === 'stale')
            <span class="text-xs font-medium text-warning">Stale</span>
        @elseif ($card['state'] === 'pending')
            <span class="text-xs font-medium text-zinc-500">Not read yet</span>
        @endif
        <span class="text-xs tabular-nums text-zinc-500 dark:text-zinc-400">{{ $total }} {{ Str::plural('story', $total) }}</span>
    </div>

    @if ($total > 0)
        <div class="mt-2 flex h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800" aria-hidden="true">
            @foreach ($card['counts'] as $status => $n)
                <span class="{{ $bar[$status] ?? 'bg-danger' }}" style="width: {{ round($n / $total * 100, 2) }}%"></span>
            @endforeach
        </div>
        <dl class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-zinc-600 dark:text-zinc-400">
            @foreach ($card['counts'] as $status => $n)
                <div><dt class="inline">{{ $status }}</dt> <dd class="inline font-medium tabular-nums text-zinc-900 dark:text-zinc-100">{{ $n }}</dd></div>
            @endforeach
        </dl>
    @endif

    @if ($card['parse_errors'] > 0)
        <p class="mt-2 rounded bg-danger/10 px-2 py-1 text-xs text-danger">⚠ {{ $card['parse_errors'] }} {{ $card['parse_errors'] === 1 ? 'story has a parse error' : 'stories have parse errors' }}</p>
    @endif
    @if ($card['last_error'] && $card['state'] !== 'ok')
        <p class="mt-2 break-words text-xs text-zinc-500 dark:text-zinc-400">{{ $card['last_error'] }}</p>
    @endif

    <p class="mt-2 font-mono text-xs text-zinc-500 dark:text-zinc-400">
        {{ $card['sha'] ? 'origin/main @ '.substr($card['sha'], 0, 8) : 'no snapshot' }}
        · {{ $card['indexed_at']?->diffForHumans() ?? 'never read' }}
    </p>
</div>
