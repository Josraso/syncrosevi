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

class SyncroSeviWebservice
{
    protected $url;
    protected $key;
    protected $debug = false;

    /** Tiempo máximo de cada petición (segundos) */
    public $timeout = 30;

    public function __construct($url, $key, $debug = false)
    {
        $this->url = rtrim($url, '/') . '/';
        $this->key = $key;
        $this->debug = $debug;
    }

    /** true si en la última sincronización quedaron pedidos nuevos por descargar (se bajan en la siguiente) */
    public $hasMore = false;

    /** Pedidos nuevos como máximo en cada sincronización (evita timeouts) */
    const MAX_ORDERS_PER_SYNC = 1000;

    /** Pedidos completos pedidos en cada llamada al WebService */
    const CHUNK_SIZE = 25;

    /**
     * Obtener pedidos nuevos desde la tienda hija.
     *
     * 1) Se pide un listado ligero (solo id y estado) de los pedidos desde $startOrderId en los estados a importar.
     * 2) Se descartan los que ya están sincronizados ($skipIds).
     * 3) Solo de los nuevos se descarga el detalle, en bloques.
     *
     * @param int $startOrderId
     * @param string $importStates estados separados por coma
     * @param array $skipIds IDs de pedido que ya están sincronizados
     */
    public function getNewOrders($startOrderId = 1, $importStates = '2,3,4,5', $skipIds = array())
    {
        $this->hasMore = false;
        $states = $this->statesArray($importStates);
        if (empty($states)) {
            return array();
        }
        $startOrderId = max(1, (int) $startOrderId);
        $skip = array_flip(array_map('intval', (array) $skipIds));

        $newIds = array();
        $offset = 0;
        $page = 1000;
        do {
            $xml = $this->get(array(
                'resource' => 'orders',
                'display' => '[id,current_state]',
                'sort' => '[id_ASC]',
                'filter' => array(
                    'id' => '[' . $startOrderId . ',999999999]',
                    'current_state' => '[' . implode('|', $states) . ']',
                ),
                'limit' => $offset . ',' . $page,
            ));
            $count = 0;
            if ($xml && isset($xml->orders->order)) {
                foreach ($xml->orders->order as $orderXml) {
                    ++$count;
                    $id = (int) $orderXml->id;
                    if ($id < $startOrderId || isset($skip[$id]) || !in_array((int) $orderXml->current_state, $states)) {
                        continue;
                    }
                    $newIds[] = $id;
                }
            }
            $offset += $page;
        } while ($count >= $page && count($newIds) <= self::MAX_ORDERS_PER_SYNC);

        if (count($newIds) > self::MAX_ORDERS_PER_SYNC) {
            $this->hasMore = true;
            $newIds = array_slice($newIds, 0, self::MAX_ORDERS_PER_SYNC);
        }

        if ($this->debug) {
            $this->debugLog('Pedidos nuevos encontrados desde #' . $startOrderId . ': ' . count($newIds));
        }

        return $this->getOrdersByIds($newIds, $importStates);
    }

    /**
     * Descargar pedidos concretos con sus líneas (solo los que estén en un estado a importar)
     */
    public function getOrdersByIds(array $ids, $importStates = '2,3,4,5')
    {
        $states = $this->statesArray($importStates);
        $ids = array_values(array_filter(array_map('intval', $ids)));
        $orders = array();

        foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
            $xml = $this->get(array(
                'resource' => 'orders',
                'display' => 'full',
                'sort' => '[id_ASC]',
                'filter' => array('id' => '[' . implode('|', $chunk) . ']'),
            ));
            if (!$xml || !isset($xml->orders->order)) {
                continue;
            }

            foreach ($xml->orders->order as $orderXml) {
                $orderId = (int) $orderXml->id;
                $currentState = (int) $orderXml->current_state;
                if (!in_array($currentState, $states)) {
                    continue;
                }

                $order = array(
                    'id' => $orderId,
                    'reference' => (string) $orderXml->reference,
                    'current_state' => $currentState,
                    'date_add' => (string) $orderXml->date_add,
                    'order_rows' => array(),
                );

                // Las líneas vienen ya en el propio pedido (associations/order_rows)
                if (isset($orderXml->associations->order_rows->order_row)) {
                    foreach ($orderXml->associations->order_rows->order_row as $row) {
                        if (!isset($row->product_reference)) {
                            $order['order_rows'] = array();
                            break; // formato inesperado: se piden las líneas aparte
                        }
                        $order['order_rows'][] = array(
                            'product_id' => (int) $row->product_id,
                            'product_attribute_id' => (int) $row->product_attribute_id,
                            'product_quantity' => (int) $row->product_quantity,
                            'product_reference' => (string) $row->product_reference,
                            'product_name' => (string) $row->product_name,
                        );
                    }
                }

                if (empty($order['order_rows'])) {
                    try {
                        $order['order_rows'] = $this->getOrderDetails($orderId);
                    } catch (Exception $e) {
                        if ($this->debug) {
                            $this->debugLog('Error obteniendo detalles del pedido #' . $orderId . ': ' . $e->getMessage());
                        }
                        continue; // se reintenta en la siguiente sincronización
                    }
                }

                if (!empty($order['order_rows'])) {
                    $orders[] = $order;
                }
            }
        }

