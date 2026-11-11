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

class AdminSyncroseviController extends ModuleAdminController
{
    /** @var Syncrosevi */
    public $module;

    public function __construct()
    {
        $this->bootstrap = true;
        $this->context = Context::getContext();
        $this->lang = false;

        parent::__construct();

        $this->meta_title = $this->t('SyncroSevi - Gestión de Sincronización');
    }

    /**
     * Traducción con el módulo: PrestaShop 9 eliminó AdminController::l() (compatible con 1.7 y 8)
     */
    protected function t($string)
    {
        return ($this->module instanceof Module) ? $this->module->l($string, 'adminsyncrosevicontroller') : $string;
    }

    public function initContent()
    {
        parent::initContent();

        $action = Tools::getValue('action');

        switch ($action) {
            case 'reset_shop':
                $this->doResetShop();
                break;
            case 'add_shop':
                $this->doAddShop();
                break;
            case 'edit_shop':
            case 'update_shop':
                $this->doEditShop();
                if (!empty($this->errors)) {
                    $this->displayEditShop();

                    return;
                }
                break;
            case 'edit':
                $this->displayEditShop();

                return;
            case 'delete_shop':
                $this->doDeleteShop();
                break;
            case 'test_connection':
                $this->doTestConnection();

                return;
            case 'test_credentials':
                $this->doTestCredentials();

                return;
            case 'sync_orders':
                $this->doSyncOrders();
                break;
            case 'process_orders':
                $this->processOrders();
                break;
            case 'save_settings':
                $this->doSaveSettings();
                break;
            case 'view_pending':
                $this->displayPendingOrders();

                return;
            case 'view_processed':
                $this->displayProcessedOrders();

                return;
            case 'get_processed_orders':
                $this->getProcessedOrdersAjax();

                return;
            case 'get_order_details':
                $this->getOrderDetailsAjax();

                return;
            case 'get_states':
                $this->getChildShopStatesAjax();

                return;
            case 'get_order_products':
                $this->getOrderProductsAjax();

                return;
            case 'delete_pending_order':
                $this->deletePendingOrderAjax();

                return;
            case 'regenerate_webhook_secret':
                $this->regenerateWebhookSecret();

                return;
            case 'get_webhook_info':
                $this->getWebhookInfoAjax();

                return;
            case 'search_customers':
                $this->searchCustomersAjax();

                return;
            case 'get_customer_addresses':
                $this->getCustomerAddressesAjax();

                return;
            case 'export_processed':
                $this->exportProcessedOrders();

                return;
            case 'download_child_module':
                $this->downloadChildModule(true);

                return;
            case 'download_sender_clean':
                $this->downloadChildModule(false);

                return;
        }

        // Tras las acciones se vuelve a la página de origen
        $back = Tools::getValue('back');
        if ($back === 'edit' && (int) Tools::getValue('id_child_shop')) {
            $this->displayEditShop();
        } elseif ($back === 'pending') {
            $this->displayPendingOrders();
        } else {
            $this->displayMainContent();
        }
    }

    /**
     * Restablecer datos de una tienda
     */
    protected function doResetShop()
    {
        $id_shop = (int) Tools::getValue('id_child_shop');
        if (!$id_shop) {
            $this->errors[] = $this->t('ID de tienda inválido');

            return;
        }

        $db = Db::getInstance();
        $deleted_lines = (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'syncrosevi_order_lines` WHERE id_child_shop = ' . $id_shop);
        $deleted_tracking = (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'syncrosevi_order_tracking` WHERE id_child_shop = ' . $id_shop);
        $db->delete('syncrosevi_order_lines', 'id_child_shop = ' . $id_shop);
        $db->delete('syncrosevi_order_tracking', 'id_child_shop = ' . $id_shop);

        $this->confirmations[] = sprintf($this->t('Tienda restablecida correctamente. Eliminadas: %d líneas y %d registros de tracking.'), $deleted_lines, $deleted_tracking);
        $_GET['back'] = 'edit';
        $_POST['back'] = 'edit';
    }

    /**
     * Mostrar contenido principal
     */
    protected function displayMainContent()
    {
        $this->loadAssets();

        $cron = _PS_MODULE_DIR_ . 'syncrosevi/cron/syncrosevi_cron.php';

        $this->context->smarty->assign(array(
            'stats' => $this->getStats(),
            'shops' => $this->getConfiguredShops(),
            'pending_orders' => $this->getPendingOrders(),
            'customers' => array(), // se buscan por AJAX (antes se cargaban todos los clientes en la página)
            'groups' => Group::getGroups($this->context->language->id),
            'carriers' => Carrier::getCarriers($this->context->language->id, true, false, false, null, Carrier::ALL_CARRIERS),
            'order_states' => OrderState::getOrderStates($this->context->language->id),
            'addresses' => array(), // se cargan por AJAX al elegir el cliente
            'admin_link' => $this->context->link->getAdminLink('AdminSyncrosevi'),
            'token' => Tools::getAdminTokenLite('AdminSyncrosevi'),
            'webhook_sync_url' => $this->module->getWebhookUrl('sync'),
            'webhook_process_url' => $this->module->getWebhookUrl('process'),
            'webhook_test_url' => $this->module->getWebhookUrl('test'),
            'cron_sync_cmd' => 'php ' . $cron . ' sync',
            'cron_process_cmd' => 'php ' . $cron . ' process',
            'settings_form' => $this->renderSettingsForm(),
            'module_version' => $this->module->version,
        ));

        // Actualizaciones del módulo: arriba si hay versión nueva, abajo si no
        $updater = $this->module->getUpdater();
        $info = $updater->getInfo();
        $this->context->smarty->assign(array(
            'updater_panel' => $updater->renderPanel(),
            'updater_top' => !empty($info['update_available']),
        ));
        if (!$updater->ready()) {
            $this->errors[] = $this->module->notEnabledMessage();
        }

        $this->setTemplate('main.tpl');
    }

    /**
     * Formulario nativo de ajustes generales
     */
    protected function renderSettingsForm()
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->module = $this->module;
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->identifier = 'id_child_shop';
        $helper->submit_action = 'submitSyncroseviSettings';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminSyncrosevi', false) . '&action=save_settings';
        $helper->token = Tools::getAdminTokenLite('AdminSyncrosevi');
        $helper->tpl_vars = array(
            'fields_value' => array(
                'SYNCROSEVI_BATCH_LIMIT' => (int) Configuration::get('SYNCROSEVI_BATCH_LIMIT') ?: 10,
                'SYNCROSEVI_DEBUG' => (int) Configuration::get('SYNCROSEVI_DEBUG'),
            ),
            'languages' => $this->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        $switch = version_compare(_PS_VERSION_, '1.6', '>=') ? 'switch' : 'radio';

        return $helper->generateForm(array(array('form' => array(
            'legend' => array('title' => $this->t('Ajustes generales'), 'icon' => 'icon-cogs'),
            'input' => array(
                array(
                    'type' => 'text',
                    'name' => 'SYNCROSEVI_BATCH_LIMIT',
                    'label' => $this->t('Pedidos por tienda en cada procesamiento'),
                    'class' => 'fixed-width-sm',
                    'desc' => $this->t('Entre 1 y 50. Cada procesamiento agrupa como máximo estos pedidos de cada tienda en un solo pedido madre. Los que sobren se procesan en la siguiente ejecución.'),
                ),
                array(
                    'type' => $switch,
                    'name' => 'SYNCROSEVI_DEBUG',
                    'label' => $this->t('Log detallado'),
                    'is_bool' => true,
                    'values' => array(
                        array('id' => 'debug_on', 'value' => 1, 'label' => $this->t('Sí')),
                        array('id' => 'debug_off', 'value' => 0, 'label' => $this->t('No')),
                    ),
                    'desc' => $this->t('Guarda en logs/syncrosevi_debug.log cada producto, consulta y total. Actívalo solo para investigar un problema: los errores y pedidos creados se registran siempre.'),
                ),
            ),
            'submit' => array('title' => $this->t('Guardar')),
        ))));
    }

