<?php
/**
 * 2024 SyncroSevi
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    SyncroSevi Team
 * @copyright 2024 SyncroSevi
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Syncrosevi extends Module
{
    private $productCache = array(); // NUEVO: Cache para productos
    private $debug = false; // TEMPORAL: Activar debug
	
    public function __construct()
    {
		
        $this->name = 'syncrosevi';
        $this->tab = 'administration';
        $this->version = '1.0.2';
        $this->author = 'SyncroSevi Team';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array(
            'min' => '1.7.0.0',
            'max' => '8.99.99'
        );
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('SyncroSevi - Sincronización de Pedidos');
$this->description = $this->l('Módulo para sincronizar pedidos entre tienda madre e hijas mediante WebService');
$this->confirmUninstall = $this->l('¿Estás seguro de que quieres desinstalar?');
}

private function log($message) {
    if ($this->debug) {
        $logFile = dirname(__FILE__) . '/logs/syncrosevi_debug.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
        // También enviar a error_log del sistema
        error_log('SyncroSevi: ' . $message);
    }
}

public function install()
    {
        if (!parent::install() ||
            !$this->createTables() ||
            !$this->installTab() ||
            !$this->registerHook('actionObjectOrderHistoryAddAfter')) { // CORREGIDO: Hook correcto
            return false;
        }
        
        return true;
    }

public function uninstall()
{
    if (!parent::uninstall() || !$this->uninstallTab()) {
        return false;
    }
    
    // Eliminar todas las tablas
    $this->dropTables();
    
    return true;
}
private function dropTables()
{
    $sql = array();
    
    $sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'syncrosevi_order_lines`';
    $sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'syncrosevi_order_tracking`';
    $sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'syncrosevi_child_shops`';
    
    foreach ($sql as $query) {
        Db::getInstance()->execute($query);
    }
}
    private function installTab()
    {
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = 'AdminSyncrosevi';
        $tab->name = array();
        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = 'SyncroSevi';
        }
        $tab->id_parent = (int)Tab::getIdFromClassName('AdminParentOrders');
        $tab->module = $this->name;
        return $tab->add();
    }

    private function uninstallTab()
    {
        $id_tab = (int)Tab::getIdFromClassName('AdminSyncrosevi');
        if ($id_tab) {
            $tab = new Tab($id_tab);
            return $tab->delete();
        }
        return false;
    }

    private function createTables()
    {
        $sql = array();

        // Tabla de configuración de tiendas hijas
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'syncrosevi_child_shops` (
            `id_child_shop` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL,
            `url` varchar(500) NOT NULL,
            `api_key` varchar(255) NOT NULL,
            `id_customer` int(11) NOT NULL,
            `id_group` int(11) NOT NULL,
            `id_address` int(11) NOT NULL,
            `id_carrier` int(11) NULL DEFAULT NULL,
            `id_order_state` int(11) NOT NULL,
            `start_order_id` int(11) NOT NULL DEFAULT 1,
            `import_states` varchar(255) NOT NULL DEFAULT "2,3,4,5",
            `active` tinyint(1) NOT NULL DEFAULT 1,
            `date_add` datetime NOT NULL,
            `date_upd` datetime NOT NULL,
            PRIMARY KEY (`id_child_shop`),
            KEY `idx_active` (`active`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

        // Tabla de tracking de pedidos
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'syncrosevi_order_tracking` (
            `id_tracking` int(11) NOT NULL AUTO_INCREMENT,
            `id_original_order` int(11) NOT NULL,
            `id_child_shop` int(11) NOT NULL,
            `id_mother_order` int(11) NULL DEFAULT NULL,
            `processed` tinyint(1) NOT NULL DEFAULT 0,
            `date_sync` datetime NOT NULL,
            `date_processed` datetime NULL DEFAULT NULL,
            PRIMARY KEY (`id_tracking`),
            UNIQUE KEY `unique_order_shop` (`id_original_order`, `id_child_shop`),
            KEY `idx_processed` (`processed`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

        // Tabla temporal de líneas de pedido
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'syncrosevi_order_lines` (
            `id_line` int(11) NOT NULL AUTO_INCREMENT,
            `id_child_shop` int(11) NOT NULL,
            `id_original_order` int(11) NOT NULL,
            `id_product` int(11) NOT NULL,
            `id_product_attribute` int(11) NOT NULL DEFAULT 0,
            `quantity` int(11) NOT NULL,
            `product_reference` varchar(255) NOT NULL,
            `product_name` varchar(255) NOT NULL,
            `processed` tinyint(1) NOT NULL DEFAULT 0,
            `date_add` datetime NOT NULL,
            PRIMARY KEY (`id_line`),
            KEY `idx_child_shop_processed` (`id_child_shop`, `processed`),
            KEY `idx_order_processed` (`id_original_order`, `processed`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

        foreach ($sql as $query) {
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }

        // Actualizar tabla existente si no tiene las nuevas columnas
        $this->updateExistingTables();
        
        return true;
    }
    
    private function updateExistingTables()
    {
        $columns = Db::getInstance()->executeS("SHOW COLUMNS FROM `" . _DB_PREFIX_ . "syncrosevi_child_shops`");
        $hasStartOrderId = false;
        $hasImportStates = false;
        
        foreach ($columns as $column) {
            if ($column['Field'] == 'start_order_id') $hasStartOrderId = true;
            if ($column['Field'] == 'import_states') $hasImportStates = true;
        }
        
        if (!$hasStartOrderId) {
            Db::getInstance()->execute('ALTER TABLE `' . _DB_PREFIX_ . 'syncrosevi_child_shops` ADD COLUMN `start_order_id` int(11) NOT NULL DEFAULT 1 AFTER `id_order_state`');
        }
        
        if (!$hasImportStates) {
            Db::getInstance()->execute('ALTER TABLE `' . _DB_PREFIX_ . 'syncrosevi_child_shops` ADD COLUMN `import_states` varchar(255) NOT NULL DEFAULT "2,3,4,5" AFTER `start_order_id`');
        }
    }

    /**
     * CORREGIDO: Hook correcto para cambios de estado de pedido
     */
    public function hookActionObjectOrderHistoryAddAfter($params)
    {
        try {
            if (!isset($params['object']) || !($params['object'] instanceof OrderHistory)) {
                return;
            }
            
            $orderHistory = $params['object'];
            $orderId = $orderHistory->id_order;
            $newStateId = $orderHistory->id_order_state;
            
            // Obtener tiendas hijas activas
            $childShops = Db::getInstance()->executeS(
                'SELECT * FROM `' . _DB_PREFIX_ . 'syncrosevi_child_shops` WHERE `active` = 1'
            );
            
            if (empty($childShops)) {
                return;
            }
            
            // Sincronizar este pedido específico si cumple condiciones
            $this->syncSingleOrderToTemp($orderId, $newStateId, $childShops);
            
        } catch (Exception $e) {
            $this->log('Hook Error: ' . $e->getMessage());
        }
    }
	
	/**
     * Sincronizar un pedido específico a tabla temporal
     */
    private function syncSingleOrderToTemp($orderId, $stateId, $childShops)
    {
        if (!class_exists('SyncroSeviWebservice')) {
            require_once(dirname(__FILE__).'/classes/SyncroSeviWebservice.php');
        }

        foreach ($childShops as $shop) {
            $importStates = explode(',', $shop['import_states']);
            if (!in_array($stateId, $importStates)) {
                continue;
            }
            
            try {
                $webservice = new SyncroSeviWebservice($shop['url'], $shop['api_key'], false);
                $orders = $webservice->getNewOrders($orderId, $shop['import_states']);
                
                foreach ($orders as $order) {
                    if ($order['id'] == $orderId) {
                        $this->syncOrderToTemp($order, $shop);
                        break;
                    }
                }
                
            } catch (Exception $e) {
                $this->log('Error sincronizando pedido #' . $orderId . ' de ' . $shop['name'] . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Sincronizar un pedido a tabla temporal
     */
    private function syncOrderToTemp($order, $shop)
    {
        // Verificar si ya está sincronizado
        $existing = Db::getInstance()->getRow(
            'SELECT id_tracking FROM `' . _DB_PREFIX_ . 'syncrosevi_order_tracking` 
             WHERE id_original_order = ' . (int)$order['id'] . ' 
             AND id_child_shop = ' . (int)$shop['id_child_shop']
        );

        if ($existing) {
            return false;
        }

        // Insertar tracking
        Db::getInstance()->insert('syncrosevi_order_tracking', array(
            'id_original_order' => (int)$order['id'],
            'id_child_shop' => (int)$shop['id_child_shop'],
            'processed' => 0,
            'date_sync' => date('Y-m-d H:i:s')
        ));

        // Insertar líneas de pedido
        foreach ($order['order_rows'] as $line) {
            Db::getInstance()->insert('syncrosevi_order_lines', array(
                'id_child_shop' => (int)$shop['id_child_shop'],
                'id_original_order' => (int)$order['id'],
                'id_product' => (int)$line['product_id'],
                'id_product_attribute' => (int)$line['product_attribute_id'],
                'quantity' => (int)$line['product_quantity'],
                'product_reference' => pSQL($line['product_reference']),
                'product_name' => pSQL($line['product_name']),
                'processed' => 0,
                'date_add' => date('Y-m-d H:i:s')
            ));
        }

        return true;
    }

    /**
     * Método para ejecutar sincronización manual
     */
    public function syncOrders()
    {
        if (!class_exists('SyncroSeviWebservice')) {
            require_once(dirname(__FILE__).'/classes/SyncroSeviWebservice.php');
        }

        $childShops = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'syncrosevi_child_shops` WHERE `active` = 1'
        );

        $syncResults = array();
        
        foreach ($childShops as $shop) {
            try {
                $webservice = new SyncroSeviWebservice($shop['url'], $shop['api_key'], true);
                $orders = $webservice->getNewOrders($shop['start_order_id'], $shop['import_states']);
                
                $syncCount = 0;
                foreach ($orders as $order) {
                    if ($this->syncOrderToTemp($order, $shop)) {
                        $syncCount++;
                    }
                }
                
                $syncResults[] = array(
                    'shop' => $shop['name'],
                    'count' => $syncCount,
                    'status' => 'success'
                );
                
            } catch (Exception $e) {
                $syncResults[] = array(
                    'shop' => $shop['name'],
                    'count' => 0,
                    'status' => 'error',
                    'message' => $e->getMessage()
                );
            }
        }
        
        return $syncResults;
    }

    /**
     * CORREGIDO: Procesar pedidos pendientes - CREAR UN SOLO PEDIDO CONSOLIDADO
     */
    public function processOrders()
    {
        $childShops = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'syncrosevi_child_shops` WHERE `active` = 1'
        );

        $processResults = array();

       		
		foreach ($childShops as $shop) {
            try {
                // Obtener TODAS las líneas pendientes para esta tienda
                $pendingLines = Db::getInstance()->executeS(
                    'SELECT * FROM `' . _DB_PREFIX_ . 'syncrosevi_order_lines` 
                     WHERE id_child_shop = ' . (int)$shop['id_child_shop'] . ' 
                     AND processed = 0'
                );

                if (empty($pendingLines)) {
                    continue;
                }

// Agrupar productos y sumar cantidades
$products = array();
foreach ($pendingLines as $line) {
    $key = $line['id_product'] . '_' . $line['id_product_attribute'];
    if (isset($products[$key])) {
        $products[$key]['quantity'] += $line['quantity'];
    } else {
        $products[$key] = $line;
    }
}

// DEBUG: Log productos agrupados
$this->log(' DEBUG - Tienda ' . $shop['name'] . ': ' . count($products) . ' productos únicos agrupados');
foreach ($products as $prod) {
    $this->log(' DEBUG - Producto: ' . $prod['product_reference'] . ' (ID: ' . $prod['id_product'] . ') Cantidad: ' . $prod['quantity']);
}

                try {
                    Db::getInstance()->execute('START TRANSACTION');
                    
                    $orderId = null;
                    $validProducts = 0;
                    
                    try {
                        $customer = $this->validateCustomer($shop['id_customer']);
                        $address = $this->validateAddress($shop['id_address'], $customer->id);
                        $cart = $this->createTempCart($customer, $address, $shop);
                        $orderData = $this->calculateOrderData($products, $shop, $cart, $address);
                        $validProducts = count($orderData['products_details']);
                        
                        if ($validProducts > 0) {
                            $orderId = $this->createMotherOrder($products, $shop, $customer, $address, $cart);
                        }
                    } catch (Exception $e) {
                        $this->log(': Error calculando productos: ' . $e->getMessage());
                    }
                    
                    // SIEMPRE marcar como procesado
                    Db::getInstance()->execute(
                        'UPDATE `' . _DB_PREFIX_ . 'syncrosevi_order_lines` 
                         SET processed = 1 
                         WHERE id_child_shop = ' . (int)$shop['id_child_shop'] . ' 
                         AND processed = 0'
                    );

                    Db::getInstance()->execute(
                        'UPDATE `' . _DB_PREFIX_ . 'syncrosevi_order_tracking` 
                         SET id_mother_order = ' . ($orderId ? (int)$orderId : 0) . ', 
                             processed = 1, 
                             date_processed = "' . date('Y-m-d H:i:s') . '" 
                         WHERE id_child_shop = ' . (int)$shop['id_child_shop'] . ' 
                         AND processed = 0'
                    );
                    
                    Db::getInstance()->execute('COMMIT');
                    
                    if ($orderId) {
                        $processResults[] = array(
                            'shop' => $shop['name'],
                            'order_id' => $orderId,
                            'products_count' => $validProducts,
                            'status' => 'success'
                        );
                    } else {
                        $processResults[] = array(
                            'shop' => $shop['name'],
                            'status' => 'success',
                            'message' => 'Procesado sin crear pedido (sin productos válidos)'
                        );
                    }
                } catch (Exception $e) {
                    Db::getInstance()->execute('ROLLBACK');
                    $processResults[] = array(
                        'shop' => $shop['name'],
                        'status' => 'error',
                        'message' => $e->getMessage()
                    );
                }
            } catch (Exception $e) {
                $processResults[] = array(
                    'shop' => $shop['name'],
                    'status' => 'error',
                    'message' => 'Error general: ' . $e->getMessage()
                );
            }
        }

        return $processResults;
    }

    /**
     * NUEVO: Buscar producto por referencia con cache
     */
private function findProductByReference($reference)
{
    if (empty($reference)) {
        return null;
    }
    
    $reference = trim($reference);
    
    // Verificar cache
    if (isset($this->productCache[$reference])) {
        return $this->productCache[$reference];
    }
    
    $result = null;
    
    // 1. Buscar combinación por referencia primero
    $sql = 'SELECT pa.id_product_attribute, pa.id_product FROM ' . _DB_PREFIX_ . 'product_attribute pa ' .
           'JOIN ' . _DB_PREFIX_ . 'product p ON pa.id_product = p.id_product ' .
           'WHERE pa.reference = "' . pSQL($reference) . '"';
    
    $this->log('SQL Combinación (sin LIMIT): ' . $sql);
    $combinations = Db::getInstance()->executeS($sql);
    
    if (!empty($combinations)) {
        $combination_data = $combinations[0]; // Tomar el primero
        $result = array(
            'id_product' => (int)$combination_data['id_product'],
            'id_product_attribute' => (int)$combination_data['id_product_attribute'],
            'type' => 'combination'
        );
        $this->log('Producto encontrado (combinación): "' . $reference . '" -> ID: ' . $result['id_product'] . ', Combinación: ' . $result['id_product_attribute']);
    } else {
        // 2. Buscar producto simple
        $sql2 = 'SELECT id_product FROM ' . _DB_PREFIX_ . 'product ' .
                'WHERE reference = "' . pSQL($reference) . '"';
        
        $this->log('SQL Producto (sin LIMIT): ' . $sql2);
        $products = Db::getInstance()->executeS($sql2);
        
        if (!empty($products)) {
            $result = array(
                'id_product' => (int)$products[0]['id_product'],
                'id_product_attribute' => 0,
                'type' => 'simple'
            );
            $this->log('Producto encontrado (simple): "' . $reference . '" -> ID: ' . $result['id_product']);
        } else {
            $this->log('Producto no encontrado: "' . $reference . '"');
        }
    }
    
    // Guardar en cache
    $this->productCache[$reference] = $result;
    
    return $result;
}
private function debugProductSearch($reference)
{
    error_log('=== DEBUG BÚSQUEDA DE PRODUCTO ===');
    error_log('Buscando referencia: "' . $reference . '"');
    
    // Buscar en combinaciones
    $combinations = Db::getInstance()->executeS(
        'SELECT pa.id_product_attribute, pa.id_product, pa.reference, p.reference as product_ref, p.active
         FROM `' . _DB_PREFIX_ . 'product_attribute` pa
         JOIN `' . _DB_PREFIX_ . 'product` p ON pa.id_product = p.id_product
         WHERE pa.reference = "' . pSQL($reference) . '"'
    );
    
    error_log('Combinaciones encontradas: ' . count($combinations));
    foreach ($combinations as $comb) {
        error_log('- Combinación ID: ' . $comb['id_product_attribute'] . ', Producto ID: ' . $comb['id_product'] . ', Activo: ' . $comb['active']);
    }
    
    // Buscar en productos simples
    $products = Db::getInstance()->executeS(
        'SELECT id_product, reference, active
         FROM `' . _DB_PREFIX_ . 'product` 
         WHERE reference = "' . pSQL($reference) . '"'
    );
    
    error_log('Productos simples encontrados: ' . count($products));
    foreach ($products as $prod) {
        error_log('- Producto ID: ' . $prod['id_product'] . ', Activo: ' . $prod['active']);
    }
    
    error_log('=== FIN DEBUG ===');
}
    private function validateCustomer($customerId)
    {
        $customer = new Customer($customerId);
        if (!Validate::isLoadedObject($customer)) {
            throw new Exception('Cliente ID ' . $customerId . ' no encontrado');
        }
        return $customer;
    }

    private function validateAddress($addressId, $customerId)
    {
        $address = new Address($addressId);
        if (!Validate::isLoadedObject($address)) {
            throw new Exception('Dirección ID ' . $addressId . ' no encontrada');
        }
        
        if ($address->id_customer != $customerId) {
            throw new Exception('La dirección no pertenece al cliente especificado');
        }
        
        return $address;
    }

    private function createTempCart($customer, $address, $shop)
{
    // Asegurar contexto de empleado
    if (!Context::getContext()->employee) {
        Context::getContext()->employee = new Employee(1);
    }
    
    $cart = new Cart();
    $cart->id_customer = $customer->id;
    $cart->id_address_delivery = $address->id;
    $cart->id_address_invoice = $address->id;
    $cart->id_lang = Configuration::get('PS_LANG_DEFAULT');
    $cart->id_currency = Configuration::get('PS_CURRENCY_DEFAULT');
    $cart->id_shop = Configuration::get('PS_SHOP_DEFAULT');
    $cart->id_shop_group = Shop::getGroupFromShop($cart->id_shop);
    
    // Asignar transportista (solo para que el carrito sea válido)
    if (!empty($shop['id_carrier']) && $shop['id_carrier'] > 0) {
        // Verificar que existe y está activo
        $carrier = new Carrier($shop['id_carrier']);
        if (Validate::isLoadedObject($carrier) && $carrier->active) {
            $cart->id_carrier = (int)$shop['id_carrier'];
        } else {
            // Si no es válido, usar uno por defecto pero será gratis
            $cart->id_carrier = $this->getAnyValidCarrier();
        }
    } else {
        // Sin transportista específico = usar uno cualquiera (será gratis)
        $cart->id_carrier = $this->getAnyValidCarrier();
    }
    
    $this->log('Carrito creado - Transportista configurado: ' . ($shop['id_carrier'] ?: 'Ninguno') . ', Transportista asignado: ' . $cart->id_carrier);
    
    if (!$cart->add()) {
        throw new Exception('Error al crear carrito temporal');
    }
    
    return $cart;
}
	private function getAnyValidCarrier()
{
    // Intentar transportista por defecto
    $defaultCarrierId = (int)Configuration::get('PS_CARRIER_DEFAULT');
    if ($defaultCarrierId > 0) {
        $carrier = new Carrier($defaultCarrierId);
        if (Validate::isLoadedObject($carrier) && $carrier->active) {
            return $defaultCarrierId;
        }
    }
    
    // Buscar cualquier transportista activo
    $carriers = Carrier::getCarriers(Configuration::get('PS_LANG_DEFAULT'), true);
    if (!empty($carriers)) {
        return (int)$carriers[0]['id_carrier'];
    }
    
    throw new Exception('No hay transportistas disponibles');
}
	/**
     * CORREGIDO: Calcular datos de pedido con búsqueda mejorada de productos
     */
private function calculateOrderData($products, $shop, $cart, $address)
{
    $this->log('DEBUG - calculateOrderData: Iniciando cálculo para ' . count($products) . ' productos');
    
    $total_products_tax_excl = 0;
    $total_products_tax_incl = 0;
    $products_details = array();
    
    // Obtener descuento del grupo
    $group_reduction = 0;
    if ($shop['id_group']) {
        $group = new Group($shop['id_group']);
        if (Validate::isLoadedObject($group)) {
            $group_reduction = (float)$group->reduction;
        }
    }
    
    foreach ($products as $product) {
        $reference = trim($product['product_reference']);
        
        if (empty($reference)) {
            $this->log('Saltando producto sin referencia: ' . $product['product_name']);
            continue;
        }
        
        $this->log('Buscando producto con referencia: "' . $reference . '"');
        $productFound = $this->findProductByReference($reference);
        
        if (!$productFound) {
            $this->log('Producto NO encontrado en tienda madre: "' . $reference . '" (Producto: ' . $product['product_name'] . ') - SALTANDO');
            continue;
        }

        $this->log('Producto SÍ encontrado "' . $reference . '" -> ID: ' . $productFound['id_product'] . ' (Tipo: ' . $productFound['type'] . ')');
                
        $id_product_found = $productFound['id_product'];
        $id_product_attribute_found = $productFound['id_product_attribute'];
        
        // Cargar producto para obtener precios
        $product_obj = new Product($id_product_found, false, $cart->id_lang);
        if (!Validate::isLoadedObject($product_obj)) {
            $this->log('No se pudo cargar el objeto Product para ID: ' . $id_product_found);
            continue;
        }
        
        // Verificar que el producto esté activo
// if (!$product_obj->active) {
//     $this->log('Producto inactivo: ' . $reference);
//     continue;
// }
        
        // Obtener precio base del producto
        $base_price_tax_excl = $product_obj->getPrice(false, $id_product_attribute_found, 6, null, false, false);
        $base_price_tax_incl = $product_obj->getPrice(true, $id_product_attribute_found, 6, null, false, false);

        // Aplicar descuento del grupo si existe
        if ($group_reduction > 0) {
            $unit_price_tax_excl = $base_price_tax_excl * (1 - ($group_reduction / 100));
            $unit_price_tax_incl = $base_price_tax_incl * (1 - ($group_reduction / 100));
        } else {
            $unit_price_tax_excl = $base_price_tax_excl;
            $unit_price_tax_incl = $base_price_tax_incl;
        }
        
        $line_total_tax_excl = $unit_price_tax_excl * $product['quantity'];
        $line_total_tax_incl = $unit_price_tax_incl * $product['quantity'];
        
        $total_products_tax_excl += $line_total_tax_excl;
        $total_products_tax_incl += $line_total_tax_incl;
        
        $products_details[] = array(
            'id_product' => $id_product_found,
            'id_product_attribute' => $id_product_attribute_found,
            'product_name' => $product['product_name'],
            'product_reference' => $reference,
            'quantity' => $product['quantity'],
            'unit_price_tax_excl' => $unit_price_tax_excl,
            'unit_price_tax_incl' => $unit_price_tax_incl,
            'total_price_tax_excl' => $line_total_tax_excl,
            'total_price_tax_incl' => $line_total_tax_incl,
            'group_reduction' => $group_reduction,
            'found_by' => $productFound['type']
        );
        
        $this->log('Producto añadido al pedido: "' . $reference . '" Cantidad: ' . $product['quantity'] . ' Precio: ' . $unit_price_tax_excl);
    }

    $this->log('RESUMEN - Productos procesados: ' . count($products) . ', Productos válidos encontrados: ' . count($products_details));

    // Calcular gastos de envío
    $shipping_data = $this->calculateShippingCosts($shop, $products_details, $address, $total_products_tax_excl);

    return array(
        'products_details' => $products_details,
        'total_products_tax_excl' => $total_products_tax_excl,
        'total_products_tax_incl' => $total_products_tax_incl,
        'total_shipping_tax_excl' => $shipping_data['tax_excl'],
        'total_shipping_tax_incl' => $shipping_data['tax_incl'],
        'total_paid_tax_excl' => $total_products_tax_excl + $shipping_data['tax_excl'],
        'total_paid_tax_incl' => $total_products_tax_incl + $shipping_data['tax_incl']
    );
}

    /**
     * CORREGIDO: Cálculo de transporte
     */
    private function calculateShippingCosts($shop, $products_details, $address, $total_products_tax_excl)
    {
        $total_shipping_tax_excl = 0;
        $total_shipping_tax_incl = 0;

        if (!empty($shop['id_carrier']) && $shop['id_carrier'] > 0) {
            $carrier = new Carrier($shop['id_carrier']);
            if (Validate::isLoadedObject($carrier) && $carrier->active) {
                try {
                    // Calcular peso total
                    $total_weight = $this->calculateTotalWeight($products_details);
                    
                    // Obtener zona de entrega
                    $country = new Country($address->id_country);
                    $id_zone = $country->id_zone ?: 1;
                    
                    // Usar las tarifas reales del transportista
                    $shipping_cost = $carrier->getDeliveryPriceByWeight($total_weight, $id_zone);
                    
                    // Si no hay tarifa por peso, probar por precio
                    if ($shipping_cost <= 0) {
                        $shipping_cost = $carrier->getDeliveryPriceByPrice($total_products_tax_excl, $id_zone);
                    }
                    
                    $total_shipping_tax_excl = $shipping_cost;
                    
                    // Aplicar IVA del transportista
                    $tax_rate = $this->getCarrierTaxRate($carrier, $address);
                    $total_shipping_tax_incl = $total_shipping_tax_excl * (1 + ($tax_rate / 100));
                    
                } catch (Exception $e) {
                    $this->log(': Error calculando envío: ' . $e->getMessage());
                    $total_shipping_tax_excl = 0;
                    $total_shipping_tax_incl = 0;
                }
            }
        }
        // Si no hay transportista configurado = ENVÍO GRATIS

        return array(
            'tax_excl' => round($total_shipping_tax_excl, 2),
            'tax_incl' => round($total_shipping_tax_incl, 2)
        );
    }

    private function calculateTotalWeight($products_details)
    {
        $total_weight = 0;
        
        foreach ($products_details as $detail) {
            $product_obj = new Product($detail['id_product']);
            if (Validate::isLoadedObject($product_obj)) {
                $weight = $product_obj->weight > 0 ? $product_obj->weight : 0.1; // Mínimo 100g
                $total_weight += ($weight * $detail['quantity']);
            }
        }
        
        return $total_weight;
    }

    private function getCarrierTaxRate($carrier, $address)
    {
        try {
            return $carrier->getTaxesRate($address);
        } catch (Exception $e) {
            return 21; // IVA por defecto
        }
    }
	
	/**
     * CORREGIDO: Crear pedido madre consolidado
     */
    private function createMotherOrder($products, $shop, $customer = null, $address = null, $cart = null)
    {
        if (!$customer) {
            $customer = $this->validateCustomer($shop['id_customer']);
        }
        if (!$address) {
            $address = $this->validateAddress($shop['id_address'], $customer->id);
        }
        if (!$cart) {
            $cart = $this->createTempCart($customer, $address, $shop);
        }
        
        // Calcular precios y productos
        $orderData = $this->calculateOrderData($products, $shop, $cart, $address);
            
        if (empty($orderData['products_details'])) {
            throw new Exception('No hay productos válidos para procesar');
        }
        
        // Crear pedido principal
        $order = $this->createOrder($customer, $cart, $address, $shop, $orderData);
        
        if ($order->add()) {
            // Crear detalles del pedido
            $this->createOrderDetails($order, $orderData['products_details'], $shop);
            
            // Crear historial de estados
            $this->createOrderHistory($order);
            
            // Crear registro en ps_order_carrier
            $this->createOrderCarrier($order, $shop, $orderData);
            
            // Actualizar stock si está activado
            $this->updateProductStock($orderData['products_details']);
            
            return $order->id;
        }
        
        throw new Exception('Error al crear el pedido en la base de datos');
    }

    private function createOrder($customer, $cart, $address, $shop, $orderData)
    {
        $order = new Order();
        $order->id_customer = $customer->id;
        $order->id_cart = $cart->id;
        $order->id_currency = $cart->id_currency;
        $order->id_lang = $cart->id_lang;
        // Usar el mismo transportista que se usará en OrderCarrier
if (!empty($shop['id_carrier']) && $shop['id_carrier'] > 0) {
    $carrier = new Carrier($shop['id_carrier']);
    if (Validate::isLoadedObject($carrier) && $carrier->active) {
        $order->id_carrier = (int)$shop['id_carrier'];
    } else {
        $order->id_carrier = $this->getAnyValidCarrier();
    }
} else {
    $order->id_carrier = $this->getAnyValidCarrier();
}
        $order->id_address_delivery = $address->id;
        $order->id_address_invoice = $address->id;
        $order->id_shop = Configuration::get('PS_SHOP_DEFAULT');
        $order->id_shop_group = Shop::getGroupFromShop($order->id_shop);
        $order->current_state = (int)$shop['id_order_state'];
        $order->payment = 'SyncroSevi - ' . $shop['name'];
        $order->module = 'syncrosevi';
        $order->secure_key = $customer->secure_key;
        $order->reference = Order::generateReference();
        $order->conversion_rate = 1;
        $order->valid = 1;
        
        // Todos los totales
        $order->total_paid = $orderData['total_paid_tax_incl'];
        $order->total_paid_tax_incl = $orderData['total_paid_tax_incl'];
        $order->total_paid_tax_excl = $orderData['total_paid_tax_excl'];
        $order->total_paid_real = $orderData['total_paid_tax_incl'];
        $order->total_products = $orderData['total_products_tax_excl'];
        $order->total_products_wt = $orderData['total_products_tax_incl'];
        $order->total_shipping = $orderData['total_shipping_tax_incl'];
        $order->total_shipping_tax_incl = $orderData['total_shipping_tax_incl'];
        $order->total_shipping_tax_excl = $orderData['total_shipping_tax_excl'];
        $order->total_discounts = 0;
        $order->total_discounts_tax_incl = 0;
        $order->total_discounts_tax_excl = 0;
        $order->total_wrapping = 0;
        $order->total_wrapping_tax_incl = 0;
        $order->total_wrapping_tax_excl = 0;
        $order->round_mode = Configuration::get('PS_PRICE_ROUND_MODE');
        $order->round_type = Configuration::get('PS_ROUND_TYPE');
        
        return $order;
    }

    private function createOrderDetails($order, $products_details, $shop)
    {
        $default_warehouse = Configuration::get('PS_WAREHOUSE_DEFAULT') ?: 0;
        
        foreach ($products_details as $detail) {
            $order_detail = new OrderDetail();
            $order_detail->id_order = $order->id;
            $order_detail->id_warehouse = $default_warehouse;
            $order_detail->id_shop = $order->id_shop;
            $order_detail->product_id = $detail['id_product'];
            $order_detail->product_attribute_id = $detail['id_product_attribute'];
            $order_detail->product_name = $detail['product_name'];
            $order_detail->product_reference = $detail['product_reference'];
            $order_detail->product_quantity = $detail['quantity'];
            $order_detail->product_price = $detail['unit_price_tax_excl'];
            $order_detail->unit_price_tax_incl = $detail['unit_price_tax_incl'];
            $order_detail->unit_price_tax_excl = $detail['unit_price_tax_excl'];
            $order_detail->total_price_tax_incl = $detail['total_price_tax_incl'];
            $order_detail->total_price_tax_excl = $detail['total_price_tax_excl'];
            $order_detail->product_quantity_in_stock = 1000;
            $order_detail->product_quantity_refunded = 0;
            $order_detail->product_quantity_return = 0;
            $order_detail->product_quantity_reinjected = 0;
            $order_detail->group_reduction = $detail['group_reduction'];
            $order_detail->discount_quantity_applied = 0;
            $order_detail->download_hash = '';
            $order_detail->download_nb = 0;
            $order_detail->download_deadline = null;
            
            if (!$order_detail->add()) {
                throw new Exception('Error al crear detalle del pedido para producto ID: ' . $detail['id_product']);
            }
        }
    }

    private function createOrderHistory($order)
    {
        $order_history = new OrderHistory();
        $order_history->id_order = $order->id;
        $order_history->id_employee = 0;
        $order_history->id_order_state = $order->current_state;
        $order_history->date_add = date('Y-m-d H:i:s');
        $order_history->add();
    }

    /**
     * CORREGIDO: Crear registro en ps_order_carrier
     */
    private function createOrderCarrier($order, $shop, $orderData)
{
    try {
        // Determinar qué transportista usar
        if (!empty($shop['id_carrier']) && $shop['id_carrier'] > 0) {
            $id_carrier = (int)$shop['id_carrier'];
            $carrier = new Carrier($id_carrier);
            
            if (!Validate::isLoadedObject($carrier) || !$carrier->active) {
                // Si el transportista configurado no es válido, usar uno por defecto pero gratis
                $id_carrier = $this->getAnyValidCarrier();
                $this->log('Transportista configurado no válido, usando por defecto: ' . $id_carrier);
            }
        } else {
            // Sin transportista específico = usar uno por defecto (será gratis)
            $id_carrier = $this->getAnyValidCarrier();
        }
        
        $order_carrier = new OrderCarrier();
        $order_carrier->id_order = $order->id;
        $order_carrier->id_carrier = $id_carrier;
        $order_carrier->id_order_invoice = 0;
        $order_carrier->weight = $this->calculateTotalWeight($orderData['products_details']);
        $order_carrier->shipping_cost_tax_excl = $orderData['total_shipping_tax_excl'];
        $order_carrier->shipping_cost_tax_incl = $orderData['total_shipping_tax_incl'];
        $order_carrier->tracking_number = '';
        $order_carrier->date_add = date('Y-m-d H:i:s');
        
        if ($order_carrier->add()) {
            $this->log('OrderCarrier creado - Transportista: ' . $id_carrier . ', Coste: ' . $orderData['total_shipping_tax_incl']);
        } else {
            $this->log('Error creando OrderCarrier para pedido #' . $order->id);
        }
        
    } catch (Exception $e) {
        $this->log('Error en createOrderCarrier: ' . $e->getMessage());
    }
}

    private function updateProductStock($products_details)
    {
        if (Configuration::get('PS_STOCK_MANAGEMENT')) {
            foreach ($products_details as $detail) {
                StockAvailable::updateQuantity(
                    $detail['id_product'],
                    $detail['id_product_attribute'],
                    -$detail['quantity']
                );
            }
        }
    }
	
	public function getContent()
    {
        $output = '';
        
        // Procesar formularios
        if (Tools::isSubmit('submitAddShop')) {
            $output .= $this->processAddShop();
        } elseif (Tools::isSubmit('submitEditShop')) {
            $output .= $this->processEditShop();
        } elseif (Tools::isSubmit('submitSyncOrders')) {
            $output .= $this->processSyncOrders();
        } elseif (Tools::isSubmit('submitProcessOrders')) {
            $output .= $this->processOrdersAction();
        } elseif (Tools::isSubmit('submitViewPending')) {
            return $this->processViewPending();
        } elseif (Tools::getValue('action') == 'delete') {
            $output .= $this->processDeleteShop();
        } elseif (Tools::getValue('action') == 'edit') {
            return $this->showEditForm();
        } elseif (Tools::getValue('action') == 'test') {
            $this->processTestConnection();
            return;
        } elseif (Tools::getValue('action') == 'get_states') {
            $this->getChildShopStates();
            return;
        } elseif (Tools::getValue('action') == 'webhook') {
            $this->processWebhook();
            return;
        }
        
        $output .= $this->displayConfigurationForm();
        return $output;
    }

    protected function processWebhook()
    {
        $action = Tools::getValue('webhook_action');
        $security = Tools::getValue('security_token');
        
        // Token de seguridad mejorado (mensual en lugar de diario)
        $expected_token = md5('syncrosevi_' . Configuration::get('PS_SHOP_NAME') . '_' . Configuration::get('PS_SHOP_EMAIL') . '_' . date('Y-m'));
        
        if ($security !== $expected_token) {
            header('HTTP/1.1 403 Forbidden');
            die(json_encode(array('error' => 'Invalid security token')));
        }
        
        header('Content-Type: application/json');
        
        try {
            switch ($action) {
                case 'sync':
                    $results = $this->syncOrders();
                    die(json_encode(array('success' => true, 'results' => $results)));
                    break;
                    
                case 'process':
                    $results = $this->processOrders();
                    die(json_encode(array('success' => true, 'results' => $results)));
                    break;
                    
                default:
                    die(json_encode(array('error' => 'Invalid action')));
            }
        } catch (Exception $e) {
            die(json_encode(array('error' => $e->getMessage())));
        }
    }

    protected function displayConfigurationForm()
    {
        $shops = $this->getConfiguredShops();
        $stats = $this->getStats();
        $pendingOrders = $this->getPendingOrders();
        
        // CORREGIDO: Token mensual
        $webhook_token = md5('syncrosevi_' . Configuration::get('PS_SHOP_NAME') . '_' . Configuration::get('PS_SHOP_EMAIL') . '_' . date('Y-m'));
        $base_url = (Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . __PS_BASE_URI__;
        $webhook_sync_url = $base_url . 'modules/syncrosevi/webhook.php?action=sync&security_token=' . $webhook_token;
        $webhook_process_url = $base_url . 'modules/syncrosevi/webhook.php?action=process&security_token=' . $webhook_token;
        
        $html = '';
        
        // CSS y JavaScript CORREGIDO
        $html .= '<style>
        .syncrosevi-panel { margin-bottom: 20px; }
        .customer-search { position: relative; }
        .customer-results { position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #ddd; max-height: 200px; overflow-y: auto; z-index: 1000; display: none; }
        .customer-item { padding: 10px; cursor: pointer; border-bottom: 1px solid #eee; }
        .customer-item:hover { background: #f5f5f5; }
        .transport-options { display: none; margin-top: 10px; }
        .shop-row:hover { background-color: #f9f9f9; }
        .btn-group .btn { margin-right: 2px; }
        .badge-success { background-color: #5cb85c; }
        .badge-danger { background-color: #d9534f; }
        .webhook-url { background: #f5f5f5; padding: 10px; font-family: monospace; border: 1px solid #ddd; word-break: break-all; }
        </style>';
        
        // JavaScript CORREGIDO
        $html .= '<script>
        var customers_cache = ' . json_encode(Customer::getCustomers(true)) . ';
        var addresses_all = ' . json_encode($this->getAllAddresses()) . ';
        var adminLink = "' . $this->context->link->getAdminLink('AdminModules') . '&configure=syncrosevi";
        
        function searchCustomers(query, resultId, customerId) {
            if (query.length < 2) {
                document.getElementById(resultId).style.display = "none";
                return;
            }
            
            var filtered = customers_cache.filter(function(c) {
                return (c.firstname + " " + c.lastname + " " + c.email).toLowerCase().indexOf(query.toLowerCase()) !== -1;
            });
            
            showCustomerResults(filtered.slice(0, 10), resultId, customerId);
        }
        
        function showCustomerResults(customers, resultId, customerId) {
            var resultsDiv = document.getElementById(resultId);
            resultsDiv.innerHTML = "";
            
            customers.forEach(function(customer) {
                var div = document.createElement("div");
                div.className = "customer-item";
                div.innerHTML = "<strong>" + customer.firstname + " " + customer.lastname + "</strong><br><small>" + customer.email + "</small>";
                div.onclick = function() { selectCustomer(customer, resultId, customerId); };
                resultsDiv.appendChild(div);
            });
            
            resultsDiv.style.display = customers.length > 0 ? "block" : "none";
        }
        
        function selectCustomer(customer, resultId, customerId) {
            var searchField = document.getElementById(resultId.replace("-results", "-search"));
            searchField.value = customer.firstname + " " + customer.lastname;
            document.getElementById(customerId).value = customer.id_customer;
            document.getElementById(resultId).style.display = "none";
            loadCustomerAddresses(customer.id_customer, resultId.replace("customer-results", "id_address"));
        }
        
        function loadCustomerAddresses(customerId, addressSelectId) {
            var customerAddresses = addresses_all.filter(function(addr) {
                return addr.id_customer == customerId;
            });
            populateAddresses(customerAddresses, addressSelectId);
        }
        
        function populateAddresses(addresses, selectId) {
            var select = document.getElementById(selectId);
            select.innerHTML = "<option value=\"\">Seleccionar dirección...</option>";
            
            addresses.forEach(function(address) {
                var option = document.createElement("option");
                option.value = address.id_address;
                option.textContent = address.alias + " - " + address.address1 + ", " + address.city + " (" + address.postcode + ")";
                select.appendChild(option);
            });
            
            if (addresses.length === 0) {
                select.innerHTML = "<option value=\"\">Este cliente no tiene direcciones</option>";
            }
        }
        
        function toggleTransport(show, containerId) {
            var container = document.getElementById(containerId || "transport-options");
            container.style.display = show ? "block" : "none";
            if (!show && container.querySelector("select")) {
                container.querySelector("select").value = "";
            }
        }
        
        function editShop(shopId) {
            window.location.href = adminLink + "&action=edit&id_shop=" + shopId;
        }
        
        function deleteShop(shopId) {
            if (confirm("¿Estás seguro de eliminar esta tienda? Esta acción no se puede deshacer.")) {
                window.location.href = adminLink + "&action=delete&id_shop=" + shopId;
            }
        }
        
        function testConnection(shopId) {
            var btn = event.target.closest("button");
            var originalHtml = btn.innerHTML;
            btn.innerHTML = "<i class=\"icon-spinner icon-spin\"></i>";
            btn.disabled = true;
            
            var xhr = new XMLHttpRequest();
            xhr.open("GET", adminLink + "&action=test&id_shop=" + shopId, true);
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    btn.innerHTML = originalHtml;
                    btn.disabled = false;
                    
                    if (xhr.status === 200) {
                        try {
                            var response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                alert("✓ Conexión exitosa\\n\\nInfo: " + (response.shop_info.name || "Tienda conectada"));
                            } else {
                                alert("✗ Error de conexión\\n\\n" + response.message);
                            }
                        } catch(e) {
                            alert("Error al procesar respuesta");
                        }
                    } else {
                        alert("Error de comunicación");
                    }
                }
            };
            xhr.send();
        }

        // CORREGIDO: Función para cargar estados
        function loadStatesFromShop() {
            var url = document.getElementById("shop_url").value;
            var apiKey = document.getElementById("api_key").value;
            
            if (!url || !apiKey) {
                alert("Completa primero URL y API Key");
                return;
            }
            
            var btn = document.getElementById("load-states-btn");
            btn.innerHTML = "<i class=\"icon-spinner icon-spin\"></i> Cargando...";
            btn.disabled = true;
            
            var xhr = new XMLHttpRequest();
            xhr.open("GET", adminLink + "&action=get_states&shop_url=" + encodeURIComponent(url) + "&api_key=" + encodeURIComponent(apiKey), true);
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    btn.innerHTML = "Cargar Estados de la Tienda Hija";
                    btn.disabled = false;
                    
                    if (xhr.status === 200) {
                        try {
                            var response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                var container = document.getElementById("import-states-container");
                                container.innerHTML = "";
                                
                                response.states.forEach(function(state) {
    var div = document.createElement("div");
    div.className = "checkbox";
    div.innerHTML = "<label><input type=\"checkbox\" name=\"import_states[]\" value=\"" + state.id + "\"> ID " + state.id + ": " + state.name + "</label>";
    container.appendChild(div);
});
                                
                                container.style.display = "block";
                            } else {
                                alert("Error cargando estados: " + (response.message || "Error desconocido"));
                            }
                        } catch(e) {
                            alert("Error procesando respuesta de estados");
                        }
                    } else {
                        alert("Error de comunicación cargando estados");
                    }
                }
            };
            xhr.send();
        }
        
        // Ocultar resultados al hacer clic fuera
        document.addEventListener("click", function(e) {
            if (!e.target.closest(".customer-search")) {
                var resultsDiv = document.querySelectorAll(".customer-results");
                resultsDiv.forEach(function(div) {
                    div.style.display = "none";
                });
            }
        });
        </script>';
        
        // Resto del HTML sigue siendo el mismo que tenías...
        $html .= $this->buildConfigurationHTML($stats, $webhook_sync_url, $webhook_process_url, $pendingOrders, $shops);
        
        return $html;
    }

    private function buildConfigurationHTML($stats, $webhook_sync_url, $webhook_process_url, $pendingOrders, $shops)
    {
        $html = '';
        
        // Estadísticas
        $html .= '<div class="panel syncrosevi-panel"><div class="panel-heading"><i class="icon-bar-chart"></i> Estadísticas de Sincronización</div><div class="panel-body">';
        $html .= '<div class="row">';
        $html .= '<div class="col-lg-3"><div class="alert alert-info text-center"><h3>' . $stats['total_shops'] . '</h3>Tiendas Configuradas</div></div>';
        $html .= '<div class="col-lg-3"><div class="alert alert-success text-center"><h3>' . $stats['active_shops'] . '</h3>Tiendas Activas</div></div>';
        $html .= '<div class="col-lg-3"><div class="alert alert-warning text-center"><h3>' . $stats['pending_orders'] . '</h3>Pedidos Pendientes</div></div>';
        $html .= '<div class="col-lg-3"><div class="alert alert-info text-center"><h3>' . $stats['processed_orders'] . '</h3>Pedidos Procesados</div></div>';
        $html .= '</div></div></div>';
        
        // URLs para cron CORREGIDAS
        $html .= '<div class="panel syncrosevi-panel"><div class="panel-heading"><i class="icon-link"></i> URLs para Cron/Webhook</div><div class="panel-body">';
        $html .= '<div class="alert alert-info">';
        $html .= '<h4>Configuración de Cron SEPARADOS (recomendado)</h4>';
        $html .= '<p><strong>Para SOLO sincronizar pedidos (cada 15 min):</strong><br>';
        $html .= '<code>*/15 * * * * curl -s "' . $webhook_sync_url . '"</code></p>';
        $html .= '<p><strong>Para SOLO procesar pedidos (cada hora):</strong><br>';
        $html .= '<code>0 * * * * curl -s "' . $webhook_process_url . '"</code></p>';
        $html .= '<p><small><strong>Nota:</strong> La sincronización también es automática cuando cambia el estado de un pedido en las tiendas hijas.</small></p>';
        $html .= '</div>';
        $html .= '</div></div>';
        
        // Resto del HTML del formulario...
        $html .= $this->getShopConfigurationForm($shops, $pendingOrders);
        
        return $html;
    }

    // CORREGIDO: Cargar estados de tienda hija
protected function getChildShopStates()
{
    $url = Tools::getValue('shop_url');
    $api_key = Tools::getValue('api_key');
    
    if (empty($url) || empty($api_key)) {
        header('Content-Type: application/json');
        die(json_encode(array('success' => false, 'message' => 'URL y API Key requeridos')));
    }

    try {
        if (!class_exists('SyncroSeviWebservice')) {
            require_once(dirname(__FILE__).'/classes/SyncroSeviWebservice.php');
        }
        
        $webservice = new SyncroSeviWebservice($url, $api_key, true);
        $xml = $webservice->get(array('resource' => 'order_states', 'display' => 'full'));
        
        $states = array();
        if ($xml && isset($xml->order_states)) {
            $orderStates = $xml->order_states->order_state;
            if (!is_array($orderStates) && !($orderStates instanceof Traversable)) {
                $orderStates = array($orderStates);
            }
            
            foreach ($orderStates as $state) {
                $stateName = '';
                if (isset($state->name)) {
                    if (is_array($state->name) || $state->name instanceof Traversable) {
                        $stateName = (string)$state->name[0];
                    } else {
                        $stateName = (string)$state->name;
                    }
                }
                
                $states[] = array(
                    'id' => (int)$state->id,
                    'name' => $stateName ?: 'Estado #' . (int)$state->id
                );
            }
        }
        
        header('Content-Type: application/json');
        die(json_encode(array('success' => true, 'states' => $states)));
        
    } catch (Exception $e) {
        header('Content-Type: application/json');
        die(json_encode(array('success' => false, 'message' => $e->getMessage())));
    }
}
    // Continúa con el resto de métodos que ya estaban bien...
    protected function processAddShop()
    {
        $name = Tools::getValue('shop_name');
        $url = Tools::getValue('shop_url');
        $api_key = Tools::getValue('api_key');
        $id_customer = (int)Tools::getValue('id_customer');
        $id_address = (int)Tools::getValue('id_address');
        $id_group = (int)Tools::getValue('id_group');
        $use_carrier = (int)Tools::getValue('use_carrier');
        $id_carrier = $use_carrier ? (int)Tools::getValue('id_carrier') : null;
        $id_order_state = (int)Tools::getValue('id_order_state');
        $start_order_id = (int)Tools::getValue('start_order_id') ?: 1;
        $import_states = Tools::getValue('import_states');
        $active = Tools::getValue('active') ? 1 : 0;
        
        if (empty($name) || empty($url) || empty($api_key) || !$id_customer || !$id_address || !$id_group || !$id_order_state || empty($import_states)) {
            return $this->displayError($this->l('Todos los campos obligatorios deben ser completados'));
        }
        
        // Convertir array de estados a string
        $import_states_str = is_array($import_states) ? implode(',', array_map('intval', $import_states)) : $import_states;
        
        // Validar URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->displayError($this->l('La URL no es válida'));
        }
        
        // Validar que la dirección pertenece al cliente
        $address = new Address($id_address);
        if ($address->id_customer != $id_customer) {
            return $this->displayError($this->l('La dirección seleccionada no pertenece al cliente'));
        }
        
        $inserted = Db::getInstance()->insert('syncrosevi_child_shops', array(
            'name' => pSQL($name),
            'url' => pSQL(rtrim($url, '/')),
            'api_key' => pSQL($api_key),
            'id_customer' => $id_customer,
            'id_group' => $id_group,
            'id_address' => $id_address,
            'id_carrier' => $id_carrier,
            'id_order_state' => $id_order_state,
            'start_order_id' => $start_order_id,
            'import_states' => pSQL($import_states_str),
            'active' => $active,
            'date_add' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s')
        ));
        
        if ($inserted) {
            return $this->displayConfirmation($this->l('Tienda hija añadida correctamente'));
        } else {
            return $this->displayError($this->l('Error al añadir la tienda hija'));
        }
    }

    // Resto de métodos que continúan igual...
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
    
    protected function getAllAddresses()
    {
        return Db::getInstance()->executeS(
            'SELECT a.id_address, a.id_customer, a.alias, a.firstname, a.lastname, a.address1, a.city, a.postcode
             FROM `' . _DB_PREFIX_ . 'address` a
             WHERE a.deleted = 0
             ORDER BY a.id_customer, a.alias ASC'
        );
    }



    protected function getConfiguredShops()
    {
        return Db::getInstance()->executeS(
            'SELECT cs.*, c.firstname, c.lastname, c.email, 
                    a.alias as address_alias, a.address1, a.city,
                    g.name as group_name, os.name as state_name, ca.name as carrier_name
             FROM `' . _DB_PREFIX_ . 'syncrosevi_child_shops` cs
             LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON cs.id_customer = c.id_customer
             LEFT JOIN `' . _DB_PREFIX_ . 'address` a ON cs.id_address = a.id_address
             LEFT JOIN `' . _DB_PREFIX_ . 'group_lang` g ON cs.id_group = g.id_group AND g.id_lang = ' . (int)$this->context->language->id . '
             LEFT JOIN `' . _DB_PREFIX_ . 'order_state_lang` os ON cs.id_order_state = os.id_order_state AND os.id_lang = ' . (int)$this->context->language->id . '
             LEFT JOIN `' . _DB_PREFIX_ . 'carrier` ca ON cs.id_carrier = ca.id_carrier
             ORDER BY cs.name ASC'
        );
    }
    
    protected function getPendingOrders()
    {
        return Db::getInstance()->executeS(
            'SELECT cs.name as shop_name, cs.id_child_shop,
                   ol.id_original_order, ol.product_name, ol.product_reference, 
                   SUM(ol.quantity) as total_quantity,
                   COUNT(ol.id_line) as lines_count,
                   MIN(ol.date_add) as first_sync
            FROM `' . _DB_PREFIX_ . 'syncrosevi_order_lines` ol
            JOIN `' . _DB_PREFIX_ . 'syncrosevi_child_shops` cs ON ol.id_child_shop = cs.id_child_shop
            WHERE ol.processed = 0
            GROUP BY cs.id_child_shop, ol.id_original_order
            ORDER BY ol.date_add DESC
            LIMIT 10'
        );
    }

    // Continuar con el resto de métodos para procesar formularios, etc.
    // (implementar processEditShop, showEditForm, processDeleteShop, processTestConnection, etc.)
    



    
    /**
     * CORREGIDO: Mostrar formulario de edición funcional
     */
    protected function showEditForm()
    {
        $id_shop = (int)Tools::getValue('id_shop');
        
        if (!$id_shop) {
            return $this->displayError($this->l('ID de tienda inválido'));
        }

        $shop = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'syncrosevi_child_shops` WHERE id_child_shop = ' . $id_shop
        );

        if (!$shop) {
            return $this->displayError($this->l('Tienda no encontrada'));
        }

        $customer = new Customer($shop['id_customer']);
        $import_states_array = explode(',', $shop['import_states']);

        $html = '<div class="panel"><div class="panel-heading"><i class="icon-edit"></i> Editar Tienda Hija: ' . $shop['name'];
        $html .= ' <a href="' . $this->context->link->getAdminLink('AdminModules') . '&configure=syncrosevi" class="btn btn-default btn-sm pull-right">Volver</a>';
        $html .= '</div><div class="panel-body">';
        
        // JavaScript para formulario de edición
        $html .= '<script>
        var customers_cache = ' . json_encode(Customer::getCustomers(true)) . ';
        var addresses_all = ' . json_encode($this->getAllAddresses()) . ';
        var adminLink = "' . $this->context->link->getAdminLink('AdminModules') . '&configure=syncrosevi";
        
        function searchCustomersEdit(query) {
            searchCustomers(query, "edit-customer-results", "edit-selected-customer-id");
        }
        
        function loadStatesFromShopEdit() {
            var url = document.getElementById("edit_shop_url").value;
            var apiKey = document.getElementById("edit_api_key").value;
            loadStatesFromShopGeneric(url, apiKey, "edit-import-states-container");
        }
        
        function loadStatesFromShopGeneric(url, apiKey, containerId) {
            if (!url || !apiKey) {
                alert("Completa primero URL y API Key");
                return;
            }
            
            var xhr = new XMLHttpRequest();
            xhr.open("GET", adminLink + "&action=get_states&shop_url=" + encodeURIComponent(url) + "&api_key=" + encodeURIComponent(apiKey), true);
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            var container = document.getElementById(containerId);
                            container.innerHTML = "";
                            
                           response.states.forEach(function(state) {
    var div = document.createElement("div");
    div.className = "checkbox";
    var checked = ' . json_encode($import_states_array) . '.indexOf(state.id.toString()) !== -1 ? "checked" : "";
    div.innerHTML = "<label><input type=\"checkbox\" name=\"import_states[]\" value=\"" + state.id + "\" " + checked + "> ID " + state.id + ": " + state.name + "</label>";
    container.appendChild(div);
});
                            
                            container.style.display = "block";
                        }
                    } catch(e) {
                        alert("Error procesando respuesta");
                    }
                }
            };
            xhr.send();
        }
        </script>';
        
        $html .= '<form method="post" class="form-horizontal">';
        $html .= '<input type="hidden" name="id_child_shop" value="' . $shop['id_child_shop'] . '">';
        
        // Campos del formulario con valores prellenados
        $html .= '<div class="row">';
        $html .= '<div class="col-md-6">';
        $html .= '<div class="form-group">';
        $html .= '<label class="control-label">Nombre de la Tienda *</label>';
        $html .= '<input type="text" name="shop_name" class="form-control" value="' . htmlspecialchars($shop['name']) . '" required>';
        $html .= '</div>';
        $html .= '<div class="form-group">';
        $html .= '<label class="control-label">URL de la Tienda *</label>';
        $html .= '<input type="url" name="shop_url" id="edit_shop_url" class="form-control" value="' . htmlspecialchars($shop['url']) . '" required>';
        $html .= '</div>';
        $html .= '<div class="form-group">';
        $html .= '<label class="control-label">Clave API WebService *</label>';
        $html .= '<input type="text" name="api_key" id="edit_api_key" class="form-control" value="' . htmlspecialchars($shop['api_key']) . '" required>';
        $html .= '</div>';
        $html .= '</div>';
        
        $html .= '<div class="col-md-6">';
        // Cliente con buscador
        $html .= '<div class="form-group">';
        $html .= '<label class="control-label">Cliente Asignado *</label>';
        $html .= '<div class="customer-search">';
        $html .= '<input type="text" id="edit-customer-search" class="form-control" value="' . $customer->firstname . ' ' . $customer->lastname . '" onkeyup="searchCustomersEdit(this.value)" autocomplete="off">';
        $html .= '<input type="hidden" id="edit-selected-customer-id" name="id_customer" value="' . $shop['id_customer'] . '" required>';
        $html .= '<div id="edit-customer-results" class="customer-results"></div>';
        $html .= '</div>';
        $html .= '</div>';
        
        // Dirección
        $html .= '<div class="form-group">';
        $html .= '<label class="control-label">Dirección de Facturación *</label>';
        $html .= '<select name="id_address" id="edit_id_address" class="form-control" required>';
        $addresses = Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'address` WHERE id_customer = ' . (int)$shop['id_customer'] . ' AND deleted = 0');
        foreach ($addresses as $addr) {
            $selected = ($addr['id_address'] == $shop['id_address']) ? 'selected' : '';
            $html .= '<option value="' . $addr['id_address'] . '" ' . $selected . '>' . $addr['alias'] . ' - ' . $addr['address1'] . '</option>';
        }
        $html .= '</select>';
        $html .= '</div>';
        
        // Grupo
        $html .= '<div class="form-group">';
        $html .= '<label class="control-label">Grupo de Precios *</label>';
        $html .= '<select name="id_group" class="form-control" required>';
        foreach (Group::getGroups($this->context->language->id) as $group) {
            $selected = ($group['id_group'] == $shop['id_group']) ? 'selected' : '';
            $html .= '<option value="' . $group['id_group'] . '" ' . $selected . '>' . $group['name'] . '</option>';
        }
        $html .= '</select>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        
        // Resto de campos...
        $html .= '<div class="row">';
        $html .= '<div class="col-md-6">';
        
        // Transporte
        $useCarrier = !empty($shop['id_carrier']);
        $html .= '<div class="form-group">';
        $html .= '<label class="control-label">¿Usar Transportista Específico?</label>';
        $html .= '<div>';
        $html .= '<label class="radio-inline"><input type="radio" name="use_carrier" value="0"' . (!$useCarrier ? ' checked' : '') . ' onclick="toggleTransport(false, \'edit-transport-options\')"> No</label>';
        $html .= '<label class="radio-inline"><input type="radio" name="use_carrier" value="1"' . ($useCarrier ? ' checked' : '') . ' onclick="toggleTransport(true, \'edit-transport-options\')"> Sí</label>';
        $html .= '</div>';
        $style = $useCarrier ? 'display: block;' : 'display: none;';
        $html .= '<div id="edit-transport-options" class="transport-options" style="' . $style . ' margin-top: 10px;">';
        $html .= '<select name="id_carrier" class="form-control">';
        $html .= '<option value="">Seleccionar transportista...</option>';
        foreach (Carrier::getCarriers($this->context->language->id, true) as $carrier) {
            $selected = ($carrier['id_carrier'] == $shop['id_carrier']) ? 'selected' : '';
            $html .= '<option value="' . $carrier['id_carrier'] . '" ' . $selected . '>' . $carrier['name'] . '</option>';
        }
        $html .= '</select>';
        $html .= '</div>';
        $html .= '</div>';
        
        // ID de pedido inicial
        $html .= '<div class="form-group">';
        $html .= '<label class="control-label">Importar desde el pedido ID *</label>';
        $html .= '<input type="number" name="start_order_id" class="form-control" value="' . $shop['start_order_id'] . '" min="1" required>';
        $html .= '</div>';
        $html .= '</div>';
        
        $html .= '<div class="col-md-6">';
        // Estado
        $html .= '<div class="form-group">';
        $html .= '<label class="control-label">Estado del Pedido *</label>';
        $html .= '<select name="id_order_state" class="form-control" required>';
        foreach (OrderState::getOrderStates($this->context->language->id) as $state) {
            $selected = ($state['id_order_state'] == $shop['id_order_state']) ? 'selected' : '';
            $html .= '<option value="' . $state['id_order_state'] . '" ' . $selected . '>' . $state['name'] . '</option>';
        }
        $html .= '</select>';
        $html .= '</div>';
        
        // Activo
        $html .= '<div class="form-group">';
        $html .= '<div class="checkbox">';
        $checked = $shop['active'] ? 'checked' : '';
        $html .= '<label><input type="checkbox" name="active" value="1" ' . $checked . '> Tienda activa</label>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        
        // Estados a importar
        $html .= '<div class="form-group">';
        $html .= '<label class="control-label">Estados de pedidos a importar *</label>';
        $html .= '<button type="button" class="btn btn-info btn-sm" onclick="loadStatesFromShopEdit()">Recargar Estados de la Tienda</button>';
        $html .= '<div id="edit-import-states-container" class="import-states-container" style="display: block;">';
        foreach (OrderState::getOrderStates($this->context->language->id) as $state) {
            $checked = in_array($state['id_order_state'], $import_states_array) ? 'checked' : '';
            $html .= '<div class="checkbox">';
            $html .= '<label><input type="checkbox" name="import_states[]" value="' . $state['id_order_state'] . '" ' . $checked . '> ' . $state['name'] . '</label>';
            $html .= '</div>';
        }
        $html .= '</div>';
        $html .= '</div>';
        
        $html .= '<div class="form-group">';
        $html .= '<button type="submit" name="submitEditShop" class="btn btn-success btn-lg"><i class="icon-save"></i> Guardar Cambios</button>';
        $html .= ' <a href="' . $this->context->link->getAdminLink('AdminModules') . '&configure=syncrosevi" class="btn btn-default">Cancelar</a>';
        $html .= '</div>';
        $html .= '</form>';
        $html .= '</div></div>';
        
        return $html;
    }

