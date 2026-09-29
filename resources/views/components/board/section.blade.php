{{--
    One boxed list on the home page: a heading with its count and hint, then rows.
    Used for the three action groups and, with `collapsible`, for Built / Parked drafts.
--}}
@props(['key', 'title', 'count', 'hint' => null, 'note' => null, 'accent' => 'border-zinc-200 dark:border-zinc-800', 'collapsible' => false, 'open' => true])
<section data-{{ $collapsible ? 'section' : 'group' }}="{{ $key }}" {{ $attributes->class(['overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900']) }}>
    <h2 class="border-l-4 {{ $accent }} {{ $open ? 'border-b border-b-zinc-200 dark:border-b-zinc-800' : '' }}">
        @if ($collapsible)
            <button type="button" wire:click="toggleSection('{{ $key }}')" aria-expanded="{{ $open ? 'true' : 'false' }}"
                class="flex w-full flex-wrap items-baseline gap-x-2 px-3 py-2 text-left hover:bg-zinc-50 focus-visible:outline-2 focus-visible:outline-accent dark:hover:bg-zinc-800/50">
                <span aria-hidden="true" class="text-zinc-400">{{ $open ? '▾' : '▸' }}</span>
                <span class="font-semibold">{{ $title }}</span>
                <span class="rounded-full bg-zinc-100 px-2 text-xs tabular-nums dark:bg-zinc-800">{{ $count }}</span>
                @if ($hint)<span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $hint }}</span>@endif
                <span wire:loading wire:target="toggleSection('{{ $key }}')" class="text-xs text-zinc-400">Loading…</span>
            </button>
        @else
            <div class="flex flex-wrap items-baseline gap-x-2 px-3 py-2">
                <span class="font-semibold">{{ $title }}</span>
                <span class="rounded-full bg-zinc-100 px-2 text-xs tabular-nums dark:bg-zinc-800">{{ $count }}</span>
                @if ($hint)<span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $hint }}</span>@endif
                @if ($note)<span class="text-xs text-zinc-500 sm:ml-auto dark:text-zinc-400">{{ $note }}</span>@endif
            </div>
        @endif
    </h2>
    @if ($open)
        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">{{ $slot }}</div>
    @endif
</section>
