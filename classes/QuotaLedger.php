<?php

/**
 * @file plugins/generic/journalQuota/classes/QuotaLedger.php
 *
 * @class QuotaLedger
 *
 * @brief Registro dei file contati (tabella journal_quota_files), una riga per
 *  file, con chiave il suo percorso.
 *
 *  Perché non ci sono corse tra caricamenti, cancellazioni e misure:
 *  - contare due volte lo stesso file (copia in un'altra fase, hook e misura
 *    completa insieme) aggiorna la stessa riga, non somma di nuovo;
 *  - ogni riga ricorda quando il file è stato visto (seen_at) o cancellato
 *    (deleted_at). Una misura sul disco iniziata nel momento S scrive solo le
 *    righe che nessuno ha toccato dopo S: se un file viene caricato o
 *    cancellato mentre la misura è in corso, vince l'hook, che è più recente;
 *  - ogni scrittura è una singola istruzione SQL condizionata, quindi non
 *    servono blocchi e nessun processo resta in attesa di un altro.
 *  Le righe cancellate restano TOMBSTONE_DAYS giorni, poi si eliminano.
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
    /** Parti senza hook di OJS, rimisurate dopo ogni modifica di un utente */
    public const SMALL_PARTS = ['issues', 'library', 'other', 'public'];

    /** Cartelle della rivista in files_dir che non contano nella quota */
    public const EXCLUDED_DIRS = ['pln'];

    /** Giorni per cui una riga cancellata resta come segno */
    public const TOMBSTONE_DAYS = 2;

    private static bool $schemaChecked = false;

    public function __construct(private JournalQuotaPlugin $plugin)
    {
        $this->ensureSchema();
    }

    /** Ora con la precisione salvata nella tabella */
    public static function now(): float
    {
        return round(microtime(true), 4);
    }

    /**
     * Crea la tabella se manca: l'installazione del plugin la crea, ma un
     * aggiornamento caricato via FTP o dalla galleria potrebbe non farlo
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
                // Due richieste insieme: l'altra l'ha appena creata
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
     * Riga di un file di files_dir, dal percorso relativo, o null se il file
     * non appartiene a nessuna rivista o è in una cartella esclusa
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
     * Un file di files_dir è stato caricato: lo conta subito (o aggiorna la sua
     * riga, se era già contato). Restituisce la rivista, o null se non conta.
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
     * Un file di files_dir potrebbe essere stato cancellato: se non è più sul
     * disco lascia il segno di cancellazione. Restituisce la rivista, o null.
     */
    public function removed(string $relative): ?int
    {
        $row = self::classify($relative);
        if (!$row || file_exists(self::filesDir() . '/' . ltrim($relative, '/'))) {
            return null;
        }
        $now = self::now();
        // Anche se la riga non c'era: il segno impedisce a una misura in corso di contarlo
        DB::table(self::TABLE)->upsert(
            [$row + ['seen_at' => $now, 'deleted_at' => $now]],
            ['path_hash'],
            ['deleted_at']
        );
        return $row['context_id'];
    }

    /** Righe di una submission i cui file non sono più sul disco (submission cancellata) */
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

    /** Rivista cancellata: le sue righe non servono più */
    public function dropContext(int $contextId): void
    {
        DB::table(self::TABLE)->where('context_id', $contextId)->delete();
    }

    /** Byte per parte, solo dei file presenti */
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

    /** Le submission che occupano più spazio: id => byte */
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
     * Misura sul disco le parti indicate e allinea il registro. $since è
     * l'ora d'inizio della misura: le righe toccate dopo non si modificano.
     */
    public function sync(Context $context, array $parts): void
    {
        $since = self::now();
        $skipped = [];
        $found = $this->scan($context, $parts, $skipped);
        $this->reconcile($context->getId(), $parts, $found, $since, $skipped);
    }

    /** Elimina i segni di cancellazione vecchi */
    public function purge(int $contextId): void
    {
        DB::table(self::TABLE)
            ->where('context_id', $contextId)
            ->where('deleted_at', '<', self::now() - self::TOMBSTONE_DAYS * 86400)
            ->delete();
    }

    /**
     * Allinea il registro a ciò che la misura ha trovato.
     *
     * @param array $found path_hash => riga, per i file trovati sul disco
     * @param array $skipped inizi dei percorsi che la misura non ha potuto
     *  leggere (cartelle illeggibili, file senza dimensione): i loro file non
     *  si danno per cancellati
     */
    public function reconcile(int $contextId, array $parts, array $found, float $since, array $skipped = []): void
    {
        $existing = DB::table(self::TABLE)
            ->where('context_id', $contextId)
            ->whereIn('part', $parts)
            ->get(['path_hash', 'path', 'submission_id', 'bytes', 'seen_at', 'deleted_at'])
            ->keyBy('path_hash');
        // Solo le righe che nessuno ha toccato dopo l'inizio della misura
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
        // Se un hook ha appena inserito la stessa riga, la sua vince
        foreach (array_chunk($insert, 500, true) as $chunk) {
            DB::table(self::TABLE)->insertOrIgnore(array_values($chunk));
            // Se invece l'ha inserita una misura partita prima (insieme a
            // questa), la sua dimensione è più vecchia: vale la nostra
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
     * File presenti sul disco nelle parti indicate.
     *
     * @param array $skipped riceve gli inizi dei percorsi non letti (vedi reconcile)
     *
     * @return array path_hash => riga
     */
    public function scan(Context $context, array $parts, array &$skipped = []): array
    {
        $id = $context->getId();
        $filesDir = self::filesDir();
        $journalDir = "{$filesDir}/journals/{$id}";
        $found = [];
        $privateKey = fn (string $path) => 'f:' . substr($path, strlen($filesDir) + 1);
        // Cartella dei file non montata o non leggibile: nessun file è cancellato
        if (!is_dir($filesDir) || !is_readable($filesDir) || !is_executable($filesDir)) {
            error_log("journalQuota: files_dir non raggiungibile, misura saltata: {$filesDir}");
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
     * Un percorso che non risulta esistere è irraggiungibile se la prima
     * cartella esistente sopra di lui non si può leggere o attraversare
     */
    private static function unreachable(string $path): bool
    {
        $dir = dirname($path);
        while (!file_exists($dir) && dirname($dir) !== $dir) {
            $dir = dirname($dir);
        }
        return is_dir($dir) && !(is_readable($dir) && is_executable($dir));
    }

    /** Il percorso registrato è sotto una parte che la misura non ha letto */
    private function isSkipped(string $path, array $skipped): bool
    {
        foreach ($skipped as $prefix) {
            // "f:" o "p:": tutta la parte privata o pubblica
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
     * Chiama $fn per ogni file sotto $path (o per $path, se è un file) e
     * $skip per ogni cartella che non si è potuta leggere: i file che contiene
     * non si devono dare per cancellati. Una cartella che non esiste è vuota.
     */
    private static function walk(string $path, callable $fn, callable $skip): void
    {
        if (is_file($path)) {
            $fn($path);
            return;
        }
        if (!is_dir($path)) {
            // Assente davvero, o nascosta da una cartella sopra che non si
            // può attraversare? Nel secondo caso i file non sono cancellati.
            if (self::unreachable($path)) {
                error_log("journalQuota: cartella non raggiungibile, misura incompleta: {$path}");
                $skip($path);
            }
            return;
        }
        if (!is_readable($path) || !is_executable($path)) {
            error_log("journalQuota: cartella non leggibile, misura incompleta: {$path}");
            $skip($path);
            return;
        }
        try {
            $directories = new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                function (SplFileInfo $item) use ($skip) {
                    if ($item->isDir() && !($item->isReadable() && $item->isExecutable())) {
                        error_log('journalQuota: cartella non leggibile, misura incompleta: ' . $item->getPathname());
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
            error_log("journalQuota: misura di {$path} incompleta: " . $e->getMessage());
            $skip($path);
        }
    }

    private static function bytes(string $path): ?int
    {
        $bytes = @filesize($path);
        return $bytes === false ? null : (int) $bytes;
    }

    /** Contenuto diretto di una cartella; vuoto se non esiste o non è leggibile */
    private static function entries(string $dir): array
    {
        try {
            return is_dir($dir) && is_readable($dir) ? iterator_to_array(new FilesystemIterator($dir), false) : [];
        } catch (Throwable) {
            return [];
        }
    }
}