        return $orders;
    }

    /**
     * Líneas de un pedido desde el recurso order_details
     */
    public function getOrderDetails($orderId)
    {
        $rows = array();
        $xml = $this->get(array(
            'resource' => 'order_details',
            'filter' => array('id_order' => '[' . (int) $orderId . ']'),
            'display' => 'full',
        ));
        if ($xml && isset($xml->order_details->order_detail)) {
            foreach ($xml->order_details->order_detail as $detailXml) {
                $rows[] = array(
                    'product_id' => (int) $detailXml->product_id,
                    'product_attribute_id' => (int) $detailXml->product_attribute_id,
                    'product_quantity' => (int) $detailXml->product_quantity,
                    'product_reference' => (string) $detailXml->product_reference,
                    'product_name' => (string) $detailXml->product_name,
                );
            }
        }

        return $rows;
    }

    /**
     * ======================================================================
     * MODO CLONAR: lecturas de un solo recurso
     * ======================================================================
     */

    /** Un recurso por ID (orders, customers, addresses...) como nodo XML, o null si no existe */
    public function getOne($resource, $id)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        $xml = $this->get(array('resource' => $resource, 'id' => $id));
        if ($xml) {
            foreach ($xml->children() as $child) {
                return $child;
            }
        }

        return null;
    }

    /** Lista de un recurso filtrada (display=full) como array de nodos XML */
    public function getList($resource, array $filter, $limit = null)
    {
        $options = array('resource' => $resource, 'display' => 'full', 'filter' => $filter);
        if ($limit) {
            $options['limit'] = $limit;
        }
        $xml = $this->get($options);
        $out = array();
        if ($xml) {
            foreach ($xml->children() as $list) {
                foreach ($list->children() as $item) {
                    $out[] = $item;
                }
            }
        }

        return $out;
    }

    /** Líneas completas de un pedido (precios, totales e impuestos) */
    public function getOrderDetailsFull($orderId)
    {
        $rows = array();
        foreach ($this->getList('order_details', array('id_order' => '[' . (int) $orderId . ']')) as $d) {
            $taxIds = array();
            if (isset($d->associations->taxes->tax)) {
                foreach ($d->associations->taxes->tax as $t) {
                    if ((int) $t->id) {
                        $taxIds[] = (int) $t->id;
                    }
                }
            }
            $row = array('id' => (int) $d->id, 'tax_ids' => $taxIds);
            foreach (array('product_id', 'product_attribute_id', 'product_quantity') as $f) {
                $row[$f] = (int) $d->{$f};
            }
            foreach (array('product_name', 'product_reference', 'product_ean13', 'product_isbn', 'product_upc', 'product_supplier_reference') as $f) {
                $row[$f] = isset($d->{$f}) ? (string) $d->{$f} : '';
            }
            foreach (array('product_price', 'reduction_percent', 'reduction_amount', 'reduction_amount_tax_incl', 'reduction_amount_tax_excl',
                'group_reduction', 'ecotax', 'unit_price_tax_incl', 'unit_price_tax_excl', 'total_price_tax_incl', 'total_price_tax_excl',
                'original_product_price', 'product_weight') as $f) {
                $row[$f] = isset($d->{$f}) ? (float) $d->{$f} : 0.0;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Cambia el estado de un pedido de la hija (POST order_histories).
     * Con $sendEmail la hija envía al cliente el correo de ese estado, como si lo cambiaras a mano allí.
     */
    public function addOrderHistory($idOrder, $idState, $sendEmail = false)
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<prestashop xmlns:xlink="http://www.w3.org/1999/xlink"><order_history>'
            . '<id_order>' . (int) $idOrder . '</id_order>'
            . '<id_order_state>' . (int) $idState . '</id_order_state>'
            . '</order_history></prestashop>';
        $url = $this->url . 'api/order_histories' . ($sendEmail ? '?sendemail=1' : '');
        if ($this->debug) {
            $this->debugLog('POST ' . $url . ' (pedido ' . (int) $idOrder . ' -> estado ' . (int) $idState . ')');
        }
        $request = $this->executeRequest($url, array(
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $xml,
        ));
        $this->checkStatusCode($request);

        return true;
    }

    /** Estado actual (y número de seguimiento) de varios pedidos: array(id => array('state' => int, 'tracking' => string)) */
    public function getOrderStates(array $ids)
    {
        $out = array();
        $ids = array_values(array_filter(array_map('intval', $ids)));
        foreach (array_chunk($ids, 100) as $chunk) {
            $query = array(
                'resource' => 'orders',
                'display' => '[id,current_state,shipping_number]',
                'filter' => array('id' => '[' . implode('|', $chunk) . ']'),
            );
            try {
                $xml = $this->get($query);
            } catch (Exception $e) {
                $query['display'] = '[id,current_state]'; // versiones sin shipping_number
                $xml = $this->get($query);
            }
            if ($xml && isset($xml->orders->order)) {
                foreach ($xml->orders->order as $o) {
                    $out[(int) $o->id] = array('state' => (int) $o->current_state, 'tracking' => trim((string) $o->shipping_number));
                }
            }
        }

        return $out;
    }

    private function statesArray($importStates)
    {
        return array_values(array_filter(array_map('intval', explode(',', (string) $importStates))));
    }

    private function debugLog($message)
    {
        $module = Module::getInstanceByName('syncrosevi');
        if ($module && method_exists($module, 'log')) {
            $module->log('WS ' . $this->url . ': ' . $message, true);
        }
    }

    /**
     * Realizar petición GET al WebService
     */
    public function get($options)
{
    if (isset($options['url'])) {
        $url = $options['url'];
    } elseif (isset($options['resource'])) {
        $url = $this->url . 'api/' . $options['resource'];
        
        $params = array();
        if (isset($options['id'])) {
            $url .= '/' . $options['id'];
        }
        if (isset($options['display'])) {
            $params['display'] = $options['display'];
        }
        if (isset($options['filter'])) {
            foreach ($options['filter'] as $key => $value) {
                $params['filter[' . $key . ']'] = $value;
            }
        }
        if (isset($options['sort'])) {
            $params['sort'] = $options['sort'];
        }
        if (isset($options['limit'])) {
            $params['limit'] = $options['limit'];
        }
        
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
    } else {
        throw new Exception('Invalid options for GET request');
    }

    if ($this->debug) {
        $this->debugLog('GET ' . $url);
    }

    $request = $this->executeRequest($url, array(
        CURLOPT_CUSTOMREQUEST => 'GET'
    ));

    $this->checkStatusCode($request);
    return $this->parseXML($request['response']);
}
    /**
     * Ejecutar petición HTTP
     */
    public function executeRequest($url, $curlOptions = array())
    {
        $defaultOptions = array(
            CURLOPT_HEADER => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->key . ':',
            CURLOPT_HTTPHEADER => array(
                'Output-Format: XML',
                'Content-Type: application/xml'
            ),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => (int) $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, (int) $this->timeout),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'SyncroSevi/1.1'
        );

        $curl = curl_init($url);
        curl_setopt_array($curl, $defaultOptions);
        curl_setopt_array($curl, $curlOptions);

        $response = curl_exec($curl);

        // Si no llega ni a conectar (tiempo agotado o conexión fallida), un reintento por IPv4 y con más margen:
        // cubre servidores con IPv6 mal configurado y cortes momentáneos de la tienda hija
        if ($response === false && in_array(curl_errno($curl), array(7, 28)) && !curl_getinfo($curl, CURLINFO_CONNECT_TIME)) {
            $firstError = curl_error($curl);
            curl_close($curl);
            if ($this->debug) {
                $this->debugLog('Reintento por IPv4 tras: ' . $firstError);
            }
            $curl = curl_init($url);
            curl_setopt_array($curl, $defaultOptions);
            curl_setopt_array($curl, $curlOptions);
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 20);
            if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
                curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            }
            $response = curl_exec($curl);
            if ($response === false) {
                $error = curl_error($curl);
                curl_close($curl);
                throw new Exception('CURL Error: ' . $error . ' (también tras reintentar: la tienda hija no acepta conexiones desde este servidor ahora mismo)');
            }
        }

        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        
        if ($response === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new Exception('CURL Error: ' . $error);
        }

        curl_close($curl);

        $header = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        if ($this->debug) {
            $this->debugLog('HTTP ' . $httpCode . ' (' . strlen($body) . ' bytes)');
        }

        return array(
            'status_code' => $httpCode,
            'response' => $body,
            'header' => $header
        );
    }

    /**
     * Verificar código de estado HTTP
     */
    public function checkStatusCode($request)
    {
        $statusCode = $request['status_code'];
        
        if ($statusCode < 200 || $statusCode >= 300) {
            $errorMsg = 'HTTP Error ' . $statusCode;
            
            try {
                $xml = simplexml_load_string($request['response']);
                if ($xml && isset($xml->errors)) {
                    $errorMsg .= ': ' . (string)$xml->errors->error->message;
                }
            } catch (Exception $e) {
                $errorMsg .= '. Response: ' . substr($request['response'], 0, 200);
            }
            
            throw new Exception($errorMsg);
        }
    }

    /**
     * Parsear respuesta XML
     */
    public function parseXML($response)
    {
        $response = trim($response);
        
        if (strpos($response, '<?xml') !== 0) {
            $xmlStart = strpos($response, '<?xml');
            if ($xmlStart === false) {
                throw new Exception('La respuesta no contiene XML válido. Respuesta recibida: ' . substr($response, 0, 200));
            }
            $response = substr($response, $xmlStart);
        }
        
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response);
        
        if ($xml === false) {
            $errors = libxml_get_errors();
            $errorMsg = 'XML Parse Error';
            if (!empty($errors)) {
                $errorMsg .= ': ' . $errors[0]->message;
            }
            $errorMsg .= '. Respuesta: ' . substr($response, 0, 500);
            throw new Exception($errorMsg);
        }
        
        return $xml;
    }

    /**
     * Verificar conexión con la tienda
     */
