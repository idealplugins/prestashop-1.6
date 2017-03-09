<!-- iDEAL -->
<div class="row">
    <div class="col-xs-12 col-md-6">
        <p class="payment_module">
            <a href="#" id="ideal-toggle" class="ideal" title="iDEAL">
            <img  src="{$this_path}/views/img/{$method}_50.png"/>
            </a>
        </p>
    </div>

    <div class="col-xs-12 col-md-6" id="ideal-bankselect">
        <p class="payment_module">
        {foreach from=$idealBankListArr key=k item=v}
            <a class="bank" href="index.php?fc=module&module=ps_targetpay&controller=payment&bankID={$k}" style="background-image: url(https://www.targetpay.com/gfx/banks/{$k}.png);">
                {$v}
            </a>
        {/foreach}
        </p>
    </div>
</div>