<?php
/*
 * classes/QuotaLedger.php
 * Copyright (C) 2026 Stefano Natangelo
 * SPDX-License-Identifier: AGPL-3.0-only
 */

/**
 * @file plugins/generic/journalQuota/classes/QuotaLedger.php
 *
 * @class QuotaLedger
 *
 * @brief Ledger of counted files (journal_quota_files table), one row
 *  per file, keyed by its path.
 *
 *  Why uploads, deletions, and scans do not race:
 *  - counting the same file twice (copy to another stage, hook and full
 *    scan together) updates the same row instead of adding its size;
 *  - each row records when the file was last seen (seen_at) or deleted
 *    (deleted_at). A disk scan started at time S updates only rows
 *    untouched since S: if a file is uploaded or deleted while the
 *    scan runs, the newer hook event takes precedence;
 *  - each write is a single conditional SQL statement, so
 *    no locks or inter-process waiting are required.
 *  Deleted rows remain for TOMBSTONE_DAYS days before cleanup.
 */

namespace APP\plugins\generic\journalQuota\classes;

use APP\core\Application;
use APP\file\PublicFileManager;
use APP\plugins\generic\journalQuota\JournalQuotaPlugin;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PKP\config\Config;
use PKP\context\Context;
use PKP\core\Core;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

class QuotaLedger
{
    public const TABLE = QuotaSchemaMigration::TABLE;
    public const SCHEMA_VERSION = 2;

    public const PARTS = ['articles', 'issues', 'library', 'other', 'public'];
    /** Sections without OJS hooks, remeasured after user changes */
    public const SMALL_PARTS = ['issues', 'library', 'other', 'public'];

    /** Journal directories under files_dir that are excluded from the quota */
    public const EXCLUDED_DIRS = ['pln'];

    /** Number of days to retain deleted rows as tombstones */
    public const TOMBSTONE_DAYS = 2;

    private static bool $schemaChecked = false;

    public function __construct(private JournalQuotaPlugin $plugin)
    {
        $this->ensureSchema();
    }

    /** Current time with the precision stored in the table */
    public static function now(): float
    {
        return round(microtime(true), 4);
    }

    /**
     * Create the table if absent: plugin installation creates it, but an
     * update deployed via FTP or the gallery might not.
     */
    private function ensureSchema(): void
    {
        if (self::$schemaChecked) {
            return;
        }
        if ((int) $this->plugin->getSetting(Application::SITE_CONTEXT_ID, 'schemaVersion') < self::SCHEMA_VERSION) {
            try {
                (new QuotaSchemaMigration())->up();
            } catch (Throwable $e) {
                // Concurrent requests: the other request just created the table.
                if (!Schema::hasTable(self::TABLE) || !Schema::hasTable(QuotaSchemaMigration::RESERVATIONS)) {
                    throw $e;
                }
            }
            $this->plugin->updateSetting(Application::SITE_CONTEXT_ID, 'schemaVersion', self::SCHEMA_VERSION, 'int');
        }
        self::$schemaChecked = true;
    }

    private static function filesDir(): string
    {
        return rtrim(Config::getVar('files', 'files_dir'), '/');
    }

    /**
     * File-ledger row for a relative files_dir path, or null if the file
     * does not belong to a journal or is in an excluded directory.
     */
    public static function classify(string $relative): ?array
    {
        $relative = ltrim(preg_replace('#/+#', '/', $relative), '/');
        if ($relative === '' || str_contains("/{$relative}/", '/../')) {
            return null;
        }
        $submissionId = null;
        if (preg_match('#^journals/(\d+)/(.+)$#', $relative, $m)) {
            $contextId = (int) $m[1];
            $segments = explode('/', $m[2]);
            $part = match (count($segments) > 1 ? $segments[0] : '') {
                'articles' => 'articles',
                'issues' => 'issues',
                default => 'other',
            };
            if (count($segments) > 1 && in_array($segments[0], self::EXCLUDED_DIRS)) {
                return null;
            }
            if ($part === 'articles' && count($segments) > 2 && ctype_digit($segments[1])) {
                $submissionId = (int) $segments[1];
            }
        } elseif (preg_match('#^contexts/(\d+)/library/.#', $relative, $m)) {
            $contextId = (int) $m[1];
            $part = 'library';
        } else {
            return null;
        }
        return self::row($contextId, $part, 'f:' . $relative, $submissionId);
    }

