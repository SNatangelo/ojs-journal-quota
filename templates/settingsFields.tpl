{**
 * templates/settingsFields.tpl
 * Copyright (C) 2026 Stefano Natangelo
 * SPDX-License-Identifier: AGPL-3.0-only
 *
 * Campi dei limiti di una rivista o dei valori predefiniti. Parametro: $values.
 *}
<div class="jq-grid">
	<label>{translate key="plugins.generic.journalQuota.settings.quotaMb"}
		<input type="number" min="0" step="1" name="quotaMb" value="{$values.quotaMb|escape}">
	</label>
	<label class="jq-wide">{translate key="plugins.generic.journalQuota.settings.mode"}
		<select name="mode">
			<option value="grace"{if $values.mode == 'grace'} selected{/if}>{translate key="plugins.generic.journalQuota.settings.mode.grace"}</option>
			<option value="block"{if $values.mode == 'block'} selected{/if}>{translate key="plugins.generic.journalQuota.settings.mode.block"}</option>
		</select>
	</label>
	<label>{translate key="plugins.generic.journalQuota.settings.warnPct"}
		<input type="number" min="1" max="100" step="1" name="warnPct" value="{$values.warnPct|escape}">
	</label>
	<label>{translate key="plugins.generic.journalQuota.settings.tolerancePct"}
		<input type="number" min="0" step="1" name="tolerancePct" value="{$values.tolerancePct|escape}">
	</label>
</div>

<details>
	<summary>{translate key="plugins.generic.journalQuota.settings.more"}</summary>
	<div class="jq-checks">
		<strong>{translate key="plugins.generic.journalQuota.settings.notify"}</strong>
		<label><input type="checkbox" name="notifyWarn" value="1"{if $values.notifyWarn} checked{/if}> {translate key="plugins.generic.journalQuota.settings.notifyWarn"}</label>
		<label><input type="checkbox" name="notifyQuota" value="1"{if $values.notifyQuota} checked{/if}> {translate key="plugins.generic.journalQuota.settings.notifyQuota"}</label>
		<label><input type="checkbox" name="notifyTolerance" value="1"{if $values.notifyTolerance} checked{/if}> {translate key="plugins.generic.journalQuota.settings.notifyTolerance"}</label>
	</div>
	<div class="jq-grid" style="margin-top: 0.75rem;">
		<label>{translate key="plugins.generic.journalQuota.settings.reminderDays"}
			<input type="number" min="0" step="1" name="reminderDays" value="{$values.reminderDays|escape}">
		</label>
	</div>
	<div class="jq-checks">
		<strong>{translate key="plugins.generic.journalQuota.settings.counted"}</strong>
		<label><input type="checkbox" name="countPublic" value="1"{if $values.countPublic} checked{/if}> {translate key="plugins.generic.journalQuota.settings.countPublic"}</label>
		<label><input type="checkbox" name="countDatabase" value="1"{if $values.countDatabase} checked{/if}> {translate key="plugins.generic.journalQuota.settings.countDatabase"}</label>
	</div>
</details>
