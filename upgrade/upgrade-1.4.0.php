<?php
/**
 * syncrosevi 1.4.0: entra todo lo que coincide por referencia (sin tocar el stock) y se apunta lo que se queda fuera.
 * Añade la referencia del pedido de la hija y el estado de cada línea.
 *
 * @author josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_4_0($module)
{
    return $module->updateExistingTables() && $module->getUpdater()->registerHooks();
}
