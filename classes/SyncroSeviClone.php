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
 * Modo «clonar»: cada pedido de la tienda hija se crea en la madre como un pedido propio,
 * con el mismo cliente, direcciones, líneas, precios, impuestos, descuentos y transporte.
 *
 * Entra todo producto cuya referencia exista en la madre, esté activo o no y tenga stock o no
 * (no se toca el stock: baja con el pedido como en cualquier venta).
 * El pedido se crea con el circuito normal de PrestaShop (stock, historial, hooks) y después
 * se copian literalmente los importes de la hija para que coincidan al céntimo.
 */
class SyncroSeviClone
{
    /** El pedido se retiene (queda pendiente con el motivo a la vista) */
    const ERR_HOLD = 4202;

    /** @var Syncrosevi */
    private $module;

    /** @var array fila de syncrosevi_child_shops */
    private $shop;

    /** @var SyncroSeviWebservice */
    private $ws;

    private $nodes = array();
    private $motherTaxes = null;

    public function __construct($module, array $shop, $ws)
    {
        $this->module = $module;
        $this->shop = $shop;
        $this->ws = $ws;
    }

    /* ==================== datos de la hija ==================== */

    private function node($resource, $id)
    {
        $key = $resource . '_' . (int) $id;
        if (!array_key_exists($key, $this->nodes)) {
            $this->nodes[$key] = $this->ws->getOne($resource, (int) $id);
        }

        return $this->nodes[$key];
    }

    private static function ml($node)
    {
        if (!$node) {
            return '';
        }
        if (isset($node->language)) {
            foreach ($node->language as $l) {
                if (trim((string) $l) !== '') {
                    return trim((string) $l);
                }
            }

            return '';
        }

        return trim((string) $node);
    }

    private static function money($v)
    {
        return number_format((float) $v, 2, ',', '.') . ' €';
    }

    /* ==================== mapeos ==================== */

    public static function decodeMap($json)
    {
        $map = json_decode((string) $json, true);

        return is_array($map) ? array_map('intval', $map) : array();
    }

    /** Estado de la madre para un estado de la hija (0 = sin mapear / no hacer nada) */
    public function mappedState($childState)
    {
        $map = self::decodeMap(isset($this->shop['clone_state_map']) ? $this->shop['clone_state_map'] : '');

        return isset($map[(int) $childState]) ? (int) $map[(int) $childState] : 0;
    }

    /** Estado de la hija para un estado de la madre (0 = no hacer nada) */
    public function mappedStateOut($motherState)
    {
        $map = self::decodeMap(isset($this->shop['clone_state_map_out']) ? $this->shop['clone_state_map_out'] : '');

        return isset($map[(int) $motherState]) ? (int) $map[(int) $motherState] : 0;
    }

    /** Impuestos de la madre (activos y no borrados) */
    private function motherTaxes()
    {
        if ($this->motherTaxes === null) {
            $this->motherTaxes = (array) Db::getInstance()->executeS(
                'SELECT t.id_tax, t.rate, tl.name FROM `' . _DB_PREFIX_ . 'tax` t
                 LEFT JOIN `' . _DB_PREFIX_ . 'tax_lang` tl ON (tl.id_tax = t.id_tax AND tl.id_lang = ' . (int) Configuration::get('PS_LANG_DEFAULT') . ')
                 WHERE t.deleted = 0 ORDER BY t.active DESC, t.id_tax ASC'
            );
        }

        return $this->motherTaxes;
    }

    /** Impuesto de la madre para un impuesto de la hija: primero el mapeo guardado, si no, el mismo porcentaje */
    private function motherTax($childTaxId, $rate)
    {
        $map = self::decodeMap(isset($this->shop['clone_tax_map']) ? $this->shop['clone_tax_map'] : '');
        if ($childTaxId && !empty($map[(int) $childTaxId])) {
            foreach ($this->motherTaxes() as $t) {
                if ((int) $t['id_tax'] === (int) $map[(int) $childTaxId]) {
                    return $t;
                }
            }
        }
        foreach ($this->motherTaxes() as $t) {
            if (abs((float) $t['rate'] - (float) $rate) < 0.001) {
                return $t;
            }
        }

        return null;
    }

    /* ==================== pedido ==================== */

