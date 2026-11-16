<?php
/*
 * tests/guard.php
 * Copyright (C) 2026 Stefano Natangelo
 * SPDX-License-Identifier: AGPL-3.0-only
 */

define('JQ_SAFETY_LIBRARY_ONLY', true);
require __DIR__ . '/preflight.php';

/** A snapshot is usable only by the same explicitly authorized test run. */
function jqSafetySnapshot(string $path): array
{
    if (is_link($path) || !is_file($path)) {
        throw new RuntimeException('The initial test snapshot is missing.');
    }
    $state = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($state)
        || ($state['_run'] ?? null) !== getenv('JQ_RUN_NONCE')
        || ($state['_instance'] ?? null) !== getenv('JQ_TEST_INSTANCE')) {
        throw new RuntimeException('The snapshot belongs to another test run or instance.');
    }
    foreach (['settings', 'files', 'reservations', 'notifications'] as $key) {
        if (!isset($state[$key]) || !is_array($state[$key])) {
            throw new RuntimeException('The initial test snapshot is incomplete.');
        }
    }
    return $state;
}

/** Fail before OJS bootstrap even when cli.php is invoked directly. */
function jqSafetyGuard(string $command): void
{
    $instance = (string) getenv('JQ_TEST_INSTANCE');
    $ip = (string) getenv('JQ_DB_IP');
    $nonce = (string) getenv('JQ_RUN_NONCE');
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $nonce)) {
        throw new RuntimeException('Start the suite through tests/run.sh; no authorized run exists.');
    }
    jqSafetyEnvironment($instance, $ip);
    $path = '/tmp/jqtests/authorization';
    if (is_link($path) || !is_file($path)
        || file_get_contents($path) !== "$nonce\n$instance\n$ip\n") {
        throw new RuntimeException('The active run authorization does not match this container.');
    }
    if (!in_array($command, ['mail-driver', 'snapshot'], true)) {
        jqSafetySnapshot('/tmp/jqtests/state.json');
    }
}
