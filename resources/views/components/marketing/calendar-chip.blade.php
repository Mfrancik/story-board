{{-- One chip on the marketing calendar (MK-10, the prototype's `.chip`): a campaign date's posts, a one-off post, or an
     open date as a dashed ghost, coloured by state — approved (blue), drafts to approve (amber), posted (green), missed
     (red), open (amber dashes). `chip` is one BuildMarketingCalendar chip; `project` the project's name.

     A click fills the calendar's Alpine `peek` with this chip's own data (no request): the date, campaign, state, the
     campaign page's link and what "Open in studio" sends with `open-studio` — the campaign and date, or a one-off's
     post. The data is the owner's own and goes through Js::from, which escapes it for an attribute. --}}
@props(['chip', 'project'])
@php
    $campaign = $chip['campaign'];
    $state = $chip['state'];
    $words = [
        'approved' => 'Approved', 'posted' => 'Posted', 'missed' => 'Missed', 'open' => 'Open date',
        'review' => $chip['drafts'].' '.Str::plural('draft', $chip['drafts']).' to approve',
    ];
    $meta = ['approved' => 'approved', 'posted' => 'posted', 'missed' => 'missed', 'open' => 'open', 'review' => $chip['drafts'].' '.Str::plural('draft', $chip['drafts'])];
    $tone = [
        'approved' => 'border-live/50 bg-live/10',
        'posted' => 'border-go/50 bg-go/10',
        'missed' => 'border-fail/60 bg-fail/10',
        'review' => 'border-needs/50 bg-needs/10',
        'open' => 'border-dashed border-needs/60 bg-transparent text-needs',
    ][$state] ?? 'border-rim';
    $dot = ['approved' => 'bg-live', 'posted' => 'bg-go', 'missed' => 'bg-fail', 'review' => 'bg-needs', 'open' => 'border border-dashed border-needs'][$state] ?? 'bg-rim';
    $name = $campaign?->name ?? 'One-off';
    $caption = $chip['post'] ? Str::limit(trim(strtok((string) $chip['post']->caption, "\n") ?: ''), 140) : '';
    $peek = [
        'when' => $chip['at']->format('D j M · H:i'),
        'campaign' => $name,
        'state' => $words[$state] ?? $state,
        'tone' => $state,
        'caption' => $caption,
        'campaignUrl' => $campaign ? route('projects.marketing.campaign', [$project, $campaign->slug]) : null,
        'studio' => $campaign ? ['campaign' => $campaign->id, 'date' => $chip['date']] : ['post' => $chip['post']?->id],
    ];
@endphp
<button type="button" data-chip="{{ $campaign?->slug ?? 'one-off' }}" data-chip-date="{{ $chip['date'] }}" data-chip-state="{{ $state }}"
    x-on:click="peek = {{ Js::from($peek) }}"
    title="{{ $chip['at']->format('H:i') }} · {{ $name }}{{ $caption !== '' ? ' · '.$caption : '' }}"
    {{ $attributes->class(['flex w-full min-w-0 items-center gap-1.5 rounded-md border px-1.5 py-1 text-left text-xs leading-tight text-ink hover:brightness-125 outline-needs focus-visible:outline-2 focus-visible:outline-offset-1', $tone]) }}>
    <span class="size-1.5 shrink-0 rounded-full {{ $dot }}" aria-hidden="true"></span>
    <span class="shrink-0 font-mono text-ink-muted">{{ $chip['at']->format('H:i') }}</span>
    <span class="min-w-0 flex-1 truncate">{{ $name }}</span>
    <span class="shrink-0 text-ink-muted">{{ $meta[$state] ?? $state }}</span>
</button>