    /**
     * Clona un pedido de la hija.
     *
     * @return array('id_order', 'products', 'child_state', 'note')
     */
    public function cloneOrder($childOrderId)
    {
        $o = $this->node('orders', $childOrderId);
        if (!$o) {
            throw new Exception('El pedido #' . (int) $childOrderId . ' no se encuentra en la tienda hija');
        }
        $ref = (string) $o->reference;
        $rows = $this->ws->getOrderDetailsFull($childOrderId);
        if (empty($rows)) {
            throw new Exception('El pedido ' . $ref . ' no tiene líneas en la tienda hija');
        }

        // Productos: por referencia/código, como en el modo consolidado
        $lines = array();
        $missing = array();
        foreach ($rows as $r) {
            $found = trim($r['product_reference']) !== '' ? $this->module->findProductByReference($r['product_reference']) : null;
            if ($found) {
                $r['found'] = $found;
                $lines[] = $r;
            } else {
                $missing[] = $r;
            }
        }

        $sub = null;
        if ($missing) {
            $text = $this->missingText($missing);
            if (!isset($this->shop['clone_missing']) || $this->shop['clone_missing'] !== 'substitute') {
                throw new Exception('Faltan productos en la tienda madre: ' . $text, self::ERR_HOLD);
            }
            $sub = $this->substitute();
            if (!$sub) {
                throw new Exception('Faltan productos en la tienda madre (' . $text . ') y no hay un producto de sustitución válido configurado', self::ERR_HOLD);
            }
        }

        $customer = $this->customer($o);
        $delivery = $this->address((int) $o->id_address_delivery, $customer);
        $invoice = (int) $o->id_address_invoice === (int) $o->id_address_delivery ? $delivery : $this->address((int) $o->id_address_invoice, $customer);
        $idCurrency = $this->currencyId((int) $o->id_currency);
        $idLang = $this->langId((int) $o->id_lang);
        $carrierId = $this->carrierId((int) $o->id_carrier);

        $cart = new Cart();
        $cart->id_customer = (int) $customer->id;
        $cart->id_address_delivery = (int) $delivery->id;
        $cart->id_address_invoice = (int) $invoice->id;
        $cart->id_lang = $idLang;
        $cart->id_currency = $idCurrency;
        $cart->id_shop = (int) Configuration::get('PS_SHOP_DEFAULT');
        $cart->id_shop_group = (int) Shop::getGroupFromShop($cart->id_shop);
        $cart->secure_key = $customer->secure_key;
        $cart->id_carrier = $carrierId;
        $cart->gift = (int) $o->gift;
        $cart->gift_message = (string) $o->gift_message;
        if (!$cart->add()) {
            throw new Exception('No se pudo crear el carrito');
        }

        $context = Context::getContext();
        $context->cart = $cart;
        $context->customer = $customer;
        $context->currency = new Currency($idCurrency);
        $shopObject = new Shop($cart->id_shop);

        try {
            // Entra todo lo que coincida por referencia, esté como esté en la madre y sin tocar el stock
            foreach ($lines as $r) {
                $f = $r['found'];
                $this->cartPrice($cart, $f['id_product'], $f['id_product_attribute'], (float) $r['unit_price_tax_excl']);
                if (!$this->module->addToCartAnyway($cart, $f['id_product'], $f['id_product_attribute'], (int) $r['product_quantity'], $shopObject, $r['product_reference'])) {
                    throw new Exception('No se pudo añadir «' . $r['product_reference'] . '» al carrito: falló la escritura en la base de datos');
                }
            }

            $subStockBefore = null;
            if ($missing) {
                $subStockBefore = (int) StockAvailable::getQuantityAvailableByProduct($sub['id_product'], $sub['id_product_attribute']);
                $this->cartPrice($cart, $sub['id_product'], $sub['id_product_attribute'], array_sum(array_column($missing, 'total_price_tax_excl')));
                if (!$this->module->addToCartAnyway($cart, $sub['id_product'], $sub['id_product_attribute'], 1, $shopObject, 'sustitución')) {
                    throw new Exception('No se pudo añadir el producto de sustitución al carrito: falló la escritura en la base de datos');
                }
            }

            // Carrito recargado: algunas líneas pueden haberse metido directamente en la base de datos
            $cart = new Cart((int) $cart->id);
            $context->cart = $cart;

            $cart->setDeliveryOption(array($cart->id_address_delivery => $carrierId . ','));
            $cart->update();

            // Estado inicial: siempre el configurado en la tienda («Estado del Pedido»)
            $state = (int) $this->shop['id_order_state'] ?: $this->mappedState((int) $o->current_state);
            $idOrder = $this->validate($cart, $state, $ref);
            // Si PrestaShop lo pasa a «En espera de reposición» por stock, vuelve al estado configurado
            $this->module->keepOrderState($idOrder, $state);

            $this->copyAmounts($idOrder, $o, $lines, $missing, $sub, $carrierId);

            if ($missing && $subStockBefore !== null) {
                // El producto de sustitución no debe perder ni ganar stock
                StockAvailable::setQuantity($sub['id_product'], $sub['id_product_attribute'], $subStockBefore);
            }
        } catch (Exception $e) {
            $this->cleanCartPrices($cart);
            throw $e;
        }
        $this->cleanCartPrices($cart);

        if (isset($this->shop['clone_date']) && $this->shop['clone_date'] === 'original') {
            $date = (string) $o->date_add;
            if (Validate::isDate($date)) {
                Db::getInstance()->update('orders', array('date_add' => pSQL($date)), 'id_order = ' . (int) $idOrder);
                Db::getInstance()->update('order_history', array('date_add' => pSQL($date)), 'id_order = ' . (int) $idOrder);
            }
        }

        $note = $this->note($o, $missing, $sub, $idOrder);
        $this->addPrivateMessage($idOrder, $cart->id, $note);

        return array(
            'id_order' => (int) $idOrder,
            'products' => count($rows),
            'child_state' => (int) $o->current_state,
            'id_customer' => (int) $customer->id,
            'note' => $note,
            'missing' => $missing,
        );
    }

