<div class="bankwire-info">
    <h2>{l s='Thank you for ordering in our webshop!' mod='ps_targetpay'}</h2>
    <p>
        {l s='You will receive your order as soon as we receive payment from the bank.' mod='ps_targetpay'}
        <br>
        {l s='Would you be so friendly to transfer the total amount of €%s to the bankaccount [1]%s[/1] in name of %s*?' sprintf=[$order_total, $bw_info[2], $bw_info[4]] tags=['<b style="color:#c00000">'] mod='ps_targetpay'}
    </p>
    <p>
        {l s='State the payment feature [1]%s[/1], this way the payment can be automatically processed.' sprintf=$bw_info[0] tags=['<b>'] mod='ps_targetpay'}
        <br>
        {l s='As soon as this happens you shall receive a confirmation mail on %s' sprintf=$customer_email mod='ps_targetpay'}
    </p>
    <p>
    {l s='If it is necessary for payments abroad, then the BIC code from the bank [1]%s[/1] and the name of the bank is %s.' sprintf=[$bw_info[3], $bw_info[5]] tags=['<span style="color:#c00000">'] mod='ps_targetpay'}</p>
    <p>
        <i>* {l s='Payment for our webstore is processed by TargetMedia. TargetMedia is certified as a Collecting Payment Service Provider by Currence. This means we set the highest security standards when is comes to security of payment for you as a customer and us as a webshop.' mod='ps_targetpay'}</i>
    </p>
</div>