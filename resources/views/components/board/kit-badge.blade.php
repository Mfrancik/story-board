{{-- How a project's file compares with the dev-standards kit (SB-14): same blob, changed, missing from the
     project, or the project's own. `dot` renders only the coloured dot, for a sub-tab.
     RD-4: only the dark shell uses it, so it carries the shell tokens alone (layout unchanged). --}}
@props(['badge', 'dot' => false])
@php
    [$label, $chip, $tone] = match ($badge) {
        \App\Actions\Board\ReadHandbook::SAME => ['Same as kit', 'bg-built/10 ring-built/40 text-ink', 'bg-built'],
        \App\Actions\Board\ReadHandbook::CHANGED => ['Changed in this project', 'bg-warning/10 ring-warning/40 text-ink', 'bg-warning'],
        \App\Actions\Board\ReadHandbook::MISSING => ['Missing', 'bg-danger/10 text-danger ring-danger/40', 'bg-danger'],
        default => ['Project only', 'bg-raised ring-rim text-ink-soft', 'bg-ink-faint'],
    };
@endphp
@if ($dot)
    <span class="size-1.5 rounded-full {{ $tone }}" title="{{ $label }}" aria-hidden="true"></span>
@else
    <span data-kit-badge="{{ $badge }}" {{ $attributes->class(['inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset', $chip]) }}>
        <span class="size-1.5 rounded-full {{ $tone }}" aria-hidden="true"></span>{{ $label }}
    </span>
@endif