    private static function row(int $contextId, string $part, string $key, ?int $submissionId = null): array
    {
        return [
            'context_id' => $contextId,
            'path_hash' => sha1($key),
            'path' => mb_substr($key, 0, 1024),
            'part' => $part,
            'submission_id' => $submissionId,
            'bytes' => 0,
        ];
    }

    /**
     * A file in files_dir was uploaded: count it immediately (or update
     * its existing row). Return the journal, or null if excluded.
     */
    public function stored(string $relative): ?int
    {
        $row = self::classify($relative);
        $path = self::filesDir() . '/' . ltrim($relative, '/');
        if (!$row || !is_file($path)) {
            return null;
        }
        $row['bytes'] = (int) filesize($path);
        $row['seen_at'] = self::now();
        $row['deleted_at'] = null;
        DB::table(self::TABLE)->upsert(
            [$row],
            ['path_hash'],
            ['context_id', 'part', 'submission_id', 'bytes', 'seen_at', 'deleted_at']
        );
        return $row['context_id'];
    }

    /**
     * A file in files_dir may have been deleted: if it is no longer on
     * disk, record a tombstone. Return the journal, or null.
     */
    public function removed(string $relative): ?int
    {
        $row = self::classify($relative);
        if (!$row || file_exists(self::filesDir() . '/' . ltrim($relative, '/'))) {
            return null;
        }
        $now = self::now();
        // Even without a prior row, the tombstone prevents an ongoing scan from counting it.
        DB::table(self::TABLE)->upsert(
            [$row + ['seen_at' => $now, 'deleted_at' => $now]],
            ['path_hash'],
            ['deleted_at']
        );
        return $row['context_id'];
    }

    /** Submission rows whose files are no longer on disk (deleted submission) */
    public function removedSubmission(int $submissionId): ?int
    {
        $contextId = null;
        $rows = DB::table(self::TABLE)->where('submission_id', $submissionId)->whereNull('deleted_at')->get(['path']);
        foreach ($rows as $row) {
            if (str_starts_with($row->path, 'f:')) {
                $contextId = $this->removed(substr($row->path, 2)) ?? $contextId;
            }
        }
        return $contextId;
    }

    /** Deleted journal: its ledger rows are no longer needed */
    public function dropContext(int $contextId): void
    {
        DB::table(self::TABLE)->where('context_id', $contextId)->delete();
    }

    /** Bytes per section, counting only existing files */
    public function totals(int $contextId): array
    {
        $parts = array_fill_keys(self::PARTS, 0);
        $rows = DB::table(self::TABLE)
            ->where('context_id', $contextId)
            ->whereNull('deleted_at')
            ->groupBy('part')
            ->select('part')
            ->selectRaw('SUM(bytes) AS total')
            ->get();
        foreach ($rows as $row) {
            if (isset($parts[$row->part])) {
                $parts[$row->part] = (int) $row->total;
            }
        }
        return $parts;
    }

    /** Largest submissions by storage usage: ID => bytes */
    public function top(int $contextId, int $limit = 10): array
    {
        return DB::table(self::TABLE)
            ->where('context_id', $contextId)
            ->whereNull('deleted_at')
            ->whereNotNull('submission_id')
            ->groupBy('submission_id')
            ->select('submission_id')
            ->selectRaw('SUM(bytes) AS total')
            ->orderByDesc('total')
            ->limit($limit)
            ->pluck('total', 'submission_id')
            ->map(fn ($bytes) => (int) $bytes)
            ->all();
    }

    /**
     * Scan the selected sections and reconcile the ledger. $since is
     * the scan start time; rows changed afterwards are left untouched.
     */
    public function sync(Context $context, array $parts): void
    {
        $since = self::now();
        $skipped = [];
        $found = $this->scan($context, $parts, $skipped);
        $this->reconcile($context->getId(), $parts, $found, $since, $skipped);
    }

    /** Remove old tombstones */
    public function purge(int $contextId): void
    {
        DB::table(self::TABLE)
            ->where('context_id', $contextId)
            ->where('deleted_at', '<', self::now() - self::TOMBSTONE_DAYS * 86400)
            ->delete();
    }

