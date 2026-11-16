<?php
/*
 * tests/preflight.php
 * Copyright (C) 2026 Stefano Natangelo
 * SPDX-License-Identifier: AGPL-3.0-only
 */

/** Read-only fixture checks: deliberately do not bootstrap OJS or connect to SQL. */
function jqSafetyValidateConfig(array $config, string $expectedIp, array $resolved): void
{
    $db = $config['database'] ?? [];
    if (!in_array($db['driver'] ?? '', ['mysql', 'mysqli'], true)
        || ($db['host'] ?? '') !== 'db'
        || ($db['name'] ?? '') !== 'ojs'
        || ($db['username'] ?? '') !== 'ojs'
        || ($db['password'] ?? '') !== 'ojs'
        || !in_array((string) ($db['port'] ?? '3306'), ['', '3306'], true)
        || !empty($db['unix_socket'])) {
        throw new RuntimeException('The database must be the local db/ojs fixture, not another database.');
    }
    if (!filter_var($expectedIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        || array_values(array_unique($resolved)) !== [$expectedIp]) {
        throw new RuntimeException('The db hostname does not resolve exclusively to the verified database container.');
    }
    if (($config['email']['default'] ?? '') !== 'log') {
        throw new RuntimeException('The fixture must use [email] default = log.');
    }
    $urls = $config['general']['base_url'] ?? null;
    $urls = is_array($urls) ? $urls : [$urls];
    if (!$urls) {
        throw new RuntimeException('The fixture base URL is missing.');
    }
    foreach ($urls as $url) {
        if (!is_string($url)
            || !preg_match('~\Ahttp://(?:localhost|127\.0\.0\.1):8080(?:/[A-Za-z0-9_./-]*)?\z~D', $url)) {
            throw new RuntimeException('Every OJS base URL must use the fixed local test origin.');
        }
    }
}

function jqSafetyEnvironment(string $expectedInstance, string $expectedIp): void
{
    if (PHP_SAPI !== 'cli'
        || getenv('JQ_TEST_DISPOSABLE') !== '1'
        || !preg_match('/\Ajq-test-[a-z0-9][a-z0-9-]{7,55}\z/D', $expectedInstance)
        || getenv('JQ_TEST_INSTANCE') !== $expectedInstance) {
        throw new RuntimeException('The container is not explicitly marked as the selected disposable test instance.');
    }
    $config = @parse_ini_file('/var/www/html/config.inc.php', true, INI_SCANNER_RAW);
    if (!is_array($config)) {
        throw new RuntimeException('Cannot read the local OJS configuration.');
    }
    jqSafetyValidateConfig($config, $expectedIp, gethostbynamel('db') ?: []);
}

if (!defined('JQ_SAFETY_LIBRARY_ONLY')) {
    try {
        jqSafetyEnvironment($argv[1] ?? '', $argv[2] ?? '');
        echo "Disposable OJS configuration verified.\n";
    } catch (Throwable $error) {
        file_put_contents('php://stderr', "Journal Quota safety check: " . $error->getMessage() . "\n");
        exit(2);
    }
}
