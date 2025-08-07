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

// Determinar la ruta de PrestaShop
$prestashop_root = realpath(dirname(__FILE__) . '/../../');
if (!$prestashop_root || !file_exists($prestashop_root . '/config/config.inc.php')) {
    header('HTTP/1.1 500 Internal Server Error');
    die(json_encode(array('error' => 'PrestaShop not found')));
}

// Incluir archivos de PrestaShop
require_once($prestashop_root . '/config/config.inc.php');
require_once($prestashop_root . '/init.php');

// Verificar que el módulo esté instalado y activo
if (!Module::isInstalled('syncrosevi')) {
    header('HTTP/1.1 404 Not Found');
    die(json_encode(array('error' => 'SyncroSevi module not installed')));
}

// Usar Tools::getValue() método PrestaShop
$action = Tools::getValue('action', '');
$security_token = Tools::getValue('security_token', '');

// Verificar token de seguridad mejorado (fijo por mes en lugar de diario)
$expected_token = md5('syncrosevi_' . Configuration::get('PS_SHOP_NAME') . '_' . Configuration::get('PS_SHOP_EMAIL') . '_' . date('Y-m'));

if ($security_token !== $expected_token) {
    header('HTTP/1.1 403 Forbidden');
    die(json_encode(array('error' => 'Invalid security token')));
}

// Configurar respuesta JSON
header('Content-Type: application/json');

try {
    // Cargar módulo
    $module = Module::getInstanceByName('syncrosevi');
    if (!$module || !$module->active) {
        throw new Exception('Cannot load SyncroSevi module or module is inactive');
    }
    
    // Verificar que los métodos existen
    if (!method_exists($module, 'syncOrders') || !method_exists($module, 'processOrders')) {
        throw new Exception('Required module methods not found');
    }
    
    switch ($action) {
        case 'sync':
            $results = $module->syncOrders();
            echo json_encode(array(
                'success' => true, 
                'action' => 'sync',
                'results' => $results,
                'count' => is_array($results) ? count($results) : 0,
                'timestamp' => date('Y-m-d H:i:s')
            ));
            break;
            
        case 'process':
            $results = $module->processOrders();
            echo json_encode(array(
                'success' => true, 
                'action' => 'process',
                'results' => $results,
                'count' => is_array($results) ? count($results) : 0,
                'timestamp' => date('Y-m-d H:i:s')
            ));
            break;
            
        case 'test':
            echo json_encode(array(
                'success' => true,
                'action' => 'test',
                'message' => 'Webhook funcionando correctamente',
                'prestashop_version' => _PS_VERSION_,
                'module_version' => $module->version,
                'timestamp' => date('Y-m-d H:i:s')
            ));
            break;
            
        default:
            throw new Exception('Invalid action: ' . $action . '. Valid actions: sync, process, test');
    }
    
} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(array(
        'success' => false,
        'error' => $e->getMessage(),
        'action' => $action,
        'timestamp' => date('Y-m-d H:i:s')
    ));
}
?>