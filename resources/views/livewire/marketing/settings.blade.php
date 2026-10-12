{{--
    MK-2 Marketing settings (docs/mockups/MK-prototype/index.html, Settings route) in the RD-2 shell: the project header
    with Marketing current and the marketing sub-nav, then two columns from 1024 px (one below): the brand kit on the
    left; favourite photos, copy rules and the disabled Facebook card on the right.

    The brand is editable here (the story; the prototype showed it read-only): one form that creates the brand on first
    save and edits it after. Its preview is drawn from the SAVED values (or the defaults), never from what is being
    typed, so an unvalidated string never reaches a style attribute. The logo is sanitised SVG (SanitiseSvg) and is the
    only unescaped output. `deleting` is Alpine state for board/confirm-modal: no round trip until Delete is confirmed.
--}}
@php
    $focus = 'outline-needs focus-visible:outline-2 focus-visible:outline-offset-2';
    $card = 'flex min-w-0 flex-col gap-4 rounded-xl border border-edge bg-surface p-4 sm:p-5';
    $label = 'block text-sm font-medium';
    $input = 'mt-1 w-full rounded-lg border bg-ground px-3 py-2 text-sm '.$focus;
    $border = fn (string $field) => $errors->has($field) ? 'border-danger' : 'border-rim';
    $button = 'inline-flex min-h-9 items-center justify-center rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-60 '.$focus;
    $primary = $button.' bg-needs text-needs-ink hover:brightness-110';
    $secondary = $button.' border border-rim hover:bg-raised';
    // The preview shows what is saved: the brand's stored values, or the defaults with the project's name.
    $saved = ($brand?->values ?? []) + ['name' => $model->name] + \App\Models\MarketingBrand::DEFAULTS;
    $shadows = ['none' => 'none', 'soft' => '0 4px 14px -4px rgba(0, 0, 0, 0.35)', 'halo' => '0 10px 32px -6px '.$saved['accent'].'73'];
    $photoErrors = collect($errors->get('photos.*'))->flatten()->merge($errors->get('photos'))->unique();
