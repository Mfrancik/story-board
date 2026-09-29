{{--
    A stacked bar of story counts by raw status (SB-9). One segment per status, in the order given,
    each as wide as its share. The kit's four statuses take their own tokens; any other value — a
    project's own word, a typo, `(none)` — gets a danger-toned segment, so it is seen, not hidden.
    Used by the project tile; SB-10's initiative rows and the sidebar are meant to reuse it.
    An empty count set draws the bare track, never a missing bar.
--}}
@props(['counts', 'label' => null])
@php
    $tones = ['draft' => 'bg-draft', 'approved' => 'bg-approved', 'built' => 'bg-built', 'cancelled' => 'bg-cancelled'];
    $total = array_sum($counts);
    $summary = $label ?? ($total === 0 ? 'No stories' : collect($counts)->map(fn ($n, $s) => "{$s} {$n}")->join(', '));
@endphp
<div role="img" aria-label="{{ $summary }}" data-status-bar
    {{ $attributes->class(['flex h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800']) }}>
    @foreach ($counts as $status => $n)
        @continue($n <= 0)
        <span data-segment="{{ $status }}" data-tone="{{ isset($tones[$status]) ? $status : 'danger' }}" title="{{ $status }} {{ $n }}"
            class="{{ $tones[$status] ?? 'bg-danger' }}" style="width: {{ round($n / $total * 100, 2) }}%"></span>
    @endforeach
</div>
