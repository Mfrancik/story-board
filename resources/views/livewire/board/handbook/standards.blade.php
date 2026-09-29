{{-- Handbook › Standards (SB-14): every docs/standards/*.md in the project or the kit, one per sub-tab
     (Alpine), each badged against the kit's blob. A changed file shows the kit's text below its own on
     demand, and a missing one the kit's text alone: the badge and both texts, no line diff (out of scope). --}}
@php
    $missing = \App\Actions\Board\ReadHandbook::MISSING;
    $changed = \App\Actions\Board\ReadHandbook::CHANGED;
@endphp
<div x-data="{ std: 0, showKit: false }">
    @unless ($present)
        <x-board.handbook-empty :project="$project" file="docs/standards/" @class(['border-b border-zinc-200 dark:border-zinc-800' => $files !== []])>
            @if ($files !== [])
                The kit has {{ count($files) }} standards {{ Str::plural('file', count($files)) }}, each badged Missing below.
            @else
                Standards appear here once the project has a docs/standards/ folder.
            @endif
        </x-board.handbook-empty>
    @endunless

    @if ($files !== [])
        <div role="tablist" aria-label="Standards files" class="flex gap-5 overflow-x-auto border-b border-zinc-200 px-5 text-sm dark:border-zinc-800">
            @foreach ($files as $i => $file)
                <button type="button" role="tab" x-on:click="std = {{ $i }}; showKit = false" x-bind:aria-selected="std === {{ $i }}"
                    data-standard="{{ $file['file'] }}" @if ($file['badge']) data-badge="{{ $file['badge'] }}" @endif
                    class="-mb-px inline-flex shrink-0 items-center gap-2 border-b-2 py-3"
                    x-bind:class="std === {{ $i }} ? 'border-zinc-800 font-medium dark:border-white' : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200'">
                    @if ($file['badge'])
                        <x-board.kit-badge :badge="$file['badge']" dot />
                    @endif
                    {{ Str::before($file['file'], '-standards.md') === $file['file'] ? Str::beforeLast($file['file'], '.md') : Str::before($file['file'], '-standards.md') }}
                </button>
            @endforeach
        </div>

        @foreach ($files as $i => $file)
            <div role="tabpanel" x-show="std === {{ $i }}" @if ($i > 0) x-cloak @endif wire:key="standard-{{ $file['file'] }}">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-zinc-100 px-5 py-3 dark:border-zinc-800">
                    <p class="font-mono text-xs text-zinc-500 dark:text-zinc-400">docs/standards/{{ $file['file'] }}</p>
                    @if ($file['badge'])
                        <x-board.kit-badge :badge="$file['badge']" />
                    @endif
                    @if ($file['badge'] === $changed)
                        <button type="button" x-on:click="showKit = ! showKit" x-bind:aria-expanded="showKit"
                            class="ml-auto rounded-md px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                            x-text="showKit ? 'Hide the kit\'s version' : 'Show the kit\'s version'">Show the kit's version</button>
                    @endif
                </div>

                @if ($file['html'] !== null)
                    <x-board.prose :html="$file['html']" class="px-5 py-6 sm:px-8" />
                    @if ($file['kit_html'] !== null && $file['badge'] === $changed)
                        <div x-show="showKit" x-cloak data-kit-text class="border-t-4 border-double border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-950/40">
                            <p class="px-5 pt-5 text-xs font-semibold tracking-wider text-zinc-500 uppercase sm:px-8 dark:text-zinc-400">
                                The kit's version · <span class="font-mono tracking-normal normal-case">{{ $kitPath }}/docs/standards/{{ $file['file'] }}</span>
                            </p>
                            <x-board.prose :html="$file['kit_html']" class="px-5 py-5 sm:px-8" />
                        </div>
                    @endif
                @else
                    <x-board.handbook-empty :project="$project" :file="'docs/standards/'.$file['file']" class="py-12">
                        The kit has it; its text is below.
                    </x-board.handbook-empty>
                    @if ($file['kit_html'] !== null && $file['badge'] === $missing)
                        <div data-kit-text class="border-t border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-950/40">
                            <p class="px-5 pt-5 text-xs font-semibold tracking-wider text-zinc-500 uppercase sm:px-8 dark:text-zinc-400">
                                The kit's version · <span class="font-mono tracking-normal normal-case">{{ $kitPath }}/docs/standards/{{ $file['file'] }}</span>
                            </p>
                            <x-board.prose :html="$file['kit_html']" class="px-5 py-5 sm:px-8" />
                        </div>
                    @endif
                @endif
            </div>
        @endforeach
    @endif
</div>
