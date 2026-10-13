{{-- One marketing post drawn in its project's brand (MK-9; MK-10's library cards reuse it): a square card in the
     brand's font, colours and radius (MK-2), with its logo. A post with a photo is the photo with the caption's first
     line over it; a post without one is the brand text card — the first line as the headline, the rest under it. A
     post still held for its photo (MarketingPost::needsPhoto) is the "Needs a photo" card, with the default slot for
     its actions (the studio puts Upload and Use a favourite there). The type scales with the card's width (.post-card
     in app.css), so it reads the same at any size.

     Props: `post` (MarketingPost), `brand` (?MarketingBrand; defaults when null), `project` (name, for the photo
     URL), `label` (the small line top left, usually the campaign's name). The brand values are the owner's own data
     and are written into custom properties only; the logo is SanitiseSvg's output, the one unescaped value. --}}
@props(['post', 'brand' => null, 'project', 'label' => null])
@php
    $values = ($brand?->values ?? []) + ['name' => $project] + \App\Models\MarketingBrand::DEFAULTS;
    $name = $values['name'] ?: $project;
    $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $post->caption) ?: []), fn ($l) => $l !== ''));
    $head = $lines[0] ?? '';
    $sub = implode(' ', array_slice($lines, 1));
    // The headline shrinks as it grows, as the prototype's text card does.
    $size = match (true) { mb_strlen($head) <= 24 => 10.4, mb_strlen($head) <= 44 => 8.2, mb_strlen($head) <= 72 => 6.6, default => 5.4 };
    $style = sprintf("--pp-surface: %s; --pp-ink: %s; --pp-accent: %s; --pp-font: '%s'; --pp-radius: %dpx",
        $values['surface'], $values['ink'], $values['accent'], $values['font'], (int) $values['radius']);
    $logo = $brand?->logo_svg;
@endphp
<div data-post-preview="{{ $post->id }}" {{ $attributes->class(['post-card']) }} style="{{ $style }}">
    @if ($post->needsPhoto())
        <div class="post-card__needs">
            <svg class="size-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="4"/></svg>
            <b>Needs a photo</b>
            {{ $slot }}
        </div>
    @elseif ($post->asset_id)
        <img class="post-card__photo" src="{{ route('marketing.asset', [$project, $post->asset_id]) }}" alt="Photo for this post">
        <div class="post-card__brand post-card__chip">
            @if ($logo) {!! $logo !!} @endif
            <span>{{ $name }}</span>
        </div>
        @if ($head !== '')
            <div class="post-card__over">{{ $head }}</div>
        @endif
    @else
        <div class="post-card__label">{{ $label ?? $name }}</div>
        <div class="post-card__brand">
            @if ($logo) {!! $logo !!} @endif
            <span>{{ $name }}</span>
        </div>
        <div class="post-card__head" style="font-size: {{ $size }}cqw">{{ $head }}</div>
        @if ($sub !== '')
            <div class="post-card__sub">{{ $sub }}</div>
        @endif
        <div class="post-card__rule"><i></i></div>
        <div class="post-card__foot">
            @if ($logo) {!! $logo !!} @endif
            <span>{{ $name }}</span>
        </div>
    @endif
</div>
