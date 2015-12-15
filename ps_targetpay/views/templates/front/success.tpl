{capture name=path}<a href="order.php">{l s='Winkelwagentje' mod='ps_targetpay'}</a><span class="navigation-pipe"> {$navigationPipe} </span> {l s='Targetpay' mod='ps_targetpay'}{/capture}
{include file="$tpl_dir./breadcrumb.tpl"}

<h2>{$message}</h2>

<style>
.success ol {
	margin-left:20px
}
</style>
{if isset($logs) && $logs}
	<div class="success">
		<p><b>{l s='Betaling is gelukt:' mod='ps_targetpay'}</b></p>

		<ol>
		{foreach from=$logs key=key item=log}
			<li>{$log}</li>
		{/foreach}
		</ol>

		<br>

		{if isset($order)}
			<p>
				{l s='Totaalbedrag (incl. belasting) :' mod='ps_targetpay'} <span class="bold">{$price}</span><br>
				{l s='Je betaalkenmerk is:' mod='ps_targetpay'} <span class="bold">{$order.transaction_id}</span><br>
			</p>
		{/if}

		<p><a href="{$base_dir}" class="button_small" title="{l s='Terug' mod='ps_targetpay'}">&laquo; {l s='Terug' mod='ps_targetpay'}</a></p>
	</div>

{/if}
