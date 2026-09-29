<?php

namespace App\Livewire\Board;

use App\Actions\Board\RemoveProdConnection;
use App\Actions\Board\SaveProdConnection;
use App\Actions\Board\SaveProdMetrics;
use App\Actions\Board\TestProdConnection;
use App\Actions\Board\TestProdMetric;
use App\Exceptions\ProdMetricsRefusedException;
use App\Exceptions\ProductionRefusedException;
use App\Models\ProdMetric;
use App\Models\Project;
use App\Services\ProductionReader;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One project's Production panel on /projects (SB-17, mockup option A): the
 * read-only connection form with Test and Save, the result inline, the
 * paste-ready read-only user, and the metrics — four presets and custom SELECTs,
 * each with a Test. Rendered lazily under the project's row, so nothing is read
 * until the owner opens it. Writes go through the Prod* actions; every
 * production call goes through ProductionReader.
 *
 * The stored password is never put into a property: the form's `password` is
 * only what the owner types, and is cleared once saved.
 */
class ProductionSettings extends Component
{
    /** The project whose production database this is. Locked: the browser cannot point it at another. */
    #[Locked]
    public Project $project;

    public string $host = '';

    public string $port = '3306';

    public string $database = '';

    public string $username = '';

    /** Only what the owner has typed; blank keeps the saved password. */
    public string $password = '';

    public bool $useSsl = true;

    /** @var array<string, mixed>|null the last Test or Save result (ConnectionCheck::toArray) */
    public ?array $check = null;

    /** Which button produced `check`: test | save. */
    public ?string $checkAction = null;

    /** @var array<string, array{enabled: bool, table: string, column: string, distinct: string}> keyed by ProdMetric::PRESETS key */
    public array $presets = [];

    /** @var list<array{key: string, label: string, sql: string}> */
    public array $customs = [];

    /** @var array<string, array<string, mixed>> the last Test per metric key (MetricReading::toArray) */
    public array $results = [];

    /** @var array<string, array{columns: list<string>, timestamps: list<string>}>|null production tables, for the pickers */
    public ?array $schema = null;

    /** Why the tables could not be listed, if they could not. */
    public ?string $schemaError = null;

    /**
     * Fill the form from the saved connection (never its password) and metrics.
     */
    public function mount(Project $project): void
    {
        // Without relations: the caller's instance may hold a stale prodConnection, and this one must read fresh.
        $this->project = $project->withoutRelations();
        $this->fillConnection();
        $this->fillMetrics();
    }

    /**
     * A small stand-in while the panel loads on first open.
     */
    public function placeholder(): string
    {
        return '<p class="px-1 py-4 text-sm text-zinc-500 dark:text-zinc-400">Loading production settings…</p>';
    }

    /**
     * "Test connection": check what the form holds; store nothing. The result shows inline.
     */
    public function testConnection(TestProdConnection $test): void
    {
        $this->validate();

        try {
            $this->showCheck($test->handle($this->project, $this->input())->toArray(), 'test');
        } catch (ProductionRefusedException $e) {
            $this->addError('password', $e->getMessage());
        }
    }

    /**
     * "Save connection": the same check, and store only a read-only connection.
     * The typed password is cleared once stored — it is never rendered back.
     */
    public function save(SaveProdConnection $save): void
    {
        $this->validate();

        try {
            $this->showCheck($save->handle($this->project, $this->input())->toArray(), 'save');
        } catch (ProductionRefusedException $e) {
            if ($e->check->reason === 'password_needed') {
                $this->addError('password', $e->getMessage());

                return;
            }
            $this->showCheck($e->check->toArray(), 'save');

            return;
        }

        $this->password = '';
        $this->summarise();
        $this->loadSchema(app(ProductionReader::class));
    }

