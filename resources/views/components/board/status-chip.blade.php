{{-- A story's raw status. Out-of-vocabulary values and parse errors show in the danger colour, never hidden. --}}
@props(['status', 'errors' => 0])
@php
    $tone = match ($status) {
        'draft' => 'bg-draft/15 text-zinc-800 ring-draft/40 dark:text-zinc-100',
        'approved' => 'bg-approved/15 text-zinc-800 ring-approved/40 dark:text-zinc-100',
        'built' => 'bg-built/15 text-zinc-800 ring-built/40 dark:text-zinc-100',
        'cancelled' => 'bg-cancelled/15 text-zinc-700 ring-cancelled/40 dark:text-zinc-300',
        default => 'bg-danger/10 text-danger ring-danger/40',
    };
@endphp
<span data-status-chip="{{ $status }}" {{ $attributes->class(['relative inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset', $tone, 'ring-danger/60' => $errors > 0]) }}
    @if ($errors > 0) title="{{ $errors }} parse {{ Str::plural('error', $errors) }}" @endif>
    {{ $status ?? 'no status' }}@if ($errors > 0)<span aria-hidden="true">⚠</span><span class="sr-only">, has parse errors</span>@endif
</span>
