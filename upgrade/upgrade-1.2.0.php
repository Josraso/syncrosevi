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
 * SyncroSevi 1.2.0
 * - Modo «clonar» por tienda hija (cada pedido de la hija entra como un pedido propio en la madre).
 * - Las tiendas que ya existen siguen en modo consolidado: no cambia nada en ellas.
 * - Hook actionEmailSendBefore: ningún correo al cliente por los pedidos clonados.
 */
function upgrade_module_1_2_0($module)
{
    $module->updateExistingTables();

    if (!$module->isRegisteredInHook('actionEmailSendBefore')) {
        $module->registerHook('actionEmailSendBefore');
    }

    Syncrosevi::refreshCloneFlag();

    return true;
}