public function testConnection()
{
    try {
        // Probar con productos primero
        $xml = $this->get(array('resource' => 'products', 'display' => '[id]', 'limit' => '1'));
        if ($xml && isset($xml->products)) {
            return true;
        }
        
        // Si productos falla, probar con categorías
        $xml = $this->get(array('resource' => 'categories', 'display' => '[id]', 'limit' => '1'));
        if ($xml && isset($xml->categories)) {
            return true;
        }
        
        return false;
        
    } catch (Exception $e) {
        if ($this->debug) {
            $this->debugLog('Test de conexión fallido: ' . $e->getMessage());
        }
        return false;
    }
}

    /**
     * Obtener información de la tienda
     */
    public function getShopInfo()
    {
        try {
            $xml = $this->get(array('resource' => 'shops', 'display' => 'full'));
            if ($xml && isset($xml->shops->shop)) {
                $shop = $xml->shops->shop[0];
                return array(
                    'name' => (string)$shop->name,
                    'domain' => (string)$shop->domain,
                    'theme_name' => (string)$shop->theme_name
                );
            }
            return array();
        } catch (Exception $e) {
            if ($this->debug) {
                $this->debugLog('Error obteniendo datos de la tienda: ' . $e->getMessage());
            }
            return array();
        }
    }
}