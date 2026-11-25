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

require_once dirname(__FILE__) . '/classes/SyncroseviUpdater.php';

class Syncrosevi extends Module
{
    /** Código de excepción: el lote no tiene ningún producto que exista en la tienda madre */
    const ERR_NO_PRODUCTS = 4201;

    /** Estado de cada línea de la tienda hija (columna import_status) */
    const LINE_PENDING = 0;
    const LINE_IMPORTED = 1;
    const LINE_NOT_FOUND = 2;
    const LINE_NO_REFERENCE = 3;

    /** Tope del mensaje del pedido (en 1.6/1.7 el mensaje admite 1600 caracteres) */
    const MESSAGE_MAX = 1500;

    /** Tamaño máximo del log antes de rotarlo (5 MB) */
    const LOG_MAX_SIZE = 5242880;

    private $productCache = array();

    /** Mientras se crea o actualiza un pedido clonado no sale ningún correo a clientes */
    public static $mailWindow = 0;
    private static $mailMethodBackup = null;
    private static $staffEmails = null;

    /** Mientras se crea un clon o se aplica un estado venido de la hija, la madre no avisa a la hija (evita rebotes) */
    public static $noPush = 0;

    /** true cuando el webhook ya ha contestado al cron y sigue trabajando después (sync/process) */
    private $webhookResponded = false;

    /** Bloqueo para que no vayan dos sincronizaciones o procesamientos del webhook a la vez */
    private $webhookLock = null;

    public function __construct()
    {
        $this->name = 'syncrosevi';
        $this->tab = 'administration';
        $this->version = '1.4.2';
        $this->author = 'josra';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array(
            'min' => '1.7.0.0',
            'max' => _PS_VERSION_,
        );
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('SyncroSevi - Sincronización de Pedidos');
        $this->description = $this->l('Módulo para sincronizar pedidos entre tienda madre e hijas mediante WebService');
        $this->confirmUninstall = $this->l('¿Estás seguro de que quieres desinstalar?');

        // Crear configuraciones que falten en instalaciones antiguas (solo si el módulo ya está instalado)
        if ($this->id) {
            $this->ensureConfigurationExists();
        }
    }

    /**
     * Asegurar que las configuraciones necesarias existen
     */
    private function ensureConfigurationExists()
    {
        if (!Configuration::get('SYNCROSEVI_BATCH_LIMIT')) {
            Configuration::updateValue('SYNCROSEVI_BATCH_LIMIT', 10);
        }
        if (!Configuration::get('SYNCROSEVI_WEBHOOK_TOKEN')) {
            Configuration::updateValue('SYNCROSEVI_WEBHOOK_TOKEN', self::generateToken());
        }
    }

    public static function generateToken()
    {
        return md5('syncrosevi_' . Configuration::get('PS_SHOP_NAME') . '_' . Configuration::get('PS_SHOP_EMAIL') . '_' . uniqid('', true) . '_' . time());
    }

    /**
     * ======================================================================
     * LOG
     * ======================================================================
     */

    /** ¿Está activado el log detallado (consultas SQL, totales, etc.)? */
    public function isDebug()
    {
        return (bool) Configuration::get('SYNCROSEVI_DEBUG');
    }

    /**
     * Escribe en logs/syncrosevi_debug.log. Las líneas de detalle ($detail = true) solo se escriben
     * con el log detallado activado. El archivo se rota al llegar a 5 MB (se guarda una copia .1).
     */
    public function log($message, $detail = false)
    {
        if ($detail && !$this->isDebug()) {
            return;
        }
        $logDir = dirname(__FILE__) . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
            self::protectDir($logDir);
        }
        $logFile = $logDir . '/syncrosevi_debug.log';
        if (@filesize($logFile) > self::LOG_MAX_SIZE) {
            @rename($logFile, $logFile . '.1');
        }
        @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /** Bloquea el acceso web a un directorio (logs) */
    public static function protectDir($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        if (!file_exists($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order Deny,Allow\n    Deny from all\n</IfModule>\n");
        }
        if (!file_exists($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php\nheader('Location: ../');\nexit;\n");
        }
    }

    /**
     * ======================================================================
     * INSTALACIÓN
     * ======================================================================
     */

    /** true mientras se instala: PrestaShop activa el módulo dentro de install() */
    private static $setup = false;

    public function install()
    {
        self::$setup = true;
        $ok = $this->installSteps();
        self::$setup = false;
        if ($ok && !$this->getUpdater()->canEnable()) {
            // Instalado, pero desactivado hasta que la tienda tenga su licencia
            $this->disable();
        }

        return $ok;
    }

    private function installSteps()
    {
        if (!parent::install()
            || !$this->createTables()
            || !$this->installTab()
            || !$this->registerHook('actionEmailSendBefore')
            || !$this->registerHook('actionOrderHistoryAddAfter')
            || !$this->getUpdater()->registerHooks()
            || !$this->createInitialConfig()
            || !$this->createFreeShippingCarrier()) {
            return false;
        }

        return true;
    }

    /**
     * Activar el módulo. Si la tienda aún no tiene su alta, se pide al servidor en ese momento.
     */
    public function enable($force_all = false)
    {
        $updater = $this->getUpdater();
        if (!self::$setup && !$updater->canEnable()) {
            $this->_errors[] = $updater->noticeText();

            return false;
        }

        return parent::enable($force_all);
    }

    public function getUpdater()
    {
        return new SyncroseviUpdater($this);
    }

    /** Aviso periódico al servidor de actualizaciones (solo en el back-office) */
    public function hookActionAdminControllerSetMedia($params)
    {
        $this->getUpdater()->maybePing();
    }

    /** PS 8.1+: la versión nueva aparece en Gestor de módulos > Actualizaciones */
    public function hookActionListModules($params)
    {
        return $this->getUpdater()->getNativeModuleList();
    }

    public function uninstall()
    {
        $this->getUpdater()->notifyUninstall();
        if (!parent::uninstall()) {
            return false;
        }
        foreach (array('_UPD_TS', '_UPD_INFO', '_UPD_SU', '_UPD_LT') as $suffix) {
            Configuration::deleteByName('SYNCROSEVI' . $suffix);
        }
        $this->uninstallTab();
        $this->deleteFreeShippingCarrier();
        $this->dropTables();
        $this->removeConfig();

        return true;
    }

    /**
     * Crear transportista gratuito de SyncroSevi
     */
    private function createFreeShippingCarrier()
    {
        try {
            // Si ya existe (reinstalación), reutilizarlo
            $existing = (int) Configuration::get('SYNCROSEVI_FREE_CARRIER_ID');
            if ($existing) {
                $carrier = new Carrier($existing);
                if (Validate::isLoadedObject($carrier) && !$carrier->deleted) {
                    return true;
                }
            }

            $carrier = new Carrier();
            $carrier->name = 'SyncroSevi - Envío Gratuito';
            $carrier->active = 1;
            $carrier->is_free = 1;
            $carrier->shipping_handling = 0;
            $carrier->range_behavior = 0;
            $carrier->shipping_method = Carrier::SHIPPING_METHOD_WEIGHT;
            $carrier->max_width = 0;
            $carrier->max_height = 0;
            $carrier->max_depth = 0;
            $carrier->max_weight = 0;
            $carrier->grade = 1;

            $delay = array();
            foreach (Language::getLanguages(false) as $language) {
                $delay[$language['id_lang']] = 'Envío gratuito para pedidos consolidados';
            }
            $carrier->delay = $delay;

            if ($carrier->add()) {
                Configuration::updateValue('SYNCROSEVI_FREE_CARRIER_ID', $carrier->id);

                if ($this->setupCarrierRanges($carrier)) {
                    $this->log('✓ Transportista gratuito SyncroSevi creado correctamente (ID: ' . $carrier->id . ')');

                    return true;
                }
                $carrier->delete();
            }

            return false;
        } catch (Exception $e) {
            $this->log('✗ Error creando transportista gratuito: ' . $e->getMessage());

            return false;
        }
    }

    private function setupCarrierRanges($carrier)
    {
        try {
            $range_weight = new RangeWeight();
            $range_weight->id_carrier = $carrier->id;
            $range_weight->delimiter1 = 0;
            $range_weight->delimiter2 = 999999;
            if (!$range_weight->add()) {
                $this->log('✗ Error creando rango de peso');

                return false;
            }

            $range_price = new RangePrice();
            $range_price->id_carrier = $carrier->id;
            $range_price->delimiter1 = 0;
            $range_price->delimiter2 = 999999;
            if (!$range_price->add()) {
                $this->log('✗ Error creando rango de precio');

                return false;
            }

            foreach (Zone::getZones() as $zone) {
                $carrier->addZone($zone['id_zone']);
                Db::getInstance()->insert('delivery', array(
                    'id_carrier' => (int) $carrier->id,
                    'id_range_weight' => (int) $range_weight->id,
                    'id_zone' => (int) $zone['id_zone'],
                    'price' => 0,
                ));
                Db::getInstance()->insert('delivery', array(
                    'id_carrier' => (int) $carrier->id,
                    'id_range_price' => (int) $range_price->id,
                    'id_zone' => (int) $zone['id_zone'],
                    'price' => 0,
                ));
            }

            foreach (Group::getGroups(Configuration::get('PS_LANG_DEFAULT')) as $group) {
                Db::getInstance()->insert('carrier_group', array(
                    'id_carrier' => (int) $carrier->id,
                    'id_group' => (int) $group['id_group'],
                ), false, true, Db::INSERT_IGNORE);
            }

            return true;
        } catch (Exception $e) {
            $this->log('✗ Error configurando rangos del transportista: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Eliminar transportista gratuito al desinstalar
     */
    private function deleteFreeShippingCarrier()
    {
        try {
            $carrierId = (int) Configuration::get('SYNCROSEVI_FREE_CARRIER_ID');
            if ($carrierId) {
                $carrier = new Carrier($carrierId);
                if (Validate::isLoadedObject($carrier)) {
                    Db::getInstance()->delete('delivery', 'id_carrier = ' . $carrierId);
                    Db::getInstance()->delete('carrier_zone', 'id_carrier = ' . $carrierId);
                    Db::getInstance()->delete('carrier_group', 'id_carrier = ' . $carrierId);
                    Db::getInstance()->delete('range_weight', 'id_carrier = ' . $carrierId);
                    Db::getInstance()->delete('range_price', 'id_carrier = ' . $carrierId);
                    $carrier->delete();
                }
                Configuration::deleteByName('SYNCROSEVI_FREE_CARRIER_ID');
            }
        } catch (Exception $e) {
            $this->log('✗ Error eliminando transportista gratuito: ' . $e->getMessage());
        }

        return true;
    }

    private function createInitialConfig()
    {
        if (!Configuration::get('SYNCROSEVI_WEBHOOK_TOKEN')) {
            Configuration::updateValue('SYNCROSEVI_WEBHOOK_TOKEN', self::generateToken());
        }
        // Límite de pedidos por tienda en cada procesamiento (evita timeouts)
        if (!Configuration::get('SYNCROSEVI_BATCH_LIMIT')) {
            Configuration::updateValue('SYNCROSEVI_BATCH_LIMIT', 10);
        }
        Configuration::updateValue('SYNCROSEVI_DEBUG', 0);

        $logDir = dirname(__FILE__) . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        self::protectDir($logDir);

        return true;
    }

    private function removeConfig()
    {
        Configuration::deleteByName('SYNCROSEVI_WEBHOOK_TOKEN');
        Configuration::deleteByName('SYNCROSEVI_FREE_CARRIER_ID');
        Configuration::deleteByName('SYNCROSEVI_BATCH_LIMIT');
        Configuration::deleteByName('SYNCROSEVI_DEBUG');
        Configuration::deleteByName('SYNCROSEVI_CLONE_ACTIVE');

        return true;
    }

    private function dropTables()
    {
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'syncrosevi_order_lines`');
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'syncrosevi_order_tracking`');
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'syncrosevi_child_shops`');
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'syncrosevi_clone_customer`');
    }

    /**
     * Pestaña oculta (no aparece en el menú lateral): se entra desde Módulos > Configurar
     */
    public function installTab()
    {
        $id_tab = (int) Tab::getIdFromClassName('AdminSyncrosevi');
        $tab = $id_tab ? new Tab($id_tab) : new Tab();
        $tab->active = 1;
        $tab->class_name = 'AdminSyncrosevi';
        $tab->name = array();
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[$lang['id_lang']] = 'SyncroSevi';
        }
        $tab->id_parent = -1;
        $tab->module = $this->name;

        return $id_tab ? $tab->save() : $tab->add();
    }

