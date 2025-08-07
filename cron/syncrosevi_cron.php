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

/**
 * Archivo CORREGIDO para ejecutar las tareas programadas de SyncroSevi
 * 
 * CRONS SEPARADOS:
 * 
 * Para SOLO sincronizar pedidos (cada 15 minutos):
 * */15 * * * * /usr/bin/php /ruta/a/prestashop/modules/syncrosevi/cron/syncrosevi_cron.php sync
 * 
 * Para SOLO procesar pedidos (cada hora):
 * 0 * * * * /usr/bin/php /ruta/a/prestashop/modules/syncrosevi/cron/syncrosevi_cron.php process
 * 
 * Para obtener estadísticas:
 * 0 8 * * * /usr/bin/php /ruta/a/prestashop/modules/syncrosevi/cron/syncrosevi_cron.php stats
 * 
 * Para verificar salud de tiendas:
 * 0 9 * * 1 /usr/bin/php /ruta/a/prestashop/modules/syncrosevi/cron/syncrosevi_cron.php health
 */

// Configuración
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Determinar la ruta de PrestaShop
$prestashop_root = realpath(dirname(__FILE__) . '/../../../');
if (!$prestashop_root || !file_exists($prestashop_root . '/config/config.inc.php')) {
    die("Error: No se puede encontrar la instalación de PrestaShop\n");
}

// Incluir archivos de PrestaShop
require_once($prestashop_root . '/config/config.inc.php');
require_once($prestashop_root . '/init.php');

// Verificar que el módulo esté instalado
if (!Module::isInstalled('syncrosevi')) {
    die("Error: El módulo SyncroSevi no está instalado\n");
}

/**
 * Clase para gestionar las tareas cron de SyncroSevi
 */
class SyncroSeviCron
{
    private $module;
    private $logFile;
    
    public function __construct()
    {
        $this->module = Module::getInstanceByName('syncrosevi');
        if (!$this->module) {
            die("Error: No se puede cargar el módulo SyncroSevi\n");
        }
        
        $this->ensureLogDirectory();
    }
    
