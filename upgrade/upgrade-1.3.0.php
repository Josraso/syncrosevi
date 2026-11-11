<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 1.3.0: conexión con el servidor de actualizaciones de josra
 */
function upgrade_module_1_3_0($module)
{
    return $module->getUpdater()->registerHooks();
}
