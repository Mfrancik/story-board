{{-- The mockup viewer's "Current" pane (SB-21): a placeholder until SB-23 fills it with the page as it is
     today. Grey on purpose — it is the "before", and it must never read as one of the options. --}}
@props(['where' => null])
<div data-current-placeholder class="grid h-full place-items-center border-2 border-dashed border-zinc-300 bg-zinc-50 p-4 text-center dark:border-zinc-700 dark:bg-zinc-900">
    <div>
        <svg class="mx-auto size-6 text-zinc-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M12 9v6M9 12h6"/></svg>
        <p class="mt-1.5 text-sm font-medium">No current version yet</p>
        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
            The page as it is today
            @if ($where) (<code class="font-mono">{{ $where }}</code>) @endif
            will show here. Compare two options instead.
        </p>
    </div>
</div>
