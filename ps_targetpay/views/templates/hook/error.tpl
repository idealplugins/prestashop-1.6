{if $module == "targetpay"}
    {if $errorMessage}
        <div class="alert alert-danger">
            <button type="button" class="close" data-dismiss="alert">×</button>
            {$errorMessage|escape:'html':'UTF-8'}
        </div>
    {/if}
{/if}
