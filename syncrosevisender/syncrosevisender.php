<?php
/**
 * 2024 SyncroSevi Sender
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
 * Módulo para las TIENDAS HIJAS: cuando un pedido pasa a uno de los estados configurados,
 * avisa a la tienda madre (SyncroSevi) para que lo importe al momento.
 *
 * - Descargado "preconfigurado" desde la tienda madre: trae preset.php y se configura solo al instalar.
 * - Descargado "limpio": se configura en Módulos > Configurar (pegando el código de configuración o a mano).
 */
class SyncroSeviSender extends Module
{
    const MAX_QUEUE = 200;
    const LOG_MAX_SIZE = 2097152;

    private static $configKeys = array(
        'SYNCROSEVISENDER_ACTIVE',
        'SYNCROSEVISENDER_MOTHER_URL',
        'SYNCROSEVISENDER_SHOP_ID',
        'SYNCROSEVISENDER_SECRET_KEY',
        'SYNCROSEVISENDER_SYNC_STATES',
        'SYNCROSEVISENDER_TIMEOUT',
        'SYNCROSEVISENDER_MAX_RETRIES',
        'SYNCROSEVISENDER_QUEUE',
        'SYNCROSEVISENDER_LAST_OK',
        'SYNCROSEVISENDER_LAST_ERROR',
    );

    public function __construct()
    {
        $this->name = 'syncrosevisender';
        $this->tab = 'administration';
        $this->version = '2.0.0';
        $this->author = 'SyncroSevi Team';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array(
            'min' => '1.7.0.0',
            'max' => _PS_VERSION_,
        );
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('SyncroSevi Sender - Webhooks Tiempo Real');
        $this->description = $this->l('Avisa a la tienda madre (SyncroSevi) cuando un pedido cambia a un estado a sincronizar');
        $this->confirmUninstall = $this->l('¿Estás seguro de que quieres desinstalar este módulo?');
    }

    public function install()
    {
        if (!parent::install() || !$this->registerHook('actionOrderHistoryAddAfter')) {
            return false;
        }

        $defaults = array(
            'SYNCROSEVISENDER_ACTIVE' => 0,
            'SYNCROSEVISENDER_MOTHER_URL' => '',
            'SYNCROSEVISENDER_SHOP_ID' => '',
            'SYNCROSEVISENDER_SECRET_KEY' => '',
            'SYNCROSEVISENDER_SYNC_STATES' => '2,3,4,5',
            'SYNCROSEVISENDER_TIMEOUT' => 5,
            'SYNCROSEVISENDER_MAX_RETRIES' => 2,
        );
        foreach ($defaults as $key => $value) {
            // Si ya había configuración (reinstalación), se conserva
            if (Configuration::get($key) === false) {
                Configuration::updateValue($key, $value);
            }
        }

        // Módulo preconfigurado desde la tienda madre
        $preset = $this->getPreset();
        if ($preset) {
            foreach ($preset as $key => $value) {
                if (in_array($key, self::$configKeys)) {
                    Configuration::updateValue($key, $value);
                }
            }
            $test = $this->testConnection();
            $this->log($test['success'] ? '✓ INSTALACIÓN: configuración aplicada y conexión con la tienda madre correcta' : '⚠ INSTALACIÓN: configuración aplicada pero la prueba falló: ' . $test['error']);
        }

        $this->protectLogDir();

        return true;
    }

    public function uninstall()
    {
        foreach (self::$configKeys as $key) {
            Configuration::deleteByName($key);
        }

        return parent::uninstall();
    }

    /** Configuración incluida en el ZIP preconfigurado */
    private function getPreset()
    {
        $file = dirname(__FILE__) . '/preset.php';
        if (!file_exists($file)) {
            return array();
        }
        $preset = include $file;

        return is_array($preset) ? $preset : array();
    }

