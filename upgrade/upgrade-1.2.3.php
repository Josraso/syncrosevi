<?php
/**
 * 2024 SyncroSevi
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 *
 * @author    SyncroSevi Team
 * @copyright 2024 SyncroSevi
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * SyncroSevi 1.2.3
 * - Modo clonar: el pedido entra siempre con el «Estado del Pedido» configurado.
 * - Cambios de estado también de la madre a la hija (hook actionOrderHistoryAddAfter, solo pedidos clonados).
 */
function upgrade_module_1_2_3($module)
{
    $module->updateExistingTables();

    if (!$module->isRegisteredInHook('actionOrderHistoryAddAfter')) {
        $module->registerHook('actionOrderHistoryAddAfter');
    }
    if (!$module->isRegisteredInHook('actionEmailSendBefore')) {
        $module->registerHook('actionEmailSendBefore');
    }
    Syncrosevi::refreshCloneFlag();

    return true;
}