    private function missingText(array $missing)
    {
        $parts = array();
        foreach ($missing as $r) {
            $parts[] = ($r['product_reference'] !== '' ? 'referencia ' . $r['product_reference'] : 'sin referencia')
                . ' «' . $r['product_name'] . '», ' . (int) $r['product_quantity'] . ' ud. a ' . self::money($r['unit_price_tax_incl']);
        }

        return implode('; ', $parts);
    }

    /** Producto de sustitución configurado (existe y está activo) */
    private function substitute()
    {
        $id = isset($this->shop['id_product_substitute']) ? (int) $this->shop['id_product_substitute'] : 0;
        if (!$id) {
            return null;
        }
        $p = new Product($id);
        if (!Validate::isLoadedObject($p)) {
            return null;
        }

        return array('id_product' => $id, 'id_product_attribute' => 0, 'name' => is_array($p->name) ? reset($p->name) : $p->name);
    }

    /** Precio fijo solo para este carrito (lo que hace el back office al cambiar un precio en un pedido) */
    private function cartPrice(Cart $cart, $idProduct, $idAttribute, $priceTaxExcl)
    {
        try {
            $sp = new SpecificPrice();
            $sp->id_product = (int) $idProduct;
            $sp->id_product_attribute = (int) $idAttribute;
            $sp->id_cart = (int) $cart->id;
            $sp->id_customer = (int) $cart->id_customer;
            $sp->id_shop = (int) $cart->id_shop;
            $sp->id_shop_group = 0;
            $sp->id_currency = 0;
            $sp->id_country = 0;
            $sp->id_group = 0;
            $sp->id_specific_price_rule = 0;
            $sp->from_quantity = 1;
            $sp->price = max(0, round((float) $priceTaxExcl, 6));
            $sp->reduction = 0;
            $sp->reduction_tax = 1;
            $sp->reduction_type = 'amount';
            $sp->from = '0000-00-00 00:00:00';
            $sp->to = '0000-00-00 00:00:00';
            $sp->add();
        } catch (Exception $e) {
            // Mismo producto en dos líneas: ya tiene su precio; los importes se copian después igualmente
        }
        if (method_exists('SpecificPrice', 'flushCache')) {
            SpecificPrice::flushCache();
        }
    }

    private function cleanCartPrices(Cart $cart)
    {
        if ($cart->id) {
            Db::getInstance()->delete('specific_price', 'id_cart = ' . (int) $cart->id);
        }
    }

    /** Crea el pedido con el módulo de pago interno (sin factura automática, como el modo consolidado) */
    private function validate(Cart $cart, $state, $ref)
    {
        require_once dirname(__FILE__) . '/SyncroSeviPayment.php';
        $total = (float) $cart->getOrderTotal(true, Cart::BOTH);

        // El módulo de pago usa el contexto global (ya tiene este carrito y este cliente);
        // su propiedad «context» es protegida en PrestaShop 9, no se puede asignar desde aquí
        $context = Context::getContext();
        $context->cart = $cart;
        $context->customer = new Customer($cart->id_customer);
        $payment = new SyncroSeviPayment();

        $ok = $payment->validateOrder(
            $cart->id,
            (int) $state,
            $total,
            'SyncroSevi - ' . $this->shop['name'],
            null,
            array(),
            (int) $cart->id_currency,
            false,
            $cart->secure_key
        );
        if ($ok && $payment->currentOrder) {
            return (int) $payment->currentOrder;
        }

        throw new Exception('No se pudo crear el pedido clonado de ' . $ref . ($payment->lastError ? ': ' . $payment->lastError : ''));
    }

    /* ==================== copia exacta de importes ==================== */

    private function copyAmounts($idOrder, $o, array $lines, array $missing, $sub, $carrierId)
    {
        $db = Db::getInstance();
        $order = new Order((int) $idOrder);

        // Líneas: se agrupan las de la hija por producto de la madre (el carrito junta las repetidas)
        $groups = array();
        foreach ($lines as $r) {
            $groups[$r['found']['id_product'] . '_' . $r['found']['id_product_attribute']][] = $r;
        }
        $subKey = $sub ? $sub['id_product'] . '_' . $sub['id_product_attribute'] : '';
        $details = (array) $db->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'order_detail` WHERE id_order = ' . (int) $idOrder . ' ORDER BY id_order_detail ASC');
        $subDone = false;
        foreach ($details as $d) {
            $key = (int) $d['product_id'] . '_' . (int) $d['product_attribute_id'];
            if (isset($groups[$key])) {
                $this->setDetail($d, $groups[$key], false);
                unset($groups[$key]);
            } elseif ($missing && !$subDone && $key === $subKey) {
                $this->replaceSubstitute($d, $missing);
                $subDone = true;
            }
        }

