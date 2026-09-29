{{-- SB-12 Manage projects, design A (docs/mockups/SB-7/option-a.html, view 4). Every project with its switch
     (Livewire: it writes), the inline Add project form, and Remove behind a confirmation modal whose open/close
     is Alpine (`removing` holds the project being confirmed; pure UI, no round trip). --}}
@php
    $input = 'mt-1 w-full rounded-lg border bg-white px-3 py-2 text-sm dark:bg-zinc-950';
    $border = fn (string $field) => $errors->has($field) ? 'border-danger' : 'border-zinc-300 dark:border-zinc-700';
    // Paths under the home folder read as ~/…, the way the owner types them.
    $tilde = fn (string $path) => $home !== '' && str_starts_with($path, $home.'/') ? '~'.substr($path, strlen($home)) : $path;
@endphp
<main class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-10 lg:py-10" x-data="{ removing: null }">
    <header>
        <h1 class="text-2xl font-semibold tracking-tight">Manage projects</h1>
        <p class="mt-1 max-w-2xl text-sm text-zinc-500 dark:text-zinc-400">
            Turn a project off to hide it everywhere and stop refreshing it. Its data is kept. These settings live in the
            board's own database — nothing is written to your projects.
        </p>
    </header>

    <div class="mt-6 rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <div class="hidden grid-cols-[4rem_1.3fr_0.8fr_5rem_4rem_7rem_10rem_6rem] gap-4 border-b border-zinc-200 px-5 py-3 text-xs font-medium text-zinc-500 md:grid dark:border-zinc-800 dark:text-zinc-400">
            <span>Shown</span><span>Project</span><span>Ref</span><span>State</span><span class="text-right">Stories</span><span>Refreshed</span><span>Production</span><span class="sr-only">Actions</span>
        </div>
        @if ($projects->isEmpty())
            <p class="px-5 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">No projects yet. Add one below.</p>
        @else
            <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($projects as $p)
                    @php $call = 'setEnabled('.$p->id.', '.($p->is_enabled ? 'false' : 'true').')'; @endphp
                    {{-- `open` is the Production panel under the row (SB-17): Alpine, no round trip; the panel's
                         component is lazy, so nothing reads production until it is first opened. --}}
                    @php $prod = ['connected' => (bool) $p->prod_connection_exists, 'metrics' => (int) $p->enabled_metrics_count]; @endphp
                    <li wire:key="project-{{ $p->id }}" data-project-row="{{ $p->name }}"
                        x-data="{ open: false, prod: @js($prod) }" x-on:prod-summary.window="$event.detail.project === {{ $p->id }} && (prod = $event.detail)">
                    <div @class([
                        'grid grid-cols-[auto_1fr_auto] items-center gap-x-4 gap-y-1 px-5 py-4 md:grid-cols-[4rem_1.3fr_0.8fr_5rem_4rem_7rem_10rem_6rem]',
                        'bg-zinc-50 dark:bg-zinc-950/40' => ! $p->is_enabled,
                    ])>
                        <x-board.switch :on="$p->is_enabled" label="Show {{ $p->name }}" :target="$call"
                            data-project-switch="{{ $p->name }}" wire:click="{{ $call }}" />
                        <div class="min-w-0">
                            <p @class(['font-medium', 'text-zinc-500 dark:text-zinc-400' => ! $p->is_enabled])>{{ $p->name }}</p>
                            <p class="truncate font-mono text-xs text-zinc-500 dark:text-zinc-400" title="{{ $p->path }}">{{ $tilde($p->path) }}</p>
                        </div>
                        <button type="button" data-remove="{{ $p->name }}" x-on:click="removing = { id: {{ $p->id }}, name: @js($p->name) }"
                            class="justify-self-end rounded-lg px-3 py-1.5 text-sm font-medium text-danger hover:bg-danger/10 md:order-last">
                            Remove<span class="sr-only"> {{ $p->name }}</span>
                        </button>
                        <span class="col-start-2 font-mono text-xs text-zinc-500 md:col-start-auto dark:text-zinc-400">{{ $p->ref }}</span>
                        <span class="col-start-2 inline-flex items-center gap-1.5 text-xs md:col-start-auto">
                            @if ($p->is_enabled)
                                <x-board.state :state="$p->state" /> {{ $p->state }}
                            @else
                                <span class="size-1.5 rounded-full bg-zinc-400" aria-hidden="true"></span>
                                <span class="text-zinc-500 dark:text-zinc-400">Off</span>
                            @endif
                        </span>
                        <span data-project-stories="{{ $p->stories_count }}" class="col-start-2 text-xs tabular-nums text-zinc-600 md:col-start-auto md:text-right md:text-sm dark:text-zinc-400">
                            {{ number_format($p->stories_count) }}<span class="md:hidden"> {{ Str::plural('story', $p->stories_count) }}</span>
                        </span>
                        <span class="col-start-2 text-xs text-zinc-500 md:col-start-auto dark:text-zinc-400">
                            @if ($p->indexed_at)
                                {{ $p->is_enabled ? 'Refreshed' : 'Last refreshed' }} {{ $p->indexed_at->diffForHumans() }}
                            @else
                                Not read yet
                            @endif
                        </span>
                        <div class="col-start-2 min-w-0 md:col-start-auto">
                            <button type="button" x-on:click="open = ! open" :aria-expanded="open" aria-controls="prod-{{ $p->id }}"
                                data-prod-toggle="{{ $p->name }}" data-prod-state="{{ $prod['connected'] ? 'connected' : 'none' }}" :data-prod-state="prod.connected ? 'connected' : 'none'"
                                class="-mx-2 flex w-full items-center justify-between gap-2 rounded-lg px-2 py-1 text-left hover:bg-zinc-100 dark:hover:bg-zinc-800">
                                {{-- Server-rendered first, then kept current by the panel's prod-summary event (no page reload). --}}
                                <span x-show="prod.connected" @unless ($prod['connected']) style="display: none" @endunless class="inline-flex items-center gap-1.5 text-xs whitespace-nowrap">
                                    <span class="size-1.5 rounded-full bg-ok" aria-hidden="true"></span>Read-only · <span x-text="prod.metrics + (prod.metrics === 1 ? ' metric' : ' metrics')">{{ $prod['metrics'] }} {{ Str::plural('metric', $prod['metrics']) }}</span>
                                </span>
                                <span x-show="! prod.connected" @if ($prod['connected']) style="display: none" @endif class="inline-flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                                    <span class="size-1.5 rounded-full bg-zinc-300 dark:bg-zinc-600" aria-hidden="true"></span>Not set up
                                </span>
                                <svg class="size-4 shrink-0 transition-transform" :class="open && 'rotate-180'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                                <span class="sr-only">Production settings for {{ $p->name }}</span>
                            </button>
                        </div>
                    </div>
                    <div id="prod-{{ $p->id }}" x-show="open" x-cloak class="border-t border-zinc-200 bg-zinc-50/70 dark:border-zinc-800 dark:bg-zinc-950/40">
                        <livewire:board.production-settings :project="$p" :key="'prod-'.$p->id" lazy />
                    </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <form wire:submit="add" novalidate class="mt-8 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
        <h2 class="font-medium">Add project</h2>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Point the board at a git checkout on this machine.</p>
        <div class="mt-5 grid gap-4 md:grid-cols-3">
            <div>
                <label for="f-path" class="block text-sm font-medium">Path <span class="text-danger" aria-hidden="true">*</span></label>
                <input id="f-path" type="text" wire:model="path" required placeholder="~/Code/new-site" autocomplete="off" spellcheck="false"
                    @error('path') aria-invalid="true" @enderror aria-describedby="f-path-err" class="{{ $input }} {{ $border('path') }} font-mono">
                <flux:error name="path" id="f-path-err" class="mt-1.5" />
            </div>
            <div>
                <label for="f-name" class="block text-sm font-medium">Name <span class="font-normal text-zinc-500 dark:text-zinc-400">(optional)</span></label>
                <input id="f-name" type="text" wire:model="name" placeholder="new-site" autocomplete="off"
                    @error('name') aria-invalid="true" @enderror aria-describedby="f-name-hint f-name-err" class="{{ $input }} {{ $border('name') }}">
                <p id="f-name-hint" class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">Defaults to the folder name.</p>
                <flux:error name="name" id="f-name-err" class="mt-1.5" />
            </div>
            <div>
                <label for="f-ref" class="block text-sm font-medium">Ref</label>
                <input id="f-ref" type="text" wire:model="ref" autocomplete="off" spellcheck="false"
                    @error('ref') aria-invalid="true" @enderror aria-describedby="f-ref-hint f-ref-err" class="{{ $input }} {{ $border('ref') }} font-mono">
                <p id="f-ref-hint" class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">The branch the board treats as main.</p>
                <flux:error name="ref" id="f-ref-err" class="mt-1.5" />
            </div>
        </div>
        <div class="mt-5 flex justify-end">
            <button type="submit" data-add-project wire:loading.attr="disabled" wire:target="add"
                class="rounded-lg bg-zinc-800 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 disabled:opacity-60 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">
                <span wire:loading.remove wire:target="add">Add project</span>
                <span wire:loading wire:target="add">Adding…</span>
            </button>
        </div>
    </form>

    <x-board.confirm-modal show="removing" confirm="Remove project" action="$wire.remove(removing.id)" target="remove" id="remove-project" data-remove-modal>
        <x-slot:title>Remove <span x-text="removing?.name"></span>?</x-slot:title>
        Its stories and history are deleted from the board. The project's folder and git are not touched.
    </x-board.confirm-modal>
</main>
