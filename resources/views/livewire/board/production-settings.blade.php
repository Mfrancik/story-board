{{-- SB-17 Production panel, mockup option A (docs/mockups/SB-17/option-a.html): opens in place under a project's
     row on /projects. Connection form + inline result, the paste-ready read-only user, then the metrics. Test results
     are inline status, never a toast. Remove connection confirms through board/confirm-modal (Alpine `removing`). --}}
@php
    $pid = $project->id;
    $input = 'mt-1 w-full min-w-0 rounded-lg border bg-white px-3 py-2 text-sm dark:bg-zinc-950';
    $small = 'mt-1 w-full min-w-0 rounded-lg border border-zinc-300 bg-white px-2 py-1.5 font-mono text-sm dark:border-zinc-700 dark:bg-zinc-950';
    $border = fn (string $field) => $errors->has($field) ? 'border-danger' : 'border-zinc-300 dark:border-zinc-700';
    $outline = 'rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium hover:bg-zinc-50 disabled:opacity-60 dark:border-zinc-700 dark:hover:bg-zinc-800';
    $primary = 'rounded-lg bg-zinc-800 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 disabled:opacity-60 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200';
    $mini = 'rounded-md border border-zinc-300 px-2.5 py-1 text-xs font-medium hover:bg-zinc-50 disabled:opacity-60 dark:border-zinc-700 dark:hover:bg-zinc-800';
    $connected = $connection !== null;
    $tables = array_keys($schema ?? []);
