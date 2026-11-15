{**
 * plugins/generic/journalQuota/templates/overview.tpl
 *
 * Pagina dell'amministratore: si sceglie una rivista dal menu e si vedono solo
 * il suo spazio e i suoi limiti; dallo stesso menu si aprono i valori
 * predefiniti. Stili e script sono nell'intestazione (QuotaSiteHandler):
 * il pannello di OJS è un'app Vue che scarta <style> e <script> nella pagina.
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
<div class="jq-page" v-pre>
	<h1 class="app__pageHeading">{translate key="plugins.generic.journalQuota.overview.title"}</h1>

	<form class="jq-picker" method="get" action="{$quotaPageUrl|escape}">
		<label for="jqJournal">{translate key="plugins.generic.journalQuota.overview.select"}</label>
		<select id="jqJournal" name="journal">
			<option value=""{if !$quotaChoice} selected{/if}>{translate key="plugins.generic.journalQuota.overview.all"}</option>
			<option disabled>──────────</option>
			{foreach from=$quotaMenu item=item}
				<option value="{$item.id|escape}"{if $quotaChoice === $item.id} selected{/if}>{$item.label|escape}</option>
			{/foreach}
			<option disabled>──────────</option>
			<option value="defaults"{if $quotaChoice === 'defaults'} selected{/if}>{translate key="plugins.generic.journalQuota.overview.defaults"}</option>
		</select>
		<button type="submit" class="jq-button jq-button--plain jq-picker-go">{translate key="plugins.generic.journalQuota.overview.show"}</button>
	</form>

	{if $quotaProblem}
		<div class="jq-problem" role="alert">
			{translate key="plugins.generic.journalQuota.overview.problem" date=$quotaProblem.date|escape}
			<br><code>{$quotaProblem.message|escape}</code>
		</div>
	{/if}

	{if $quotaSaved}
		<p class="jq-saved" role="status">{translate key="plugins.generic.journalQuota.overview.saved"}</p>
	{/if}

	{if $quotaJournal}
		{assign var=journal value=$quotaJournal}

		<section class="jq-card jq-level-{$journal.level}">
			<h2>{translate key="plugins.generic.journalQuota.overview.space" journal=$journal.name|escape}</h2>
			{if $journal.percent !== null}
				<div><span class="jq-big">{$journal.used|escape}</span> <span class="jq-muted">{translate key="plugins.generic.journalQuota.overview.of" quota=$journal.quota} ({$journal.percent}%)</span></div>
				<div class="jq-meter" role="img" aria-label="{$journal.percent}%"><div style="width: {$journal.bar|string_format:"%.1f"}%;"></div></div>
				<p class="jq-status">{$journal.message|escape}</p>
			{else}
				<div><span class="jq-big">{$journal.used|escape}</span> <span class="jq-muted">· {translate key="plugins.generic.journalQuota.overview.noLimit"}</span></div>
			{/if}

			<h3>{translate key="plugins.generic.journalQuota.page.breakdown"}</h3>
			<div class="jq-stack" role="img" aria-label="{translate key="plugins.generic.journalQuota.page.breakdown"}">
				{foreach from=$journal.breakdown item=part}
					{if $part.bytes > 0}
						<div title="{$part.label|escape}: {$part.size|escape}" class="{if !$part.counted}jq-faded{/if}" style="width: {$part.percent|string_format:"%.2f"}%; min-width: 2px; background: {$part.color};"></div>
					{/if}
				{/foreach}
			</div>
			<ul class="jq-legend">
				{foreach from=$journal.breakdown item=part}
					{if $part.bytes > 0}
						<li><span class="jq-dot{if !$part.counted} jq-faded{/if}" style="background: {$part.color};"></span>{$part.label|escape} <strong>{$part.size|escape}</strong>{if !$part.counted} <em class="jq-muted">({translate key="plugins.generic.journalQuota.page.notCounted"})</em>{/if}</li>
					{/if}
				{/foreach}
			</ul>
			{if $journal.dbTables}
				<details>
					<summary>{translate key="plugins.generic.journalQuota.page.dbTables"}</summary>
					<ul>
						{foreach from=$journal.dbTables key=table item=size}
							<li><code>{$table|escape}</code>: {$size|escape}</li>
						{/foreach}
					</ul>
				</details>
			{/if}
			<p class="jq-muted" style="margin-bottom: 0;">
				{if $journal.computedAt}{translate key="plugins.generic.journalQuota.page.computedAt" date=$journal.computedAt}{else}{translate key="plugins.generic.journalQuota.page.notMeasured"}{/if}
				· <a href="{$journal.url|escape}">{translate key="plugins.generic.journalQuota.overview.open"}</a>
			</p>
		</section>

		<form class="jq-card jq-limits" method="post" action="{$quotaSaveUrl|escape}">
			{csrf}
			<input type="hidden" name="journal" value="{$journal.id|escape}">
			<h2>{translate key="plugins.generic.journalQuota.overview.limits"}</h2>
			<label class="jq-choice">
				<input type="radio" name="custom" value="0"{if !$journal.custom} checked{/if}>
				{translate key="plugins.generic.journalQuota.overview.useDefaults"}
				<small>{$quotaDefaultsSummary|escape}</small>
			</label>
			<label class="jq-choice">
				<input type="radio" name="custom" value="1"{if $journal.custom} checked{/if}>
				{translate key="plugins.generic.journalQuota.overview.useCustom"}
			</label>
			<div class="jq-fields">
				{include file=$quotaFieldsTemplate values=$journal.values}
			</div>
			<div class="jq-actions">
				<button type="submit" class="jq-button">{translate key="common.save"}</button>
			</div>
		</form>

	{elseif $quotaChoice === 'defaults'}

		<form class="jq-card" method="post" action="{$quotaSaveUrl|escape}">
			{csrf}
			<input type="hidden" name="journal" value="defaults">
			<h2>{translate key="plugins.generic.journalQuota.overview.defaults"}</h2>
			<p class="jq-muted">
				{translate key="plugins.generic.journalQuota.settings.defaults.description"}
				{if $quotaUsingDefaults}
					<br>{translate key="plugins.generic.journalQuota.overview.usedBy" journals=$quotaUsingDefaults|escape}
				{else}
					<br>{translate key="plugins.generic.journalQuota.overview.usedByNone"}
				{/if}
			</p>
			{include file=$quotaFieldsTemplate values=$quotaDefaults}
			<h3>{translate key="plugins.generic.journalQuota.overview.scan"}</h3>
			<div class="jq-grid">
				<label>{translate key="plugins.generic.journalQuota.settings.scanHours"}
					<input type="number" min="1" step="1" name="scanHours" value="{$quotaScanHours|escape}">
				</label>
			</div>
			<p class="jq-muted">{translate key="plugins.generic.journalQuota.settings.scanHours.description"}</p>
			<div class="jq-actions">
				<button type="submit" class="jq-button">{translate key="common.save"}</button>
			</div>
		</form>

	{else}

		<section class="jq-card">
			<h2>{translate key="plugins.generic.journalQuota.overview.site"}</h2>
			<div><span class="jq-big">{$quotaSiteTotal|escape}</span> <span class="jq-muted">{translate key="plugins.generic.journalQuota.overview.siteOf" number=$quotaJournalCount}</span></div>

			<h3>{translate key="plugins.generic.journalQuota.overview.status"}</h3>
			<ul class="jq-states">
				{foreach from=['blocked', 'warning', 'ok', 'noLimit'] item=state}
					{if $quotaStates[$state]}
						<li class="jq-state-{$state}">
							<strong>{$quotaStates[$state]|@count}</strong>
							{translate key="plugins.generic.journalQuota.overview.state.$state"}:
							{foreach from=$quotaStates[$state] item=link name=links}<a href="{$link.url|escape}">{$link.name|escape}</a>{if !$smarty.foreach.links.last}, {/if}{/foreach}
						</li>
					{/if}
				{/foreach}
			</ul>

			<h3>{translate key="plugins.generic.journalQuota.overview.byJournal"}</h3>
			<div class="jq-stack" role="img" aria-label="{translate key="plugins.generic.journalQuota.overview.byJournal"}">
				{foreach from=$quotaShares item=share}
					{if $share.bytes > 0}
						<div title="{$share.name|escape}: {$share.size|escape}" style="width: {$share.percent|string_format:"%.2f"}%; min-width: 2px; background: {$share.color};"></div>
					{/if}
				{/foreach}
			</div>
			<ul class="jq-legend">
				{foreach from=$quotaShares item=share}
					<li><span class="jq-dot" style="background: {$share.color};"></span><a href="{$share.url|escape}">{$share.name|escape}</a> <strong>{$share.size|escape}</strong> <span class="jq-muted">({$share.percent}%)</span></li>
				{/foreach}
			</ul>

			<h3>{translate key="plugins.generic.journalQuota.page.breakdown"}</h3>
			<div class="jq-stack" role="img" aria-label="{translate key="plugins.generic.journalQuota.page.breakdown"}">
				{foreach from=$quotaSiteBreakdown item=part}
					{if $part.bytes > 0}
						<div title="{$part.label|escape}: {$part.size|escape}" style="width: {$part.percent|string_format:"%.2f"}%; min-width: 2px; background: {$part.color};"></div>
					{/if}
				{/foreach}
			</div>
			<ul class="jq-legend">
				{foreach from=$quotaSiteBreakdown item=part}
					{if $part.bytes > 0}
						<li><span class="jq-dot" style="background: {$part.color};"></span>{$part.label|escape} <strong>{$part.size|escape}</strong></li>
					{/if}
				{/foreach}
			</ul>
			<p class="jq-muted" style="margin-bottom: 0;">{translate key="plugins.generic.journalQuota.overview.intro"}</p>
		</section>

	{/if}
</div>
{/block}
