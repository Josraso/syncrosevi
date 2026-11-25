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
 * Webhook de SyncroSevi como controlador de módulo:
 * index.php?fc=module&module=syncrosevi&controller=webhook&action=...&security_token=...
 *
 * Sustituye a modules/syncrosevi/webhook.php, que PrestaShop 9 bloquea en Apache
 * (el .htaccess de /modules deniega el acceso directo a archivos .php).
 */
class SyncroseviWebhookModuleFrontController extends ModuleFrontController
{
    public $ajax = true;
    protected $content_only = true;

    /** Las tiendas hijas y los cron deben poder llamar aunque la tienda esté en mantenimiento */
    protected function displayMaintenancePage()
    {
    }

    protected function displayRestrictedCountryPage()
    {
    }

    public function initContent()
    {
        // Sin plantilla: solo JSON
    }

    public function postProcess()
    {
        @set_time_limit(300);
        $result = $this->module->handleWebhookRequest();
        if ($this->module->webhookResponded()) {
            // sync/process: el cron ya tiene su respuesta y el trabajo ha terminado
            exit;
        }
        $this->sendJson($result[0], $result[1]);
    }

    private function sendJson($code, array $data)
    {
        if (!headers_sent()) {
            http_response_code((int) $code);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('X-Robots-Tag: noindex, nofollow');
        }
        echo json_encode($data);
        exit;
    }
}
