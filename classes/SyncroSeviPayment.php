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
 * Clase de pago virtual para SyncroSevi
 * Extiende PaymentModule para poder crear pedidos correctamente
 */
class SyncroSeviPayment extends PaymentModule
{
    /** Último error de validateOrder (para mostrarlo en el resultado del procesamiento) */
    public $lastError = '';

    public function __construct()
    {
        $this->name = 'syncrosevipayment';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'SyncroSevi Team';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array(
            'min' => '1.7.0.0',
            'max' => _PS_VERSION_
        );
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = 'SyncroSevi Payment Handler';
        $this->description = 'Módulo de pago interno para SyncroSevi';
        
        // Marcar como activo para poder crear pedidos
        $this->active = 1;
        
        // Configurar contexto si no existe
        if (!$this->context) {
            $this->context = Context::getContext();
        }
    }

    /**
     * Instalar el módulo de pago (no es necesario instalarlo realmente)
     */
    public function install()
    {
        return true; // Siempre retornar true ya que es un módulo virtual
    }

    /**
     * Desinstalar el módulo de pago
     */
    public function uninstall()
    {
        return true;
    }

    /**
     * No mostrar opciones de pago en el frontend
     */
    public function hookPaymentOptions($params)
    {
        return array(); // No mostrar opciones de pago
    }

    /**
     * No mostrar en el hook de pago
     */
    public function hookPayment($params)
    {
        return false;
    }