    protected function doSaveSettings()
    {
        if (!Tools::isSubmit('submitSyncroseviSettings')) {
            return;
        }
        $limit = (int) Tools::getValue('SYNCROSEVI_BATCH_LIMIT');
        if ($limit < 1 || $limit > 50) {
            $this->errors[] = $this->t('El número de pedidos por tienda debe estar entre 1 y 50');

            return;
        }
        Configuration::updateValue('SYNCROSEVI_BATCH_LIMIT', $limit);
        Configuration::updateValue('SYNCROSEVI_DEBUG', (int) (bool) Tools::getValue('SYNCROSEVI_DEBUG'));
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminSyncrosevi') . '&conf=6');
    }

    /**
     * Mostrar pedidos pendientes
     */
    protected function displayPendingOrders()
    {
        $this->loadAssets();

        $this->context->smarty->assign(array(
            'pending_orders' => $this->getAllPendingOrders(),
            'admin_link' => $this->context->link->getAdminLink('AdminSyncrosevi'),
            'token' => Tools::getAdminTokenLite('AdminSyncrosevi'),
            'batch_limit' => (int) Configuration::get('SYNCROSEVI_BATCH_LIMIT') ?: 10,
        ));

        $this->setTemplate('pending-orders.tpl');
    }

    /**
     * Mostrar pedidos procesados (se cargan por AJAX con filtros y paginación)
     */
    protected function displayProcessedOrders()
    {
        $this->loadAssets();

        $this->context->smarty->assign(array(
            'admin_link' => $this->context->link->getAdminLink('AdminSyncrosevi'),
            'token' => Tools::getAdminTokenLite('AdminSyncrosevi'),
            'shops_list' => Db::getInstance()->executeS('SELECT id_child_shop, name FROM `' . _DB_PREFIX_ . 'syncrosevi_child_shops` ORDER BY name'),
        ));

        $this->setTemplate('processed-orders.tpl');
    }

    /**
     * Mostrar formulario de edición de tienda
     */
    protected function displayEditShop()
    {
        $id_shop = (int) Tools::getValue('id_child_shop');
        if (!$id_shop) {
            $this->errors[] = $this->t('ID de tienda inválido');
            $this->displayMainContent();

            return;
        }

        $shop = Db::getInstance()->getRow(
            'SELECT cs.*, c.firstname, c.lastname, c.email
             FROM `' . _DB_PREFIX_ . 'syncrosevi_child_shops` cs
             LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON cs.id_customer = c.id_customer
             WHERE cs.id_child_shop = ' . $id_shop
        );
        if (!$shop) {
            $this->errors[] = $this->t('Tienda no encontrada');
            $this->displayMainContent();

            return;
        }

        $this->loadAssets();

        $shopStats = array(
            'pending_orders' => (int) Db::getInstance()->getValue(
                'SELECT COUNT(DISTINCT id_original_order) FROM `' . _DB_PREFIX_ . 'syncrosevi_order_lines`
                 WHERE id_child_shop = ' . $id_shop . ' AND processed = 0'
            ),
            'processed_orders' => (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'syncrosevi_order_tracking`
                 WHERE id_child_shop = ' . $id_shop . ' AND processed = 1'
            ),
            'last_sync' => Db::getInstance()->getValue(
                'SELECT MAX(date_sync) FROM `' . _DB_PREFIX_ . 'syncrosevi_order_tracking` WHERE id_child_shop = ' . $id_shop
            ) ?: 'Nunca',
        );

        // Estados reales de la tienda hija (timeout corto para no bloquear la página si la hija no responde)
        $childStates = array();
        try {
            $webservice = $this->getWebservice($shop['url'], $shop['api_key']);
            $webservice->timeout = 8;
            $childStates = $this->parseStates($webservice->get(array('resource' => 'order_states', 'display' => 'full')));
        } catch (Exception $e) {
            // Sin conexión: se muestran solo los IDs guardados
        }

        try {
            $cloneVars = $this->getCloneViewData($shop, $childStates, isset($webservice) ? $webservice : null);
        } catch (Exception $e) {
            // Nunca romper la edición de la tienda por el panel del modo clonar
            $cloneVars = array('child_states_ok' => false, 'state_map' => array(), 'state_map_out' => array(), 'child_taxes' => array(), 'mother_taxes' => array(),
                'tax_map' => array(), 'substitute_name' => '', 'carrier_zone_warning' => '');
            foreach (array('sync_mode' => 'consolidated', 'clone_missing' => 'hold', 'clone_date' => 'import', 'clone_state_sync' => 0, 'id_product_substitute' => 0) as $k => $v) {
                if (!isset($shop[$k])) {
                    $shop[$k] = $v;
                }
            }
        }

        $this->context->smarty->assign(array_merge(array(
            'shop' => $shop,
            'shop_stats' => $shopStats,
            'child_states' => $childStates,
            'customer' => array(
                'id_customer' => $shop['id_customer'],
                'firstname' => $shop['firstname'],
                'lastname' => $shop['lastname'],
                'email' => $shop['email'],
            ),
            'customers' => array(),
            'groups' => Group::getGroups($this->context->language->id),
            'carriers' => Carrier::getCarriers($this->context->language->id, true, false, false, null, Carrier::ALL_CARRIERS),
            'order_states' => OrderState::getOrderStates($this->context->language->id),
            'addresses' => $this->getAddresses((int) $shop['id_customer']),
            'addresses_all' => array(),
            'webhook_url' => $this->module->getWebhookUrl('realtime_sync'),
            'sender_config_code' => $this->getSenderConfigCode($shop),
            'admin_link' => $this->context->link->getAdminLink('AdminSyncrosevi'),
            'token' => Tools::getAdminTokenLite('AdminSyncrosevi'),
        ), $cloneVars));

        $this->setTemplate('edit-shop.tpl');
    }