    /**
     * Ejecutar SOLO sincronización de pedidos
     */
    public function syncOrders()
    {
        $this->log("=== INICIANDO SINCRONIZACIÓN DE PEDIDOS ===");
        
        try {
            $results = $this->module->syncOrders();
            
            $totalSynced = 0;
            $errors = [];
            
            foreach ($results as $result) {
                if ($result['status'] == 'success') {
                    $totalSynced += $result['count'];
                    $this->log("✓ " . $result['shop'] . ": " . $result['count'] . " pedidos sincronizados");
                } else {
                    $errors[] = $result['shop'] . ": " . $result['message'];
                    $this->log("✗ " . $result['shop'] . ": Error - " . $result['message']);
                }
            }
            
            $this->log("=== SINCRONIZACIÓN COMPLETADA ===");
            $this->log("Total sincronizados: {$totalSynced} pedidos");
            
            if (!empty($errors)) {
                $this->log("Errores encontrados: " . count($errors));
                foreach ($errors as $error) {
                    $this->log("ERROR: " . $error);
                }
                return false;
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->log("ERROR CRÍTICO en sincronización: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Ejecutar SOLO procesamiento de pedidos
     */
    public function processOrders()
    {
        $this->log("=== INICIANDO PROCESAMIENTO DE PEDIDOS ===");
        
        try {
            $results = $this->module->processOrders();
            
            $totalProcessed = 0;
            $errors = [];
            
            foreach ($results as $result) {
                if ($result['status'] == 'success') {
                    $totalProcessed++;
                    if (isset($result['order_id'])) {
                        $this->log("✓ " . $result['shop'] . ": Pedido #{$result['order_id']} creado con {$result['products_count']} productos");
                    } else {
                        $this->log("✓ " . $result['shop'] . ": " . ($result['message'] ?: 'Procesado correctamente'));
                    }
                } else {
                    $errors[] = $result['shop'] . ": " . $result['message'];
                    $this->log("✗ " . $result['shop'] . ": Error - " . $result['message']);
                }
            }
            
            $this->log("=== PROCESAMIENTO COMPLETADO ===");
            $this->log("Total procesados: {$totalProcessed} pedidos");
            
            if (!empty($errors)) {
                $this->log("Errores encontrados: " . count($errors));
                foreach ($errors as $error) {
                    $this->log("ERROR: " . $error);
                }
                return false;
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->log("ERROR CRÍTICO en procesamiento: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obtener estadísticas del sistema
     */
    public function getStats()
    {
        $this->log("=== OBTENIENDO ESTADÍSTICAS ===");
        
        try {
            $stats = [
                'total_shops' => (int)Db::getInstance()->getValue(
                    'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'syncrosevi_child_shops`'
                ),
                'active_shops' => (int)Db::getInstance()->getValue(
                    'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'syncrosevi_child_shops` WHERE active = 1'
                ),
                'pending_orders' => (int)Db::getInstance()->getValue(
                    'SELECT COUNT(DISTINCT id_child_shop, id_original_order) FROM `' . _DB_PREFIX_ . 'syncrosevi_order_lines` WHERE processed = 0'
                ),
                'processed_today' => (int)Db::getInstance()->getValue(
                    'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'syncrosevi_order_tracking` WHERE processed = 1 AND DATE(date_processed) = CURDATE()'
                ),
                'processed_total' => (int)Db::getInstance()->getValue(
                    'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'syncrosevi_order_tracking` WHERE processed = 1'
                )
            ];
            
            $this->log("=== ESTADÍSTICAS ===");
            $this->log("Tiendas configuradas: {$stats['total_shops']} ({$stats['active_shops']} activas)");
            $this->log("Pedidos pendientes: {$stats['pending_orders']}");
            $this->log("Procesados hoy: {$stats['processed_today']}");
            $this->log("Procesados total: {$stats['processed_total']}");
            
            return $stats;
            
        } catch (Exception $e) {
            $this->log("ERROR obteniendo estadísticas: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Verificar estado de las tiendas hijas
     */
    public function checkShopsHealth()
    {
        $this->log("=== VERIFICANDO SALUD DE TIENDAS ===");
        
        try {
            $shops = Db::getInstance()->executeS(
                'SELECT * FROM `' . _DB_PREFIX_ . 'syncrosevi_child_shops` WHERE active = 1'
            );
            
            $healthy = 0;
            $unhealthy = 0;
            
            foreach ($shops as $shop) {
                try {
                    require_once(dirname(__FILE__) . '/../classes/SyncroSeviWebservice.php');
                    $webservice = new SyncroSeviWebservice($shop['url'], $shop['api_key']);
                    
                    if ($webservice->testConnection()) {
                        $healthy++;
                        $this->log("✓ {$shop['name']}: Conexión OK");
                    } else {
                        $unhealthy++;
                        $this->log("✗ {$shop['name']}: Sin conexión");
                    }
                    
                } catch (Exception $e) {
                    $unhealthy++;
                    $this->log("✗ {$shop['name']}: Error - " . $e->getMessage());
                }
            }
            
            $this->log("=== VERIFICACIÓN COMPLETADA ===");
            $this->log("Tiendas saludables: {$healthy}");
            $this->log("Tiendas con problemas: {$unhealthy}");
            
            return ['healthy' => $healthy, 'unhealthy' => $unhealthy];
            
        } catch (Exception $e) {
            $this->log("ERROR en verificación de salud: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Escribir en el archivo de log
     */
    private function log($message)
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}" . PHP_EOL;
        
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
        
        if (php_sapi_name() === 'cli') {
            echo $logMessage;
        }
    }
    
    /**
     * Asegurar que existe el directorio de logs
     */
    private function ensureLogDirectory()
    {
        $logDir = dirname(__FILE__) . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $dateStr = date('Y-m-d');
        $this->logFile = $logDir . "/syncrosevi_cron_{$dateStr}.log";
    }
}

/**
 * Función principal
 */
function main($args)
{
    if (empty($args[1])) {
        echo "Uso: php syncrosevi_cron.php [sync|process|stats|health]\n";
        echo "\n";
        echo "Comandos disponibles:\n";
        echo "  sync    - SOLO sincronizar pedidos desde tiendas hijas\n";
        echo "  process - SOLO procesar pedidos pendientes\n";
        echo "  stats   - Mostrar estadísticas del sistema\n";
        echo "  health  - Verificar estado de conexión de tiendas\n";
        echo "\n";
        echo "EJEMPLO DE CRONS SEPARADOS:\n";
        echo "*/15 * * * * php /ruta/syncrosevi_cron.php sync\n";
        echo "0 * * * * php /ruta/syncrosevi_cron.php process\n";
        exit(1);
    }
    
    $cron = new SyncroSeviCron();
    $command = $args[1];
    $success = true;
    
    switch ($command) {
        case 'sync':
            $success = $cron->syncOrders();
            break;
            
        case 'process':
            $success = $cron->processOrders();
            break;
            
        case 'stats':
            $stats = $cron->getStats();
            $success = ($stats !== false);
            break;
            
        case 'health':
            $health = $cron->checkShopsHealth();
            $success = ($health !== false);
            break;
            
        default:
            echo "Error: Comando desconocido '{$command}'\n";
            echo "Usa: sync, process, stats o health\n";
            exit(1);
    }
    
    exit($success ? 0 : 1);
}

// Ejecutar solo si se llama directamente desde línea de comandos
if (php_sapi_name() === 'cli') {
    main($argv);
}