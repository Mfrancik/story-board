<?php

namespace Tests\Support;

use PDO;

/**
 * A stand-in "production" database for SB-17's tests: a second local MySQL
 * database plus three users — SELECT-only, SELECT + INSERT, and ALL — so
 * ProductionReader's grants check and read-only session run against a real
 * server. Never a real production database.
 *
 * The database and user names are derived from this process's test database
 * and checkout path, so parallel workers and other worktrees never share them.
 * Created idempotently once per process; `drop()` removes them.
 */
class ProductionFixture
{
    /** The one instance per test process, so users are created once, not per test. */
    private static ?self $instance = null;

    /** The fixture database, e.g. `sb17_prod_1a2b3c4d`. */
    public readonly string $database;

    /** The password all three users share: random per process, letters and digits only (usable as a table name). */
    public readonly string $password;

    /** @var array{ro: string, rw: string, all: string} user names by kind */
    public readonly array $users;

    /** The MySQL host the board's own test database is on. */
    public readonly string $host;

    /** Its port. */
    public readonly int $port;

    /** The board test database's own (root) login, kept because `drop()` runs in afterAll, after the app is torn down. */
    private readonly string $adminUser;

    private readonly string $adminPassword;

    private function __construct()
    {
        $board = (array) config('database.connections.mysql');
        $this->host = (string) $board['host'];
        $this->port = (int) $board['port'];
        $this->adminUser = (string) $board['username'];
        $this->adminPassword = (string) $board['password'];
        $suffix = substr(md5(base_path().'|'.$board['database']), 0, 10);
        $this->database = 'sb17_prod_'.$suffix;
        // User names are capped at 32 characters; 17 is safely under.
        $this->users = ['ro' => 'sb17ro_'.$suffix, 'rw' => 'sb17rw_'.$suffix, 'all' => 'sb17al_'.$suffix];
        $this->password = 'Sb17pw'.bin2hex(random_bytes(6));
    }

    /**
     * The fixture for this process, creating its database and users on first use.
     */
    public static function boot(): self
    {
        if (self::$instance === null) {
            self::$instance = new self;
            self::$instance->create();
        }

        return self::$instance;
    }

    /**
     * Drop this process's fixture database and users, if they were created.
     */
    public static function drop(): void
    {
        if (self::$instance === null) {
            return;
        }

        $fx = self::$instance;
        $admin = $fx->admin();
        foreach ($fx->users as $user) {
            $admin->exec("DROP USER IF EXISTS '{$user}'@'%'");
        }
        $admin->exec("DROP DATABASE IF EXISTS `{$fx->database}`");
        self::$instance = null;
    }

    /**
     * The Manage projects form input for one of the users.
     *
     * @param  'ro'|'rw'|'all'  $who
     * @return array{host: string, port: string, database: string, username: string, password: string, useSsl: bool}
     */
    public function input(string $who = 'ro'): array
    {
        return [
            'host' => $this->host,
            'port' => (string) $this->port,
            'database' => $this->database,
            'username' => $this->users[$who],
            'password' => $this->password,
            'useSsl' => true,
        ];
    }

    /**
     * Replace the fixture's tables with `users` and `user_login_events` holding the given rows.
     *
     * @param  list<array{name: string, created_at: string, last_seen_at: string|null}>  $users
     */
    public function seed(array $users = []): void
    {
        $admin = $this->admin();
        $admin->exec("USE `{$this->database}`");
        $admin->exec('DROP TABLE IF EXISTS users, user_login_events');
        $admin->exec('CREATE TABLE users (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NOT NULL,
            created_at DATETIME NULL, last_seen_at DATETIME NULL)');
        $admin->exec('CREATE TABLE user_login_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL, occurred_at DATETIME NOT NULL)');

        $insert = $admin->prepare('INSERT INTO users (name, created_at, last_seen_at) VALUES (?, ?, ?)');
        foreach ($users as $u) {
            $insert->execute([$u['name'], $u['created_at'], $u['last_seen_at']]);
        }
    }

    /**
     * Create the database and the three users. Idempotent: a user left by a crashed
     * run of the same worker is re-passworded and re-granted, not duplicated.
     */
    private function create(): void
    {
        $admin = $this->admin();
        $admin->exec("CREATE DATABASE IF NOT EXISTS `{$this->database}`");

        $grants = ['ro' => 'SELECT', 'rw' => 'SELECT, INSERT', 'all' => 'ALL'];
        foreach ($this->users as $kind => $user) {
            $admin->exec("CREATE USER IF NOT EXISTS '{$user}'@'%' IDENTIFIED BY '{$this->password}'");
            $admin->exec("ALTER USER '{$user}'@'%' IDENTIFIED BY '{$this->password}'");
            $admin->exec("REVOKE ALL PRIVILEGES, GRANT OPTION FROM '{$user}'@'%'");
            $admin->exec("GRANT {$grants[$kind]} ON `{$this->database}`.* TO '{$user}'@'%'");
        }

        $this->seed();
    }

    /**
     * A root connection of its own. DDL here must not run on the board's default
     * connection: CREATE USER commits implicitly and would end RefreshDatabase's transaction.
     */
    private function admin(): PDO
    {
        return new PDO("mysql:host={$this->host};port={$this->port}", $this->adminUser, $this->adminPassword,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
