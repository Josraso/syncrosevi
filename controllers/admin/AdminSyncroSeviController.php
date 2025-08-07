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

class AdminSyncroseviController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->context = Context::getContext();
        $this->lang = false;
        
        parent::__construct();
        
        $this->meta_title = $this->l('SyncroSevi - Gestión de Sincronización');
    }

    public function initContent()
    {
        parent::initContent();
        
        $action = Tools::getValue('action');
        
        switch ($action) {
            case 'add_shop':
                $this->processAddShop();
                break;
            case 'edit_shop':
                $this->processEditShop();
                break;
            case 'delete_shop':
                $this->processDeleteShop();
                break;
            case 'test_connection':
                $this->processTestConnection();
                break;
            case 'sync_orders':
                $this->processSyncOrders();
                break;
            case 'process_orders':
                $this->processOrders();
                break;
            case 'view_pending':
                $this->displayPendingOrders();
                return;
            default:
                $this->displayMainContent();
                break;
        }
        
        $this->displayMainContent();
    }

    protected function displayMainContent()
    {
        // Obtener estadísticas
        $stats = $this->getStats();
        
        // Obtener tiendas configuradas
        $shops = $this->getConfiguredShops();
        
        // Asignar variables a Smarty
        $this->context->smarty->assign(array(
            'stats' => $stats,
            'shops' => $shops,
            'customers' => Customer::getCustomers(true),
            'groups' => Group::getGroups($this->context->language->id),
            'carriers' => Carrier::getCarriers($this->context->language->id, true),
            'order_states' => OrderState::getOrderStates($this->context->language->id),
            'addresses' => $this->getAddresses(),
            'admin_link' => $this->context->link->getAdminLink('AdminSyncrosevi'),
            'token' => Tools::getAdminTokenLite('AdminSyncrosevi')
        ));
        
        $this->setTemplate('syncrosevi_main.tpl');
    }

    protected function displayPendingOrders()
    {
        $pendingOrders = $this->getPendingOrders();
        
        $this->context->smarty->assign(array(
            'pending_orders' => $pendingOrders,
            'admin_link' => $this->context->link->getAdminLink('AdminSyncrosevi'),
            'token' => Tools::getAdminTokenLite('AdminSyncrosevi')
        ));
        
        $this->setTemplate('syncrosevi_pending.tpl');
    }

    protected function processAddShop()
    {
        if (Tools::isSubmit('submitAddShop')) {
            $name = Tools::getValue('shop_name');
            $url = Tools::getValue('shop_url');
            $api_key = Tools::getValue('api_key');
            $id_customer = (int)Tools::getValue('id_customer');
            $id_group = (int)Tools::getValue('id_group');
            $id_address = (int)Tools::getValue('id_address');
            $id_carrier = Tools::getValue('id_carrier') ? (int)Tools::getValue('id_carrier') : null;
            $id_order_state = (int)Tools::getValue('id_order_state');
            $active = Tools::getValue('active') ? 1 : 0;

            if (empty($name) || empty($url) || empty($api_key) || !$id_customer || !$id_group || !$id_address || !$id_order_state) {
                $this->errors[] = $this->l('Todos los campos obligatorios deben ser completados');
                return;
            }

            // Validar URL
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                $this->errors[] = $this->l('La URL no es válida');
                return;
            }

            // Insertar nueva tienda
            $inserted = Db::getInstance()->insert('syncrosevi_child_shops', array(
                'name' => pSQL($name),
                'url' => pSQL($url),
                'api_key' => pSQL($api_key),
                'id_customer' => $id_customer,
                'id_group' => $id_group,
                'id_address' => $id_address,
                'id_carrier' => $id_carrier,
                'id_order_state' => $id_order_state,
                'active' => $active,
                'date_add' => date('Y-m-d H:i:s'),
                'date_upd' => date('Y-m-d H:i:s')
            ));

            if ($inserted) {
                $this->confirmations[] = $this->l('Tienda hija añadida correctamente');
            } else {
                $this->errors[] = $this->l('Error al añadir la tienda hija');
            }
        }
    }

    protected function processEditShop()
    {
        if (Tools::isSubmit('submitEditShop')) {
            $id_shop = (int)Tools::getValue('id_child_shop');
            $name = Tools::getValue('shop_name');
            $url = Tools::getValue('shop_url');
            $api_key = Tools::getValue('api_key');
            $id_customer = (int)Tools::getValue('id_customer');
            $id_group = (int)Tools::getValue('id_group');
            $id_address = (int)Tools::getValue('id_address');
            $id_carrier = Tools::getValue('id_carrier') ? (int)Tools::getValue('id_carrier') : null;
            $id_order_state = (int)Tools::getValue('id_order_state');
            $active = Tools::getValue('active') ? 1 : 0;

            if (!$id_shop || empty($name) || empty($url) || empty($api_key) || !$id_customer || !$id_group || !$id_address || !$id_order_state) {
                $this->errors[] = $this->l('Todos los campos obligatorios deben ser completados');
                return;
            }

            // Validar URL
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                $this->errors[] = $this->l('La URL no es válida');
                return;
            }

            $updated = Db::getInstance()->update('syncrosevi_child_shops', array(
                'name' => pSQL($name),
                'url' => pSQL($url),
                'api_key' => pSQL($api_key),
                'id_customer' => $id_customer,
                'id_group' => $id_group,
                'id_address' => $id_address,
                'id_carrier' => $id_carrier,
                'id_order_state' => $id_order_state,
                'active' => $active,
                'date_upd' => date('Y-m-d H:i:s')
            ), 'id_child_shop = ' . $id_shop);

            if ($updated) {
                $this->confirmations[] = $this->l('Tienda hija actualizada correctamente');
            } else {
                $this->errors[] = $this->l('Error al actualizar la tienda hija');
            }
        }
    }

    protected function processDeleteShop()
    {
        $id_shop = (int)Tools::getValue('id_child_shop');
        
        if (!$id_shop) {
            $this->errors[] = $this->l('ID de tienda inválido');
            return;
        }

        $deleted = Db::getInstance()->delete('syncrosevi_child_shops', 'id_child_shop = ' . $id_shop);
        
        if ($deleted) {
            $this->confirmations[] = $this->l('Tienda hija eliminada correctamente');
        } else {
            $this->errors[] = $this->l('Error al eliminar la tienda hija');
        }
    }

    protected function processTestConnection()
    {
        $id_shop = (int)Tools::getValue('id_child_shop');
        
        if (!$id_shop) {
            die(json_encode(array('success' => false, 'message' => 'ID de tienda inválido')));
        }

        $shop = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'syncrosevi_child_shops` WHERE id_child_shop = ' . $id_shop
        );

        if (!$shop) {
            die(json_encode(array('success' => false, 'message' => 'Tienda no encontrada')));
        }

        try {
            require_once(dirname(__FILE__).'/../../classes/SyncroSeviWebservice.php');
            $webservice = new SyncroSeviWebservice($shop['url'], $shop['api_key']);
            $connection = $webservice->testConnection();
            $shopInfo = array();
            
            if ($connection) {
                $shopInfo = $webservice->getShopInfo();
            }
            
            die(json_encode(array(
                'success' => $connection,
                'message' => $connection ? 'Conexión exitosa' : 'Error de conexión',
                'shop_info' => $shopInfo
            )));
            
        } catch (Exception $e) {
            die(json_encode(array('success' => false, 'message' => $e->getMessage())));
        }
    }

    protected function processSyncOrders()
    {
        try {
            $module = Module::getInstanceByName('syncrosevi');
            $results = $module->syncOrders();
            
            $this->confirmations[] = $this->l('Sincronización completada');
            
            foreach ($results as $result) {
                if ($result['status'] == 'success') {
                    $this->confirmations[] = $result['shop'] . ': ' . $result['count'] . ' pedidos sincronizados';
                } else {
                    $this->errors[] = $result['shop'] . ': Error - ' . $result['message'];
                }
            }
        } catch (Exception $e) {
            $this->errors[] = $this->l('Error en la sincronización: ') . $e->getMessage();
        }
    }

    protected function processOrders()
    {
        try {
            $module = Module::getInstanceByName('syncrosevi');
            $results = $module->processOrders();
            
            $this->confirmations[] = $this->l('Procesamiento completado');
            
            foreach ($results as $result) {
                if ($result['status'] == 'success') {
                    $this->confirmations[] = $result['shop'] . ': Pedido #' . $result['order_id'] . ' creado con ' . $result['products_count'] . ' productos';
                } else {
                    $this->errors[] = $result['shop'] . ': Error - ' . $result['message'];
                }
            }
        } catch (Exception $e) {
            $this->errors[] = $this->l('Error en el procesamiento: ') . $e->getMessage();
        }
    }

    protected function getStats()
    {
        $totalShops = (int)Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'syncrosevi_child_shops`'
        );
        
        $activeShops = (int)Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'syncrosevi_child_shops` WHERE active = 1'
        );
        
        $pendingOrders = (int)Db::getInstance()->getValue(
            'SELECT COUNT(DISTINCT id_child_shop, id_original_order) FROM `' . _DB_PREFIX_ . 'syncrosevi_order_lines` WHERE processed = 0'
        );
        
        $processedOrders = (int)Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'syncrosevi_order_tracking` WHERE processed = 1'
        );

        return array(
            'total_shops' => $totalShops,
            'active_shops' => $activeShops,
            'pending_orders' => $pendingOrders,
            'processed_orders' => $processedOrders
        );
    }

    protected function getConfiguredShops()
    {
        return Db::getInstance()->executeS(
            'SELECT cs.*, c.firstname, c.lastname, g.name as group_name, os.name as state_name, ca.name as carrier_name
             FROM `' . _DB_PREFIX_ . 'syncrosevi_child_shops` cs
             LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON cs.id_customer = c.id_customer
             LEFT JOIN `' . _DB_PREFIX_ . 'group_lang` g ON cs.id_group = g.id_group AND g.id_lang = ' . (int)$this->context->language->id . '
             LEFT JOIN `' . _DB_PREFIX_ . 'order_state_lang` os ON cs.id_order_state = os.id_order_state AND os.id_lang = ' . (int)$this->context->language->id . '
             LEFT JOIN `' . _DB_PREFIX_ . 'carrier` ca ON cs.id_carrier = ca.id_carrier
             ORDER BY cs.name ASC'
        );
    }

    protected function getAddresses()
    {
        return Db::getInstance()->executeS(
            'SELECT a.id_address, a.alias, a.firstname, a.lastname, a.address1, a.city, a.postcode
             FROM `' . _DB_PREFIX_ . 'address` a
             WHERE a.deleted = 0
             ORDER BY a.alias ASC'
        );
    }

    protected function getPendingOrders()
    {
        $sql = 'SELECT cs.name as shop_name, cs.id_child_shop,
                       ol.id_original_order, ol.product_name, ol.product_reference, 
                       SUM(ol.quantity) as total_quantity,
                       COUNT(ol.id_line) as lines_count,
                       MIN(ol.date_add) as first_sync
                FROM `' . _DB_PREFIX_ . 'syncrosevi_order_lines` ol
                JOIN `' . _DB_PREFIX_ . 'syncrosevi_child_shops` cs ON ol.id_child_shop = cs.id_child_shop
                WHERE ol.processed = 0
                GROUP BY cs.id_child_shop, ol.id_original_order
                ORDER BY cs.name ASC, ol.id_original_order ASC';
        
        return Db::getInstance()->executeS($sql);
    }

    public function setMedia($isNewTheme = false)
    {
        parent::setMedia($isNewTheme);
        
        $this->addCSS(_MODULE_DIR_ . 'syncrosevi/views/css/admin.css');
        $this->addJS(_MODULE_DIR_ . 'syncrosevi/views/js/admin.js');
    }
}