    /** Datos del panel «Modo de importación»: mapeos de estados e impuestos con propuestas */
    protected function getCloneViewData(array $shop, array $childStates, $webservice)
    {
        $idLang = (int) $this->context->language->id;
        foreach (array('sync_mode' => 'consolidated', 'clone_missing' => 'hold', 'clone_date' => 'import', 'clone_state_sync' => 0, 'id_product_substitute' => 0, 'clone_state_map' => '', 'clone_state_map_out' => '', 'clone_state_email' => 0, 'clone_tax_map' => '') as $k => $v) {
            if (!isset($shop[$k])) {
                $shop[$k] = $v;
            }
        }
        if (!class_exists('SyncroSeviClone')) {
            require_once dirname(__FILE__) . '/../../classes/SyncroSeviClone.php';
        }

        // Estados: lo guardado y, para lo que falte, el que se llame igual
        $stateMap = SyncroSeviClone::decodeMap($shop['clone_state_map']);
        $motherStates = OrderState::getOrderStates($idLang);
        foreach ($childStates as $cs) {
            if (isset($stateMap[$cs['id']])) {
                continue;
            }
            foreach ($motherStates as $ms) {
                if (Tools::strtolower(trim($ms['name'])) === Tools::strtolower(trim($cs['name']))) {
                    $stateMap[$cs['id']] = (int) $ms['id_order_state'];
                    break;
                }
            }
        }

        // Madre → hija: lo guardado y, para lo que falte, el que se llame igual
        $stateMapOut = SyncroSeviClone::decodeMap($shop['clone_state_map_out']);
        foreach ($motherStates as $ms) {
            if (isset($stateMapOut[$ms['id_order_state']])) {
                continue;
            }
            foreach ($childStates as $cs) {
                if (Tools::strtolower(trim($ms['name'])) === Tools::strtolower(trim($cs['name']))) {
                    $stateMapOut[(int) $ms['id_order_state']] = (int) $cs['id'];
                    break;
                }
            }
        }

        // Impuestos de la hija y propuesta por porcentaje
        $childTaxes = array();
        if ($webservice && $childStates) {
            try {
                $xml = $webservice->get(array('resource' => 'taxes', 'display' => 'full'));
                if ($xml && isset($xml->taxes->tax)) {
                    foreach ($xml->taxes->tax as $t) {
                        if ((int) $t->deleted) {
                            continue;
                        }
                        $name = '';
                        if (isset($t->name->language)) {
                            $name = (string) $t->name->language[0];
                        } else {
                            $name = (string) $t->name;
                        }
                        $childTaxes[] = array('id' => (int) $t->id, 'name' => $name, 'rate' => (float) $t->rate);
                    }
                }
            } catch (Exception $e) {
                // Sin permiso para «taxes»: se mapea automáticamente por porcentaje
            }
        }
        $motherTaxes = (array) Db::getInstance()->executeS(
            'SELECT t.id_tax, t.rate, tl.name FROM `' . _DB_PREFIX_ . 'tax` t
             LEFT JOIN `' . _DB_PREFIX_ . 'tax_lang` tl ON (tl.id_tax = t.id_tax AND tl.id_lang = ' . $idLang . ')
             WHERE t.deleted = 0 ORDER BY t.rate DESC, tl.name ASC'
        );
        $taxMap = SyncroSeviClone::decodeMap($shop['clone_tax_map']);
        foreach ($childTaxes as $ct) {
            if (isset($taxMap[$ct['id']])) {
                continue;
            }
            foreach ($motherTaxes as $mt) {
                if (abs((float) $mt['rate'] - $ct['rate']) < 0.001) {
                    $taxMap[$ct['id']] = (int) $mt['id_tax'];
                    break;
                }
            }
        }

        $substituteName = '';
        if ((int) $shop['id_product_substitute']) {
            $substituteName = (string) Product::getProductName((int) $shop['id_product_substitute'], null, $idLang);
        }

        // Zonas que no cubre el transportista configurado
        $zoneWarning = '';
        $idCarrier = (int) $shop['id_carrier'] ?: (int) Configuration::get('SYNCROSEVI_FREE_CARRIER_ID');
        if ($idCarrier) {
            $carrier = new Carrier($idCarrier, $idLang);
            if (Validate::isLoadedObject($carrier)) {
                $covered = array_map('intval', array_column((array) $carrier->getZones(), 'id_zone'));
                $missingZones = array();
                foreach (Zone::getZones(true) as $zone) {
                    if (!in_array((int) $zone['id_zone'], $covered)) {
                        $missingZones[] = $zone['name'];
                    }
                }
                if ($missingZones) {
                    $zoneWarning = sprintf('El transportista «%s» no tiene asignadas estas zonas: %s. Los pedidos entran igual (se corrige al copiar los importes), pero si se las asignas todo va más limpio.', $carrier->name, implode(', ', $missingZones));
                }
            }
        }

        return array(
            'shop' => $shop,
            'child_states_ok' => !empty($childStates),
            'state_map' => $stateMap,
            'state_map_out' => $stateMapOut,
            'child_taxes' => $childTaxes,
            'mother_taxes' => $motherTaxes,
            'tax_map' => $taxMap,
            'substitute_name' => $substituteName,
            'carrier_zone_warning' => $zoneWarning,
        );
    }

    /**
     * Cargar assets CSS y JS
     */
    protected function loadAssets()
    {
        $v = '?v=' . $this->module->version;
        $this->addCSS(_MODULE_DIR_ . 'syncrosevi/views/css/admin.css' . $v);
        $this->addCSS(_MODULE_DIR_ . 'syncrosevi/views/css/modal.css' . $v);
        $this->addJS(_MODULE_DIR_ . 'syncrosevi/views/js/admin.js' . $v);
        $this->addJS(_MODULE_DIR_ . 'syncrosevi/views/js/processed-orders.js' . $v);
    }

    protected function getWebservice($url, $apiKey)
    {
        if (!class_exists('SyncroSeviWebservice')) {
            require_once dirname(__FILE__) . '/../../classes/SyncroSeviWebservice.php';
        }

        return new SyncroSeviWebservice($url, $apiKey, $this->module->isDebug());
    }

    /** Convierte la respuesta XML de order_states en array id/name/display_name */
    protected function parseStates($xml)
    {
        $states = array();
        if ($xml && isset($xml->order_states->order_state)) {
            foreach ($xml->order_states->order_state as $state) {
                $stateName = '';
                if (isset($state->name)) {
                    if (isset($state->name->language)) {
                        $stateName = (string) $state->name->language[0];
                    } else {
                        $stateName = (string) $state->name;
                    }
                }
                $stateName = $stateName ?: 'Estado #' . (int) $state->id;
                $states[] = array(
                    'id' => (int) $state->id,
                    'name' => $stateName,
                    'display_name' => 'ID ' . (int) $state->id . ' - ' . $stateName,
                );
            }
        }

        return $states;
    }

    // AJAX HANDLERS

    /**
     * AJAX: pedidos procesados con filtros y paginación
     */
    protected function getProcessedOrdersAjax()
    {
        try {
            $page = max(1, (int) Tools::getValue('page', 1));
            $limit = (int) Tools::getValue('limit', 25);
            if ($limit < 1 || $limit > 200) {
                $limit = 25;
            }

            $where = $this->getProcessedWhere();
            $db = Db::getInstance();

            $total = (int) $db->getValue(
                'SELECT COUNT(DISTINCT o.id_order)
                 FROM `' . _DB_PREFIX_ . 'syncrosevi_order_tracking` ot
                 JOIN `' . _DB_PREFIX_ . 'syncrosevi_child_shops` cs ON ot.id_child_shop = cs.id_child_shop
                 JOIN `' . _DB_PREFIX_ . 'orders` o ON ot.id_mother_order = o.id_order
                 JOIN `' . _DB_PREFIX_ . 'customer` c ON o.id_customer = c.id_customer
                 WHERE ' . $where
            );

            $orders = $db->executeS($this->getProcessedSelect($where) . ' LIMIT ' . (($page - 1) * $limit) . ', ' . $limit);

            foreach ($orders as &$order) {
                $order['order_url'] = $this->getOrderAdminUrl($order['id_mother_order']);
                $order['total_paid'] = number_format((float) $order['total_paid'], 2, ',', '.');
                $order['total_products'] = (int) $order['total_products'];
            }
            unset($order);

            $this->ajaxResponse(true, 'success', array('orders' => $orders, 'total' => $total));
        } catch (Exception $e) {
            $this->ajaxResponse(false, $e->getMessage());
        }
    }

    /** Filtros de pedidos procesados (tienda, fechas, búsqueda) */
    protected function getProcessedWhere()
    {
        $where = 'ot.processed = 1 AND ot.id_mother_order > 0';
        $shop = trim((string) Tools::getValue('shop', ''));
        $dateFrom = (string) Tools::getValue('dateFrom', '');
        $dateTo = (string) Tools::getValue('dateTo', '');
        $search = trim((string) Tools::getValue('search', ''));

        if ($shop !== '') {
            $where .= ctype_digit($shop) ? ' AND cs.id_child_shop = ' . (int) $shop : ' AND cs.name LIKE "%' . pSQL($shop) . '%"';
        }
        if ($dateFrom && Validate::isDate($dateFrom)) {
            $where .= ' AND ot.date_processed >= "' . pSQL($dateFrom) . ' 00:00:00"';
        }
        if ($dateTo && Validate::isDate($dateTo)) {
            $where .= ' AND ot.date_processed <= "' . pSQL($dateTo) . ' 23:59:59"';
        }
        if ($search !== '') {
            $s = pSQL($search);
            $where .= ' AND (o.reference LIKE "%' . $s . '%" OR o.id_order = ' . (int) $search . ' OR c.firstname LIKE "%' . $s . '%" OR c.lastname LIKE "%' . $s . '%" OR c.email LIKE "%' . $s . '%")';
        }

        return $where;
    }

