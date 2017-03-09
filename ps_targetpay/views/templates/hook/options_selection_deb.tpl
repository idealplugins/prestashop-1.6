<div class="row">
    <div class="col-xs-12 col-md-6">
        <p class="payment_module">
            <a href="#" id="sofort-toggle" class="sofort" title="Sofort Banking">
            <img  src="{$this_path}/views/img/{$method}_50.png"/>
            </a>
        </p>
    </div>

	<div class="col-xs-12 col-md-6" id="sofort-bankselect">
        <p class="payment_module">
            {foreach from=$directEBankingBankListArr key=k item=v}
            <a class="bank" href="index.php?fc=module&module=ps_targetpay&controller=payment&bankID={$k}" style="padding-left: 20px">
                {$v}
            </a>
            {/foreach}
        </p>
    </div>
</div>

