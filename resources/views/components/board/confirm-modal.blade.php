{{--
    Confirmation for a destructive action (SB-12, design A; design-standards: "Delete/destructive: confirmation
    modal"). Opening and closing are Alpine — pure UI, no round trip: `show` names an Alpine variable in the
    caller's x-data that is truthy while the modal is open and set to null to close it. `confirm` is the red
    button's label and `action` the JS it runs (usually `$wire.<method>(…)`); `target` is that Livewire method,
    so the button disables while it runs. Slots: `title`, and the body as the default slot. Escape, the
    backdrop and Cancel close it; focus starts on Cancel so Enter never destroys by accident.
    RD-4: only the dark shell uses it, so it carries the shell tokens alone (layout unchanged).
--}}
@props(['show', 'confirm', 'action', 'target' => null, 'id' => 'confirm-modal'])
<div x-show="{{ $show }}" x-cloak x-on:keydown.escape.window="{{ $show }} = null"
    class="fixed inset-0 z-60 flex items-center justify-center p-4" {{ $attributes }}>
    <div x-on:click="{{ $show }} = null" class="absolute inset-0 bg-ground/70" aria-hidden="true"></div>
    <div role="alertdialog" aria-modal="true" aria-labelledby="{{ $id }}-title" aria-describedby="{{ $id }}-body" x-trap.noscroll="{{ $show }}"
        class="relative w-full max-w-md rounded-xl p-6 shadow-2xl ring-1 bg-surface ring-edge">
        <h2 id="{{ $id }}-title" class="text-lg font-semibold">{{ $title }}</h2>
        <p id="{{ $id }}-body" class="mt-2 text-sm text-ink-muted">{{ $slot }}</p>
        <div class="mt-6 flex justify-end gap-2">
            <button type="button" x-on:click="{{ $show }} = null" data-confirm-cancel
                class="rounded-lg px-4 py-2 text-sm font-medium hover:bg-raised">Cancel</button>
            <button type="button" x-on:click="{{ $action }}" data-confirm-ok
                @if ($target) wire:loading.attr="disabled" wire:target="{{ $target }}" @endif
                class="rounded-lg bg-danger px-4 py-2 text-sm font-medium text-ground hover:opacity-90 disabled:opacity-60">{{ $confirm }}</button>
        </div>
    </div>
</div>
