<?php
/*
 * classes/QuotaSiteHandler.php
 * Copyright (C) 2026 Stefano Natangelo
 * SPDX-License-Identifier: AGPL-3.0-only
 */

/**
 * @file plugins/generic/journalQuota/classes/QuotaSiteHandler.php
 *
 * @class QuotaSiteHandler
 *
 * @brief Administrator page (/index/quota): select a journal from
 *  a menu to view and edit its usage and limits.
 *  The same menu provides access to the default settings.
 *  Shows the latest stored measurements without scanning the disk.
 */

namespace APP\plugins\generic\journalQuota\classes;

use APP\core\Application;
use APP\handler\Handler;
use APP\plugins\generic\journalQuota\JournalQuotaPlugin;
use APP\template\TemplateManager;
use PKP\context\Context;
use PKP\core\PKPRequest;
use PKP\security\authorization\PKPSiteAccessPolicy;
use PKP\security\Role;
use Throwable;

class QuotaSiteHandler extends Handler
{
    /** Menu entry for default settings */
    public const DEFAULTS = 'defaults';

    /** Journal colors in the overview */
    public const JOURNAL_COLORS = ['#1e6fa8', '#e08a00', '#2e8b57', '#7b4fc9', '#c62828', '#00838f', '#8d6e63', '#5c6bc0'];

    /** @copydoc PKPHandler::_isBackendPage */
    public $_isBackendPage = true;

    public function __construct(private JournalQuotaPlugin $plugin)
    {
        parent::__construct();
        $this->addRoleAssignment([Role::ROLE_ID_SITE_ADMIN], ['index', 'save']);
    }

