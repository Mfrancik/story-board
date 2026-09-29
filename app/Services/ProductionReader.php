<?php

namespace App\Services;

use App\Exceptions\ProductionRefusedException;
use App\Models\ProdConnection;
use App\Models\ProdMetric;
use App\Services\Production\ConnectionCheck;
use App\Services\Production\MetricReading;
use App\Services\Production\ProductionRead;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use PDO;
use Pdo\Mysql;
use PDOException;
use PDOStatement;
use SensitiveParameter;

/**
 * The only code in the board that opens a production database connection
 * (SB-17; the same single-gateway rule as GitReader, ADR-004). Every connection
 * is built here at runtime — never in config/database.php — and before any
 * metric runs it is made safe in layers:
 *
 * - a 5 s connect timeout, then `SET SESSION TRANSACTION READ ONLY`,
 *   `max_execution_time` 5 s and a UTC session time zone;
 * - `SHOW GRANTS` must show nothing beyond SELECT, SHOW VIEW and USAGE, on
 *   every open — save, test and every read (SB-18) — not only on save;
 * - custom SQL must be one SELECT (refuseSql) before it is sent, and the driver
 *   refuses multi-statements anyway;
 * - the stored password is redacted from every message it hands back.
 *
 * Refusals to open are logged here as `board.prod_connection_refused` (warning),
 * so every caller — the Production panel now, SB-18's reads later — is covered.
 * Nothing it logs includes host, database, username, password or a value.
 */
class ProductionReader
{
    /** Connect timeout, and the server-side limit on every SELECT (max_execution_time). */
    public const TIMEOUT_SECONDS = 5;

    /** The privileges a read-only user may hold; anything else in SHOW GRANTS is refused. */
    public const READ_ONLY_PRIVILEGES = ['SELECT', 'SHOW VIEW', 'USAGE'];

    /** Privileges that change data, schema or users: named "can write" in the refusal. */
    public const WRITE_PRIVILEGES = [
        'ALL', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'GRANT OPTION', 'INDEX', 'REFERENCES',
        'CREATE VIEW', 'CREATE ROUTINE', 'ALTER ROUTINE', 'EXECUTE', 'TRIGGER', 'EVENT', 'LOCK TABLES',
        'CREATE TEMPORARY TABLES', 'FILE', 'SUPER', 'CREATE USER', 'CREATE ROLE', 'DROP ROLE', 'RELOAD',
        'SHUTDOWN', 'CREATE TABLESPACE',
    ];

    /**
     * Words that turn a SELECT into something else — WITH … DELETE, FOR UPDATE, INTO OUTFILE / @var —
     * refused anywhere outside strings and comments. Word-bounded, so `deleted_at` and `updated_at` pass.
     */
    private const FORBIDDEN_WORDS = ['INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'DROP', 'ALTER', 'CREATE', 'TRUNCATE', 'RENAME',
        'GRANT', 'REVOKE', 'CALL', 'LOAD', 'HANDLER', 'LOCK', 'UNLOCK', 'INTO'];

    /** A table or column a preset may name: MySQL's unquoted identifier characters, nothing that needs quoting. */
    private const NAME = '/^[A-Za-z0-9_$]{1,64}$/';

    /** Host and database go into a PDO DSN, where `;` or `=` would add options such as unix_socket. */
    private const DSN_SAFE = '/^[A-Za-z0-9_.\-$:\[\]]+$/';

    /** MySQL's "maximum statement execution time exceeded". */
    private const ER_QUERY_TIMEOUT = 3024;

    /**
     * Connect, prove the user read-only, and report what was found. Never throws
     * for a production-side problem: an unreachable host, a failed login or a
     * refused privilege is a ConnectionCheck with that status.
     *
     * Side effects: one production connection (closed on return); logs
     * board.prod_connection_refused when it does not open read-only.
     */
    public function check(ProdConnection $connection): ConnectionCheck
    {
        try {
            return $this->open($connection, 'check')[1];
        } catch (ProductionRefusedException $e) {
            return $e->check;
        }
    }

