{{--
    SB-21 mockup gallery, option A (docs/mockups/SB-21/option-a.html, "Gallery"): cards with a live
    thumbnail, grouped by project, awaiting-pick first; status, project and search filters above.
    Every set is in this markup once and the filters are Alpine (mockupGallery in resources/js), so
    nothing here calls the server. A group shows its first PER_GROUP cards until "Show all": each card
    is a live frame, and coins alone has 263 sets. Hidden cards' lazy frames do not load.
    RD-4: in the RD-2 dark shell (layouts/shell), restyled with its tokens; layout and behaviour unchanged.
--}}
@php
    $perGroup = 12;
    $meta = collect($groups)->flatten(1)->map(fn ($s) => ['project' => $s['project'], 'story' => $s['story'], 'title' => $s['title'], 'state' => $s['state']])->values();
    $count = fn (string $state) => $meta->where('state', $state)->count();
    $filters = ['all' => ['All', $total], 'awaiting' => ['Awaiting pick', $count('awaiting')], 'picked' => ['Picked', $count('picked')], 'other' => ['Other', $count('other')]];
    $seg = 'inline-flex shrink-0 rounded-lg border p-0.5 border-edge bg-surface';
    $segBtn = 'rounded-md px-2.5 py-1 text-xs font-medium whitespace-nowrap';
    $on = "bg-ink text-ground";
    $off = "text-ink-muted hover:text-ink";
