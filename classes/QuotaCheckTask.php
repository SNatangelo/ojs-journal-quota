<?php
/*
 * classes/QuotaCheckTask.php
 * Copyright (C) 2026 Stefano Natangelo
 * SPDX-License-Identifier: AGPL-3.0-only
 */

/**
 * @file plugins/generic/journalQuota/classes/QuotaCheckTask.php
 *
 * @class QuotaCheckTask
 *
 * @brief Hourly scheduled task: synchronizes alerts and emails with
 *  each journal's stored usage and performs a full scan every scanHours hours.
 */

namespace APP\plugins\generic\journalQuota\classes;

use APP\core\Application;
use APP\plugins\generic\journalQuota\JournalQuotaPlugin;
use PKP\scheduledTask\ScheduledTask;
use PKP\scheduledTask\ScheduledTaskHelper;
use Throwable;

class QuotaCheckTask extends ScheduledTask
{
    public function __construct(private JournalQuotaPlugin $plugin)
    {
        parent::__construct();
    }

    public function getName(): string
    {
        return __('plugins.generic.journalQuota.task');
    }

    protected function executeActions(): bool
    {
        // The scheduler may load this task even when the plugin is disabled.
        if (!$this->plugin->getEnabled(Application::SITE_CONTEXT_ID)) {
            return true;
        }
        $service = $this->plugin->service();
        $contexts = Application::getContextDAO()->getAll();
        $ok = true;
        while ($context = $contexts->next()) {
            try {
                // Check alerts hourly against stored usage; run a full scan only when
                // due (null means another process is already checking the journal).
                $service->check($context, $service->fullScanDue($context));
            } catch (Throwable $e) {
                $this->addExecutionLogEntry(
                    "{$context->getPath()}: {$e->getMessage()}",
                    ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_ERROR
                );
                $ok = false;
            }
        }
        // Returning false sends the error report to the administrator.
        return $ok;
    }
}
