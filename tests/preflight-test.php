<?php
/*
 * tests/preflight-test.php
 * Copyright (C) 2026 Stefano Natangelo
 * SPDX-License-Identifier: AGPL-3.0-only
 */

/** Pure safety regressions: no OJS bootstrap, Docker, database or network access. */
require __DIR__ . '/guard.php';

$passed = 0;
$failed = 0;
$skipped = 0;

function jqSafetyTest(string $name, callable $check, bool $reject = false): void
{
    global $passed, $failed;
    try {
        $check();
        if ($reject) {
            throw new LogicException('Expected a safety rejection, but the input was accepted.');
        }
        $passed++;
        echo "ok - {$name}\n";
    } catch (Throwable $error) {
        if ($reject && ($error instanceof RuntimeException || $error instanceof JsonException)) {
            $passed++;
            echo "ok - {$name}\n";
            return;
        }
        $failed++;
        fwrite(STDERR, "not ok - {$name}: {$error->getMessage()}\n");
    }
}

function jqSafetyTestConfig(): array
{
    return [
        'database' => [
            'driver' => 'mysqli',
            'host' => 'db',
            'name' => 'ojs',
            'username' => 'ojs',
            'password' => 'ojs',
            'port' => '3306',
        ],
        'email' => ['default' => 'log'],
        'general' => ['base_url' => 'http://127.0.0.1:8080'],
    ];
}

$databaseIp = '172.28.0.3';
$config = jqSafetyTestConfig();
jqSafetyTest('local mysqli fixture', fn () => jqSafetyValidateConfig($config, $databaseIp, [$databaseIp]));
jqSafetyTest('duplicate DNS entries for the same verified IP', fn () => jqSafetyValidateConfig($config, $databaseIp, [$databaseIp, $databaseIp]));

$validChanges = [
    'mysql driver' => static function (array &$c): void { $c['database']['driver'] = 'mysql'; },
    'omitted default database port' => static function (array &$c): void { unset($c['database']['port']); },
    'empty default database port' => static function (array &$c): void { $c['database']['port'] = ''; },
    'localhost base URL with trailing slash' => static function (array &$c): void { $c['general']['base_url'] = 'http://localhost:8080/'; },
    'local journal URL overrides' => static function (array &$c): void {
        $c['general']['base_url'] = ['index' => 'http://localhost:8080', 'journal' => 'http://127.0.0.1:8080/index.php/journal'];
    },
];
foreach ($validChanges as $name => $change) {
    $config = jqSafetyTestConfig();
    $change($config);
    jqSafetyTest($name, fn () => jqSafetyValidateConfig($config, $databaseIp, [$databaseIp]));
}

$invalidChanges = [
    'missing database configuration' => static function (array &$c): void { unset($c['database']); },
    'different database driver' => static function (array &$c): void { $c['database']['driver'] = 'pgsql'; },
    'missing database host' => static function (array &$c): void { unset($c['database']['host']); },
    'remote database host' => static function (array &$c): void { $c['database']['host'] = 'db.example.invalid'; },
    'unverified loopback database host' => static function (array &$c): void { $c['database']['host'] = '127.0.0.1'; },
    'different database name' => static function (array &$c): void { $c['database']['name'] = 'production'; },
    'different database user' => static function (array &$c): void { $c['database']['username'] = 'root'; },
    'different database password' => static function (array &$c): void { $c['database']['password'] = 'another-fixture'; },
    'nonstandard database port' => static function (array &$c): void { $c['database']['port'] = '3307'; },
    'database socket override' => static function (array &$c): void { $c['database']['unix_socket'] = '/tmp/another-db.sock'; },
    'missing mail configuration' => static function (array &$c): void { unset($c['email']); },
    'SMTP mail configuration' => static function (array &$c): void { $c['email']['default'] = 'smtp'; },
    'missing base URL' => static function (array &$c): void { unset($c['general']['base_url']); },
    'empty base URL overrides' => static function (array &$c): void { $c['general']['base_url'] = []; },
    'external base URL' => static function (array &$c): void { $c['general']['base_url'] = 'https://example.invalid'; },
    'mixed local and external overrides' => static function (array &$c): void {
        $c['general']['base_url'] = ['index' => 'http://127.0.0.1:8080', 'journal' => 'https://example.invalid/journal'];
    },
    'base URL with user information' => static function (array &$c): void { $c['general']['base_url'] = 'http://user@127.0.0.1:8080'; },
    'base URL with an external authority' => static function (array &$c): void { $c['general']['base_url'] = 'http://127.0.0.1:8080@example.invalid'; },
    'base URL with another port' => static function (array &$c): void { $c['general']['base_url'] = 'http://127.0.0.1:8081'; },
    'base URL with HTTPS' => static function (array &$c): void { $c['general']['base_url'] = 'https://127.0.0.1:8080'; },
    'base URL with query string' => static function (array &$c): void { $c['general']['base_url'] = 'http://127.0.0.1:8080/?redirect=elsewhere'; },
    'base URL with fragment' => static function (array &$c): void { $c['general']['base_url'] = 'http://127.0.0.1:8080/#elsewhere'; },
];
foreach ($invalidChanges as $name => $change) {
    $config = jqSafetyTestConfig();
    $change($config);
    jqSafetyTest('reject ' . $name, fn () => jqSafetyValidateConfig($config, $databaseIp, [$databaseIp]), true);
}

