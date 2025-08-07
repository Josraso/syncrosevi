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

class SyncroSeviWebservice
{
    protected $url;
    protected $key;
    protected $debug = false;

    public function __construct($url, $key, $debug = false)
    {
        $this->url = rtrim($url, '/') . '/';
        $this->key = $key;
        $this->debug = $debug;
    }

    /**
     * Obtener pedidos nuevos desde la tienda hija
     */
    public function getNewOrders($startOrderId = 1, $importStates = '2,3,4,5')
    {
        try {
            $statesArray = explode(',', $importStates);
            $statesArray = array_map('intval', $statesArray);
            
            if ($this->debug) {
                error_log('SyncroSevi: Buscando pedidos desde ID ' . $startOrderId . ' con estados: ' . $importStates);
            }
            
            $ordersXml = $this->get(array(
                'resource' => 'orders',
                'display' => 'full',
                'sort' => 'id_DESC',
                'limit' => '100'
            ));
            
            if (!$ordersXml || !isset($ordersXml->orders)) {
                if ($this->debug) {
                    error_log('SyncroSevi: No se encontraron pedidos o respuesta XML inválida');
                }
                return array();
            }

            $orders = array();
            
            foreach ($ordersXml->orders->order as $orderXml) {
                $orderId = (int)$orderXml->id;
                $currentState = (int)$orderXml->current_state;
                
                if ($orderId < $startOrderId) {
                    if ($this->debug) {
                        error_log('SyncroSevi: Pedido #' . $orderId . ' omitido (menor que ' . $startOrderId . ')');
                    }
                    continue;
                }
                
                if (!in_array($currentState, $statesArray)) {
                    if ($this->debug) {
                        error_log('SyncroSevi: Pedido #' . $orderId . ' omitido (estado ' . $currentState . ' no permitido)');
                    }
                    continue;
                }
                
                if ($this->debug) {
                    error_log('SyncroSevi: Procesando pedido #' . $orderId . ' (estado: ' . $currentState . ')');
                }
                
                $order = array(
                    'id' => $orderId,
                    'reference' => (string)$orderXml->reference,
                    'current_state' => $currentState,
                    'date_add' => (string)$orderXml->date_add,
                    'order_rows' => array()
                );

                try {
                    $orderDetailsXml = $this->get(array(
                        'resource' => 'order_details',
                        'filter' => array('id_order' => $orderId),
                        'display' => 'full'
                    ));

                    if ($orderDetailsXml && isset($orderDetailsXml->order_details)) {
                        $details = $orderDetailsXml->order_details->order_detail;
                        
                        if (!is_array($details) && !($details instanceof Traversable)) {
                            $details = array($details);
                        }
                        
                        foreach ($details as $detailXml) {
                            $order['order_rows'][] = array(
                                'product_id' => (int)$detailXml->product_id,
                                'product_attribute_id' => (int)$detailXml->product_attribute_id,
                                'product_quantity' => (int)$detailXml->product_quantity,
                                'product_reference' => (string)$detailXml->product_reference,
                                'product_name' => (string)$detailXml->product_name
                            );
                        }
                    }
                } catch (Exception $e) {
                    if ($this->debug) {
                        error_log('SyncroSevi: Error obteniendo detalles del pedido #' . $orderId . ': ' . $e->getMessage());
                    }
                    continue;
                }

                $orders[] = $order;
            }

            if ($this->debug) {
                error_log('SyncroSevi: Total de pedidos procesados: ' . count($orders));
            }

            return $orders;
            
        } catch (Exception $e) {
            if ($this->debug) {
                error_log('SyncroSevi WebService Error: ' . $e->getMessage());
            }
            throw $e;
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
        error_log('SyncroSevi: GET Request URL: ' . $url);
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
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'SyncroSevi/1.0'
        );

        $curl = curl_init($url);
        curl_setopt_array($curl, $defaultOptions);
        curl_setopt_array($curl, $curlOptions);

        $response = curl_exec($curl);
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
            error_log('SyncroSevi: HTTP Code: ' . $httpCode);
            error_log('SyncroSevi: Response Body: ' . substr($body, 0, 500) . '...');
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
            error_log('SyncroSevi: Test connection failed: ' . $e->getMessage());
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
                error_log('SyncroSevi: Get shop info failed: ' . $e->getMessage());
            }
            return array();
        }
    }
}