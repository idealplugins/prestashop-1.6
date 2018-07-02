<!-- iDEAL -->
{if $listMode == 1}
<div class="row">
    <div class="col-xs-12 col-md-6">
        <p class="payment_module">
            <a href="index.php?fc=module&module=ps_targetpay&controller=payment&method={$method}" class="tp_method" title="iDEAL">
            <img  src="{$this_path}/views/img/{$method}_50.png"/>
            </a>
        </p>
    </div>
</div>
{else}
<div class="row">
    <div class="col-xs-12 col-md-6">
        <p class="payment_module">
            <a href="#" id="ideal-toggle" class="tp_method" title="iDEAL">
            <img  src="{$this_path}/views/img/{$method}_50.png"/>
            </a>
        </p>
    </div>

    <div class="col-xs-12 col-md-6" id="ideal-bankselect">
        <p class="payment_module">
        {foreach from=$idealBankListArr key=k item=v}
            <a class="bank" href="index.php?fc=module&module=ps_targetpay&controller=payment&method={$method}&option={$k}">
                <img src="https://transaction.digiwallet.nl/gfx/banks/ide_v3_bankselect/{$k}.png">
            </a>
        {/foreach}
        </p>
    </div>
</div>
{/if}