{{--
    MK-18 Inspiration (docs/mockups/MK-prototype/index.html, Inspiration route, rounds 4–5) in the RD-2 shell: the
    project header with Marketing current and the marketing sub-nav; the title and what the wall is for (tone and copy
    now, layout with the designer later); a big drop zone; then one masonry wall of every image, newest first — one
    column on a phone, up to four on a wide screen.

    Each tile: the image (or, for a text-only post saved here, its brand text card via marketing/post-preview), a heart
    and a delete, and what is known about it: "AI read it" with the headline and style notes, "From the kit", "From
    your post", "Reading…", or "Couldn't read · Retry". Dragging over the drop zone and the delete confirm are Alpine;
    the drop hands the files to Livewire's own upload (`$wire.uploadMultiple`).
--}}
@php
    $focus = 'outline-needs focus-visible:outline-2 focus-visible:outline-offset-2';
    $spin = '<span class="size-3.5 animate-spin rounded-full border-2 border-current border-t-transparent" aria-hidden="true"></span>';
    $errorsHere = collect($errors->get('images.*'))->flatten()->merge($errors->get('images'))->unique();
    $sources = ['kit' => 'From the kit', 'post' => 'From your post'];
@endphp
<main class="mx-auto flex max-w-7xl min-w-0 flex-col gap-6 px-4 py-6 sm:px-6 lg:px-10 lg:py-10" x-data="{ deleting: null, over: false }">
    <div class="flex flex-col gap-4">
        <x-board.project-header :model="$model" page="Marketing" current="marketing" />
        <x-marketing.sub-nav :project="$model->name" current="inspiration" />
    </div>

    <div class="flex flex-col gap-1">
        <h2 class="text-xl font-semibold tracking-tight">Inspiration</h2>
        <p class="max-w-3xl text-sm text-ink-muted">
            Ads you like, in one place. Claude reads each one once, and Fill uses your favourites and the closest matches as examples for tone and copy.
            Layout matching arrives with the designer later.
        </p>
    </div>

    <label data-inspiration-drop
        x-on:dragover.prevent="over = true" x-on:dragleave.prevent="over = false"
        x-on:drop.prevent="over = false; $wire.uploadMultiple('images', $event.dataTransfer.files)"
        x-bind:class="over && 'border-needs bg-raised'"
        class="relative flex min-h-36 cursor-pointer flex-col items-center justify-center gap-1.5 rounded-xl border-2 border-dashed border-rim bg-surface p-5 text-center text-needs hover:border-needs hover:bg-raised focus-within:outline-2 focus-within:outline-offset-2 outline-needs">
        <input type="file" wire:model="images" multiple accept="image/jpeg,image/png,image/webp" data-inspiration-upload class="sr-only">
        <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 16v4h16v-4"/></svg>
        <span wire:loading.remove wire:target="images" class="text-base font-semibold text-ink">Drop images here, or click to choose</span>
        <span wire:loading wire:target="images" class="inline-flex items-center gap-2 text-base font-semibold text-ink">{!! $spin !!}Uploading…</span>
        <span class="text-sm text-ink-muted">Ads you like, screenshots, your own posts. JPG, PNG or WebP, up to 10 MB each. No folders, no tags. {{ $wall->count() }} so far.</span>
    </label>
    @if ($errorsHere->isNotEmpty())
        <div data-inspiration-errors role="alert" class="-mt-3 text-sm text-danger">
            @foreach ($errorsHere as $message)
                <p>{{ $message }}</p>
            @endforeach
        </div>
    @endif

    @if ($reading)
        <div wire:poll.3s data-polling class="sr-only">Reading images…</div>
    @endif

    @if ($wall->isEmpty())
        <p data-inspiration-empty class="rounded-xl border border-dashed border-rim bg-surface px-6 py-8 text-center text-sm text-ink-muted">
            Nothing here yet. Drop a few ads you like above, and heart the ones you like most.
        </p>
    @else
        <div data-inspiration-wall class="columns-1 gap-3 sm:columns-2 lg:columns-3 xl:columns-4">
            @foreach ($wall as $image)
                @php
                    $notes = $image->ai_notes ?? [];
                    $state = $image->readState();
                    $headline = trim((string) ($notes['headline'] ?? ''));
                    $about = array_filter([implode(', ', (array) ($notes['style'] ?? [])), (string) ($notes['topic'] ?? '')], fn ($s) => $s !== '');
                    $label = $headline !== '' ? $headline : 'Inspiration image';
                @endphp
                <figure wire:key="insp-{{ $image->id }}" data-inspiration="{{ $image->id }}" data-read-state="{{ $state }}"
                    @class(['group relative mb-3 break-inside-avoid overflow-hidden rounded-xl border bg-surface', 'border-needs/60' => $image->is_favourite, 'border-edge' => ! $image->is_favourite])>
                    @if ($image->isTextCard())
                        <x-marketing.post-preview :post="new \App\Models\MarketingPost(['caption' => (string) ($notes['caption'] ?? $headline)])" :brand="$brand" :project="$model->name" :label="$notes['topic'] ?? null" />
                    @else
                        <img src="{{ route('marketing.asset', ['project' => $model->name, 'asset' => $image->id]) }}" alt="{{ $label }}" loading="lazy"
                            @if ($image->width && $image->height) width="{{ $image->width }}" height="{{ $image->height }}" @endif
                            class="block h-auto w-full bg-raised">
                    @endif
                    <div class="absolute top-2 right-2 flex gap-1.5">
                        <button type="button" wire:click="toggleFavourite({{ $image->id }})" data-favourite-inspiration="{{ $image->id }}"
                            aria-pressed="{{ $image->is_favourite ? 'true' : 'false' }}" aria-label="Favourite {{ $label }}"
                            @class(['inline-flex size-9 items-center justify-center rounded-full bg-ground/80 text-base hover:bg-ground', $focus, 'text-needs' => $image->is_favourite, 'text-ink' => ! $image->is_favourite])>{{ $image->is_favourite ? '♥' : '♡' }}</button>
                        <button type="button" x-on:click="deleting = { id: {{ $image->id }}, name: @js($label) }" data-delete-inspiration="{{ $image->id }}" aria-label="Delete {{ $label }}"
                            class="inline-flex size-9 items-center justify-center rounded-full bg-ground/80 text-ink hover:bg-danger hover:text-ground {{ $focus }}">
                            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
                        </button>
                    </div>
                    <figcaption class="flex flex-col gap-1 px-3 pt-2.5 pb-3 text-xs text-ink-muted">
                        @if ($state === \App\Models\MarketingAsset::READ_READING)
                            <span class="inline-flex items-center gap-2">{!! $spin !!}Reading…</span>
                        @elseif ($state === \App\Models\MarketingAsset::READ_FAILED)
                            <span class="flex flex-wrap items-center gap-x-1.5 text-fail">Couldn’t read ·
                                <button type="button" wire:click="retry({{ $image->id }})" wire:loading.attr="disabled" wire:target="retry" data-retry-read="{{ $image->id }}" class="rounded text-live hover:underline {{ $focus }}">Retry</button>
                            </span>
                        @else
                            <span class="text-xs font-medium tracking-wider text-pick uppercase">{{ $sources[$notes['source'] ?? ''] ?? 'AI read it' }}</span>
                            @if ($headline !== '')
                                <b class="text-sm font-medium text-ink">“{{ $headline }}”</b>
                            @endif
                            @if ($about !== [])
                                <span>{{ implode(' · ', $about) }}</span>
                            @endif
                        @endif
                    </figcaption>
                </figure>
            @endforeach
        </div>
    @endif

    <x-board.confirm-modal show="deleting" confirm="Delete" action="$wire.delete(deleting.id).then(() => deleting = null)" target="delete" id="delete-inspiration" data-delete-inspiration-modal>
        <x-slot:title>Delete <span x-text="deleting?.name"></span>?</x-slot:title>
        It leaves Inspiration, and Fill stops using it as an example.
    </x-board.confirm-modal>
</main>