    private function uninstallTab()
    {
        $id_tab = (int) Tab::getIdFromClassName('AdminSyncrosevi');
        if ($id_tab) {
            $tab = new Tab($id_tab);

            return $tab->delete();
        }

        return true;
    }

    private function createTables()
    {
        $sql = array();

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
            `realtime_enabled` tinyint(1) NOT NULL DEFAULT 1,
            `webhook_secret` varchar(64) NOT NULL DEFAULT "",
            `last_webhook` datetime NULL DEFAULT NULL,
            `date_add` datetime NOT NULL,
            `date_upd` datetime NOT NULL,
            PRIMARY KEY (`id_child_shop`),
            KEY `idx_active` (`active`),
            KEY `idx_realtime` (`realtime_enabled`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'syncrosevi_order_tracking` (
            `id_tracking` int(11) NOT NULL AUTO_INCREMENT,
            `id_original_order` int(11) NOT NULL,
            `id_child_shop` int(11) NOT NULL,
            `id_mother_order` int(11) NULL DEFAULT NULL,
            `child_reference` varchar(32) NOT NULL DEFAULT "",
            `processed` tinyint(1) NOT NULL DEFAULT 0,
            `realtime_sync` tinyint(1) NOT NULL DEFAULT 0,
            `date_sync` datetime NOT NULL,
            `date_realtime` datetime NULL DEFAULT NULL,
            `date_processed` datetime NULL DEFAULT NULL,
            PRIMARY KEY (`id_tracking`),
            UNIQUE KEY `unique_order_shop` (`id_original_order`, `id_child_shop`),
            KEY `idx_shop_order` (`id_child_shop`, `id_original_order`),
            KEY `idx_processed` (`processed`),
            KEY `idx_mother` (`id_mother_order`),
            KEY `idx_realtime` (`realtime_sync`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

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
            `import_status` tinyint(1) NOT NULL DEFAULT 0,
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

        $this->updateExistingTables();

        return true;
    }

    /**
     * Añade columnas e índices que falten en instalaciones antiguas (se puede llamar varias veces)
     */
    public function updateExistingTables()
    {
        $db = Db::getInstance();
        $columns = array_column((array) $db->executeS('SHOW COLUMNS FROM `' . _DB_PREFIX_ . 'syncrosevi_child_shops`'), 'Field');

        if (!in_array('start_order_id', $columns)) {
            $db->execute('ALTER TABLE `' . _DB_PREFIX_ . 'syncrosevi_child_shops` ADD COLUMN `start_order_id` int(11) NOT NULL DEFAULT 1 AFTER `id_order_state`');
        }
        if (!in_array('import_states', $columns)) {
            $db->execute('ALTER TABLE `' . _DB_PREFIX_ . 'syncrosevi_child_shops` ADD COLUMN `import_states` varchar(255) NOT NULL DEFAULT "2,3,4,5" AFTER `start_order_id`');
        }
        if (!in_array('realtime_enabled', $columns)) {
            $db->execute('ALTER TABLE `' . _DB_PREFIX_ . 'syncrosevi_child_shops` ADD COLUMN `realtime_enabled` tinyint(1) NOT NULL DEFAULT 1 AFTER `active`');
        }
        if (!in_array('webhook_secret', $columns)) {
            $db->execute('ALTER TABLE `' . _DB_PREFIX_ . 'syncrosevi_child_shops` ADD COLUMN `webhook_secret` varchar(64) NOT NULL DEFAULT "" AFTER `realtime_enabled`');
        }
        if (!in_array('last_webhook', $columns)) {
            $db->execute('ALTER TABLE `' . _DB_PREFIX_ . 'syncrosevi_child_shops` ADD COLUMN `last_webhook` datetime NULL DEFAULT NULL AFTER `webhook_secret`');
        }

        // Claves secretas para tiendas que no la tengan
        $shops = $db->executeS('SELECT id_child_shop FROM `' . _DB_PREFIX_ . 'syncrosevi_child_shops` WHERE webhook_secret = "" OR webhook_secret IS NULL');
        foreach ((array) $shops as $shop) {
            $db->update('syncrosevi_child_shops', array('webhook_secret' => bin2hex(random_bytes(32))), 'id_child_shop = ' . (int) $shop['id_child_shop']);
        }

        $trackingColumns = array_column((array) $db->executeS('SHOW COLUMNS FROM `' . _DB_PREFIX_ . 'syncrosevi_order_tracking`'), 'Field');
        if (!in_array('realtime_sync', $trackingColumns)) {
            $db->execute('ALTER TABLE `' . _DB_PREFIX_ . 'syncrosevi_order_tracking` ADD COLUMN `realtime_sync` tinyint(1) NOT NULL DEFAULT 0 AFTER `processed`');
        }
        if (!in_array('date_realtime', $trackingColumns)) {
            $db->execute('ALTER TABLE `' . _DB_PREFIX_ . 'syncrosevi_order_tracking` ADD COLUMN `date_realtime` datetime NULL DEFAULT NULL AFTER `date_sync`');
        }

        // Modo clonar (1.2.0)
        $shopColumns = array(
            'sync_mode' => 'varchar(16) NOT NULL DEFAULT "consolidated"',
            'clone_missing' => 'varchar(16) NOT NULL DEFAULT "hold"',
            'id_product_substitute' => 'int(11) NOT NULL DEFAULT 0',
            'clone_date' => 'varchar(16) NOT NULL DEFAULT "import"',
            'clone_state_sync' => 'tinyint(1) NOT NULL DEFAULT 0',
            'clone_state_map' => 'text NULL',
            'clone_state_map_out' => 'text NULL',
            'clone_state_email' => 'tinyint(1) NOT NULL DEFAULT 0',
            'clone_tax_map' => 'text NULL',
        );
        foreach ($shopColumns as $name => $definition) {
            if (!in_array($name, $columns)) {
                $db->execute('ALTER TABLE `' . _DB_PREFIX_ . 'syncrosevi_child_shops` ADD COLUMN `' . $name . '` ' . $definition);
            }
        }
        $trackingNew = array(
            'is_clone' => 'tinyint(1) NOT NULL DEFAULT 0',
            'child_state' => 'int(11) NOT NULL DEFAULT 0',
            'clone_error' => 'text NULL',
        );
        foreach ($trackingNew as $name => $definition) {
            if (!in_array($name, $trackingColumns)) {
                $db->execute('ALTER TABLE `' . _DB_PREFIX_ . 'syncrosevi_order_tracking` ADD COLUMN `' . $name . '` ' . $definition);
            }
        }
        // 1.4.0: referencia del pedido de la hija (para buscarlo) y estado de cada línea (importada / fuera)
        if (!in_array('child_reference', $trackingColumns)) {
            $db->execute('ALTER TABLE `' . _DB_PREFIX_ . 'syncrosevi_order_tracking` ADD COLUMN `child_reference` varchar(32) NOT NULL DEFAULT "" AFTER `id_mother_order`');
        }
        $lineColumns = array_column((array) $db->executeS('SHOW COLUMNS FROM `' . _DB_PREFIX_ . 'syncrosevi_order_lines`'), 'Field');
        if (!in_array('import_status', $lineColumns)) {
            $db->execute('ALTER TABLE `' . _DB_PREFIX_ . 'syncrosevi_order_lines` ADD COLUMN `import_status` tinyint(1) NOT NULL DEFAULT 0 AFTER `processed`');
        }

        $db->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'syncrosevi_clone_customer` (
            `id_customer` int(11) NOT NULL,
            `id_child_shop` int(11) NOT NULL DEFAULT 0,
            `date_add` datetime NOT NULL,
            PRIMARY KEY (`id_customer`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;');

        // Índice para obtener rápido los pedidos ya sincronizados de cada tienda
        $indexes = array_column((array) $db->executeS('SHOW INDEX FROM `' . _DB_PREFIX_ . 'syncrosevi_order_tracking`'), 'Key_name');
        if (!in_array('idx_shop_order', $indexes)) {
            $db->execute('ALTER TABLE `' . _DB_PREFIX_ . 'syncrosevi_order_tracking` ADD INDEX `idx_shop_order` (`id_child_shop`, `id_original_order`)');
        }
        if (!in_array('idx_mother', $indexes)) {
            $db->execute('ALTER TABLE `' . _DB_PREFIX_ . 'syncrosevi_order_tracking` ADD INDEX `idx_mother` (`id_mother_order`)');
        }

        return true;
    }

    /**
     * ======================================================================
     * UTILIDADES
     * ======================================================================
     */

    private function loadWebservice()
    {
        if (!class_exists('SyncroSeviWebservice')) {
            require_once dirname(__FILE__) . '/classes/SyncroSeviWebservice.php';
        }
    }

    /** Tiendas hijas activas (o solo una si se indica) */
    private function getActiveShops($onlyShopId = null)
    {
        return (array) Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'syncrosevi_child_shops` WHERE `active` = 1'
            . ($onlyShopId ? ' AND id_child_shop = ' . (int) $onlyShopId : '')
            . ' ORDER BY id_child_shop ASC'
        );
    }

    /** Estados a importar de una tienda como array de enteros */
    public static function parseStates($states)
    {
        return array_values(array_filter(array_map('intval', explode(',', (string) $states))));
    }

    /**
     * URL del webhook de esta tienda (controlador de módulo, funciona también en PrestaShop 9 con Apache)
     */
    public function getWebhookUrl($action = 'realtime_sync')
    {
        $params = array('security_token' => Configuration::get('SYNCROSEVI_WEBHOOK_TOKEN'));
        if ($action) {
            $params = array('action' => $action) + $params;
        }

        return Context::getContext()->link->getModuleLink($this->name, 'webhook', $params);
    }

    /**
     * ======================================================================
     * SINCRONIZACIÓN (descarga pedidos de las tiendas hijas a la tabla temporal)
     * ======================================================================
     */

    /**
     * Guardar un pedido de la tienda hija en la tabla temporal
     *
     * @return bool true si se ha insertado (false si ya estaba)
     */
    private function syncOrderToTemp($order, $shop)
    {
        if (empty($order['order_rows'])) {
            return false;
        }

        $db = Db::getInstance();
        $existing = $db->getValue(
            'SELECT id_tracking FROM `' . _DB_PREFIX_ . 'syncrosevi_order_tracking`
             WHERE id_original_order = ' . (int) $order['id'] . '
             AND id_child_shop = ' . (int) $shop['id_child_shop']
        );
        if ($existing) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        // INSERT IGNORE: si dos procesos (cron + webhook) llegan a la vez, solo uno inserta las líneas
        $db->insert('syncrosevi_order_tracking', array(
            'id_original_order' => (int) $order['id'],
            'id_child_shop' => (int) $shop['id_child_shop'],
            'child_reference' => pSQL(Tools::substr(isset($order['reference']) ? (string) $order['reference'] : '', 0, 32)),
            'processed' => 0,
            'date_sync' => $now,
        ), false, true, Db::INSERT_IGNORE);
        if (!$db->Affected_Rows()) {
            return false;
        }

        $rows = array();
        foreach ($order['order_rows'] as $line) {
            $rows[] = array(
                'id_child_shop' => (int) $shop['id_child_shop'],
                'id_original_order' => (int) $order['id'],
                'id_product' => (int) $line['product_id'],
                'id_product_attribute' => (int) $line['product_attribute_id'],
                'quantity' => (int) $line['product_quantity'],
                'product_reference' => pSQL($line['product_reference']),
                'product_name' => pSQL(Tools::substr($line['product_name'], 0, 255)),
                'processed' => 0,
                'date_add' => $now,
            );
        }
        $db->insert('syncrosevi_order_lines', $rows);

        return true;
    }

    /**
     * Sincronizar pedidos desde las tiendas hijas activas
     *
     * @param int|null $onlyShopId para sincronizar solo una tienda
     */
    public function syncOrders($onlyShopId = null)
    {
        if (!$this->getUpdater()->ready()) {
            return array();
        }
        $this->loadWebservice();
        $syncResults = array();

        foreach ($this->getActiveShops($onlyShopId) as $shop) {
            try {
                $webservice = new SyncroSeviWebservice($shop['url'], $shop['api_key'], $this->isDebug());

                // Pedidos que ya tenemos: no se vuelven a descargar
                $known = Db::getInstance()->executeS(
                    'SELECT id_original_order FROM `' . _DB_PREFIX_ . 'syncrosevi_order_tracking`
                     WHERE id_child_shop = ' . (int) $shop['id_child_shop'] . '
                     AND id_original_order >= ' . (int) $shop['start_order_id']
                );
                $knownIds = array_column((array) $known, 'id_original_order');

                $orders = $webservice->getNewOrders($shop['start_order_id'], $shop['import_states'], $knownIds);

                $syncCount = 0;
                foreach ($orders as $order) {
                    if ($this->syncOrderToTemp($order, $shop)) {
                        ++$syncCount;
                    }
                }

                if ($syncCount) {
                    $this->log('Sincronización ' . $shop['name'] . ': ' . $syncCount . ' pedidos nuevos');
                }

                // Modo clonar: los cambios de estado de la hija pasan a los pedidos clonados
                if (self::isCloneShop($shop) && self::syncIn($shop)) {
                    try {
                        $this->syncCloneStates($shop, $webservice);
                    } catch (Exception $e) {
                        $this->log('✗ Estados de los clones de ' . $shop['name'] . ': ' . $e->getMessage());
                    }
                }

                $syncResults[] = array(
                    'shop' => $shop['name'],
                    'count' => $syncCount,
                    'status' => 'success',
                    'more' => $webservice->hasMore,
                );
            } catch (Exception $e) {
                $this->log('✗ Error sincronizando ' . $shop['name'] . ': ' . $e->getMessage());
                $syncResults[] = array(
                    'shop' => $shop['name'],
                    'count' => 0,
                    'status' => 'error',
                    'message' => $e->getMessage(),
                );
            }
        }

        return $syncResults;
    }

    /**
     * ======================================================================
     * PROCESAMIENTO (crea un pedido consolidado por tienda en la tienda madre)
     * ======================================================================
     */

    /**
     * Procesar pedidos pendientes - CREA UN SOLO PEDIDO CONSOLIDADO POR TIENDA
     *
     * Entra todo producto cuya referencia exista en la madre, esté activo o no y tenga stock o no
     * (no se toca el stock). Solo se queda fuera lo que no coincide, y queda apuntado línea a línea.
     * Solo se marcan como procesados los pedidos del lote cuando el pedido madre se crea bien
     * (o cuando ninguna de sus referencias existe en la tienda madre). Si falla, quedan pendientes
     * para el siguiente intento y el error se devuelve en el resultado.
     *
     * @param int $limit número máximo de pedidos por tienda (null = configuración)
     * @param int|null $onlyShopId para procesar solo una tienda
     */
    public function processOrders($limit = null, $onlyShopId = null)
    {
        if (!$this->getUpdater()->ready()) {
            return array();
        }
        if ($limit === null) {
            $limit = (int) Configuration::get('SYNCROSEVI_BATCH_LIMIT') ?: 10;
        }
        if ($limit <= 0 || $limit > 50) {
            $limit = 10;
        }

        $db = Db::getInstance();
        $processResults = array();
        $this->ensureContext();

        foreach ($this->getActiveShops($onlyShopId) as $shop) {
            if (self::isCloneShop($shop)) {
                $processResults = array_merge($processResults, $this->processCloneOrders($shop, $limit));
                continue;
            }
            $idShop = (int) $shop['id_child_shop'];
            try {
                $limitedOrderIds = $db->executeS(
                    'SELECT DISTINCT id_original_order FROM `' . _DB_PREFIX_ . 'syncrosevi_order_lines`
                     WHERE id_child_shop = ' . $idShop . ' AND processed = 0
                     ORDER BY id_original_order ASC
                     LIMIT ' . (int) $limit
                );
                if (empty($limitedOrderIds)) {
                    continue;
                }

                $orderIds = array_map('intval', array_column($limitedOrderIds, 'id_original_order'));
                $inIds = implode(',', $orderIds);

                $pendingLines = $db->executeS(
                    'SELECT * FROM `' . _DB_PREFIX_ . 'syncrosevi_order_lines`
                     WHERE id_child_shop = ' . $idShop . ' AND processed = 0
                     AND id_original_order IN (' . $inIds . ')'
                );
                if (empty($pendingLines)) {
                    continue;
                }

                $this->log('Procesando lote de ' . count($orderIds) . ' pedidos de ' . $shop['name'] . ' (#' . implode(', #', $orderIds) . ')');

                // Entra todo lo que coincida por referencia (esté como esté en la madre); fuera solo lo que no coincide
                $products = array();
                $missingLines = array();
                $lineStatus = array();
                foreach ($pendingLines as $line) {
                    $reference = trim((string) $line['product_reference']);
                    $found = $reference !== '' ? $this->findProductByReference($reference) : null;
                    if (!$found) {
                        $lineStatus[(int) $line['id_line']] = $reference === '' ? self::LINE_NO_REFERENCE : self::LINE_NOT_FOUND;
                        $missingLines[] = $line;
                        $this->log('✗ Fuera: pedido #' . (int) $line['id_original_order'] . ' de ' . $shop['name'] . ' · '
                            . ($reference !== '' ? 'referencia "' . $reference . '" (no existe en la madre)' : 'línea sin referencia')
                            . ' · «' . $line['product_name'] . '» · ' . (int) $line['quantity'] . ' uds');
                        continue;
                    }
                    $lineStatus[(int) $line['id_line']] = self::LINE_IMPORTED;
                    // Se agrupan por producto de la madre y se suman las cantidades
                    $key = (int) $found['id_product'] . '_' . (int) $found['id_product_attribute'];
                    if (isset($products[$key])) {
                        $products[$key]['quantity'] += (int) $line['quantity'];
                    } else {
                        $products[$key] = array(
                            'id_product' => (int) $found['id_product'],
                            'id_product_attribute' => (int) $found['id_product_attribute'],
                            'quantity' => (int) $line['quantity'],
                            'reference' => $reference,
                        );
                    }
                }
                $missing = self::groupMissing($missingLines);

                $orderId = null;
                $validProducts = 0;
                $noProducts = false;

                if (!$products) {
                    // Ninguna referencia coincide: se marcan como procesados sin pedido (y quedan a la vista como «fuera»)
                    $noProducts = true;
                    $this->markBatchProcessed($idShop, $inIds, 0, $lineStatus);
                    $this->log('Lote de ' . $shop['name'] . ' (#' . implode(', #', $orderIds) . ') procesado sin pedido: ninguna referencia existe en la madre (' . self::missingSummary($missing) . ')');
                } else {
                    $db->execute('START TRANSACTION');
                    try {
                        $customer = $this->validateCustomer($shop['id_customer']);
                        $address = $this->validateAddress($shop['id_address'], $customer->id);
                        $created = $this->createMotherOrder($products, $shop, $customer, $address, $missing);
                        $orderId = $created['id_order'];
                        $validProducts = $created['products'];
                        $this->markBatchProcessed($idShop, $inIds, $orderId, $lineStatus);
                        $db->execute('COMMIT');
                    } catch (Exception $e) {
                        $db->execute('ROLLBACK');
                        if ($e->getCode() != self::ERR_NO_PRODUCTS) {
                            throw $e;
                        }
                        // Carrito con total 0: como hasta ahora, se marca procesado sin pedido
                        $noProducts = true;
                        $this->markBatchProcessed($idShop, $inIds, 0, $lineStatus);
                        $this->log('Lote de ' . $shop['name'] . ' marcado como procesado sin pedido: ' . $e->getMessage());
                    } catch (Throwable $e) {
                        // Errores fatales de PHP 7+ (TypeError...): se deshace todo y los pedidos siguen pendientes
                        $db->execute('ROLLBACK');
                        throw new Exception($e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
                    }
                    if ($orderId) {
                        $this->log('Lote de ' . $shop['name'] . ' (#' . implode(', #', $orderIds) . ') → pedido madre #' . $orderId . ': '
                            . $validProducts . ' productos' . ($missing ? ', ' . count($missing) . ' fuera (' . self::missingSummary($missing) . ')' : ', ninguno fuera'));
                    }
                }

                if ($orderId) {
                    $processResults[] = array(
                        'shop' => $shop['name'],
                        'order_id' => $orderId,
                        'products_count' => $validProducts,
                        'orders_count' => count($orderIds),
                        'missing_count' => count($missing),
                        'status' => 'success',
                    );
                } elseif ($noProducts) {
                    $processResults[] = array(
                        'shop' => $shop['name'],
                        'status' => 'success',
                        'orders_count' => count($orderIds),
                        'missing_count' => count($missing),
                        'message' => $products ? 'Procesado sin crear pedido (el carrito daba 0 €)' : 'Procesado sin crear pedido (ninguna referencia existe en la tienda madre)',
                    );
                }
            } catch (Exception $e) {
                $this->log('✗ Error procesando ' . $shop['name'] . ' (los pedidos siguen pendientes): ' . $e->getMessage());
                $processResults[] = array(
                    'shop' => $shop['name'],
                    'status' => 'error',
                    'message' => $e->getMessage() . ' (los pedidos siguen pendientes)',
                );
            }
        }

        return $processResults;
    }

    /**
     * Desde cron (línea de comandos) el contexto no tiene moneda, idioma ni país:
     * sin ellos PrestaShop falla al calcular precios del carrito.
     */
    private function ensureContext()
    {
        $context = Context::getContext();
        if (!$context->shop || !$context->shop->id) {
            $context->shop = new Shop((int) Configuration::get('PS_SHOP_DEFAULT'));
        }
        if (!$context->language || !$context->language->id) {
            $context->language = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        }
        if (!$context->currency || !$context->currency->id) {
            $context->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
        }
        if (!$context->country || !$context->country->id) {
            $context->country = new Country((int) Configuration::get('PS_COUNTRY_DEFAULT'));
        }
        if (!$context->link) {
            $context->link = new Link();
        }
    }

    /** Marca como procesadas las líneas y el tracking de los pedidos del lote (y solo esos), con el estado de cada línea */
    private function markBatchProcessed($idShop, $inIds, $motherOrderId, array $lineStatus = array())
    {
        $db = Db::getInstance();
        $this->saveLineStatus($lineStatus);
        $db->execute(
            'UPDATE `' . _DB_PREFIX_ . 'syncrosevi_order_lines`
             SET processed = 1
             WHERE id_child_shop = ' . (int) $idShop . ' AND processed = 0
             AND id_original_order IN (' . $inIds . ')'
        );
        $db->execute(
            'UPDATE `' . _DB_PREFIX_ . 'syncrosevi_order_tracking`
             SET id_mother_order = ' . (int) $motherOrderId . ',
                 processed = 1,
                 date_processed = "' . date('Y-m-d H:i:s') . '"
             WHERE id_child_shop = ' . (int) $idShop . ' AND processed = 0
             AND id_original_order IN (' . $inIds . ')'
        );
    }

    /** Guarda el estado de cada línea (id_line => estado) */
    public function saveLineStatus(array $lineStatus)
    {
        $byStatus = array();
        foreach ($lineStatus as $idLine => $status) {
            $byStatus[(int) $status][] = (int) $idLine;
        }
        foreach ($byStatus as $status => $ids) {
            foreach (array_chunk($ids, 500) as $chunk) {
                Db::getInstance()->execute(
                    'UPDATE `' . _DB_PREFIX_ . 'syncrosevi_order_lines` SET import_status = ' . (int) $status . '
                     WHERE id_line IN (' . implode(',', $chunk) . ')'
                );
            }
        }
    }

    /**
     * Agrupa las líneas que se quedaron fuera por referencia (sin referencia: por nombre),
     * sumando cantidades y juntando los pedidos de la hija.
     *
     * @param array $lines filas con product_reference, product_name, quantity, id_original_order
     *
     * @return array lista de array('reference', 'name', 'quantity', 'orders' => array(ids))
     */
    public static function groupMissing(array $lines)
    {
        $groups = array();
        foreach ($lines as $line) {
            $reference = trim((string) $line['product_reference']);
            $name = trim((string) $line['product_name']);
            $key = $reference !== '' ? 'r:' . Tools::strtolower($reference) : 'n:' . Tools::strtolower($name);
            if (!isset($groups[$key])) {
                $groups[$key] = array('reference' => $reference, 'name' => $name, 'quantity' => 0, 'orders' => array());
            }
            $groups[$key]['quantity'] += (int) $line['quantity'];
            $groups[$key]['orders'][(int) $line['id_original_order']] = (int) $line['id_original_order'];
        }
        foreach ($groups as &$group) {
            sort($group['orders']);
            $group['orders'] = array_values($group['orders']);
        }
        unset($group);

        return array_values($groups);
    }

    /** Resumen corto para el log: REF1 x6, REF2 x1… */
    public static function missingSummary(array $missing)
    {
        $parts = array();
        foreach ($missing as $m) {
            $parts[] = ($m['reference'] !== '' ? $m['reference'] : 'sin referencia «' . $m['name'] . '»') . ' x' . (int) $m['quantity'];
        }

        return implode(', ', $parts);
    }

    /** Una línea de producto fuera para el mensaje del pedido */
    public static function missingLine(array $m)
    {
        $clean = function ($text) {
            return trim(preg_replace('/\s+/', ' ', str_replace(array('<', '>'), '', (string) $text)));
        };
        $orders = $m['orders'];
        $label = count($orders) > 1 ? 'pedidos' : 'pedido';
        if (count($orders) > 8) {
            $ordersText = '#' . implode(', #', array_slice($orders, 0, 8)) . '… (' . count($orders) . ')';
        } else {
            $ordersText = '#' . implode(', #', $orders);
        }

        return '- ' . ($m['reference'] !== '' ? $clean($m['reference']) : 'SIN REFERENCIA') . ' · ' . Tools::substr($clean($m['name']), 0, 120)
            . ' · ' . (int) $m['quantity'] . ' uds (' . $label . ' ' . $ordersText . ')';
    }

    /**
     * Mensaje del pedido madre: título con la fecha en castellano y, debajo, los productos que se quedaron fuera.
     * Con saltos de línea, y cortado para que el pedido se cree siempre (en 1.6/1.7 el mensaje tiene un tope).
     */
    public function buildOrderMessage($title, array $missing)
    {
        $text = $title;
        if (!$missing) {
            return $text;
        }
        $text .= "\nProductos no importados (la referencia no existe en la tienda madre):";
        $more = function ($n) {
            return "\n… y " . $n . ' más (ver Pedidos procesados en SyncroSevi)';
        };
        $count = count($missing);
        foreach (array_values($missing) as $i => $m) {
            $line = "\n" . self::missingLine($m);
            $rest = $count - $i - 1;
            $reserve = $rest > 0 ? Tools::strlen($more($rest)) : 0;
            if (Tools::strlen($text . $line) + $reserve > self::MESSAGE_MAX) {
                $text .= $more($rest + 1);
                break;
            }
            $text .= $line;
        }

        return $text;
    }

    /** Fecha y hora actuales en formato castellano (dd/mm/aaaa hh:mm:ss) */
    public static function spanishNow()
    {
        return date('d/m/Y H:i:s');
    }

    /**
     * Mete un producto en el carrito aunque PrestaShop no lo deje (desactivado, no disponible para pedidos,
     * sin stock, cantidad mínima, pack sin stock en sus componentes). No toca el stock: al crearse el pedido
     * baja como en cualquier venta (y puede quedar en negativo).
     *
     * @return bool
     */
    public function addToCartAnyway(Cart $cart, $idProduct, $idAttribute, $quantity, Shop $shop, $reference = '')
    {
        $quantity = (int) $quantity;
        if ($quantity <= 0) {
            return true;
        }

        // 1. Lo normal de PrestaShop (en las versiones que lo admiten, sin mirar el stock)
        $result = $cart->updateQty($quantity, (int) $idProduct, (int) $idAttribute, false, 'up', (int) $cart->id_address_delivery, $shop, false, true);
        if ($result && $result > 0) {
            $this->log('✓ "' . $reference . '" añadido (cantidad: ' . $quantity . ')', true);

            return true;
        }

        // 2. PrestaShop no lo deja: la línea se mete directamente en el carrito
        $db = Db::getInstance();
        $hasCustomization = (bool) $db->executeS('SHOW COLUMNS FROM `' . _DB_PREFIX_ . 'cart_product` LIKE "id_customization"');
        $where = 'id_cart = ' . (int) $cart->id . ' AND id_product = ' . (int) $idProduct . ' AND id_product_attribute = ' . (int) $idAttribute
            . ($hasCustomization ? ' AND id_customization = 0' : '');
        $current = (int) $db->getValue('SELECT SUM(quantity) FROM `' . _DB_PREFIX_ . 'cart_product` WHERE ' . $where);
        if ($current > 0) {
            $ok = $db->execute('UPDATE `' . _DB_PREFIX_ . 'cart_product` SET quantity = quantity + ' . $quantity . ' WHERE ' . $where . ' LIMIT 1');
        } else {
            $row = array(
                'id_cart' => (int) $cart->id,
                'id_product' => (int) $idProduct,
                'id_product_attribute' => (int) $idAttribute,
                // PrestaShop 9 guarda las líneas sin dirección de entrega
                'id_address_delivery' => version_compare(_PS_VERSION_, '9.0.0', '>=') ? 0 : (int) $cart->id_address_delivery,
                'id_shop' => (int) $shop->id,
                'quantity' => $quantity,
                'date_add' => date('Y-m-d H:i:s'),
            );
            if ($hasCustomization) {
                $row['id_customization'] = 0;
            }
            $ok = $db->insert('cart_product', $row);
        }
        if ($ok) {
            $this->log('✓ "' . $reference . '" añadido directamente (PrestaShop no lo dejaba: desactivado, no disponible, sin stock o cantidad mínima) (cantidad: ' . $quantity . ')', true);
        }

        return (bool) $ok;
    }

    /**
     * Si PrestaShop ha cambiado el pedido a «En espera de reposición» (stock en negativo),
     * lo vuelve al estado configurado para la tienda, sin correos.
     */
    public function keepOrderState($idOrder, $state)
    {
        $state = (int) $state;
        if (!$state) {
            return;
        }
        $order = new Order((int) $idOrder);
        if (!Validate::isLoadedObject($order) || (int) $order->current_state === $state) {
            return;
        }
        $previous = (int) $order->current_state;
        $history = new OrderHistory();
        $history->id_order = (int) $order->id;
        $history->id_employee = 0;
        $history->changeIdOrderState($state, $order, true);
        $history->add();
        $this->log('Pedido madre #' . (int) $order->id . ': PrestaShop lo pasó al estado ' . $previous . ' (en espera de reposición por stock); vuelto al estado configurado (' . $state . ')');
    }

    /**
     * ======================================================================
     * WEBHOOK
     * ======================================================================
     */

    /**
     * Verificar firma de webhook de seguridad
     */
    public function verifyWebhookSignature($shopId, $orderId, $newState, $timestamp, $receivedSignature)
    {
        $secret = Db::getInstance()->getValue(
            'SELECT webhook_secret FROM `' . _DB_PREFIX_ . 'syncrosevi_child_shops`
             WHERE id_child_shop = ' . (int) $shopId
        );
        if (!$secret) {
            $this->log('Webhook rechazado: la tienda ' . (int) $shopId . ' no existe o no tiene clave secreta');

            return false;
        }

        $expected = hash_hmac('sha256', $shopId . $orderId . $newState . $timestamp, $secret);
        if (!is_string($receivedSignature) || !hash_equals($expected, $receivedSignature)) {
            $this->log('Webhook rechazado: firma no válida (tienda ' . (int) $shopId . ', pedido ' . (int) $orderId . ')');

            return false;
        }

        return true;
    }

    /**
     * Sincronizar un pedido específico en tiempo real (llamado por el webhook de la tienda hija)
     */
    public function syncSingleOrderRealtime($shopId, $orderId, $newState)
    {
        $this->loadWebservice();

        try {
            $shop = Db::getInstance()->getRow(
                'SELECT * FROM `' . _DB_PREFIX_ . 'syncrosevi_child_shops`
                 WHERE id_child_shop = ' . (int) $shopId . ' AND active = 1'
            );
            if (!$shop) {
                throw new Exception('Tienda no encontrada o inactiva: ' . $shopId);
            }
            if (!$shop['realtime_enabled']) {
                $this->log('Webhook ignorado: la tienda ' . $shop['name'] . ' tiene desactivado el tiempo real', true);

                return false;
            }

            // Modo clonar: si el pedido ya está clonado, el aviso es un cambio de estado
            if (self::isCloneShop($shop)) {
                $clone = Db::getInstance()->getRow(
                    'SELECT id_mother_order, child_state FROM `' . _DB_PREFIX_ . 'syncrosevi_order_tracking`
                     WHERE id_original_order = ' . (int) $orderId . ' AND id_child_shop = ' . (int) $shopId . '
                     AND processed = 1 AND is_clone = 1 AND id_mother_order > 0'
                );
                if ($clone) {
                    if ((int) $clone['child_state'] === (int) $newState) {
                        return false; // ya lo tenemos (p. ej. lo acaba de poner la propia madre)
                    }

                    return $this->applyCloneState($shop, (int) $orderId, (int) $clone['id_mother_order'], (int) $newState);
                }
            }
            if (!in_array((int) $newState, self::parseStates($shop['import_states']))) {
                $this->log('Webhook: estado ' . $newState . ' no está en la lista de importación de ' . $shop['name'], true);

                return false;
            }
            if ((int) $orderId < (int) $shop['start_order_id']) {
                return false;
            }

            $existing = Db::getInstance()->getValue(
                'SELECT id_tracking FROM `' . _DB_PREFIX_ . 'syncrosevi_order_tracking`
                 WHERE id_original_order = ' . (int) $orderId . ' AND id_child_shop = ' . (int) $shopId
            );
            if ($existing) {
                $this->log('Webhook: pedido #' . $orderId . ' de ' . $shop['name'] . ' ya estaba sincronizado', true);

                return false;
            }

            // Solo se pide ese pedido a la tienda hija
            $webservice = new SyncroSeviWebservice($shop['url'], $shop['api_key'], $this->isDebug());
            $orders = $webservice->getOrdersByIds(array((int) $orderId), $shop['import_states']);
            if (empty($orders)) {
                throw new Exception('Pedido ' . $orderId . ' no encontrado en la tienda ' . $shop['name'] . ' (o su estado no se importa)');
            }

            if ($this->syncOrderToTemp($orders[0], $shop)) {
                Db::getInstance()->execute(
                    'UPDATE `' . _DB_PREFIX_ . 'syncrosevi_order_tracking`
                     SET realtime_sync = 1, date_realtime = "' . date('Y-m-d H:i:s') . '"
                     WHERE id_original_order = ' . (int) $orderId . ' AND id_child_shop = ' . (int) $shopId
                );
                $this->log('✓ TIEMPO REAL: pedido #' . $orderId . ' de ' . $shop['name'] . ' sincronizado');

                return true;
            }

            return false;
        } catch (Exception $e) {
            $this->log('✗ TIEMPO REAL: tienda ' . $shopId . ' pedido ' . $orderId . ': ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Atiende una petición al webhook (la usan el controlador de módulo y el webhook.php antiguo)
     *
     * @return array array(código HTTP, datos de la respuesta)
     */
    public function handleWebhookRequest()
    {
        $action = (string) Tools::getValue('action', '');
        $expected = (string) Configuration::get('SYNCROSEVI_WEBHOOK_TOKEN');
        $token = (string) Tools::getValue('security_token', '');

        if ($expected === '') {
            return array(500, array('success' => false, 'error' => 'Webhook token not configured. Please reinstall the module.'));
        }
        if (!hash_equals($expected, $token)) {
            return array(403, array('success' => false, 'error' => 'Invalid security token'));
        }
        if (!$this->active) {
            return array(503, array('success' => false, 'error' => 'SyncroSevi module is inactive'));
        }
        if (!$this->getUpdater()->ready()) {
            return array(403, array('success' => false, 'error' => 'SyncroSevi is not enabled for this shop'));
        }

        try {
            switch ($action) {
                case 'sync':
                    if (!$this->lockWebhook()) {
                        return array(200, $this->webhookBusy('sync'));
                    }
                    $this->respondEarly('sync');
                    try {
                        $results = $this->syncOrders();
                    } catch (Exception $e) {
                        $this->unlockWebhook();
                        throw $e;
                    }
                    $this->unlockWebhook();

                    return array(200, array(
                        'success' => true,
                        'action' => 'sync',
                        'results' => $results,
                        'count' => count($results),
                        'timestamp' => date('Y-m-d H:i:s'),
                    ));

                case 'process':
                    $batchLimit = (int) Configuration::get('SYNCROSEVI_BATCH_LIMIT') ?: 10;
                    if (!$this->lockWebhook()) {
                        return array(200, $this->webhookBusy('process'));
                    }
                    $this->respondEarly('process');
                    try {
                        $results = $this->processOrders($batchLimit);
                    } catch (Exception $e) {
                        $this->unlockWebhook();
                        throw $e;
                    }
                    $this->unlockWebhook();

                    return array(200, array(
                        'success' => true,
                        'action' => 'process',
                        'results' => $results,
                        'count' => count($results),
                        'batch_limit' => $batchLimit,
                        'timestamp' => date('Y-m-d H:i:s'),
                    ));

                case 'realtime_sync':
                    $shopId = (int) Tools::getValue('shop_id');
                    $orderId = (int) Tools::getValue('order_id');
                    $newState = (int) Tools::getValue('new_state');
                    $timestamp = (int) Tools::getValue('timestamp');
                    $signature = (string) Tools::getValue('signature', '');

                    // Máximo 5 minutos de diferencia entre servidores
                    if (abs(time() - $timestamp) > 300) {
                        throw new Exception('Webhook timestamp too old (revisa la hora de los servidores)');
                    }
                    if (!$this->verifyWebhookSignature($shopId, $orderId, $newState, $timestamp, $signature)) {
                        return array(403, array('success' => false, 'error' => 'Invalid webhook signature'));
                    }

                    Db::getInstance()->update('syncrosevi_child_shops', array('last_webhook' => date('Y-m-d H:i:s')), 'id_child_shop = ' . (int) $shopId);

                    if (Tools::getValue('test_mode') || $orderId <= 0) {
                        return array(200, array(
                            'success' => true,
                            'action' => 'realtime_sync',
                            'test' => true,
                            'message' => 'Conexión correcta con la tienda madre',
                            'shop_id' => $shopId,
                            'timestamp' => date('Y-m-d H:i:s'),
                        ));
                    }

                    $result = $this->syncSingleOrderRealtime($shopId, $orderId, $newState);

                    return array(200, array(
                        'success' => true,
                        'action' => 'realtime_sync',
                        'shop_id' => $shopId,
                        'order_id' => $orderId,
                        'new_state' => $newState,
                        'synced' => $result,
                        'timestamp' => date('Y-m-d H:i:s'),
                    ));

                case 'test':
                    return array(200, array(
                        'success' => true,
                        'action' => 'test',
                        'message' => 'Webhook funcionando correctamente',
                        'prestashop_version' => _PS_VERSION_,
                        'module_version' => $this->version,
                        'timestamp' => date('Y-m-d H:i:s'),
                    ));

                default:
                    throw new Exception('Invalid action: ' . $action . '. Valid actions: sync, process, realtime_sync, test');
            }
        } catch (Exception $e) {
            if ($this->webhookResponded) {
                // el cron ya tiene su respuesta: el fallo solo puede quedar en el registro
                $this->log('✗ Webhook ' . $action . ' (en segundo plano): ' . $e->getMessage());
            }

            return array(500, array(
                'success' => false,
                'error' => $e->getMessage(),
                'action' => $action,
                'timestamp' => date('Y-m-d H:i:s'),
            ));
        }
    }

    /**
     * ¿El webhook ya ha contestado al cron? Entonces quien lo llama no debe enviar nada más.
     */
    public function webhookResponded()
    {
        return $this->webhookResponded;
    }

    /**
     * Contesta ya al cron («recibido») y deja que PHP siga con la sincronización o el procesamiento.
     * Así el servidor web (proxy de Plesk) no corta la espera con un 503 en las pasadas largas.
     * Con &wait=1 en la URL no se adelanta la respuesta y se devuelve el resultado completo, como antes.
     */
    private function respondEarly($action)
    {
        if (Tools::getValue('wait') || php_sapi_name() === 'cli' || headers_sent()) {
            return;
        }

        @ignore_user_abort(true);
        $body = json_encode(array(
            'success' => true,
            'action' => $action,
            'accepted' => true,
            'message' => 'Recibido: se está ejecutando en segundo plano. El resultado queda en el registro de SyncroSevi.',
            'timestamp' => date('Y-m-d H:i:s'),
        ));

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Robots-Tag: noindex, nofollow');
        header('Content-Length: ' . strlen($body));
        header('Connection: close');
        echo $body;
        @flush();

        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
        }
        $this->webhookResponded = true;
    }

    /**
     * Bloqueo de las pasadas del webhook (sync y process comparten uno): si ya hay una en marcha,
     * la nueva no hace nada. Se suelta solo si PHP termina o se cae.
     */
    private function lockWebhook()
    {
        $dir = dirname(__FILE__) . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
            self::protectDir($dir);
        }
        $handle = @fopen($dir . '/webhook.lock', 'c');
        if (!$handle) {
            // sin poder crear el archivo de bloqueo se sigue como antes
            return true;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }
        $this->webhookLock = $handle;

        return true;
    }

    private function unlockWebhook()
    {
        if ($this->webhookLock) {
            @flock($this->webhookLock, LOCK_UN);
            @fclose($this->webhookLock);
            $this->webhookLock = null;
        }
    }

    private function webhookBusy($action)
    {
        $this->log('Webhook ' . $action . ' saltado: ya hay una sincronización o un procesamiento en marcha');

        return array(
            'success' => true,
            'action' => $action,
            'busy' => true,
            'message' => 'Ya hay una sincronización o un procesamiento en marcha: esta llamada no hace nada.',
            'timestamp' => date('Y-m-d H:i:s'),
        );
    }

    /**
     * ======================================================================
     * CREACIÓN DEL PEDIDO MADRE
     * ======================================================================
     */

    /**
     * Buscar producto por referencia/código (EAN13, UPC, ISBN) con caché.
     * Nunca se busca por ID: los IDs de la tienda hija no tienen relación con los de la madre.
     */
    public function findProductByReference($reference)
    {
        $reference = trim((string) $reference);
        if ($reference === '') {
            return null;
        }
        if (array_key_exists($reference, $this->productCache)) {
            return $this->productCache[$reference];
        }

        $db = Db::getInstance();
        $ref = pSQL($reference);
        $result = null;

        // 1. Combinación por referencia
        $row = $db->getRow(
            'SELECT pa.id_product_attribute, pa.id_product FROM `' . _DB_PREFIX_ . 'product_attribute` pa
             JOIN `' . _DB_PREFIX_ . 'product` p ON pa.id_product = p.id_product
             WHERE pa.reference = "' . $ref . '"
             ORDER BY p.active DESC, pa.id_product_attribute ASC'
        );
        if ($row) {
            $result = array('id_product' => (int) $row['id_product'], 'id_product_attribute' => (int) $row['id_product_attribute'], 'type' => 'combination', 'found_by' => 'combination_reference');
        }

        // 2. Combinación por código (ean13, upc, isbn)
        if (!$result) {
            $row = $db->getRow(
                'SELECT pa.id_product_attribute, pa.id_product FROM `' . _DB_PREFIX_ . 'product_attribute` pa
                 JOIN `' . _DB_PREFIX_ . 'product` p ON pa.id_product = p.id_product
                 WHERE (pa.ean13 = "' . $ref . '" OR pa.upc = "' . $ref . '" OR pa.isbn = "' . $ref . '")
             ORDER BY p.active DESC, pa.id_product_attribute ASC'
            );
            if ($row) {
                $result = array('id_product' => (int) $row['id_product'], 'id_product_attribute' => (int) $row['id_product_attribute'], 'type' => 'combination', 'found_by' => 'combination_code');
            }
        }

        // 3. Producto simple por referencia
        if (!$result) {
            $id = (int) $db->getValue('SELECT id_product FROM `' . _DB_PREFIX_ . 'product` WHERE reference = "' . $ref . '" ORDER BY active DESC, id_product ASC');
            if ($id) {
                $result = array('id_product' => $id, 'id_product_attribute' => 0, 'type' => 'simple', 'found_by' => 'product_reference');
            }
        }

        // 4. Producto simple por código
        if (!$result) {
            $id = (int) $db->getValue(
                'SELECT id_product FROM `' . _DB_PREFIX_ . 'product`
                 WHERE (ean13 = "' . $ref . '" OR upc = "' . $ref . '" OR isbn = "' . $ref . '")
                 ORDER BY active DESC, id_product ASC'
            );
            if ($id) {
                $result = array('id_product' => $id, 'id_product_attribute' => 0, 'type' => 'simple', 'found_by' => 'product_code');
            }
        }

        if ($result) {
            $this->log('✓ "' . $reference . '" -> producto ' . $result['id_product'] . ' / combinación ' . $result['id_product_attribute'] . ' (' . $result['found_by'] . ')', true);
        } else {
            $this->log('✗ Producto NO encontrado en la tienda madre: "' . $reference . '"', true);
        }

        $this->productCache[$reference] = $result;

        return $result;
    }

    private function validateCustomer($customerId)
    {
        $customer = new Customer((int) $customerId);
        if (!Validate::isLoadedObject($customer)) {
            throw new Exception('Cliente ID ' . (int) $customerId . ' no encontrado');
        }

        return $customer;
    }

    private function validateAddress($addressId, $customerId)
    {
        $address = new Address((int) $addressId);
        if (!Validate::isLoadedObject($address) || $address->deleted) {
            throw new Exception('Dirección ID ' . (int) $addressId . ' no encontrada');
        }
        if ($address->id_customer != $customerId) {
            throw new Exception('La dirección no pertenece al cliente especificado');
        }

        return $address;
    }

    /**
     * Crear pedido madre consolidado
     *
     * @return array array('id_order' => int, 'products' => int)
     */
    private function createMotherOrder($products, $shop, $customer = null, $address = null, array $missing = array())
    {
        if (!$customer) {
            $customer = $this->validateCustomer($shop['id_customer']);
        }
        if (!$address) {
            $address = $this->validateAddress($shop['id_address'], $customer->id);
        }

        $cart = $this->createSimpleCart($customer, $address, $shop);
        $validProducts = $this->addProductsToCart($cart, $products, $shop);
        // Carrito recargado: algunas líneas pueden haberse metido directamente en la base de datos
        $cart = new Cart((int) $cart->id);
        Context::getContext()->cart = $cart;
        $this->configureCartShipping($cart, $shop);
        $orderId = $this->createOrderFromCart($cart, $shop, $missing);

        $this->log('✓ Pedido madre #' . $orderId . ' creado para ' . $shop['name'] . ' con ' . $validProducts . ' productos');

        return array('id_order' => $orderId, 'products' => $validProducts);
    }

    /**
     * Crear carrito
     */
    private function createSimpleCart($customer, $address, $shop)
    {
        $cart = new Cart();
        $cart->id_customer = $customer->id;
        // El cliente de la tienda hija usa el grupo de precios configurado
        if ((int) $customer->id_default_group !== (int) $shop['id_group']) {
            $customer->id_default_group = (int) $shop['id_group'];
            $customer->update();
        }
        $cart->id_address_delivery = $address->id;
        $cart->id_address_invoice = $address->id;
        $cart->id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
        $cart->id_currency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
        $cart->id_shop = (int) Configuration::get('PS_SHOP_DEFAULT');
        $cart->id_shop_group = (int) Shop::getGroupFromShop($cart->id_shop);
        $cart->secure_key = $customer->secure_key;

        if (!$cart->add()) {
            throw new Exception('Error al crear carrito');
        }

        $this->log('Carrito creado con ID: ' . $cart->id, true);

        return $cart;
    }

    /**
     * Añadir al carrito los productos ya encontrados en la madre (todos entran, sin tocar el stock)
     *
     * @param array $products array('id_product', 'id_product_attribute', 'quantity', 'reference')
     */
    private function addProductsToCart($cart, $products, $shop)
    {
        $addedCount = 0;

        $customer = new Customer($cart->id_customer);
        $context = Context::getContext();
        $context->customer = $customer;
        $context->cart = $cart;

        $shopObject = new Shop($cart->id_shop);

        // Más memoria para pedidos grandes, pero sin bajar nunca el límite actual
        $currentMemoryLimit = ini_get('memory_limit');
        if ($currentMemoryLimit != -1 && Tools::getOctets($currentMemoryLimit) < 805306368) {
            @ini_set('memory_limit', '768M');
        }

        foreach ($products as $product) {
            if (!$this->addToCartAnyway($cart, $product['id_product'], $product['id_product_attribute'], (int) $product['quantity'], $shopObject, $product['reference'])) {
                throw new Exception('No se pudo añadir "' . $product['reference'] . '" (producto ' . (int) $product['id_product'] . ', combinación ' . (int) $product['id_product_attribute'] . ') al carrito: falló la escritura en la base de datos');
            }
            ++$addedCount;

            if ($addedCount % 10 == 0) {
                gc_collect_cycles();
            }
        }

        if ($currentMemoryLimit !== false) {
            @ini_set('memory_limit', $currentMemoryLimit);
        }

        $this->log('RESUMEN carrito ' . $cart->id . ': ' . $addedCount . ' productos añadidos', true);

        return $addedCount;
    }

    /**
     * Configurar transportista del carrito
     */
    private function configureCartShipping($cart, $shop)
    {
        $carrierId = null;

        if (!empty($shop['id_carrier'])) {
            $carrier = new Carrier((int) $shop['id_carrier']);
            if (Validate::isLoadedObject($carrier) && $carrier->active && !$carrier->deleted) {
                $carrierId = (int) $shop['id_carrier'];
            } else {
                $this->log('Transportista configurado no válido para ' . $shop['name'] . ', se usa el gratuito');
            }
        }

        if (!$carrierId) {
            $carrierId = (int) Configuration::get('SYNCROSEVI_FREE_CARRIER_ID');
            if (!$carrierId) {
                throw new Exception('Transportista gratuito de SyncroSevi no encontrado');
            }
        }

        $cart->id_carrier = $carrierId;
        $cart->setDeliveryOption(array($cart->id_address_delivery => $carrierId . ','));
        $cart->update();

        $this->log('Transportista ' . $carrierId . ' asignado al carrito ' . $cart->id, true);
    }

    /**
     * Asegurar que el producto se puede pedir: si no admite pedidos sin stock y falta stock,
     * se suma exactamente lo que falta (antes se sumaban 100 unidades de más que quedaban como stock ficticio).
     */
    /**
     * Crear pedido desde carrito usando el módulo de pago interno
     */
    private function createOrderFromCart($cart, $shop, array $missing = array())
    {
        $cartTotal = $cart->getOrderTotal(true);

        if ($this->isDebug()) {
            $this->log('Carrito ' . $cart->id . ': productos=' . $cart->getOrderTotal(false, Cart::ONLY_PRODUCTS) . ', envío=' . $cart->getOrderTotal(false, Cart::ONLY_SHIPPING) . ', total=' . $cartTotal, true);
        }

        if ($cartTotal <= 0) {
            throw new Exception('El carrito tiene un total de 0, no se puede crear el pedido', self::ERR_NO_PRODUCTS);
        }
        if (!$cart->id || $cart->orderExists()) {
            throw new Exception('El carrito no es válido o ya tiene un pedido asociado');
        }

        require_once dirname(__FILE__) . '/classes/SyncroSeviPayment.php';
        $paymentModule = new SyncroSeviPayment();
        $paymentModule->context = Context::getContext();
        $paymentModule->context->cart = $cart;
        $paymentModule->context->customer = new Customer($cart->id_customer);

        $cart->update();

        $result = $paymentModule->validateOrder(
            $cart->id,
            (int) $shop['id_order_state'],
            $cartTotal,
            'SyncroSevi - ' . $shop['name'],
            $this->buildOrderMessage('Pedido consolidado de ' . $shop['name'] . ' - Fecha: ' . self::spanishNow(), $missing),
            array(),
            (int) $cart->id_currency,
            false,
            $cart->secure_key
        );

        if ($result && $paymentModule->currentOrder) {
            $this->keepOrderState((int) $paymentModule->currentOrder, (int) $shop['id_order_state']);

            return (int) $paymentModule->currentOrder;
        }

        throw new Exception('Error al crear pedido desde carrito - ValidateOrder falló' . ($paymentModule->lastError ? ': ' . $paymentModule->lastError : ''));
    }

    /**
     * ======================================================================
     * MODO CLONAR
     * ======================================================================
     */

    public static function isCloneShop($shop)
    {
        return isset($shop['sync_mode']) && $shop['sync_mode'] === 'clone';
    }

    /** Cambios de estado de la hija a la madre (1 = hija → madre, 2 = madre → hija, 3 = las dos) */
    public static function syncIn($shop)
    {
        return isset($shop['clone_state_sync']) && in_array((int) $shop['clone_state_sync'], array(1, 3));
    }

    public static function syncOut($shop)
    {
        return isset($shop['clone_state_sync']) && in_array((int) $shop['clone_state_sync'], array(2, 3));
    }

    /**
     * Madre → hija: al cambiar el estado de un pedido clonado en la madre, se cambia el de la hija según el mapeo
     */
    public function hookActionOrderHistoryAddAfter($params)
    {
        if (!$this->getUpdater()->ready()) {
            return;
        }
        if (self::$noPush > 0 || !(int) Configuration::getGlobalValue('SYNCROSEVI_CLONE_ACTIVE')) {
            return;
        }
        if (!isset($params['order_history']) || !($params['order_history'] instanceof OrderHistory)) {
            return;
        }
        $history = $params['order_history'];
        try {
            $row = Db::getInstance()->getRow(
                'SELECT t.id_original_order, t.child_state, cs.*
                 FROM `' . _DB_PREFIX_ . 'syncrosevi_order_tracking` t
                 JOIN `' . _DB_PREFIX_ . 'syncrosevi_child_shops` cs ON cs.id_child_shop = t.id_child_shop
                 WHERE t.id_mother_order = ' . (int) $history->id_order . ' AND t.is_clone = 1 AND t.processed = 1
                 AND cs.sync_mode = "clone" AND cs.active = 1'
            );
            if (!$row || !self::syncOut($row)) {
                return;
            }
            $this->loadClone();
            $clone = new SyncroSeviClone($this, $row, null);
            $target = $clone->mappedStateOut((int) $history->id_order_state);
            if (!$target || $target === (int) $row['child_state']) {
                return;
            }
            $webservice = new SyncroSeviWebservice($row['url'], $row['api_key'], $this->isDebug());
            $webservice->timeout = 15;
            $webservice->addOrderHistory((int) $row['id_original_order'], $target, !empty($row['clone_state_email']));
            Db::getInstance()->update(
                'syncrosevi_order_tracking',
                array('child_state' => (int) $target),
                'id_child_shop = ' . (int) $row['id_child_shop'] . ' AND id_original_order = ' . (int) $row['id_original_order']
            );
            $this->log('→ Pedido #' . (int) $row['id_original_order'] . ' de ' . $row['name'] . ' cambiado a estado ' . $target . ' (desde el pedido #' . (int) $history->id_order . ' de la madre)');
        } catch (Exception $e) {
            $this->log('✗ No se pudo cambiar el estado en la tienda hija del pedido #' . (int) $history->id_order . ': ' . $e->getMessage() . ' (¿la clave tiene permiso POST en «order_histories»?)');
        }
    }

    private function loadClone()
    {
        $this->loadWebservice();
        if (!class_exists('SyncroSeviClone')) {
            require_once dirname(__FILE__) . '/classes/SyncroSeviClone.php';
        }
    }

    /** Recalcula si hay alguna tienda en modo clonar (el bloqueo de correos solo trabaja si la hay) */
    public static function refreshCloneFlag()
    {
        $n = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'syncrosevi_child_shops` WHERE sync_mode = "clone"');
        Configuration::updateGlobalValue('SYNCROSEVI_CLONE_ACTIVE', $n ? 1 : 0);
    }

    /** Abre o cierra la ventana en la que no sale ningún correo a clientes */
    public static function mailWindow($open)
    {
        if ($open) {
            if (++self::$mailWindow === 1 && version_compare(_PS_VERSION_, '1.7.7.0', '<')) {
                // Versiones antiguas: además se desactiva el envío solo en este proceso (no se guarda en la base de datos)
                self::$mailMethodBackup = Configuration::get('PS_MAIL_METHOD');
                Configuration::set('PS_MAIL_METHOD', 3);
            }
        } elseif (self::$mailWindow > 0 && --self::$mailWindow === 0 && self::$mailMethodBackup !== null) {
            Configuration::set('PS_MAIL_METHOD', self::$mailMethodBackup);
            self::$mailMethodBackup = null;
        }
    }

    /**
     * Procesa los pedidos pendientes de una tienda en modo clonar: un pedido de la madre por cada pedido de la hija.
     * Los retenidos (falta un producto, error...) siguen pendientes con el motivo guardado y van al final de la cola.
     */
    private function processCloneOrders($shop, $limit)
    {
        $db = Db::getInstance();
        $idShop = (int) $shop['id_child_shop'];
        $results = array();

        $ids = (array) $db->executeS(
            'SELECT DISTINCT ol.id_original_order,
                    (t.clone_error IS NOT NULL AND t.clone_error <> "") AS held
             FROM `' . _DB_PREFIX_ . 'syncrosevi_order_lines` ol
             LEFT JOIN `' . _DB_PREFIX_ . 'syncrosevi_order_tracking` t
                ON (t.id_child_shop = ol.id_child_shop AND t.id_original_order = ol.id_original_order)
             WHERE ol.id_child_shop = ' . $idShop . ' AND ol.processed = 0
             ORDER BY held ASC, ol.id_original_order ASC
             LIMIT ' . (int) $limit
        );
        if (!$ids) {
            return $results;
        }

        $this->loadClone();
        $webservice = new SyncroSeviWebservice($shop['url'], $shop['api_key'], $this->isDebug());
        $clone = new SyncroSeviClone($this, $shop, $webservice);

        $context = Context::getContext();
        $backup = array($context->cart, $context->customer, $context->currency);
        foreach ($ids as $row) {
            $childId = (int) $row['id_original_order'];
            $db->execute('START TRANSACTION');
            self::mailWindow(true);
            ++self::$noPush;
            try {
                $created = $clone->cloneOrder($childId);
                $this->markCloneProcessed($idShop, $childId, $created['id_order'], $created['child_state'], isset($created['missing']) ? $created['missing'] : array());
                $db->execute('COMMIT');
                $this->log('✓ Pedido #' . $childId . ' de ' . $shop['name'] . ' clonado como pedido #' . $created['id_order']);
                foreach ((array) (isset($created['missing']) ? $created['missing'] : array()) as $m) {
                    $this->log('✗ Fuera: pedido #' . $childId . ' de ' . $shop['name'] . ' · '
                        . (trim($m['product_reference']) !== '' ? 'referencia "' . $m['product_reference'] . '" (no existe en la madre, pasó con el producto de sustitución)' : 'línea sin referencia')
                        . ' · «' . $m['product_name'] . '» · ' . (int) $m['product_quantity'] . ' uds');
                }
                $results[] = array(
                    'shop' => $shop['name'],
                    'order_id' => $created['id_order'],
                    'products_count' => $created['products'],
                    'orders_count' => 1,
                    'missing_count' => count(Syncrosevi::groupMissing(array_map(function ($m) use ($childId) {
                        return array('product_reference' => $m['product_reference'], 'product_name' => $m['product_name'], 'quantity' => $m['product_quantity'], 'id_original_order' => $childId);
                    }, isset($created['missing']) ? $created['missing'] : array()))),
                    'status' => 'success',
                );
            } catch (Exception $e) {
                $db->execute('ROLLBACK');
                $results[] = $this->cloneFailed($shop, $childId, $e->getMessage(), $e->getCode() == SyncroSeviClone::ERR_HOLD);
            } catch (Throwable $e) {
                $db->execute('ROLLBACK');
                $results[] = $this->cloneFailed($shop, $childId, $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', false);
            }
            self::mailWindow(false);
            --self::$noPush;
            list($context->cart, $context->customer, $context->currency) = $backup;
        }

        return $results;
    }

    private function cloneFailed($shop, $childId, $message, $held)
    {
        Db::getInstance()->update(
            'syncrosevi_order_tracking',
            array('clone_error' => pSQL(Tools::substr(($held ? 'Retenido: ' : 'Error: ') . $message, 0, 2000))),
            'id_child_shop = ' . (int) $shop['id_child_shop'] . ' AND id_original_order = ' . (int) $childId
        );
        $this->log(($held ? '⏸ Pedido #' : '✗ Pedido #') . $childId . ' de ' . $shop['name'] . ' sin clonar (sigue pendiente): ' . $message);

        return array(
            'shop' => $shop['name'],
            'status' => 'error',
            'message' => 'Pedido #' . $childId . ($held ? ' retenido: ' : ': ') . $message . ' (sigue pendiente)',
        );
    }

    private function markCloneProcessed($idShop, $childId, $motherId, $childState, array $missing = array())
    {
        $db = Db::getInstance();
        // Estado de cada línea: importada, o fuera (pasó con el producto de sustitución) si su referencia no existe en la madre
        $missingRefs = array();
        foreach ($missing as $m) {
            $missingRefs[Tools::strtolower(trim((string) $m['product_reference']))] = true;
        }
        $lineStatus = array();
        $lines = (array) $db->executeS(
            'SELECT id_line, product_reference FROM `' . _DB_PREFIX_ . 'syncrosevi_order_lines`
             WHERE id_child_shop = ' . (int) $idShop . ' AND id_original_order = ' . (int) $childId . ' AND processed = 0'
        );
        foreach ($lines as $line) {
            $ref = Tools::strtolower(trim((string) $line['product_reference']));
            if (isset($missingRefs[$ref])) {
                $lineStatus[(int) $line['id_line']] = $ref === '' ? self::LINE_NO_REFERENCE : self::LINE_NOT_FOUND;
            } else {
                $lineStatus[(int) $line['id_line']] = self::LINE_IMPORTED;
            }
        }
        $this->saveLineStatus($lineStatus);
        $db->execute(
            'UPDATE `' . _DB_PREFIX_ . 'syncrosevi_order_lines` SET processed = 1
             WHERE id_child_shop = ' . (int) $idShop . ' AND id_original_order = ' . (int) $childId . ' AND processed = 0'
        );
        $db->execute(
            'UPDATE `' . _DB_PREFIX_ . 'syncrosevi_order_tracking`
             SET id_mother_order = ' . (int) $motherId . ', processed = 1, is_clone = 1,
                 child_state = ' . (int) $childState . ', clone_error = NULL,
                 date_processed = "' . date('Y-m-d H:i:s') . '"
             WHERE id_child_shop = ' . (int) $idShop . ' AND id_original_order = ' . (int) $childId
        );
    }

    /** Pide a la hija el estado actual de los pedidos clonados (últimos 120 días) y aplica los cambios */
    private function syncCloneStates($shop, $webservice)
    {
        $rows = (array) Db::getInstance()->executeS(
            'SELECT id_original_order, id_mother_order, child_state FROM `' . _DB_PREFIX_ . 'syncrosevi_order_tracking`
             WHERE id_child_shop = ' . (int) $shop['id_child_shop'] . ' AND processed = 1 AND is_clone = 1
             AND id_mother_order > 0 AND date_processed > "' . date('Y-m-d H:i:s', time() - 120 * 86400) . '"
             ORDER BY id_original_order DESC LIMIT 2000'
        );
        if (!$rows) {
            return;
        }
        $states = $webservice->getOrderStates(array_column($rows, 'id_original_order'));
        foreach ($rows as $row) {
            $id = (int) $row['id_original_order'];
            if (isset($states[$id]) && $states[$id]['state'] && $states[$id]['state'] !== (int) $row['child_state']) {
                $this->applyCloneState($shop, $id, (int) $row['id_mother_order'], $states[$id]['state'], $states[$id]['tracking']);
            }
        }
    }

    /** Aplica un estado de la hija a su pedido clonado (sin correos) y lo apunta */
    private function applyCloneState($shop, $childId, $motherId, $childState, $tracking = '')
    {
        $this->loadClone();
        Db::getInstance()->update(
            'syncrosevi_order_tracking',
            array('child_state' => (int) $childState),
            'id_child_shop = ' . (int) $shop['id_child_shop'] . ' AND id_original_order = ' . (int) $childId
        );
        if (!self::syncIn($shop)) {
            return false;
        }
        self::mailWindow(true);
        ++self::$noPush;
        try {
            $clone = new SyncroSeviClone($this, $shop, null);
            $changed = $clone->applyState($motherId, $childState, (string) $tracking);
        } catch (Exception $e) {
            $this->log('✗ No se pudo cambiar el estado del pedido clonado #' . (int) $motherId . ': ' . $e->getMessage());
            $changed = false;
        }
        self::mailWindow(false);
        --self::$noPush;

        return $changed;
    }

    /**
     * ======================================================================
     * CORREOS: ninguno al cliente por los pedidos clonados
     * ======================================================================
     */
    public function hookActionEmailSendBefore($params)
    {
        try {
            if (!self::$mailWindow && !(int) Configuration::getGlobalValue('SYNCROSEVI_CLONE_ACTIVE')) {
                return true;
            }
            $to = isset($params['to']) ? $params['to'] : '';
            $recipients = array();
            foreach ((array) $to as $k => $v) {
                $email = Validate::isEmail($k) ? $k : $v;
                if (is_string($email) && trim($email) !== '') {
                    $recipients[] = Tools::strtolower(trim($email));
                }
            }
            $customers = array_values(array_diff($recipients, $this->staffEmails()));
            if (!$customers) {
                return true; // solo personal de la tienda (avisos de pedido nuevo, etc.)
            }

            $reason = '';
            $vars = isset($params['templateVars']) && is_array($params['templateVars']) ? $params['templateVars'] : array();
            if (self::$mailWindow) {
                $reason = 'se está creando o actualizando un pedido clonado';
            } elseif ($id = $this->cloneOrderInVars($vars)) {
                $reason = 'es del pedido clonado #' . $id;
            } elseif ($this->isCloneOnlyCustomer($customers)) {
                $reason = 'cliente creado por el clonado';
            }
            if ($reason === '') {
                return true;
            }
            $this->log('✉ Correo bloqueado: plantilla «' . (isset($params['template']) ? $params['template'] : '') . '» a ' . implode(', ', $customers) . ' (' . $reason . ')');

            return false;
        } catch (Exception $e) {
            return true;
        }
    }

    /** Emails del personal: empleados activos, email de la tienda y avisos de pedidos (mailalerts) */
    private function staffEmails()
    {
        if (self::$staffEmails === null) {
            $list = array(Tools::strtolower((string) Configuration::get('PS_SHOP_EMAIL')));
            foreach ((array) Db::getInstance()->executeS('SELECT email FROM `' . _DB_PREFIX_ . 'employee` WHERE active = 1') as $e) {
                $list[] = Tools::strtolower(trim($e['email']));
            }
            foreach (preg_split('/[\s,;]+/', (string) Configuration::get('MA_MERCHANT_MAILS')) as $e) {
                if (Validate::isEmail($e)) {
                    $list[] = Tools::strtolower(trim($e));
                }
            }
            self::$staffEmails = array_values(array_unique(array_filter($list)));
        }

        return self::$staffEmails;
    }

    /** ¿El correo es de un pedido clonado? (por número o referencia del pedido) */
    private function cloneOrderInVars(array $vars)
    {
        $ids = array();
        $refs = array();
        foreach (array('{id_order}', '{order_id}', '{order_name}', '{order_reference}', '{reference}', '{order_ref}') as $key) {
            if (!isset($vars[$key]) || !is_scalar($vars[$key])) {
                continue;
            }
            $value = trim(strip_tags((string) $vars[$key]));
            if ($value === '') {
                continue;
            }
            if (ctype_digit($value)) {
                $ids[] = (int) $value;
            }
            $refs[] = '"' . pSQL($value) . '"';
        }
        if (!$ids && !$refs) {
            return 0;
        }
        $where = array();
        if ($ids) {
            $where[] = 'o.id_order IN (' . implode(',', array_unique($ids)) . ')';
        }
        if ($refs) {
            $where[] = 'o.reference IN (' . implode(',', array_unique($refs)) . ')';
        }

        return (int) Db::getInstance()->getValue(
            'SELECT o.id_order FROM `' . _DB_PREFIX_ . 'syncrosevi_order_tracking` t
             JOIN `' . _DB_PREFIX_ . 'orders` o ON o.id_order = t.id_mother_order
             WHERE t.is_clone = 1 AND (' . implode(' OR ', $where) . ')'
        );
    }

    /** ¿Todos los destinatarios son invitados creados por el clonado que nunca han comprado directamente en la madre? */
    private function isCloneOnlyCustomer(array $emails)
    {
        $db = Db::getInstance();
        foreach ($emails as $email) {
            $rows = (array) $db->executeS(
                'SELECT c.id_customer, c.is_guest, cc.id_customer AS cloned
                 FROM `' . _DB_PREFIX_ . 'customer` c
                 LEFT JOIN `' . _DB_PREFIX_ . 'syncrosevi_clone_customer` cc ON cc.id_customer = c.id_customer
                 WHERE c.email = "' . pSQL($email) . '" AND c.deleted = 0'
            );
            if (!$rows) {
                return false;
            }
            foreach ($rows as $r) {
                if (!$r['cloned'] || !(int) $r['is_guest']) {
                    return false; // también es cliente propio de la madre
                }
                $own = (int) $db->getValue(
                    'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'orders` o
                     WHERE o.id_customer = ' . (int) $r['id_customer'] . '
                     AND o.id_order NOT IN (SELECT id_mother_order FROM `' . _DB_PREFIX_ . 'syncrosevi_order_tracking` WHERE is_clone = 1)'
                );
                if ($own) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * ======================================================================
     * CONFIGURACIÓN
     * ======================================================================
     */
    public function getContent()
    {
        $updater = $this->getUpdater();
        $output = $updater->processRequest(); // incluye el cartel de licencia si hace falta
        $info = $updater->getInfo();
        $panel = $updater->renderPanel();

        if ($this->isFirstTime()) {
            return !empty($info['update_available'])
                ? $output . $panel . $this->displayInitialSetup()
                : $output . $this->displayInitialSetup() . $panel;
        }

        // Con un mensaje de actualizaciones que enseñar, se queda aquí; si no, a la pantalla de SyncroSevi como siempre
        if ($output !== '') {
            return $output . $panel
                . '<p><a class="btn btn-primary" href="' . Tools::safeOutput($this->context->link->getAdminLink('AdminSyncrosevi')) . '"><i class="icon-arrow-right"></i> ' . $this->l('Ir a SyncroSevi') . '</a></p>';
        }

        Tools::redirectAdmin($this->context->link->getAdminLink('AdminSyncrosevi'));
    }

    private function isFirstTime()
    {
        return !(int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'syncrosevi_child_shops`');
    }

    private function displayInitialSetup()
    {
        $this->context->controller->addCSS($this->_path . 'views/css/admin.css');
        $this->context->controller->addCSS($this->_path . 'views/css/modal.css');

        $cron = _PS_MODULE_DIR_ . $this->name . '/cron/syncrosevi_cron.php';
        $this->context->smarty->assign(array(
            'admin_url' => $this->context->link->getAdminLink('AdminSyncrosevi'),
            'ps_version' => _PS_VERSION_,
            'php_version' => phpversion(),
            'module_version' => $this->version,
            'webhook_test_url' => $this->getWebhookUrl('test'),
            'cron_sync_path' => $cron . ' sync',
            'cron_process_path' => $cron . ' process',
            'cron_stats_path' => $cron . ' stats',
            'cron_health_path' => $cron . ' health',
        ));

        return $this->display(__FILE__, 'views/templates/admin/configure.tpl');
    }
}
