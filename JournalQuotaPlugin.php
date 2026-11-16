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
 * @brief Per-journal disk quota. The site administrator configures
 * the quota, tolerance, warning threshold, and behavior; journal managers
 * view usage on the "Disk Space" page and receive OJS and email alerts.
 *
 * When a journal is blocked, the plugin sets "Disable submissions"
 * in memory for the current request only: OJS displays its standard
 * warnings, but the manager's stored setting remains unchanged. Above
 * the limit, uploads are also rejected (refuseUploads): existing files
 * can still be read, downloaded, and deleted, and uploads resume as soon
 * as usage falls below the limit.
 * The site administrator is never blocked.
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
    /** Type of OJS notifications created by the plugin */
    public const NOTIFICATION_TYPE = Notification::NOTIFICATION_TYPE_PLUGIN_BASE + 0x51A0;

    /** Journal manager page: /<journal>/quota */
    public const PAGE = 'quota';

    private ?QuotaService $service = null;

    /** Files (paths under files_dir) that an ongoing deletion may remove from disk */
    private array $deleting = [];

    /** Stored disableSubmissions value for journals blocked in this request */
    private array $storedDisable = [];

    /** Storage reserved by uploads in this request: journal => token */
    private array $reservations = [];

    /** Temporary files attached by this request (null = not yet read) */
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
     * Disabling the plugin removes its notifications: without the plugin,
     * the bell would show empty entries. Alerts resume when it is enabled.
     */
    public function setEnabled($enabled)
    {
        parent::setEnabled($enabled);
        $this->safely(fn () => $enabled
            // Uploads and deletions were missed while disabled: run a full scan at the next check.
            ? $this->service()->requestFullScan()
            : $this->service()->clearAlerts());
    }

    /** File-ledger table (QuotaLedger) */
    public function getInstallMigration(): QuotaSchemaMigration
    {
        return new QuotaSchemaMigration();
    }

    /** Site-wide setting configurable only by the administrator */
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
            // If execution stops, the lock expires after 30 minutes.
            ->withoutOverlapping(30);
    }

    /**
     * @copydoc Plugin::getActions()
     *
     * "Settings" opens the journal storage overview (/index/quota), where
     * an administrator selects a journal and edits its limits.
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
     * Reject all file uploads above the journal limit (submission
     * files, revisions, copyediting, issues, library files, and
     * images), including uploads that would exceed the limit.
     * Existing files remain readable, downloadable, and deletable;
     * uploads resume when usage falls below the limit.
     *
     * Applies to all requests carrying an uploaded file (APIs, grids, pages)
     * before OJS saves it. Site administrators are never blocked.
     */
    public function refuseUploads(string $hookName, array $args): bool
    {
        $request = $args[0];
        $attached = $this->uploadedBytes();
        // Two-step uploads (library, issues, images, XML
        // import): the temporary file belongs to the user, not the
        // journal, and is attached through temporaryFileId in a
        // second request. Count both uploaded files and attached temporary
        // files: a request may contain both, and OJS decides which
        // ones to save.
        $temporaryIds = $this->temporaryFileIds();
        if ($attached === null && !$temporaryIds) {
            return Hook::CONTINUE;
        }
        $incoming = (int) $attached;
        try {
            $context = $request->getContext();
            // Profile images belong to the site directory, not the journal.
            if (!$context || $this->isProfileRequest($request)) {
                return Hook::CONTINUE;
            }
            // Unauthenticated users (neither session nor API token)
            // are rejected by OJS without revealing journal details.
            $user = $this->uploader($request);
            if (!$user || $user->hasRole([Role::ROLE_ID_SITE_ADMIN], Application::SITE_CONTEXT_ID)) {
                return Hook::CONTINUE;
            }
            if ($temporaryIds) {
                // Only include the attaching user's temporary files; OJS rejects the others.
                $incoming += (int) DB::table('temporary_files')
                    ->whereIn('file_id', $temporaryIds)
                    ->where('user_id', $user->getId())
                    ->sum('file_size');
            }
            // Reserve storage under a lock: two simultaneous uploads
            // cannot jointly exceed the limit.
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
                // Too many concurrent uploads even after retries: reject the request.
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
            // A plugin failure must not prevent normal work: allow
            // the upload and alert the administrator. However, if the
            // database aborted the lock, reject the upload (retry is possible).
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
     * Release the reservation once the file is in the ledger (or at
     * request completion), so it is not counted twice.
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
     * Identify the uploader: session user or, for APIs, token owner.
     * Decode the token here as OJS does, because OJS has not
     * processed it yet at this point.
     */
    private function uploader($request): ?User
    {
        $user = $request->getUser();
        if ($user || !($request->getRouter() instanceof \PKP\core\APIRouter)) {
            return $user;
        }
        // As in OJS: when the Authorization header exists, use only it;
        // otherwise use apiToken (some servers expose that header to PHP
        // only as REDIRECT_HTTP_AUTHORIZATION).
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
            // Invalid token: let OJS reject it.
            return null;
        }
    }

    /** Temporary files this request attaches to the journal */
    private function temporaryFileIds(): array
    {
        if ($this->temporaryIds !== null) {
            return $this->temporaryIds;
        }
        $ids = [];
        // Include GET requests: OJS native XML import creates articles via
        // a GET request that attaches a previously uploaded temporary file.
        if (PHP_SAPI !== 'cli' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'OPTIONS') {
            $collect = function ($value, $key) use (&$ids) {
                if ($key === 'temporaryFileId' && is_numeric($value) && (int) $value > 0) {
                    $ids[] = (int) $value;
                }
            };
            $form = $_REQUEST;
            array_walk_recursive($form, $collect);
            // APIs receive JSON.
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
     * User profile: the profile image is uploaded from the
     * profile tab (tab.user.ProfileTabHandler component) and belongs
     * to the site directory, not the journal.
     */
    private function isProfileRequest($request): bool
    {
        $router = $request->getRouter();
        if ($router instanceof \PKP\core\PKPComponentRouter) {
            return $router->getRequestedComponent($request) === 'tab.user.ProfileTabHandler';
        }
        return $router instanceof \PKP\core\PKPPageRouter && $router->getRequestedPage($request) === 'user';
    }

    /** Bytes of files attached to the request, or null when none exist */
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

    /** Response in the format expected by the upload caller */
    private function sendUploadRefusal($request, string $key, string $message): void
    {
        $router = $request->getRouter();
        if ($router instanceof \PKP\core\APIRouter) {
            // APIs and the OJS file uploader display errorMessage.
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => $key, 'errorMessage' => $message]);
        } elseif ($router instanceof \PKP\core\PKPComponentRouter) {
            // Grids and forms (library, issues): standard OJS JSON response.
            header('Content-Type: application/json');
            echo (new JSONMessage(false, $message))->getString();
        } else {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo $message;
        }
    }

    /**
     * Managers' "Disk Space" page and blocking of new submissions in pages
     */
    public function loadHandler(string $hookName, array $args): bool
    {
        $page = &$args[0];
        $handler = &$args[3];
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context) {
            // No journal (/index/quota): administrator overview.
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
            // Plugin problems must never make a journal unreachable.
            error_log('journalQuota: ' . $e->getMessage());
        }
        return Hook::CONTINUE;
    }

    /**
     * Disable new submissions in memory for this request only.
     *
     * GET requests only: some pages (for example, payments) save
     * this request's journal object through updateObject(), which
     * would persist the block. In management settings, the actual
     * value must remain visible; otherwise saving the form would
     * make the block permanent.
     */
    private function applyBlock($request, Context $context, ?string $page): void
    {
        if ($request->isGet() && $page !== 'management' && $this->isBlocked($context)) {
            $this->storedDisable[$context->getId()] = $context->getData('disableSubmissions');
            $context->setData('disableSubmissions', true);
            // TemplateManager may have been created before Dispatcher
            // reloaded the journal; templates would then use another copy.
            $templateMgr = TemplateManager::getManager($request);
            foreach (['currentContext', 'currentJournal'] as $var) {
                $copy = $templateMgr->getTemplateVars($var);
                if ($copy instanceof Context && $copy !== $context && $copy->getId() === $context->getId()) {
                    $copy->setData('disableSubmissions', true);
                }
            }
        }
    }

    /** Reject creation of a new submission (API) when the journal is blocked */
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
     * Also reject submission of a draft started before blocking:
     * it is still a new submission for that journal.
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

    /** If the blocked context is saved, do not persist the in-memory block */
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

    /** "Disk Space" item under Statistics for managers and administrators */
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

    /** "Journal storage" panel on the site Administration page */
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

    /** Text of the plugin's notifications in the OJS bell */
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
     * After a request that may have changed files (issue files,
     * library files, logos, etc.), rescan the journal's small
     * sections. OJS has no hooks for these uploads, which occur
     * through pages, grids, and APIs; do this after each non-GET
     * request from an authenticated user.
     */
    private function refreshSmallPartsAfterWrites(): void
    {
        // GET requests normally do not write; XML import is an exception:
        // it attaches a temporary file and saves issue PDFs and covers
        // without passing through file hooks.
        if (PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS'
            || (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD']) && !$this->temporaryFileIds())) {
            return;
        }
        // If a file was uploaded, attached, or deleted, rescan
        // immediately. For other requests (autosaves, grid actions),
        // rescan at most once per minute per journal because the
        // operation traverses directories.
        $immediate = !empty($_FILES) || $this->temporaryFileIds()
            || ($_SERVER['REQUEST_METHOD'] ?? '') === 'DELETE';
        register_shutdown_function(fn () => $this->safely(function () use ($immediate) {
            $request = Application::get()->getRequest();
            $context = $request->getContext();
            // Also include API-token users, who have no session.
            if (!$context || !$this->uploader($request)) {
                return;
            }
            if ($immediate || Cache::add('journalQuota.smallParts.' . $context->getId(), 1, 60)) {
                $this->service()->refreshSmallParts($context);
            }
        }));
    }

    /** New submission file: update usage immediately */
    public function fileAdded(string $hookName, array $args): bool
    {
        $submissionFile = $args[0];
        $this->safely(fn () => $this->storeFile((int) $submissionFile->getData('fileId')));
        // Keep the reservation until request completion: XML import
        // adds multiple files sequentially. Until then, saved files
        // count twice (ledger and reservation), conservatively.
        return Hook::CONTINUE;
    }

    /**
     * New file revision: the old file remains on disk.
     * Copying a file to another stage uses the same disk file,
     * which already has a ledger row, so it is not counted twice.
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

    /** Before deletion: collect files from all versions */
    public function fileDeleting(string $hookName, array $args): bool
    {
        $submissionFile = $args[0];
        $this->safely(function () use ($submissionFile) {
            // Per-file deletion: when a file has dependent files (such as
            // images in an HTML draft), OJS deletes those first and calls
            foreach (Repo::submissionFile()->getRevisions($submissionFile->getId()) as $revision) {
                $this->deleting[$submissionFile->getId()][] = (string) $revision->path;
            }
        });
        return Hook::CONTINUE;
    }

    /**
     * After deletion, remove only files OJS actually deleted
     * from disk (files shared by copies remain).
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

    /** Deleted submission: remove ledger files no longer on disk */
    public function submissionDeleted(string $hookName, array $args): bool
    {
        $submission = $args[0];
        $this->safely(fn () => $this->service()->submissionRemoved((int) $submission->getId()));
        return Hook::CONTINUE;
    }

    /** Deleted journal: remove its ledger rows */
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

    /** Accounting problems must never prevent uploads or deletions */
    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            $this->problem($e);
        }
    }

    /** Plugin error: log it and show it on the administrator's Disk Space page */
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

    /** Journal rejects new submissions; administrators are always exempt */
    public function isBlocked(Context $context): bool
    {
        return !Validation::isSiteAdmin() && $this->service()->status($context)['blocked'];
    }

    public function canSeeQuota(Context $context): bool
    {
        return Validation::isSiteAdmin() || Validation::isAuthorized(Role::ROLE_ID_MANAGER, $context->getId());
    }
}
