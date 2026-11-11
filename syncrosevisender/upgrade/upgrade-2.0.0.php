<?php
/**
 * SyncroSevi Sender 2.0.0: compatibilidad PrestaShop 9, cola de reintentos y configuración nativa.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_0_0($module)
{
    // Reintentos más cortos para no frenar los cambios de estado si la madre no responde
    if ((int) Configuration::get('SYNCROSEVISENDER_TIMEOUT') > 10) {
        Configuration::updateValue('SYNCROSEVISENDER_TIMEOUT', 10);
    }
    if ((int) Configuration::get('SYNCROSEVISENDER_MAX_RETRIES') > 3) {
        Configuration::updateValue('SYNCROSEVISENDER_MAX_RETRIES', 3);
    }

    // Logs antiguos (uno por día) a un solo archivo protegido
    $dir = dirname(__FILE__) . '/../logs';
    if (is_dir($dir)) {
        foreach ((array) glob($dir . '/syncrosevisender_*.log') as $file) {
            if (filemtime($file) < time() - 30 * 86400) {
                @unlink($file);
            }
        }
        if (!file_exists($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order Deny,Allow\n    Deny from all\n</IfModule>\n");
        }
    }
    // Script de autoconfiguración antiguo, accesible desde la web
    foreach (array('auto_config.php', 'CONFIGURACION_AUTOMATICA.php') as $old) {
        if (file_exists(dirname(__FILE__) . '/../' . $old)) {
            @unlink(dirname(__FILE__) . '/../' . $old);
        }
    }

    return true;
}