        // Descuentos
        $this->copyCartRules($order, $o);

        // Totales del pedido
        $f = function ($name) use ($o) {
            return (float) $o->{$name};
        };
        $db->update('orders', array(
            'id_carrier' => (int) $carrierId,
            'total_discounts' => $f('total_discounts'),
            'total_discounts_tax_incl' => $f('total_discounts_tax_incl'),
            'total_discounts_tax_excl' => $f('total_discounts_tax_excl'),
            'total_paid' => $f('total_paid'),
            'total_paid_tax_incl' => $f('total_paid_tax_incl'),
            'total_paid_tax_excl' => $f('total_paid_tax_excl'),
            'total_paid_real' => $f('total_paid_real'),
            'total_products' => $f('total_products'),
            'total_products_wt' => $f('total_products_wt'),
            'total_shipping' => $f('total_shipping'),
            'total_shipping_tax_incl' => $f('total_shipping_tax_incl'),
            'total_shipping_tax_excl' => $f('total_shipping_tax_excl'),
            'carrier_tax_rate' => $f('carrier_tax_rate'),
            'total_wrapping' => $f('total_wrapping'),
            'total_wrapping_tax_incl' => $f('total_wrapping_tax_incl'),
            'total_wrapping_tax_excl' => $f('total_wrapping_tax_excl'),
        ), 'id_order = ' . (int) $idOrder);

        // Transporte
        $tracking = '';
        try {
            foreach ($this->ws->getList('order_carriers', array('id_order' => '[' . (int) $o->id . ']')) as $oc) {
                if (trim((string) $oc->tracking_number) !== '') {
                    $tracking = trim((string) $oc->tracking_number);
                }
            }
        } catch (Exception $e) {
        }
        $carrierData = array(
            'id_carrier' => (int) $carrierId,
            'shipping_cost_tax_excl' => $f('total_shipping_tax_excl'),
            'shipping_cost_tax_incl' => $f('total_shipping_tax_incl'),
        );
        if ($tracking !== '') {
            $carrierData['tracking_number'] = pSQL($tracking);
        }
        if ((int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'order_carrier` WHERE id_order = ' . (int) $idOrder)) {
            $db->update('order_carrier', $carrierData, 'id_order = ' . (int) $idOrder);
        } else {
            $carrierData['id_order'] = (int) $idOrder;
            $carrierData['id_order_invoice'] = 0;
            $carrierData['weight'] = (float) $order->getTotalWeight();
            $carrierData['date_add'] = date('Y-m-d H:i:s');
            $db->insert('order_carrier', $carrierData);
        }

        // Pago: lo cobrado en la hija
        $paid = $f('total_paid_real');
        $payments = (array) $db->executeS('SELECT id_order_payment FROM `' . _DB_PREFIX_ . 'order_payment` WHERE order_reference = "' . pSQL($order->reference) . '"');
        foreach ($payments as $i => $p) {
            if ($i === 0 && $paid > 0) {
                $db->update('order_payment', array('amount' => $paid), 'id_order_payment = ' . (int) $p['id_order_payment']);
            } else {
                $db->delete('order_invoice_payment', 'id_order_payment = ' . (int) $p['id_order_payment']);
                $db->delete('order_payment', 'id_order_payment = ' . (int) $p['id_order_payment']);
            }
        }

        // Factura (si el estado la ha generado)
        $db->update('order_invoice', array(
            'total_discount_tax_excl' => $f('total_discounts_tax_excl'),
            'total_discount_tax_incl' => $f('total_discounts_tax_incl'),
            'total_paid_tax_excl' => $f('total_paid_tax_excl'),
            'total_paid_tax_incl' => $f('total_paid_tax_incl'),
            'total_products' => $f('total_products'),
            'total_products_wt' => $f('total_products_wt'),
            'total_shipping_tax_excl' => $f('total_shipping_tax_excl'),
            'total_shipping_tax_incl' => $f('total_shipping_tax_incl'),
            'total_wrapping_tax_excl' => $f('total_wrapping_tax_excl'),
            'total_wrapping_tax_incl' => $f('total_wrapping_tax_incl'),
        ), 'id_order = ' . (int) $idOrder);

