{{--
    An on/off switch (SB-12, design A): a <button role="switch"> whose aria-checked is the current state.
    It does no toggling of its own — the caller's click handler (wire:click) writes the new state, so the
    switch always shows what the server stored. `label` is its accessible name. Disabled while `target`
    (a Livewire action) is running.
--}}
@props(['on', 'label', 'target' => null])
<button type="button" role="switch" aria-checked="{{ $on ? 'true' : 'false' }}" aria-label="{{ $label }}"
    @if ($target) wire:loading.attr="disabled" wire:target="{{ $target }}" @endif
    {{ $attributes->class([
        'relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent disabled:cursor-wait disabled:opacity-60',
        $on ? 'bg-zinc-800 dark:bg-white' : 'bg-zinc-300 dark:bg-zinc-700',
    ]) }}>
    <span aria-hidden="true" @class([
        'absolute top-0.5 left-0.5 size-5 rounded-full bg-white shadow transition-transform dark:bg-zinc-900',
        'translate-x-5' => $on,
    ])></span>
</button>
