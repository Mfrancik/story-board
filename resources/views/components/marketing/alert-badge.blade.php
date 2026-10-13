{{-- One "needs you" badge on a campaign row (MK-1, the prototype's `.nb` and `.att`): `tone` is missed (red), photo
     (violet), approve (amber), open (dashed amber), thin (amber outline, "Nothing approved past this week") or quiet
     (grey). A label, not a control: on the campaigns list the caller wraps it in the button that opens the studio (MK-9). --}}
@props(['tone' => 'approve'])
@php
    $toneClass = match ($tone) {
        'missed' => 'border-fail/60 bg-fail/10 text-fail',
        'photo' => 'border-pick/60 bg-pick/15 text-ink',
        'open' => 'border-dashed border-needs/60 text-needs',
        'thin' => 'border-needs/50 text-needs',
        'quiet' => 'border-rim text-ink-faint',
        default => 'border-needs/50 bg-needs/10 text-needs',
    };
@endphp
<span data-alert="{{ $tone }}" {{ $attributes->class(['inline-flex min-h-7 items-center self-start rounded-full border px-2.5 text-xs font-medium whitespace-nowrap', $toneClass]) }}>{{ $slot }}</span>
