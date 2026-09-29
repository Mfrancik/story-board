{{-- SB-7 shell, design A: mobile top bar, drawer backdrop and the project sidebar. `open` (the drawer)
     lives in layouts/board's x-data; the filter text `q` is local. Pure UI, so all of it is Alpine. --}}
@php
    $item = 'flex w-full items-center gap-3 rounded-lg px-3 py-2';
    $state = fn (bool $on) => $on ? 'bg-zinc-100 font-medium dark:bg-zinc-800' : 'hover:bg-zinc-100/60 dark:hover:bg-zinc-800/50';
    $names = $projects->pluck('name')->map(fn ($n) => Str::lower($n))->values();
@endphp
<div class="sticky top-0 z-30 flex h-14 items-center gap-3 border-b border-zinc-200 bg-white/90 px-4 backdrop-blur md:hidden dark:border-zinc-800 dark:bg-zinc-900/90">
    <button type="button" aria-label="Open projects" aria-controls="board-sidebar" x-bind:aria-expanded="open.toString()" x-on:click="open = true"
        class="-ml-2 rounded-lg p-2 hover:bg-zinc-100 dark:hover:bg-zinc-800">
        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
    </button>
    <span class="font-semibold">Story board</span>
    <span class="truncate text-sm text-zinc-500 dark:text-zinc-400">· {{ $current ?? ($onManage ? 'Manage projects' : 'All projects') }}</span>
</div>

<div x-show="open" x-cloak x-on:click="open = false" class="fixed inset-0 z-40 bg-zinc-950/40 md:hidden" aria-hidden="true"></div>

<aside id="board-sidebar" aria-label="Projects" x-data="{ q: '' }" x-bind:data-open="open" x-trap.noscroll="open"
    x-on:keydown.escape.window="open = false"
    x-on:keydown.meta.k.window.prevent="open = true; $nextTick(() => $refs.search.focus())"
    x-on:keydown.ctrl.k.window.prevent="open = true; $nextTick(() => $refs.search.focus())"
    class="board-drawer fixed inset-y-0 left-0 z-50 hidden w-72 flex-col border-r border-zinc-200 bg-white data-open:flex md:flex md:w-64 dark:border-zinc-800 dark:bg-zinc-900">
    <div class="flex items-center justify-between px-5 pt-5 pb-3">
        <a href="{{ route('home') }}" wire:navigate class="flex items-center gap-2">
            <span class="grid size-7 place-items-center rounded-lg bg-accent text-xs font-bold text-accent-foreground" aria-hidden="true">SB</span>
            <span class="font-semibold">Story board</span>
        </a>
        <button type="button" aria-label="Close projects" x-on:click="open = false" class="rounded-lg p-1.5 hover:bg-zinc-100 md:hidden dark:hover:bg-zinc-800">
            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
        </button>
    </div>

    <div class="px-4 pb-3">
        <label for="sidebar-search" class="sr-only">Filter projects</label>
        <div class="relative">
            <svg class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-zinc-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
            <input id="sidebar-search" x-ref="search" x-model="q" type="search" placeholder="Filter projects" autocomplete="off" aria-keyshortcuts="Meta+K Control+K"
                class="w-full rounded-lg border border-zinc-200 bg-zinc-50 py-2 pr-14 pl-9 text-sm dark:border-zinc-800 dark:bg-zinc-950">
            <kbd class="pointer-events-none absolute top-1/2 right-2 -translate-y-1/2 rounded border border-zinc-200 px-1.5 font-sans text-xs text-zinc-500 dark:border-zinc-700"
                x-text="/Mac|iP(hone|ad)/.test(navigator.platform) ? '⌘K' : 'Ctrl K'">⌘K</kbd>
        </div>
    </div>

    <nav aria-label="Project switcher" class="flex-1 overflow-y-auto px-3 pb-3 text-sm">
        <a href="{{ route('home') }}" wire:navigate data-sidebar-all @if ($onHome) aria-current="page" @endif class="{{ $item }} {{ $state($onHome) }}">
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
            <span class="flex-1">All projects</span>
            <span class="text-xs tabular-nums text-zinc-500 dark:text-zinc-400">{{ number_format($total) }}<span class="sr-only"> stories</span></span>
        </a>

        <p class="mt-5 mb-1 px-3 text-xs font-semibold tracking-wider text-zinc-400 uppercase">Projects</p>
        @if ($projects->isEmpty())
            <p class="px-3 py-2 text-zinc-500 dark:text-zinc-400">
                No projects yet.
                @if ($manageUrl)
                    <a href="{{ $manageUrl }}" wire:navigate class="font-medium underline">Add a project</a>
                @else
                    Add one with <code class="text-xs">php artisan board:project add &lt;path&gt;</code>.
                @endif
            </p>
        @else
            <ul class="space-y-0.5">
                @foreach ($projects as $p)
                    @php $on = $current === $p->name; @endphp
                    <li x-show="! q.trim() || @js(Str::lower($p->name)).includes(q.trim().toLowerCase())">
                        <a href="{{ route('projects.show', ['project' => $p->name]) }}" wire:navigate data-sidebar-project="{{ $p->name }}" data-story-count="{{ $p->stories_count }}"
                            @if ($on) aria-current="page" @endif class="{{ $item }} {{ $state($on) }}">
                            <x-board.state :state="$p->state" title="State: {{ $p->state }}" />
                            <span class="flex-1 truncate">{{ $p->name }}</span>
                            <span class="sr-only">{{ $p->state }}</span>
                            <span class="text-xs tabular-nums text-zinc-500 dark:text-zinc-400">{{ number_format($p->stories_count) }}<span class="sr-only"> stories</span></span>
                        </a>
                    </li>
                @endforeach
            </ul>
            <p x-show="q.trim() && ! @js($names).some(n => n.includes(q.trim().toLowerCase()))" x-cloak class="px-3 py-2 text-zinc-500 dark:text-zinc-400">
                No project matches “<span x-text="q.trim()"></span>”.
            </p>
        @endif
    </nav>

    @if ($manageUrl)
        <div class="border-t border-zinc-200 p-3 text-sm dark:border-zinc-800">
            <a href="{{ $manageUrl }}" wire:navigate @if ($onManage) aria-current="page" @endif class="{{ $item }} {{ $state($onManage) }}">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7h16M4 12h10M4 17h7"/><circle cx="18" cy="16" r="3"/></svg>
                <span class="flex-1">Manage projects</span>
            </a>
        </div>
    @endif
</aside>
