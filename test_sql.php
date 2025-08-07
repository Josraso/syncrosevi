<?php
require_once(dirname(__FILE__) . '/../../config/config.inc.php');

$reference = '34-06344';

echo "Probando búsqueda para: " . $reference . "\n";

// Test 1: Combinación
$sql1 = 'SELECT pa.id_product_attribute, pa.id_product FROM ' . _DB_PREFIX_ . 'product_attribute pa JOIN ' . _DB_PREFIX_ . 'product p ON pa.id_product = p.id_product WHERE pa.reference = "' . pSQL($reference) . '" AND p.active = 1 LIMIT 1';
echo "SQL1: " . $sql1 . "\n";

try {
    $result1 = Db::getInstance()->getRow($sql1);
    echo "Resultado combinación: " . json_encode($result1) . "\n";
} catch (Exception $e) {
    echo "Error en combinación: " . $e->getMessage() . "\n";
}

// Test 2: Producto simple
$sql2 = 'SELECT id_product FROM ' . _DB_PREFIX_ . 'product WHERE reference = "' . pSQL($reference) . '" AND active = 1 LIMIT 1';
echo "SQL2: " . $sql2 . "\n";

try {
    $result2 = Db::getInstance()->getValue($sql2);
    echo "Resultado producto: " . $result2 . "\n";
} catch (Exception $e) {
    echo "Error en producto: " . $e->getMessage() . "\n";
}
?>