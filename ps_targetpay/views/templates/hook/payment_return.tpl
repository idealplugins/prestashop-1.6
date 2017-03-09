{if $status == 'ok'}
    <p class="alert alert-success">{l s='Your order on %s is complete.' sprintf=$shop_name mod='ps_targetpay'}</p>
    <div class="box">
        {l s='Your order information:' mod='ps_targetpay'}
        <br />- {l s='Order number' mod='ps_targetpay'} <strong>{$id_order}</strong>
        <br />- {l s='Amount' mod='ps_targetpay'} <span class="price"><strong>{$total}</strong></span>
        <br /> <strong>{l s='Your order will be sent as soon as we receive payment.' mod='ps_targetpay'}</strong>
        <br /> {l s='Thank you for shopping. While logged in, you may continue shopping or view your current order status and order history.' mod='ps_targetpay'}
    </div>
{else}
    <p class="alert alert-warning">Your order on Presta Shop 1.6 is failed.</p>
    <div class="box">
        {l s='Your order information:' mod='ps_targetpay'}
        <br />- {l s='Order number' mod='ps_targetpay'} <strong>{$id_order}</strong>
        <br />- {l s='Amount' mod='ps_targetpay'} <span class="price"><strong>{$total}</strong></span>
        <br /><strong>{l s='We noticed a problem with your order.' mod='ps_targetpay'}</strong>
        <br />{l s='If you want to reorder ' mod='ps_targetpay'}
        <a class="link-button" href="{$link->getPageLink('order', true, NULL, "submitReorder&id_order={$id_order|intval}")|escape:'html':'UTF-8'}" title="{l s='Reorder'}">{l s='click here' mod='ps_targetpay'}</a>.
    </div>
{/if}