$config = jqSafetyTestConfig();
foreach ([
    'missing DNS answer' => [],
    'different database IP' => ['172.28.0.9'],
    'ambiguous database DNS' => [$databaseIp, '172.28.0.9'],
] as $name => $resolved) {
    jqSafetyTest('reject ' . $name, fn () => jqSafetyValidateConfig($config, $databaseIp, $resolved), true);
}
jqSafetyTest('reject invalid expected IP', fn () => jqSafetyValidateConfig($config, 'not-an-ip', ['not-an-ip']), true);
jqSafetyTest('reject IPv6 expected IP', fn () => jqSafetyValidateConfig($config, '::1', ['::1']), true);

// Snapshot tests touch only a fresh file in the operating system's temp directory.
$snapshotPath = tempnam(sys_get_temp_dir(), 'jq-safety-');
if ($snapshotPath === false) {
    fwrite(STDERR, "Cannot create the synthetic snapshot fixture.\n");
    exit(2);
}
$linkPath = $snapshotPath . '-link';
$oldNonce = getenv('JQ_RUN_NONCE');
$oldInstance = getenv('JQ_TEST_INSTANCE');
register_shutdown_function(static function () use ($snapshotPath, $linkPath, $oldNonce, $oldInstance): void {
    if (is_link($linkPath)) {
        unlink($linkPath);
    }
    if (is_file($snapshotPath)) {
        unlink($snapshotPath);
    }
    putenv($oldNonce === false ? 'JQ_RUN_NONCE' : 'JQ_RUN_NONCE=' . $oldNonce);
    putenv($oldInstance === false ? 'JQ_TEST_INSTANCE' : 'JQ_TEST_INSTANCE=' . $oldInstance);
});
putenv('JQ_RUN_NONCE=' . str_repeat('a', 32));
putenv('JQ_TEST_INSTANCE=jq-test-local-20261116');
$snapshot = [
    '_run' => str_repeat('a', 32),
    '_instance' => 'jq-test-local-20261116',
    'settings' => [],
    'files' => [],
    'reservations' => [],
    'notifications' => [],
];
$writeSnapshot = static function ($state) use ($snapshotPath): void {
    $json = json_encode($state, JSON_THROW_ON_ERROR);
    if (file_put_contents($snapshotPath, $json) !== strlen($json)) {
        throw new RuntimeException('Cannot write the synthetic snapshot fixture.');
    }
};
$writeSnapshot($snapshot);
jqSafetyTest('valid snapshot returned unchanged', static function () use ($snapshotPath, $snapshot): void {
    if (jqSafetySnapshot($snapshotPath) !== $snapshot) {
        throw new RuntimeException('Snapshot content changed during validation.');
    }
});
jqSafetyTest('reject missing snapshot', fn () => jqSafetySnapshot($snapshotPath . '-missing'), true);

foreach (['_run', '_instance', 'settings', 'files', 'reservations', 'notifications'] as $key) {
    $incomplete = $snapshot;
    unset($incomplete[$key]);
    $writeSnapshot($incomplete);
    jqSafetyTest('reject snapshot missing ' . $key, fn () => jqSafetySnapshot($snapshotPath), true);
}
foreach (['settings', 'files', 'reservations', 'notifications'] as $key) {
    $incomplete = $snapshot;
    $incomplete[$key] = 'not-an-array';
    $writeSnapshot($incomplete);
    jqSafetyTest('reject malformed snapshot ' . $key, fn () => jqSafetySnapshot($snapshotPath), true);
}
foreach (['_run' => str_repeat('b', 32), '_instance' => 'jq-test-another-fixture'] as $key => $value) {
    $mismatch = $snapshot;
    $mismatch[$key] = $value;
    $writeSnapshot($mismatch);
    jqSafetyTest('reject mismatched snapshot ' . $key, fn () => jqSafetySnapshot($snapshotPath), true);
}
$writeSnapshot(null);
jqSafetyTest('reject non-object snapshot', fn () => jqSafetySnapshot($snapshotPath), true);
file_put_contents($snapshotPath, '{invalid-json');
jqSafetyTest('reject invalid snapshot JSON', fn () => jqSafetySnapshot($snapshotPath), true);
$writeSnapshot($snapshot);
if (function_exists('symlink') && @symlink($snapshotPath, $linkPath)) {
    jqSafetyTest('reject snapshot symlink', fn () => jqSafetySnapshot($linkPath), true);
} else {
    $skipped++;
    echo "skip - snapshot symlink (not available on this platform)\n";
}

// Even if the guard regresses, open_basedir prevents the child from reading OJS.
if (function_exists('proc_open')) {
    foreach (['test', 'snapshot', 'restore', 'set-quota', 'worker', 'mail-driver'] as $command) {
        jqSafetyTest('direct CLI ' . $command . ' refuses absent authorization before bootstrap', static function () use ($command): void {
            $process = proc_open(
                [PHP_BINARY, '-n', '-d', 'open_basedir=' . __DIR__, __DIR__ . '/cli.php', $command],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                __DIR__,
                []
            );
            if (!is_resource($process)) {
                throw new RuntimeException('Cannot start the isolated CLI rejection check.');
            }
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);
            if ($status !== 2 || $stdout !== ''
                || !str_contains($stderr, 'Journal Quota safety check:')
                || !str_contains($stderr, 'no authorized run exists')
                || str_contains($stderr, 'bootstrap.php')) {
                throw new RuntimeException('The CLI did not stop at its authorization guard.');
            }
        });
    }
} else {
    $skipped++;
    echo "skip - direct CLI guards (proc_open is unavailable)\n";
}

printf("PHP safety: %d passed, %d failed, %d skipped\n", $passed, $failed, $skipped);
exit($failed === 0 ? 0 : 1);