    /** Consulta de pedidos procesados: un registro por pedido madre (el total de productos no se multiplica) */
    protected function getProcessedSelect($where)
    {
        return 'SELECT
                    o.id_order AS id_mother_order,
                    o.reference,
                    o.total_paid_tax_incl AS total_paid,
                    o.date_add,
                    MAX(cs.name) AS shop_name,
                    MAX(CONCAT(c.firstname, " ", c.lastname)) AS customer_name,
                    MAX(c.email) AS customer_email,
                    COUNT(DISTINCT ot.id_original_order) AS original_orders,
                    (SELECT SUM(od.product_quantity) FROM `' . _DB_PREFIX_ . 'order_detail` od WHERE od.id_order = o.id_order) AS total_products,
                    MAX(ot.date_processed) AS date_processed
                FROM `' . _DB_PREFIX_ . 'syncrosevi_order_tracking` ot
                JOIN `' . _DB_PREFIX_ . 'syncrosevi_child_shops` cs ON ot.id_child_shop = cs.id_child_shop
                JOIN `' . _DB_PREFIX_ . 'orders` o ON ot.id_mother_order = o.id_order
                JOIN `' . _DB_PREFIX_ . 'customer` c ON o.id_customer = c.id_customer
                WHERE ' . $where . '
                GROUP BY o.id_order, o.reference, o.total_paid_tax_incl, o.date_add
                ORDER BY date_processed DESC, o.id_order DESC';
    }

    /** Enlace a la ficha del pedido (Symfony en 1.7.7+, clásico antes) */
    protected function getOrderAdminUrl($idOrder)
    {
        $idOrder = (int) $idOrder;
        if (version_compare(_PS_VERSION_, '1.7.7.0', '>=')) {
            return $this->context->link->getAdminLink('AdminOrders', true, array('route' => 'admin_orders_view', 'orderId' => $idOrder), array('id_order' => $idOrder, 'vieworder' => 1));
        }

        return $this->context->link->getAdminLink('AdminOrders') . '&vieworder&id_order=' . $idOrder;
    }

