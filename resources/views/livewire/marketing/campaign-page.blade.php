{{--
    MK-7 Campaign page (docs/mockups/MK-prototype/index.html, campaign route; criteria = docs/mockups/MK-7/option-a.html)
    in the RD-2 shell: the project header with Marketing current and the marketing sub-nav; back to Campaigns; the
    header (name, schedule, on/off switch, the campaign in one sentence); the runway status line; then from 1024 px two
    columns, Future posts and Past posts on the left and the Criteria form on the right (one column below 1024 px).

    Create mode (`/campaigns/new`, no `$campaign`) is the same form alone under "New campaign", with Cancel and
    "Create campaign". The "Unsaved changes" marker is Alpine: `dirty` compares the deferred inputs ($wire) with
    `stored`, normalised the same way as CampaignPage::snapshot(), so typing never makes a request.
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
    $pillToggle = 'inline-flex min-h-10 cursor-pointer items-center justify-center rounded-lg border border-rim px-3 text-sm text-ink-soft select-none hover:border-ink-faint peer-checked:border-needs peer-checked:bg-needs/15 peer-checked:text-needs peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-needs';
    $dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    $photoOptions = [
        \App\Models\MarketingCampaign::PHOTO_PER_POST => ["I'll add a photo per post", 'You upload it in the studio'],
        \App\Models\MarketingCampaign::PHOTO_FAVOURITES => ['Pick from favourites', 'The AI picks one of '.$favourites.' '.Str::plural('favourite', $favourites)],
        \App\Models\MarketingCampaign::PHOTO_TEXT_ONLY => ['Text only', 'The brand text card'],
        \App\Models\MarketingCampaign::PHOTO_AI_DECIDES => ['Let AI decide', 'A favourite photo or text'],
    ];
    $ticked = fn (string $category) => collect($this->ideas)->filter(fn ($k) => str_starts_with($k, $category.'|'))->count();
@endphp
<main data-campaign-page="{{ $campaign?->slug ?? 'new' }}" class="mx-auto flex max-w-7xl min-w-0 flex-col gap-6 px-4 py-6 sm:px-6 lg:px-10 lg:py-10">
    <div class="flex flex-col gap-4">
        <x-board.project-header :model="$model" page="Marketing" current="marketing" />
        <x-marketing.sub-nav :project="$model->name" current="campaigns" />
    </div>

    <div class="flex flex-col gap-3">
        <a href="{{ route('projects.marketing', $model->name) }}" wire:navigate class="self-start text-sm text-ink-muted hover:text-ink hover:underline {{ $focus }}">‹ Campaigns</a>

        @if ($campaign)
            <div data-campaign-header class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <h2 class="min-w-0 text-2xl font-semibold tracking-tight break-words">{{ $campaign->name }}</h2>
                <span class="font-mono text-sm text-ink-muted">{{ $campaign->scheduleLabel() }}</span>
                <span class="flex items-center gap-2 text-sm text-ink-soft sm:ml-auto">
                    <span data-campaign-on>{{ $campaign->is_enabled ? 'On' : 'Paused' }}</span>
                    <x-board.switch :on="$campaign->is_enabled" :label="$campaign->name.' on'" target="toggle" wire:click="toggle" />
                </span>
            </div>
            <p data-sentence class="text-base leading-relaxed text-ink-soft">{{ $campaign->sentence() }}.</p>

            @if ($status)
                <section data-status-line aria-label="Approval runway" class="flex flex-col gap-2.5 rounded-xl border border-edge bg-surface px-4 py-3.5">
                    <p class="text-base leading-relaxed text-ink-soft">
                        <b data-approved-through @class(['font-semibold', 'text-go' => $status['approved_through'], 'text-needs' => ! $status['approved_through']])>{{ $status['approved_through'] ? 'Approved through '.$status['approved_through']->format('D j M') : 'Nothing approved yet' }}</b>
                        · {{ $status['to_approve'] }} {{ $status['to_approve'] === 1 ? 'date needs' : 'dates need' }} approval
                        · {{ $status['open_dates'] }} open
                        @if ($status['missed'])
                            · <span class="text-fail">missed {{ $status['missed']->format('D j M') }}</span>
                        @endif
                    </p>
                    <x-marketing.runway-bar :runway="$status['runway']" :approved="$status['approved']" />
                </section>
            @endif
        @else
            <h2 class="text-2xl font-semibold tracking-tight">New campaign</h2>
            <section aria-label="This campaign in one sentence" class="flex flex-col gap-1 rounded-xl border border-needs/40 bg-needs/5 px-4 py-3.5">
                <span class="text-xs font-medium tracking-wider text-needs uppercase">In one sentence</span>
                <p data-sentence class="text-base leading-relaxed text-ink-soft">{{ $sentence }}.</p>
            </section>
        @endif
    </div>

    <div class="grid min-w-0 grid-cols-1 items-start gap-4 lg:grid-cols-12">
        @if ($campaign)
            <div class="flex min-w-0 flex-col gap-4 lg:col-span-7">
                <section data-future-posts aria-labelledby="fp-h" class="{{ $card }}">
                    <div class="flex flex-wrap items-baseline gap-x-2">
                        <h3 id="fp-h" class="text-base font-semibold">Future posts</h3>
                        <span class="text-xs text-ink-faint">next {{ \App\Actions\Marketing\ListCampaignOccurrences::COUNT }} dates</span>
                    </div>
                    @unless ($campaign->is_enabled)
                        <p class="text-sm text-ink-muted">Paused: these dates stay empty until you turn it back on.</p>
                    @endunless
                    @if ($error)
                        <p data-timeline-error class="text-sm text-fail">This campaign's dates can't be worked out: {{ $error }} Fix its days, time or timezone below.</p>
                    @elseif ($timeline === [])
                        <p class="text-sm text-ink-muted">No dates yet. Pick a day below to see them.</p>
                    @else
                        <ol role="list" class="-mx-2 flex flex-col">
                            @foreach ($timeline as $occurrence)
                                <x-marketing.occurrence-row :occurrence="$occurrence" :project="$model->name" :photo-mode="$campaign->photo_mode" wire:key="occ-{{ $occurrence['date'] }}" />
                            @endforeach
                        </ol>
                    @endif
                </section>

                <section data-past-posts aria-labelledby="pp-h" class="{{ $card }}">
                    <div class="flex items-baseline gap-2">
                        <h3 id="pp-h" class="text-base font-semibold">Past posts</h3>
                        <span class="font-mono text-xs text-ink-faint">{{ $past->count() }}</span>
                    </div>
                    @if ($past->isEmpty())
                        <p class="text-sm text-ink-muted">Nothing posted yet. Posts you mark as posted appear here, newest first.</p>
                    @else
                        <ul role="list" class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            @foreach ($past as $post)
                                <li data-past-post class="flex min-w-0 items-center gap-2.5 rounded-lg border border-edge bg-ground p-2">
                                    @if ($post->asset_id)
                                        <img src="{{ route('marketing.asset', [$model->name, $post->asset_id]) }}" alt="Photo posted {{ ($post->posted_at ?? $post->scheduled_for)?->format('D j M') }}" class="size-12 shrink-0 rounded-md object-cover">
                                    @else
                                        <span class="flex size-12 shrink-0 items-center justify-center rounded-md border border-rim bg-raised text-xs text-ink-soft" aria-hidden="true">Aa</span>
                                    @endif
                                    <span class="flex min-w-0 flex-col gap-0.5 text-xs">
                                        <b class="font-medium text-ink">{{ ($post->posted_at ?? $post->scheduled_for)?->format('D j M Y') }}</b>
                                        <span class="truncate text-ink-soft">{{ Str::before((string) $post->caption, "\n") }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>
        @endif

        {{-- Criteria: one form for create and edit. --}}
        <section data-criteria aria-labelledby="cr-h" @class([$card, 'lg:col-span-5' => $campaign, 'lg:col-span-8' => ! $campaign])
            x-data="{
                current() {
                    return JSON.stringify({
                        name: $wire.name, days: [...$wire.days].map(Number).sort((a, b) => a - b), time: $wire.time, timezone: $wire.timezone,
                        ideas: [...$wire.ideas].sort(), own: $wire.own.map(o => ({ text: o.text, detail: o.detail ?? '' })),
                        brief: $wire.brief ?? '', photoMode: $wire.photoMode,
                    });
                },
                get dirty() { return this.current() !== JSON.stringify($wire.stored); },
            }">
            <div class="flex flex-wrap items-baseline gap-x-2">
                <h3 id="cr-h" class="text-base font-semibold">Criteria</h3>
                <span class="text-xs text-ink-faint">what the AI writes from</span>
            </div>

            <form wire:submit="save" novalidate class="flex flex-col gap-5">
                <div>
                    <label for="cp-name" class="{{ $label }}">Name</label>
                    <input id="cp-name" type="text" wire:model="name" maxlength="60" autocomplete="off" placeholder="e.g. TrueView Tuesday"
                        @error('name') aria-invalid="true" @enderror aria-describedby="cp-name-err" class="{{ $input }} {{ $border('name') }}">
                    <flux:error name="name" id="cp-name-err" class="mt-1.5" />
                </div>

                <fieldset data-days>
                    <legend class="{{ $label }}">Every week on</legend>
                    <div class="mt-1 flex flex-wrap gap-1.5">
                        @foreach ($dayNames as $iso => $day)
                            <label class="relative">
                                <input type="checkbox" wire:model="days" value="{{ $iso }}" class="peer sr-only" data-day="{{ $iso }}">
                                <span class="{{ $pillToggle }} min-w-11">{{ $day }}</span>
                            </label>
                        @endforeach
                    </div>
                    <flux:error name="days" class="mt-1.5" />
                    <flux:error name="days.*" class="mt-1.5" />
                </fieldset>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="cp-time" class="{{ $label }}">At</label>
                        <input id="cp-time" type="time" wire:model="time" @error('time') aria-invalid="true" @enderror aria-describedby="cp-time-err" class="{{ $input }} {{ $border('time') }} scheme-dark">
                        <flux:error name="time" id="cp-time-err" class="mt-1.5" />
                    </div>
                    <div>
                        <label for="cp-tz" class="{{ $label }}">Timezone</label>
                        <select id="cp-tz" wire:model="timezone" @error('timezone') aria-invalid="true" @enderror aria-describedby="cp-tz-err" class="{{ $input }} {{ $border('timezone') }}">
                            @unless (in_array($timezone, $timezones, true))
                                <option value="{{ $timezone }}">{{ $timezone }} (not a timezone)</option>
                            @endunless
                            @foreach ($timezones as $zone)
                                <option value="{{ $zone }}">{{ $zone }}</option>
                            @endforeach
                        </select>
                        <flux:error name="timezone" id="cp-tz-err" class="mt-1.5" />
                    </div>
                </div>

                <div class="flex flex-col gap-2">
                    <h4 class="text-sm font-medium">Topics in your own words <span class="font-normal text-ink-faint">a line each, detail optional</span></h4>
                    <div class="flex gap-2">
                        <input type="text" wire:model="newTopic" wire:keydown.enter.prevent="addOwnTopic" maxlength="120" aria-label="New topic in your own words"
                            placeholder="Add a topic, e.g. my rainbow Morgan from the Baltimore show" data-new-topic
                            @error('newTopic') aria-invalid="true" @enderror class="{{ $input }} {{ $border('newTopic') }} mt-0 min-w-0 flex-1">
                        <button type="button" wire:click="addOwnTopic" wire:loading.attr="disabled" wire:target="addOwnTopic" data-add-topic class="{{ $secondary }}">Add</button>
                    </div>
                    <flux:error name="newTopic" />
                    @if ($own === [])
                        <p class="text-sm text-ink-faint">Nothing yet. Type a topic above and press Enter: a coin you just got, a story, a show you went to.</p>
                    @else
                        <ol role="list" data-own-topics class="flex flex-col gap-1.5">
                            @foreach ($own as $i => $topic)
                                <li wire:key="own-{{ $i }}" data-own-topic x-data="{ open: false }" class="flex flex-col gap-1 rounded-lg border border-rim bg-ground py-1 pr-1 pl-2.5">
                                    <div class="flex flex-wrap items-center gap-1 sm:flex-nowrap">
                                        <span class="text-sm text-needs" aria-hidden="true">✎</span>
                                        <input type="text" wire:model="own.{{ $i }}.text" value="{{ $topic['text'] }}" maxlength="120" aria-label="Topic {{ $i + 1 }}"
                                            class="min-h-10 min-w-0 flex-1 rounded-md bg-transparent px-1 text-sm font-medium text-ink hover:bg-raised {{ $focus }}">
                                        <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open" class="min-h-10 rounded-md px-2 text-sm whitespace-nowrap text-needs hover:bg-raised {{ $focus }}"
                                            x-text="open ? 'Done' : ($wire.own[{{ $i }}]?.detail ? 'Edit detail' : '+ Detail')">{{ $topic['detail'] !== '' ? 'Edit detail' : '+ Detail' }}</button>
                                        <button type="button" wire:click="removeOwnTopic({{ $i }})" aria-label="Remove topic {{ $topic['text'] }}" data-remove-topic
                                            class="min-h-10 w-10 rounded-md text-base text-ink-faint hover:bg-raised hover:text-fail {{ $focus }}">×</button>
                                    </div>
                                    <textarea x-show="open" x-cloak wire:model="own.{{ $i }}.detail" rows="2" maxlength="1000" aria-label="Detail for topic {{ $i + 1 }}"
                                        placeholder="Optional detail in your words: facts, the angle, what to avoid" class="{{ $input }} border-rim mt-0 mb-1 w-auto"></textarea>
                                    <p data-own-detail x-show="! open && $wire.own[{{ $i }}]?.detail" x-text="$wire.own[{{ $i }}]?.detail" class="mb-1.5 ml-5 text-xs leading-relaxed text-ink-muted">{{ $topic['detail'] }}</p>
                                    @error('own.'.$i.'.text')
                                        <p class="mb-1 ml-5 text-xs text-fail">{{ $message }}</p>
                                    @enderror
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </div>

                <div class="flex flex-col gap-1" data-idea-menu>
                    <h4 class="text-sm font-medium">From the idea menu <span class="font-normal text-ink-faint">tick any; hover for the sample line</span></h4>
                    @forelse ($menu as $category)
                        <details data-idea-category class="group border-t border-hairline first-of-type:border-t-0" @if ($ticked($category['category']) > 0) open @endif>
                            <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 text-sm text-ink-soft {{ $focus }}">
                                {{ $category['category'] }}
                                <span class="font-mono text-xs text-needs" x-text="(n => n ? n + ' ticked' : '')($wire.ideas.filter(k => k.startsWith(@js($category['category'].'|'))).length)">{{ $ticked($category['category']) ? $ticked($category['category']).' ticked' : '' }}</span>
                                <span class="ml-auto text-ink-faint group-open:rotate-90" aria-hidden="true">▸</span>
                            </summary>
                            <div class="flex flex-wrap gap-1.5 pb-3">
                                @foreach ($category['ideas'] as $idea)
                                    <label class="relative" title="{{ $idea['text'] }}">
                                        <input type="checkbox" wire:model="ideas" value="{{ $category['category'] }}|{{ $idea['title'] }}" class="peer sr-only">
                                        <span class="{{ $pillToggle }} text-left">{{ $idea['title'] }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </details>
                    @empty
                        <p class="text-sm text-ink-faint">No idea menu yet. It comes from the brand kit (<code class="font-mono">marketing:seed-kit</code>); your own topics above work without it.</p>
                    @endforelse
                    @if ($offMenu !== [])
                        <div data-off-menu class="flex flex-col gap-1.5 border-t border-hairline pt-2">
                            <span class="text-xs text-ink-muted">No longer on the menu: untick to drop</span>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($offMenu as $key)
                                    <label class="relative">
                                        <input type="checkbox" wire:model="ideas" value="{{ $key }}" class="peer sr-only">
                                        <span class="{{ $pillToggle }}">{{ Str::after($key, '|') ?: $key }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                <div>
                    <label for="cp-brief" class="{{ $label }}">Brief</label>
                    <textarea id="cp-brief" wire:model="brief" rows="3" maxlength="240" placeholder="e.g. Show off the toning. One line, then ask people to share theirs."
                        @error('brief') aria-invalid="true" @enderror aria-describedby="cp-brief-hint" class="{{ $input }} {{ $border('brief') }}"></textarea>
                    <p id="cp-brief-hint" class="mt-1 text-xs text-ink-muted">One or two sentences. The AI writes every caption from this.</p>
                    <flux:error name="brief" class="mt-1.5" />
                </div>

                <fieldset>
                    <legend class="{{ $label }}">Photos</legend>
                    <div class="mt-1 grid grid-cols-1 gap-2 sm:grid-cols-2">
                        @foreach ($photoOptions as $value => [$title, $hint])
                            <label class="relative">
                                <input type="radio" wire:model="photoMode" value="{{ $value }}" class="peer sr-only" data-photo-mode="{{ $value }}">
                                <span class="flex min-h-14 cursor-pointer flex-col gap-0.5 rounded-lg border border-rim px-3 py-2 text-sm font-medium text-ink-soft hover:border-ink-faint peer-checked:border-needs peer-checked:bg-needs/15 peer-checked:text-needs peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-needs">
                                    {{ $title }}
                                    <span class="text-xs font-normal text-ink-muted">{{ $hint }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    <flux:error name="photoMode" class="mt-1.5" />
                    <p class="mt-1.5 text-xs text-ink-muted">Favourite photos: {{ $favourites }} · <a href="{{ route('projects.marketing.settings', $model->name) }}" wire:navigate class="underline hover:text-ink">manage</a>.</p>
                </fieldset>

                <div data-savebar class="sticky bottom-0 -mx-4 flex flex-wrap items-center gap-2 border-t border-hairline bg-surface px-4 pt-3 pb-1 sm:-mx-5 sm:px-5">
                    @if ($campaign)
                        <span data-unsaved x-show="dirty" x-cloak class="text-xs text-needs">Unsaved changes</span>
                        <span data-saved x-show="! dirty" class="text-xs text-ink-faint">All saved</span>
                    @endif
                    @if ($errors->any())
                        <span class="text-xs text-fail">Fix the fields marked above.</span>
                    @endif
                    <span class="flex-1"></span>
                    @if ($campaign)
                        <button type="button" wire:click="discard" wire:loading.attr="disabled" wire:target="discard" data-discard class="{{ $secondary }}">Discard</button>
                        <button type="submit" wire:loading.attr="disabled" wire:target="save" data-save class="{{ $primary }}">Save changes</button>
                    @else
                        <a href="{{ route('projects.marketing', $model->name) }}" wire:navigate class="{{ $secondary }}">Cancel</a>
                        <button type="submit" wire:loading.attr="disabled" wire:target="save" data-save class="{{ $primary }}">Create campaign</button>
                    @endif
                </div>
            </form>
        </section>
    </div>
</main>
