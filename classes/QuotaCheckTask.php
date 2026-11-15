<?php

/**
 * @file plugins/generic/journalQuota/classes/QuotaCheckTask.php
 *
 * @class QuotaCheckTask
 *
 * @brief Attività pianificata (ogni ora): allinea avvisi ed email al totale
 *  salvato di ogni rivista e, ogni scanHours ore, rifà la misura completa.
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
        // Il pianificatore può caricare l'attività anche a plugin disattivato
        if (!$this->plugin->getEnabled(Application::SITE_CONTEXT_ID)) {
            return true;
        }
        $service = $this->plugin->service();
        $contexts = Application::getContextDAO()->getAll();
        $ok = true;
        while ($context = $contexts->next()) {
            try {
                // Avvisi ogni ora sul totale salvato; misura completa solo quando è
                // ora (null: un altro processo sta già controllando la rivista)
                $service->check($context, $service->fullScanDue($context));
            } catch (Throwable $e) {
                $this->addExecutionLogEntry(
                    "{$context->getPath()}: {$e->getMessage()}",
                    ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_ERROR
                );
                $ok = false;
            }
        }
        // false fa arrivare il resoconto degli errori all'amministratore
        return $ok;
    }
}