    /**
     * Reconcile the ledger with the scan results.
     *
     * @param array $found path_hash => row, for files found on disk
     * @param array $skipped prefixes of paths the scan could not
     *  read (unreadable directories or files of unknown size);
     *  their files must not be treated as deleted.
     */
    public function reconcile(int $contextId, array $parts, array $found, float $since, array $skipped = []): void
    {
        $existing = DB::table(self::TABLE)
            ->where('context_id', $contextId)
            ->whereIn('part', $parts)
            ->get(['path_hash', 'path', 'submission_id', 'bytes', 'seen_at', 'deleted_at'])
            ->keyBy('path_hash');
        // Only rows untouched since the scan started.
        $untouched = fn () => DB::table(self::TABLE)
            ->where('seen_at', '<', $since)
            ->where(fn ($q) => $q->whereNull('deleted_at')->orWhere('deleted_at', '<', $since));

        $insert = [];
        foreach ($found as $hash => $row) {
            $old = $existing[$hash] ?? null;
            if (!$old) {
                $insert[] = $row + ['seen_at' => $since, 'deleted_at' => null];
                continue;
            }
            if (max((float) $old->seen_at, (float) $old->deleted_at) >= $since) {
                continue;
            }
            $changed = $old->deleted_at !== null
                || (int) $old->bytes !== $row['bytes']
                || ($old->submission_id === null ? null : (int) $old->submission_id) !== $row['submission_id'];
            if ($changed) {
                $untouched()->where('path_hash', $hash)->update([
                    'bytes' => $row['bytes'],
                    'submission_id' => $row['submission_id'],
                    'seen_at' => $since,
                    'deleted_at' => null,
                ]);
            }
        }
        // If a hook just inserted the same row, its value wins.
        foreach (array_chunk($insert, 500, true) as $chunk) {
            DB::table(self::TABLE)->insertOrIgnore(array_values($chunk));
            // If instead an earlier scan inserted it (concurrently with
            // this one), its size is older, so use ours.
            $older = DB::table(self::TABLE)
                ->whereIn('path_hash', array_column($chunk, 'path_hash'))
                ->where('seen_at', '<', $since)
                ->where(fn ($q) => $q->whereNull('deleted_at')->orWhere('deleted_at', '<', $since))
                ->get(['path_hash', 'bytes', 'submission_id', 'deleted_at']);
            foreach ($older as $old) {
                $row = $found[$old->path_hash];
                if ($old->deleted_at !== null || (int) $old->bytes !== $row['bytes']
                    || ($old->submission_id === null ? null : (int) $old->submission_id) !== $row['submission_id']) {
                    $untouched()->where('path_hash', $old->path_hash)->update([
                        'bytes' => $row['bytes'],
                        'submission_id' => $row['submission_id'],
                        'seen_at' => $since,
                        'deleted_at' => null,
                    ]);
                }
            }
        }

        $gone = [];
        foreach ($existing as $hash => $old) {
            if (!isset($found[$hash]) && $old->deleted_at === null && (float) $old->seen_at < $since
                && !$this->isSkipped((string) $old->path, $skipped)) {
                $gone[] = $hash;
            }
        }
        foreach (array_chunk($gone, 500) as $chunk) {
            $untouched()->whereIn('path_hash', $chunk)->update(['deleted_at' => $since]);
        }
    }

