<?php

use App\Services\ProductionReader;

/**
 * SB-17: ProductionReader's two guards that run without a server — the SHOW GRANTS
 * reading that decides "read-only", and the one-SELECT check on custom SQL — plus
 * password redaction. The server-backed paths are in Feature/Board/ProductionConnectionTest.
 */
it('accepts SELECT, SHOW VIEW and USAGE grants as read-only', function () {
    expect(ProductionReader::extraPrivilege([
        'GRANT USAGE ON *.* TO `board_ro`@`%`',
        'GRANT SELECT, SHOW VIEW ON `coins`.* TO `board_ro`@`%`',
        'GRANT SELECT (`id`, `email`) ON `coins`.`users` TO `board_ro`@`%`',
    ]))->toBeNull();
});

it('names the first privilege beyond SELECT', function (array $grants, string $privilege) {
    expect(ProductionReader::extraPrivilege($grants))->toBe($privilege);
})->with([
    'insert' => [['GRANT SELECT, INSERT ON `coins`.* TO `u`@`%`'], 'INSERT'],
    'all' => [['GRANT ALL PRIVILEGES ON `coins`.* TO `u`@`%`'], 'ALL'],
    'grant option' => [['GRANT SELECT ON `coins`.* TO `u`@`%` WITH GRANT OPTION'], 'GRANT OPTION'],
    'global write' => [['GRANT UPDATE ON *.* TO `u`@`%`'], 'UPDATE'],
    'dynamic privilege' => [['GRANT USAGE ON *.* TO `u`@`%`', 'GRANT SYSTEM_VARIABLES_ADMIN ON *.* TO `u`@`%`'], 'SYSTEM_VARIABLES_ADMIN'],
    'column update' => [['GRANT SELECT (`id`), UPDATE (`name`) ON `coins`.`users` TO `u`@`%`'], 'UPDATE'],
    'a role' => [['GRANT `writer`@`%` TO `u`@`%`'], 'ROLE `writer`@`%`'],
    'proxy' => [['GRANT PROXY ON ``@`` TO `u`@`%`'], 'PROXY'],
    'unrecognised line' => [['SOMETHING ELSE'], 'UNRECOGNISED GRANT'],
]);

it('tells write privileges from other extra ones', function () {
    expect(ProductionReader::isWrite('INSERT'))->toBeTrue()
        ->and(ProductionReader::isWrite('ALL'))->toBeTrue()
        ->and(ProductionReader::isWrite('GRANT OPTION'))->toBeTrue()
        ->and(ProductionReader::isWrite('PROCESS'))->toBeFalse();
});

it('lets one SELECT or WITH … SELECT through, with strings, comments and a trailing semicolon', function (string $sql) {
    expect(ProductionReader::refuseSql($sql))->toBeNull();
})->with([
    'plain' => ['SELECT count(*) FROM users'],
    'with' => ['WITH a AS (SELECT id FROM users WHERE deleted_at IS NULL) SELECT count(*) FROM a'],
    'semicolon in a string' => ["SELECT count(*) FROM users WHERE name = 'a; DROP TABLE users'"],
    'trailing semicolon' => ["SELECT count(*) FROM users;\n"],
    'comment' => ["-- how many\nSELECT count(*) FROM users /* all */"],
    'parenthesised' => ['(SELECT count(*) FROM users)'],
    'updated_at column' => ['SELECT count(*) FROM users WHERE updated_at > created_at'],
]);

it('refuses anything but one SELECT, saying why', function (string $sql, string $why) {
    expect(ProductionReader::refuseSql($sql))->toContain($why);
})->with([
    'blank' => ['   ', 'Enter one SELECT'],
    'two statements' => ['SELECT 1; SELECT 2', 'has 2 statements'],
    'starts with DELETE and two statements' => ['DELETE FROM s; SELECT count(*) FROM s', 'starts with DELETE and has 2 statements'],
    'WITH … DELETE' => ['WITH a AS (SELECT 1) DELETE FROM users', 'contains DELETE'],
    'FOR UPDATE' => ['SELECT count(*) FROM users FOR UPDATE', 'contains UPDATE'],
    'INTO OUTFILE' => ["SELECT count(*) INTO OUTFILE '/tmp/x' FROM users", 'contains INTO'],
    'optimizer hint' => ['SELECT /*+ MAX_EXECUTION_TIME(99999) */ count(*) FROM users', 'hints'],
    'executable comment' => ['SELECT 1 /*!50000 , 2 */', 'hints'],
    'unterminated string' => ["SELECT 'abc", 'unterminated'],
]);

it('redacts the password from a message, whatever its case', function () {
    expect(ProductionReader::redact("Table 'coins.sb17pwabc' doesn't exist", 'Sb17PwAbc'))
        ->toBe("Table 'coins.[redacted]' doesn't exist")
        ->and(ProductionReader::redact('no password here', ''))->toBe('no password here');
});