    /**
     * Run one metric — saved or still being edited — and return its number or
     * why there is none. The metric is checked (one SELECT, plain names) before
     * any connection is opened, so a refused one never reaches production.
     *
     * Side effects: at most one production connection; see check() for logging.
     */
    public function testMetric(ProdConnection $connection, ProdMetric $metric): MetricReading
    {
        if ($refused = $this->refuseMetric($metric)) {
            return $refused;
        }

        try {
            [$pdo] = $this->open($connection, 'metric');
        } catch (ProductionRefusedException $e) {
            return MetricReading::fromCheck($e->check);
        }

        return $this->read($pdo, $metric, $connection->password);
    }

    /**
     * Read every enabled metric of the connection's project, in position order,
     * in one read-only session: the entry point for SB-18's dashboard and daily
     * snapshots. The grants check runs first; if it fails nothing is read and
     * `readings` is empty. A metric that fails on its own does not stop the rest.
     *
     * Side effects: one production connection; see check() for logging.
     */
    public function readEnabledMetrics(ProdConnection $connection): ProductionRead
    {
        $metrics = $connection->project->prodMetrics()->where('is_enabled', true)->orderBy('position')->get();

        try {
            [$pdo, $check] = $this->open($connection, 'read');
        } catch (ProductionRefusedException $e) {
            return new ProductionRead($e->check, []);
        }

        $readings = [];
        foreach ($metrics as $metric) {
            $readings[$metric->key] = $this->refuseMetric($metric) ?? $this->read($pdo, $metric, $connection->password);
        }

        return new ProductionRead($check, $readings);
    }

    /**
     * The production database's tables and their columns, for the preset pickers.
     * Timestamp-typed columns (datetime, timestamp, date) are listed separately.
     *
     * Side effects: one production connection; see check() for logging.
     *
     * @return array<string, array{columns: list<string>, timestamps: list<string>}>
     *
     * @throws ProductionRefusedException when the connection does not open read-only, or the listing fails.
     */
    public function schema(ProdConnection $connection): array
    {
        [$pdo] = $this->open($connection, 'schema');

        try {
            // Raw SQL: information_schema on a runtime PDO; the query builder is bound to the board's own connections.
            $rows = self::run($pdo, 'SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION')->fetchAll(PDO::FETCH_NUM);
        } catch (PDOException $e) {
            $message = self::redact($e->getMessage(), $connection->password);
            throw new ProductionRefusedException(new ConnectionCheck(ConnectionCheck::FAILED, 'failed',
                'Could not list the tables: '.$message, target: $connection->target(), detail: $message));
        }

        $schema = [];
        foreach ($rows as [$table, $column, $type]) {
            $schema[$table]['columns'][] = $column;
            $schema[$table]['timestamps'] ??= [];
            if (in_array(strtolower($type), ['datetime', 'timestamp', 'date'], true)) {
                $schema[$table]['timestamps'][] = $column;
            }
        }

        return $schema;
    }

    /**
     * The statement a metric runs and its bindings. Presets are built from
     * validated names (quoted with backticks); "today" is bound as the UTC
     * range from local midnight (board.timezone) to the next.
     *
     * @return array{0: string, 1: list<string>}
     */
    public function sqlFor(ProdMetric $metric): array
    {
        if (! $metric->isPreset()) {
            return [rtrim(trim((string) $metric->sql), "; \t\n\r"), []];
        }

        $config = (array) $metric->config;
        $count = ($config['distinct'] ?? '') !== '' ? 'count(DISTINCT `'.$config['distinct'].'`)' : 'count(*)';
        $sql = 'SELECT '.$count.' FROM `'.($config['table'] ?? '').'`';
        if (($config['column'] ?? '') === '') {
            return [$sql, []];
        }

        $today = self::today();

        return [$sql.' WHERE `'.$config['column'].'` >= ? AND `'.$config['column'].'` < ?', [$today['since'], $today['until']]];
    }

    /**
     * sqlFor() with its bindings written in, for "Show SQL".
     */
    public function displaySql(ProdMetric $metric): string
    {
        [$sql, $bindings] = $this->sqlFor($metric);
        foreach ($bindings as $value) {
            $sql = preg_replace('/\?/', "'".$value."'", $sql, 1) ?? $sql;
        }

        return $sql;
    }

