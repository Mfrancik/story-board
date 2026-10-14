{{-- One post's 1:1 ad as a card, whatever its render state (MK-20's review inbox; MK-5's states): the rendered PNG once
     stored; "Rendering…" in a dashed placeholder while a first render runs (an old image keeps a "Rendering…" badge);
     MK-5's fallback — marketing/post-preview, the post drawn in its brand — with "Couldn't render · Retry" after a
     failure, or "No ad image yet · Render it" for a post never rendered. A post waiting for its photo is the preview's
     "Needs a photo" card, with the default slot as its action.

     Retry and Render it call the page's `retryRender(id)` (the Review page's; the studio keeps its own markup).
     Props: `post`, `brand` (?MarketingBrand), `project` (name), `label` (the preview's small top-left line). --}}
@use('App\Models\MarketingPost')
@props(['post', 'brand' => null, 'project', 'label' => null])
@php
    $render = $post->renderState();
    $square = $post->renderId('square');
    $needs = $post->needsPhoto();
    $headline = $post->adSpec()['headline'] ?? strtok((string) $post->caption, "\n");
    $spin = '<span class="size-3.5 animate-spin rounded-full border-2 border-current border-t-transparent motion-reduce:animate-none" aria-hidden="true"></span>';
    $bar = 'absolute inset-x-2 bottom-2 flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm';
    $barButton = 'min-h-8 rounded-md border px-3 text-sm font-medium outline-needs focus-visible:outline-2 focus-visible:outline-offset-2';
@endphp
<div {{ $attributes->class('relative') }}>
    @if ($needs)
        <x-marketing.post-preview :post="$post" :brand="$brand" :project="$project" :label="$label">{{ $slot }}</x-marketing.post-preview>
    @elseif ($square !== null && $render !== MarketingPost::RENDER_FAILED)
        <img data-review-render="{{ $post->id }}" src="{{ route('marketing.asset', [$project, $square]) }}" width="1080" height="1080" loading="lazy"
            alt="Ad: {{ $headline }}" class="block aspect-square h-auto w-full rounded-md bg-surface object-cover">
        @if ($render === MarketingPost::RENDER_RENDERING)
            <span data-rendering="{{ $post->id }}" class="absolute top-2 left-2 inline-flex items-center gap-2 rounded-full bg-ground/90 px-3 py-1 text-xs text-ink">{!! $spin !!}Rendering…</span>
        @endif
    @elseif ($render === MarketingPost::RENDER_RENDERING)
        <div data-rendering="{{ $post->id }}" role="img" aria-label="Ad still rendering"
            class="flex aspect-square w-full flex-col items-center justify-center gap-2.5 rounded-md border border-dashed border-rim bg-surface text-sm font-medium text-ink-muted">
            {!! $spin !!}<span>Rendering…</span>
        </div>
    @else
        <x-marketing.post-preview :post="$post" :brand="$brand" :project="$project" :label="$label" />
        @if ($render === MarketingPost::RENDER_FAILED)
            <div data-render-failed="{{ $post->id }}" role="alert" class="{{ $bar }} border-fail/40 bg-ground/95 text-fail">
                <span class="min-w-0 flex-1">Couldn’t render</span>
                <button type="button" wire:click="retryRender({{ $post->id }})" wire:loading.attr="disabled" wire:target="retryRender({{ $post->id }})" data-retry-render="{{ $post->id }}"
                    class="{{ $barButton }} border-fail text-fail hover:bg-fail hover:text-ground">Retry</button>
            </div>
        @else
            <div class="{{ $bar }} border-rim bg-ground/95 text-ink-muted">
                <span class="min-w-0 flex-1">No ad image yet</span>
                <button type="button" wire:click="retryRender({{ $post->id }})" wire:loading.attr="disabled" wire:target="retryRender({{ $post->id }})" data-render-now="{{ $post->id }}"
                    class="{{ $barButton }} border-rim text-ink hover:bg-raised">Render it</button>
            </div>
        @endif
    @endif
</div>
