{{-- Handbook › Runbook (SB-14): the project's docs/RUNBOOK.md, rendered whole. --}}
@if ($html === null)
    <x-board.handbook-empty :project="$project" file="docs/RUNBOOK.md">Solved bugs appear here once /document writes the first runbook entry.</x-board.handbook-empty>
@else
    <div class="border-b px-5 py-3 border-edge">
        <p class="font-mono text-xs text-ink-muted">docs/RUNBOOK.md</p>
    </div>
    <x-board.prose :html="$html" class="px-5 py-6 sm:px-8" />
@endif