    /**
     * ======================================================================
     * HOOK
     * ======================================================================
     */
    public function hookActionOrderHistoryAddAfter($params)
    {
        if (!Configuration::get('SYNCROSEVISENDER_ACTIVE')) {
            return;
        }
        if (!isset($params['order_history']) || !($params['order_history'] instanceof OrderHistory)) {
            return;
        }

        try {
            $orderId = (int) $params['order_history']->id_order;
            $newState = (int) $params['order_history']->id_order_state;

            if (!in_array($newState, $this->getSyncStates())) {
                return;
            }
            if (!$this->isConfigured()) {
                $this->log('ERROR: configuración incompleta, no se envía el pedido #' . $orderId);

                return;
            }

            $result = $this->sendWebhook($orderId, $newState);
            if ($result['success']) {
                // La tienda madre responde: se reenvían los avisos que fallaron antes
                $this->processQueue(5);
            } else {
                $this->enqueue($orderId, $newState);
            }
        } catch (Exception $e) {
            $this->log('ERROR: ' . $e->getMessage());
        }
    }

    private function getSyncStates()
    {
        return array_values(array_filter(array_map('intval', explode(',', (string) Configuration::get('SYNCROSEVISENDER_SYNC_STATES')))));
    }

    private function isConfigured()
    {
        return Configuration::get('SYNCROSEVISENDER_MOTHER_URL')
            && (int) Configuration::get('SYNCROSEVISENDER_SHOP_ID') > 0
            && Configuration::get('SYNCROSEVISENDER_SECRET_KEY');
    }

    /**
     * Enviar aviso a la tienda madre (con reintentos cortos para no frenar el cambio de estado)
     */
    private function sendWebhook($orderId, $newState)
    {
        $maxRetries = max(1, min(5, (int) Configuration::get('SYNCROSEVISENDER_MAX_RETRIES') ?: 2));
        $timeout = max(2, min(60, (int) Configuration::get('SYNCROSEVISENDER_TIMEOUT') ?: 5));
        $result = array('success' => false, 'error' => '');

        for ($attempt = 1; $attempt <= $maxRetries; ++$attempt) {
            $result = $this->post($this->buildPayload($orderId, $newState), $timeout);
            if ($result['success']) {
                $this->log('✓ Pedido #' . $orderId . ' (estado ' . $newState . ') enviado a la tienda madre');
                Configuration::updateValue('SYNCROSEVISENDER_LAST_OK', date('Y-m-d H:i:s'));

                return $result;
            }
            // Errores definitivos (clave o token incorrectos): no tiene sentido reintentar
            if (isset($result['http']) && $result['http'] == 403) {
                break;
            }
            if ($attempt < $maxRetries) {
                sleep(1);
            }
        }

        $this->log('✗ Pedido #' . $orderId . ': no se pudo avisar a la tienda madre: ' . $result['error']);
        Configuration::updateValue('SYNCROSEVISENDER_LAST_ERROR', date('Y-m-d H:i:s') . ' - ' . Tools::substr($result['error'], 0, 200));

        return $result;
    }

    private function buildPayload($orderId, $newState, $test = false)
    {
        $shopId = (int) Configuration::get('SYNCROSEVISENDER_SHOP_ID');
        $timestamp = time();
        $signature = hash_hmac('sha256', $shopId . $orderId . $newState . $timestamp, (string) Configuration::get('SYNCROSEVISENDER_SECRET_KEY'));

        $securityToken = '';
        $query = parse_url((string) Configuration::get('SYNCROSEVISENDER_MOTHER_URL'), PHP_URL_QUERY);
        if ($query) {
            parse_str($query, $queryParams);
            $securityToken = isset($queryParams['security_token']) ? $queryParams['security_token'] : '';
        }

        $data = array(
            'action' => 'realtime_sync',
            'shop_id' => $shopId,
            'order_id' => (int) $orderId,
            'new_state' => (int) $newState,
            'timestamp' => $timestamp,
            'signature' => $signature,
            'security_token' => $securityToken,
        );
        if ($test) {
            $data['test_mode'] = 1;
        }

        return $data;
    }

