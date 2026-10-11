{{-- A handbook section of a project the board has not read yet (no snapshot SHA to read at). --}}
<div data-unread class="px-5 py-14 text-center">
    <p class="font-medium">{{ $project }} has not been read yet</p>
    <p class="mt-1 text-sm text-ink-muted">The handbook reads the project at its snapshot. The board refreshes it within a few minutes of a page load, or run <code class="font-mono">php artisan board:refresh {{ $project }}</code>. Then come back.</p>
</div>