    /**
     * Files found on disk in the selected sections.
     *
     * @param array $skipped receives unreadable path prefixes (see reconcile)
     *
     * @return array path_hash => row
     */
    public function scan(Context $context, array $parts, array &$skipped = []): array
    {
        $id = $context->getId();
        $filesDir = self::filesDir();
        $journalDir = "{$filesDir}/journals/{$id}";
        $found = [];
        $privateKey = fn (string $path) => 'f:' . substr($path, strlen($filesDir) + 1);
        // File directory not mounted or unreadable: do not mark files as deleted.
        if (!is_dir($filesDir) || !is_readable($filesDir) || !is_executable($filesDir)) {
            error_log("journalQuota: files_dir not reachable, scan skipped: {$filesDir}");
            $skipped[] = 'f:';
        }
        $skipPrivate = function (string $path) use ($privateKey, &$skipped) {
            $skipped[] = $privateKey($path);
        };
        $addPrivate = function (string $path) use ($filesDir, $id, $parts, &$found, $skipPrivate) {
            $row = self::classify(substr($path, strlen($filesDir) + 1));
            if ($row && $row['context_id'] === $id && in_array($row['part'], $parts)) {
                $bytes = self::bytes($path);
                if ($bytes === null) {
                    $skipPrivate($path);
                } else {
                    $found[$row['path_hash']] = ['bytes' => $bytes] + $row;
                }
            }
        };

        $roots = [];
        foreach (['articles', 'issues'] as $part) {
            if (in_array($part, $parts)) {
                $roots[] = "{$journalDir}/{$part}";
            }
        }
        if (in_array('other', $parts)) {
            if ((is_dir($journalDir) && !(is_readable($journalDir) && is_executable($journalDir)))
                || (!is_dir($journalDir) && self::unreachable($journalDir))) {
                $skipPrivate($journalDir);
            }
            foreach (self::entries($journalDir) as $item) {
                if (!in_array($item->getFilename(), ['articles', 'issues', ...self::EXCLUDED_DIRS])) {
                    $roots[] = $item->getPathname();
                }
            }
        }
        if (in_array('library', $parts)) {
            $roots[] = "{$filesDir}/contexts/{$id}/library";
        }
        foreach ($roots as $root) {
            self::walk($root, $addPrivate, $skipPrivate);
        }

        if (in_array('public', $parts)) {
            $baseDir = rtrim(Core::getBaseDir(), '/');
            $publicDir = $baseDir . '/' . trim((new PublicFileManager())->getContextFilesPath($id), '/');
            $publicKey = fn (string $path) => 'p:' . substr($path, strlen($baseDir) + 1);
            $skipPublic = function (string $path) use ($publicKey, &$skipped) {
                $skipped[] = $publicKey($path);
            };
            self::walk($publicDir, function (string $path) use ($id, &$found, $publicKey, $skipPublic) {
                if (($bytes = self::bytes($path)) === null) {
                    $skipPublic($path);
                } else {
                    $row = self::row($id, 'public', $publicKey($path));
                    $found[$row['path_hash']] = ['bytes' => $bytes] + $row;
                }
            }, $skipPublic);
        }
        return $found;
    }

    /**
     * A missing path is unreachable if the nearest existing
     * ancestor directory cannot be read or traversed.
     */
    private static function unreachable(string $path): bool
    {
        $dir = dirname($path);
        while (!file_exists($dir) && dirname($dir) !== $dir) {
            $dir = dirname($dir);
        }
        return is_dir($dir) && !(is_readable($dir) && is_executable($dir));
    }

    /** The stored path belongs to a section the scan could not read */
    private function isSkipped(string $path, array $skipped): bool
    {
        foreach ($skipped as $prefix) {
            // "f:" or "p:": the entire private or public section
            if (str_ends_with($prefix, ':') && str_starts_with($path, $prefix)) {
                return true;
            }
            if ($path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Call $fn for each file under $path (or for $path if it is a file),
     * and $skip for each unreadable directory; files inside it
     * must not be treated as deleted. A missing directory is empty.
     */
    private static function walk(string $path, callable $fn, callable $skip): void
    {
        if (is_file($path)) {
            $fn($path);
            return;
        }
        if (!is_dir($path)) {
            // Is it truly absent, or hidden by an ancestor directory that
            // cannot be traversed? In the latter case, files are not deleted.
            if (self::unreachable($path)) {
                error_log("journalQuota: folder not reachable, scan incomplete: {$path}");
                $skip($path);
            }
            return;
        }
        if (!is_readable($path) || !is_executable($path)) {
            error_log("journalQuota: folder not readable, scan incomplete: {$path}");
            $skip($path);
            return;
        }
        try {
            $directories = new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                function (SplFileInfo $item) use ($skip) {
                    if ($item->isDir() && !($item->isReadable() && $item->isExecutable())) {
                        error_log('journalQuota: folder not readable, scan incomplete: ' . $item->getPathname());
                        $skip($item->getPathname());
                        return false;
                    }
                    return true;
                }
            );
            foreach (new RecursiveIteratorIterator($directories) as $file) {
                if ($file->isFile()) {
                    $fn($file->getPathname());
                }
            }
        } catch (Throwable $e) {
            error_log("journalQuota: scan of {$path} incomplete: " . $e->getMessage());
            $skip($path);
        }
    }

    private static function bytes(string $path): ?int
    {
        $bytes = @filesize($path);
        return $bytes === false ? null : (int) $bytes;
    }

    /** Direct contents of a directory; empty if missing or unreadable */
    private static function entries(string $dir): array
    {
        try {
            return is_dir($dir) && is_readable($dir) ? iterator_to_array(new FilesystemIterator($dir), false) : [];
        } catch (Throwable) {
            return [];
        }
    }
}
