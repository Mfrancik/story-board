{{--
    A production stat's change against an earlier snapshot (SB-18): an arrow and a sign as well as colour, so the
    direction never rides on colour alone; "—" when there was no snapshot that day. Greyed with the rest of a
    last-good value. `id` goes on `data-prod-change` for tests.
--}}
@props(['change', 'suffix', 'stale' => false, 'id'])
<span data-prod-change="{{ $id }}" class="whitespace-nowrap">
    @if ($change['dir'] === 'none')
        <span class="text-zinc-400 dark:text-zinc-500" title="No snapshot that day">—</span>
    @elseif ($change['dir'] === 'flat')
        <span class="tabular-nums text-zinc-500 dark:text-zinc-400">±0</span>
    @else
        <span @class([
            'font-medium tabular-nums',
            'text-zinc-400 dark:text-zinc-500' => $stale,
            'text-gain' => ! $stale && $change['dir'] === 'up',
            'text-loss' => ! $stale && $change['dir'] === 'down',
        ])><span aria-hidden="true">{{ $change['dir'] === 'up' ? '▲' : '▼' }}</span> {{ $change['text'] }}</span>
    @endif
    <span class="text-zinc-500 dark:text-zinc-400">{{ $suffix }}</span>
</span>
