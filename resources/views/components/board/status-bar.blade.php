{{--
    A stacked bar of story counts by raw status (SB-9). One segment per status, in the order given,
    each as wide as its share. The kit's four statuses take their own tokens; any other value — a
    project's own word, a typo, `(none)` — gets a danger-toned segment, so it is seen, not hidden.
    Used by the project tile; SB-10's initiative rows and the sidebar are meant to reuse it.
    An empty count set draws the bare track, never a missing bar.
    `variant="tag"` (SB-15 Stories page) matches status-chip's tag variant: any other value is a grey
    segment there, not danger. Nowhere else passes it.
--}}
@props(['counts', 'label' => null, 'variant' => null])
@php
    $tones = ['draft' => 'bg-draft', 'approved' => 'bg-approved', 'built' => 'bg-built', 'cancelled' => 'bg-cancelled'];
    $total = array_sum($counts);
    [$otherTone, $otherClass] = $variant === 'tag' ? ['other', 'bg-zinc-300 dark:bg-zinc-600'] : ['danger', 'bg-danger'];
    $summary = $label ?? ($total === 0 ? 'No stories' : collect($counts)->map(fn ($n, $s) => "{$s} {$n}")->join(', '));
@endphp
<div role="img" aria-label="{{ $summary }}" data-status-bar
    {{ $attributes->class(['flex h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800']) }}>
    @foreach ($counts as $status => $n)
        @continue($n <= 0)
        <span data-segment="{{ $status }}" data-tone="{{ isset($tones[$status]) ? $status : $otherTone }}" title="{{ $status }} {{ $n }}"
            class="{{ $tones[$status] ?? $otherClass }}" style="width: {{ round($n / $total * 100, 2) }}%"></span>
    @endforeach
</div>
