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
 * Script de actualización para SyncroSevi v1.0.3
 *
 * Mejoras incluidas:
 * - Panel de administración refactorizado
 * - Sistema de webhooks mejorado
 * - Mejor gestión de errores
 * - Cache de productos optimizado
 * - Nuevas funcionalidades AJAX
 */
function upgrade_module_1_0_3($module)
{
    $success = true;
    
    try {
        // 1. Actualizar estructura de base de datos
        if (!syncrosevi_upg103_db()) {
            $success = false;
        }
        
        // 2. Regenerar token de webhook si no existe
        if (!Configuration::get('SYNCROSEVI_WEBHOOK_TOKEN')) {
            $webhook_token = md5('syncrosevi_' . Configuration::get('PS_SHOP_NAME') . '_' . Configuration::get('PS_SHOP_EMAIL') . '_' . date('Y-m'));
            Configuration::updateValue('SYNCROSEVI_WEBHOOK_TOKEN', $webhook_token);
        }
        
        // 3. Crear directorio de logs si no existe
        $logDir = dirname(__FILE__) . '/../logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
            
            // Crear archivo .htaccess para proteger logs
            $htaccessContent = "Order Deny,Allow\nDeny from all\n";
            file_put_contents($logDir . '/.htaccess', $htaccessContent);
        }
        
        // 4. Limpiar cache de productos si existe
        if (method_exists($module, 'clearProductCache')) {
            $module->clearProductCache();
        }
        
        // 5. Verificar y reparar datos existentes
        if (!syncrosevi_upg103_repair()) {
            // No detener actualización por errores menores en datos
            error_log('SyncroSevi Upgrade: Warning - Some data repair operations failed');
        }
        
        // 6. Registrar nuevo hook si no está registrado
        // (el hook actionObjectOrderHistoryAddAfter ya no se usa: lo quita la actualización 1.1.0)
        
        // Log de actualización exitosa
        error_log('SyncroSevi: Successfully upgraded to version 1.0.3');
        
    } catch (Exception $e) {
        error_log('SyncroSevi Upgrade Error: ' . $e->getMessage());
        $success = false;
    }
    
    return $success;
}

/**
 * Actualizar estructura de base de datos
 */
function syncrosevi_upg103_db()
{
    try {
        // Verificar y añadir columnas faltantes en syncrosevi_child_shops
        $columns = Db::getInstance()->executeS("SHOW COLUMNS FROM `" . _DB_PREFIX_ . "syncrosevi_child_shops`");
        $columnNames = array_column($columns, 'Field');
        
        if (!in_array('start_order_id', $columnNames)) {
            Db::getInstance()->execute('
                ALTER TABLE `' . _DB_PREFIX_ . 'syncrosevi_child_shops` 
                ADD COLUMN `start_order_id` int(11) NOT NULL DEFAULT 1 
                AFTER `id_order_state`
            ');
        }
        
        if (!in_array('import_states', $columnNames)) {
            Db::getInstance()->execute('
                ALTER TABLE `' . _DB_PREFIX_ . 'syncrosevi_child_shops` 
                ADD COLUMN `import_states` varchar(255) NOT NULL DEFAULT "2,3,4,5" 
                AFTER `start_order_id`
            ');
        }
        
        // Índices para mejor rendimiento (solo si no existen)
        $idx = array_column((array) Db::getInstance()->executeS('SHOW INDEX FROM `' . _DB_PREFIX_ . 'syncrosevi_order_tracking`'), 'Key_name');
        if (!in_array('idx_processed_date', $idx)) {
            Db::getInstance()->execute('ALTER TABLE `' . _DB_PREFIX_ . 'syncrosevi_order_tracking` ADD INDEX `idx_processed_date` (`processed`, `date_processed`)');
        }
        $idx = array_column((array) Db::getInstance()->executeS('SHOW INDEX FROM `' . _DB_PREFIX_ . 'syncrosevi_order_lines`'), 'Key_name');
        if (!in_array('idx_reference', $idx)) {
            Db::getInstance()->execute('ALTER TABLE `' . _DB_PREFIX_ . 'syncrosevi_order_lines` ADD INDEX `idx_reference` (`product_reference`)');
        }

        return true;
        
    } catch (Exception $e) {
        error_log('SyncroSevi DB Update Error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Reparar datos existentes
 */
function syncrosevi_upg103_repair()
{
    try {
        // Actualizar import_states vacíos con valores por defecto
        Db::getInstance()->execute('
            UPDATE `' . _DB_PREFIX_ . 'syncrosevi_child_shops` 
            SET `import_states` = "2,3,4,5" 
            WHERE `import_states` = "" OR `import_states` IS NULL
        ');
        
        // Actualizar start_order_id con valor por defecto
        Db::getInstance()->execute('
            UPDATE `' . _DB_PREFIX_ . 'syncrosevi_child_shops` 
            SET `start_order_id` = 1 
            WHERE `start_order_id` = 0 OR `start_order_id` IS NULL
        ');
        
        // Limpiar datos huérfanos en order_lines
        Db::getInstance()->execute('
            DELETE ol FROM `' . _DB_PREFIX_ . 'syncrosevi_order_lines` ol
            LEFT JOIN `' . _DB_PREFIX_ . 'syncrosevi_child_shops` cs 
            ON ol.id_child_shop = cs.id_child_shop
            WHERE cs.id_child_shop IS NULL
        ');
        
        // Limpiar datos huérfanos en order_tracking
        Db::getInstance()->execute('
            DELETE ot FROM `' . _DB_PREFIX_ . 'syncrosevi_order_tracking` ot
            LEFT JOIN `' . _DB_PREFIX_ . 'syncrosevi_child_shops` cs 
            ON ot.id_child_shop = cs.id_child_shop
            WHERE cs.id_child_shop IS NULL
        ');
        
        return true;
        
    } catch (Exception $e) {
        error_log('SyncroSevi Data Repair Error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Verificar integridad post-actualización
 */
function syncrosevi_upg103_verify()
{
    $errors = array();
    
    // Verificar tablas
    $tables = ['syncrosevi_child_shops', 'syncrosevi_order_tracking', 'syncrosevi_order_lines'];
    foreach ($tables as $table) {
        $exists = Db::getInstance()->getValue("SHOW TABLES LIKE '" . _DB_PREFIX_ . $table . "'");
        if (!$exists) {
            $errors[] = "Tabla {$table} no encontrada";
        }
    }
    
    // Verificar token webhook
    if (!Configuration::get('SYNCROSEVI_WEBHOOK_TOKEN')) {
        $errors[] = "Token de webhook no configurado";
    }
    
    // Verificar directorio de logs
    $logDir = dirname(__FILE__) . '/../logs';
    if (!is_dir($logDir) || !is_writable($logDir)) {
        $errors[] = "Directorio de logs no disponible o sin permisos de escritura";
    }
    
    if (!empty($errors)) {
        error_log('SyncroSevi Upgrade Verification Errors: ' . implode(', ', $errors));
        return false;
    }
    
    return true;
}
