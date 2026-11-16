<?php
/*
 * classes/QuotaHandler.php
 * Copyright (C) 2026 Stefano Natangelo
 * SPDX-License-Identifier: AGPL-3.0-only
 */

/**
 * @file plugins/generic/journalQuota/classes/QuotaHandler.php
 *
 * @class QuotaHandler
 *
 * @brief Journal managers' "Disk Space" page: usage, limits, and details.
 */

namespace APP\plugins\generic\journalQuota\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\handler\Handler;
use APP\plugins\generic\journalQuota\JournalQuotaPlugin;
use APP\template\TemplateManager;
use PKP\core\PKPRequest;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\security\Role;
use PKP\security\Validation;

class QuotaHandler extends Handler
{
    /** @copydoc PKPHandler::_isBackendPage */
    public $_isBackendPage = true;

    public function __construct(private JournalQuotaPlugin $plugin)
    {
        parent::__construct();
        $this->addRoleAssignment([Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER], ['index']);
    }

    /** @copydoc PKPHandler::authorize() */
    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
        return parent::authorize($request, $args, $roleAssignments);
    }

    /**
     * @param array $args
     * @param PKPRequest $request
     */
    public function index($args, $request)
    {
        $this->setupTemplate($request);
        $context = $request->getContext();
        $service = $this->plugin->service();

        // "Scan again": refresh usage and alerts immediately, but no more
        // than once per minute because disk scans are expensive.
        $refresh = $request->isPost() && $request->checkCSRF() && $request->getUserVar('refresh')
            && time() - $service->usage($context)['computedAt'] >= 60;
        // If another process is already scanning, show the last saved result.
        if ($refresh) {
            $service->check($context, true);
        }
        if ($request->isPost()) {
            // Redirect back after scanning so refreshing the page does not rescan.
            $request->redirectUrl($request->url(null, JournalQuotaPlugin::PAGE));
            return;
        }
        $status = $service->status($context);

        $format = QuotaService::formatBytes(...);

        $dispatcher = $request->getDispatcher();
        $top = [];
        foreach ($service->top($context) as $submissionId => $size) {
            $submission = Repo::submission()->get((int) $submissionId, $context->getId());
            $top[] = [
                'id' => $submissionId,
                'title' => $submission?->getCurrentPublication()?->getLocalizedFullTitle() ?? __('plugins.generic.journalQuota.deletedSubmission'),
                'url' => $submission ? $dispatcher->url($request, Application::ROUTE_PAGE, null, 'dashboard', 'editorial', null, ['workflowSubmissionId' => $submission->getId()]) : null,
                'size' => $format($size),
            ];
        }

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'pageTitle' => __('plugins.generic.journalQuota.page.title'),
            'quotaStatus' => $status,
            'quotaUsed' => $format($status['used']),
            'quotaLimit' => $format($status['quota']),
            'quotaWarnLimit' => $format($status['warnLimit']),
            'quotaBlockLimit' => $format($status['blockLimit']),
            'quotaBarWidth' => $status['quota'] > 0 ? min(100, $status['used'] * 100 / $status['quota']) : 0,
            'quotaMessage' => $service->levelMessage($status),
            'quotaComputedAt' => $status['usage']['computedAt'] ? date('Y-m-d H:i', $status['usage']['computedAt']) : null,
            'quotaBreakdown' => $service->breakdown($status),
            'quotaBreakdownTemplate' => $this->plugin->getTemplateResource('breakdown.tpl'),
            'quotaDbTables' => array_map($format, $status['usage']['dbTables']),
            // Site administrators can open the overview of all journals.
            'quotaOverviewUrl' => Validation::isSiteAdmin()
                ? $dispatcher->url($request, Application::ROUTE_PAGE, 'index', JournalQuotaPlugin::PAGE)
                : null,
            'quotaTop' => $top,
            'quotaRefreshUrl' => $request->url(null, JournalQuotaPlugin::PAGE),
        ]);
        return $templateMgr->display($this->plugin->getTemplateResource('quota.tpl'));
    }
}
