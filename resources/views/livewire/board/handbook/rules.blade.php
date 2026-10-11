{{-- Handbook › Rules (SB-14): the project's CLAUDE.md, rendered. Never compared with the kit. --}}
@if ($html === null)
    <x-board.handbook-empty :project="$project" file="CLAUDE.md">The project's operating manual appears here once it has one at the snapshot.</x-board.handbook-empty>
@else
    <div class="flex flex-wrap items-center justify-between gap-2 border-b px-5 py-3 border-edge">
        <p class="font-mono text-xs text-ink-muted">CLAUDE.md</p>
        <p class="text-xs text-ink-muted">Not compared with the kit — every project rewrites it.</p>
    </div>
    <x-board.prose :html="$html" class="px-5 py-6 sm:px-8" />
@endif