@endphp
<main class="mx-auto flex max-w-7xl min-w-0 flex-col gap-6 px-4 py-6 sm:px-6 lg:px-10 lg:py-10" x-data="{ deleting: null }">
    <div class="flex flex-col gap-4">
        <x-board.project-header :model="$model" page="Marketing" current="marketing" />
        <x-marketing.sub-nav :project="$model->name" current="settings" />
    </div>

    <div class="flex flex-col gap-1">
        <h2 class="text-xl font-semibold tracking-tight">Settings</h2>
        <p class="text-sm text-ink-muted">What every {{ $model->name }} post looks and sounds like.</p>
    </div>

    <div class="grid min-w-0 grid-cols-1 items-start gap-4 lg:grid-cols-12">
        {{-- Brand kit: preview, then the one brand form, then where the logo comes from. --}}
        <section aria-labelledby="brand-h" data-brand-kit class="{{ $card }} lg:col-span-7">
            <div class="flex flex-col gap-1">
                <h3 id="brand-h" class="text-base font-semibold">Brand kit</h3>
                @if ($brand?->logo_source)
                    <p data-logo-source class="font-mono text-xs break-all text-ink-muted">Logo source: {{ $brand->logo_source }}</p>
                @endif
                <p class="text-sm text-ink-muted">Every post uses these. Likes and feedback never change them.</p>
                @unless ($brand)
                    <p data-brand-defaults class="text-sm text-ink-muted">No brand saved yet: these are the defaults. Save to keep them.</p>
                @endunless
            </div>

            <div class="flex flex-wrap items-start gap-4">
                <figure class="flex flex-col gap-1.5 text-xs text-ink-muted">
                    <div data-brand-logo class="flex size-16 items-center justify-center rounded-lg border border-edge [&>svg]:size-10" style="background: {{ $saved['surface'] }}; color: {{ $saved['accent'] }}">
                        @if ($brand?->logo_svg)
                            {!! $brand->logo_svg !!}
                        @else
                            <span class="text-xs" style="color: {{ $saved['ink'] }}">No logo</span>
                        @endif
                    </div>
                    <figcaption>Logo</figcaption>
                </figure>
                <div class="grid min-w-0 flex-1 grid-cols-3 gap-2.5" role="list" aria-label="Brand colours">
                    @foreach (['accent' => 'Accent', 'ink' => 'Ink', 'surface' => 'Surface'] as $key => $word)
                        <div role="listitem" data-swatch="{{ $key }}" class="overflow-hidden rounded-lg border border-edge text-xs">
                            <i class="block h-10" style="background: {{ $saved[$key] }}" aria-hidden="true"></i>
                            <span class="block px-2 pt-1.5 text-ink-soft">{{ $word }}</span>
                            <code class="block px-2 pb-1.5 font-mono text-ink-muted">{{ $saved[$key] }}</code>
                        </div>
                    @endforeach
                </div>
            </div>

            <div data-brand-sample class="px-4 py-3.5" style="background: {{ $saved['surface'] }}; color: {{ $saved['ink'] }}; font-family: '{{ $saved['font'] }}', sans-serif; border-radius: {{ (int) $saved['radius'] }}px; box-shadow: {{ $shadows[$saved['shadow']] ?? 'none' }}">
                <p class="text-2xl font-semibold">{{ $saved['name'] }}</p>
                <p class="mt-0.5 text-sm opacity-70">{{ $saved['font'] }} · radius {{ (int) $saved['radius'] }}px · {{ $saved['shadow'] }} shadow</p>
            </div>

            <form wire:submit="saveBrand" novalidate data-brand-form class="flex flex-col gap-4 border-t border-edge pt-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="b-name" class="{{ $label }}">Brand name</label>
                        <input id="b-name" type="text" wire:model="name" autocomplete="off" @error('name') aria-invalid="true" @enderror aria-describedby="b-name-err" class="{{ $input }} {{ $border('name') }}">
                        <flux:error name="name" id="b-name-err" class="mt-1.5" />
                    </div>
                    @foreach (['accent' => 'Accent colour', 'ink' => 'Ink colour', 'surface' => 'Surface colour'] as $key => $word)
                        <div>
                            <label for="b-{{ $key }}" class="{{ $label }}">{{ $word }}</label>
                            <input id="b-{{ $key }}" type="text" wire:model="{{ $key }}" autocomplete="off" spellcheck="false" placeholder="#B79F82"
                                @error($key) aria-invalid="true" @enderror aria-describedby="b-{{ $key }}-err" class="{{ $input }} {{ $border($key) }} font-mono">
                            <flux:error :name="$key" id="b-{{ $key }}-err" class="mt-1.5" />
                        </div>
                    @endforeach
                    <div>
                        <label for="b-font" class="{{ $label }}">Font family</label>
                        <input id="b-font" type="text" wire:model="font" autocomplete="off" @error('font') aria-invalid="true" @enderror aria-describedby="b-font-err" class="{{ $input }} {{ $border('font') }}">
                        <flux:error name="font" id="b-font-err" class="mt-1.5" />
                    </div>
                    <div>
                        <label for="b-radius" class="{{ $label }}">Corner radius (px)</label>
                        <input id="b-radius" type="number" min="0" max="32" wire:model="radius" @error('radius') aria-invalid="true" @enderror aria-describedby="b-radius-err" class="{{ $input }} {{ $border('radius') }}">
                        <flux:error name="radius" id="b-radius-err" class="mt-1.5" />
                    </div>
                    <div>
                        <label for="b-shadow" class="{{ $label }}">Shadow</label>
                        <select id="b-shadow" wire:model="shadow" @error('shadow') aria-invalid="true" @enderror aria-describedby="b-shadow-err" class="{{ $input }} {{ $border('shadow') }}">
                            @foreach (\App\Models\MarketingBrand::SHADOWS as $option)
                                <option value="{{ $option }}">{{ ucfirst($option) }}</option>
                            @endforeach
                        </select>
                        <flux:error name="shadow" id="b-shadow-err" class="mt-1.5" />
                    </div>
                </div>
                <div class="flex justify-end">
                    <button type="submit" data-save-brand wire:loading.attr="disabled" wire:target="saveBrand" class="{{ $primary }}">
                        <span wire:loading.remove wire:target="saveBrand">Save brand</span>
                        <span wire:loading wire:target="saveBrand">Saving…</span>
                    </button>
                </div>
            </form>

            <div class="flex flex-col gap-4 border-t border-edge pt-4">
                <form wire:submit="readLogo" novalidate data-logo-repo-form class="flex flex-col gap-3">
                    <div>
                        <h4 class="text-sm font-medium">Logo from the repo</h4>
                        <p class="text-xs text-ink-muted">Reads an SVG from {{ $model->name }}'s repo, read-only. Scripts and outside links are stripped.</p>
                    </div>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div class="sm:col-span-2">
                            <label for="l-path" class="{{ $label }}">Path</label>
                            <input id="l-path" type="text" wire:model="logoPath" autocomplete="off" spellcheck="false" placeholder="resources/views/components/app-mark.blade.php"
                                @error('logoPath') aria-invalid="true" @enderror aria-describedby="l-path-err" class="{{ $input }} {{ $border('logoPath') }} font-mono">
                            <flux:error name="logoPath" id="l-path-err" class="mt-1.5" />
                        </div>
                        <div>
                            <label for="l-ref" class="{{ $label }}">Ref</label>
                            <input id="l-ref" type="text" wire:model="logoRef" autocomplete="off" spellcheck="false"
                                @error('logoRef') aria-invalid="true" @enderror aria-describedby="l-ref-err" class="{{ $input }} {{ $border('logoRef') }} font-mono">
                            <flux:error name="logoRef" id="l-ref-err" class="mt-1.5" />
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center justify-end gap-3">
                        <label class="{{ $secondary }} cursor-pointer focus-within:outline-2 focus-within:outline-offset-2">
                            <input type="file" wire:model="logoFile" accept=".svg,image/svg+xml" data-logo-upload class="sr-only">
                            <span wire:loading.remove wire:target="logoFile">Upload an SVG</span>
                            <span wire:loading wire:target="logoFile">Uploading…</span>
                        </label>
                        <button type="submit" data-read-logo wire:loading.attr="disabled" wire:target="readLogo" class="{{ $secondary }}">
                            <span wire:loading.remove wire:target="readLogo">Read logo</span>
                            <span wire:loading wire:target="readLogo">Reading…</span>
                        </button>
                    </div>
                    <flux:error name="logoFile" class="text-right" />
                </form>
            </div>
        </section>

        <div class="flex min-w-0 flex-col gap-4 lg:col-span-5">
            {{-- Favourite photos: a small optional set, not a library; most posts get their own photo in the studio (MK-9). --}}
            <section aria-labelledby="fav-h" data-favourites class="{{ $card }}">
                <div class="flex flex-col gap-1">
                    <h3 id="fav-h" class="flex flex-wrap items-center gap-2 text-base font-semibold">
                        Favourite photos <span class="rounded-full border border-rim px-2 py-0.5 text-xs font-normal text-ink-muted">optional</span>
                    </h3>
                    <p class="text-sm text-ink-muted">A few photos you love to show off. Campaigns set to "Pick from favourites" use these. Most posts get their own photo when you approve them.</p>
                </div>

                <ul role="list" class="grid grid-cols-3 gap-2 sm:grid-cols-4" data-favourite-grid>
                    <li>
                        <label class="flex aspect-square cursor-pointer flex-col items-center justify-center gap-1 rounded-md border-2 border-dashed border-rim p-1.5 text-center text-xs text-needs hover:border-needs hover:bg-raised focus-within:outline-2 focus-within:outline-offset-2 outline-needs">
                            <input type="file" wire:model="photos" multiple accept="image/jpeg,image/png,image/webp" data-photo-upload class="sr-only">
                            <span wire:loading.remove wire:target="photos" class="text-xl leading-none" aria-hidden="true">+</span>
                            <span wire:loading.remove wire:target="photos">Add favourites</span>
                            <span wire:loading wire:target="photos">Uploading…</span>
                        </label>
                    </li>
                    @foreach ($favourites as $photo)
                        <li wire:key="photo-{{ $photo->id }}" data-favourite="{{ $photo->id }}" class="relative min-w-0">
                            <img src="{{ route('marketing.asset', ['project' => $model->name, 'asset' => $photo->id]) }}" alt="{{ $photo->original_name }}"
                                loading="lazy" @if ($photo->width && $photo->height) width="{{ $photo->width }}" height="{{ $photo->height }}" @endif
                                class="block aspect-square w-full rounded-md bg-raised object-cover">
                            <p class="mt-1 truncate text-xs text-ink-muted" title="{{ $photo->original_name }}">{{ $photo->original_name }}</p>
                            <button type="button" data-delete-photo="{{ $photo->id }}" x-on:click="deleting = { id: {{ $photo->id }}, name: @js($photo->original_name) }"
                                aria-label="Delete {{ $photo->original_name }}"
                                class="absolute top-1 right-1 flex size-7 items-center justify-center rounded-full bg-ground/80 text-ink hover:bg-danger hover:text-ground {{ $focus }}">
                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
                            </button>
                        </li>
                    @endforeach
                </ul>
                @if ($photoErrors->isNotEmpty())
                    <div data-photo-errors role="alert" class="text-sm text-danger">
                        @foreach ($photoErrors as $message)
                            <p>{{ $message }}</p>
                        @endforeach
                    </div>
                @endif
                @if ($favourites->isEmpty())
                    <p data-favourites-empty class="text-sm text-ink-muted">No favourites yet. Add a few JPG, PNG or WebP photos, up to 10 MB each.</p>
                @endif
                <p class="text-xs text-ink-faint">Real coin photos will come from the coins app later.</p>
            </section>

            <form wire:submit="saveCopyRules" novalidate aria-labelledby="rules-h" data-copy-rules class="{{ $card }}">
                <div class="flex flex-col gap-1">
                    <h3 id="rules-h" class="text-base font-semibold">Copy rules</h3>
                    <p class="text-sm text-ink-muted">Checked on every caption as warnings, and given to the AI as its brief.</p>
                </div>
                <div>
                    <label for="c-voice" class="{{ $label }}">Voice</label>
                    <textarea id="c-voice" wire:model="voice" rows="4" @error('voice') aria-invalid="true" @enderror aria-describedby="c-voice-err" class="{{ $input }} {{ $border('voice') }}"></textarea>
                    <flux:error name="voice" id="c-voice-err" class="mt-1.5" />
                </div>
                <div>
                    <label for="c-filler" class="{{ $label }}">Filler phrases to avoid</label>
                    <textarea id="c-filler" wire:model="filler" rows="3" @error('filler') aria-invalid="true" @enderror aria-describedby="c-filler-hint c-filler-err" class="{{ $input }} {{ $border('filler') }}"></textarea>
                    <p id="c-filler-hint" class="mt-1.5 text-xs text-ink-muted">One per line, e.g. "tell us below".</p>
                    <flux:error name="filler" id="c-filler-err" class="mt-1.5" />
                </div>
                <div>
                    <label for="c-banned" class="{{ $label }}">Claims never to make</label>
                    <textarea id="c-banned" wire:model="banned" rows="3" @error('banned') aria-invalid="true" @enderror aria-describedby="c-banned-hint c-banned-err" class="{{ $input }} {{ $border('banned') }}"></textarea>
                    <p id="c-banned-hint" class="mt-1.5 text-xs text-ink-muted">One per line, e.g. "cert lookup".</p>
                    <flux:error name="banned" id="c-banned-err" class="mt-1.5" />
                </div>
                <div class="flex justify-end">
                    <button type="submit" data-save-rules wire:loading.attr="disabled" wire:target="saveCopyRules" class="{{ $primary }}">
                        <span wire:loading.remove wire:target="saveCopyRules">Save copy rules</span>
                        <span wire:loading wire:target="saveCopyRules">Saving…</span>
                    </button>
                </div>
            </form>

            {{-- Facebook is phase 2 (MK-12): shown so the owner knows it is coming, never usable. --}}
            <section aria-labelledby="fb-h" data-facebook-card class="{{ $card }} opacity-60">
                <div class="flex flex-col gap-1">
                    <h3 id="fb-h" class="flex flex-wrap items-center gap-2 text-base font-semibold">
                        Facebook Page <span class="rounded-full border border-rim px-2 py-0.5 text-xs font-normal text-ink-muted">Phase 2</span>
                    </h3>
                    <p class="text-sm text-ink-muted">Connecting a Page, posting on schedule and engagement come in phase 2. For now you post by hand.</p>
                </div>
                <div><button type="button" disabled aria-disabled="true" class="{{ $secondary }}">Connect a Page</button></div>
            </section>
        </div>
    </div>

    <x-board.confirm-modal show="deleting" confirm="Delete photo" action="$wire.deletePhoto(deleting.id).then(() => deleting = null)" target="deletePhoto" id="delete-photo" data-delete-photo-modal>
        <x-slot:title>Delete <span x-text="deleting?.name"></span>?</x-slot:title>
        It leaves your favourites. Posts that used it keep their caption and lose the photo.
    </x-board.confirm-modal>
</main>