    /**
     * Petición POST a la tienda madre
     */
    private function post($data, $timeout)
    {
        $url = (string) Configuration::get('SYNCROSEVISENDER_MOTHER_URL');
        if (!function_exists('curl_init')) {
            return array('success' => false, 'error' => 'cURL no está disponible en el servidor');
        }

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_POSTREDIR => 3, // mantener POST si hay redirección 301/302
            CURLOPT_USERAGENT => 'SyncroSeviSender/' . $this->version,
            CURLOPT_HTTPHEADER => array('Accept: application/json'),
        ));
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return array('success' => false, 'error' => 'cURL: ' . $error, 'http' => 0);
        }

        $json = json_decode($response, true);
        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = is_array($json) && !empty($json['error']) ? $json['error'] : Tools::substr(strip_tags($response), 0, 200);

            return array('success' => false, 'error' => 'HTTP ' . $httpCode . ': ' . $msg, 'http' => $httpCode);
        }
        if (is_array($json)) {
            if (!empty($json['success'])) {
                return array('success' => true, 'data' => $json, 'http' => $httpCode);
            }

            return array('success' => false, 'error' => isset($json['error']) ? $json['error'] : 'Error desconocido', 'http' => $httpCode);
        }

        return array('success' => false, 'error' => 'La tienda madre no devolvió JSON. ¿Es correcta la URL? Respuesta: ' . Tools::substr(strip_tags($response), 0, 150), 'http' => $httpCode);
    }

    /**
     * Prueba de conexión (pedido 0, no importa nada; la madre registra la llamada como "último webhook")
     */
    public function testConnection()
    {
        if (!$this->isConfigured()) {
            return array('success' => false, 'error' => 'Faltan datos: URL, ID de tienda o clave secreta');
        }
        $timeout = max(5, (int) Configuration::get('SYNCROSEVISENDER_TIMEOUT'));

        return $this->post($this->buildPayload(0, 0, true), $timeout);
    }

    /**
     * Cola de avisos que fallaron (se reintentan cuando la madre vuelve a responder)
     */
    private function getQueue()
    {
        $queue = json_decode((string) Configuration::get('SYNCROSEVISENDER_QUEUE'), true);

        return is_array($queue) ? $queue : array();
    }

    private function enqueue($orderId, $newState)
    {
        $queue = $this->getQueue();
        $queue[(string) $orderId] = (int) $newState;
        if (count($queue) > self::MAX_QUEUE) {
            $queue = array_slice($queue, -self::MAX_QUEUE, null, true);
        }
        Configuration::updateValue('SYNCROSEVISENDER_QUEUE', json_encode($queue));
    }

    /**
     * @return array array(enviados, fallidos)
     */
    private function processQueue($max)
    {
        $queue = $this->getQueue();
        if (empty($queue)) {
            return array(0, 0);
        }
        $timeout = max(2, min(60, (int) Configuration::get('SYNCROSEVISENDER_TIMEOUT') ?: 5));
        $sent = 0;
        $failed = 0;
        foreach (array_slice($queue, 0, $max, true) as $orderId => $state) {
            $result = $this->post($this->buildPayload((int) $orderId, (int) $state), $timeout);
            if ($result['success']) {
                unset($queue[$orderId]);
                ++$sent;
                $this->log('✓ Reenviado pedido #' . $orderId . ' que estaba en cola');
            } else {
                ++$failed;
                break; // la madre vuelve a fallar: se deja para más tarde
            }
        }
        Configuration::updateValue('SYNCROSEVISENDER_QUEUE', json_encode($queue));

        return array($sent, $failed);
    }

    /**
     * ======================================================================
     * LOG
     * ======================================================================
     */
    private function protectLogDir()
    {
        $dir = dirname(__FILE__) . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!file_exists($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order Deny,Allow\n    Deny from all\n</IfModule>\n");
        }
        if (!file_exists($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php\nheader('Location: ../');\nexit;\n");
        }
    }

    private function log($message)
    {
        $dir = dirname(__FILE__) . '/logs';
        if (!is_dir($dir)) {
            $this->protectLogDir();
        }
        $file = $dir . '/syncrosevisender.log';
        if (@filesize($file) > self::LOG_MAX_SIZE) {
            @rename($file, $file . '.1');
        }
        @file_put_contents($file, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * ======================================================================
     * CONFIGURACIÓN
     * ======================================================================
     */
    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitSyncroSeviSender') || Tools::isSubmit('submitSyncroSeviSenderTest')) {
            $output .= $this->processConfiguration();
        } elseif (Tools::isSubmit('submitSyncroSeviSenderQueue')) {
            if (!$this->isConfigured()) {
                $output .= $this->displayError($this->l('El módulo no está configurado'));
            } else {
                list($sent, $failed) = $this->processQueue(50);
                $output .= $failed
                    ? $this->displayWarning(sprintf($this->l('Reenviados %d avisos; la tienda madre sigue sin responder, el resto queda en cola.'), $sent))
                    : $this->displayConfirmation(sprintf($this->l('Reenviados %d avisos pendientes.'), $sent));
            }
        } elseif (Tools::isSubmit('submitSyncroSeviSenderClearQueue')) {
            Configuration::updateValue('SYNCROSEVISENDER_QUEUE', '');
            $output .= $this->displayConfirmation($this->l('Cola vaciada. La tienda madre recogerá esos pedidos en su próxima sincronización.'));
        }

        return $output . $this->renderStatus() . $this->renderForm();
    }

    private function processConfiguration()
    {
        $errors = array();

        // Código de configuración copiado de la tienda madre: rellena todos los campos
        $code = trim((string) Tools::getValue('SYNCROSEVISENDER_CONFIG_CODE'));
        if ($code !== '') {
            $decoded = $this->decodeConfigCode($code);
            if (!$decoded) {
                return $this->displayError($this->l('El código de configuración no es válido. Cópialo completo desde la tienda madre (SyncroSevi > Editar tienda).'));
            }
            $active = 1;
            $motherUrl = $decoded['url'];
            $shopId = (int) $decoded['shop_id'];
            $secretKey = $decoded['secret'];
            $syncStates = implode(',', array_filter(array_map('intval', explode(',', $decoded['states']))));
        } else {
            $active = (int) (bool) Tools::getValue('SYNCROSEVISENDER_ACTIVE');
            $motherUrl = trim((string) Tools::getValue('SYNCROSEVISENDER_MOTHER_URL'));
            $shopId = (int) Tools::getValue('SYNCROSEVISENDER_SHOP_ID');
            $secretKey = trim((string) Tools::getValue('SYNCROSEVISENDER_SECRET_KEY'));
            $states = array();
            foreach (OrderState::getOrderStates((int) $this->context->language->id) as $state) {
                if (Tools::getValue('SYNCROSEVISENDER_STATE_' . (int) $state['id_order_state'])) {
                    $states[] = (int) $state['id_order_state'];
                }
            }
            $syncStates = implode(',', $states);
        }
        $timeout = (int) Tools::getValue('SYNCROSEVISENDER_TIMEOUT', 5);
        $maxRetries = (int) Tools::getValue('SYNCROSEVISENDER_MAX_RETRIES', 2);

        if ($active) {
            if ($motherUrl === '' || !filter_var($motherUrl, FILTER_VALIDATE_URL)) {
                $errors[] = $this->l('La URL del webhook de la tienda madre no es válida');
            }
            if ($shopId <= 0) {
                $errors[] = $this->l('El ID de tienda debe ser un número mayor que 0');
            }
            if ($secretKey === '') {
                $errors[] = $this->l('La clave secreta es obligatoria');
            }
            if ($syncStates === '') {
                $errors[] = $this->l('Marca al menos un estado a enviar');
            }
        }
        if ($timeout < 2 || $timeout > 60) {
            $errors[] = $this->l('El tiempo de espera debe estar entre 2 y 60 segundos');
        }
        if ($maxRetries < 1 || $maxRetries > 5) {
            $errors[] = $this->l('Los intentos deben estar entre 1 y 5');
        }
        if (!empty($errors)) {
            return $this->displayError(implode('<br>', $errors));
        }

        Configuration::updateValue('SYNCROSEVISENDER_ACTIVE', $active);
        Configuration::updateValue('SYNCROSEVISENDER_MOTHER_URL', $motherUrl);
        Configuration::updateValue('SYNCROSEVISENDER_SHOP_ID', $shopId ?: '');
        Configuration::updateValue('SYNCROSEVISENDER_SECRET_KEY', $secretKey);
        Configuration::updateValue('SYNCROSEVISENDER_SYNC_STATES', $syncStates);
        Configuration::updateValue('SYNCROSEVISENDER_TIMEOUT', $timeout);
        Configuration::updateValue('SYNCROSEVISENDER_MAX_RETRIES', $maxRetries);

        if ($active || Tools::isSubmit('submitSyncroSeviSenderTest')) {
            $test = $this->testConnection();
            if ($test['success']) {
                return $this->displayConfirmation($this->l('Configuración guardada. Conexión con la tienda madre correcta.'));
            }

            return $this->displayWarning($this->l('Configuración guardada, pero la prueba de conexión falló:') . ' ' . htmlspecialchars($test['error']));
        }

        return $this->displayConfirmation($this->l('Configuración guardada (módulo desactivado)'));
    }

    private function decodeConfigCode($code)
    {
        $code = preg_replace('/\s+/', '', $code);
        if (strpos($code, 'SSV1:') === 0) {
            $code = substr($code, 5);
        }
        $data = json_decode((string) base64_decode($code, true), true);
        if (!is_array($data) || empty($data['url']) || empty($data['shop_id']) || empty($data['secret'])) {
            return false;
        }
        $data['states'] = isset($data['states']) ? (string) $data['states'] : '2,3,4,5';

        return $data;
    }

    private function renderStatus()
    {
        $active = (int) Configuration::get('SYNCROSEVISENDER_ACTIVE');
        $configured = $this->isConfigured();
        $queue = $this->getQueue();
        $lastOk = Configuration::get('SYNCROSEVISENDER_LAST_OK');
        $lastError = Configuration::get('SYNCROSEVISENDER_LAST_ERROR');

        if ($active && $configured) {
            $html = '<div class="alert alert-success"><strong>' . $this->l('Activo') . '</strong> — '
                . sprintf($this->l('los pedidos que pasen a los estados %s se envían a la tienda madre (tienda ID %d).'), htmlspecialchars(Configuration::get('SYNCROSEVISENDER_SYNC_STATES')), (int) Configuration::get('SYNCROSEVISENDER_SHOP_ID'))
                . '</div>';
        } elseif (!$configured) {
            $html = '<div class="alert alert-info">' . $this->l('Pega el «Código de configuración» de la tienda madre (SyncroSevi > Editar tienda > Webhook) y guarda, o rellena los campos a mano.') . '</div>';
        } else {
            $html = '<div class="alert alert-warning">' . $this->l('Módulo desactivado: no se envía ningún aviso a la tienda madre.') . '</div>';
        }

        $lines = array();
        if ($lastOk) {
            $lines[] = $this->l('Último envío correcto:') . ' ' . htmlspecialchars($lastOk);
        }
        if ($lastError) {
            $lines[] = $this->l('Último error:') . ' ' . htmlspecialchars($lastError);
        }
        if (!empty($queue)) {
            $lines[] = sprintf($this->l('%d avisos pendientes de reenviar (se reenvían solos cuando la tienda madre vuelve a responder).'), count($queue))
                . ' <form method="post" style="display:inline" action="' . htmlspecialchars($this->getConfigUrl()) . '">'
                . '<button type="submit" name="submitSyncroSeviSenderQueue" class="btn btn-default btn-xs">' . $this->l('Reenviar ahora') . '</button> '
                . '<button type="submit" name="submitSyncroSeviSenderClearQueue" class="btn btn-default btn-xs">' . $this->l('Vaciar cola') . '</button></form>';
        }
        if ($lines) {
            $html .= '<div class="panel"><div class="panel-heading"><i class="icon-info"></i> ' . $this->l('Estado') . '</div><p>' . implode('</p><p>', $lines) . '</p></div>';
        }

        return $html;
    }

    private function getConfigUrl()
    {
        return $this->context->link->getAdminLink('AdminModules', true) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
    }

    private function renderForm()
    {
        $idLang = (int) $this->context->language->id;
        $orderStates = OrderState::getOrderStates($idLang);
        $selected = $this->getSyncStates();

        $values = array(
            'SYNCROSEVISENDER_CONFIG_CODE' => '',
            'SYNCROSEVISENDER_ACTIVE' => (int) Configuration::get('SYNCROSEVISENDER_ACTIVE'),
            'SYNCROSEVISENDER_MOTHER_URL' => Configuration::get('SYNCROSEVISENDER_MOTHER_URL'),
            'SYNCROSEVISENDER_SHOP_ID' => Configuration::get('SYNCROSEVISENDER_SHOP_ID'),
            'SYNCROSEVISENDER_SECRET_KEY' => Configuration::get('SYNCROSEVISENDER_SECRET_KEY'),
            'SYNCROSEVISENDER_TIMEOUT' => (int) Configuration::get('SYNCROSEVISENDER_TIMEOUT') ?: 5,
            'SYNCROSEVISENDER_MAX_RETRIES' => (int) Configuration::get('SYNCROSEVISENDER_MAX_RETRIES') ?: 2,
        );
        foreach ($orderStates as $state) {
            $values['SYNCROSEVISENDER_STATE_' . (int) $state['id_order_state']] = in_array((int) $state['id_order_state'], $selected);
        }
        foreach ($orderStates as &$state) {
            $state['name'] = $state['name'] . ' (ID ' . (int) $state['id_order_state'] . ')';
        }
        unset($state);

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->table = $this->table;
        $helper->default_form_language = $idLang;
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitSyncroSeviSender';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $values,
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $idLang,
        );

        $forms = array();
        $forms[] = array('form' => array(
            'legend' => array('title' => $this->l('Configuración rápida'), 'icon' => 'icon-magic'),
            'input' => array(
                array(
                    'type' => 'textarea',
                    'name' => 'SYNCROSEVISENDER_CONFIG_CODE',
                    'label' => $this->l('Código de configuración'),
                    'rows' => 3,
                    'desc' => $this->l('Opcional. Pégalo desde la tienda madre (SyncroSevi > Editar tienda > Webhook) y pulsa Guardar: rellena todos los datos y activa el módulo.'),
                ),
            ),
            'submit' => array('title' => $this->l('Guardar'), 'name' => 'submitSyncroSeviSender'),
        ));
        $forms[] = array('form' => array(
            'legend' => array('title' => $this->l('Configuración manual'), 'icon' => 'icon-cogs'),
            'input' => array(
                array(
                    'type' => 'switch',
                    'name' => 'SYNCROSEVISENDER_ACTIVE',
                    'label' => $this->l('Enviar avisos a la tienda madre'),
                    'is_bool' => true,
                    'values' => array(
                        array('id' => 'ssv_active_on', 'value' => 1, 'label' => $this->l('Sí')),
                        array('id' => 'ssv_active_off', 'value' => 0, 'label' => $this->l('No')),
                    ),
                ),
                array(
                    'type' => 'text',
                    'name' => 'SYNCROSEVISENDER_MOTHER_URL',
                    'label' => $this->l('URL del webhook de la tienda madre'),
                    'desc' => $this->l('La que aparece en la tienda madre en SyncroSevi > Editar tienda > Webhook (incluye security_token).'),
                ),
                array(
                    'type' => 'text',
                    'name' => 'SYNCROSEVISENDER_SHOP_ID',
                    'label' => $this->l('ID de esta tienda en la madre'),
                    'class' => 'fixed-width-sm',
                ),
                array(
                    'type' => 'text',
                    'name' => 'SYNCROSEVISENDER_SECRET_KEY',
                    'label' => $this->l('Clave secreta'),
                    'desc' => $this->l('Clave de 64 caracteres de esta tienda en la madre.'),
                ),
                array(
                    'type' => 'checkbox',
                    'name' => 'SYNCROSEVISENDER_STATE',
                    'label' => $this->l('Estados que se envían'),
                    'values' => array('query' => $orderStates, 'id' => 'id_order_state', 'name' => 'name'),
                    'desc' => $this->l('Normalmente los mismos «Estados a importar» configurados en la tienda madre.'),
                ),
                array(
                    'type' => 'text',
                    'name' => 'SYNCROSEVISENDER_TIMEOUT',
                    'label' => $this->l('Tiempo de espera (segundos)'),
                    'class' => 'fixed-width-sm',
                    'desc' => $this->l('Entre 2 y 60. Bajo para no frenar el cambio de estado si la madre no responde.'),
                ),
                array(
                    'type' => 'text',
                    'name' => 'SYNCROSEVISENDER_MAX_RETRIES',
                    'label' => $this->l('Intentos'),
                    'class' => 'fixed-width-sm',
                    'desc' => $this->l('Entre 1 y 5. Si fallan todos, el aviso queda en cola y la madre también lo recoge en su sincronización.'),
                ),
            ),
            'submit' => array('title' => $this->l('Guardar'), 'name' => 'submitSyncroSeviSender'),
            'buttons' => array(
                array(
                    'title' => $this->l('Guardar y probar conexión'),
                    'icon' => 'process-icon-refresh',
                    'name' => 'submitSyncroSeviSenderTest',
                    'type' => 'submit',
                    'class' => 'btn btn-default pull-right',
                ),
            ),
        ));

        return $helper->generateForm($forms);
    }
}
