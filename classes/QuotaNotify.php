<?php
/*
 * classes/QuotaNotify.php
 * Copyright (C) 2026 Stefano Natangelo
 * SPDX-License-Identifier: AGPL-3.0-only
 */

/**
 * @file plugins/generic/journalQuota/classes/QuotaNotify.php
 *
 * @class QuotaNotify
 *
 * @brief Email ai journal manager quando lo spazio della rivista supera una
 *  soglia. Il testo si modifica in Workflow > Email.
 */

namespace APP\plugins\generic\journalQuota\classes;

use PKP\context\Context;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;
use PKP\mail\traits\Recipient;
use PKP\security\Role;

class QuotaNotify extends Mailable
{
    use Recipient;
    use Configurable;

    public const QUOTA_USED = 'quotaUsed';
    public const QUOTA_LIMIT = 'quotaLimit';
    public const QUOTA_PERCENT = 'quotaPercent';
    public const QUOTA_MESSAGE = 'quotaMessage';
    public const QUOTA_URL = 'quotaUrl';

    protected static ?string $name = 'plugins.generic.journalQuota.email.name';
    protected static ?string $description = 'plugins.generic.journalQuota.email.description';
    protected static ?string $emailTemplateKey = 'JOURNAL_QUOTA_NOTIFY';
    protected static array $groupIds = [self::GROUP_OTHER];
    protected static array $fromRoleIds = [self::FROM_SYSTEM];
    protected static array $toRoleIds = [Role::ROLE_ID_MANAGER];

    public function __construct(Context $context, array $variables = [])
    {
        parent::__construct([$context]);
        $this->addData($variables);
    }

    public static function getDataDescriptions(): array
    {
        return array_merge(parent::getDataDescriptions(), [
            self::QUOTA_USED => __('plugins.generic.journalQuota.variable.used'),
            self::QUOTA_LIMIT => __('plugins.generic.journalQuota.variable.limit'),
            self::QUOTA_PERCENT => __('plugins.generic.journalQuota.variable.percent'),
            self::QUOTA_MESSAGE => __('plugins.generic.journalQuota.variable.message'),
            self::QUOTA_URL => __('plugins.generic.journalQuota.variable.url'),
        ]);
    }
}
