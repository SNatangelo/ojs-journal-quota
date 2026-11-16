<?php
/**
 * Test automatici di journalQuota da riga di comando (solo copia locale).
 *
 * Si esegue nel container, come www-data, tramite run.sh:
 *   php cli.php test [gruppo]      tutti i test (o solo un gruppo)
 *   php cli.php snapshot|restore   salva / rimette lo stato del plugin
 *   php cli.php <aiuto> ...        comandi usati da http.sh
 *
 * Nessuna email parte davvero: le email dei test vanno al mailer "array"
 * di Laravel, che le tiene in memoria.
 */

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\journalQuota\classes\QuotaLedger;
use APP\plugins\generic\journalQuota\classes\QuotaSchemaMigration;
use APP\plugins\generic\journalQuota\classes\QuotaService;
use Firebase\JWT\JWT;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use PKP\cliTool\CommandLineTool;
use PKP\config\Config;
use PKP\notification\Notification;
use PKP\plugins\Hook;
use PKP\plugins\PluginRegistry;
use PKP\submissionFile\SubmissionFile;

require('/var/www/html/tools/bootstrap.php');

const MB = 1048576;
const STATE = '/tmp/jqtests/state.json';

class JqTests extends CommandLineTool
{
    private $p;
    private int $pass = 0;
    private int $fail = 0;
    private array $failures = [];
    /** Rivista con submission per i test sui file (ECAIR) */
    private int $filesCtx;
    /** Rivista per avvisi e prenotazioni (spazio finto) */
    private int $alertCtx;
    /** Rivista per i test sulle cartelle (crecer) */
    private int $dirCtx;

    public function execute()
    {
        $this->p = PluginRegistry::loadPlugin('generic', 'journalQuota');
        $this->filesCtx = (int) (getenv('JQ_FILES_CTX') ?: 3);
        $this->alertCtx = (int) (getenv('JQ_ALERT_CTX') ?: 3);
        $this->dirCtx = (int) (getenv('JQ_DIR_CTX') ?: 4);
        $cmd = $this->argv[0] ?? 'test';
        $args = array_slice($this->argv, 1);
        switch ($cmd) {
            case 'test':
                // Per sicurezza tutte le email dei test vanno al mailer finto
                $this->useMailer('array');
                $this->runTests($args[0] ?? null);
                break;
            case 'snapshot':
                $this->snapshot();
                break;
            case 'restore':
                $this->restore();
                break;
            case 'worker':
                $this->worker((float) $args[0], (int) $args[1], (int) $args[2]);
                break;
            case 'set-quota': // set-quota <ctx> <MB> <mode>
                $s = $this->svc();
                $s->saveSettings((int) $args[0], ['quotaMb' => (int) $args[1], 'mode' => $args[2]] + $s->settings((int) $args[0]), true);
                $st = $this->svc()->status($this->ctx((int) $args[0]));
                printf("%d %d %d\n", $st['used'], $st['blockLimit'], $st['blocked'] ? 1 : 0);
                break;
            case 'age-scan': // age-scan <ctx>: ultima misura di 2 minuti fa (il pulsante vale una volta al minuto)
                $this->p->updateSetting((int) $args[0], 'scannedAt', time() - 120, 'int');
                echo time() - 120, "\n";
                break;
            case 'get-setting': // get-setting <ctx|site> <nome>
                $id = $args[0] === 'site' ? Application::SITE_CONTEXT_ID : (int) $args[0];
                echo json_encode($this->p->getSetting($id, $args[1])), "\n";
                break;
            case 'tempfile': // tempfile <userId> <byte>: file temporaneo dell'utente, stampa l'id
                echo $this->tempFile((int) $args[0], (int) $args[1]), "\n";
                break;
            case 'tempfile-clean':
                $this->tempFileClean();
                break;
            case 'tokens': // tokens set|clear <userId>...
                $this->tokens($args[0], array_map('intval', array_slice($args, 1)));
                break;
            case 'xss': // xss set|restore <ctx>
                $this->xss($args[0], (int) $args[1]);
                break;
            case 'mail-driver':
                echo Config::getVar('email', 'default'), "\n";
                break;
            default:
                fwrite(STDERR, "comando sconosciuto: {$cmd}\n");
                exit(2);
        }
    }

    // ------------------------------------------------------------------
    // Piccolo motore di test

    private function ok(bool $cond, string $name, string $detail = ''): void
    {
        if ($cond) {
            $this->pass++;
            echo "  ok    {$name}\n";
        } else {
            $this->fail++;
            $this->failures[] = $name;
            echo "  FALLITO {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
        }
    }

    private function eq($expected, $actual, string $name): void
    {
        $this->ok($expected === $actual, $name, 'atteso ' . json_encode($expected) . ', ottenuto ' . json_encode($actual));
    }

    private function near(float $expected, float $actual, string $name, float $tolerance = 1024): void
    {
        $this->ok(abs($expected - $actual) <= $tolerance, $name,
            sprintf('atteso %.0f, ottenuto %.0f (differenza %.0f byte)', $expected, $actual, $actual - $expected));
    }

