<?php
/*
 * classes/QuotaService.php
 * Copyright (C) 2026 Stefano Natangelo
 * SPDX-License-Identifier: AGPL-3.0-only
 */

/**
 * @file plugins/generic/journalQuota/classes/QuotaService.php
 *
 * @class QuotaService
 *
 * @brief Measures journal disk usage, determines quota status,
 *  and sends alerts (email and OJS notifications) to journal managers.
 *
 *  OJS does not store file sizes, so the plugin maintains a ledger
 *  with one row per file (QuotaLedger), and sums those rows:
 *  - initially and every scanHours hours, a full disk scan
 *    reconciles the ledger;
 *  - between scans, submission-file uploads and deletions update
 *    their rows immediately, while small sections (issues, library,
 *    public files) are rescanned after user changes (refreshSmallParts).
 *  Pages read the ledger only; they never scan the disk.
 */

namespace APP\plugins\generic\journalQuota\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\notification\NotificationManager;
use APP\plugins\generic\journalQuota\JournalQuotaPlugin;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use PKP\config\Config;
use PKP\context\Context;
use PKP\notification\Notification;
use PKP\security\Role;
use Throwable;

class QuotaService
{
    public const LEVEL_OK = 0;
    public const LEVEL_WARN = 1;
    public const LEVEL_QUOTA = 2;
    public const LEVEL_TOLERANCE = 3;

    /** Block as soon as the quota is reached */
    public const MODE_BLOCK = 'block';
    /** Warn at the quota; block beyond the tolerance threshold */
    public const MODE_GRACE = 'grace';

    /** Maximum lifetime of an upload reservation */
    public const RESERVATION_SECONDS = 3600;

    /** Margin below a threshold before lowering the alert level (fraction of quota; at least 1 MB) */
    public const RESERVE_ATTEMPTS = 5;
    public const HYSTERESIS = 0.02;

    /** Value limits: maximum quota of 1 PB; tolerance at most ten times the quota */
    public const MAX_QUOTA_MB = 1000000000;
    public const MAX_TOLERANCE_PCT = 1000;

    /** Default hours between full disk scans */
    public const SCAN_HOURS = 24;

    public const DEFAULTS = [
        'quotaMb' => 0,
        'tolerancePct' => 10,
        'warnPct' => 80,
        'mode' => self::MODE_GRACE,
        'notifyWarn' => true,
        'notifyQuota' => true,
        'notifyTolerance' => true,
        'reminderDays' => 7,
        'countPublic' => true,
        'countDatabase' => true,
    ];

    /** Storage sections in display order, with their colors */
    public const PART_COLORS = [
        'articles' => '#1e6fa8',
        'issues' => '#7b4fc9',
        'library' => '#2e8b57',
        'other' => '#8a8a8a',
        'public' => '#e08a00',
        'database' => '#00838f',
    ];

    /** Minimum minutes between database-size estimates */
    public const DATABASE_MINUTES = 10;

    /** Computed per-journal status cached for this request */
    private array $statusCache = [];

    /** Messages delivered to the mail server in this process (MessageSent event) */
    private int $delivered = 0;

    private bool $listening = false;

    private ?QuotaLedger $ledger = null;

    /** Directory for locks and reservations (see stateDir) */
    private ?string $stateDir = null;

    public function __construct(private JournalQuotaPlugin $plugin)
    {
    }

