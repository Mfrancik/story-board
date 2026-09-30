{{-- A story's raw status. Out-of-vocabulary values and parse errors show in the danger colour, never hidden.
     With `count` it labels a tally instead ("draft 11"), as on the SB-9 project tile.
     `variant="tag"` (SB-15 Stories page) words the kit's statuses for reading — Built, To do, Draft,
     Cancelled — gives `cancelled` a muted outline, and shows any other value grey with its raw text
     ("no status" for none). Only that page asks for it, so the danger tone everywhere else is unchanged. --}}
@props(['status', 'errors' => 0, 'count' => null, 'variant' => null])
@php
    // Unpassed, `errors` resolves to Laravel's shared ViewErrorBag, not the default 0.
    $errors = is_int($errors) ? $errors : 0;
    $tag = $variant === 'tag';
    $kind = \App\Actions\Board\ReadProjectStories::kind($status);
    $tone = match (true) {
        $status === 'draft' => 'bg-draft/15 text-zinc-800 ring-draft/40 dark:text-zinc-100',
        $status === 'approved' => 'bg-approved/15 text-zinc-800 ring-approved/40 dark:text-zinc-100',
        $status === 'built' => 'bg-built/15 text-zinc-800 ring-built/40 dark:text-zinc-100',
        $tag && $status === 'cancelled' => 'bg-transparent text-zinc-500 ring-zinc-300 dark:text-zinc-400 dark:ring-zinc-700',
        $status === 'cancelled' => 'bg-cancelled/15 text-zinc-700 ring-cancelled/40 dark:text-zinc-300',
        // A project's own word is not an error on the Stories page: grey, raw, never guessed at.
        $tag => 'bg-zinc-100 font-mono text-zinc-600 ring-zinc-300 dark:bg-zinc-800 dark:text-zinc-300 dark:ring-zinc-700',
        default => 'bg-danger/10 text-danger ring-danger/40',
    };
    $text = match (true) {
        ! $tag || $kind === 'other' => $status ?? 'no status',
        default => ['built' => 'Built', 'approved' => 'To do', 'draft' => 'Draft', 'cancelled' => 'Cancelled'][$kind],
    };
@endphp
<span data-status-chip="{{ $status }}" @if ($tag) data-tag="{{ $kind }}" @endif {{ $attributes->class(['relative inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset', $tone, 'ring-danger/60' => $errors > 0]) }}
    @if ($errors > 0) title="{{ $errors }} parse {{ Str::plural('error', $errors) }}" @endif>{{ $text }}
    {{-- Whitespace between these is dropped: the chip is inline-flex, spaced by gap. --}}
    @if ($count !== null)<span class="tabular-nums">{{ $count }}</span>@endif
    @if ($errors > 0)<span aria-hidden="true">⚠</span><span class="sr-only">, has parse errors</span>@endif
</span>