    /**
     * "Remove connection" in the confirmation modal: delete credentials and metrics.
     */
    public function remove(RemoveProdConnection $remove): void
    {
        // The button only shows for a saved connection; another tab may have removed it already.
        if ($this->project->prodConnection === null) {
            Log::info('board.prod_connection_remove_refused', ['project' => $this->project->name, 'reason' => 'none']);
            Flux::toast(variant: 'warning', text: 'There is no production connection to remove.');

            return;
        }

        $remove->handle($this->project);
        $this->reset('password', 'check', 'checkAction', 'results', 'schema', 'schemaError', 'customs');
        $this->fillConnection();
        $this->fillMetrics();
        Flux::toast(variant: 'success', text: 'Production connection removed');
        $this->summarise();
    }

    /**
     * A preset's Test: run it as the form has it now, saved or not.
     */
    public function testPreset(string $key, TestProdMetric $test): void
    {
        // The key comes from the browser; only the four presets exist.
        if (! isset(ProdMetric::PRESETS[$key])) {
            Log::info('board.prod_metric_tested', ['project' => $this->project->name, 'metric' => $key, 'result' => 'refused', 'reason' => 'unknown_metric']);

            return;
        }

        $this->results[$key] = $test->handle($this->project, $this->presetMetric($key))->toArray();
    }

    /**
     * A custom metric's Test: its SQL is checked for one SELECT before it is sent.
     */
    public function testCustom(int $index, TestProdMetric $test): void
    {
        $custom = $this->customs[$index] ?? null;
        if ($custom === null) {
            Log::info('board.prod_metric_tested', ['project' => $this->project->name, 'metric' => "#{$index}", 'result' => 'refused', 'reason' => 'unknown_metric']);

            return;
        }

        $this->results[$custom['key']] = $test->handle($this->project, $this->customMetric($custom))->toArray();
    }

    /**
     * "+ Add custom metric": a new row, stored on Save metrics.
     */
    public function addCustom(): void
    {
        // A stable key from the start, so SB-18's history for this metric survives renames.
        $this->customs[] = ['key' => 'custom-'.Str::lower(Str::random(8)), 'label' => '', 'sql' => 'SELECT count(*) FROM '];
    }

    /**
     * Delete a custom row from the form; stored on Save metrics.
     */
    public function dropCustom(int $index): void
    {
        unset($this->results[$this->customs[$index]['key'] ?? '']);
        // array_splice keeps the rows a list, so their indexes stay the wire:model paths.
        $customs = $this->customs;
        array_splice($customs, $index, 1);
        $this->customs = $customs;
    }