/**
     * CORREGIDO: Procesar eliminación de tienda COMPLETA
     */
    protected function processDeleteShop()
    {
        $id_shop = (int)Tools::getValue('id_shop');
        
        if (!$id_shop) {
            return $this->displayError($this->l('ID de tienda inválido'));
        }
        
        // Verificar que no tenga pedidos pendientes
        $pendingCount = (int)Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'syncrosevi_order_lines` 
             WHERE id_child_shop = ' . $id_shop . ' AND processed = 0'
        );
        
        if ($pendingCount > 0) {
            return $this->displayError($this->l('No se puede eliminar la tienda porque tiene ') . $pendingCount . $this->l(' pedidos pendientes de procesamiento. Procesa los pedidos primero.'));
        }
        
        // También eliminar tracking relacionado
        Db::getInstance()->delete('syncrosevi_order_tracking', 'id_child_shop = ' . $id_shop);
        Db::getInstance()->delete('syncrosevi_order_lines', 'id_child_shop = ' . $id_shop . ' AND processed = 1');
        
        // Eliminar la tienda
        $deleted = Db::getInstance()->delete('syncrosevi_child_shops', 'id_child_shop = ' . $id_shop);
        
        if ($deleted) {
            return $this->displayConfirmation($this->l('Tienda hija eliminada correctamente'));
        } else {
            return $this->displayError($this->l('Error al eliminar la tienda hija'));
        }
    }
		
		
		
		/**
     * CORREGIDO: Formulario de configuración de tiendas completo
     */
    protected function getShopConfigurationForm($shops, $pendingOrders)
    {
        $html = '';
        
        // Acciones rápidas
        $html .= '<div class="panel syncrosevi-panel"><div class="panel-heading"><i class="icon-cogs"></i> Acciones Manuales</div><div class="panel-body">';
        $html .= '<div class="btn-group" role="group">';
        $html .= '<form method="post" style="display:inline;">';
        $html .= '<button type="submit" name="submitSyncOrders" class="btn btn-primary"><i class="icon-refresh"></i> Sincronizar Pedidos</button>';
        $html .= '</form> ';
        $html .= '<form method="post" style="display:inline;">';
        $html .= '<button type="submit" name="submitProcessOrders" class="btn btn-success"><i class="icon-cogs"></i> Procesar Pedidos</button>';
        $html .= '</form> ';
        $html .= '<form method="post" style="display:inline;">';
        $html .= '<button type="submit" name="submitViewPending" class="btn btn-warning"><i class="icon-list"></i> Ver Pedidos Pendientes</button>';
        $html .= '</form>';
        $html .= '</div></div></div>';
        
        // Pedidos pendientes (vista rápida)
        if (!empty($pendingOrders)) {
            $html .= '<div class="panel syncrosevi-panel"><div class="panel-heading"><i class="icon-clock-o"></i> Últimos Pedidos Pendientes <span class="badge">' . count($pendingOrders) . '</span></div><div class="panel-body">';
            $html .= '<div class="table-responsive"><table class="table table-striped table-condensed">';
            $html .= '<thead><tr><th>Tienda</th><th>Pedido #</th><th>Productos</th><th>Fecha Sync</th></tr></thead><tbody>';
            $count = 0;
            foreach ($pendingOrders as $order) {
                if ($count >= 5) break;
                $html .= '<tr>';
                $html .= '<td><strong>' . $order['shop_name'] . '</strong></td>';
                $html .= '<td>#' . $order['id_original_order'] . '</td>';
                $html .= '<td>' . $order['lines_count'] . ' líneas</td>';
                $html .= '<td>' . date('d/m/Y H:i', strtotime($order['first_sync'])) . '</td>';
                $html .= '</tr>';
                $count++;
            }
            $html .= '</tbody></table></div>';
            if (count($pendingOrders) > 5) {
                $html .= '<div class="text-center"><form method="post" style="display:inline;"><button type="submit" name="submitViewPending" class="btn btn-default btn-sm">Ver todos los ' . count($pendingOrders) . ' pedidos pendientes</button></form></div>';
            }
            $html .= '</div></div>';
        }
        
        // Formulario para añadir tienda
        $html .= '<div class="panel syncrosevi-panel"><div class="panel-heading"><i class="icon-plus"></i> Añadir Nueva Tienda Hija</div><div class="panel-body">';
        $html .= '<form method="post" class="form-horizontal" id="add-shop-form">';
        
        $html .= '<div class="row">';
        $html .= '<div class="col-md-6">';
        $html .= '<div class="form-group">';
        $html .= '<label class="control-label">Nombre de la Tienda *</label>';
        $html .= '<input type="text" name="shop_name" class="form-control" placeholder="Ej: Tienda Barcelona" required>';
        $html .= '</div>';
        $html .= '<div class="form-group">';
        $html .= '<label class="control-label">URL de la Tienda *</label>';
        $html .= '<input type="url" name="shop_url" id="shop_url" class="form-control" placeholder="https://tienda-hija.com" required>';
        $html .= '</div>';
        $html .= '<div class="form-group">';
        $html .= '<label class="control-label">Clave API WebService *</label>';
        $html .= '<input type="text" name="api_key" id="api_key" class="form-control" placeholder="Clave del WebService de la tienda hija" required>';
        $html .= '<small class="help-block">Generar en: Parámetros Avanzados > WebService</small>';
        $html .= '</div>';
        $html .= '</div>';
        
        $html .= '<div class="col-md-6">';
        // Búsqueda de cliente
        $html .= '<div class="form-group">';
        $html .= '<label class="control-label">Cliente Asignado *</label>';
        $html .= '<div class="customer-search">';
        $html .= '<input type="text" id="customer-search" class="form-control" placeholder="Buscar cliente..." onkeyup="searchCustomers(this.value, \'customer-results\', \'selected-customer-id\')" autocomplete="off">';
        $html .= '<input type="hidden" id="selected-customer-id" name="id_customer" required>';
        $html .= '<div id="customer-results" class="customer-results"></div>';
        $html .= '</div>';
        $html .= '</div>';
        
        // Dirección de facturación
        $html .= '<div class="form-group">';
        $html .= '<label class="control-label">Dirección de Facturación *</label>';
        $html .= '<select name="id_address" id="id_address" class="form-control" required>';
        $html .= '<option value="">Primero selecciona un cliente</option>';
        $html .= '</select>';
        $html .= '</div>';
        
        // Grupo de precios
        $html .= '<div class="form-group">';
        $html .= '<label class="control-label">Grupo de Precios *</label>';
        $html .= '<select name="id_group" class="form-control" required>';
        $html .= '<option value="">Seleccionar grupo...</option>';
        foreach (Group::getGroups($this->context->language->id) as $group) {
            $html .= '<option value="' . $group['id_group'] . '">' . $group['name'] . '</option>';
        }
        $html .= '</select>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        
        // Configuración adicional
        $html .= '<div class="row">';
        $html .= '<div class="col-md-6">';
        
        // Transporte
        $html .= '<div class="form-group">';
        $html .= '<label class="control-label">¿Usar Transportista Específico?</label>';
        $html .= '<div>';
        $html .= '<label class="radio-inline"><input type="radio" name="use_carrier" value="0" onclick="toggleTransport(false)" checked> No</label>';
        $html .= '<label class="radio-inline"><input type="radio" name="use_carrier" value="1" onclick="toggleTransport(true)"> Sí</label>';
        $html .= '</div>';
        $html .= '<div id="transport-options" class="transport-options">';
        $html .= '<select name="id_carrier" id="id_carrier" class="form-control">';
        $html .= '<option value="">Seleccionar transportista...</option>';
        foreach (Carrier::getCarriers($this->context->language->id, true) as $carrier) {
            $html .= '<option value="' . $carrier['id_carrier'] . '">' . $carrier['name'] . '</option>';
        }
        $html .= '</select>';
        $html .= '</div>';
        $html .= '</div>';
        
        // ID de pedido inicial
        $html .= '<div class="form-group">';
        $html .= '<label class="control-label">Importar desde el pedido ID *</label>';
        $html .= '<input type="number" name="start_order_id" class="form-control" value="1" min="1" required>';
        $html .= '<small class="help-block">Solo se importarán pedidos con ID igual o mayor a este número</small>';
        $html .= '</div>';
        $html .= '</div>';
        
        $html .= '<div class="col-md-6">';
        // Estado del pedido
        $html .= '<div class="form-group">';
        $html .= '<label class="control-label">Estado del Pedido *</label>';
        $html .= '<select name="id_order_state" class="form-control" required>';
        $html .= '<option value="">Seleccionar estado...</option>';
        foreach (OrderState::getOrderStates($this->context->language->id) as $state) {
            $html .= '<option value="' . $state['id_order_state'] . '">' . $state['name'] . '</option>';
        }
        $html .= '</select>';
        $html .= '</div>';
        
        // Activo
        $html .= '<div class="form-group">';
        $html .= '<div class="checkbox">';
        $html .= '<label><input type="checkbox" name="active" value="1" checked> Tienda activa</label>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';

        // Estados a importar CORREGIDO
        $html .= '<div class="form-group">';
        $html .= '<label class="control-label">Estados de pedidos a importar *</label>';
        $html .= '<div class="alert alert-info"><small>Primero completa URL y API Key, luego haz clic en "Cargar Estados"</small></div>';
        $html .= '<button type="button" id="load-states-btn" class="btn btn-info btn-sm" onclick="loadStatesFromShop()">Cargar Estados de la Tienda Hija</button>';
        $html .= '<div id="import-states-container" class="import-states-container">';
        $html .= '</div>';
        $html .= '</div>';
        
        $html .= '<div class="form-group">';
        $html .= '<button type="submit" name="submitAddShop" class="btn btn-primary btn-lg"><i class="icon-plus"></i> Añadir Tienda Hija</button>';
        $html .= '</div>';
        $html .= '</form>';
        $html .= '</div></div>';
        
        // Lista de tiendas configuradas
        if ($shops) {
            $html .= '<div class="panel syncrosevi-panel"><div class="panel-heading"><i class="icon-list"></i> Tiendas Hijas Configuradas</div><div class="panel-body">';
            $html .= '<div class="table-responsive"><table class="table table-striped">';
            $html .= '<thead><tr><th>Nombre</th><th>URL</th><th>Cliente</th><th>Dirección</th><th>Transportista</th><th>Estado Pedido</th><th>Activa</th><th>Acciones</th></tr></thead><tbody>';
            foreach ($shops as $shop) {
                $html .= '<tr class="shop-row">';
                $html .= '<td><strong>' . $shop['name'] . '</strong></td>';
                $html .= '<td><small>' . $shop['url'] . '</small></td>';
                $html .= '<td>' . $shop['firstname'] . ' ' . $shop['lastname'] . '</td>';
                $html .= '<td>' . ($shop['address_alias'] ?: 'N/A') . '</td>';
                $html .= '<td>' . ($shop['carrier_name'] ?: '<em>Sin transportista</em>') . '</td>';
                $html .= '<td><span class="badge">' . $shop['state_name'] . '</span></td>';
                $html .= '<td>' . ($shop['active'] ? '<span class="badge badge-success">Sí</span>' : '<span class="badge badge-danger">No</span>') . '</td>';
                $html .= '<td>';
                $html .= '<div class="btn-group">';
                $html .= '<button type="button" class="btn btn-xs btn-info" onclick="testConnection(' . $shop['id_child_shop'] . ')" title="Probar Conexión"><i class="icon-link"></i></button>';
                $html .= '<button type="button" class="btn btn-xs btn-primary" onclick="editShop(' . $shop['id_child_shop'] . ')" title="Editar"><i class="icon-edit"></i></button>';
                $html .= '<button type="button" class="btn btn-xs btn-danger" onclick="deleteShop(' . $shop['id_child_shop'] . ')" title="Eliminar"><i class="icon-trash"></i></button>';
                $html .= '</div>';
                $html .= '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table></div></div></div>';
        }
        
        return $html;
    }

    /**
     * CORREGIDO: Procesar edición de tienda
     */
    protected function processEditShop()
    {
        $id_shop = (int)Tools::getValue('id_child_shop');
        $name = Tools::getValue('shop_name');
        $url = Tools::getValue('shop_url');
        $api_key = Tools::getValue('api_key');
        $id_customer = (int)Tools::getValue('id_customer');
        $id_address = (int)Tools::getValue('id_address');
        $id_group = (int)Tools::getValue('id_group');
        $use_carrier = (int)Tools::getValue('use_carrier');
        $id_carrier = $use_carrier ? (int)Tools::getValue('id_carrier') : null;
        $id_order_state = (int)Tools::getValue('id_order_state');
        $start_order_id = (int)Tools::getValue('start_order_id') ?: 1;
        $import_states = Tools::getValue('import_states');
        $active = Tools::getValue('active') ? 1 : 0;

        if (!$id_shop || empty($name) || empty($url) || empty($api_key) || !$id_customer || !$id_address || !$id_group || !$id_order_state || empty($import_states)) {
            return $this->displayError($this->l('Todos los campos obligatorios deben ser completados'));
        }

        // Validar URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->displayError($this->l('La URL no es válida'));
        }

        // Convertir array de estados a string
        $import_states_str = is_array($import_states) ? implode(',', array_map('intval', $import_states)) : $import_states;

        $updated = Db::getInstance()->update('syncrosevi_child_shops', array(
            'name' => pSQL($name),
            'url' => pSQL(rtrim($url, '/')),
            'api_key' => pSQL($api_key),
            'id_customer' => $id_customer,
            'id_group' => $id_group,
            'id_address' => $id_address,
            'id_carrier' => $id_carrier,
            'id_order_state' => $id_order_state,
            'start_order_id' => $start_order_id,
            'import_states' => pSQL($import_states_str),
            'active' => $active,
            'date_upd' => date('Y-m-d H:i:s')
        ), 'id_child_shop = ' . $id_shop);

        if ($updated) {
            return $this->displayConfirmation($this->l('Tienda hija actualizada correctamente'));
        } else {
            return $this->displayError($this->l('Error al actualizar la tienda hija'));
        }
    }

   
    /**
     * CORREGIDO: Procesar test de conexión
     */
