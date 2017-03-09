<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * This function updates your module from previous versions to the version 1.1,
 * usefull when you modify your database, or register a new hook ...
 * Don't forget to create one file per version.
 */
function upgrade_module_1_1_0($module)
{
    // add an index on the transaction_id column to improve performance
    return Db::getInstance()->execute("ALTER TABLE `"._DB_PREFIX_."targetpay_ideal` ADD INDEX `IX_tp_transaction_id` (`transaction_id`)");
}