@endphp
<main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-10 lg:py-10" x-data="mockupGallery(@js($project), {{ $perGroup }}, @js($meta))">
    <header>
        <p class="font-mono text-xs text-ink-muted">/mockups</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight">Mockups</h1>
        <p class="mt-1 text-sm text-ink-muted">
            Every story's design options, from every project.
            @if ($awaiting > 0)<strong class="font-medium text-ink">{{ $awaiting }} waiting for your pick.</strong>@endif
        </p>
    </header>

    @if ($total === 0)
        <div data-mockups-empty class="mt-8 rounded-xl border border-dashed p-10 text-center border-rim">
            <p class="font-medium">No mockups yet</p>
            <p class="mt-1 text-sm text-ink-muted">
                A story's mockups come from its project's <code class="font-mono">docs/mockups/&lt;ID&gt;/</code> folder at the ref —
                <code class="font-mono">option-a.html</code>, <code class="font-mono">option-b.html</code>, … — drawn at the story's mockup gate.
            </p>
            <a href="{{ route('home') }}" wire:navigate class="mt-4 inline-flex rounded-md border px-3 py-1.5 text-sm font-medium border-rim bg-surface hover:bg-raised">Back to all projects</a>
        </div>
    @else
        <div class="mt-5 flex flex-wrap items-center gap-2">
            <div role="group" aria-label="Pick state" class="{{ $seg }}">
                @foreach ($filters as $key => [$label, $n])
                    <button type="button" data-filter-status="{{ $key }}" x-on:click="status = @js($key)" x-bind:aria-pressed="(status === @js($key)).toString()"
                        class="{{ $segBtn }}" x-bind:class="status === @js($key) ? @js($on) : @js($off)">{{ $label }} <span class="tabular-nums opacity-60">{{ $n }}</span></button>
                @endforeach
            </div>
            @if (count($groups) > 1)
                <div role="group" aria-label="Project" class="{{ $seg }} max-w-full overflow-x-auto">
                    <button type="button" x-on:click="project = ''" x-bind:aria-pressed="(project === '').toString()" class="{{ $segBtn }}" x-bind:class="project === '' ? @js($on) : @js($off)">All projects</button>
                    @foreach (array_keys($groups) as $name)
                        <button type="button" data-filter-project="{{ $name }}" x-on:click="project = @js($name)" x-bind:aria-pressed="(project === @js($name)).toString()"
                            class="{{ $segBtn }}" x-bind:class="project === @js($name) ? @js($on) : @js($off)">{{ $name }}</button>
                    @endforeach
                </div>
            @endif
            <label class="w-full sm:ml-auto sm:w-64">
                <span class="sr-only">Find a mockup set</span>
                <input type="search" x-model="q" placeholder="Story ID or title" autocomplete="off"
                    class="w-full rounded-lg border px-3 py-1.5 text-sm placeholder:text-ink-faint border-edge bg-surface">
            </label>
        </div>

        @foreach ($groups as $name => $sets)
            @php $groupAwaiting = collect($sets)->where('state', 'awaiting')->count(); @endphp
            <section data-mockup-project="{{ $name }}" class="mt-8" x-show="(project === '' || project === @js($name)) && sets.some(s => s.project === @js($name) && matches(s))">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="font-semibold">{{ $name }}
                        <span class="text-sm font-normal text-ink-muted">· {{ count($sets) }} {{ Str::plural('set', count($sets)) }} · {{ $groupAwaiting }} awaiting</span>
                    </h2>
                    <span class="font-mono text-xs text-ink-muted">docs/mockups/</span>
                </div>
                <ul class="mt-3 grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                    @foreach ($sets as $rank => $set)
                        @php
                            $thumb = $set['picked'] ?? $set['options'][0];
                            $n = count($set['options']);
                        @endphp
                        <li data-set="{{ $set['project'] }}/{{ $set['story'] }}" data-state="{{ $set['state'] }}"
                            x-show="shown(@js(['project' => $set['project'], 'story' => $set['story'], 'title' => $set['title'], 'state' => $set['state']]), {{ $rank }})"
                            @if ($rank >= $perGroup) x-cloak @endif>
                            <a href="{{ route('mockups.show', ['project' => $set['project'], 'story' => $set['story']]) }}" wire:navigate
                                class="group block overflow-hidden rounded-xl border text-left transition hover:shadow-md focus-visible:outline-2 focus-visible:outline-accent bg-surface {{ $set['state'] === 'awaiting' ? 'border-pick/50' : 'border-edge' }}">
                                <div class="relative aspect-16/10 overflow-hidden border-b border-edge bg-raised" x-data="mockupFit(() => 1280)">
                                    {{-- A live render at a desktop viewport, scaled into the card; inert and sandboxed like every mockup. --}}
                                    <iframe src="{{ route('mockups.frame', ['project' => $set['project'], 'story' => $set['story'], 'file' => "option-{$thumb}.html"]) }}"
                                        sandbox="allow-scripts" loading="lazy" tabindex="-1" aria-hidden="true" title="{{ $set['story'] }} option {{ strtoupper($thumb) }}"
                                        class="pointer-events-none absolute top-0 left-0 origin-top-left border-0 bg-white" x-bind:style="frameStyle"></iframe>
                                    <span class="absolute top-2 right-2 rounded-md bg-ground/80 px-1.5 py-0.5 text-xs font-medium text-ink">{{ $n }} {{ Str::plural('option', $n) }}</span>
                                    <span class="absolute inset-0 grid place-items-center opacity-0 transition group-hover:bg-ground/30 group-hover:opacity-100" aria-hidden="true">
                                        <span class="rounded-md bg-surface px-2.5 py-1 text-xs font-medium text-ink">Open full screen</span>
                                    </span>
                                </div>
                                <div class="space-y-1 p-3">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-mono text-xs text-ink-muted">{{ $set['story'] }}</span>
                                        <x-board.mockup-state :set="$set" />
                                    </div>
                                    <p class="truncate text-sm font-medium">{{ $set['title'] ?? $set['story'] }}</p>
                                    <p class="truncate text-xs text-ink-muted">Where:
                                        @if ($set['where'])<code class="font-mono text-ink-soft">{{ $set['where'] }}</code>@else not named in the story @endif
                                    </p>
                                    <p class="line-clamp-2 min-h-8 text-xs text-ink-muted">
                                        @if ($set['state'] === 'awaiting')
                                            {{ $n }} {{ Str::plural('option', $n) }}, none picked. Shows option {{ strtoupper($thumb) }}.
                                        @elseif ($set['state'] === 'picked')
                                            {{ $set['reason'] ?? 'No reason recorded.' }}
                                        @else
                                            {{ $set['recorded'] ? 'As written: '.$set['recorded'] : ($set['status'] === null ? 'Mockups with no story file beside them.' : 'The story is '.$set['status'].' with no pick recorded.') }}
                                        @endif
                                    </p>
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
                @if (count($sets) > $perGroup)
                    <div class="mt-4 flex items-center justify-center gap-3 text-sm text-ink-muted"
                        x-show="! expanded.includes(@js($name)) && q.trim() === '' && status === 'all'">
                        Showing {{ $perGroup }} of {{ count($sets) }}
                        <button type="button" data-show-all-sets="{{ $name }}" x-on:click="expanded.push(@js($name))"
                            class="rounded-md border px-2.5 py-1 text-xs font-medium border-rim bg-surface hover:bg-raised">Show all {{ count($sets) }}</button>
                    </div>
                @endif
            </section>
        @endforeach

        <div x-show="none" x-cloak class="mt-8 rounded-xl border border-dashed p-10 text-center border-rim">
            <p class="font-medium">No mockup sets match</p>
            <p class="mt-1 text-sm text-ink-muted">Clear the search or pick “All”.</p>
            <button type="button" x-on:click="clear()" class="mt-3 rounded-md border px-2.5 py-1 text-xs font-medium border-rim bg-surface hover:bg-raised">Clear filters</button>
        </div>
    @endif
</main>
