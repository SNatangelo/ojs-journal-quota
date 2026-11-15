<?php

/**
 * @file plugins/generic/journalQuota/classes/QuotaService.php
 *
 * @class QuotaService
 *
 * @brief Misura lo spazio occupato da una rivista, calcola il livello rispetto
 *  alla quota e invia avvisi (email e notifiche di OJS) ai journal manager.
 *
 *  OJS non registra la dimensione dei file, quindi il plugin tiene un registro
 *  con una riga per file (QuotaLedger) e il totale è la loro somma:
 *  - la prima volta, e poi ogni scanHours ore, una misura completa sul disco
 *    allinea il registro;
 *  - in mezzo, ogni file di submission caricato o cancellato aggiorna subito
 *    la sua riga, e le parti piccole (fascicoli, biblioteca, pubblici) si
 *    rimisurano dopo ogni modifica fatta da un utente (refreshSmallParts).
 *  Le pagine leggono solo il registro e non scandiscono mai il disco.
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

    /** Blocco appena si raggiunge la quota */
    public const MODE_BLOCK = 'block';
    /** Avviso alla quota, blocco oltre la tolleranza */
    public const MODE_GRACE = 'grace';

    /** Durata massima di una prenotazione di spazio per un caricamento */
    public const RESERVATION_SECONDS = 3600;

    /** Margine sotto una soglia prima che l'avviso scenda di livello (frazione della quota, minimo 1 MB) */
    public const RESERVE_ATTEMPTS = 5;
    public const HYSTERESIS = 0.02;

    /** Limiti dei valori: 1 PB di quota, tolleranza al massimo 10 volte la quota */
    public const MAX_QUOTA_MB = 1000000000;
    public const MAX_TOLERANCE_PCT = 1000;

    /** Ore tra una misura completa del disco e la successiva (predefinito) */
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

    /** Parti dello spazio, nell'ordine in cui si mostrano, con il loro colore */
    public const PART_COLORS = [
        'articles' => '#1e6fa8',
        'issues' => '#7b4fc9',
        'library' => '#2e8b57',
        'other' => '#8a8a8a',
        'public' => '#e08a00',
        'database' => '#00838f',
    ];

    /** Minuti minimi tra due stime del database */
    public const DATABASE_MINUTES = 10;

    /** Stato già calcolato in questa richiesta, per rivista */
    private array $statusCache = [];

    /** Email consegnate al server di posta in questo processo (evento MessageSent) */
    private int $delivered = 0;

    private bool $listening = false;

    private ?QuotaLedger $ledger = null;

    /** Cartella dei blocchi e delle prenotazioni (vedi stateDir) */
    private ?string $stateDir = null;

    public function __construct(private JournalQuotaPlugin $plugin)
    {
    }

    /**
     * Misura (se richiesto) e allinea avvisi ed email, una rivista alla volta.
     *
     * L'attività pianificata, "Misura di nuovo" e il salvataggio delle
     * impostazioni possono partire insieme: senza blocco due processi
     * manderebbero le stesse email e creerebbero notifiche doppie. Il blocco è
     * un flock su un file in files_dir, rilasciato dal sistema anche se il
     * processo si interrompe. Se un altro processo sta già controllando la
     * rivista, si salta e si restituisce null.
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
            // Impostazioni rilette dentro il blocco: valgono quelle appena salvate
            $status = $this->status($context);
            $this->processAlerts($context, $status);
            return $status;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Prenota lo spazio di un caricamento. Prenotazioni e blocco stanno nel
     * database (tabella journal_quota_reservations), non su file: non
     * dipendono dai permessi delle cartelle. Dentro una transazione si blocca
     * la riga della rivista, poi si leggono lo spazio usato e le prenotazioni
     * degli altri caricamenti in corso: due caricamenti nello stesso istante
     * non superano insieme il limite.
     *
     * @return array ['token' => codice da liberare ('' senza limite) o null se
     *  rifiutato, 'status' => stato della rivista, 'pending' => byte prenotati da altri]
     */
    public function reserveUpload(Context $context, int $bytes): array
    {
        $id = $context->getId();
        $this->ledger();
        // Prima della prima misura lo spazio usato risulta 0: una rivista
        // nuova accetterebbe file su file fino al controllo orario. Si misura
        // adesso, una volta sola (fuori dalla transazione: può durare).
        if (!$this->hasUsage($context) && $this->settings($id)['quotaMb'] > 0) {
            $this->measureOnce($context);
        }
        $table = QuotaSchemaMigration::RESERVATIONS;
        // Riga di blocco della rivista (0 byte, non scade mai), creata in una
        // istruzione a sé: dentro la transazione il primo accesso è già il
        // blocco esclusivo, senza passare da un blocco condiviso (due richieste
        // che convertono insieme un blocco condiviso vanno in deadlock)
        DB::table($table)->insertOrIgnore(['token' => "lock-{$id}", 'context_id' => $id, 'bytes' => 0, 'expires' => PHP_INT_MAX]);
        try {
            // Se il database interrompe comunque una transazione (deadlock o
            // attesa troppo lunga) la si ripete, fino a RESERVE_ATTEMPTS volte
            return DB::transaction(fn () => $this->reserveLocked($context, $id, $bytes), self::RESERVE_ATTEMPTS);
        } catch (QueryException $e) {
            if (!self::isConcurrencyError($e)) {
                throw $e;
            }
            // Troppi caricamenti insieme: il caricamento si rifiuta (si può
            // riprovare subito), non passa senza controllo
            return ['token' => null, 'busy' => true, 'status' => $this->status($context), 'pending' => 0];
        }
    }

    private function reserveLocked(Context $context, int $id, int $bytes): array
    {
        $table = QuotaSchemaMigration::RESERVATIONS;
        if (!DB::table($table)->where('token', "lock-{$id}")->lockForUpdate()->first()) {
            // Riga cancellata a mano tra le due istruzioni: si ricrea (e si
            // riparte, così il blocco è preso sulla riga vera)
            DB::table($table)->insertOrIgnore(['token' => "lock-{$id}", 'context_id' => $id, 'bytes' => 0, 'expires' => PHP_INT_MAX]);
            DB::table($table)->where('token', "lock-{$id}")->lockForUpdate()->first();
        }

        // Lo spazio usato si rilegge sotto blocco: un caricamento appena
        // concluso ha già la sua riga nel registro
        $this->forgetStatus($id);
        $status = $this->status($context);
        if ($status['quota'] <= 0) {
            return ['token' => '', 'status' => $status, 'pending' => 0];
        }
        // Le prenotazioni di richieste interrotte scadono da sole
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

    /** Deadlock o attesa del blocco scaduta (MySQL/MariaDB e PostgreSQL) */
    public static function isConcurrencyError(Throwable $e): bool
    {
        $info = $e instanceof QueryException ? ($e->errorInfo ?? []) : [];
        return in_array((int) ($info[1] ?? 0), [1213, 1205], true)
            || in_array((string) ($info[0] ?? $e->getCode()), ['40001', '40P01', '55P03'], true)
            || str_contains($e->getMessage(), 'Deadlock')
            || str_contains($e->getMessage(), 'Lock wait timeout');
    }

    /**
     * Prima misura di una rivista, aspettando un'eventuale misura già in
     * corso (che basta, se finisce bene)
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

    /** A fine richiesta: i file salvati sono ormai nel registro */
    public function releaseUpload(int $contextId, string $token): void
    {
        DB::table(QuotaSchemaMigration::RESERVATIONS)->where('token', $token)->delete();
    }

    /**
     * Registro dei file contati. Avvisi ed email restano al controllo orario,
     * per non rallentare il caricamento di chi sta lavorando.
     */
    public function ledger(): QuotaLedger
    {
        return $this->ledger ??= new QuotaLedger($this->plugin);
    }

    /** Un file di files_dir è stato caricato: conta subito */
    public function fileStored(string $relative): void
    {
        $this->forgetStatus($this->ledger()->stored($relative));
    }

    /** Un file di files_dir potrebbe essere stato cancellato */
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
     * Blocco del controllo di una rivista, o null se lo tiene un altro processo
     *
     * @return resource|null
     */
    /**
     * Cartella dei blocchi dei controlli (files_dir/journalQuota). Servono
     * solo a non mandare due volte le stesse email: se manca, il controllo
     * funziona lo stesso. Le prenotazioni dei caricamenti sono nel database.
     */
    private function stateDir(): string
    {
        if ($this->stateDir !== null) {
            return $this->stateDir;
        }
        $dir = rtrim(Config::getVar('files', 'files_dir'), '/') . '/journalQuota';
        if (!is_dir($dir) && @mkdir($dir, 0775, true)) {
            // Come per i file: web e riga di comando possono essere utenti diversi
            @chmod($dir, 0777);
        }
        return $this->stateDir = $dir;
    }

    /**
     * Ricorda l'ultimo problema (al massimo una scrittura l'ora per lo stesso
     * messaggio), per mostrarlo nella pagina dell'amministratore
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
            // Il database stesso non risponde: resta il log
        }
    }

    /** Ultimo problema degli ultimi 7 giorni, o null */
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
            // Il controllo può partire dal web o dalla riga di comando, con utenti diversi
            @chmod($file, 0666);
        }
        if (!$handle) {
            // Senza file di blocco si procede comunque: meglio un'email doppia che nessun avviso
            $this->reportProblem("impossibile creare il file di blocco in {$dir}");
            return fopen('php://memory', 'r');
        }
        if (!flock($handle, $wait ? LOCK_EX : LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    /**
     * Impostazioni in vigore per la rivista: le sue, se ha una quota
     * personalizzata, altrimenti i valori predefiniti del sito
     */
    public function settings(int $contextId): array
    {
        return $this->isCustom($contextId) ? $this->ownSettings($contextId) : $this->defaults();
    }

    /** Valori predefiniti, validi per tutte le riviste senza quota personalizzata */
    public function defaults(): array
    {
        return $this->read(Application::SITE_CONTEXT_ID, self::DEFAULTS);
    }

    /** Valori salvati per la rivista, anche quando non sono in vigore */
    public function ownSettings(int $contextId): array
    {
        return $this->read($contextId, $this->defaults());
    }

    public function isCustom(int $contextId): bool
    {
        return (bool) $this->plugin->getSetting($contextId, 'custom');
    }

    /** Salva i valori predefiniti del sito */
    public function saveDefaults(array $settings): void
    {
        $this->write(Application::SITE_CONTEXT_ID, $settings);
        $this->statusCache = [];
    }

    /**
     * Salva le impostazioni della rivista. Senza quota personalizzata i suoi
     * valori restano salvati ma non valgono, così si possono riattivare.
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
        // Valori enormi darebbero limiti fuori dagli interi
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
     * Spazio occupato. Senza $refresh usa sempre l'ultima misura salvata, anche
     * se vecchia: le pagine non devono mai scandire il disco. Si misura solo
     * dall'attività pianificata, al salvataggio delle impostazioni e con
     * "Misura di nuovo". Prima della prima misura risulta 0 (mai bloccata).
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
            // Tabelle che pesano di più nella stima del database: tabella => byte
            'dbTables' => (array) ($this->plugin->getSetting($id, 'dbTables') ?: []),
        ];
    }

    /**
     * Stima dello spazio di ogni rivista nel database. È periodica, non segue
     * i caricamenti: si rifà con la misura completa (ogni scanHours ore o con
     * "Misura di nuovo"), una sola volta per tutte le riviste e al massimo
     * ogni DATABASE_MINUTES minuti.
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
     * Ripartizione dello spazio per parte, per pagine e grafici
     *
     * @return array elenco di ['key', 'label', 'bytes', 'size', 'counted', 'color', 'percent']
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

    /** Ora dell'ultima misura completa (0 se non è mai stata fatta) */
    private function scannedAt(Context $context): int
    {
        return (int) $this->plugin->getSetting($context->getId(), 'scannedAt');
    }

    public function hasUsage(Context $context): bool
    {
        return $this->scannedAt($context) > 0;
    }

    /** Le submission che occupano più spazio: id => byte */
    public function top(Context $context): array
    {
        return $this->hasUsage($context) ? $this->ledger()->top($context->getId()) : [];
    }

    /**
     * Chiede una misura completa di tutte le riviste al prossimo controllo
     * orario (per esempio alla riattivazione: da disattivato il plugin non ha
     * visto caricamenti e cancellazioni)
     */
    public function requestFullScan(): void
    {
        $this->plugin->updateSetting(Application::SITE_CONTEXT_ID, 'scanRequestedAt', time(), 'int');
    }

    /** Ogni quante ore l'attività pianificata rifà la misura completa */
    public function scanHours(): int
    {
        $hours = (int) $this->plugin->getSetting(Application::SITE_CONTEXT_ID, 'scanHours');
        return $hours > 0 ? $hours : self::SCAN_HOURS;
    }

    public function saveScanHours(int $hours): void
    {
        $this->plugin->updateSetting(Application::SITE_CONTEXT_ID, 'scanHours', max(1, $hours), 'int');
    }

    /** È ora di una misura completa (con 10 minuti di margine sull'attività oraria) */
    public function fullScanDue(Context $context): bool
    {
        $scannedAt = $this->scannedAt($context);
        return !$scannedAt
            || $scannedAt < (int) $this->plugin->getSetting(Application::SITE_CONTEXT_ID, 'scanRequestedAt')
            || time() - $scannedAt >= $this->scanHours() * 3600 - 600;
    }

    /**
     * Rimisura solo le parti piccole (fascicoli, biblioteca, altro, file
     * pubblici); i file delle submission si contano file per file dagli hook.
     * OJS non avvisa i plugin quando carica questi file, quindi si rimisurano
     * dopo ogni modifica fatta da un utente.
     */
    public function refreshSmallParts(Context $context): void
    {
        if ($this->hasUsage($context)) {
            $this->ledger()->sync($context, QuotaLedger::SMALL_PARTS);
            $this->forgetStatus($context->getId());
        }
    }

    /**
     * Misura completa sul disco dei file privati (files_dir) e pubblici della
     * rivista. Si chiama da check(), quindi mai due insieme per la stessa rivista.
     */
    private function measure(Context $context): void
    {
        // Una misura interrotta a metà non salva scannedAt e si ripeterebbe
        // per sempre: su riviste grandi può superare max_execution_time
        @set_time_limit(0);
        $startedAt = time();
        $ledger = $this->ledger();
        $ledger->sync($context, QuotaLedger::PARTS);
        $ledger->purge($context->getId());
        try {
            $this->measureDatabase();
        } catch (Throwable $e) {
            error_log('journalQuota: stima del database non riuscita: ' . $e->getMessage());
        }
        $this->plugin->updateSetting($context->getId(), 'scannedAt', $startedAt, 'int');
        $this->forgetStatus($context->getId());
    }

    /**
     * Stato della rivista rispetto alla quota.
     *
     * @param bool $refresh true per rimisurare comunque lo spazio
     */
    public function status(Context $context, bool $refresh = false): array
    {
        // Senza nuova misura lo stato non cambia nella stessa richiesta: le
        // pagine lo chiedono più volte (blocco, intestazione, notifiche)
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
     * Allinea notifiche ed email al livello attuale. Si chiama solo da check(),
     * dentro il blocco della rivista.
     *
     * notifiedLevel è il livello più alto già comunicato per email. Un'email
     * parte quando il livello supera notifiedLevel (e si ritenta finché non
     * parte), oppure come promemoria ogni reminderDays giorni. Se il livello
     * scende, notifiedLevel scende con lui solo 24 ore dopo l'ultima email:
     * così, se l'uso oscilla intorno a una soglia, non arriva un'email a ogni
     * passaggio.
     */
    private function processAlerts(Context $context, array $status): void
    {
        $id = $context->getId();
        // Con "blocca subito" la chiusura avviene alla quota: per avvisi ed
        // email conta come il livello più alto
        $level = $status['blocked'] ? self::LEVEL_TOLERANCE : $status['level'];
        $lastLevel = (int) $this->plugin->getSetting($id, 'lastLevel');
        // Isteresi: lo spazio che oscilla intorno a una soglia (la stima del
        // database cambia di poco a ogni misura) non deve togliere e ricreare
        // gli avvisi ogni ora. Si scende di livello solo sotto la soglia meno
        // un margine. Blocco e caricamenti seguono sempre lo stato reale.
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

        // Campanella: al cambio di livello si ricreano (tornano "non lette");
        // altrimenti si aggiungono ai nuovi manager e si tolgono agli ex manager
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
            // Con "chiudi subito" la quota è anche la chiusura: basta una delle due spunte
            self::LEVEL_TOLERANCE => $settings['notifyTolerance']
                || ($settings['mode'] === self::MODE_BLOCK && $settings['notifyQuota']),
            default => false,
        };
        $risen = $level > $notifiedLevel;
        $reminderDue = $level > self::LEVEL_OK
            && $settings['reminderDays'] > 0
            && time() - $lastNotifiedAt >= $settings['reminderDays'] * 86400;

        // Manager a cui l'ultima email non è arrivata: si ritenta solo con loro
        $pending = array_map('intval', (array) ($this->plugin->getSetting($id, 'emailPending') ?: []));
        if ($emailEnabled && ($risen || $reminderDue)) {
            $result = $this->sendEmails($context, $status);
            if ($result['sent'] > 0) {
                $this->plugin->updateSetting($id, 'lastNotifiedAt', time(), 'int');
                $this->plugin->updateSetting($id, 'notifiedLevel', max($level, $notifiedLevel), 'int');
            }
            // Se non è arrivata a nessuno, si ritenta comunque con tutti
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

    /** Toglie tutti gli avvisi del plugin (per esempio quando viene disattivato) */
    public function clearAlerts(): void
    {
        Notification::withType(JournalQuotaPlugin::NOTIFICATION_TYPE)->delete();
        foreach (Application::getContextDAO()->getAll()->toArray() as $context) {
            $this->plugin->updateSetting($context->getId(), 'lastLevel', self::LEVEL_OK, 'int');
        }
    }

    /**
     * Una notifica (campanella di OJS) per ogni manager attuale, solo per il
     * livello attuale. Con $keep restano quelle dei manager che già l'hanno.
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
     * Invia l'email ai manager (o solo a quelli in $only)
     *
     * @return array ['sent' => quante sono partite, 'failed' => id dei manager non raggiunti]
     */
    private function sendEmails(Context $context, array $status, ?array $only = null): array
    {
        $locale = $this->locale($context);
        $template = Repo::emailTemplate()->getByKey($context->getId(), QuotaNotify::getEmailTemplateKey());
        // Se il modello non è installato (plugin copiato via FTP) si usano i testi predefiniti
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
        // Destinatari: i journal manager della rivista (chiave = id utente)
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
                // OJS registra gli errori SMTP nel log senza sollevare eccezioni e
                // Mail::send() non restituisce nulla: l'email è partita solo se
                // Laravel ha emesso l'evento MessageSent
                $before = $this->delivered;
                Mail::send($mailable);
                if ($this->delivered > $before) {
                    $sent++;
                    array_pop($failed);
                } else {
                    error_log('journalQuota: invio email a ' . $user->getEmail() . ' non riuscito');
                }
            } catch (Throwable $e) {
                error_log('journalQuota: invio email a ' . $user->getEmail() . ' non riuscito: ' . $e->getMessage());
            }
        }
        return ['sent' => $sent, 'failed' => $failed];
    }

    /** Lingua dei testi per la rivista: la primaria, se il plugin la traduce, altrimenti l'inglese */
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

    /** Frase che spiega il livello attuale e cosa succede alle submission */
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

    /** Journal manager attivi della rivista */
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