protected function processTestConnection()
{
    $id_shop = (int)Tools::getValue('id_shop');
    
    if (!$id_shop) {
        header('Content-Type: application/json');
        die(json_encode(array('success' => false, 'message' => 'ID de tienda inválido')));
    }

    $shop = Db::getInstance()->getRow(
        'SELECT * FROM `' . _DB_PREFIX_ . 'syncrosevi_child_shops` WHERE id_child_shop = ' . $id_shop
    );

    if (!$shop) {
        header('Content-Type: application/json');
        die(json_encode(array('success' => false, 'message' => 'Tienda no encontrada')));
    }

    try {
        if (!class_exists('SyncroSeviWebservice')) {
            require_once(dirname(__FILE__).'/classes/SyncroSeviWebservice.php');
        }
        
        $webservice = new SyncroSeviWebservice($shop['url'], $shop['api_key'], true);
        $connection = $webservice->testConnection();
        $shopInfo = array();
        
        if ($connection) {
            try {
                $shopInfo = $webservice->getShopInfo();
            } catch (Exception $e) {
                // Si falla obtener info de tienda, no es crítico
                $shopInfo = array('name' => 'Tienda conectada');
            }
        }
        
        header('Content-Type: application/json');
        die(json_encode(array(
            'success' => $connection,
            'message' => $connection ? 'Conexión exitosa' : 'Error de conexión',
            'shop_info' => $shopInfo
        )));
        
    } catch (Exception $e) {
        header('Content-Type: application/json');
        die(json_encode(array(
            'success' => false, 
            'message' => 'Error: ' . $e->getMessage()
        )));
    }
}

    /**
     * Procesar sincronización manual
     */
    protected function processSyncOrders()
    {
        $results = $this->syncOrders();
        $output = '';
        
        foreach ($results as $result) {
            if ($result['status'] == 'success') {
                $output .= $this->displayConfirmation($result['shop'] . ': ' . $result['count'] . ' pedidos sincronizados');
            } else {
                $output .= $this->displayError($result['shop'] . ': ' . $result['message']);
            }
        }
        
        return $output;
    }

    /**
     * Procesar pedidos manual
     */
    protected function processOrdersAction()
    {
        $results = $this->processOrders();
        $output = '';
        
        foreach ($results as $result) {
            if ($result['status'] == 'success') {
                if (isset($result['order_id'])) {
                    $output .= $this->displayConfirmation($result['shop'] . ': Pedido #' . $result['order_id'] . ' creado con ' . $result['products_count'] . ' productos');
                } else {
                    $output .= $this->displayConfirmation($result['shop'] . ': ' . ($result['message'] ?: 'Procesado correctamente'));
                }
            } else {
                $output .= $this->displayError($result['shop'] . ': ' . $result['message']);
            }
        }
        
        return $output;
    }

    /**
     * CORREGIDO: Ver pedidos pendientes completo
     */
    protected function processViewPending()
    {
        $pendingOrders = Db::getInstance()->executeS(
            'SELECT cs.name as shop_name, cs.id_child_shop,
                   ol.id_original_order, 
                   GROUP_CONCAT(CONCAT(ol.product_name, " (", ol.quantity, ")") SEPARATOR ", ") as products,
                   SUM(ol.quantity) as total_quantity,
                   COUNT(ol.id_line) as lines_count,
                   MIN(ol.date_add) as first_sync
            FROM `' . _DB_PREFIX_ . 'syncrosevi_order_lines` ol
            JOIN `' . _DB_PREFIX_ . 'syncrosevi_child_shops` cs ON ol.id_child_shop = cs.id_child_shop
            WHERE ol.processed = 0
            GROUP BY cs.id_child_shop, ol.id_original_order
            ORDER BY cs.name ASC, ol.date_add DESC'
        );
        
        $html = '<div class="panel"><div class="panel-heading"><i class="icon-list"></i> Todos los Pedidos Pendientes de Procesamiento';
        $html .= ' <a href="' . $this->context->link->getAdminLink('AdminModules') . '&configure=syncrosevi" class="btn btn-default btn-sm pull-right">Volver</a>';
        $html .= '</div><div class="panel-body">';
        
        if (empty($pendingOrders)) {
            $html .= '<div class="alert alert-success text-center">';
            $html .= '<h4><i class="icon-check"></i> ¡Perfecto!</h4>';
            $html .= '<p>No hay pedidos pendientes de procesamiento.</p>';
            $html .= '<p>Todos los pedidos sincronizados han sido procesados correctamente.</p>';
            $html .= '</div>';
            
            $html .= '<div class="text-center">';
            $html .= '<form method="post" style="display: inline-block;">';
            $html .= '<button type="submit" name="submitSyncOrders" class="btn btn-primary btn-lg">';
            $html .= '<i class="icon-refresh"></i> Sincronizar Nuevos Pedidos';
            $html .= '</button>';
            $html .= '</form>';
            $html .= '</div>';
        } else {
            $html .= '<div class="alert alert-info">';
            $html .= '<strong>Información:</strong> Estos pedidos han sido sincronizados desde las tiendas hijas pero aún no han sido procesados. ';
            $html .= 'Haz clic en "Procesar Pedidos" para crear los pedidos únicos en la tienda madre.';
            $html .= '</div>';
            
            $html .= '<div class="table-responsive">';
            $html .= '<table class="table table-striped table-bordered">';
            $html .= '<thead><tr><th>Tienda Origen</th><th>Pedido #</th><th>Productos</th><th>Cantidad Total</th><th>Fecha Sincronización</th></tr></thead>';
            $html .= '<tbody>';
            
            $currentShop = '';
            foreach ($pendingOrders as $order) {
                $html .= '<tr' . ($order['shop_name'] != $currentShop ? ' class="active"' : '') . '>';
                
                if ($order['shop_name'] != $currentShop) {
                    $html .= '<td><strong>' . $order['shop_name'] . '</strong></td>';
                    $currentShop = $order['shop_name'];
                } else {
                    $html .= '<td></td>';
                }
                
                $html .= '<td><span class="badge badge-info">#' . $order['id_original_order'] . '</span></td>';
                $html .= '<td><small>' . $order['products'] . '</small></td>';
                $html .= '<td><span class="badge badge-warning">' . $order['total_quantity'] . '</span></td>';
                $html .= '<td>' . date('d/m/Y H:i', strtotime($order['first_sync'])) . '</td>';
                $html .= '</tr>';
            }
            
            $html .= '</tbody></table></div>';
            
            // Resumen y botones de acción
            $totalOrders = count($pendingOrders);
            $totalShops = count(array_unique(array_column($pendingOrders, 'shop_name')));
            
            $html .= '<div class="alert alert-warning">';
            $html .= '<strong>Resumen:</strong> Hay <strong>' . $totalOrders . '</strong> pedidos pendientes de <strong>' . $totalShops . '</strong> tiendas hijas.';
            $html .= '</div>';
            
            $html .= '<div class="text-center" style="margin-top: 20px;">';
            $html .= '<form method="post" style="display: inline-block; margin-right: 10px;">';
            $html .= '<button type="submit" name="submitProcessOrders" class="btn btn-success btn-lg">';
            $html .= '<i class="icon-cogs"></i> Procesar Todos los Pedidos (' . $totalOrders . ')';
            $html .= '</button>';
            $html .= '</form>';
            
            $html .= '<form method="post" style="display: inline-block;">';
            $html .= '<button type="submit" name="submitSyncOrders" class="btn btn-primary">';
            $html .= '<i class="icon-refresh"></i> Sincronizar Más Pedidos';
            $html .= '</button>';
            $html .= '</form>';
            $html .= '</div>';
        }
        
        $html .= '</div></div>';
        
        return $html;
        }
  
}