    /**
     * AJAX: Obtener detalles de pedido
     */
    protected function getOrderDetailsAjax()
    {
        try {
            $orderId = (int) Tools::getValue('id_order');
            if (!$orderId) {
                throw new Exception('ID de pedido inválido');
            }

            $order = Db::getInstance()->getRow('
                SELECT
                    o.*,
                    os.name as state_name,
                    CONCAT(c.firstname, " ", c.lastname) as customer_name,
                    c.email as customer_email,
                    CONCAT(a.address1, ", ", a.city, " ", a.postcode) as delivery_address,
                    ca.name as carrier_name
                FROM `' . _DB_PREFIX_ . 'orders` o
                LEFT JOIN `' . _DB_PREFIX_ . 'order_state_lang` os ON o.current_state = os.id_order_state AND os.id_lang = ' . (int) $this->context->language->id . '
                JOIN `' . _DB_PREFIX_ . 'customer` c ON o.id_customer = c.id_customer
                LEFT JOIN `' . _DB_PREFIX_ . 'address` a ON o.id_address_delivery = a.id_address
                LEFT JOIN `' . _DB_PREFIX_ . 'carrier` ca ON o.id_carrier = ca.id_carrier
                WHERE o.id_order = ' . $orderId
            );
            if (!$order) {
                throw new Exception('Pedido no encontrado');
            }

            $products = Db::getInstance()->executeS('
                SELECT product_name, product_reference, product_quantity,
                       unit_price_tax_incl as unit_price, total_price_tax_incl as total_price
                FROM `' . _DB_PREFIX_ . 'order_detail`
                WHERE id_order = ' . $orderId
            );

            $syncInfo = Db::getInstance()->getRow('
                SELECT
                    MAX(cs.name) as shop_name,
                    COUNT(DISTINCT ot.id_original_order) as original_orders,
                    GROUP_CONCAT(ot.id_original_order ORDER BY ot.id_original_order SEPARATOR ", ") as original_ids,
                    MIN(ot.date_sync) as date_sync,
                    MAX(ot.date_processed) as date_processed
                FROM `' . _DB_PREFIX_ . 'syncrosevi_order_tracking` ot
                JOIN `' . _DB_PREFIX_ . 'syncrosevi_child_shops` cs ON ot.id_child_shop = cs.id_child_shop
                WHERE ot.id_mother_order = ' . $orderId . '
                GROUP BY cs.id_child_shop
            ');

            // Solo los campos que usa la ventana (no se envía el pedido entero con secure_key, etc.)
            $data = array(
                'id_order' => (int) $order['id_order'],
                'reference' => $order['reference'],
                'state_name' => $order['state_name'],
                'customer_name' => $order['customer_name'],
                'customer_email' => $order['customer_email'],
                'delivery_address' => $order['delivery_address'],
                'carrier_name' => $order['carrier_name'],
                'payment' => $order['payment'],
                'date_add' => $order['date_add'],
                'total_paid' => number_format((float) $order['total_paid_tax_incl'], 2, ',', '.'),
                'total_paid_tax_incl' => $order['total_paid_tax_incl'],
                'total_products' => $order['total_products'],
                'total_shipping' => $order['total_shipping'],
                'order_url' => $this->getOrderAdminUrl($orderId),
                'products' => $products,
                'sync_info' => $syncInfo,
            );

            $this->ajaxResponse(true, 'success', array('order' => $data));
        } catch (Exception $e) {
            $this->ajaxResponse(false, $e->getMessage());
        }
    }

    /**
     * AJAX: Obtener estados de tienda hija
     */
    protected function getChildShopStatesAjax()
    {
        $url = trim((string) Tools::getValue('shop_url'));
        $api_key = trim((string) Tools::getValue('api_key'));

        if (empty($url) || empty($api_key)) {
            $this->ajaxResponse(false, 'URL y API Key requeridos');
        }

        try {
            $webservice = $this->getWebservice($url, $api_key);
            $states = $this->parseStates($webservice->get(array('resource' => 'order_states', 'display' => 'full')));
            foreach ($states as &$state) {
                $state['name'] = 'ID ' . $state['id'] . ': ' . $state['name'];
            }
            unset($state);
            $this->ajaxResponse(true, 'success', array('states' => $states));
        } catch (Exception $e) {
            $this->ajaxResponse(false, $e->getMessage());
        }
    }

    /**
     * Exportar pedidos procesados (CSV con los mismos filtros de la pantalla)
     */
    protected function exportProcessedOrders()
    {
        $orders = Db::getInstance()->executeS($this->getProcessedSelect($this->getProcessedWhere()));

        $filename = 'pedidos_procesados_' . date('Y-m-d_H-i-s') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');

        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF"); // BOM para que Excel lea bien los acentos
        fputcsv($output, array(
            'ID Pedido Madre', 'Referencia', 'Tienda Origen', 'Cliente', 'Email',
            'Pedidos Originales', 'Total Productos', 'Total Pagado', 'Fecha Procesado',
        ), ';');

        foreach ((array) $orders as $order) {
            fputcsv($output, array(
                $order['id_mother_order'],
                $order['reference'],
                $order['shop_name'],
                $order['customer_name'],
                $order['customer_email'],
                $order['original_orders'],
                (int) $order['total_products'],
                number_format((float) $order['total_paid'], 2, ',', '.'),
                $order['date_processed'] ? date('d/m/Y H:i', strtotime($order['date_processed'])) : '',
            ), ';');
        }

        fclose($output);
        exit;
    }

    // PROCESAMIENTO DE FORMULARIOS

    /** Lee y valida los campos del formulario de tienda */
    protected function getShopFormData()
    {
        $data = array(
            'name' => trim((string) Tools::getValue('shop_name')),
            'url' => rtrim(trim((string) Tools::getValue('shop_url')), '/'),
            'api_key' => trim((string) Tools::getValue('api_key')),
            'id_customer' => (int) Tools::getValue('id_customer'),
            'id_group' => (int) Tools::getValue('id_group'),
            'id_address' => (int) Tools::getValue('id_address'),
            'id_carrier' => Tools::getValue('id_carrier') ? (int) Tools::getValue('id_carrier') : null,
            'id_order_state' => (int) Tools::getValue('id_order_state'),
            'start_order_id' => (int) Tools::getValue('start_order_id') ?: 1,
            'active' => Tools::getValue('active') ? 1 : 0,
            'realtime_enabled' => Tools::getValue('realtime_enabled') ? 1 : 0,
        );
        $import_states = Tools::getValue('import_states');
        $data['import_states'] = is_array($import_states)
            ? implode(',', array_filter(array_map('intval', $import_states)))
            : implode(',', Syncrosevi::parseStates($import_states));

        // Modo de importación (solo lo envía el formulario de edición)
        $clone = false;
        if (Tools::getIsset('sync_mode')) {
            $clone = Tools::getValue('sync_mode') === 'clone';
            $cloneData = $this->getCloneFormData($clone);
            if ($cloneData === false) {
                return false;
            }
            $data = array_merge($data, $cloneData);
        }

        if ($data['name'] === '' || $data['url'] === '' || $data['api_key'] === '' || !$data['id_order_state'] || $data['import_states'] === ''
            || (!$clone && (!$data['id_customer'] || !$data['id_group'] || !$data['id_address']))) {
            $this->errors[] = $this->t('Todos los campos obligatorios deben ser completados');

            return false;
        }
        if (!filter_var($data['url'], FILTER_VALIDATE_URL)) {
            $this->errors[] = $this->t('La URL no es válida');

            return false;
        }
        // En modo clonar el cliente asignado no se usa: solo se comprueba si se ha indicado
        if (!$clone || $data['id_address']) {
            $address = new Address($data['id_address']);
            if (!Validate::isLoadedObject($address) || (int) $address->id_customer !== $data['id_customer']) {
                $this->errors[] = $this->t('La dirección elegida no pertenece al cliente asignado');

                return false;
            }
        }

        return $data;
    }

    /** Campos del modo clonar */
    protected function getCloneFormData($clone)
    {
        $data = array(
            'sync_mode' => $clone ? 'clone' : 'consolidated',
            'clone_missing' => Tools::getValue('clone_missing') === 'substitute' ? 'substitute' : 'hold',
            'clone_date' => Tools::getValue('clone_date') === 'original' ? 'original' : 'import',
            'clone_state_sync' => min(3, max(0, (int) Tools::getValue('clone_state_sync'))),
            'clone_state_email' => Tools::getValue('clone_state_email') ? 1 : 0,
            'id_product_substitute' => 0,
        );

        $sub = trim((string) Tools::getValue('substitute_product'));
        if ($sub !== '') {
            $idProduct = ctype_digit($sub) ? (int) Db::getInstance()->getValue('SELECT id_product FROM `' . _DB_PREFIX_ . 'product` WHERE id_product = ' . (int) $sub) : 0;
            if (!$idProduct) {
                $idProduct = (int) Db::getInstance()->getValue('SELECT id_product FROM `' . _DB_PREFIX_ . 'product` WHERE reference = "' . pSQL($sub) . '"');
            }
            if (!$idProduct) {
                $this->errors[] = sprintf($this->t('No existe ningún producto con ID o referencia «%s» para usar como sustitución'), $sub);

                return false;
            }
            $data['id_product_substitute'] = $idProduct;
        }
        if ($clone && $data['clone_missing'] === 'substitute' && !$data['id_product_substitute']) {
            $this->errors[] = $this->t('Indica el producto de sustitución o elige retener los pedidos con productos que falten');

            return false;
        }

        foreach (array('clone_state_map', 'clone_state_map_out', 'clone_tax_map') as $field) {
            if (Tools::getValue($field . '_keep')) {
                continue; // la tienda hija no respondió: se conserva lo guardado
            }
            $map = array();
            $posted = Tools::getValue($field);
            if (is_array($posted)) {
                foreach ($posted as $from => $to) {
                    if ((int) $from && (int) $to) {
                        $map[(int) $from] = (int) $to;
                    }
                }
            }
            $data[$field] = pSQL(json_encode($map));
        }

        return $data;
    }

    /**
     * Procesar adición de tienda
     */
    protected function doAddShop()
    {
        if (!Tools::isSubmit('submitAddShop')) {
            return;
        }
        $data = $this->getShopFormData();
        if (!$data) {
            return;
        }

        $data['name'] = pSQL($data['name']);
        $data['url'] = pSQL($data['url']);
        $data['api_key'] = pSQL($data['api_key']);
        $data['import_states'] = pSQL($data['import_states']);
        $data['webhook_secret'] = bin2hex(random_bytes(32));
        $data['date_add'] = date('Y-m-d H:i:s');
        $data['date_upd'] = date('Y-m-d H:i:s');

        if (Db::getInstance()->insert('syncrosevi_child_shops', $data, true)) {
            $newId = (int) Db::getInstance()->Insert_ID();
            Syncrosevi::refreshCloneFlag();
            // Redirección para que al recargar no se vuelva a añadir; se abre la edición para descargar el Sender
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminSyncrosevi') . '&action=edit&id_child_shop=' . $newId . '&conf=3');
        } else {
            $this->errors[] = $this->t('Error al añadir la tienda hija');
        }
    }

    /**
     * Procesar edición de tienda
     */
    protected function doEditShop()
    {
        if (!Tools::isSubmit('submitEditShop')) {
            return;
        }
        $id_shop = (int) Tools::getValue('id_child_shop');
        if (!$id_shop) {
            $this->errors[] = $this->t('ID de tienda inválido');

            return;
        }
        $data = $this->getShopFormData();
        if (!$data) {
            return;
        }

        $data['name'] = pSQL($data['name']);
        $data['url'] = pSQL($data['url']);
        $data['api_key'] = pSQL($data['api_key']);
        $data['import_states'] = pSQL($data['import_states']);
        $data['date_upd'] = date('Y-m-d H:i:s');

        if (Db::getInstance()->update('syncrosevi_child_shops', $data, 'id_child_shop = ' . $id_shop, 0, true)) {
            Syncrosevi::refreshCloneFlag();
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminSyncrosevi') . '&conf=4');
        } else {
            $this->errors[] = $this->t('Error al actualizar la tienda hija');
        }
    }

    /**
     * Procesar eliminación de tienda
     */
    protected function doDeleteShop()
    {
        $id_shop = (int) Tools::getValue('id_child_shop');
        if (!$id_shop) {
            $this->errors[] = $this->t('ID de tienda inválido');

            return;
        }

        $pendingCount = (int) Db::getInstance()->getValue(
            'SELECT COUNT(DISTINCT id_original_order) FROM `' . _DB_PREFIX_ . 'syncrosevi_order_lines`
             WHERE id_child_shop = ' . $id_shop . ' AND processed = 0'
        );
        if ($pendingCount > 0) {
            $this->errors[] = sprintf($this->t('No se puede eliminar la tienda porque tiene %d pedidos pendientes'), $pendingCount);

            return;
        }

        Db::getInstance()->delete('syncrosevi_order_tracking', 'id_child_shop = ' . $id_shop);
        Db::getInstance()->delete('syncrosevi_order_lines', 'id_child_shop = ' . $id_shop);

        if (Db::getInstance()->delete('syncrosevi_child_shops', 'id_child_shop = ' . $id_shop)) {
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminSyncrosevi') . '&conf=1');
        } else {
            $this->errors[] = $this->t('Error al eliminar la tienda hija');
        }
    }

    /**
     * Test de conexión de una tienda guardada
     */
    protected function doTestConnection()
    {
        $id_shop = (int) Tools::getValue('id_child_shop');
        $shop = $id_shop ? Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'syncrosevi_child_shops` WHERE id_child_shop = ' . $id_shop) : false;
        if (!$shop) {
            $this->ajaxResponse(false, 'Tienda no encontrada');
        }
        $this->testCredentials($shop['url'], $shop['api_key']);
    }

