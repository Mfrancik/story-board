{{-- How a project's file compares with the dev-standards kit (SB-14): same blob, changed, missing from the
     project, or the project's own. `dot` renders only the coloured dot, for a sub-tab. --}}
@props(['badge', 'dot' => false])
@php
    [$label, $chip, $tone] = match ($badge) {
        \App\Actions\Board\ReadHandbook::SAME => ['Same as kit', 'bg-built/10 text-zinc-800 ring-built/40 dark:text-zinc-100', 'bg-built'],
        \App\Actions\Board\ReadHandbook::CHANGED => ['Changed in this project', 'bg-warning/10 text-zinc-800 ring-warning/40 dark:text-zinc-100', 'bg-warning'],
        \App\Actions\Board\ReadHandbook::MISSING => ['Missing', 'bg-danger/10 text-danger ring-danger/40', 'bg-danger'],
        default => ['Project only', 'bg-zinc-400/10 text-zinc-600 ring-zinc-400/40 dark:text-zinc-300', 'bg-zinc-400'],
    };
@endphp
@if ($dot)
    <span class="size-1.5 rounded-full {{ $tone }}" title="{{ $label }}" aria-hidden="true"></span>
@else
    <span data-kit-badge="{{ $badge }}" {{ $attributes->class(['inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset', $chip]) }}>
        <span class="size-1.5 rounded-full {{ $tone }}" aria-hidden="true"></span>{{ $label }}
    </span>
@endif