    private function group(string $title, callable $fn): void
    {
        echo "\n## {$title}\n";
        try {
            $fn();
        } catch (Throwable $e) {
            $this->ok(false, "{$title}: eccezione", $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        }
    }

    private function runTests(?string $only): void
    {
        $groups = [
            'schema' => ['Tabelle del plugin', fn () => $this->testSchema()],
            'misura' => ['Misura confrontata con il disco', fn () => $this->testMeasureVsDisk()],
            'file' => ['File delle submission: caricamento, copia, revisione, cancellazione', fn () => $this->testSubmissionFiles()],
            'scansioni' => ['Misure in parallelo con file che cambiano', fn () => $this->testScanRace()],
            'registro' => ['Registro: submission cancellata, pulizia, riattivazione, percorsi', fn () => $this->testLedgerMisc()],
            'cartelle' => ['Cartelle illeggibili o non attraversabili', fn () => $this->testDirectories()],
            'prenotazioni' => ['Prenotazioni dello spazio', fn () => $this->testReservations()],
            'concorrenza' => ['Caricamenti simultanei (processi paralleli)', fn () => $this->testConcurrency()],
            'avvisi' => ['Avvisi, campanella ed email', fn () => $this->testAlerts()],
            'blocco' => ['Chiusura delle submission e blocco dei controlli', fn () => $this->testBlocking()],
            'problemi' => ['Avviso dei problemi all\'amministratore', fn () => $this->testProblems()],
        ];
        foreach ($groups as $key => [$title, $fn]) {
            if ($only === null || $only === $key) {
                $this->group($title, $fn);
            }
        }
        echo "\nCLI: {$this->pass} superati, {$this->fail} falliti\n";
        foreach ($this->failures as $f) {
            echo "  - {$f}\n";
        }
        exit($this->fail ? 1 : 0);
    }

    // ------------------------------------------------------------------
    // Aiuti

    /** Servizio nuovo, come in una nuova richiesta */
    private function svc(): QuotaService
    {
        Cache::flush();
        // Anche il servizio interno del plugin (usato dagli hook) riparte da zero
        $prop = new ReflectionProperty($this->p, 'service');
        $prop->setValue($this->p, null);
        return new QuotaService($this->p);
    }

    private function ctx(int $id)
    {
        return Application::getContextDAO()->getById($id);
    }

    private function totals(int $id): array
    {
        Cache::flush();
        return $this->svc()->ledger()->totals($id);
    }

    /** Tutto lo spazio della rivista in una riga finta del registro */
    private function fakeUsage(int $id, int $bytes): void
    {
        // Solo la riga finta: niente parte pubblica né database
        $this->setSettings($id, ['countPublic' => false, 'countDatabase' => false]);
        DB::table(QuotaLedger::TABLE)->where('context_id', $id)->delete();
        DB::table(QuotaLedger::TABLE)->insert(['context_id' => $id, 'path_hash' => sha1('jqtest-fake'),
            'path' => 'jqtest-fake', 'part' => 'other', 'bytes' => $bytes, 'seen_at' => QuotaLedger::now()]);
        $this->p->updateSetting($id, 'scannedAt', time(), 'int');
        Cache::flush();
    }

    private function setSettings(int $id, array $values): void
    {
        $s = $this->svc();
        $s->saveSettings($id, $values + $s->settings($id), true);
    }

    private function clearAlertState(int $id): void
    {
        foreach (['lastLevel', 'notifiedLevel', 'lastNotifiedAt', 'emailPending'] as $k) {
            \PKP\db\DAORegistry::getDAO('PluginSettingsDAO')->deleteSetting($id, $this->p->getName(), $k);
        }
        Notification::withType($this->p::NOTIFICATION_TYPE)->withContextId($id)->delete();
        Cache::flush();
    }

    private function clearReservations(int $id): void
    {
        DB::table(QuotaSchemaMigration::RESERVATIONS)->where('context_id', $id)->where('token', '!=', "lock-{$id}")->delete();
    }

    /** Mailer finto ("array" di Laravel): le email non escono, ma risultano consegnate */
    private function useMailer(string $kind): void
    {
        $cfg = app('config');
        if ($kind === 'array') {
            $cfg->set('mail.mailers.array', ['transport' => 'array']);
            $cfg->set('mail.default', 'array');
        } else {
            // Server SMTP irraggiungibile: porta chiusa in locale, nessuna credenziale
            $cfg->set('mail.mailers.jqdown', ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 9,
                'encryption' => null, 'username' => null, 'password' => null, 'timeout' => 2]);
            $cfg->set('mail.default', 'jqdown');
        }
        app()->forgetInstance('mail.manager');
        app()->forgetInstance('mailer');
        Mail::clearResolvedInstances();
    }

    /** Destinatari delle email arrivate al mailer finto */
    private function sentTo(): array
    {
        $to = [];
        foreach (Mail::mailer()->getSymfonyTransport()->messages() as $m) {
            foreach ($m->getOriginalMessage()->getTo() as $a) {
                $to[] = strtolower($a->getAddress());
            }
        }
        return $to;
    }

    private function flushSent(): void
    {
        $transport = Mail::mailer()->getSymfonyTransport();
        if (method_exists($transport, 'flush')) {
            $transport->flush();
        }
    }

    private function filesDir(): string
    {
        return rtrim(Config::getVar('files', 'files_dir'), '/');
    }

