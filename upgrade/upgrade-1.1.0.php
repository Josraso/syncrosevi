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
 * SyncroSevi 1.1.0
 * - Compatible con PrestaShop 9.
 * - Pestaña oculta (se entra desde Módulos > Configurar).
 * - Se quita el hook actionObjectOrderHistoryAddAfter: se ejecutaba con los pedidos de la propia
 *   tienda madre (incluidos los que crea el módulo) y consultaba todas las tiendas hijas con un ID que no era suyo.
 * - Índices, log con rotación y limpieza de archivos que no deben ser accesibles.
 */
function upgrade_module_1_1_0($module)
{
    // Hook que ya no se usa
    if ($module->isRegisteredInHook('actionObjectOrderHistoryAddAfter')) {
        $module->unregisterHook('actionObjectOrderHistoryAddAfter');
    }

    // Pestaña oculta
    $module->installTab();

    // Columnas e índices
    $module->updateExistingTables();

    if (Configuration::get('SYNCROSEVI_DEBUG') === false) {
        Configuration::updateValue('SYNCROSEVI_DEBUG', 0);
    }

    // Log: el antiguo podía pasar de varios MB (registraba cada consulta SQL)
    $logDir = dirname(__FILE__) . '/../logs';
    $log = $logDir . '/syncrosevi_debug.log';
    if (file_exists($log) && filesize($log) > Syncrosevi::LOG_MAX_SIZE) {
        @rename($log, $log . '.1');
    }
    Syncrosevi::protectDir($logDir);

    $cronLogs = dirname(__FILE__) . '/../cron/logs';
    if (is_dir($cronLogs)) {
        Syncrosevi::protectDir($cronLogs);
        foreach ((array) glob($cronLogs . '/syncrosevi_cron_*.log') as $file) {
            if ($file && filemtime($file) < time() - 30 * 86400) {
                @unlink($file);
            }
        }
    }

    // Scripts de depuración y el ZIP del módulo hijo antiguo (ahora se genera al descargar)
    foreach (array('debug.php', 'test_sql.php', 'install.php', 'syncrosevisender.zip', 'syncrosevisender/auto_config.php',
        'views/templates/admin/syncrosevi_main.tpl', 'views/templates/admin/syncrosevi_pending.tpl',
        'views/templates/admin/add-shop-form.tpl', 'views/js/edit-shop.js') as $old) {
        $path = dirname(__FILE__) . '/../' . $old;
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    if (method_exists('Media', 'clearCache')) {
        Media::clearCache();
    }

    return true;
}