    /**
     * Test de conexión con la URL y clave escritas en el formulario (sin guardar)
     */
    protected function doTestCredentials()
    {
        $url = rtrim(trim((string) Tools::getValue('shop_url')), '/');
        $key = trim((string) Tools::getValue('api_key'));
        if (!$url || !$key) {
            $this->ajaxResponse(false, 'URL y API Key requeridos');
        }
        $this->testCredentials($url, $key);
    }

    protected function testCredentials($url, $key)
    {
        try {
            $webservice = $this->getWebservice($url, $key);
            $webservice->timeout = 15;
            $connection = $webservice->testConnection();
            $shopInfo = $connection ? $webservice->getShopInfo() : array();
            // Además se comprueba que la clave puede leer pedidos (lo que realmente se usa)
            $ordersOk = false;
            if ($connection) {
                try {
                    $xml = $webservice->get(array('resource' => 'orders', 'display' => '[id]', 'limit' => '1'));
                    $ordersOk = (bool) $xml;
                } catch (Exception $e) {
                    $ordersOk = false;
                }
            }
            $message = $connection
                ? ($ordersOk ? 'Conexión exitosa' : 'Conexión correcta, pero la clave API no tiene permiso para leer pedidos (orders, order_details, order_states)')
                : 'Error de conexión';
            $this->ajaxResponse($connection && $ordersOk, $message, array('shop_info' => $shopInfo));
        } catch (Exception $e) {
            $this->ajaxResponse(false, $e->getMessage());
        }
    }

    /**
     * Sincronizar (todas las tiendas o solo una)
     */
    protected function doSyncOrders()
    {
        try {
            @set_time_limit(300);
            $results = $this->module->syncOrders((int) Tools::getValue('id_child_shop') ?: null);
            if (empty($results)) {
                $this->warnings[] = $this->t('No hay tiendas activas que sincronizar');
            }
            foreach ($results as $result) {
                if ($result['status'] == 'success') {
                    $msg = $result['shop'] . ': ' . $result['count'] . ' pedidos sincronizados';
                    if (!empty($result['more'])) {
                        $msg .= ' (quedan más pedidos: vuelve a sincronizar)';
                    }
                    $this->confirmations[] = $msg;
                } else {
                    $this->errors[] = $result['shop'] . ': Error - ' . $result['message'];
                }
            }
        } catch (Exception $e) {
            $this->errors[] = $this->t('Error en la sincronización: ') . $e->getMessage();
        }
    }

    /**
     * Procesar pedidos (todas las tiendas o solo una, con límite por lotes)
     */
    protected function processOrders()
    {
        try {
            @set_time_limit(300);
            $batchLimit = (int) Configuration::get('SYNCROSEVI_BATCH_LIMIT') ?: 10;
            $results = $this->module->processOrders($batchLimit, (int) Tools::getValue('id_child_shop') ?: null);

            if (empty($results)) {
                $this->warnings[] = $this->t('No hay pedidos pendientes que procesar');
            }
            foreach ($results as $result) {
                if ($result['status'] == 'success') {
                    if (isset($result['order_id'])) {
                        $this->confirmations[] = $result['shop'] . ': Pedido #' . $result['order_id'] . ' creado con ' . $result['products_count'] . ' productos (' . $result['orders_count'] . ' pedidos de la tienda hija)';
                    } else {
                        $this->confirmations[] = $result['shop'] . ': ' . $result['message'];
                    }
                } else {
                    $this->errors[] = $result['shop'] . ': Error - ' . $result['message'];
                }
            }
            $remaining = (int) Db::getInstance()->getValue('SELECT COUNT(DISTINCT id_child_shop, id_original_order) FROM `' . _DB_PREFIX_ . 'syncrosevi_order_lines` WHERE processed = 0');
            if ($remaining && !empty($results)) {
                $this->informations[] = sprintf($this->t('Quedan %d pedidos pendientes (se procesan %d por tienda en cada ejecución).'), $remaining, $batchLimit);
            }
        } catch (Exception $e) {
            $this->errors[] = $this->t('Error en el procesamiento: ') . $e->getMessage();
        }
    }

    // MÉTODOS DE DATOS

    protected function getStats()
    {
        $db = Db::getInstance();

        return array(
            'total_shops' => (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'syncrosevi_child_shops`'),
            'active_shops' => (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'syncrosevi_child_shops` WHERE active = 1'),
            'pending_orders' => (int) $db->getValue('SELECT COUNT(DISTINCT id_child_shop, id_original_order) FROM `' . _DB_PREFIX_ . 'syncrosevi_order_lines` WHERE processed = 0'),
            'processed_orders' => (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'syncrosevi_order_tracking` WHERE processed = 1'),
        );
    }