    // ------------------------------------------------------------------
    // Test

    private function testSchema(): void
    {
        $this->svc()->ledger();
        $this->ok(Schema::hasTable(QuotaLedger::TABLE), 'esiste la tabella dei file (journal_quota_files)');
        $this->ok(Schema::hasTable(QuotaSchemaMigration::RESERVATIONS), 'esiste la tabella delle prenotazioni');
        $this->eq(QuotaLedger::SCHEMA_VERSION, (int) $this->p->getSetting(Application::SITE_CONTEXT_ID, 'schemaVersion'), 'versione dello schema registrata');
        $this->eq('1.0.0', (string) simplexml_load_file(dirname((new ReflectionClass($this->p))->getFileName()) . '/version.xml')->release, 'version.xml è 1.0.0');
    }

    private function testMeasureVsDisk(): void
    {
        $filesDir = $this->filesDir();
        foreach (Application::getContextDAO()->getAll(false)->toIterator() as $context) {
            $id = $context->getId();
            $this->svc()->check($context, true);
            $ledger = $this->totals($id);
            // Conto indipendente: ogni file del disco classificato con le stesse regole
            $disk = ['articles' => 0, 'issues' => 0, 'library' => 0, 'other' => 0, 'public' => 0];
            foreach (["{$filesDir}/journals/{$id}", "{$filesDir}/contexts/{$id}"] as $root) {
                if (!is_dir($root)) {
                    continue;
                }
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if ($f->isFile() && ($row = QuotaLedger::classify(substr($f->getPathname(), strlen($filesDir) + 1))) && $row['context_id'] === $id) {
                        $disk[$row['part']] += $f->getSize();
                    }
                }
            }
            $public = "/var/www/html/public/journals/{$id}";
            if (is_dir($public)) {
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($public, FilesystemIterator::SKIP_DOTS)) as $f) {
                    if ($f->isFile()) {
                        $disk['public'] += $f->getSize();
                    }
                }
            }
            foreach ($disk as $part => $bytes) {
                $this->eq($bytes, (int) ($ledger[$part] ?? 0), "{$context->getPath()}: parte {$part} uguale al disco");
            }
        }
    }

    private function testSubmissionFiles(): void
    {
        $cid = $this->filesCtx;
        $ctx = $this->ctx($cid);
        $sub = Repo::submission()->getCollector()->filterByContextIds([$cid])->getMany()->first();
        if (!$sub) {
            $this->ok(false, 'serve una submission nella rivista ' . $cid);
            return;
        }
        $s = $this->svc();
        $s->check($ctx, true);
        $base = $this->totals($cid)['articles'];
        $st = $this->svc()->status($ctx);
        $this->setSettings($cid, ['quotaMb' => (int) ceil($st['used'] / MB) + 1, 'mode' => 'block']);
        $this->eq(false, $this->svc()->status($ctx)['blocked'], 'prima del caricamento la rivista è aperta');

        $newFile = function (int $bytes, string $name) use ($sub, $cid) {
            $tmp = tempnam('/tmp', 'jq');
            file_put_contents($tmp, random_bytes($bytes));
            $id = app()->get('file')->add($tmp, "journals/{$cid}/articles/{$sub->getId()}/{$name}.pdf");
            unlink($tmp);
            return $id;
        };
        $add = fn (int $fileId, int $stage) => Repo::submissionFile()->get(Repo::submissionFile()->add(Repo::submissionFile()->newDataObject([
            'fileId' => $fileId, 'submissionId' => $sub->getId(), 'fileStage' => $stage,
            'name' => ['en' => 'journalQuota test'], 'genreId' => 1, 'uploaderUserId' => 1,
        ])));
        $delta = fn () => $this->totals($cid)['articles'] - $base;

        $f1 = $newFile(2 * MB, 'jqtest-a');
        $a = $add($f1, SubmissionFile::SUBMISSION_FILE_SUBMISSION);
        $this->eq(2 * MB, $delta(), 'caricato un file da 2 MB: +2 MB subito');
        $this->eq(true, $this->svc()->status($ctx)['blocked'], 'la rivista si chiude subito oltre la quota');
        $b = $add($f1, SubmissionFile::SUBMISSION_FILE_REVIEW_FILE);
        $this->eq(2 * MB, $delta(), 'lo stesso file copiato in revisione non conta due volte');
        $f2 = $newFile(1 * MB, 'jqtest-b');
        Repo::submissionFile()->edit($a, ['fileId' => $f2]);
        $this->eq(3 * MB, $delta(), 'nuova versione da 1 MB: +3 MB in tutto');
        Repo::submissionFile()->delete(Repo::submissionFile()->get($a->getId()));
        $this->eq(2 * MB, $delta(), 'cancellato il file: resta la prima versione usata dalla copia');
        Repo::submissionFile()->delete(Repo::submissionFile()->get($b->getId()));
        $this->eq(0, $delta(), 'cancellata la copia: si torna a zero');
        $this->svc()->check($ctx, true);
        $this->eq(0, $delta(), 'la misura completa conferma');
    }

    private function testScanRace(): void
    {
        $cid = $this->filesCtx;
        $ctx = $this->ctx($cid);
        $ledger = $this->svc()->ledger();
        $this->svc()->check($ctx, true);
        $dir = "journals/{$cid}/articles/999998";
        $abs = $this->filesDir() . "/{$dir}";
        @mkdir($abs, 0775, true);
        $mk = function (string $name, int $bytes) use ($abs, $dir) {
            file_put_contents("{$abs}/{$name}", random_bytes($bytes));
            return "{$dir}/{$name}";
        };
        try {
            $del = $mk('del.pdf', 2 * MB);
            $ledger->stored($del);
            $gone = $mk('gone.pdf', 1 * MB);
            $ledger->stored($gone);
            $base = $this->totals($cid)['articles'];

            // Misura iniziata; durante la misura: un caricamento e una cancellazione (con hook)
            $since = QuotaLedger::now();
            usleep(20000);
            $found = $ledger->scan($ctx, QuotaLedger::PARTS);
            $new = $mk('new.pdf', 3 * MB);
            $ledger->stored($new);
            unlink($this->filesDir() . "/{$del}");
            $ledger->removed($del);
            $ledger->reconcile($cid, QuotaLedger::PARTS, $found, $since);
            $this->eq(1 * MB, $this->totals($cid)['articles'] - $base, 'durante la misura: +3 MB caricati, −2 MB cancellati');

            unlink($this->filesDir() . "/{$gone}");
            $this->svc()->check($ctx, true);
            $this->eq(0, $this->totals($cid)['articles'] - $base, 'file sparito senza hook: tolto alla misura successiva');

            // Due misure delle parti piccole in parallelo, con un file aggiunto in mezzo
            $pubDir = "/var/www/html/public/journals/{$cid}";
            @mkdir($pubDir, 0775, true);
            $pub = "{$pubDir}/jqtest-race.bin";
            $basePub = $this->totals($cid)['public'];
            $sinceA = QuotaLedger::now();
            $foundA = $ledger->scan($ctx, QuotaLedger::SMALL_PARTS);
            usleep(20000);
            file_put_contents($pub, random_bytes(1 * MB));
            $ledger->sync($ctx, QuotaLedger::SMALL_PARTS);
            $ledger->reconcile($cid, QuotaLedger::SMALL_PARTS, $foundA, $sinceA);
            $this->eq(1 * MB, $this->totals($cid)['public'] - $basePub, 'la misura più vecchia non cancella il file visto da quella più nuova');
            unlink($pub);
            $ledger->sync($ctx, QuotaLedger::SMALL_PARTS);
            $this->eq(0, $this->totals($cid)['public'] - $basePub, 'tolto il file pubblico');

            unlink($this->filesDir() . "/{$new}");
            $ledger->removed($new);
        } finally {
            foreach (glob("{$abs}/*") as $f) {
                unlink($f);
            }
            @rmdir($abs);
            @unlink("/var/www/html/public/journals/{$cid}/jqtest-race.bin");
            $this->svc()->check($ctx, true);
        }
    }

    private function testLedgerMisc(): void
    {
        $cid = $this->filesCtx;
        $ctx = $this->ctx($cid);
        $key = "f:journals/{$cid}/articles/999999/x.pdf";
        $hash = sha1($key);
        DB::table(QuotaLedger::TABLE)->where('path_hash', $hash)->delete();
        DB::table(QuotaLedger::TABLE)->insert(['context_id' => $cid, 'path_hash' => $hash, 'path' => $key,
            'part' => 'articles', 'submission_id' => 999999, 'bytes' => 5 * MB, 'seen_at' => QuotaLedger::now()]);
        $before = $this->totals($cid)['articles'];
        $this->svc()->submissionRemoved(999999);
        $this->eq(-5 * MB, $this->totals($cid)['articles'] - $before, 'submission cancellata: i suoi file non contano più');
        DB::table(QuotaLedger::TABLE)->where('path_hash', $hash)->update(['deleted_at' => QuotaLedger::now() - 3 * 86400]);
        $this->svc()->ledger()->purge($cid);
        $this->eq(false, DB::table(QuotaLedger::TABLE)->where('path_hash', $hash)->exists(), 'i segni di cancellazione vecchi vengono eliminati');

        $this->svc()->check($ctx, true);
        $this->eq(false, $this->svc()->fullScanDue($ctx), 'dopo una misura completa non ne serve un\'altra');
        sleep(1); // la richiesta di misura vale per le misure dei secondi precedenti
        $this->p->setEnabled(false);
        $this->p->setEnabled(true);
        $this->eq(true, $this->svc()->fullScanDue($ctx), 'dopo disattivazione e riattivazione serve una misura completa');
        $this->svc()->check($ctx, true);
        $this->eq(false, $this->svc()->fullScanDue($ctx), 'la misura successiva la soddisfa');

        $this->eq(null, QuotaLedger::classify('journals/3/../1/x'), 'percorso con .. ignorato');
        $this->eq(null, QuotaLedger::classify('journals/3/pln/x'), 'cartella pln esclusa');
        $this->eq('library', QuotaLedger::classify('contexts/3/library/a.pdf')['part'] ?? null, 'file della biblioteca riconosciuto');
        $this->eq(12, QuotaLedger::classify('journals/3/articles/12/a.pdf')['submission_id'] ?? null, 'submission ricavata dal percorso');
        $this->eq('issues', QuotaLedger::classify('journals/3/issues/4/a.pdf')['part'] ?? null, 'file di fascicolo riconosciuto');

        // Prestazioni: solo un controllo largo, per accorgersi di un peggioramento grave
        $s = $this->svc();
        $t = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            (new QuotaService($this->p))->status($ctx);
        }
        $ms = (microtime(true) - $t) * 10;
        $this->ok($ms < 50, sprintf('lettura dello stato veloce (%.1f ms, limite 50)', $ms));
    }

    private function testDirectories(): void
    {
        $cid = $this->dirCtx;
        $ctx = $this->ctx($cid);
        $ledger = $this->svc()->ledger();
        $j = $this->filesDir() . "/journals/{$cid}";
        $dir = "{$j}/articles/999997/x";
        @mkdir($dir, 0775, true);
        $art = fn () => $this->totals($cid)['articles'];
        try {
            file_put_contents("{$dir}/a.bin", str_repeat('a', 2048));
            $ledger->sync($ctx, ['articles']);
            $base = $art() - 2048;
            $this->eq(2048, $art() - $base, 'file nuovo contato');
            chmod("{$j}/articles", 0400);
            $ledger->sync($ctx, ['articles']);
            $this->eq(2048, $art() - $base, 'cartella leggibile ma non attraversabile (0400): il file resta contato');
            chmod("{$j}/articles", 0300);
            $ledger->sync($ctx, ['articles']);
            $this->eq(2048, $art() - $base, 'cartella attraversabile ma non leggibile (0300): il file resta contato');
            chmod("{$j}/articles", 0775);
            chmod($j, 0600);
            $ledger->sync($ctx, ['articles', 'other']);
            $this->eq(2048, $art() - $base, 'cartella della rivista nascosta (0600): il file resta contato');
            chmod($j, 0775);
            unlink("{$dir}/a.bin");
            $ledger->sync($ctx, ['articles']);
            $this->eq(0, $art() - $base, 'file cancellato davvero: tolto');
        } finally {
            @chmod("{$j}/articles", 0775);
            @chmod($j, 0775);
            @unlink("{$dir}/a.bin");
            @rmdir($dir);
            @rmdir(dirname($dir));
            $this->svc()->check($ctx, true);
        }
    }

    private function testReservations(): void
    {
        $id = $this->alertCtx;
        $ctx = $this->ctx($id);
        $this->clearReservations($id);
        $this->setSettings($id, ['quotaMb' => 10, 'mode' => 'block', 'tolerancePct' => 10]);
        $this->fakeUsage($id, 1 * MB);
        $s = $this->svc();

        $a = $s->reserveUpload($ctx, 8 * MB);
        $this->ok(is_string($a['token']) && $a['token'] !== '', 'prenotazione da 8 MB accettata (1 MB usato su 10)');
        $b = $this->svc()->reserveUpload($ctx, 2 * MB);
        $this->eq(null, $b['token'], 'altri 2 MB rifiutati: lo spazio è prenotato');
        $this->eq(8 * MB, $b['pending'], 'il rifiuto conosce lo spazio prenotato da altri');
        $this->svc()->releaseUpload($id, $a['token']);
        $c = $this->svc()->reserveUpload($ctx, 2 * MB);
        $this->ok(is_string($c['token']) && $c['token'] !== '', 'liberata la prima prenotazione, i 2 MB passano');
        $this->svc()->releaseUpload($id, (string) $c['token']);

        DB::table(QuotaSchemaMigration::RESERVATIONS)->insert(['token' => 'jqtest-expired', 'context_id' => $id, 'bytes' => 9 * MB, 'expires' => time() - 10]);
        $d = $this->svc()->reserveUpload($ctx, 2 * MB);
        $this->ok(is_string($d['token']) && $d['token'] !== '', 'una prenotazione scaduta non occupa spazio');
        $this->eq(false, DB::table(QuotaSchemaMigration::RESERVATIONS)->where('token', 'jqtest-expired')->exists(), 'la prenotazione scaduta viene cancellata');
        $this->svc()->releaseUpload($id, (string) $d['token']);

        DB::table(QuotaSchemaMigration::RESERVATIONS)->insert(['token' => 'jqtest-valid', 'context_id' => $id, 'bytes' => 9 * MB, 'expires' => time() + 600]);
        $this->eq(null, $this->svc()->reserveUpload($ctx, 512 * 1024)['token'], 'una prenotazione valida di un\'altra richiesta occupa spazio');
        $this->clearReservations($id);

        $this->fakeUsage($id, 11 * MB);
        $e = $this->svc()->reserveUpload($ctx, 1);
        $this->eq(null, $e['token'], 'rivista oltre il limite: anche 1 byte rifiutato');
        $this->eq(true, $e['status']['blocked'], 'il rifiuto dice che la rivista è bloccata');

        $this->setSettings($id, ['quotaMb' => 0]);
        $this->eq('', $this->svc()->reserveUpload($ctx, 100 * MB)['token'], 'senza limite non si prenota nulla');

        $this->eq(true, QuotaService::isConcurrencyError(new QueryException('mysql', 'select 1', [], $this->pdoError('40001', 1213, 'Deadlock found'))), 'un deadlock è riconosciuto come errore di concorrenza');
        $this->eq(true, QuotaService::isConcurrencyError(new QueryException('mysql', 'select 1', [], $this->pdoError('HY000', 1205, 'Lock wait timeout exceeded'))), 'un\'attesa del blocco scaduta è riconosciuta');
        $this->eq(false, QuotaService::isConcurrencyError(new QueryException('mysql', 'select 1', [], $this->pdoError('42S02', 1146, 'Table does not exist'))), 'un altro errore del database non lo è');
        $this->clearReservations($id);
    }

    private function pdoError(string $state, int $code, string $message): PDOException
    {
        $e = new PDOException("SQLSTATE[{$state}]: {$message}");
        $e->errorInfo = [$state, $code, $message];
        return $e;
    }

    /** Un processo del test di concorrenza: aspetta l'istante comune e prenota */
    private function worker(float $at, int $id, int $bytes): void
    {
        $ctx = $this->ctx($id);
        $s = $this->svc();
        while (microtime(true) < $at) {
            usleep(200);
        }
        try {
            $r = $s->reserveUpload($ctx, $bytes);
            echo $r['token'] ? 'ACCETTATO' : (!empty($r['busy']) ? 'OCCUPATO' : 'RIFIUTATO'), "\n";
        } catch (Throwable $e) {
            echo 'ECCEZIONE ', substr($e->getMessage(), 0, 120), "\n";
        }
    }

    private function testConcurrency(): void
    {
        $id = $this->alertCtx;
        $this->setSettings($id, ['quotaMb' => 10, 'mode' => 'block']);
        $this->fakeUsage($id, 1 * MB);
        $free = 9 * MB;
        foreach ([[8, 0.6, 1, 5], [20, 0.15, 6, 3]] as [$workers, $share, $expected, $rounds]) {
            $all = [];
            for ($r = 0; $r < $rounds; $r++) {
                $this->clearReservations($id);
                $at = microtime(true) + 2.5;
                $procs = [];
                for ($i = 0; $i < $workers; $i++) {
                    $procs[] = popen(sprintf('php %s worker %F %d %d 2>&1', escapeshellarg(__FILE__), $at, $id, (int) ($free * $share)), 'r');
                }
                $results = [];
                foreach ($procs as $proc) {
                    $out = stream_get_contents($proc);
                    pclose($proc);
                    preg_match('/^(ACCETTATO|RIFIUTATO|OCCUPATO|ECCEZIONE.*)$/m', $out, $m);
                    $results[] = $m[1] ?? ('?' . trim(substr($out, -120)));
                }
                $all[] = $results;
            }
            $accepted = array_map(fn ($res) => count(array_keys($res, 'ACCETTATO')), $all);
            $errors = array_merge(...array_map(fn ($res) => array_filter($res, fn ($x) => !in_array($x, ['ACCETTATO', 'RIFIUTATO', 'OCCUPATO'])), $all));
            $this->eq(array_fill(0, $rounds, $expected), $accepted, "{$rounds} giri da {$workers} richieste simultanee: {$expected} accettate per giro");
            $this->eq([], array_values($errors), 'nessun deadlock né eccezione');
        }
        $this->clearReservations($id);
    }

    private function testAlerts(): void
    {
        $id = $this->alertCtx;
        $ctx = $this->ctx($id);
        $managers = [];
        foreach ($this->svc()->managers($ctx) as $u) {
            $managers[(int) $u->getId()] = strtolower($u->getEmail());
        }
        if (!$managers) {
            $this->ok(false, "la rivista {$id} non ha journal manager: impossibile provare le email");
            return;
        }
        $n = count($managers);
        $sorted = array_values($managers);
        sort($sorted);
        $this->useMailer('array');
        $this->clearAlertState($id);
        $this->setSettings($id, ['quotaMb' => 100, 'tolerancePct' => 10, 'warnPct' => 80, 'mode' => 'grace',
            'notifyWarn' => true, 'notifyQuota' => true, 'notifyTolerance' => true, 'reminderDays' => 7]);
        $notifs = fn () => Notification::withType($this->p::NOTIFICATION_TYPE)->withContextId($id)->count();
        $at = function (float $mb) use ($id, $ctx) {
            $this->fakeUsage($id, (int) ($mb * MB));
            $this->flushSent();
            return $this->svc()->check($ctx, false);
        };
        $level = fn () => (int) $this->p->getSetting($id, 'lastLevel');

        $at(50);
        $this->eq([0, 0, 0], [$level(), $notifs(), count($this->sentTo())], '50 MB su 100: nessun avviso');

        $at(85);
        $to = $this->sentTo();
        sort($to);
        $this->eq(1, $level(), '85 MB: livello di avviso');
        $this->eq($n, $notifs(), 'una notifica nella campanella per ogni manager');
        $this->eq($sorted, $to, 'email a tutti e soli i manager della rivista');
        $this->ok((int) $this->p->getSetting($id, 'lastNotifiedAt') > 0, 'data dell\'ultimo invio registrata');

        $at(86);
        $this->eq(0, count($this->sentTo()), 'stesso livello: nessuna email ripetuta');

        $at(79.5);
        $this->eq(1, $level(), 'appena sotto la soglia (isteresi del 2%): l\'avviso resta');
        $at(77);
        $this->eq([0, 0], [$level(), $notifs()], 'ben sotto la soglia: avviso tolto');
        $at(81);
        $this->eq(1, $level(), 'di nuovo sopra: avviso nella campanella');
        $this->eq(0, count($this->sentTo()), 'ma nessuna email entro 24 ore (oscillazione)');

        $at(50);
        $this->p->updateSetting($id, 'lastNotifiedAt', time() - 25 * 3600, 'int');
        $at(50);
        $at(85);
        $this->eq($n, count($this->sentTo()), 'dopo 24 ore sotto la soglia l\'email si riarma');

        $this->p->updateSetting($id, 'lastNotifiedAt', time() - 8 * 86400, 'int');
        $at(85);
        $this->eq($n, count($this->sentTo()), 'promemoria dopo 7 giorni allo stesso livello');

        $at(105);
        $this->eq([2, $n], [$level(), count($this->sentTo())], 'quota superata: livello 2 ed email');
        $st = $at(115);
        $this->eq([3, $n, true], [$level(), count($this->sentTo()), $st['blocked']], 'tolleranza superata: livello 3, email, rivista chiusa');

        // Server di posta irraggiungibile: nessuna email risulta inviata, si ritenta
        $this->clearAlertState($id);
        $this->useMailer('down');
        $at(85);
        $this->eq(0, (int) $this->p->getSetting($id, 'lastNotifiedAt'), 'SMTP irraggiungibile: l\'invio non risulta fatto');
        $this->eq(0, (int) $this->p->getSetting($id, 'notifiedLevel'), 'e il livello resta da comunicare a tutti i manager');
        $this->useMailer('array');
        $at(85);
        $this->eq($n, count($this->sentTo()), 'al controllo successivo l\'email parte');
        $this->eq([], (array) ($this->p->getSetting($id, 'emailPending') ?: []), 'e non resta nulla da ritentare');
        $at(85);
        $this->eq(0, count($this->sentTo()), 'una sola volta');

        // Solo il manager a cui non era arrivata
        $first = array_key_first($managers);
        $this->p->updateSetting($id, 'emailPending', [$first], 'object');
        $at(85);
        $this->eq([$managers[$first]], $this->sentTo(), 'si ritenta solo con il manager a cui non era arrivata');

        // Con "chiudi subito" basta la spunta "alla quota"
        $this->clearAlertState($id);
        $this->setSettings($id, ['mode' => 'block', 'notifyTolerance' => false, 'notifyQuota' => true]);
        $at(101);
        $this->eq($n, count($this->sentTo()), 'chiudi subito: email alla quota anche senza la spunta "alla chiusura"');

        // Email disattivate
        $this->clearAlertState($id);
        $this->setSettings($id, ['mode' => 'grace', 'notifyWarn' => false]);
        $at(85);
        $this->eq([1, $n, 0], [$level(), $notifs(), count($this->sentTo())], 'email di avviso disattivata: solo la campanella');

        // Plugin disattivato: niente campanelle
        $this->p->setEnabled(false);
        $this->eq(0, $notifs(), 'plugin disattivato: le notifiche spariscono');
        $this->p->setEnabled(true);
        $this->clearAlertState($id);
    }

    private function testBlocking(): void
    {
        $id = $this->alertCtx;
        $ctx = $this->ctx($id);
        $this->setSettings($id, ['quotaMb' => 10, 'mode' => 'block']);
        $validate = function () use ($ctx) {
            $errors = [];
            $this->svc();
            $this->p->validateSubmission('Submission::validate', [&$errors, null, ['contextId' => $ctx->getId()]]);
            return $errors;
        };
        $this->fakeUsage($id, 5 * MB);
        $this->eq([], $validate(), 'sotto la quota: le nuove submission sono accettate');
        $this->eq(false, $this->p->isBlocked($ctx), 'rivista aperta');
        $this->fakeUsage($id, 11 * MB);
        $this->ok($validate() !== [], 'oltre la quota: le nuove submission sono rifiutate');
        $this->eq(true, $this->p->isBlocked($ctx), 'rivista chiusa');
        $this->eq(false, (bool) $ctx->getData('disableSubmissions'), 'l\'impostazione di OJS "submission disattivate" non viene toccata');

        // Un controllo già in corso: il secondo si salta (niente email doppie)
        $s = $this->svc();
        $lock = (new ReflectionMethod($s, 'lock'))->invoke($s, $id);
        $this->eq(null, $this->svc()->check($ctx, false), 'controllo già in corso sulla stessa rivista: saltato');
        flock($lock, LOCK_UN);
        fclose($lock);
        $this->ok($this->svc()->check($ctx, false) !== null, 'finito il primo, il controllo riparte');
        $this->clearAlertState($id);
    }

    private function testProblems(): void
    {
        $site = Application::SITE_CONTEXT_ID;
        $old = $this->p->getSetting($site, 'lastProblem');
        $this->svc()->reportProblem('jqtest: problema di prova');
        $last = $this->svc()->lastProblem();
        $this->eq('jqtest: problema di prova', $last['message'] ?? null, 'il problema viene registrato per l\'amministratore');
        $this->p->updateSetting($site, 'lastProblem', ['at' => time() - 8 * 86400, 'message' => 'vecchio'], 'object');
        Cache::flush();
        $this->eq(null, $this->svc()->lastProblem(), 'dopo 7 giorni l\'avviso sparisce');
        $this->p->updateSetting($site, 'lastProblem', $old, 'object');
    }

    // ------------------------------------------------------------------
    // Stato: salvato prima dei test e rimesso dopo

    private function snapshot(): void
    {
        @mkdir(dirname(STATE), 0777, true);
        $state = [
            'settings' => DB::table('plugin_settings')->where('plugin_name', strtolower($this->p->getName()))->get()->map(fn ($r) => (array) $r)->all(),
            'files' => DB::table(QuotaLedger::TABLE)->get()->map(fn ($r) => (array) $r)->all(),
            'reservations' => DB::table(QuotaSchemaMigration::RESERVATIONS)->get()->map(fn ($r) => (array) $r)->all(),
            'notifications' => DB::table('notifications')->where('type', $this->p::NOTIFICATION_TYPE)->get()->map(fn ($r) => (array) $r)->all(),
        ];
        file_put_contents(STATE, json_encode($state));
        printf("stato salvato: %d impostazioni, %d file, %d notifiche\n", count($state['settings']), count($state['files']), count($state['notifications']));
    }

    private function restore(): void
    {
        if (!is_file(STATE)) {
            echo "nessuno stato salvato\n";
            return;
        }
        $state = json_decode(file_get_contents(STATE), true);
        DB::transaction(function () use ($state) {
            DB::table('plugin_settings')->where('plugin_name', strtolower($this->p->getName()))->delete();
            foreach (array_chunk($state['settings'], 200) as $chunk) {
                DB::table('plugin_settings')->insert($chunk);
            }
            DB::table(QuotaLedger::TABLE)->delete();
            foreach (array_chunk($state['files'], 500) as $chunk) {
                DB::table(QuotaLedger::TABLE)->insert($chunk);
            }
            DB::table(QuotaSchemaMigration::RESERVATIONS)->delete();
            foreach (array_chunk($state['reservations'], 200) as $chunk) {
                DB::table(QuotaSchemaMigration::RESERVATIONS)->insert($chunk);
            }
            DB::table('notifications')->where('type', $this->p::NOTIFICATION_TYPE)->delete();
            foreach (array_chunk($state['notifications'], 200) as $chunk) {
                DB::table('notifications')->insert($chunk);
            }
        });
        Cache::flush();
        unlink(STATE);
        printf("stato rimesso: %d impostazioni, %d file, %d notifiche\n", count($state['settings']), count($state['files']), count($state['notifications']));
    }

    // ------------------------------------------------------------------
    // Aiuti per http.sh

    private function tempFile(int $userId, int $bytes): int
    {
        $dir = $this->filesDir() . '/temp';
        @mkdir($dir, 0775, true);
        $name = 'jqtest-' . bin2hex(random_bytes(4)) . '.xml';
        file_put_contents("{$dir}/{$name}", str_repeat('x', $bytes));
        return (int) DB::table('temporary_files')->insertGetId([
            'user_id' => $userId, 'file_name' => $name, 'file_type' => 'text/xml', 'file_size' => $bytes,
            'original_file_name' => 'jqtest.xml', 'date_uploaded' => date('Y-m-d H:i:s'),
        ], 'file_id');
    }

    private function tempFileClean(): void
    {
        foreach (DB::table('temporary_files')->where('original_file_name', 'jqtest.xml')->get() as $row) {
            @unlink($this->filesDir() . '/temp/' . $row->file_name);
        }
        DB::table('temporary_files')->where('original_file_name', 'jqtest.xml')->delete();
    }

    private function tokens(string $action, array $userIds): void
    {
        foreach ($userIds as $id) {
            $u = Repo::user()->get($id);
            $key = $action === 'set' ? bin2hex(random_bytes(32)) : null;
            Repo::user()->edit($u, ['apiKey' => $key, 'apiKeyEnabled' => $action === 'set' ? 1 : null]);
            if ($action === 'set') {
                echo $id, ' ', JWT::encode([$key], Config::getVar('security', 'api_key_secret'), 'HS256'), "\n";
            }
        }
    }

    private function xss(string $action, int $id): void
    {
        $file = "/tmp/jqtests/name-{$id}.json";
        if ($action === 'set') {
            $rows = DB::table('journal_settings')->where('journal_id', $id)->where('setting_name', 'name')->get()->map(fn ($r) => (array) $r)->all();
            file_put_contents($file, json_encode($rows));
            DB::table('journal_settings')->where('journal_id', $id)->where('setting_name', 'name')
                ->update(['setting_value' => '<img src=x onerror=alert(1)> {{ 7*7 }}']);
        } elseif (is_file($file)) {
            DB::table('journal_settings')->where('journal_id', $id)->where('setting_name', 'name')->delete();
            DB::table('journal_settings')->insert(json_decode(file_get_contents($file), true));
            unlink($file);
        }
        Cache::flush();
    }
}

(new JqTests($argv ?? []))->execute();
