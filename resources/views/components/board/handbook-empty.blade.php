{{-- A handbook section with no file behind it (SB-14): names the project and the missing file, and says
     what would put something here. The slot is that one-line hint. --}}
@props(['project', 'file'])
<div data-empty="{{ $file }}" {{ $attributes->class('px-5 py-14 text-center') }}>
    <svg class="mx-auto size-10 text-zinc-300 dark:text-zinc-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5M10 13h6M10 17h4"/></svg>
    <p class="mt-3 font-medium">{{ $project }} has no <span class="font-mono text-sm">{{ $file }}</span></p>
    @if ($slot->isNotEmpty())
        <p class="mx-auto mt-1 max-w-md text-sm text-zinc-500 dark:text-zinc-400">{{ $slot }}</p>
    @endif
</div>