    protected function getConfiguredShops()
    {
        return Db::getInstance()->executeS(
            'SELECT cs.*, c.firstname, c.lastname, c.email,
                    a.alias as address_alias, a.address1, a.city,
                    g.name as group_name, os.name as state_name, ca.name as carrier_name,
                    (SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'syncrosevi_order_tracking` ot
                     WHERE ot.id_child_shop = cs.id_child_shop AND ot.realtime_sync = 1
                     AND ot.date_realtime >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as realtime_orders_24h,
                    (SELECT COUNT(DISTINCT ol.id_original_order) FROM `' . _DB_PREFIX_ . 'syncrosevi_order_lines` ol
                     WHERE ol.id_child_shop = cs.id_child_shop AND ol.processed = 0) as pending_count
             FROM `' . _DB_PREFIX_ . 'syncrosevi_child_shops` cs
             LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON cs.id_customer = c.id_customer
             LEFT JOIN `' . _DB_PREFIX_ . 'address` a ON cs.id_address = a.id_address
             LEFT JOIN `' . _DB_PREFIX_ . 'group_lang` g ON cs.id_group = g.id_group AND g.id_lang = ' . (int) $this->context->language->id . '
             LEFT JOIN `' . _DB_PREFIX_ . 'order_state_lang` os ON cs.id_order_state = os.id_order_state AND os.id_lang = ' . (int) $this->context->language->id . '
             LEFT JOIN `' . _DB_PREFIX_ . 'carrier` ca ON cs.id_carrier = ca.id_carrier
             ORDER BY cs.name ASC'
        );
    }

    /**
     * Direcciones de un cliente
     */
    protected function getAddresses($idCustomer)
    {
        if (!(int) $idCustomer) {
            return array();
        }

        return Db::getInstance()->executeS(
            'SELECT a.id_address, a.id_customer, a.alias, a.firstname, a.lastname, a.address1, a.city, a.postcode
             FROM `' . _DB_PREFIX_ . 'address` a
             WHERE a.deleted = 0 AND a.id_customer = ' . (int) $idCustomer . '
             ORDER BY a.alias ASC'
        );
    }

    /**
     * AJAX: buscar clientes por nombre, email o ID
     */
    protected function searchCustomersAjax()
    {
        $q = trim((string) Tools::getValue('q'));
        if (Tools::strlen($q) < 2 && !ctype_digit($q)) {
            $this->ajaxResponse(true, 'success', array('customers' => array()));
        }
        $s = pSQL($q);
        $customers = Db::getInstance()->executeS(
            'SELECT id_customer, firstname, lastname, email, company
             FROM `' . _DB_PREFIX_ . 'customer`
             WHERE deleted = 0 AND (
                CONCAT(firstname, " ", lastname) LIKE "%' . $s . '%"
                OR email LIKE "%' . $s . '%"
                OR company LIKE "%' . $s . '%"'
                . (ctype_digit($q) ? ' OR id_customer = ' . (int) $q : '') . '
             )
             ORDER BY lastname, firstname
             LIMIT 15'
        );
        $this->ajaxResponse(true, 'success', array('customers' => $customers));
    }

    /**
     * AJAX: direcciones de un cliente
     */
    protected function getCustomerAddressesAjax()
    {
        $this->ajaxResponse(true, 'success', array('addresses' => $this->getAddresses((int) Tools::getValue('id_customer'))));
    }

    protected function getPendingOrders()
    {
        return Db::getInstance()->executeS(
            'SELECT cs.name as shop_name, cs.id_child_shop,
                   ol.id_original_order,
                   GROUP_CONCAT(CONCAT(ol.product_name, " (", ol.quantity, ")") SEPARATOR ", ") as products,
                   SUM(ol.quantity) as total_quantity,
                   COUNT(ol.id_line) as lines_count,
                   MIN(ol.date_add) as first_sync,
                   (SELECT t.clone_error FROM `' . _DB_PREFIX_ . 'syncrosevi_order_tracking` t
                    WHERE t.id_child_shop = ol.id_child_shop AND t.id_original_order = ol.id_original_order LIMIT 1) as clone_error
            FROM `' . _DB_PREFIX_ . 'syncrosevi_order_lines` ol
            JOIN `' . _DB_PREFIX_ . 'syncrosevi_child_shops` cs ON ol.id_child_shop = cs.id_child_shop
            WHERE ol.processed = 0
            GROUP BY cs.id_child_shop, cs.name, ol.id_original_order
            ORDER BY first_sync DESC
            LIMIT 10'
        );
    }

    protected function getAllPendingOrders()
    {
        return Db::getInstance()->executeS(
            'SELECT cs.name as shop_name, cs.id_child_shop,
                   ol.id_original_order,
                   GROUP_CONCAT(CONCAT(ol.product_name, " (", ol.quantity, ")") SEPARATOR ", ") as products,
                   SUM(ol.quantity) as total_quantity,
                   COUNT(ol.id_line) as lines_count,
                   MIN(ol.date_add) as first_sync,
                   (SELECT t.clone_error FROM `' . _DB_PREFIX_ . 'syncrosevi_order_tracking` t
                    WHERE t.id_child_shop = ol.id_child_shop AND t.id_original_order = ol.id_original_order LIMIT 1) as clone_error
            FROM `' . _DB_PREFIX_ . 'syncrosevi_order_lines` ol
            JOIN `' . _DB_PREFIX_ . 'syncrosevi_child_shops` cs ON ol.id_child_shop = cs.id_child_shop
            WHERE ol.processed = 0
            GROUP BY cs.id_child_shop, cs.name, ol.id_original_order
            ORDER BY cs.name ASC, first_sync DESC
            LIMIT 2000'
        );
    }

    /**
     * Respuesta AJAX estandarizada
     */
    protected function ajaxResponse($success, $message, $data = array())
    {
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode(array_merge(array(
            'success' => (bool) $success,
            'message' => $message,
        ), $data)));
    }

    /**
     * AJAX: Obtener productos de un pedido pendiente específico
     */
    protected function getOrderProductsAjax()
    {
        try {
            $shopId = (int) Tools::getValue('shop_id');
            $orderId = (int) Tools::getValue('order_id');
            if (!$shopId || !$orderId) {
                throw new Exception('Parámetros inválidos');
            }

            $shop = Db::getInstance()->getRow(
                'SELECT id_child_shop, name, url, active FROM `' . _DB_PREFIX_ . 'syncrosevi_child_shops` WHERE id_child_shop = ' . $shopId
            );
            if (!$shop) {
                throw new Exception('Tienda no encontrada');
            }

            $products = Db::getInstance()->executeS('
                SELECT product_reference, product_name, quantity, date_add
                FROM `' . _DB_PREFIX_ . 'syncrosevi_order_lines`
                WHERE id_child_shop = ' . $shopId . ' AND id_original_order = ' . $orderId . ' AND processed = 0
                ORDER BY product_name ASC
            ');
            if (empty($products)) {
                throw new Exception('No se encontraron productos para este pedido');
            }

            $this->ajaxResponse(true, 'success', array(
                'shop' => $shop,
                'products' => $products,
                'total_products' => count($products),
                'total_quantity' => array_sum(array_column($products, 'quantity')),
            ));
        } catch (Exception $e) {
            $this->ajaxResponse(false, $e->getMessage());
        }
    }

    /**
     * AJAX: Eliminar un pedido pendiente específico
     */
    protected function deletePendingOrderAjax()
    {
        try {
            $shopId = (int) Tools::getValue('shop_id');
            $orderId = (int) Tools::getValue('order_id');
            if (!$shopId || !$orderId) {
                throw new Exception('Parámetros inválidos');
            }

            $where = 'id_child_shop = ' . $shopId . ' AND id_original_order = ' . $orderId . ' AND processed = 0';
            $pendingLines = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'syncrosevi_order_lines` WHERE ' . $where);
            if (!$pendingLines) {
                throw new Exception('No se encontró el pedido pendiente');
            }

            Db::getInstance()->delete('syncrosevi_order_lines', $where);
            $deletedTracking = Db::getInstance()->delete('syncrosevi_order_tracking', $where);

            $this->ajaxResponse(true, 'Pedido eliminado correctamente', array(
                'deleted_lines' => $pendingLines,
                'deleted_tracking' => $deletedTracking,
            ));
        } catch (Exception $e) {
            $this->ajaxResponse(false, $e->getMessage());
        }
    }