    /**
     * Measure if requested, then synchronize alerts and email one journal at a time.
     *
     * The scheduled task, "Scan again", and settings updates may run
     * concurrently: without a lock, two processes could send the same
     * email and create duplicate notifications. The lock uses flock on a
     * file in files_dir; the OS releases it even if the process exits.
     * If another process is already checking the journal,
     * skip it and return null.
     */
    public function check(Context $context, bool $refresh): ?array
    {
        $lock = $this->lock($context->getId());
        if (!$lock) {
            return null;
        }
        try {
            if ($refresh) {
                $this->usage($context, true);
            }
            // Reload settings inside the lock to use the most recently saved values.
            $status = $this->status($context);
            $this->processAlerts($context, $status);
            return $status;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Reserve storage for an upload. Reservations and locks are kept in
     * the database (journal_quota_reservations), not files, so they do
     * not depend on directory permissions. Within a transaction, lock
     * the journal row, then read used space and reservations from
     * other active uploads: concurrent uploads cannot jointly
     * exceed the limit.
     *
     * @return array ['token' => token to release ('' when unlimited) or null
     *  if rejected, 'status' => journal status, 'pending' => bytes reserved by others]
     */
    public function reserveUpload(Context $context, int $bytes): array
    {
        $id = $context->getId();
        $this->ledger();
        // Before the first scan, used space appears as zero: a new
        // journal could accept unlimited files until the hourly check.
        // Scan now, once, outside the transaction because it may take time.
        if (!$this->hasUsage($context) && $this->settings($id)['quotaMb'] > 0) {
            $this->measureOnce($context);
        }
        $table = QuotaSchemaMigration::RESERVATIONS;
        // Create the journal lock row (zero bytes, never expires) in a
        // separate statement: the first operation inside the transaction
        // then acquires an exclusive lock directly. Concurrent upgrades
        // from shared to exclusive locks could otherwise deadlock.
        DB::table($table)->insertOrIgnore(['token' => "lock-{$id}", 'context_id' => $id, 'bytes' => 0, 'expires' => PHP_INT_MAX]);
        try {
            // If the database aborts a transaction (deadlock or excessive
            // lock wait), retry up to RESERVE_ATTEMPTS times.
            return DB::transaction(fn () => $this->reserveLocked($context, $id, $bytes), self::RESERVE_ATTEMPTS);
        } catch (QueryException $e) {
            if (!self::isConcurrencyError($e)) {
                throw $e;
            }
            // Too many concurrent uploads: reject this upload (it can be
            // retried immediately) rather than allowing it unchecked.
            return ['token' => null, 'busy' => true, 'status' => $this->status($context), 'pending' => 0];
        }
    }

    private function reserveLocked(Context $context, int $id, int $bytes): array
    {
        $table = QuotaSchemaMigration::RESERVATIONS;
        if (!DB::table($table)->where('token', "lock-{$id}")->lockForUpdate()->first()) {
            // Lock row deleted manually between statements: recreate it
            // and retry so the real row is locked.
            DB::table($table)->insertOrIgnore(['token' => "lock-{$id}", 'context_id' => $id, 'bytes' => 0, 'expires' => PHP_INT_MAX]);
            DB::table($table)->where('token', "lock-{$id}")->lockForUpdate()->first();
        }

        // Reread used space under the lock: a recently completed upload
        // already has a ledger row.
        $this->forgetStatus($id);
        $status = $this->status($context);
        if ($status['quota'] <= 0) {
            return ['token' => '', 'status' => $status, 'pending' => 0];
        }
        // Reservations from interrupted requests expire automatically.
        DB::table($table)->where('context_id', $id)->where('expires', '<', time())->delete();
        $pending = (int) DB::table($table)->where('context_id', $id)->sum('bytes');
        if ($status['blocked'] || $status['used'] + $pending + $bytes > $status['blockLimit']) {
            return ['token' => null, 'status' => $status, 'pending' => $pending];
        }
        $token = bin2hex(random_bytes(16));
        DB::table($table)->insert([
            'token' => $token,
            'context_id' => $id,
            'bytes' => $bytes,
            'expires' => time() + self::RESERVATION_SECONDS,
        ]);
        return ['token' => $token, 'status' => $status, 'pending' => $pending];
    }

    /** Deadlock or lock wait timeout (MySQL/MariaDB and PostgreSQL) */
    public static function isConcurrencyError(Throwable $e): bool
    {
        $info = $e instanceof QueryException ? ($e->errorInfo ?? []) : [];
        return in_array((int) ($info[1] ?? 0), [1213, 1205], true)
            || in_array((string) ($info[0] ?? $e->getCode()), ['40001', '40P01', '55P03'], true)
            || str_contains($e->getMessage(), 'Deadlock')
            || str_contains($e->getMessage(), 'Lock wait timeout');
    }

    /**
     * First scan of a journal, waiting for any scan already
     * running (sufficient if it completes successfully).
     */
    private function measureOnce(Context $context): void
    {
        $lock = $this->lock($context->getId(), true);
        try {
            if (!$this->hasUsage($context)) {
                $this->measure($context);
            }
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** At request completion, saved files are already in the ledger */
    public function releaseUpload(int $contextId, string $token): void
    {
        DB::table(QuotaSchemaMigration::RESERVATIONS)->where('token', $token)->delete();
    }

    /**
     * File ledger. Alerts and email remain on the hourly schedule
     * to avoid slowing down users' uploads.
     */
    public function ledger(): QuotaLedger
    {
        return $this->ledger ??= new QuotaLedger($this->plugin);
    }

    /** A file in files_dir was uploaded: count it immediately */
    public function fileStored(string $relative): void
    {
        $this->forgetStatus($this->ledger()->stored($relative));
    }

    /** A file in files_dir may have been deleted */
    public function fileRemoved(string $relative): void
    {
        $this->forgetStatus($this->ledger()->removed($relative));
    }

    public function submissionRemoved(int $submissionId): void
    {
        $this->forgetStatus($this->ledger()->removedSubmission($submissionId));
    }

    public function contextRemoved(int $contextId): void
    {
        $this->ledger()->dropContext($contextId);
        $this->forgetStatus($contextId);
    }

    private function forgetStatus(?int $contextId): void
    {
        if ($contextId !== null) {
            unset($this->statusCache[$contextId]);
        }
    }

    /**
     * Lock for a journal check, or null if another process holds it
     *
     * @return resource|null
     */
    /**
     * Directory for check locks (files_dir/journalQuota). These locks
     * only prevent duplicate emails; checks still work if absent.
     * Upload reservations remain in the database.
     */
    private function stateDir(): string
    {
        if ($this->stateDir !== null) {
            return $this->stateDir;
        }
        $dir = rtrim(Config::getVar('files', 'files_dir'), '/') . '/journalQuota';
        if (!is_dir($dir) && @mkdir($dir, 0775, true)) {
            // As with files, web and CLI processes may run as different users.
            @chmod($dir, 0777);
        }
        return $this->stateDir = $dir;
    }

    /**
     * Record the latest problem (at most once per hour for the same
     * message) for display on the administrator page.
     */
    public function reportProblem(string $message): void
    {
        error_log('journalQuota: ' . $message);
        try {
            $site = Application::SITE_CONTEXT_ID;
            $last = (array) ($this->plugin->getSetting($site, 'lastProblem') ?: []);
            if (($last['message'] ?? '') !== $message || time() - (int) ($last['at'] ?? 0) >= 3600) {
                $this->plugin->updateSetting($site, 'lastProblem', ['at' => time(), 'message' => mb_substr($message, 0, 500)], 'object');
            }
        } catch (Throwable) {
            // The database itself is unavailable: keep only the log entry.
        }
    }

    /** Latest problem from the past seven days, or null */
    public function lastProblem(): ?array
    {
        $last = (array) ($this->plugin->getSetting(Application::SITE_CONTEXT_ID, 'lastProblem') ?: []);
        return isset($last['at']) && time() - (int) $last['at'] < 7 * 86400 ? $last : null;
    }

    private function lock(int|string $name, bool $wait = false)
    {
        $dir = $this->stateDir();
        $file = is_int($name) ? "{$dir}/check-{$name}.lock" : "{$dir}/{$name}.lock";
        $new = !file_exists($file);
        $handle = @fopen($file, 'c');
        if ($handle && $new) {
            // Checks may run via web or CLI under different user accounts.
            @chmod($file, 0666);
        }
        if (!$handle) {
            // Proceed without a lock file: a duplicate email is better than no alert.
            $this->reportProblem("cannot create the lock file in {$dir}");
            return fopen('php://memory', 'r');
        }
        if (!flock($handle, $wait ? LOCK_EX : LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    /**
     * Effective journal settings: custom values when configured,
     * otherwise the site-wide defaults.
     */
    public function settings(int $contextId): array
    {
        return $this->isCustom($contextId) ? $this->ownSettings($contextId) : $this->defaults();
    }

    /** Site-wide defaults for journals without custom quotas */
    public function defaults(): array
    {
        return $this->read(Application::SITE_CONTEXT_ID, self::DEFAULTS);
    }

    /** Stored journal settings, including when not currently effective */
    public function ownSettings(int $contextId): array
    {
        return $this->read($contextId, $this->defaults());
    }

    public function isCustom(int $contextId): bool
    {
        return (bool) $this->plugin->getSetting($contextId, 'custom');
    }

    /** Save site-wide defaults */
    public function saveDefaults(array $settings): void
    {
        $this->write(Application::SITE_CONTEXT_ID, $settings);
        $this->statusCache = [];
    }

    /**
     * Save journal settings. Without a custom quota, its stored
     * values are inactive but retained for later reactivation.
     */
    public function saveSettings(int $contextId, array $settings, bool $custom = true): void
    {
        $this->plugin->updateSetting($contextId, 'custom', $custom, 'bool');
        if ($custom) {
            $this->write($contextId, $settings);
        }
        $this->statusCache = [];
    }

    private function read(?int $contextId, array $fallback): array
    {
        $settings = [];
        foreach (self::DEFAULTS as $name => $default) {
            $value = $this->plugin->getSetting($contextId, $name);
            $settings[$name] = match (true) {
                $value === null => $fallback[$name],
                is_bool($default) => (bool) $value,
                is_int($default) => max(0, (int) $value),
                default => (string) $value,
            };
        }
        if (!in_array($settings['mode'], [self::MODE_BLOCK, self::MODE_GRACE])) {
            $settings['mode'] = self::DEFAULTS['mode'];
        }
        $settings['warnPct'] = min(100, max(1, $settings['warnPct']));
        // Extremely large values could exceed integer limits.
        $settings['quotaMb'] = min(self::MAX_QUOTA_MB, $settings['quotaMb']);
        $settings['tolerancePct'] = min(self::MAX_TOLERANCE_PCT, $settings['tolerancePct']);
        return $settings;
    }

    private function write(?int $contextId, array $settings): void
    {
        foreach (self::DEFAULTS as $name => $default) {
            $type = is_bool($default) ? 'bool' : (is_int($default) ? 'int' : 'string');
            $this->plugin->updateSetting($contextId, $name, $settings[$name] ?? $default, $type);
        }
    }

    /**
     * Used storage. Without $refresh, always use the latest stored scan,
     * even when stale: pages must never scan the disk. Scans run
     * only from the scheduled task, settings updates, or
     * "Scan again". Before the first scan, usage is zero (never blocked).
     */
    public function usage(Context $context, bool $refresh = false): array
    {
        if ($refresh) {
            $this->measure($context);
        }
        $id = $context->getId();
        $scannedAt = $this->scannedAt($context);
        $parts = $scannedAt ? $this->ledger()->totals($id) : array_fill_keys(QuotaLedger::PARTS, 0);
        $parts['database'] = $scannedAt ? (int) $this->plugin->getSetting($id, 'dbBytes') : 0;
        return [
            'parts' => $parts,
            'computedAt' => $scannedAt,
            // Largest tables in the database estimate: table => bytes
            'dbTables' => (array) ($this->plugin->getSetting($id, 'dbTables') ?: []),
        ];
    }

    /**
     * Estimate each journal's database usage. This is periodic, not
     * triggered by uploads: recalculate with a full scan (every scanHours
     * hours or on "Scan again"), once for all journals and no more
     * often than every DATABASE_MINUTES minutes.
     */
    public function measureDatabase(): void
    {
        if (!QuotaDatabase::supported()) {
            return;
        }
        $site = Application::SITE_CONTEXT_ID;
        if (time() - (int) $this->plugin->getSetting($site, 'dbMeasuredAt') < self::DATABASE_MINUTES * 60) {
            return;
        }
        $lock = $this->lock('database');
        if (!$lock) {
            return;
        }
        try {
            foreach ((new QuotaDatabase())->measure() as $contextId => $entry) {
                $this->plugin->updateSetting($contextId, 'dbBytes', $entry['bytes'], 'int');
                $this->plugin->updateSetting($contextId, 'dbTables', $entry['tables'], 'object');
            }
            $this->plugin->updateSetting($site, 'dbMeasuredAt', time(), 'int');
            $this->statusCache = [];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Usage breakdown by section, for pages and charts
     *
     * @return array list of ['key', 'label', 'bytes', 'size', 'counted', 'color', 'percent']
     */
    public function breakdown(array $status): array
    {
        $parts = $status['usage']['parts'];
        $settings = $status['settings'];
        $total = array_sum($parts);
        $result = [];
        foreach (self::PART_COLORS as $key => $color) {
            $bytes = $parts[$key] ?? 0;
            $result[] = [
                'key' => $key,
                'label' => __("plugins.generic.journalQuota.part.{$key}"),
                'bytes' => $bytes,
                'size' => self::formatBytes($bytes),
                'counted' => match ($key) {
                    'public' => $settings['countPublic'],
                    'database' => $settings['countDatabase'],
                    default => true,
                },
                'color' => $color,
                'percent' => $total > 0 ? round($bytes * 100 / $total, 1) : 0,
            ];
        }
        return $result;
    }

    /** Timestamp of the latest full scan (0 if none has run) */
    private function scannedAt(Context $context): int
    {
        return (int) $this->plugin->getSetting($context->getId(), 'scannedAt');
    }

    public function hasUsage(Context $context): bool
    {
        return $this->scannedAt($context) > 0;
    }

    /** Largest submissions by storage usage: ID => bytes */
    public function top(Context $context): array
    {
        return $this->hasUsage($context) ? $this->ledger()->top($context->getId()) : [];
    }

    /**
     * Request a full scan of all journals at the next hourly check
     * (for example after reactivation: while disabled, the plugin did not
     * observe uploads or deletions).
     */
    public function requestFullScan(): void
    {
        $this->plugin->updateSetting(Application::SITE_CONTEXT_ID, 'scanRequestedAt', time(), 'int');
    }

    /** Hours between full scans by the scheduled task */
    public function scanHours(): int
    {
        $hours = (int) $this->plugin->getSetting(Application::SITE_CONTEXT_ID, 'scanHours');
        return $hours > 0 ? $hours : self::SCAN_HOURS;
    }

    public function saveScanHours(int $hours): void
    {
        $this->plugin->updateSetting(Application::SITE_CONTEXT_ID, 'scanHours', max(1, $hours), 'int');
    }

    /** Whether a full scan is due (10-minute allowance around the hourly task) */
    public function fullScanDue(Context $context): bool
    {
        $scannedAt = $this->scannedAt($context);
        return !$scannedAt
            || $scannedAt < (int) $this->plugin->getSetting(Application::SITE_CONTEXT_ID, 'scanRequestedAt')
            || time() - $scannedAt >= $this->scanHours() * 3600 - 600;
    }

    /**
     * Rescan only small sections (issues, library, other, public
     * files); submission files are tracked individually through hooks.
     * OJS does not notify plugins about these uploads, so rescan
     * after each user modification.
     */
    public function refreshSmallParts(Context $context): void
    {
        if ($this->hasUsage($context)) {
            $this->ledger()->sync($context, QuotaLedger::SMALL_PARTS);
            $this->forgetStatus($context->getId());
        }
    }

    /**
     * Full disk scan of the journal's private (files_dir) and public
     * files. Called from check(), so scans for one journal do not overlap.
     */
    private function measure(Context $context): void
    {
        // An interrupted scan might not save scannedAt and would then
        // repeat indefinitely; large journals may exceed max_execution_time.
        @set_time_limit(0);
        $startedAt = time();
        $ledger = $this->ledger();
        $ledger->sync($context, QuotaLedger::PARTS);
        $ledger->purge($context->getId());
        try {
            $this->measureDatabase();
        } catch (Throwable $e) {
            error_log('journalQuota: database estimate failed: ' . $e->getMessage());
        }
        $this->plugin->updateSetting($context->getId(), 'scannedAt', $startedAt, 'int');
        $this->forgetStatus($context->getId());
    }

    /**
     * Journal status relative to its quota.
     *
     * @param bool $refresh true to remeasure storage regardless
     */
    public function status(Context $context, bool $refresh = false): array
    {
        // Without a new scan, status stays unchanged during this request:
        // pages query it repeatedly (blocking, header, notifications).
        if (!$refresh && isset($this->statusCache[$context->getId()])) {
            return $this->statusCache[$context->getId()];
        }
        $settings = $this->settings($context->getId());
        $usage = $this->usage($context, $refresh);
        $parts = $usage['parts'];
        $used = $parts['articles'] + $parts['issues'] + $parts['library'] + $parts['other']
            + ($settings['countPublic'] ? $parts['public'] : 0)
            + ($settings['countDatabase'] ? $parts['database'] : 0);

        $quota = $settings['quotaMb'] * 1024 * 1024;
        $toleranceLimit = (int) round($quota * (1 + $settings['tolerancePct'] / 100));
        $blockLimit = $settings['mode'] === self::MODE_BLOCK ? $quota : $toleranceLimit;

        $level = self::LEVEL_OK;
        if ($quota > 0) {
            if ($used >= $toleranceLimit && $toleranceLimit > $quota) {
                $level = self::LEVEL_TOLERANCE;
            } elseif ($used >= $quota) {
                $level = self::LEVEL_QUOTA;
            } elseif ($used * 100 >= $quota * $settings['warnPct']) {
                $level = self::LEVEL_WARN;
            }
        }

        return $this->statusCache[$context->getId()] = [
            'settings' => $settings,
            'usage' => $usage,
            'used' => $used,
            'quota' => $quota,
            'warnLimit' => (int) round($quota * $settings['warnPct'] / 100),
            'toleranceLimit' => $toleranceLimit,
            'blockLimit' => $blockLimit,
            'percent' => $quota > 0 ? round($used * 100 / $quota, 1) : null,
            'level' => $level,
            'blocked' => $quota > 0 && $used >= $blockLimit,
        ];
    }

    /**
     * Synchronize notifications and email with the current level. Called
     * only from check(), while holding the journal lock.
     *
     * notifiedLevel is the highest level already communicated by email.
     * Send email when the level exceeds notifiedLevel (retry until
     * delivered), or as a reminder every reminderDays days. If the
     * level falls, lower notifiedLevel only 24 hours after the last email
     * to prevent repeated messages when usage oscillates around
     * a threshold.
     */
    private function processAlerts(Context $context, array $status): void
    {
        $id = $context->getId();
        // In immediate-block mode, closure occurs at the quota and
        // counts as the highest alert level.
        $level = $status['blocked'] ? self::LEVEL_TOLERANCE : $status['level'];
        $lastLevel = (int) $this->plugin->getSetting($id, 'lastLevel');
        // Hysteresis: usage fluctuating around a threshold (database
        // estimates change slightly on each scan) must not remove and
        // recreate alerts every hour. Lower the level only below the
        // threshold minus a margin. Blocking always uses actual status.
        if ($level < $lastLevel && $status['quota'] > 0) {
            $threshold = match ($lastLevel) {
                self::LEVEL_WARN => $status['warnLimit'],
                self::LEVEL_QUOTA => $status['quota'],
                default => $status['blockLimit'],
            };
            $margin = max(1024 * 1024, (int) ($status['quota'] * self::HYSTERESIS));
            if ($status['used'] >= $threshold - $margin) {
                $level = $lastLevel;
            }
        }
        $lastNotifiedAt = (int) $this->plugin->getSetting($id, 'lastNotifiedAt');
        $notifiedLevel = (int) $this->plugin->getSetting($id, 'notifiedLevel');
        $settings = $status['settings'];

        // In-app bell: recreate notifications on level changes (marking
        // them unread); otherwise add new managers and remove former ones.
        $this->syncNotifications($context, $level, $level === $lastLevel);
        if ($level !== $lastLevel) {
            $this->plugin->updateSetting($id, 'lastLevel', $level, 'int');
        }

        if ($level < $notifiedLevel && time() - $lastNotifiedAt >= 86400) {
            $notifiedLevel = $level;
            $this->plugin->updateSetting($id, 'notifiedLevel', $level, 'int');
        }

        $emailEnabled = match ($level) {
            self::LEVEL_WARN => $settings['notifyWarn'],
            self::LEVEL_QUOTA => $settings['notifyQuota'],
            // In immediate-block mode, reaching the quota also closes submissions: either setting suffices.
            self::LEVEL_TOLERANCE => $settings['notifyTolerance']
                || ($settings['mode'] === self::MODE_BLOCK && $settings['notifyQuota']),
            default => false,
        };
        $risen = $level > $notifiedLevel;
        $reminderDue = $level > self::LEVEL_OK
            && $settings['reminderDays'] > 0
            && time() - $lastNotifiedAt >= $settings['reminderDays'] * 86400;

        // Managers who missed the last email: retry only for them.
        $pending = array_map('intval', (array) ($this->plugin->getSetting($id, 'emailPending') ?: []));
        if ($emailEnabled && ($risen || $reminderDue)) {
            $result = $this->sendEmails($context, $status);
            if ($result['sent'] > 0) {
                $this->plugin->updateSetting($id, 'lastNotifiedAt', time(), 'int');
                $this->plugin->updateSetting($id, 'notifiedLevel', max($level, $notifiedLevel), 'int');
            }
            // If nobody received it, retry for all managers.
            $this->savePending($id, $pending, $result['sent'] > 0 ? $result['failed'] : []);
        } elseif ($pending && $emailEnabled && $level > self::LEVEL_OK && $level <= $notifiedLevel) {
            $this->savePending($id, $pending, $this->sendEmails($context, $status, $pending)['failed']);
        } elseif ($pending) {
            $this->savePending($id, $pending, []);
        }
    }

    private function savePending(int $contextId, array $old, array $new): void
    {
        if ($old !== $new) {
            $this->plugin->updateSetting($contextId, 'emailPending', array_values($new), 'object');
        }
    }

    /** Remove all plugin notifications (for example, on deactivation) */
    public function clearAlerts(): void
    {
        Notification::withType(JournalQuotaPlugin::NOTIFICATION_TYPE)->delete();
        foreach (Application::getContextDAO()->getAll()->toArray() as $context) {
            $this->plugin->updateSetting($context->getId(), 'lastLevel', self::LEVEL_OK, 'int');
        }
    }

    /**
     * One OJS in-app notification per current manager, only for the
     * current level. With $keep, retain managers' existing notifications.
     */
    private function syncNotifications(Context $context, int $level, bool $keep): void
    {
        $existing = fn () => Notification::withType(JournalQuotaPlugin::NOTIFICATION_TYPE)
            ->withContextId($context->getId());
        $managerIds = [];
        if ($level !== self::LEVEL_OK) {
            foreach ($this->managers($context) as $user) {
                $managerIds[] = (int) $user->getId();
            }
        }
        if ($keep) {
            $existing()->whereNotIn('user_id', $managerIds)->delete();
        } else {
            $existing()->delete();
        }
        if ($level === self::LEVEL_OK) {
            return;
        }

        $skip = $keep ? $existing()->pluck('user_id')->map(fn ($id) => (int) $id)->all() : [];
        $notificationManager = new NotificationManager();
        foreach ($managerIds as $userId) {
            if (in_array($userId, $skip, true)) {
                continue;
            }
            $notificationManager->createNotification(
                $userId,
                JournalQuotaPlugin::NOTIFICATION_TYPE,
                $context->getId(),
                Application::ASSOC_TYPE_JOURNAL,
                $context->getId(),
                Notification::NOTIFICATION_LEVEL_TASK
            );
        }
    }

    /**
     * Send email to managers (or only those listed in $only).
     *
     * @return array ['sent' => number delivered, 'failed' => IDs of unreached managers]
     */
    private function sendEmails(Context $context, array $status, ?array $only = null): array
    {
        $locale = $this->locale($context);
        $template = Repo::emailTemplate()->getByKey($context->getId(), QuotaNotify::getEmailTemplateKey());
        // If the template is not installed (plugin copied via FTP), use default text.
        $subject = $template?->getLocalizedData('subject', $locale) ?: __('emails.journalQuota.subject', [], $locale);
        $body = $template?->getLocalizedData('body', $locale) ?: __('emails.journalQuota.body', [], $locale);

        $variables = $this->emailVariables($context, $status, $locale);
        if (!$this->listening) {
            Event::listen(MessageSent::class, function () {
                $this->delivered++;
            });
            $this->listening = true;
        }
        $sent = 0;
        $failed = [];
        // Recipients: this journal's managers (keyed by user ID).
        $targets = [];
        foreach ($this->managers($context) as $user) {
            $targets[(int) $user->getId()] = $user;
        }
        foreach ($targets as $key => $user) {
            if ($only !== null && !in_array($key, $only, true)) {
                continue;
            }
            $failed[] = $key;
            try {
                $mailable = new QuotaNotify($context, $variables);
                $mailable->recipients([$user], $locale);
                if ($context->getData('contactEmail')) {
                    $mailable->from($context->getData('contactEmail'), $context->getData('contactName'));
                }
                $mailable->subject($subject);
                $mailable->body($body);
                $mailable->setLocale($locale);
                // OJS logs SMTP errors without throwing exceptions, and
                // Mail::send() returns nothing: delivery is confirmed only when
                // Laravel emits the MessageSent event.
                $before = $this->delivered;
                Mail::send($mailable);
                if ($this->delivered > $before) {
                    $sent++;
                    array_pop($failed);
                } else {
                    error_log('journalQuota: email to ' . $user->getEmail() . ' failed');
                }
            } catch (Throwable $e) {
                error_log('journalQuota: email to ' . $user->getEmail() . ' failed: ' . $e->getMessage());
            }
        }
        return ['sent' => $sent, 'failed' => $failed];
    }

    /** Language for journal messages: primary locale if supported; otherwise English */
    public function locale(Context $context): string
    {
        $locale = $context->getPrimaryLocale();
        return is_dir(dirname(__DIR__) . "/locale/{$locale}") ? $locale : 'en';
    }

    public function emailVariables(Context $context, array $status, ?string $locale = null): array
    {
        $request = Application::get()->getRequest();
        return [
            QuotaNotify::QUOTA_USED => self::formatBytes($status['used']),
            QuotaNotify::QUOTA_LIMIT => self::formatBytes($status['quota']),
            QuotaNotify::QUOTA_PERCENT => (string) $status['percent'],
            QuotaNotify::QUOTA_MESSAGE => $this->levelMessage($status, $locale),
            QuotaNotify::QUOTA_URL => Application::get()->getDispatcher()->url(
                $request,
                Application::ROUTE_PAGE,
                $context->getPath(),
                JournalQuotaPlugin::PAGE
            ),
        ];
    }

    /** Text explaining the current level and what happens to submissions */
    public function levelMessage(array $status, ?string $locale = null): string
    {
        $params = [
            'blockLimit' => self::formatBytes($status['blockLimit']),
            'quota' => self::formatBytes($status['quota']),
        ];
        $key = match ($status['level']) {
            self::LEVEL_WARN => 'warn',
            self::LEVEL_QUOTA => $status['blocked'] ? 'blocked' : 'quota',
            self::LEVEL_TOLERANCE => 'blocked',
            default => 'ok',
        };
        return __("plugins.generic.journalQuota.level.{$key}", $params, $locale);
    }

    /** Active journal managers */
    public function managers(Context $context): iterable
    {
        return Repo::user()->getCollector()
            ->filterByContextIds([$context->getId()])
            ->filterByRoleIds([Role::ROLE_ID_MANAGER])
            ->getMany();
    }

    public static function formatBytes(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return ($i === 0 ? $bytes : number_format($bytes, $bytes < 10 ? 2 : 1)) . ' ' . $units[$i];
    }
}
