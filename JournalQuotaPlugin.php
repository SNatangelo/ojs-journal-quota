<?php
/*
 * JournalQuotaPlugin.php
 * Copyright (C) 2026 Stefano Natangelo
 * SPDX-License-Identifier: AGPL-3.0-only
 */

/**
 * @file plugins/generic/journalQuota/JournalQuotaPlugin.php
 *
 * @class JournalQuotaPlugin
 *
 * @brief Quota di spazio per ogni rivista. L'amministratore del sito imposta
 *  quota, tolleranza, soglia di avviso e comportamento; i journal manager
 *  vedono i consumi nella pagina "Spazio" e ricevono avvisi in OJS e via email.
 *
 *  Quando una rivista è bloccata, il plugin attiva "Disattiva le submission"
 *  solo in memoria, per la richiesta in corso: compaiono gli avvisi standard di
 *  OJS, ma l'impostazione salvata dal manager non cambia. Oltre il limite si
 *  rifiutano anche i caricamenti di file (refuseUploads): i file si possono
 *  leggere, scaricare e cancellare, e i caricamenti riaprono appena lo spazio
 *  torna sotto il limite.
 *  L'amministratore del sito non viene mai bloccato.
 */

namespace APP\plugins\generic\journalQuota;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\journalQuota\classes\QuotaCheckTask;
use APP\plugins\generic\journalQuota\classes\QuotaHandler;
use APP\plugins\generic\journalQuota\classes\QuotaNotify;
use APP\plugins\generic\journalQuota\classes\QuotaSchemaMigration;
use APP\plugins\generic\journalQuota\classes\QuotaService;
use APP\plugins\generic\journalQuota\classes\QuotaSiteHandler;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PKP\context\Context;
use PKP\config\Config;
use PKP\core\JSONMessage;
use PKP\core\PKPJwt;
use Firebase\JWT\Key;
use PKP\user\User;
use stdClass;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\RedirectAction;
use PKP\notification\Notification;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\plugins\interfaces\HasTaskScheduler;
use PKP\scheduledTask\PKPScheduler;
use PKP\security\Role;
use PKP\security\Validation;
use Throwable;

class JournalQuotaPlugin extends GenericPlugin implements HasTaskScheduler
{
    /** Tipo delle notifiche di OJS create dal plugin */
    public const NOTIFICATION_TYPE = Notification::NOTIFICATION_TYPE_PLUGIN_BASE + 0x51A0;

    /** Pagina dei journal manager: /<rivista>/quota */
    public const PAGE = 'quota';

    private ?QuotaService $service = null;

    /** File (percorsi in files_dir) che una cancellazione in corso può togliere dal disco */
    private array $deleting = [];

    /** Valore salvato di disableSubmissions delle riviste bloccate in questa richiesta */
    private array $storedDisable = [];

    /** Spazio prenotato dai caricamenti di questa richiesta: rivista => codice */
    private array $reservations = [];

