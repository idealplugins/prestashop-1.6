<?php

if (!defined('_PS_VERSION_'))
    exit;

// object module ($this) available
function upgrade_module_1_1($object)
{
    // add an index on the transaction_id column to improve performance
    return Db::getInstance()->execute("ALTER TABLE `"._DB_PREFIX_."targetpay_ideal`  ADD INDEX `IX_tp_transaction_id` (`transaction_id`)");
}
