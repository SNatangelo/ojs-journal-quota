{**
 * templates/breakdown.tpl
 * Copyright (C) 2026 Stefano Natangelo
 * SPDX-License-Identifier: AGPL-3.0-only
 *
 * Ripartizione dello spazio di una rivista: barra a colori e legenda.
 * Parametri: $breakdown (da QuotaService::breakdown()), $dbTables (tabella => dimensione).
 *}
<div class="jq-stack" role="img" aria-label="{translate key="plugins.generic.journalQuota.page.breakdown"}" style="display: flex; height: 1.5rem; border-radius: 4px; overflow: hidden; background: #eee; margin-bottom: 0.75rem;">
	{foreach from=$breakdown item=part}
		{if $part.bytes > 0}
			<div title="{$part.label|escape}: {$part.size|escape} ({$part.percent}%)" style="width: {$part.percent|string_format:"%.2f"}%; min-width: 2px; background: {$part.color}; {if !$part.counted}opacity: 0.4;{/if}"></div>
		{/if}
	{/foreach}
</div>
<table class="pkpTable" style="width: 100%; margin-bottom: 1rem;">
	<tbody>
		{foreach from=$breakdown item=part}
			<tr>
				<td>
					<span aria-hidden="true" style="display: inline-block; width: 0.75rem; height: 0.75rem; border-radius: 2px; background: {$part.color}; {if !$part.counted}opacity: 0.4;{/if} margin-right: 0.4rem;"></span>
					{$part.label|escape}{if !$part.counted} <em>({translate key="plugins.generic.journalQuota.page.notCounted"})</em>{/if}
					{if $part.key == 'database' && $dbTables}
						<details style="margin: 0.25rem 0 0 1.15rem; color: #555; font-size: 0.875rem;">
							<summary>{translate key="plugins.generic.journalQuota.page.dbTables"}</summary>
							<ul style="margin: 0.25rem 0;">
								{foreach from=$dbTables key=table item=size}
									<li><code>{$table|escape}</code>: {$size|escape}</li>
								{/foreach}
							</ul>
						</details>
					{/if}
				</td>
				<td style="text-align: right; white-space: nowrap;">{$part.size|escape}</td>
				<td style="text-align: right; width: 4.5rem; color: #555;">{$part.percent}%</td>
			</tr>
		{/foreach}
	</tbody>
</table>
