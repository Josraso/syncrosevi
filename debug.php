<?php
require_once(dirname(__FILE__) . '/../../config/config.inc.php');

// Verificar datos en tablas
echo "=== DEBUG SYNCROSEVI ===\n";

// 1. Verificar tiendas configuradas
$shops = Db::getInstance()->executeS('SELECT * FROM ' . _DB_PREFIX_ . 'syncrosevi_child_shops');
echo "Tiendas configuradas: " . count($shops) . "\n";

// 2. Verificar pedidos pendientes
$pending = Db::getInstance()->executeS('SELECT * FROM ' . _DB_PREFIX_ . 'syncrosevi_order_lines WHERE processed = 0');
echo "Líneas pendientes: " . count($pending) . "\n";

if (count($pending) > 0) {
    echo "\nPrimeras 3 líneas pendientes:\n";
    for ($i = 0; $i < min(3, count($pending)); $i++) {
        $line = $pending[$i];
        echo "- Tienda: " . $line['id_child_shop'] . ", Pedido: " . $line['id_original_order'] . ", Referencia: '" . $line['product_reference'] . "', Cantidad: " . $line['quantity'] . "\n";
    }
}

// 3. Verificar productos en tienda madre con referencias similares
if (count($pending) > 0) {
    echo "\nVerificando productos en tienda madre:\n";
    $ref = $pending[0]['product_reference'];
    
    $products = Db::getInstance()->executeS('SELECT id_product, reference, active FROM ' . _DB_PREFIX_ . 'product WHERE reference = "' . pSQL($ref) . '"');
    echo "Productos simples con referencia '" . $ref . "': " . count($products) . "\n";
    
    $combinations = Db::getInstance()->executeS('SELECT pa.id_product_attribute, pa.id_product, pa.reference, p.active FROM ' . _DB_PREFIX_ . 'product_attribute pa JOIN ' . _DB_PREFIX_ . 'product p ON pa.id_product = p.id_product WHERE pa.reference = "' . pSQL($ref) . '"');
    echo "Combinaciones con referencia '" . $ref . "': " . count($combinations) . "\n";
}

echo "\n=== FIN DEBUG ===\n";
?>