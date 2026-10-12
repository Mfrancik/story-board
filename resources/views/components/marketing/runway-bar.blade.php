{{-- A campaign's approval runway (MK-1, the prototype's `.rw`): one small block per upcoming date, coloured by its state —
     approved or posted (blue), drafts to approve (amber), open (amber outline), skipped (grey) — then "2 of 6 approved".
     `runway` is BuildCampaignRunway's list of {at, state}; `approved` its count. With `legend` it renders the key to
     those colours instead, from the same map, so the key cannot drift from the bar. --}}
@props(['runway' => [], 'approved' => 0, 'legend' => false])
@php
    $tone = [
        'approved' => 'bg-live',
        'posted' => 'bg-live',
        'review' => 'bg-needs',
        'open' => 'ring-1 ring-needs/60 ring-inset',
        'skipped' => 'bg-rim',
    ];
    $words = ['approved' => 'approved', 'posted' => 'posted', 'review' => 'drafts to approve', 'open' => 'open', 'skipped' => 'skipped'];
    $block = 'inline-block h-2 w-4 shrink-0 rounded-xs';
@endphp
@if ($legend)
    <span {{ $attributes->class('inline-flex flex-wrap items-center gap-x-3 gap-y-1') }}>
        @foreach (['approved' => 'approved', 'review' => 'needs approval', 'open' => 'open', 'skipped' => 'skipped'] as $state => $word)
            <span class="inline-flex items-center gap-1.5"><span data-runway-key="{{ $state }}" class="{{ $block }} {{ $tone[$state] }}" aria-hidden="true"></span>{{ $word }}</span>
        @endforeach
    </span>
@else
    <span {{ $attributes->class('inline-flex flex-wrap items-center gap-2') }}>
        <span class="inline-flex gap-0.75" role="img" aria-label="{{ $approved }} of {{ count($runway) }} upcoming dates approved">
            @foreach ($runway as $date)
                <span data-runway-state="{{ $date['state'] }}" title="{{ $date['at']->format('D j M') }} · {{ $words[$date['state']] ?? $date['state'] }}"
                    class="{{ $block }} {{ $tone[$date['state']] ?? 'bg-edge' }}"></span>
            @endforeach
        </span>
        <span class="font-mono text-xs text-ink-muted">{{ $approved }} of {{ count($runway) }} approved</span>
    </span>
@endif
