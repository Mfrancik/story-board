{{-- The mockup viewer's "Current" pane (SB-23, filling SB-21's placeholder): the page as it is today, from
     the newest journey shot (SB-22) whose route matches the story's Where — with its capture time and commit,
     and a warning label when it is older than ReadCurrentVersion::STALE_DAYS. With no shot it says why, never
     blank: a new page (the route does not exist in the app) or a route no journey test covers yet. The empty
     states are grey and dashed on purpose — the "before" must never read as one of the options.
     $current is ReadCurrentVersion's answer; $project the project's name, for the shot route. --}}
@props(['current', 'project'])
@php
    $state = $current['state'];
    $where = $current['where'];
    $shot = $current['shot'];
    $captured = $shot['captured_at'] ?? null;
@endphp
<div data-current-pane="{{ $state }}" class="relative h-full">
    @if ($state === \App\Actions\Board\ReadCurrentVersion::SHOT)
        <div class="absolute inset-0 overflow-y-auto bg-white dark:bg-zinc-900">
            <img src="{{ route('shots.file', ['project' => $project, 'journey' => $shot['journey'], 'file' => $shot['file']]) }}"
                alt="{{ $where }} today, as its journey test saw it" class="block h-auto w-full">
        </div>
        <div class="pointer-events-none absolute inset-x-2 bottom-2 flex flex-wrap items-end gap-1">
            <span class="min-w-0 truncate rounded bg-zinc-900/80 px-1.5 py-0.5 text-xs text-white">
                Screenshot · <code class="font-mono">{{ $where }}</code> ·
                {{ $captured ? 'Captured '.$captured->copy()->utc()->format('M j, Y H:i').' UTC' : 'capture time unknown' }}@if ($shot['commit']) · <span class="font-mono">{{ \Illuminate\Support\Str::limit($shot['commit'], 7, '') }}</span>@endif
            </span>
            @if ($current['stale'])
                <span data-current-stale class="rounded bg-warning px-1.5 py-0.5 text-xs font-medium text-white">captured {{ $captured->copy()->utc()->format('M j, Y') }}, may be out of date</span>
            @endif
        </div>
    @else
        <div data-current-placeholder class="grid h-full place-items-center border-2 border-dashed border-zinc-300 bg-zinc-50 p-4 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <div>
                <svg class="mx-auto size-6 text-zinc-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M12 9v6M9 12h6"/></svg>
                @if ($state === \App\Actions\Board\ReadCurrentVersion::NEW_PAGE)
                    <p class="mt-1.5 text-sm font-medium">No current version — new page</p>
                    <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400"><code class="font-mono">{{ $where }}</code> does not exist yet. Compare two options instead.</p>
                @else
                    <p class="mt-1.5 text-sm font-medium">No journey test covers this route yet</p>
                    <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                        @if ($where)
                            No journey shot shows <code class="font-mono">{{ $where }}</code>. Compare two options instead.
                        @else
                            The story names no route, so no shot can be matched. Compare two options instead.
                        @endif
                    </p>
                @endif
            </div>
        </div>
    @endif
</div>
