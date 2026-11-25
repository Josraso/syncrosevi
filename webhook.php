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

/**
 * Webhook antiguo (se mantiene para las tiendas hijas y cron ya configurados con esta URL).
 * La URL nueva es el controlador de módulo: index.php?fc=module&module=syncrosevi&controller=webhook
 * (se muestra en el panel de SyncroSevi). Toda la lógica está en Syncrosevi::handleWebhookRequest().
 */
$prestashop_root = realpath(dirname(__FILE__) . '/../../');
if (!$prestashop_root || !file_exists($prestashop_root . '/config/config.inc.php')) {
    header('HTTP/1.1 500 Internal Server Error');
    header('Content-Type: application/json');
    die(json_encode(array('success' => false, 'error' => 'PrestaShop not found')));
}

require_once $prestashop_root . '/config/config.inc.php';

header('Content-Type: application/json; charset=utf-8');

$module = Module::getInstanceByName('syncrosevi');
if (!$module || !Module::isInstalled('syncrosevi')) {
    http_response_code(404);
    die(json_encode(array('success' => false, 'error' => 'SyncroSevi module not installed')));
}

@set_time_limit(300);
$result = $module->handleWebhookRequest();
if (method_exists($module, 'webhookResponded') && $module->webhookResponded()) {
    exit;
}
http_response_code((int) $result[0]);
echo json_encode($result[1]);
