{{-- Handbook › Rules (SB-14): the project's CLAUDE.md, rendered. Never compared with the kit. --}}
@if ($html === null)
    <x-board.handbook-empty :project="$project" file="CLAUDE.md">The project's operating manual appears here once it has one at the snapshot.</x-board.handbook-empty>
@else
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 px-5 py-3 dark:border-zinc-800">
        <p class="font-mono text-xs text-zinc-500 dark:text-zinc-400">CLAUDE.md</p>
        <p class="text-xs text-zinc-500 dark:text-zinc-400">Not compared with the kit — every project rewrites it.</p>
    </div>
    <x-board.prose :html="$html" class="px-5 py-6 sm:px-8" />
@endif