    /**
     * Método override para validar el pedido sin verificaciones adicionales
     * Este método está optimizado para la creación automática de pedidos desde SyncroSevi
     */
    public function validateOrder($id_cart, $id_order_state, $amount_paid, $payment_method = 'Unknown', $message = null, $extra_vars = array(), $currency_special = null, $dont_touch_amount = false, $secure_key = false, $shop = null, $order_reference = null)
    {
        $this->lastError = '';
        try {
            // Log para debug
            if (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) {
                error_log('SyncroSeviPayment::validateOrder - Carrito: ' . $id_cart . ', Estado: ' . $id_order_state . ', Total: ' . $amount_paid);
            }

            // Asegurar que tenemos un contexto válido
            if (!$this->context) {
                $this->context = Context::getContext();
            }

            // Cargar el carrito
            $cart = new Cart($id_cart);
            if (!Validate::isLoadedObject($cart)) {
                throw new Exception('Carrito no válido: ' . $id_cart);
            }

            // Establecer contexto del carrito
            $this->context->cart = $cart;
            $this->context->customer = new Customer($cart->id_customer);
            $this->context->currency = new Currency($cart->id_currency);
            $this->context->language = new Language($cart->id_lang);

// PRESERVAR el transportista del carrito antes de validateOrder
$originalCarrierId = $cart->id_carrier;

// DEBUG: Información del carrito antes de validateOrder
if (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) {
    $cartWeight = $cart->getTotalWeight();
    $cartTotal = $cart->getOrderTotal(false, Cart::ONLY_PRODUCTS);
    $shippingCost = $cart->getPackageShippingCost($originalCarrierId);
    
    error_log('SyncroSeviPayment::validateOrder - ANTES parent::validateOrder:');
    error_log('  - Transportista del carrito: ' . $originalCarrierId);
    error_log('  - Peso total carrito: ' . $cartWeight . ' kg');
    error_log('  - Total productos: ' . $cartTotal . '');
    error_log('  - Coste envío calculado: ' . $shippingCost . '');
}

// CONFIGURAR extra_vars para NO generar factura automática
$extra_vars = array(
    'dont_create_invoice' => true,
    'create_invoice' => false
);

// Usar el método padre con parámetros optimizados
$result = parent::validateOrder(
    $id_cart,
    $id_order_state,
    $amount_paid,
    $payment_method,
    $message,
    $extra_vars,
    $currency_special,
    $dont_touch_amount,
    $secure_key,
    $shop,
    $order_reference
);

// FORZAR el transportista correcto en el pedido creado
if ($result && $this->currentOrder && $originalCarrierId) {
    $order = new Order($this->currentOrder);
    if (Validate::isLoadedObject($order) && $order->id_carrier != $originalCarrierId) {
        if (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) {
            error_log('SyncroSeviPayment::validateOrder - CORRIGIENDO transportista del ' . $order->id_carrier . ' al ' . $originalCarrierId);
        }
        
        $order->id_carrier = $originalCarrierId;
        $order->update();
        
        // También actualizar en order_carrier
        Db::getInstance()->update('order_carrier', 
            array('id_carrier' => (int)$originalCarrierId),
            'id_order = ' . (int)$this->currentOrder
        );
		// CALCULAR TOTAL CORRECTO INCLUYENDO GASTOS DE ENVÍO
$productsTotal = $cart->getOrderTotal(false, Cart::ONLY_PRODUCTS);
$newShippingCost = $cart->getPackageShippingCost($originalCarrierId);
$correctTotal = $productsTotal + $newShippingCost;

if (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) {
    error_log('SyncroSeviPayment::validateOrder - TOTALES:');
    error_log('  - Productos: ' . $productsTotal . '');
    error_log('  - Envío: ' . $newShippingCost . '');
    error_log('  - Total correcto: ' . $correctTotal . '');
    error_log('  - Total pagado original: ' . $amount_paid . '');
}

// RECALCULAR gastos de envío del pedido
		// RECALCULAR gastos de envío del pedido
$newShippingCost = $cart->getPackageShippingCost($originalCarrierId);
if ($newShippingCost > 0) {
    if (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) {
        error_log('SyncroSeviPayment::validateOrder - RECALCULANDO gastos envío: ' . $newShippingCost . '');
    }
    
   // Actualizar gastos de envío en el pedido
$order->total_shipping = $newShippingCost;
$order->total_shipping_tax_incl = $newShippingCost;
$order->total_shipping_tax_excl = $newShippingCost;
$order->total_paid = $correctTotal;
$order->total_paid_tax_incl = $correctTotal;
$order->total_paid_tax_excl = $correctTotal;
$order->update();
    
    // También actualizar en order_carrier
    Db::getInstance()->update('order_carrier', 
        array(
            'shipping_cost_tax_excl' => $newShippingCost,
            'shipping_cost_tax_incl' => $newShippingCost
        ),
        'id_order = ' . (int)$this->currentOrder
    );
}
    }
}

            if ($result && $this->currentOrder) {
                if (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) {
                    error_log('SyncroSeviPayment::validateOrder - Pedido creado exitosamente: #' . $this->currentOrder);
                }
                return true;
            } else {
                if (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) {
                    error_log('SyncroSeviPayment::validateOrder - Error: resultado=' . ($result ? 'true' : 'false') . ', currentOrder=' . $this->currentOrder);
                }
                return false;
            }

        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            if (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) {
                error_log('SyncroSeviPayment::validateOrder - Excepción: ' . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Verificar si el módulo está disponible
     */
    public function isAvailable()
    {
        return true; // Siempre disponible para SyncroSevi
    }

    /**
     * Override del método checkCurrency para evitar validaciones de moneda
     */
    public function checkCurrency($cart)
    {
        return true; // Aceptar cualquier moneda
    }

    /**
     * No requerir SSL
     */
    public function needsSSL()
    {
        return false;
    }

    /**
     * Método para verificar si el pago está disponible
     */
    public function isPaymentAvailable()
    {
        return true;
    }

    /**
     * Override para evitar verificaciones de zona
     */
    public function checkZone($id_zone)
    {
        return true;
    }

    /**
     * Override para evitar verificaciones de grupo
     */
    public function checkGroup($id_group)
    {
        return true;
    }

    /**
     * Método adicional para forzar la creación del pedido con validaciones mínimas
     */
    public function forceValidateOrder($id_cart, $id_order_state, $amount_paid, $payment_method = 'SyncroSevi', $message = null)
    {
        // Configuración mínima para forzar la creación
        $extra_vars = array();
        $currency_special = null;
        $dont_touch_amount = false;
        $secure_key = false;
        $shop = null;

        // Cargar carrito y verificaciones básicas
        $cart = new Cart($id_cart);
        if (!Validate::isLoadedObject($cart) || $cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0) {
            return false;
        }

        // Verificar que el carrito no esté ya convertido en pedido
        if ($cart->OrderExists()) {
            return false;
        }

        // Establecer contexto mínimo
        if (!$this->context) {
            $this->context = Context::getContext();
        }

        $this->context->cart = $cart;
        $this->context->customer = new Customer($cart->id_customer);

        // Intentar crear el pedido
        try {
            return $this->validateOrder(
                $id_cart,
                $id_order_state,
                $amount_paid,
                $payment_method,
                $message,
                $extra_vars,
                $currency_special,
                $dont_touch_amount,
                $secure_key,
                $shop
            );
        } catch (Exception $e) {
            return false;
        }
    }
}
?>