    /**
     * Regenerar clave secreta de webhook
     */
    protected function regenerateWebhookSecret()
    {
        try {
            $id_shop = (int) Tools::getValue('id_child_shop');
            if (!$id_shop) {
                throw new Exception('ID de tienda inválido');
            }
            $new_secret = bin2hex(random_bytes(32));
            $updated = Db::getInstance()->update('syncrosevi_child_shops', array(
                'webhook_secret' => pSQL($new_secret),
                'date_upd' => date('Y-m-d H:i:s'),
            ), 'id_child_shop = ' . $id_shop);

            if (!$updated) {
                throw new Exception('Error al actualizar la clave secreta');
            }
            $shop = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'syncrosevi_child_shops` WHERE id_child_shop = ' . $id_shop);
            $this->ajaxResponse(true, 'Clave secreta regenerada correctamente. Vuelve a configurar SyncroSevi Sender en la tienda hija (descarga el módulo preconfigurado o pega el nuevo código de configuración).', array(
                'new_secret' => $new_secret,
                'config_code' => $shop ? $this->getSenderConfigCode($shop) : '',
            ));
        } catch (Exception $e) {
            $this->ajaxResponse(false, $e->getMessage());
        }
    }

    /**
     * AJAX: Obtener información de webhook de una tienda
     */
    protected function getWebhookInfoAjax()
    {
        try {
            $id_shop = (int) Tools::getValue('id_child_shop');
            if (!$id_shop) {
                throw new Exception('ID de tienda inválido');
            }

            $shop = Db::getInstance()->getRow(
                'SELECT * FROM `' . _DB_PREFIX_ . 'syncrosevi_child_shops` WHERE id_child_shop = ' . $id_shop
            );
            if (!$shop) {
                throw new Exception('Tienda no encontrada');
            }

            $webhook_stats = array(
                'total_received' => (int) Db::getInstance()->getValue(
                    'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'syncrosevi_order_tracking`
                     WHERE id_child_shop = ' . $id_shop . ' AND realtime_sync = 1'
                ),
                'last_24h' => (int) Db::getInstance()->getValue(
                    'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'syncrosevi_order_tracking`
                     WHERE id_child_shop = ' . $id_shop . ' AND realtime_sync = 1
                     AND date_realtime >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
                ),
            );

            $adminLink = $this->context->link->getAdminLink('AdminSyncrosevi');
            $this->ajaxResponse(true, 'success', array(
                'shop' => array(
                    'id_child_shop' => (int) $shop['id_child_shop'],
                    'name' => $shop['name'],
                    'url' => $shop['url'],
                    'webhook_secret' => $shop['webhook_secret'],
                    'realtime_enabled' => (int) $shop['realtime_enabled'],
                    'last_webhook' => $shop['last_webhook'],
                ),
                'webhook_url' => $this->module->getWebhookUrl('realtime_sync'),
                'webhook_stats' => $webhook_stats,
                'config_code' => $this->getSenderConfigCode($shop),
                'download_url' => $adminLink . '&action=download_child_module&id_child_shop=' . $id_shop,
                'download_clean_url' => $adminLink . '&action=download_sender_clean',
            ));
        } catch (Exception $e) {
            $this->ajaxResponse(false, $e->getMessage());
        }
    }

    /**
     * Código de configuración para pegar en SyncroSevi Sender (instalación limpia)
     */
    protected function getSenderConfigCode($shop)
    {
        return 'SSV1:' . base64_encode(json_encode(array(
            'url' => $this->module->getWebhookUrl('realtime_sync'),
            'shop_id' => (int) $shop['id_child_shop'],
            'secret' => $shop['webhook_secret'],
            'states' => $shop['import_states'],
            'name' => $shop['name'],
        )));
    }

    /**
     * Descargar el módulo SyncroSevi Sender para la tienda hija
     *
     * @param bool $preconfigured true = con la configuración de la tienda (se aplica sola al instalar),
     *                            false = limpio, para configurarlo a mano en la tienda hija
     */
    protected function downloadChildModule($preconfigured)
    {
        $shop = null;
        if ($preconfigured) {
            $id_shop = (int) Tools::getValue('id_child_shop');
            $shop = $id_shop ? Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'syncrosevi_child_shops` WHERE id_child_shop = ' . $id_shop) : false;
            if (!$shop) {
                $this->errors[] = $this->t('Tienda no encontrada');
                $this->displayMainContent();

                return;
            }
        }

        $zipFile = null;
        try {
            if (!class_exists('ZipArchive')) {
                throw new Exception('La extensión ZIP de PHP no está disponible en el servidor');
            }
            $source = _PS_MODULE_DIR_ . 'syncrosevi/syncrosevisender';
            if (!is_dir($source)) {
                throw new Exception('No se encuentra la carpeta syncrosevisender dentro del módulo');
            }

            $zipFile = tempnam(_PS_CACHE_DIR_, 'ssv');
            $zip = new ZipArchive();
            if ($zip->open($zipFile, ZipArchive::OVERWRITE) !== true) {
                throw new Exception('No se puede crear archivo ZIP');
            }
            $zip->addEmptyDir('syncrosevisender');
            $this->addDirectoryToZip($zip, $source, 'syncrosevisender');

            if ($preconfigured) {
                $zip->addFromString('syncrosevisender/preset.php', $this->generatePresetFile($shop));
                $zip->addFromString('syncrosevisender/LEEME.txt', $this->generateReadme($shop));
                $filename = 'syncrosevisender_' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', $shop['name']) . '_preconfigurado.zip';
            } else {
                $zip->addFromString('syncrosevisender/LEEME.txt', $this->generateReadme(null));
                $filename = 'syncrosevisender.zip';
            }
            $zip->close();

            while (ob_get_level()) {
                ob_end_clean();
            }
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($zipFile));
            header('Cache-Control: no-cache, must-revalidate');
            readfile($zipFile);
            @unlink($zipFile);
            exit;
        } catch (Exception $e) {
            if ($zipFile) {
                @unlink($zipFile);
            }
            $this->errors[] = 'Error generando módulo: ' . $e->getMessage();
            $this->displayMainContent();
        }
    }

    /** preset.php: configuración que SyncroSevi Sender aplica al instalarse */
    protected function generatePresetFile($shop)
    {
        $preset = array(
            'SYNCROSEVISENDER_ACTIVE' => 1,
            'SYNCROSEVISENDER_MOTHER_URL' => $this->module->getWebhookUrl('realtime_sync'),
            'SYNCROSEVISENDER_SHOP_ID' => (int) $shop['id_child_shop'],
            'SYNCROSEVISENDER_SECRET_KEY' => $shop['webhook_secret'],
            'SYNCROSEVISENDER_SYNC_STATES' => $shop['import_states'],
            'SYNCROSEVISENDER_TIMEOUT' => 5,
            'SYNCROSEVISENDER_MAX_RETRIES' => 2,
        );

        return "<?php\n/**\n * SyncroSevi Sender - configuración generada por la tienda madre\n * Tienda hija: " . str_replace(array('*/', "\n", "\r"), '', $shop['name']) . "\n * Generado: " . date('Y-m-d H:i:s') . "\n * Se aplica automáticamente al instalar el módulo. Se puede cambiar después en Módulos > Configurar.\n */\nif (!defined('_PS_VERSION_')) {\n    exit;\n}\n\nreturn " . var_export($preset, true) . ";\n";
    }

    protected function generateReadme($shop)
    {
        $txt = "=== SYNCROSEVI SENDER ===\n\n";
        $txt .= "Módulo para la TIENDA HIJA: avisa a la tienda madre en tiempo real cuando un pedido cambia a un estado a importar.\n";
        $txt .= "Compatible con PrestaShop 1.7, 8 y 9.\n\n";
        $txt .= "INSTALACIÓN\n1. En la tienda hija: Módulos > Gestor de módulos > Subir un módulo, y sube este ZIP.\n2. Instálalo.\n";
        if ($shop) {
            $txt .= "3. Ya está: viene preconfigurado para \"" . $shop['name'] . "\" (ID " . (int) $shop['id_child_shop'] . ") y prueba la conexión al instalarse.\n";
            $txt .= "   Puedes revisar o cambiar la configuración en Módulos > SyncroSevi Sender > Configurar.\n\n";
        } else {
            $txt .= "3. Pulsa Configurar y pega el «Código de configuración» que aparece en la tienda madre\n   (SyncroSevi > Editar tienda > Webhook), o rellena los campos a mano:\n";
            $txt .= "   - URL del webhook de la tienda madre\n   - ID de la tienda hija (el que tiene en la tienda madre)\n   - Clave secreta\n   - Estados que se envían\n4. Guarda: se prueba la conexión automáticamente.\n\n";
        }
        $txt .= "La tienda hija también necesita el WebService de PrestaShop activo con una clave que pueda leer\norders, order_details y order_states (esa clave se configura en la tienda madre).\n";

        return $txt;
    }

    /**
     * Añadir directorio al ZIP recursivamente (sin logs, presets antiguos ni archivos ocultos)
     */
    private function addDirectoryToZip($zip, $dirPath, $zipPath)
    {
        foreach (scandir($dirPath) as $file) {
            if ($file === '.' || $file === '..' || $file[0] === '.' || $file === 'logs' || $file === 'preset.php') {
                continue;
            }
            $filePath = $dirPath . '/' . $file;
            $zipFilePath = $zipPath . '/' . $file;
            if (is_dir($filePath)) {
                $zip->addEmptyDir($zipFilePath);
                $this->addDirectoryToZip($zip, $filePath, $zipFilePath);
            } else {
                $zip->addFile($filePath, $zipFilePath);
            }
        }
    }
}