        if (method_exists('Cache', 'clean')) {
            Cache::clean('*');
        }
    }

    /** Importes de una línea de la madre a partir de una o varias líneas de la hija */
    private function detailData(array $rows)
    {
        $qty = 0;
        $te = 0.0;
        $ti = 0.0;
        foreach ($rows as $r) {
            $qty += (int) $r['product_quantity'];
            $te += (float) $r['total_price_tax_excl'];
            $ti += (float) $r['total_price_tax_incl'];
        }
        $one = count($rows) === 1 ? $rows[0] : null;
        $ue = $one ? (float) $one['unit_price_tax_excl'] : ($qty ? $te / $qty : 0);
        $ui = $one ? (float) $one['unit_price_tax_incl'] : ($qty ? $ti / $qty : 0);
        $rate = $te > 0 ? round(($ti / $te - 1) * 100, 3) : 0;
        $tax = $rate > 0 ? $this->motherTax(!empty($rows[0]['tax_ids']) ? $rows[0]['tax_ids'][0] : 0, $rate) : null;

        return array(
            'data' => array(
                'product_quantity' => $qty,
                'product_price' => $one ? (float) $one['product_price'] : $ue,
                'reduction_percent' => $one ? (float) $one['reduction_percent'] : 0,
                'reduction_amount' => $one ? (float) $one['reduction_amount'] : 0,
                'reduction_amount_tax_incl' => $one ? (float) $one['reduction_amount_tax_incl'] : 0,
                'reduction_amount_tax_excl' => $one ? (float) $one['reduction_amount_tax_excl'] : 0,
                'group_reduction' => $one ? (float) $one['group_reduction'] : 0,
                'product_quantity_discount' => 0,
                'unit_price_tax_excl' => $ue,
                'unit_price_tax_incl' => $ui,
                'total_price_tax_excl' => $te,
                'total_price_tax_incl' => $ti,
                'original_product_price' => $one ? (float) $one['original_product_price'] : $ue,
                'ecotax' => $one ? (float) $one['ecotax'] : 0,
                'tax_rate' => $rate,
                'tax_name' => pSQL($tax ? (string) $tax['name'] : ''),
            ),
            'id_tax' => $tax ? (int) $tax['id_tax'] : 0,
            'unit_tax' => $ui - $ue,
            'total_tax' => $ti - $te,
        );
    }

    private function setDetail(array $d, array $rows, $useNames)
    {
        $calc = $this->detailData($rows);
        $data = $calc['data'];
        if ($useNames) {
            $r = $rows[0];
            $data['product_name'] = pSQL(Tools::substr($r['product_name'], 0, 255));
            $data['product_reference'] = pSQL(Tools::substr($r['product_reference'], 0, 64));
            $data['product_ean13'] = pSQL(Tools::substr($r['product_ean13'], 0, 13));
            $data['product_upc'] = pSQL(Tools::substr($r['product_upc'], 0, 12));
        }
        Db::getInstance()->update('order_detail', $data, 'id_order_detail = ' . (int) $d['id_order_detail']);
        $this->setDetailTax((int) $d['id_order_detail'], $calc);
    }

    /** La línea del producto de sustitución pasa a ser una línea por cada producto que falta, con su nombre y referencia */
    private function replaceSubstitute(array $d, array $missing)
    {
        $db = Db::getInstance();
        $first = true;
        foreach ($missing as $r) {
            if ($first) {
                $this->setDetail($d, array($r), true);
                $first = false;
                continue;
            }
            $calc = $this->detailData(array($r));
            $row = $d;
            unset($row['id_order_detail']);
            $row = array_merge($row, $calc['data'], array(
                'product_name' => Tools::substr($r['product_name'], 0, 255),
                'product_reference' => Tools::substr($r['product_reference'], 0, 64),
                'product_ean13' => Tools::substr($r['product_ean13'], 0, 13),
                'product_upc' => Tools::substr($r['product_upc'], 0, 12),
            ));
            foreach ($row as $k => $v) {
                if ($v === null) {
                    unset($row[$k]);
                } elseif (is_string($v)) {
                    $row[$k] = pSQL($v);
                }
            }
            if ($db->insert('order_detail', $row)) {
                $this->setDetailTax((int) $db->Insert_ID(), $calc);
            }
        }
    }

    private function setDetailTax($idDetail, array $calc)
    {
        $db = Db::getInstance();
        $db->delete('order_detail_tax', 'id_order_detail = ' . (int) $idDetail);
        if ($calc['id_tax'] && abs($calc['total_tax']) > 0.000001) {
            $db->insert('order_detail_tax', array(
                'id_order_detail' => (int) $idDetail,
                'id_tax' => (int) $calc['id_tax'],
                'unit_amount' => round($calc['unit_tax'], 6),
                'total_amount' => round($calc['total_tax'], 6),
            ));
        }
    }

    /** Descuentos: se quitan los que la madre haya aplicado sola y se ponen los de la hija */
    private function copyCartRules(Order $order, $o)
    {
        $db = Db::getInstance();
        foreach ((array) $db->executeS('SELECT id_cart_rule FROM `' . _DB_PREFIX_ . 'order_cart_rule` WHERE id_order = ' . (int) $order->id) as $r) {
            if ((int) $r['id_cart_rule']) {
                $db->execute('UPDATE `' . _DB_PREFIX_ . 'cart_rule` SET quantity = quantity + 1 WHERE id_cart_rule = ' . (int) $r['id_cart_rule']);
            }
        }
        $db->delete('order_cart_rule', 'id_order = ' . (int) $order->id);

        $rules = array();
        try {
            foreach ($this->ws->getList('order_cart_rules', array('id_order' => '[' . (int) $o->id . ']')) as $cr) {
                $rules[] = array(
                    'name' => (string) $cr->name,
                    'value' => (float) $cr->value,
                    'value_tax_excl' => (float) $cr->value_tax_excl,
                    'free_shipping' => (int) $cr->free_shipping,
                );
            }
        } catch (Exception $e) {
            // Clave sin permiso para order_cart_rules: se usa el total de descuentos
        }
        if (!$rules && (float) $o->total_discounts_tax_incl > 0) {
            $rules[] = array(
                'name' => 'Descuento del pedido ' . (string) $o->reference,
                'value' => (float) $o->total_discounts_tax_incl,
                'value_tax_excl' => (float) $o->total_discounts_tax_excl,
                'free_shipping' => 0,
            );
        }
        foreach ($rules as $r) {
            $db->insert('order_cart_rule', array(
                'id_order' => (int) $order->id,
                'id_cart_rule' => 0,
                'id_order_invoice' => 0,
                'name' => pSQL(Tools::substr($r['name'], 0, 254)),
                'value' => $r['value'],
                'value_tax_excl' => $r['value_tax_excl'],
                'free_shipping' => $r['free_shipping'],
            ));
        }
    }

    /* ==================== cliente y direcciones ==================== */

    private function customer($o)
    {
        $c = $this->node('customers', (int) $o->id_customer);
        if (!$c) {
            throw new Exception('No se pudo leer el cliente del pedido en la tienda hija (¿la clave del webservice tiene permiso para «customers»?)');
        }
        $email = trim((string) $c->email);
        if (!Validate::isEmail($email)) {
            throw new Exception('El cliente del pedido no tiene un email válido');
        }

        // Si ya existe en la madre se usa tal cual, sin tocar nada
        $id = (int) Db::getInstance()->getValue(
            'SELECT id_customer FROM `' . _DB_PREFIX_ . 'customer`
             WHERE email = "' . pSQL($email) . '" AND deleted = 0
             ORDER BY is_guest ASC, id_customer DESC'
        );
        if ($id) {
            $customer = new Customer($id);
            if (Validate::isLoadedObject($customer)) {
                return $customer;
            }
        }

        // Si no, se crea como invitado (sin contraseña conocida, sin newsletter ni publicidad)
        $customer = new Customer();
        $customer->firstname = self::cleanName((string) $c->firstname) ?: 'Cliente';
        $customer->lastname = self::cleanName((string) $c->lastname) ?: '-';
        $customer->email = $email;
        $customer->is_guest = 1;
        $customer->active = 1;
        $customer->newsletter = 0;
        $customer->optin = 0;
        $customer->id_lang = $this->langId((int) $c->id_lang);
        $customer->id_shop = (int) Configuration::get('PS_SHOP_DEFAULT');
        $customer->id_shop_group = (int) Shop::getGroupFromShop($customer->id_shop);
        $customer->id_default_group = (int) Configuration::get('PS_GUEST_GROUP') ?: (int) Configuration::get('PS_CUSTOMER_GROUP');
        $gender = (int) $c->id_gender;
        if ($gender && Validate::isLoadedObject(new Gender($gender))) {
            $customer->id_gender = $gender;
        }
        $birthday = trim((string) $c->birthday);
        if ($birthday !== '' && $birthday !== '0000-00-00' && Validate::isBirthDate($birthday)) {
            $customer->birthday = $birthday;
        }
        foreach (array('company', 'siret', 'ape', 'website') as $field) {
            $value = trim((string) $c->{$field});
            if ($value !== '' && property_exists($customer, $field) && $customer->validateField($field, $value) === true) {
                $customer->{$field} = $value;
            }
        }
        $customer->passwd = self::hashPassword(Tools::passwdGen(20));

        if (!$customer->add()) {
            throw new Exception('No se pudo crear el cliente ' . $email . ' en la tienda madre');
        }
        Db::getInstance()->insert('syncrosevi_clone_customer', array(
            'id_customer' => (int) $customer->id,
            'id_child_shop' => (int) $this->shop['id_child_shop'],
            'date_add' => date('Y-m-d H:i:s'),
        ), false, true, Db::INSERT_IGNORE);
        $this->module->log('Cliente invitado ' . $email . ' creado en la madre (ID ' . (int) $customer->id . ')');

        return $customer;
    }

    private static function cleanName($name)
    {
        $name = trim(preg_replace('/[0-9!<>,;?=+()@#"°{}_$%:\/\\\\*^]+/u', ' ', $name));

        return Tools::substr(preg_replace('/\s+/u', ' ', $name), 0, 255);
    }

    public static function hashPassword($plain)
    {
        if (class_exists('PrestaShop\PrestaShop\Core\Crypto\Hashing')) {
            $hashing = new PrestaShop\PrestaShop\Core\Crypto\Hashing();

            return $hashing->hash($plain);
        }

        return Tools::encrypt($plain);
    }

    private function address($idChild, Customer $customer)
    {
        $a = $this->node('addresses', $idChild);
        if (!$a) {
            throw new Exception('No se pudo leer la dirección #' . (int) $idChild . ' en la tienda hija (¿permiso para «addresses»?)');
        }
        $idCountry = $this->countryId((int) $a->id_country);
        $idState = (int) $a->id_state ? $this->stateId((int) $a->id_state, $idCountry) : 0;

        $fields = array('firstname', 'lastname', 'company', 'address1', 'address2', 'postcode', 'city', 'phone', 'phone_mobile', 'vat_number', 'dni', 'other');
        $values = array();
        foreach ($fields as $field) {
            $values[$field] = trim((string) $a->{$field});
        }

        // Dirección idéntica ya guardada: se reutiliza
        $saved = (array) Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'address`
             WHERE id_customer = ' . (int) $customer->id . ' AND deleted = 0
             AND id_country = ' . (int) $idCountry . ' AND id_state = ' . (int) $idState
        );
        foreach ($saved as $row) {
            $same = true;
            foreach ($fields as $field) {
                $mine = isset($row[$field]) ? trim((string) $row[$field]) : '';
                if (Tools::strtolower($mine) !== Tools::strtolower($values[$field])) {
                    $same = false;
                    break;
                }
            }
            if ($same) {
                return new Address((int) $row['id_address']);
            }
        }

        $address = new Address();
        $address->id_customer = (int) $customer->id;
        $address->id_country = (int) $idCountry;
        $address->id_state = (int) $idState;
        $alias = trim((string) $a->alias);
        $address->alias = $alias !== '' && $address->validateField('alias', $alias) === true ? $alias : 'Mi dirección';
        foreach ($values as $field => $value) {
            if ($value === '' || !property_exists($address, $field)) {
                continue;
            }
            if ($address->validateField($field, $value) === true) {
                $address->{$field} = $value;
            } elseif (in_array($field, array('firstname', 'lastname'))) {
                $address->{$field} = self::cleanName($value);
            }
        }
        if (!$address->firstname) {
            $address->firstname = $customer->firstname;
        }
        if (!$address->lastname) {
            $address->lastname = $customer->lastname;
        }
        if (!$address->address1 || !$address->city) {
            throw new Exception('La dirección de la tienda hija está incompleta (falta calle o población)');
        }
        if (!$address->add()) {
            throw new Exception('No se pudo crear la dirección del cliente ' . $customer->email . ' en la tienda madre');
        }

        return $address;
    }

    private function countryId($idChild)
    {
        $c = $this->node('countries', $idChild);
        $iso = $c ? trim((string) $c->iso_code) : '';
        $id = $iso !== '' ? (int) Country::getByIso($iso) : 0;
        if (!$id) {
            throw new Exception('El país ' . ($iso ?: '#' . (int) $idChild) . ' no existe en la tienda madre (o la clave no tiene permiso para «countries»)');
        }

        return $id;
    }

    private function stateId($idChild, $idCountry)
    {
        try {
            $s = $this->node('states', $idChild);
        } catch (Exception $e) {
            return 0;
        }
        if (!$s) {
            return 0;
        }
        $id = 0;
        $iso = trim((string) $s->iso_code);
        if ($iso !== '') {
            $id = (int) State::getIdByIso($iso, (int) $idCountry);
        }
        if (!$id && trim((string) $s->name) !== '') {
            $id = (int) State::getIdByName(trim((string) $s->name));
            if ($id && (int) (new State($id))->id_country !== (int) $idCountry) {
                $id = 0;
            }
        }

        return $id;
    }

    private function currencyId($idChild)
    {
        try {
            $c = $this->node('currencies', $idChild);
            $iso = $c ? trim((string) $c->iso_code) : '';
            $id = $iso !== '' ? (int) Currency::getIdByIsoCode($iso) : 0;
            if ($id) {
                return $id;
            }
        } catch (Exception $e) {
        }

        return (int) Configuration::get('PS_CURRENCY_DEFAULT');
    }

    private function langId($idChild)
    {
        try {
            $l = $this->node('languages', $idChild);
            $iso = $l ? trim((string) $l->iso_code) : '';
            $id = $iso !== '' ? (int) Language::getIdByIso($iso) : 0;
            if ($id) {
                return $id;
            }
        } catch (Exception $e) {
        }

        return (int) Configuration::get('PS_LANG_DEFAULT');
    }

    /** Transportista: el que se llame igual en la madre; si no, el configurado en la tienda; si no, el gratuito de SyncroSevi */
    private function carrierId($idChild)
    {
        $db = Db::getInstance();
        try {
            $c = $this->node('carriers', $idChild);
            $name = $c ? trim((string) $c->name) : '';
            if ($name !== '') {
                $id = (int) $db->getValue(
                    'SELECT id_carrier FROM `' . _DB_PREFIX_ . 'carrier`
                     WHERE deleted = 0 AND name = "' . pSQL($name) . '"
                     ORDER BY active DESC, id_carrier DESC'
                );
                if ($id) {
                    return $id;
                }
            }
        } catch (Exception $e) {
        }
        if (!empty($this->shop['id_carrier'])) {
            $carrier = new Carrier((int) $this->shop['id_carrier']);
            if (Validate::isLoadedObject($carrier) && !$carrier->deleted) {
                return (int) $carrier->id;
            }
        }
        $free = (int) Configuration::get('SYNCROSEVI_FREE_CARRIER_ID');
        if (!$free) {
            throw new Exception('No hay transportista para el pedido: configura uno en la tienda hija');
        }

        return $free;
    }

    /* ==================== notas ==================== */

    private function note($o, array $missing, $sub, $idOrder)
    {
        $payment = trim((string) $o->payment);
        $note = 'SyncroSevi: clon del pedido ' . (string) $o->reference . ' (#' . (int) $o->id . ') de ' . $this->shop['name']
            . '. Fecha original: ' . (string) $o->date_add . '.'
            . ($payment !== '' ? ' Pago en la tienda hija: ' . $payment . '.' : '');
        if ($missing) {
            $note .= ' ATENCIÓN: pasó sin estos productos, que no existen en la madre (se usó «' . $sub['name'] . '» con el nombre y la referencia originales): ' . $this->missingText($missing) . '.';
        }

        // Comprobación: suma de las líneas frente al total de productos de la hija
        $sum = (float) Db::getInstance()->getValue('SELECT SUM(total_price_tax_incl) FROM `' . _DB_PREFIX_ . 'order_detail` WHERE id_order = ' . (int) $idOrder);
        if (abs($sum - (float) $o->total_products_wt) > 0.011) {
            $note .= ' AVISO: la suma de las líneas (' . self::money($sum) . ') no coincide con el total de productos de la hija (' . self::money($o->total_products_wt) . ').';
        }

        return $note;
    }

    private function addPrivateMessage($idOrder, $idCart, $text)
    {
        // PrestaShop 8 y 9: nota interna del pedido (se ve en la ficha del pedido)
        try {
            $db = Db::getInstance();
            if ($db->executeS('SHOW COLUMNS FROM `' . _DB_PREFIX_ . 'orders` LIKE "note"')) {
                $current = (string) $db->getValue('SELECT note FROM `' . _DB_PREFIX_ . 'orders` WHERE id_order = ' . (int) $idOrder);
                $db->update('orders', array('note' => pSQL(trim($current . "\n" . $text))), 'id_order = ' . (int) $idOrder);
            }
        } catch (Exception $e) {
        }
        try {
            $m = new Message();
            $m->id_order = (int) $idOrder;
            $m->id_cart = (int) $idCart;
            $m->id_customer = 0;
            $m->id_employee = 0;
            $m->private = 1;
            $m->message = Tools::substr(strip_tags($text), 0, 1600);
            $m->add();
        } catch (Exception $e) {
            $this->module->log('No se pudo guardar la nota en el pedido #' . (int) $idOrder . ': ' . $e->getMessage());
        }
    }

    /* ==================== estados ==================== */

    /**
     * Aplica en el pedido clonado el estado de la madre que corresponde al estado de la hija (sin correos)
     *
     * @return bool true si se ha cambiado
     */
    public function applyState($idMotherOrder, $childState, $tracking = '')
    {
        $order = new Order((int) $idMotherOrder);
        if (!Validate::isLoadedObject($order)) {
            return false;
        }
        if ($tracking !== '') {
            $current = (string) Db::getInstance()->getValue('SELECT tracking_number FROM `' . _DB_PREFIX_ . 'order_carrier` WHERE id_order = ' . (int) $order->id);
            if ($current === '') {
                Db::getInstance()->update('order_carrier', array('tracking_number' => pSQL($tracking)), 'id_order = ' . (int) $order->id);
            }
        }
        $target = $this->mappedState($childState);
        if (!$target || (int) $order->getCurrentState() === $target) {
            return false;
        }
        $history = new OrderHistory();
        $history->id_order = (int) $order->id;
        $history->id_employee = 0;
        $history->changeIdOrderState($target, (int) $order->id, true);
        $history->add();
        $this->module->log('Estado del pedido clonado #' . (int) $order->id . ' cambiado a ' . $target . ' (estado ' . (int) $childState . ' en ' . $this->shop['name'] . ')');

        return true;
    }
}
