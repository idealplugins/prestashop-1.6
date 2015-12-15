<style>
p.payment_module a.ideal { background: url({$this_path}method-ide.png) 15px 15px no-repeat #fbfbfb; }
p.payment_module a.sofort { background: url({$this_path}method-deb.png) 15px 15px no-repeat #fbfbfb; }
p.payment_module a.mistercash { background: url({$this_path}method-mrc.png) 15px 15px no-repeat #fbfbfb; }

p.payment_module a.bank { 
	background: url() 10px 15px no-repeat #fbfbfb; 
	width: 48%;
	display: inline-block;
	margin-bottom: 5px;
	padding: 33px 25px 34px 85px;
	font-size: 16px;
}

p.payment_module a.ideal:after, p.payment_module a.mistercash:after, p.payment_module a.sofort:after {
	display: block;
    content: "\f054";
    position: absolute;
    right: 15px;
    margin-top: -11px;
    top: 50%;
    font-family: "FontAwesome";
    font-size: 25px;
    height: 22px;
    width: 14px;
    color: #777777; 
}

p.payment_module a.ideal:hover, p.payment_module a.mistercash:hover, p.payment_module a.sofort:hover, p.payment_module a.bank:hover {
	background-color: #f6f6f6; 
}

#ideal-bankselect, #sofort-bankselect {
	display: none;
}
</style>

<script>
jQuery(document).ready(function() {
  	jQuery('#ideal-toggle').click(function(){ jQuery('#ideal-bankselect').toggle(); return false;});
  	jQuery('#sofort-toggle').click(function(){ jQuery('#sofort-bankselect').toggle(); return false;});
});
</script>

<!-- iDEAL -->

<div class="row">
	<div class="col-xs-12 col-md-6">
        <p class="payment_module">
            <a href="#" id="ideal-toggle" class="ideal" title="iDEAL">
                {l s='Betaal via iDEAL' mod='ps_targetpay'} 
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