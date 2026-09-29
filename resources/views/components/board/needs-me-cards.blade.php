{{--
    The three What needs me cards (SB-9, design A): Awaiting a pick, Drafts to approve, Ready to build.
    Side by side from 768px, stacked below. Each has its count, its first `page` rows and "Show all N",
    which calls the host component's `showAll('<card>')`. Rows open the SB-8 modal (board/story-row).
    Shared by the all-projects page (Home) and the project page (SB-10, scoped to one project), so both
    render the same cards from the same ListWhatNeedsMe groups.
--}}
@props(['pick', 'approval', 'build', 'parked' => 0, 'expanded' => [], 'filtered' => false, 'page' => 5])
@php
    $cards = [
        'pick' => ['title' => 'Awaiting a pick', 'hint' => 'Mockups on main with no Chosen option', 'empty' => 'No mockups waiting on a pick.', 'dot' => 'bg-pick', 'rows' => $pick],
        'approval' => ['title' => 'Drafts to approve', 'hint' => $parked
            ? $parked.' '.Str::plural('draft', $parked).' in parked groups '.($parked === 1 ? 'is' : 'are').' hidden'
            : 'Draft stories, by project', 'empty' => 'Nothing to approve.', 'dot' => 'bg-draft', 'rows' => $approval],
        'build' => ['title' => 'Ready to build', 'hint' => 'Approved, oldest first', 'empty' => 'Nothing approved and unbuilt.', 'dot' => 'bg-approved', 'rows' => $build],
    ];
@endphp
<div {{ $attributes->class(['grid items-start gap-4 md:grid-cols-3']) }} data-cards>
    @foreach ($cards as $key => $c)
        @php $rows = $c['rows']; $all = in_array($key, $expanded, true); @endphp
        <section data-card="{{ $key }}" data-group="{{ $key }}" aria-labelledby="card-{{ $key }}"
            class="flex flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex items-center justify-between gap-2 px-4 pt-4">
                <h2 id="card-{{ $key }}" class="flex items-center gap-2 font-medium">
                    <span class="size-2 rounded-full {{ $c['dot'] }}" aria-hidden="true"></span>{{ $c['title'] }}
                </h2>
                <span data-count="{{ $rows->count() }}" class="text-2xl font-semibold tabular-nums">{{ $rows->count() }}</span>
            </div>
            <p class="px-4 text-xs text-zinc-500 dark:text-zinc-400">{{ $c['hint'] }}</p>
            @if ($rows->isEmpty())
                <p class="m-4 rounded-lg border border-dashed border-zinc-200 px-3 py-4 text-center text-sm text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                    {{ $c['empty'] }}@if ($filtered) <span class="block text-xs">None match the filters.</span>@endif
                </p>
            @else
                <div class="mt-3 divide-y divide-zinc-100 border-t border-zinc-100 dark:divide-zinc-800 dark:border-zinc-800">
                    @foreach ($all ? $rows : $rows->take($page) as $story)
                        <x-board.story-row :story="$story" :group="$key" variant="card" />
                    @endforeach
                </div>
                @if (! $all && $rows->count() > $page)
                    <button type="button" data-show-all="{{ $key }}" wire:click="showAll('{{ $key }}')" wire:loading.attr="disabled" wire:target="showAll('{{ $key }}')"
                        class="m-3 mt-1 rounded-lg px-3 py-2 text-left text-sm font-medium text-zinc-700 hover:bg-zinc-100 disabled:opacity-60 dark:text-zinc-300 dark:hover:bg-zinc-800">
                        Show all {{ $rows->count() }} →
                    </button>
                @endif
            @endif
        </section>
    @endforeach
</div>
