{{-- Handbook › Runbook (SB-14): the project's docs/RUNBOOK.md, rendered whole. --}}
@if ($html === null)
    <x-board.handbook-empty :project="$project" file="docs/RUNBOOK.md">Solved bugs appear here once /document writes the first runbook entry.</x-board.handbook-empty>
@else
    <div class="border-b border-zinc-200 px-5 py-3 dark:border-zinc-800">
        <p class="font-mono text-xs text-zinc-500 dark:text-zinc-400">docs/RUNBOOK.md</p>
    </div>
    <x-board.prose :html="$html" class="px-5 py-6 sm:px-8" />
@endif