    /**
     * Today in the owner's timezone (board.timezone, else app.timezone) as the UTC
     * range production timestamps are compared with.
     *
     * @return array{since: string, until: string, timezone: string}
     */
    public static function today(): array
    {
        $tz = (string) (config('board.timezone') ?: config('app.timezone'));
        $midnight = Carbon::now($tz)->startOfDay();

        return [
            'since' => $midnight->copy()->utc()->format('Y-m-d H:i:s'),
            'until' => $midnight->copy()->addDay()->utc()->format('Y-m-d H:i:s'),
            'timezone' => $tz,
        ];
    }

    /**
     * The first privilege in SHOW GRANTS beyond SELECT, SHOW VIEW and USAGE, or
     * null when the user is read-only. Every line counts, not only the ones on
     * this database: a board user should be able to write nowhere. A granted
     * role is refused as `ROLE <name>` — its privileges are not in the listing —
     * and a line this parser does not recognise is refused rather than trusted.
     *
     * @param  list<string>  $grants
     */
    public static function extraPrivilege(array $grants): ?string
    {
        foreach ($grants as $line) {
            $line = trim($line);
            // Partial revokes only take privileges away.
            if (preg_match('/^REVOKE\b/i', $line)) {
                continue;
            }
            if (preg_match('/^GRANT\s+(.*?)\s+ON\s+.*?\s+TO\s+/is', $line, $m)) {
                // Column lists — SELECT (`id`, `email`) — hold commas of their own.
                foreach (explode(',', (string) preg_replace('/\([^)]*\)/', '', $m[1])) as $privilege) {
                    $privilege = strtoupper((string) preg_replace('/\s+/', ' ', trim($privilege)));
                    $privilege = $privilege === 'ALL PRIVILEGES' ? 'ALL' : $privilege;
                    if (! in_array($privilege, self::READ_ONLY_PRIVILEGES, true)) {
                        return $privilege;
                    }
                }
                if (preg_match('/\bWITH\s+GRANT\s+OPTION\s*$/i', $line)) {
                    return 'GRANT OPTION';
                }

                continue;
            }
            if (preg_match('/^GRANT\s+(.+?)\s+TO\s+/is', $line, $m)) {
                return 'ROLE '.trim($m[1]);
            }

            return 'UNRECOGNISED GRANT';
        }

        return null;
    }

    /**
     * Whether a privilege changes data, schema or users (worded "can write" in the refusal).
     */
    public static function isWrite(string $privilege): bool
    {
        return in_array($privilege, self::WRITE_PRIVILEGES, true);
    }

    /**
     * Why custom SQL is not one SELECT (or WITH … SELECT), or null when it is.
     * Strings, quoted names and comments are masked first, so a `;` or a word
     * inside them does not count; optimizer hints and `/*! … *\/` are refused
     * outright because they can lift the time limit or run hidden SQL.
     */
    public static function refuseSql(string $sql): ?string
    {
        if (trim($sql) === '') {
            return 'Enter one SELECT that returns one number.';
        }

        [$code, $unmaskable] = self::mask($sql);
        if ($unmaskable !== null) {
            return 'One SELECT only. This '.$unmaskable.', so it was refused before it reached the database.';
        }

        $body = rtrim(trim($code), "; \t\n\r");
        $statements = count(array_filter(explode(';', $body), fn (string $s) => trim($s) !== ''));
        preg_match('/^[\s(]*([A-Za-z]+)/', $body, $m);
        $first = strtoupper($m[1] ?? '');

        $problems = [];
        if (! in_array($first, ['SELECT', 'WITH'], true)) {
            $problems[] = 'starts with '.($first !== '' ? $first : 'something other than SELECT');
        }
        if ($statements > 1) {
            $problems[] = "has {$statements} statements";
        }
        if ($problems === [] && preg_match('/\b('.implode('|', self::FORBIDDEN_WORDS).')\b/i', $body, $w)) {
            $problems[] = 'contains '.strtoupper($w[1]);
        }

        return $problems === [] ? null : 'One SELECT only. This '.implode(' and ', $problems).', so it was refused before it reached the database.';
    }

