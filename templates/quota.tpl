{**
 * plugins/generic/journalQuota/templates/quota.tpl
 *
 * Pagina "Spazio" dei journal manager.
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
<div v-pre>
	<h1 class="app__pageHeading">{translate key="plugins.generic.journalQuota.page.title"}</h1>

	<div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 1.5rem; margin-bottom: 1.5rem;">
		{if $quotaStatus.quota > 0}
			<p style="font-size: 1.25rem; margin-top: 0;">
				{translate key="plugins.generic.journalQuota.page.used" used=$quotaUsed quota=$quotaLimit percent=$quotaStatus.percent}
			</p>
			<div style="height: 1.25rem; background: #eee; border-radius: 0.625rem; overflow: hidden;" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{$quotaBarWidth|string_format:"%d"}">
				<div style="height: 100%; width: {$quotaBarWidth|string_format:"%.1f"}%; background: {if $quotaStatus.level >= 2 || $quotaStatus.blocked}#b00{elseif $quotaStatus.level == 1}#d89c00{else}#2e7d32{/if};"></div>
			</div>
			<p style="font-weight: bold; color: {if $quotaStatus.level >= 2 || $quotaStatus.blocked}#b00{elseif $quotaStatus.level == 1}#8a6d00{else}#2e7d32{/if};">
				{$quotaMessage|escape}
			</p>
			<ul>
				<li>{translate key="plugins.generic.journalQuota.page.warnAt" limit=$quotaWarnLimit percent=$quotaStatus.settings.warnPct}</li>
				<li>{translate key="plugins.generic.journalQuota.page.quota" limit=$quotaLimit}</li>
				<li>{translate key="plugins.generic.journalQuota.page.blockAt" limit=$quotaBlockLimit}</li>
			</ul>
		{else}
			<p style="font-size: 1.25rem; margin-top: 0;">
				{translate key="plugins.generic.journalQuota.page.noLimit" used=$quotaUsed}
			</p>
		{/if}
		<p style="color: #555; margin-bottom: 0;">
			{if $quotaComputedAt}{translate key="plugins.generic.journalQuota.page.computedAt" date=$quotaComputedAt}{else}{translate key="plugins.generic.journalQuota.page.notMeasured"}{/if}
			<form method="post" action="{$quotaRefreshUrl|escape}" style="display: inline;">
				{csrf}
				<input type="hidden" name="refresh" value="1">
				<button type="submit" style="background: none; border: 0; padding: 0; color: #006798; text-decoration: underline; cursor: pointer; font: inherit;">{translate key="plugins.generic.journalQuota.page.refresh"}</button>
			</form>
		</p>
	</div>

	<h2>{translate key="plugins.generic.journalQuota.page.breakdown"}</h2>
	{include file=$quotaBreakdownTemplate breakdown=$quotaBreakdown dbTables=$quotaDbTables}

	{if $quotaTop}
		<h2>{translate key="plugins.generic.journalQuota.page.top"}</h2>
		<table class="pkpTable" style="width: 100%;">
			<tbody>
				{foreach from=$quotaTop item=submission}
					<tr>
						<td style="width: 4rem;">{$submission.id|escape}</td>
						<td>{if $submission.url}<a href="{$submission.url|escape}">{$submission.title|strip_tags|escape}</a>{else}{$submission.title|escape}{/if}</td>
						<td style="text-align: right;">{$submission.size|escape}</td>
					</tr>
				{/foreach}
			</tbody>
		</table>
	{/if}

	<p style="color: #555;">{translate key="plugins.generic.journalQuota.page.help"}</p>
	{if $quotaOverviewUrl}
		<p><a href="{$quotaOverviewUrl|escape}">{translate key="plugins.generic.journalQuota.overview.link"}</a></p>
	{/if}
</div>
{/block}