@endphp
<div class="px-4 py-5 sm:px-5" x-data="{ removing: null, copied: false }" data-prod-panel="{{ $project->name }}"
    @if ($connected && $schema === null && $schemaError === null) wire:init="loadSchema" @endif>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="font-medium">{{ $project->name }} · Production <span class="font-normal text-zinc-500 dark:text-zinc-400">— read-only</span></h2>
        @if ($connected)
            <button type="button" data-prod-remove x-on:click="removing = true"
                class="rounded-lg px-3 py-1.5 text-sm font-medium text-danger hover:bg-danger/10">Remove connection</button>
        @endif
    </div>

    <div class="mt-4 grid gap-5 lg:grid-cols-5">
        <section aria-labelledby="prod-{{ $pid }}-conn" class="space-y-4 rounded-xl border border-zinc-200 bg-white p-4 sm:p-5 lg:col-span-3 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h3 id="prod-{{ $pid }}-conn" class="text-sm font-semibold">Connection</h3>
                @if ($connection?->verified_at)
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">Last verified {{ $connection->verified_at->diffForHumans() }}</span>
                @endif
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
                <div class="sm:col-span-4">
                    <label for="prod-{{ $pid }}-host" class="block text-sm font-medium">Host <span class="text-danger" aria-hidden="true">*</span></label>
                    <input id="prod-{{ $pid }}-host" type="text" wire:model="host" placeholder="db.example.com" autocomplete="off" spellcheck="false"
                        @error('host') aria-invalid="true" @enderror aria-describedby="prod-{{ $pid }}-host-err" class="{{ $input }} {{ $border('host') }} font-mono">
                    <flux:error name="host" id="prod-{{ $pid }}-host-err" class="mt-1.5" />
                </div>
                <div class="sm:col-span-2">
                    <label for="prod-{{ $pid }}-port" class="block text-sm font-medium">Port</label>
                    <input id="prod-{{ $pid }}-port" type="number" inputmode="numeric" wire:model="port"
                        @error('port') aria-invalid="true" @enderror aria-describedby="prod-{{ $pid }}-port-err" class="{{ $input }} {{ $border('port') }} font-mono tabular-nums">
                    <flux:error name="port" id="prod-{{ $pid }}-port-err" class="mt-1.5" />
                </div>
                <div class="sm:col-span-3">
                    <label for="prod-{{ $pid }}-db" class="block text-sm font-medium">Database <span class="text-danger" aria-hidden="true">*</span></label>
                    <input id="prod-{{ $pid }}-db" type="text" wire:model="database" placeholder="{{ $project->name }}" autocomplete="off" spellcheck="false"
                        @error('database') aria-invalid="true" @enderror aria-describedby="prod-{{ $pid }}-db-err" class="{{ $input }} {{ $border('database') }} font-mono">
                    <flux:error name="database" id="prod-{{ $pid }}-db-err" class="mt-1.5" />
                </div>
                <div class="sm:col-span-3">
                    <label for="prod-{{ $pid }}-user" class="block text-sm font-medium">Username <span class="text-danger" aria-hidden="true">*</span></label>
                    <input id="prod-{{ $pid }}-user" type="text" wire:model="username" placeholder="board_ro" autocomplete="off" spellcheck="false"
                        @error('username') aria-invalid="true" @enderror aria-describedby="prod-{{ $pid }}-user-err" class="{{ $input }} {{ $border('username') }} font-mono">
                    <flux:error name="username" id="prod-{{ $pid }}-user-err" class="mt-1.5" />
                </div>
                <div class="sm:col-span-4">
                    <label for="prod-{{ $pid }}-pass" class="block text-sm font-medium">Password @unless ($connected)<span class="text-danger" aria-hidden="true">*</span>@endunless</label>
                    <input id="prod-{{ $pid }}-pass" type="password" wire:model="password" autocomplete="new-password"
                        placeholder="{{ $connected ? 'saved — leave blank to keep' : '' }}"
                        @error('password') aria-invalid="true" @enderror aria-describedby="prod-{{ $pid }}-pass-hint prod-{{ $pid }}-pass-err" class="{{ $input }} {{ $border('password') }}">
                    <p id="prod-{{ $pid }}-pass-hint" class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                        {{ $connected ? 'Stored encrypted. It is never shown again, only replaced.' : 'Stored encrypted in the board database.' }}
                    </p>
                    <flux:error name="password" id="prod-{{ $pid }}-pass-err" class="mt-1.5" />
                </div>
                <div class="flex items-start gap-3 sm:col-span-2 sm:pt-7">
                    {{-- The switch only edits the form; nothing is stored until Save, so it is plain wire:model state. --}}
                    <x-board.switch :on="$useSsl" label="Use SSL" wire:click="$toggle('useSsl')" data-prod-ssl />
                    <div class="text-sm leading-6"><span class="font-medium">SSL</span> <span class="text-zinc-500 dark:text-zinc-400">{{ $useSsl ? 'on' : 'off' }}</span></div>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button type="button" wire:click="testConnection" wire:loading.attr="disabled" wire:target="testConnection,save" data-prod-test class="{{ $outline }}">
                    <span wire:loading.remove wire:target="testConnection">Test connection</span>
                    <span wire:loading wire:target="testConnection">Testing…</span>
                </button>
                <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="testConnection,save" data-prod-save class="{{ $primary }}">
                    <span wire:loading.remove wire:target="save">Save connection</span>
                    <span wire:loading wire:target="save">Checking…</span>
                </button>
                <p class="basis-full text-xs text-zinc-500 dark:text-zinc-400">Saving runs the same check. A user that can write is refused and nothing is stored.</p>
            </div>

            <div role="status" aria-live="polite" data-prod-result="{{ $check['status'] ?? '' }}">
                <div wire:loading.flex wire:target="testConnection,save"
                    class="items-center gap-2 rounded-lg border border-zinc-200 px-3 py-3 text-sm text-zinc-600 dark:border-zinc-800 dark:text-zinc-300">
                    <svg class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3"/></svg>
                    Connecting… (gives up after {{ \App\Services\ProductionReader::TIMEOUT_SECONDS }} s)
                </div>
                @if ($check)
                    @php
                        $tick = '<svg class="mt-0.5 size-4 shrink-0 text-ok" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M5 12l5 5L20 7"/></svg>';
                        $cross = '<svg class="mt-0.5 size-4 shrink-0 text-danger" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>';
                        $saved = $checkAction === 'save';
                    @endphp
                    <div wire:loading.remove wire:target="testConnection,save" @class([
                        'rounded-lg border px-3 py-3 text-sm',
                        'border-ok/40 bg-ok/5' => $check['status'] === 'ok',
                        'border-danger/40 bg-danger/5' => in_array($check['status'], ['refused', 'failed'], true),
                        'border-warning/50 bg-warning/5' => $check['status'] === 'unreachable',
                    ])>
                        @if ($check['status'] === 'ok')
                            <p class="font-medium">{{ $saved ? 'Connection saved — read-only confirmed' : $check['message'] }}</p>
                        @else
                            <p @class(['font-medium', 'text-danger' => $check['status'] !== 'unreachable'])>{{ $check['message'] }}</p>
                        @endif
                        <ul class="mt-2 space-y-1.5 text-zinc-700 dark:text-zinc-300">
                            @if ($check['status'] === 'unreachable')
                                <li class="flex gap-2">{!! $cross !!}<span>Not reachable, so grants and version were not checked</span></li>
                            @elseif ($check['grants'] === [])
                                <li class="flex gap-2">{!! $cross !!}<span class="min-w-0 break-words">{{ $check['detail'] ?? 'Grants were not checked.' }}</span></li>
                            @else
                                <li class="flex gap-2">{!! $tick !!}<span>Reachable · <span class="font-mono text-xs break-all">{{ $check['target'] }}</span> · {{ $check['ssl'] ? 'SSL' : 'no SSL' }}</span></li>
                                <li class="flex gap-2">{!! $check['privilege'] ? $cross : $tick !!}<span class="min-w-0">
                                    @if ($check['privilege'])
                                        Grants include <span class="font-medium">{{ $check['privilege'] }}</span>
                                    @else
                                        Grants <span class="font-medium">SELECT only</span>
                                    @endif
                                    @foreach ($check['grants'] as $grant)
                                        <span class="block font-mono text-xs break-all text-zinc-500 dark:text-zinc-400">{{ $grant }}</span>
                                    @endforeach
                                </span></li>
                                <li class="flex gap-2">{!! $check['privilege'] ? '<span class="mt-0.5 grid size-4 shrink-0 place-items-center text-zinc-400" aria-hidden="true">–</span>' : $tick !!}<span>MySQL {{ $check['version'] }} · <span class="tabular-nums">{{ $check['ms'] }} ms</span></span></li>
                            @endif
                        </ul>
                        @if ($check['status'] === 'unreachable')
                            <p class="mt-2 text-zinc-600 dark:text-zinc-400">Check the host and port, and that the database server accepts connections from this machine.@if ($saved) Nothing was saved.@endif</p>
                        @elseif ($check['status'] !== 'ok')
                            <p class="mt-2 text-zinc-600 dark:text-zinc-400">{{ $saved ? 'Nothing was saved.' : 'Saving would be refused.' }}</p>
                        @endif
                    </div>
                @endif
            </div>
        </section>

        <aside class="space-y-3 lg:col-span-2">
            <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-800 dark:bg-zinc-950/60">
                <div class="flex items-start justify-between gap-3">
                    <p class="text-sm"><span class="font-medium">Create the read-only user</span><span class="block text-xs text-zinc-500 dark:text-zinc-400">Run once as an admin on the production server, with your own password in place of …</span></p>
                    <button type="button" x-on:click="navigator.clipboard?.writeText($refs.oneLiner.textContent); copied = true; setTimeout(() => copied = false, 1800)"
                        class="{{ $mini }} shrink-0" :aria-label="copied ? 'Copied' : 'Copy SQL'"><span x-text="copied ? 'Copied' : 'Copy'">Copy</span></button>
                </div>
                <pre class="mt-2 font-mono text-xs leading-5 break-all whitespace-pre-wrap text-zinc-800 dark:text-zinc-200"><code x-ref="oneLiner">{{ $oneLiner }}</code></pre>
                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">Swap <span class="font-mono">'%'</span> for this machine's address if you can. The board refuses any user that can do more than SELECT.</p>
            </div>
            <div class="space-y-1.5 rounded-lg border border-zinc-200 p-3 text-xs text-zinc-600 dark:border-zinc-800 dark:text-zinc-400">
                <p class="text-sm font-medium text-zinc-800 dark:text-zinc-200">How the board keeps it read-only</p>
                <p>Every save and every read runs <span class="font-mono">SHOW GRANTS</span>. Anything beyond SELECT is refused.</p>
                <p>Each query runs in a read-only session with a {{ \App\Services\ProductionReader::TIMEOUT_SECONDS }} s limit.</p>
            </div>
        </aside>
    </div>

    <section aria-labelledby="prod-{{ $pid }}-metrics" class="mt-5 space-y-4 rounded-xl border border-zinc-200 bg-white p-4 sm:p-5 dark:border-zinc-800 dark:bg-zinc-900">
        <h3 id="prod-{{ $pid }}-metrics" class="text-sm font-semibold">Metrics</h3>

        @if (! $connected)
            <div class="rounded-lg border border-dashed border-zinc-300 px-4 py-6 text-center dark:border-zinc-700">
                <p class="text-sm font-medium">Metrics come after the connection</p>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Save a connection that passes the read-only check. Its tables and columns then load here for the pickers.</p>
            </div>
        @else
            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                "Today" is since 00:00 in the board's timezone (<span class="font-mono">{{ $today['timezone'] }}</span>), sent to production as UTC:
                <span class="font-mono">{{ $today['since'] }}</span>.
                @if ($schemaError)
                    <span class="block text-warning">Could not list the tables ({{ $schemaError }}) — type the names instead.</span>
                @endif
            </p>

            {{-- Picker suggestions from production's own tables; the inputs still take any name, so a failed listing never blocks setup. --}}
            <datalist id="prod-{{ $pid }}-tables">@foreach ($tables as $t)<option value="{{ $t }}"></option>@endforeach</datalist>

            <div>
                <h4 class="mb-2 text-xs font-semibold tracking-wider text-zinc-400 uppercase">Presets</h4>
                <ul class="divide-y divide-zinc-100 rounded-lg border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-800">
                    @foreach (\App\Models\ProdMetric::PRESETS as $key => $preset)
                        @php
                            $form = $presets[$key];
                            $result = $results[$key] ?? null;
                            $cols = $schema[$form['table']] ?? null;
                        @endphp
                        <li wire:key="preset-{{ $pid }}-{{ $key }}" class="px-3 py-3" data-preset="{{ $key }}">
                            <div class="flex items-start gap-3">
                                <x-board.switch :on="$form['enabled']" label="Use {{ $preset['label'] }}" wire:click="$toggle('presets.{{ $key }}.enabled')" />
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                        <p class="text-sm"><span class="font-medium">{{ $preset['label'] }}</span> <span class="text-zinc-500 dark:text-zinc-400">— {{ $preset['hint'] }}</span></p>
                                        @if ($form['enabled'])
                                            <div class="flex items-center gap-2">
                                                <x-board.metric-result :result="$result" target="testPreset('{{ $key }}')" />
                                                <button type="button" wire:click="testPreset('{{ $key }}')" wire:loading.attr="disabled" wire:target="testPreset('{{ $key }}')" class="{{ $mini }}">Test<span class="sr-only"> {{ $preset['label'] }}</span></button>
                                            </div>
                                        @endif
                                    </div>
                                    @if ($form['enabled'])
                                        <div class="mt-3 space-y-2">
                                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                                <div>
                                                    <label for="prod-{{ $pid }}-{{ $key }}-t" class="block text-xs font-medium text-zinc-600 dark:text-zinc-400">Table</label>
                                                    <input id="prod-{{ $pid }}-{{ $key }}-t" type="text" list="prod-{{ $pid }}-tables" wire:model.blur="presets.{{ $key }}.table" autocomplete="off" spellcheck="false" class="{{ $small }}">
                                                    <flux:error name="presets.{{ $key }}.table" class="mt-1" />
                                                </div>
                                                @if (in_array('column', $preset['fields'], true))
                                                    <div>
                                                        <label for="prod-{{ $pid }}-{{ $key }}-c" class="block text-xs font-medium text-zinc-600 dark:text-zinc-400">Timestamp column</label>
                                                        <input id="prod-{{ $pid }}-{{ $key }}-c" type="text" list="prod-{{ $pid }}-{{ $key }}-cols" wire:model.blur="presets.{{ $key }}.column" autocomplete="off" spellcheck="false" class="{{ $small }}">
                                                        <datalist id="prod-{{ $pid }}-{{ $key }}-cols">@foreach ($cols['timestamps'] ?? [] as $c)<option value="{{ $c }}"></option>@endforeach</datalist>
                                                    </div>
                                                @endif
                                                @if (in_array('distinct', $preset['fields'], true))
                                                    <div>
                                                        <label for="prod-{{ $pid }}-{{ $key }}-d" class="block text-xs font-medium text-zinc-600 dark:text-zinc-400">Count distinct <span class="font-normal">(blank: every row)</span></label>
                                                        <input id="prod-{{ $pid }}-{{ $key }}-d" type="text" list="prod-{{ $pid }}-{{ $key }}-ids" wire:model.blur="presets.{{ $key }}.distinct" autocomplete="off" spellcheck="false" class="{{ $small }}">
                                                        <datalist id="prod-{{ $pid }}-{{ $key }}-ids">@foreach ($cols['columns'] ?? [] as $c)<option value="{{ $c }}"></option>@endforeach</datalist>
                                                    </div>
                                                @endif
                                            </div>
                                            <details class="text-xs"><summary class="cursor-pointer text-zinc-500 dark:text-zinc-400">Show SQL</summary>
                                                <pre class="mt-1 font-mono break-all whitespace-pre-wrap text-zinc-700 dark:text-zinc-300">{{ $presetSql[$key] ?? '— pick a table and column first —' }}</pre></details>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div>
                <h4 class="mb-2 text-xs font-semibold tracking-wider text-zinc-400 uppercase">Custom SQL</h4>
                @if ($customs === [])
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">No custom metrics. Add one for any number a single SELECT can count.</p>
                @endif
                <ul class="space-y-3">
                    @foreach ($customs as $i => $custom)
                        @php $result = $results[$custom['key']] ?? null; @endphp
                        <li wire:key="custom-{{ $custom['key'] }}" data-custom="{{ $custom['key'] }}" @class([
                            'rounded-lg border p-3',
                            'border-danger/40' => ($result['status'] ?? null) === 'refused' || $errors->has("customs.{$i}.sql"),
                            'border-zinc-200 dark:border-zinc-800' => ! (($result['status'] ?? null) === 'refused' || $errors->has("customs.{$i}.sql")),
                        ])>
                            <div class="grid grid-cols-1 gap-3">
                                <div class="flex items-end gap-2">
                                    <div class="min-w-0 flex-1">
                                        <label for="prod-{{ $pid }}-n-{{ $custom['key'] }}" class="block text-xs font-medium text-zinc-600 dark:text-zinc-400">Name</label>
                                        <input id="prod-{{ $pid }}-n-{{ $custom['key'] }}" type="text" wire:model.blur="customs.{{ $i }}.label" placeholder="Paid subscribers" class="{{ $input }} {{ $border("customs.{$i}.label") }}">
                                    </div>
                                    <button type="button" wire:click="dropCustom({{ $i }})" class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" aria-label="Delete metric {{ $custom['label'] ?: 'untitled' }}">
                                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>
                                    </button>
                                </div>
                                <flux:error name="customs.{{ $i }}.label" />
                                <div>
                                    <label for="prod-{{ $pid }}-q-{{ $custom['key'] }}" class="block text-xs font-medium text-zinc-600 dark:text-zinc-400">SQL — one SELECT that returns one number</label>
                                    <textarea id="prod-{{ $pid }}-q-{{ $custom['key'] }}" wire:model.blur="customs.{{ $i }}.sql" rows="3" spellcheck="false"
                                        @if (($result['status'] ?? null) === 'refused' || $errors->has("customs.{$i}.sql")) aria-invalid="true" @endif
                                        class="{{ $input }} {{ $border("customs.{$i}.sql") }} font-mono text-xs leading-5"></textarea>
                                    <flux:error name="customs.{{ $i }}.sql" class="mt-1" />
                                </div>
                                <div class="flex flex-wrap items-center gap-3">
                                    <button type="button" wire:click="testCustom({{ $i }})" wire:loading.attr="disabled" wire:target="testCustom({{ $i }})" class="{{ $mini }}">Test<span class="sr-only"> {{ $custom['label'] }}</span></button>
                                    <x-board.metric-result :result="$result" target="testCustom({{ $i }})" />
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
                <button type="button" wire:click="addCustom" class="mt-3 text-sm font-medium underline-offset-4 hover:underline">+ Add custom metric</button>
            </div>

            <div class="flex justify-end">
                <button type="button" wire:click="saveMetrics" wire:loading.attr="disabled" wire:target="saveMetrics" data-prod-save-metrics class="{{ $primary }}">
                    <span wire:loading.remove wire:target="saveMetrics">Save metrics</span>
                    <span wire:loading wire:target="saveMetrics">Saving…</span>
                </button>
            </div>
        @endif
    </section>

    @if ($connected)
        <x-board.confirm-modal show="removing" confirm="Remove connection" action="$wire.remove(); removing = null" target="remove" id="remove-prod-{{ $pid }}" data-prod-remove-modal>
            <x-slot:title>Remove {{ $project->name }}'s production connection?</x-slot:title>
            Its saved credentials and metrics are deleted from the board. Nothing in {{ $project->name }}'s production database is
            touched, and the read-only user still exists there.
        </x-board.confirm-modal>
    @endif
</div>