    /** File temporanei agganciati da questa richiesta (null = non ancora letti) */
    private ?array $temporaryIds = null;

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        if (!parent::register($category, $path, $mainContextId)) {
            return false;
        }
        if ($this->getEnabled()) {
            Hook::add('Dispatcher::dispatch', $this->refuseUploads(...));
            Hook::add('LoadHandler', $this->loadHandler(...));
            Hook::add('Submission::validate', $this->validateSubmission(...));
            Hook::add('Submission::validateSubmit', $this->validateSubmit(...));
            Hook::add('Context::edit', $this->keepDisableSubmissions(...));
            Hook::add('TemplateManager::setupBackendPage', $this->addMenuItem(...));
            Hook::add('Templates::Admin::Index::AdminFunctions', $this->addAdminPanel(...));
            Hook::add('NotificationManager::getNotificationMessage', $this->notificationMessage(...));
            Hook::add('Mailer::Mailables', $this->addMailable(...));
            Hook::add('SubmissionFile::add', $this->fileAdded(...));
            Hook::add('SubmissionFile::edit', $this->fileReplaced(...));
            Hook::add('SubmissionFile::delete::before', $this->fileDeleting(...));
            Hook::add('SubmissionFile::delete', $this->fileDeleted(...));
            Hook::add('Submission::delete', $this->submissionDeleted(...));
            Hook::add('Context::delete', $this->contextDeleted(...));
            $this->refreshSmallPartsAfterWrites();
        }
        return true;
    }

    /**
     * Disattivando il plugin si tolgono le sue notifiche: senza il plugin la
     * campanella le mostrerebbe vuote. Riattivandolo, gli avvisi ripartono.
     */
    public function setEnabled($enabled)
    {
        parent::setEnabled($enabled);
        $this->safely(fn () => $enabled
            // Da disattivato non ha visto caricamenti e cancellazioni: misura completa al prossimo controllo
            ? $this->service()->requestFullScan()
            : $this->service()->clearAlerts());
    }

    /** Tabella del registro dei file (QuotaLedger) */
    public function getInstallMigration(): QuotaSchemaMigration
    {
        return new QuotaSchemaMigration();
    }

    /** Vale per tutto il sito e lo configura solo l'amministratore */
    public function isSitePlugin(): bool
    {
        return true;
    }

    public function getDisplayName(): string
    {
        return __('plugins.generic.journalQuota.displayName');
    }

    public function getDescription(): string
    {
        return __('plugins.generic.journalQuota.description');
    }

    public function getInstallEmailTemplatesFile(): string
    {
        return "{$this->getPluginPath()}/emailTemplates.xml";
    }

    public function service(): QuotaService
    {
        return $this->service ??= new QuotaService($this);
    }

    /** @copydoc HasTaskScheduler::registerSchedules() */
    public function registerSchedules(PKPScheduler $scheduler): void
    {
        $scheduler
            ->addSchedule(new QuotaCheckTask($this))
            ->hourly()
            ->name(QuotaCheckTask::class)
            // Se un'esecuzione si interrompe, il blocco scade dopo 30 minuti
            ->withoutOverlapping(30);
    }

    /**
     * @copydoc Plugin::getActions()
     *
     * "Impostazioni" apre la pagina Spazio delle riviste (/index/quota), dove
     * si sceglie una rivista e se ne cambiano i limiti
     */
    public function getActions($request, $verb)
    {
        return array_merge(
            $this->getEnabled() && Validation::isSiteAdmin() ? [
                new LinkAction(
                    'settings',
                    new RedirectAction($request->getDispatcher()->url($request, Application::ROUTE_PAGE, 'index', self::PAGE)),
                    __('manager.plugins.settings'),
                    null
                ),
            ] : [],
            parent::getActions($request, $verb)
        );
    }

    /**
     * Oltre il limite si rifiuta ogni caricamento di file nella rivista (file
     * delle submission, revisioni, copyediting, fascicoli, biblioteca,
     * immagini), e anche un caricamento che porterebbe la rivista oltre il
     * limite. Si possono sempre leggere, scaricare e cancellare i file: appena
     * lo spazio scende sotto il limite, i caricamenti riaprono.
     *
     * Vale per tutte le richieste con un file allegato (API, griglie, pagine),
     * prima che OJS lo salvi. L'amministratore del sito non viene mai bloccato.
     */
    public function refuseUploads(string $hookName, array $args): bool
    {
        $request = $args[0];
        $attached = $this->uploadedBytes();
        // Caricamenti in due passaggi (biblioteca, fascicoli, immagini,
        // importazione XML): il file temporaneo è dell'utente, non della
        // rivista, e si aggancia alla rivista con temporaryFileId in una
        // seconda richiesta. Si contano sempre sia gli allegati sia i file
        // agganciati: una richiesta può avere entrambi, e OJS salva quello
        // che vuole lui.
        $temporaryIds = $this->temporaryFileIds();
        if ($attached === null && !$temporaryIds) {
            return Hook::CONTINUE;
        }
        $incoming = (int) $attached;
        try {
            $context = $request->getContext();
            // L'immagine del profilo va nella cartella del sito, non della rivista
            if (!$context || $this->isProfileRequest($request)) {
                return Hook::CONTINUE;
            }
            // Chi non è collegato (né con la sessione né con un token API)
            // viene rifiutato da OJS: niente dettagli sulla rivista
            $user = $this->uploader($request);
            if (!$user || $user->hasRole([Role::ROLE_ID_SITE_ADMIN], Application::SITE_CONTEXT_ID)) {
                return Hook::CONTINUE;
            }
            if ($temporaryIds) {
                // Solo i file temporanei di chi li aggancia: gli altri OJS li rifiuta
                $incoming += (int) DB::table('temporary_files')
                    ->whereIn('file_id', $temporaryIds)
                    ->where('user_id', $user->getId())
                    ->sum('file_size');
            }
            // Lo spazio si prenota sotto blocco: due caricamenti nello stesso
            // istante non possono superare insieme il limite
            $reservation = $this->service()->reserveUpload($context, $incoming);
            if ($reservation['token'] !== null) {
                if ($reservation['token'] !== '') {
                    $this->reservations[$context->getId()] = $reservation['token'];
                    $contextId = $context->getId();
                    register_shutdown_function(fn () => $this->releaseReservation($contextId));
                }
                return Hook::CONTINUE;
            }
            $status = $reservation['status'];
            if (!empty($reservation['busy'])) {
                // Troppi caricamenti insieme anche dopo i tentativi: si rifiuta
                $key = 'plugins.generic.journalQuota.upload.busy';
                $message = __($key, ['journal' => $context->getLocalizedName()]);
            } else {
                $key = $status['blocked'] ? 'plugins.generic.journalQuota.upload.blocked' : 'plugins.generic.journalQuota.upload.tooLarge';
                $message = __($key, [
                    'journal' => $context->getLocalizedName(),
                    'limit' => QuotaService::formatBytes($status['blockLimit']),
                    'size' => QuotaService::formatBytes($incoming),
                    'free' => QuotaService::formatBytes(max(0, $status['blockLimit'] - $status['used'] - $reservation['pending'])),
                ]);
            }
        } catch (Throwable $e) {
            // Un problema del plugin non deve mai impedire di lavorare: il
            // caricamento passa e l'amministratore vede l'avviso. Se però il
            // database ha interrotto il blocco, si rifiuta (si può riprovare)
            $this->problem($e);
            if (!QuotaService::isConcurrencyError($e) || !isset($context)) {
                return Hook::CONTINUE;
            }
            $key = 'plugins.generic.journalQuota.upload.busy';
            $message = __($key, ['journal' => $context->getLocalizedName()]);
        }
        $this->sendUploadRefusal($request, $key, $message);
        exit;
    }

    /**
     * Toglie la prenotazione appena il file è contato nel registro (o a fine
     * richiesta), così lo stesso file non conta due volte
     */
    private function releaseReservation(int $contextId): void
    {
        $token = $this->reservations[$contextId] ?? null;
        unset($this->reservations[$contextId]);
        if ($token) {
            $this->safely(fn () => $this->service()->releaseUpload($contextId, $token));
        }
    }

    /**
     * Chi carica: l'utente della sessione o, per le API, quello del token.
     * Il token si decodifica qui come fa OJS, perché a questo punto OJS non
     * l'ha ancora letto.
     */
    private function uploader($request): ?User
    {
        $user = $request->getUser();
        if ($user || !($request->getRouter() instanceof \PKP\core\APIRouter)) {
            return $user;
        }
        // Come OJS: se c'è l'intestazione Authorization vale solo quella,
        // altrimenti il parametro apiToken (alcuni server la passano a PHP
        // solo come REDIRECT_HTTP_AUTHORIZATION)
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('getallheaders')) {
            $header = (string) (array_change_key_case((array) getallheaders())['authorization'] ?? '');
        }
        $token = null;
        if ($header === '') {
            $token = $_GET['apiToken'] ?? null;
        } else {
            foreach (explode(',', $header) as $clause) {
                $parts = explode(' ', trim($clause));
                if (count($parts) === 2 && $parts[0] === 'Bearer') {
                    $token = $parts[1];
                    break;
                }
            }
        }
        if (!is_string($token)) {
            return null;
        }
        $secret = Config::getVar('security', 'api_key_secret', null);
        if (!$token || !$secret) {
            return null;
        }
        try {
            $headers = new stdClass();
            $apiKey = ((array) PKPJwt::decode($token, new Key($secret, 'HS256'), $headers))[0];
            if (substr($apiKey, 0, 2) === '""') {
                $apiKey = json_decode($apiKey);
            }
            $user = Repo::user()->getByApiKey($apiKey);
            return $user && $user->getData('apiKeyEnabled') ? $user : null;
        } catch (Throwable) {
            // Token non valido: lo rifiuta OJS
            return null;
        }
    }

    /** File temporanei che la richiesta aggancia alla rivista */
    private function temporaryFileIds(): array
    {
        if ($this->temporaryIds !== null) {
            return $this->temporaryIds;
        }
        $ids = [];
        // Anche le GET: l'importazione XML nativa scrive gli articoli con una
        // GET che aggancia il file temporaneo caricato prima
        if (PHP_SAPI !== 'cli' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'OPTIONS') {
            $collect = function ($value, $key) use (&$ids) {
                if ($key === 'temporaryFileId' && is_numeric($value) && (int) $value > 0) {
                    $ids[] = (int) $value;
                }
            };
            $form = $_REQUEST;
            array_walk_recursive($form, $collect);
            // Le API ricevono JSON
            if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'json')) {
                $body = json_decode((string) file_get_contents('php://input'), true);
                if (is_array($body)) {
                    array_walk_recursive($body, $collect);
                }
            }
        }
        return $this->temporaryIds = array_values(array_unique($ids));
    }

    /**
     * Profilo utente: l'immagine del profilo si carica dalla scheda del
     * profilo (componente tab.user.ProfileTabHandler) e va nella cartella
     * del sito, non della rivista
     */
    private function isProfileRequest($request): bool
    {
        $router = $request->getRouter();
        if ($router instanceof \PKP\core\PKPComponentRouter) {
            return $router->getRequestedComponent($request) === 'tab.user.ProfileTabHandler';
        }
        return $router instanceof \PKP\core\PKPPageRouter && $router->getRequestedPage($request) === 'user';
    }

    /** Byte dei file allegati alla richiesta, o null se non ce ne sono */
    private function uploadedBytes(): ?int
    {
        if (PHP_SAPI === 'cli' || empty($_FILES)) {
            return null;
        }
        $bytes = 0;
        $found = false;
        array_walk_recursive($_FILES, function ($value, $key) use (&$bytes, &$found) {
            if ($key === 'size' && (int) $value > 0) {
                $bytes += (int) $value;
                $found = true;
            }
        });
        return $found ? $bytes : null;
    }

    /** Risposta nel formato che si aspetta chi ha chiesto il caricamento */
    private function sendUploadRefusal($request, string $key, string $message): void
    {
        $router = $request->getRouter();
        if ($router instanceof \PKP\core\APIRouter) {
            // Le API e il caricatore di file di OJS mostrano errorMessage
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => $key, 'errorMessage' => $message]);
        } elseif ($router instanceof \PKP\core\PKPComponentRouter) {
            // Griglie e moduli (biblioteca, fascicoli): risposta JSON di OJS
            header('Content-Type: application/json');
            echo (new JSONMessage(false, $message))->getString();
        } else {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo $message;
        }
    }

    /**
     * Pagina "Spazio" dei manager e blocco delle nuove submission nelle pagine
     */
    public function loadHandler(string $hookName, array $args): bool
    {
        $page = &$args[0];
        $handler = &$args[3];
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context) {
            // Senza rivista (/index/quota): panoramica dell'amministratore
            if ($page === self::PAGE) {
                $handler = new QuotaSiteHandler($this);
                return Hook::ABORT;
            }
            return Hook::CONTINUE;
        }

        if ($page === self::PAGE) {
            $handler = new QuotaHandler($this);
            return Hook::ABORT;
        }

        try {
            $this->applyBlock($request, $context, $page);
        } catch (Throwable $e) {
            // Un problema del plugin non deve mai rendere irraggiungibile la rivista
            error_log('journalQuota: ' . $e->getMessage());
        }
        return Hook::CONTINUE;
    }

    /**
     * Chiude le nuove submission solo in memoria, per questa richiesta.
     *
     * Solo per le richieste GET: alcune pagine (per esempio payments) salvano
     * con updateObject() proprio l'oggetto rivista di questa richiesta, e il
     * blocco diventerebbe permanente. Nelle impostazioni (management) il valore
     * vero deve restare visibile, altrimenti salvando il modulo il manager lo
     * renderebbe permanente.
     */
    private function applyBlock($request, Context $context, ?string $page): void
    {
        if ($request->isGet() && $page !== 'management' && $this->isBlocked($context)) {
            $this->storedDisable[$context->getId()] = $context->getData('disableSubmissions');
            $context->setData('disableSubmissions', true);
            // Il TemplateManager può essere stato creato prima che il Dispatcher
            // ricaricasse la rivista: in quel caso i template ne usano un'altra copia
            $templateMgr = TemplateManager::getManager($request);
            foreach (['currentContext', 'currentJournal'] as $var) {
                $copy = $templateMgr->getTemplateVars($var);
                if ($copy instanceof Context && $copy !== $context && $copy->getId() === $context->getId()) {
                    $copy->setData('disableSubmissions', true);
                }
            }
        }
    }

    /** Rifiuta la creazione di una nuova submission (API) se la rivista è bloccata */
    public function validateSubmission(string $hookName, array $args): bool
    {
        $errors = &$args[0];
        $submission = $args[1];
        $props = $args[2] ?? [];
        $context = isset($props['contextId'])
            ? Application::getContextDAO()->getById((int) $props['contextId'])
            : Application::get()->getRequest()->getContext();
        if ($submission === null && $context && $this->isBlocked($context)) {
            $errors['contextId'] = [__('author.submit.notAccepting')];
        }
        return Hook::CONTINUE;
    }

    /**
     * Rifiuta anche l'invio di una bozza iniziata prima del blocco: per la
     * rivista è comunque una nuova submission
     */
    public function validateSubmit(string $hookName, array $args): bool
    {
        $errors = &$args[0];
        $context = $args[2] ?? null;
        if ($context instanceof Context && $this->isBlocked($context)) {
            $errors['contextId'] = [__('author.submit.notAccepting')];
        }
        return Hook::CONTINUE;
    }

    /** Se il contesto bloccato viene salvato, non salva il blocco in memoria */
    public function keepDisableSubmissions(string $hookName, array $args): bool
    {
        $newContext = $args[0];
        $params = $args[2];
        $id = $newContext->getId();
        if (array_key_exists($id, $this->storedDisable) && !array_key_exists('disableSubmissions', $params)) {
            $newContext->setData('disableSubmissions', $this->storedDisable[$id]);
        }
        return Hook::CONTINUE;
    }

    /** Voce "Spazio" sotto Statistiche, per manager e amministratori */
    public function addMenuItem(string $hookName, array $args = []): bool
    {
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context || !$this->canSeeQuota($context)) {
            return Hook::CONTINUE;
        }
        $templateMgr = TemplateManager::getManager($request);
        $menu = $templateMgr->getState('menu');
        if (!is_array($menu)) {
            return Hook::CONTINUE;
        }

        $item = [
            'name' => __('plugins.generic.journalQuota.menu'),
            'url' => $request->getRouter()->url($request, null, self::PAGE),
            'isCurrent' => $request->getRequestedPage() === self::PAGE,
        ];
        if (isset($menu['statistics']['submenu'])) {
            $menu['statistics']['submenu']['journalQuota'] = $item;
        } else {
            $menu['journalQuota'] = $item + ['icon' => 'Statistics'];
        }
        $templateMgr->setState(['menu' => $menu]);
        return Hook::CONTINUE;
    }

    /** Riquadro "Spazio delle riviste" nella pagina Amministrazione del sito */
    public function addAdminPanel(string $hookName, array $args): bool
    {
        $output = &$args[2];
        $request = Application::get()->getRequest();
        if (!Validation::isSiteAdmin()) {
            return Hook::CONTINUE;
        }
        $url = $request->getDispatcher()->url($request, Application::ROUTE_PAGE, 'index', self::PAGE);
        $output .= '<action-panel><h2>' . htmlspecialchars(__('plugins.generic.journalQuota.overview.title')) . '</h2>'
            . '<p>' . htmlspecialchars(__('plugins.generic.journalQuota.overview.description')) . '</p>'
            . '<template #actions><pkp-button element="a" href="' . htmlspecialchars($url) . '">'
            . htmlspecialchars(__('plugins.generic.journalQuota.overview.link')) . '</pkp-button></template></action-panel>';
        return Hook::CONTINUE;
    }

    /** Testo delle notifiche del plugin nella campanella di OJS */
    public function notificationMessage(string $hookName, array $args): bool
    {
        $notification = $args[0];
        $message = &$args[1];
        if ($notification->type !== self::NOTIFICATION_TYPE) {
            return Hook::CONTINUE;
        }
        $context = Application::getContextDAO()->getById($notification->contextId);
        if ($context) {
            $status = $this->service()->status($context);
            $message = __('plugins.generic.journalQuota.notification', [
                'used' => QuotaService::formatBytes($status['used']),
                'quota' => QuotaService::formatBytes($status['quota']),
                'percent' => $status['percent'],
            ]) . ' ' . $this->service()->levelMessage($status);
        }
        return Hook::CONTINUE;
    }

    /**
     * Dopo una richiesta che può aver modificato file (caricamento di un file
     * di fascicolo, della biblioteca, di un logo...) si rimisurano le parti
     * piccole della rivista. OJS non ha hook per questi caricamenti, che passano
     * da pagine, griglie e API: vale per ogni richiesta non GET di un utente
     * collegato, a fine richiesta.
     */
    private function refreshSmallPartsAfterWrites(): void
    {
        // Le GET di solito non scrivono; fa eccezione l'importazione XML, che
        // aggancia un file temporaneo e salva PDF dei fascicoli e copertine
        // senza passare dagli hook dei file
        if (PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS'
            || (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD']) && !$this->temporaryFileIds())) {
            return;
        }
        // Con un file caricato o agganciato, o una cancellazione, si rimisura
        // subito; per le altre richieste (salvataggi automatici, azioni delle
        // griglie) al massimo una volta al minuto per rivista, perché la
        // misura percorre le cartelle
        $immediate = !empty($_FILES) || $this->temporaryFileIds()
            || ($_SERVER['REQUEST_METHOD'] ?? '') === 'DELETE';
        register_shutdown_function(fn () => $this->safely(function () use ($immediate) {
            $request = Application::get()->getRequest();
            $context = $request->getContext();
            // Anche chi lavora con un token API, che non ha una sessione
            if (!$context || !$this->uploader($request)) {
                return;
            }
            if ($immediate || Cache::add('journalQuota.smallParts.' . $context->getId(), 1, 60)) {
                $this->service()->refreshSmallParts($context);
            }
        }));
    }

    /** Nuovo file di una submission: lo spazio si aggiorna subito */
    public function fileAdded(string $hookName, array $args): bool
    {
        $submissionFile = $args[0];
        $this->safely(fn () => $this->storeFile((int) $submissionFile->getData('fileId')));
        // La prenotazione resta fino alla fine della richiesta: un'importazione
        // XML aggiunge più file uno dopo l'altro. Fino ad allora i file già
        // salvati contano due volte (registro e prenotazione), per prudenza.
        return Hook::CONTINUE;
    }

    /**
     * Nuova versione di un file (revisione): il file vecchio resta sul disco.
     * Copiare un file in un'altra fase usa lo stesso file sul disco, che ha già
     * la sua riga nel registro: non si conta due volte.
     */
    public function fileReplaced(string $hookName, array $args): bool
    {
        [$newSubmissionFile, $submissionFile, $params] = $args;
        $fileId = (int) ($params['fileId'] ?? 0);
        if ($fileId && $fileId !== (int) $submissionFile->getData('fileId')) {
            $this->safely(fn () => $this->storeFile($fileId));
        }
        return Hook::CONTINUE;
    }

    /** Prima della cancellazione: i file di tutte le versioni */
    public function fileDeleting(string $hookName, array $args): bool
    {
        $submissionFile = $args[0];
        $this->safely(function () use ($submissionFile) {
            // Per file: cancellando un file con file dipendenti (immagini di
            // una bozza HTML) OJS cancella prima quelli, con le loro chiamate
            foreach (Repo::submissionFile()->getRevisions($submissionFile->getId()) as $revision) {
                $this->deleting[$submissionFile->getId()][] = (string) $revision->path;
            }
        });
        return Hook::CONTINUE;
    }

    /**
     * Dopo la cancellazione: si tolgono solo i file che OJS ha davvero tolto
     * dal disco (un file usato anche da una copia resta)
     */
    public function fileDeleted(string $hookName, array $args): bool
    {
        $id = $args[0]->getId();
        $this->safely(function () use ($id) {
            foreach ($this->deleting[$id] ?? [] as $path) {
                $this->service()->fileRemoved($path);
            }
        });
        unset($this->deleting[$id]);
        return Hook::CONTINUE;
    }

    /** Submission cancellata: si tolgono i suoi file rimasti nel registro ma non più sul disco */
    public function submissionDeleted(string $hookName, array $args): bool
    {
        $submission = $args[0];
        $this->safely(fn () => $this->service()->submissionRemoved((int) $submission->getId()));
        return Hook::CONTINUE;
    }

    /** Rivista cancellata: si tolgono le sue righe dal registro */
    public function contextDeleted(string $hookName, array $args): bool
    {
        $context = $args[0];
        $this->safely(fn () => $this->service()->contextRemoved((int) $context->getId()));
        return Hook::CONTINUE;
    }

    private function storeFile(int $fileId): void
    {
        $path = $fileId ? DB::table('files')->where('file_id', $fileId)->value('path') : null;
        if ($path) {
            $this->service()->fileStored((string) $path);
        }
    }

    /** Un problema nel conteggio non deve mai impedire caricamenti e cancellazioni */
    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            $this->problem($e);
        }
    }

    /** Errore del plugin: nel log e, per l'amministratore, nella pagina Spazio */
    private function problem(Throwable $e): void
    {
        $message = $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        try {
            $this->service()->reportProblem($message);
        } catch (Throwable) {
            error_log('journalQuota: ' . $message);
        }
    }

    public function addMailable(string $hookName, array $args): bool
    {
        $args[0]->push(QuotaNotify::class);
        return Hook::CONTINUE;
    }

    /** La rivista non accetta nuove submission; l'amministratore passa sempre */
    public function isBlocked(Context $context): bool
    {
        return !Validation::isSiteAdmin() && $this->service()->status($context)['blocked'];
    }

    public function canSeeQuota(Context $context): bool
    {
        return Validation::isSiteAdmin() || Validation::isAuthorized(Role::ROLE_ID_MANAGER, $context->getId());
    }
}