    /** @copydoc PKPHandler::authorize() */
    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new PKPSiteAccessPolicy($request, null, $roleAssignments));
        return parent::authorize($request, $args, $roleAssignments);
    }

    /**
     * @param array $args
     * @param PKPRequest $request
     */
    public function index($args, $request)
    {
        $this->setupTemplate($request);
        $service = $this->plugin->service();
        $format = QuotaService::formatBytes(...);
        $choice = (string) $request->getUserVar('journal');

        $menu = [];
        $selected = null;
        $usingDefaults = [];
        // Overview: site-wide usage by section and by journal.
        $siteParts = [];
        $shares = [];
        $states = ['blocked' => [], 'warning' => [], 'ok' => [], 'noLimit' => []];
        foreach ($this->contexts() as $context) {
            $status = $service->status($context);
            foreach ($status['usage']['parts'] as $key => $bytes) {
                $siteParts[$key] = ($siteParts[$key] ?? 0) + $bytes;
            }
            $link = [
                'name' => $context->getLocalizedName(),
                'url' => $this->pageUrl($request, null, ['journal' => $context->getId()]),
            ];
            $shares[] = $link + ['bytes' => array_sum($status['usage']['parts'])];
            $state = match (true) {
                $status['quota'] <= 0 => 'noLimit',
                $status['blocked'] => 'blocked',
                $status['level'] > QuotaService::LEVEL_OK => 'warning',
                default => 'ok',
            };
            $states[$state][] = $link;
            $custom = $service->isCustom($context->getId());
            if (!$custom) {
                $usingDefaults[] = $context->getLocalizedName();
            }
            $menu[] = [
                'id' => (string) $context->getId(),
                'label' => $this->menuLabel($context, $status),
            ];
            if ($choice === (string) $context->getId()) {
                $selected = [
                    'id' => $context->getId(),
                    'name' => $context->getLocalizedName(),
                    'used' => $format($status['used']),
                    'quota' => $format($status['quota']),
                    'percent' => $status['percent'],
                    'bar' => $status['percent'] === null ? 0 : min(100, $status['percent']),
                    'level' => $status['level'],
                    'blocked' => $status['blocked'],
                    'message' => $service->levelMessage($status),
                    'breakdown' => $service->breakdown($status),
                    'dbTables' => array_map($format, $status['usage']['dbTables']),
                    'computedAt' => $status['usage']['computedAt'] ? date('Y-m-d H:i', $status['usage']['computedAt']) : null,
                    'url' => $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $context->getPath(), JournalQuotaPlugin::PAGE),
                    'custom' => $custom,
                    // Journal values suggested when "custom" is selected.
                    'values' => $service->ownSettings($context->getId()),
                ];
            }
        }

        // Each journal's share of total site usage, largest first.
        $total = array_sum($siteParts);
        usort($shares, fn ($a, $b) => $b['bytes'] <=> $a['bytes']);
        foreach ($shares as $i => &$share) {
            $share['size'] = $format($share['bytes']);
            $share['percent'] = $total > 0 ? round($share['bytes'] * 100 / $total, 1) : 0;
            $share['color'] = self::JOURNAL_COLORS[$i % count(self::JOURNAL_COLORS)];
        }
        unset($share);
        // The site-wide total includes all sections, even those that are
        // excluded from an individual journal's quota.
        $siteBreakdown = $service->breakdown([
            'usage' => ['parts' => $siteParts],
            'settings' => ['countPublic' => true, 'countDatabase' => true],
        ]);

        $defaults = $service->defaults();
        $templateMgr = TemplateManager::getManager($request);
        $this->addAssets($templateMgr);
        $templateMgr->assign([
            'pageTitle' => __('plugins.generic.journalQuota.overview.title'),
            'quotaMenu' => $menu,
            'quotaChoice' => $selected ? (string) $selected['id'] : ($choice === self::DEFAULTS ? self::DEFAULTS : ''),
            'quotaSiteBreakdown' => $siteBreakdown,
            'quotaShares' => $shares,
            'quotaStates' => $states,
            'quotaJournalCount' => count($shares),
            'quotaJournal' => $selected,
            'quotaDefaults' => $defaults,
            'quotaDefaultsSummary' => $this->summary($defaults),
            'quotaUsingDefaults' => implode(', ', $usingDefaults),
            'quotaScanHours' => $service->scanHours(),
            'quotaSiteTotal' => $format($total),
            'quotaSaved' => (bool) $request->getUserVar('saved'),
            'quotaProblem' => ($problem = $service->lastProblem())
                ? ['date' => date('Y-m-d H:i', (int) $problem['at']), 'message' => (string) $problem['message']]
                : null,
            'quotaPageUrl' => $this->pageUrl($request),
            'quotaSaveUrl' => $this->pageUrl($request, 'save'),
            'quotaFieldsTemplate' => $this->plugin->getTemplateResource('settingsFields.tpl'),
        ]);
        return $templateMgr->display($this->plugin->getTemplateResource('overview.tpl'));
    }

    /**
     * Save the selected journal's limits or the defaults, then return
     * to the same selection.
     *
     * @param array $args
     * @param PKPRequest $request
     */
    public function save($args, $request)
    {
        $choice = (string) $request->getUserVar('journal');
        if (!$request->isPost() || !$request->checkCSRF()) {
            $request->redirectUrl($this->pageUrl($request, null, ['journal' => $choice]));
            return;
        }
        $service = $this->plugin->service();
        $settings = $this->submitted($request);

        if ($choice === self::DEFAULTS) {
            $service->saveDefaults($settings);
            $service->saveScanHours((int) $request->getUserVar('scanHours'));
            // Defaults affect multiple journals: update all alerts and blocks.
            $this->recheck($this->contexts());
        } else {
            $context = Application::getContextDAO()->getById((int) $choice);
            if (!$context) {
                $request->redirectUrl($this->pageUrl($request));
                return;
            }
            $custom = $request->getUserVar('custom') === '1';
            $service->saveSettings($context->getId(), $settings, $custom);
            $this->recheck([$context]);
        }
        $request->redirectUrl($this->pageUrl($request, null, ['journal' => $choice, 'saved' => 1]));
    }

    /** Apply alerts and blocks immediately; measure only when no measurement exists */
    private function recheck(array $contexts): void
    {
        $service = $this->plugin->service();
        foreach ($contexts as $context) {
            try {
                $service->check($context, !$service->hasUsage($context));
            } catch (Throwable $e) {
                error_log("journalQuota: {$context->getPath()}: " . $e->getMessage());
            }
        }
    }

    /** Values submitted by the form */
    private function submitted(PKPRequest $request): array
    {
        $settings = [];
        foreach (QuotaService::DEFAULTS as $field => $default) {
            $value = $request->getUserVar($field);
            $settings[$field] = match (true) {
                // Unchecked checkboxes are not submitted.
                is_bool($default) => (bool) $value,
                is_int($default) => min(QuotaService::MAX_QUOTA_MB, max(0, (int) $value)),
                default => (string) $value,
            };
        }
        return $settings;
    }

    /** Menu option: name, usage, and status, for selection without opening */
    private function menuLabel(Context $context, array $status): string
    {
        $label = $context->getLocalizedName() . ' — ' . QuotaService::formatBytes($status['used']);
        if ($status['quota'] <= 0) {
            return $label . ' · ' . __('plugins.generic.journalQuota.overview.noLimit');
        }
        $label .= ' / ' . QuotaService::formatBytes($status['quota']) . " ({$status['percent']}%)";
        if ($status['blocked']) {
            $label .= ' · ' . __('plugins.generic.journalQuota.overview.blocked');
        } elseif ($status['level'] > QuotaService::LEVEL_OK) {
            $label .= ' · ' . __('plugins.generic.journalQuota.overview.warning');
        }
        return $label;
    }

    /** Default values summarized on one line */
    private function summary(array $settings): string
    {
        if ($settings['quotaMb'] <= 0) {
            return __('plugins.generic.journalQuota.overview.summary.noLimit');
        }
        return __('plugins.generic.journalQuota.overview.summary', [
            'quota' => QuotaService::formatBytes($settings['quotaMb'] * 1024 * 1024),
            'warn' => $settings['warnPct'],
            'mode' => $settings['mode'] === QuotaService::MODE_BLOCK
                ? __('plugins.generic.journalQuota.overview.summary.block')
                : __('plugins.generic.journalQuota.overview.summary.grace', ['tolerance' => $settings['tolerancePct']]),
        ]);
    }

    private function pageUrl(PKPRequest $request, ?string $op = null, array $params = []): string
    {
        $params = array_filter($params, fn ($value) => $value !== '' && $value !== null);
        return $request->getDispatcher()->url($request, Application::ROUTE_PAGE, 'index', JournalQuotaPlugin::PAGE, $op, null, $params ?: null);
    }

    private function contexts(): array
    {
        return Application::getContextDAO()->getAll()->toArray();
    }

    /**
     * Page styles and scripts belong in the header: the OJS dashboard
     * is a Vue app that discards <style> and <script> inside page content.
     */
    private function addAssets(TemplateManager $templateMgr): void
    {
        $templateMgr->addStyleSheet('journalQuotaOverview', <<<'CSS'
            .jq-page { max-width: 60rem; }
            .jq-picker { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem 0.75rem; background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 1rem 1.25rem; margin-bottom: 1.25rem; }
            .jq-picker label { font-weight: bold; }
            .jq-picker select { flex: 1 1 22rem; max-width: 40rem; font-size: 1rem; padding: 0.4rem; }
            .jq-js .jq-picker-go { display: none; }
            .jq-card { background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 1.25rem 1.5rem; margin-bottom: 1.25rem; }
            .jq-card h2 { margin: 0 0 0.75rem; font-size: 1.25rem; }
            .jq-card h3 { margin: 1.25rem 0 0.5rem; font-size: 1rem; }
            .jq-muted { color: #555; }
            .jq-problem { background: #fff8e1; border: 1px solid #ffcc80; border-radius: 4px; padding: 0.6rem 1rem; margin-bottom: 1.25rem; }
            .jq-problem code { word-break: break-all; }
            .jq-saved { background: #e8f5e9; border: 1px solid #a5d6a7; border-radius: 4px; padding: 0.6rem 1rem; margin-bottom: 1.25rem; }
            .jq-big { font-size: 1.5rem; font-weight: bold; }
            .jq-meter { height: 0.9rem; background: #eee; border-radius: 4px; overflow: hidden; margin: 0.5rem 0; }
            .jq-meter > div { height: 100%; background: #1e6fa8; }
            .jq-level-1 .jq-meter > div { background: #d4a000; }
            .jq-level-2 .jq-meter > div, .jq-level-3 .jq-meter > div { background: #c62828; }
            .jq-level-1 .jq-status { color: #8a6d00; }
            .jq-level-2 .jq-status, .jq-level-3 .jq-status { color: #b00; }
            .jq-stack { display: flex; height: 0.75rem; border-radius: 3px; overflow: hidden; background: #eee; margin: 0.25rem 0 0.5rem; }
            .jq-legend { display: flex; flex-wrap: wrap; gap: 0.25rem 1.25rem; margin: 0; padding: 0; list-style: none; font-size: 0.875rem; }
            .jq-dot { display: inline-block; width: 0.7rem; height: 0.7rem; border-radius: 2px; margin-right: 0.3rem; vertical-align: -1px; }
            .jq-faded { opacity: 0.4; }
            .jq-legend a { color: inherit; }
            .jq-states { display: flex; flex-wrap: wrap; gap: 0.75rem; margin: 0; padding: 0; list-style: none; }
            .jq-states li { flex: 1 1 12rem; border: 1px solid #e3e3e3; border-left-width: 4px; border-radius: 4px; padding: 0.5rem 0.75rem; font-size: 0.875rem; }
            .jq-states strong { display: block; font-size: 1.25rem; }
            .jq-state-blocked { border-left-color: #c62828 !important; }
            .jq-state-warning { border-left-color: #d4a000 !important; }
            .jq-state-ok { border-left-color: #2e8b57 !important; }
            .jq-state-noLimit { border-left-color: #8a8a8a !important; }
            .jq-choice { display: block; margin: 0.4rem 0; }
            .jq-choice small { display: block; margin-left: 1.5rem; color: #555; }
            .jq-fields { border: 1px solid #e3e3e3; border-radius: 4px; padding: 0.75rem 1rem; margin: 0.75rem 0 0; }
            .jq-limits:has(input[name="custom"][value="0"]:checked) .jq-fields { display: none; }
            .jq-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(14rem, 1fr)); gap: 0.75rem 1.25rem; }
            .jq-grid label, .jq-checks label { display: block; font-size: 0.875rem; }
            .jq-grid input, .jq-grid select { display: block; width: 100%; box-sizing: border-box; margin-top: 0.2rem; padding: 0.35rem 0.5rem; border: 1px solid #bbb; border-radius: 3px; background: #fff; font-size: 0.875rem; height: 2.25rem; }
            .jq-wide { grid-column: span 2; }
            @media (max-width: 40rem) { .jq-wide { grid-column: auto; } }
            .jq-picker select { border: 1px solid #bbb; border-radius: 3px; background: #fff; }
            .jq-button { padding: 0.5rem 1.25rem; border: 1px solid #006798; border-radius: 3px; background: #006798; color: #fff; font-weight: bold; cursor: pointer; }
            .jq-button:hover, .jq-button:focus { background: #004f75; }
            .jq-button--plain { background: #fff; color: #006798; }
            .jq-checks { margin: 1rem 0 0; }
            .jq-checks label { margin: 0.3rem 0; }
            .jq-actions { margin-top: 1.25rem; display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; }
            .jq-page details { margin-top: 0.5rem; font-size: 0.875rem; color: #555; }
            CSS, ['inline' => true, 'contexts' => 'backend']);

        // The menu navigates as soon as the selection changes. Delegate to
        // the document because Vue recreates page elements after loading.
        $templateMgr->addJavaScript('journalQuotaOverview', <<<'JS'
            document.documentElement.classList.add('jq-js');
            document.addEventListener('change', function (e) {
                if (e.target && e.target.id === 'jqJournal' && e.target.form) {
                    e.target.form.submit();
                }
            });
            JS, ['inline' => true, 'contexts' => 'backend']);
    }
}