    /**
     * `$message` with every occurrence of the password replaced, in any case —
     * MySQL lower-cases names it quotes back on some systems.
     */
    public static function redact(string $message, #[SensitiveParameter] string $password): string
    {
        return $password === '' ? $message : str_ireplace($password, '[redacted]', $message);
    }

    /**
     * Open a production connection and make it safe: timeouts, read-only
     * session, UTC, then the grants check.
     *
     * @param  string  $during  logged: check | metric | read | schema
     * @return array{0: PDO, 1: ConnectionCheck}
     *
     * @throws ProductionRefusedException when it cannot connect or the user is not read-only (logged).
     */
    private function open(ProdConnection $connection, string $during): array
    {
        $started = hrtime(true);
        $target = $connection->target();
        $fail = fn (bool $unreachable, string $reason, string $message, ?string $detail = null) => $this->refuse($connection, $during,
            new ConnectionCheck($unreachable ? ConnectionCheck::UNREACHABLE : ConnectionCheck::FAILED, $reason, $message,
                ms: self::msSince($started), target: $target, detail: $detail));

        // Host and database are DSN text: `;` or `=` there would smuggle in options such as unix_socket.
        if (! preg_match(self::DSN_SAFE, $connection->host) || ! preg_match(self::DSN_SAFE, $connection->database)) {
            throw $fail(false, 'bad_target', 'The host or database name has characters a MySQL address cannot have.');
        }

        try {
            $pdo = $this->connect($connection);
        } catch (PDOException $e) {
            $detail = self::redact(preg_replace('/^SQLSTATE\[\w+\]\s*(\[\d+\]\s*)?/', '', $e->getMessage()) ?? '', $connection->password);
            $seconds = number_format(self::msSince($started) / 1000, 1);

            throw match ((int) ($e->errorInfo[1] ?? $e->getCode())) {
                1045 => $fail(false, 'access_denied', 'Access denied — check the username and password.', $detail),
                1044, 1049 => $fail(false, 'no_database', 'This user cannot open that database — check its name and the user\'s grants.', $detail),
                2002, 2003, 2005, 2006 => $fail(true, 'unreachable', "Unreachable — no answer from {$target} after {$seconds} s.", $detail),
                default => $fail(false, 'failed', 'Could not connect: '.$detail, $detail),
            };
        }

        try {
            // Read only first, so even the checks below run in a session that cannot write.
            $pdo->exec('SET SESSION TRANSACTION READ ONLY');
            $pdo->exec('SET SESSION max_execution_time = '.(self::TIMEOUT_SECONDS * 1000));
            // Production timestamps are UTC; TIMESTAMP columns compare in the session zone, so pin it.
            $pdo->exec("SET SESSION time_zone = '+00:00'");
            $grants = array_values(array_map('strval', self::run($pdo, 'SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN)));
            $version = (string) self::run($pdo, 'SELECT VERSION()')->fetchColumn();
            $cipher = self::run($pdo, "SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_NUM);
        } catch (PDOException $e) {
            $detail = self::redact($e->getMessage(), $connection->password);
            throw $fail(false, 'failed', 'Connected, but could not check the user\'s grants: '.$detail, $detail);
        }

        $ssl = is_array($cipher) && ($cipher[1] ?? '') !== '';
        $privilege = self::extraPrivilege($grants);
        if ($privilege !== null) {
            $write = self::isWrite($privilege);
            throw $this->refuse($connection, $during, new ConnectionCheck(
                ConnectionCheck::REFUSED,
                $write ? 'can_write' : 'more_than_select',
                ($write ? "This user can write ({$privilege})" : "This user can do more than SELECT ({$privilege})").' — create a SELECT-only user.',
                $privilege, $grants, $version, $ssl, self::msSince($started), $target,
            ));
        }

        return [$pdo, new ConnectionCheck(ConnectionCheck::OK, null, 'Read-only connection works', null, $grants, $version, $ssl, self::msSince($started), $target)];
    }

    /**
     * The PDO itself. Multi-statements off and native prepares, so the driver
     * refuses a second statement even if refuseSql missed one.
     */
    private function connect(ProdConnection $connection): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => self::TIMEOUT_SECONDS,
            PDO::ATTR_EMULATE_PREPARES => false,
            Mysql::ATTR_MULTI_STATEMENTS => false,
            Mysql::ATTR_LOCAL_INFILE => false,
        ];
        if ($connection->use_ssl) {
            // Encrypt without verifying the certificate: the board has no CA bundle for each
            // production server. It keeps the password off the wire; it does not stop an impostor.
            $options[Mysql::ATTR_SSL_CA] = '';
            $options[Mysql::ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }

        $dsn = "mysql:host={$connection->host};port={$connection->port};dbname={$connection->database};charset=utf8mb4";

        // mysqlnd's read timeout defaults to a day: a host that accepts TCP but never speaks MySQL would
        // hang the request. It is read when the socket opens, so raising it only here leaves the board's own DB alone.
        $previous = ini_get('mysqlnd.net_read_timeout');
        ini_set('mysqlnd.net_read_timeout', (string) (self::TIMEOUT_SECONDS + 2));

        try {
            return new PDO($dsn, $connection->username, $connection->password, $options);
        } finally {
            ini_set('mysqlnd.net_read_timeout', (string) $previous);
        }
    }

    /**
     * Run one metric on an open, verified session and insist on one number back.
     */
    private function read(PDO $pdo, ProdMetric $metric, #[SensitiveParameter] string $password): MetricReading
    {
        [$sql, $bindings] = $this->sqlFor($metric);
        $shown = $this->displaySql($metric);
        $started = hrtime(true);

        try {
            $statement = $pdo->prepare($sql);
            $statement->execute($bindings);
            $rows = $statement->fetchAll(PDO::FETCH_NUM);
        } catch (PDOException $e) {
            $ms = self::msSince($started);
            if ((int) ($e->errorInfo[1] ?? 0) === self::ER_QUERY_TIMEOUT) {
                return $this->timedOut($ms, $shown);
            }
            $message = self::redact(preg_replace('/^SQLSTATE\[\w+\]:?\s*[^:]*:\s*\d+\s*/', '', $e->getMessage()) ?? '', $password);

            return new MetricReading(MetricReading::FAILED, message: $message, reason: 'query_failed', ms: $ms, sql: $shown);
        }

        $ms = self::msSince($started);
        // SLEEP() and friends are cut short at the limit without an error; a result that took the
        // whole allowance was stopped, and its value cannot be trusted.
        if ($ms >= self::TIMEOUT_SECONDS * 1000 - 50) {
            return $this->timedOut($ms, $shown);
        }

        $value = count($rows) === 1 && count($rows[0]) === 1 ? $rows[0][0] : null;
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            $got = match (true) {
                count($rows) !== 1 => count($rows).' rows',
                count($rows[0]) !== 1 => count($rows[0]).' columns',
                $value === null => 'NULL',
                default => 'text',
            };

            return new MetricReading(MetricReading::REFUSED, message: "It must return one number — this returned {$got}.",
                reason: 'not_one_number', ms: $ms, sql: $shown);
        }

        // DECIMAL arrives as a string; keep integers integral so SB-18 can compare days exactly.
        $number = is_string($value) ? (str_contains($value, '.') ? (float) $value : (int) $value) : $value;

        return new MetricReading(MetricReading::OK, $number, ms: $ms, sql: $shown);
    }

    /**
     * Why a metric cannot run, decided without a connection — custom SQL that is
     * not one SELECT, a preset with a missing or unsafe name; null when it can.
     * "Save metrics" uses it too, so nothing is stored that could never run.
     */
    public function refuseMetric(ProdMetric $metric): ?MetricReading
    {
        if (! $metric->isPreset()) {
            $why = self::refuseSql((string) $metric->sql);

            return $why === null ? null : new MetricReading(MetricReading::REFUSED, message: $why, reason: 'sql_refused');
        }

        $fields = ProdMetric::PRESETS[$metric->key]['fields'] ?? ['table'];
        $config = (array) $metric->config;
        foreach ($fields as $field) {
            $name = (string) ($config[$field] ?? '');
            // "Count distinct" is optional: blank counts every row.
            if ($name === '' && $field === 'distinct') {
                continue;
            }
            if ($name === '') {
                return new MetricReading(MetricReading::REFUSED, reason: 'incomplete',
                    message: $field === 'table' ? 'Pick a table first.' : 'Pick a timestamp column first.');
            }
            if (! preg_match(self::NAME, $name)) {
                return new MetricReading(MetricReading::REFUSED, reason: 'bad_name',
                    message: 'Table and column names are letters, digits and _ only.');
            }
        }

        return null;
    }

    /**
     * A reading stopped by the time limit.
     */
    private function timedOut(int $ms, string $sql): MetricReading
    {
        return new MetricReading(MetricReading::TIMED_OUT, reason: 'timed_out', ms: $ms, sql: $sql,
            message: 'Timed out — stopped after '.self::TIMEOUT_SECONDS.' s. Add an index or narrow the query.');
    }

    /**
     * Log a refusal to open and wrap it for the caller. Context is the project,
     * a stable reason, the privilege found and the operation — never credentials.
     */
    private function refuse(ProdConnection $connection, string $during, ConnectionCheck $check): ProductionRefusedException
    {
        Log::warning('board.prod_connection_refused', array_filter([
            'project' => $connection->project->name,
            'reason' => $check->reason,
            'privilege' => $check->privilege,
            'during' => $during,
        ], fn ($v) => $v !== null));

        return new ProductionRefusedException($check);
    }

    /**
     * The SQL with strings, quoted names and comments masked, or why it cannot be
     * masked (an unterminated string, a hint or executable comment).
     *
     * @return array{0: string, 1: string|null}
     */
    private static function mask(string $sql): array
    {
        $code = '';
        $n = strlen($sql);
        for ($i = 0; $i < $n; $i++) {
            $ch = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $end = self::closing($sql, $i, $ch);
                if ($end === null) {
                    return ['', 'has an unterminated string or name'];
                }
                $code .= ' _ ';
                $i = $end;

                continue;
            }
            // `--` starts a comment only when followed by whitespace (MySQL), so `a--1` stays arithmetic.
            if ($ch === '#' || ($ch === '-' && $next === '-' && ($i + 2 >= $n || ctype_space($sql[$i + 2])))) {
                $end = strpos($sql, "\n", $i);
                $code .= ' ';
                $i = $end === false ? $n : $end;

                continue;
            }
            if ($ch === '/' && $next === '*') {
                if (in_array($sql[$i + 2] ?? '', ['!', '+'], true)) {
                    return ['', 'uses optimizer hints or /*! … */ comments, which are not allowed'];
                }
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    return ['', 'has an unterminated comment'];
                }
                $code .= ' ';
                $i = $end + 1;

                continue;
            }
            $code .= $ch;
        }

        return [$code, null];
    }

    /**
     * Index of the quote closing the one at `$start`, or null. Doubled quotes
     * are escapes; so is a backslash, except inside backtick names.
     */
    private static function closing(string $sql, int $start, string $quote): ?int
    {
        $n = strlen($sql);
        for ($i = $start + 1; $i < $n; $i++) {
            if ($sql[$i] === '\\' && $quote !== '`') {
                $i++;

                continue;
            }
            if ($sql[$i] === $quote) {
                if (($sql[$i + 1] ?? '') === $quote) {
                    $i++;

                    continue;
                }

                return $i;
            }
        }

        return null;
    }

    /**
     * PDO::query() that always returns a statement: the connection throws on error
     * (ERRMODE_EXCEPTION), so `false` would mean the driver broke that promise.
     *
     * @throws PDOException
     */
    private static function run(PDO $pdo, string $sql): PDOStatement
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            throw new PDOException('The query returned no statement.');
        }

        return $statement;
    }

    /**
     * Whole milliseconds since an hrtime() reading.
     */
    private static function msSince(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