    /**
     * "Save metrics": store the presets and custom metrics; a refusal shows under its field.
     */
    public function saveMetrics(SaveProdMetrics $save): void
    {
        $this->resetErrorBag();

        try {
            $save->handle($this->project, $this->presets, $this->customs);
        } catch (ProdMetricsRefusedException $e) {
            $this->addError($e->field, $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: 'Metrics saved');
        $this->summarise();
    }

    /**
     * List production's tables and columns for the pickers (wire:init once the
     * panel is open). A failure leaves the pickers as free text.
     */
    public function loadSchema(ProductionReader $reader): void
    {
        $connection = $this->project->prodConnection;
        if ($connection === null) {
            return;
        }

        try {
            $this->schema = $reader->schema($connection->setRelation('project', $this->project));
            $this->schemaError = null;
        } catch (ProductionRefusedException $e) {
            $this->schema = null;
            $this->schemaError = $e->getMessage();
        }
    }

    /**
     * The panel.
     */
    public function render(ProductionReader $reader): View
    {
        $connection = $this->project->prodConnection;
        $sql = [];
        foreach (array_keys(ProdMetric::PRESETS) as $key) {
            $sql[$key] = $reader->refuseMetric($this->presetMetric($key)) === null ? $reader->displaySql($this->presetMetric($key)) : null;
        }

        return view('livewire.board.production-settings', [
            'connection' => $connection,
            'today' => ProductionReader::today(),
            'presetSql' => $sql,
            'oneLiner' => "CREATE USER 'board_ro'@'%' IDENTIFIED BY '…'; GRANT SELECT ON "
                .($this->database !== '' ? $this->database : $this->project->name).".* TO 'board_ro'@'%';",
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function rules(): array
    {
        return [
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_.\-:\[\]]+$/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_$\-]+$/'],
            'username' => ['required', 'string', 'max:80'],
            'password' => ['nullable', 'string', 'max:255'],
            'useSsl' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'host.regex' => 'A host name or IP address, with no spaces or ; or =.',
            'database.regex' => 'Letters, digits, _, - and $ only.',
        ];
    }

    /**
     * Tell the project's row what to say in its Production column. A browser event
     * the row's Alpine picks up, not a parent re-render: re-rendering ManageProjects
     * would re-mount this lazy panel and lose the result the owner is reading.
     */
    private function summarise(): void
    {
        $this->dispatch('prod-summary',
            project: $this->project->id,
            connected: $this->project->prodConnection()->exists(),
            metrics: $this->project->prodMetrics()->where('is_enabled', true)->count(),
        );
    }

    /**
     * Show a Test or Save result inline.
     *
     * @param  array<string, mixed>  $check
     */
    private function showCheck(array $check, string $action): void
    {
        $this->check = $check;
        $this->checkAction = $action;
    }

    /**
     * The form as the actions take it.
     *
     * @return array{host: string, port: string, database: string, username: string, password: string, useSsl: bool}
     */
    private function input(): array
    {
        return ['host' => $this->host, 'port' => $this->port, 'database' => $this->database,
            'username' => $this->username, 'password' => $this->password, 'useSsl' => $this->useSsl];
    }

    /**
     * The saved connection's fields, or a blank form. Never the password.
     */
    private function fillConnection(): void
    {
        $saved = $this->project->prodConnection;
        $this->host = $saved->host ?? '';
        $this->port = (string) ($saved->port ?? 3306);
        $this->database = $saved->database ?? '';
        $this->username = $saved->username ?? '';
        $this->password = '';
        $this->useSsl = $saved->use_ssl ?? true;
    }

    /**
     * The saved metrics, with each preset's defaults where nothing is saved.
     */
    private function fillMetrics(): void
    {
        $saved = $this->project->prodMetrics()->orderBy('position')->get();

        foreach (ProdMetric::PRESETS as $key => $preset) {
            $metric = $saved->firstWhere('key', $key);
            $config = (array) $metric?->config;
            $this->presets[$key] = [
                'enabled' => (bool) $metric?->is_enabled,
                'table' => (string) ($config['table'] ?? $preset['table']),
                'column' => (string) ($config['column'] ?? $preset['column'] ?? ''),
                'distinct' => (string) ($config['distinct'] ?? $preset['distinct'] ?? ''),
            ];
        }

        $this->customs = array_values($saved->where('kind', ProdMetric::KIND_CUSTOM)
            ->map(fn (ProdMetric $m) => ['key' => $m->key, 'label' => $m->label, 'sql' => (string) $m->sql])
            ->all());
    }

    /**
     * A preset as the form has it, unsaved.
     */
    private function presetMetric(string $key): ProdMetric
    {
        $form = $this->presets[$key];
        $config = [];
        foreach (ProdMetric::PRESETS[$key]['fields'] as $field) {
            $config[$field] = trim($form[$field]);
        }

        return new ProdMetric(['project_id' => $this->project->id, 'key' => $key, 'kind' => ProdMetric::KIND_PRESET,
            'label' => ProdMetric::PRESETS[$key]['label'], 'config' => $config]);
    }

    /**
     * A custom row as the form has it, unsaved.
     *
     * @param  array{key: string, label: string, sql: string}  $custom
     */
    private function customMetric(array $custom): ProdMetric
    {
        return new ProdMetric(['project_id' => $this->project->id, 'key' => $custom['key'], 'kind' => ProdMetric::KIND_CUSTOM,
            'label' => $custom['label'], 'sql' => $custom['sql']]);
    }
}
