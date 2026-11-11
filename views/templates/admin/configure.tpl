{*
* 2024 SyncroSevi - Template de Configuración Inicial
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
*}

<div class="panel">
    <div class="panel-heading">
        <i class="icon-exchange"></i>
        SyncroSevi - Sincronización de Pedidos
        <span class="badge badge-success pull-right">v{$module_version}</span>
    </div>
    <div class="panel-body">
        
        {* Estado de instalación *}
        <div class="alert alert-success">
            <h4><i class="icon-check-circle"></i> ¡Módulo instalado correctamente!</h4>
            <p>SyncroSevi se ha instalado y configurado exitosamente en tu tienda PrestaShop.</p>
        </div>
        
        {* Acceso rápido al panel principal *}
        <div class="row">
            <div class="col-lg-12 text-center">
                <div class="well">
                    <h3>Panel de Administración</h3>
                    <p>Para configurar las tiendas hijas y gestionar la sincronización, accede al panel principal:</p>
                    <a href="{$admin_url}" class="btn btn-primary btn-lg">
                        <i class="icon-cogs"></i> Ir a Configuración Principal
                    </a>
                </div>
            </div>
        </div>
        
        {* Funcionalidades principales *}
        <div class="row">
            <div class="col-lg-6">
                <div class="panel panel-info">
                    <div class="panel-heading">
                        <h4 class="panel-title"><i class="icon-star"></i> Funcionalidades Principales</h4>
                    </div>
                    <div class="panel-body">
                        <ul class="list-unstyled">
                            <li><i class="icon-check text-success"></i> syncrosevi_child_shops</li>
                            <li><i class="icon-check text-success"></i> syncrosevi_order_tracking</li>
                            <li><i class="icon-check text-success"></i> syncrosevi_order_lines</li>
                        </ul>
                        <small class="text-muted">Todas las tablas se han creado correctamente</small>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4">
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h4 class="panel-title"><i class="icon-folder"></i> Archivos y Permisos</h4>
                    </div>
                    <div class="panel-body">
                        <ul class="list-unstyled">
                            <li><i class="icon-check text-success"></i> Directorio /logs/ creado</li>
                            <li><i class="icon-check text-success"></i> Permisos de escritura OK</li>
                            <li><i class="icon-check text-success"></i> Token webhook generado</li>
                            <li><i class="icon-check text-success"></i> Archivos .htaccess creados</li>
                        </ul>
                        <small class="text-muted">Sistema listo para funcionar</small>
                    </div>
                </div>
            </div>
        </div>
        
        {* Configuración rápida de WebService *}
        <div class="panel panel-info">
            <div class="panel-heading">
                <h4 class="panel-title"><i class="icon-globe"></i> Configuración de WebService en Tiendas Hijas</h4>
            </div>
            <div class="panel-body">
                <div class="row">
                    <div class="col-lg-6">
                        <h5>1. Activar WebService</h5>
                        <div class="well well-sm">
                            <strong>Ruta:</strong> Parámetros Avanzados > Webservice<br>
                            <strong>Configuración:</strong> Activar el servicio web de PrestaShop = <span class="text-success">SÍ</span>
                        </div>
                        
                        <h5>2. Crear Clave API</h5>
                        <div class="well well-sm">
                            <strong>Ruta:</strong> Parámetros Avanzados > Webservice > Gestión de claves<br>
                            <strong>Acción:</strong> Añadir nueva clave (32 caracteres)
                        </div>
                    </div>
                    
                    <div class="col-lg-6">
                        <h5>3. Configurar Permisos</h5>
                        <div class="well well-sm">
                            <strong>Recursos necesarios:</strong>
                            <ul class="list-unstyled" style="margin-top: 10px;">
                                <li><code>orders</code> → GET (Ver)</li>
                                <li><code>order_details</code> → GET (Ver)</li>
                                <li><code>order_states</code> → GET (Ver)</li>
                                <li><code>products</code> → GET (Ver) <em>(opcional)</em></li>
                                <li><code>shops</code> → GET (Ver) <em>(opcional)</em></li>
                            </ul>
                        </div>
                        
                        <h5>4. Probar Conexión</h5>
                        <div class="well well-sm">
                            <code>curl -u "API_KEY:" "https://tienda.com/api/shops"</code>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        {* URLs útiles *}
        <div class="panel panel-default">
            <div class="panel-heading">
                <h4 class="panel-title"><i class="icon-link"></i> URLs Útiles del Sistema</h4>
            </div>
            <div class="panel-body">
                <div class="row">
                    <div class="col-lg-6">
                        <h5>Panel de Administración:</h5>
                        <div class="input-group">
                            <input type="text" class="form-control" value="{$admin_url}" readonly>
                            <span class="input-group-btn">
                                <a href="{$admin_url}" class="btn btn-primary" target="_blank">
                                    <i class="icon-external-link"></i>
                                </a>
                            </span>
                        </div>
                    </div>
                    
                    <div class="col-lg-6">
                        <h5>Webhook de Test:</h5>
                        <div class="input-group">
                            <input type="text" class="form-control" value="{$webhook_test_url}" readonly>
                            <span class="input-group-btn">
                                <button class="btn btn-info" onclick="testWebhook()" title="Probar Webhook">
                                    <i class="icon-play"></i>
                                </button>
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="alert alert-info" style="margin-top: 15px;">
                    <i class="icon-info-circle"></i>
                    <strong>Nota:</strong> Las URLs de webhook se generan automáticamente y se pueden encontrar en el panel principal una vez configuradas las tiendas.
                </div>
            </div>
        </div>
        
        {* Ejemplo de configuración cron *}
        <div class="panel panel-success">
            <div class="panel-heading">
                <h4 class="panel-title"><i class="icon-clock-o"></i> Configuración de Tareas Cron (Recomendado)</h4>
            </div>
            <div class="panel-body">
                <p>Para automatizar la sincronización, configura estas tareas cron en tu servidor:</p>
                
                <div class="row">
                    <div class="col-lg-6">
                        <h5>Sincronización (cada 15 minutos):</h5>
                        <pre><code>*/15 * * * * /usr/bin/php {$cron_sync_path}</code></pre>
                        
                        <h5>Procesamiento (cada hora):</h5>
                        <pre><code>0 * * * * /usr/bin/php {$cron_process_path}</code></pre>
                    </div>
                    
                    <div class="col-lg-6">
                        <h5>Estadísticas (diarias):</h5>
                        <pre><code>0 8 * * * /usr/bin/php {$cron_stats_path}</code></pre>
                        
                        <h5>Verificación de salud (semanal):</h5>
                        <pre><code>0 9 * * 1 /usr/bin/php {$cron_health_path}</code></pre>
                    </div>
                </div>
                
                <div class="alert alert-warning">
                    <i class="icon-exclamation-triangle"></i>
                    <strong>Importante:</strong> Reemplaza las rutas con las rutas reales de tu servidor. Los archivos cron se encuentran en <code>/modules/syncrosevi/cron/</code>
                </div>
            </div>
        </div>
        
        {* Soporte y documentación *}
        <div class="panel panel-default">
            <div class="panel-heading">
                <h4 class="panel-title"><i class="icon-question-circle"></i> Soporte y Documentación</h4>
            </div>
            <div class="panel-body">
                <div class="row">
                    <div class="col-lg-4 text-center">
                        <h5><i class="icon-book"></i> Documentación</h5>
                        <p>Consulta el archivo <code>README.md</code> para documentación completa del módulo.</p>
                    </div>
                    
                    <div class="col-lg-4 text-center">
                        <h5><i class="icon-bug"></i> Debugging</h5>
                        <p>Activa el modo debug en el código para obtener logs detallados en <code>/logs/</code></p>
                    </div>
                    
                    <div class="col-lg-4 text-center">
                        <h5><i class="icon-support"></i> Soporte</h5>
                        <p>Para soporte técnico, incluye siempre los logs y la configuración de tus tiendas.</p>
                    </div>
                </div>
            </div>
        </div>
        
        {* Botón final de acceso *}
        <div class="text-center" style="margin-top: 30px;">
            <a href="{$admin_url}" class="btn btn-primary btn-lg">
                <i class="icon-arrow-right"></i> Comenzar a Configurar Tiendas
            </a>
        </div>
    </div>
</div>

{* JavaScript para funciones adicionales *}
<script>
function testWebhook() {
    var url = '{$webhook_test_url}';
    
    // Mostrar loading
    var btn = event.target;
    var originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="icon-spinner icon-spin"></i>';
    btn.disabled = true;
    
    // Hacer petición AJAX
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('✓ Webhook funcionando correctamente\n\nVersión del módulo: ' + data.module_version + '\nTimestamp: ' + data.timestamp);
            } else {
                alert('✗ Error en webhook: ' + data.error);
            }
        })
        .catch(error => {
            alert('✗ Error de conexión: ' + error.message);
        })
        .finally(() => {
            btn.innerHTML = originalHtml;
            btn.disabled = false;
        });
}

// Auto-focus en botón principal después de 2 segundos
setTimeout(function() {
    document.querySelector('.btn-primary.btn-lg').focus();
}, 2000);
</script>

<style>
.panel {
    margin-bottom: 20px;
}

.badge {
    font-size: 11px;
}

pre {
    background-color: #f5f5f5;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 10px;
    font-size: 12px;
    margin-bottom: 10px;
}

.well-sm {
    padding: 10px;
    margin-bottom: 10px;
}

.input-group {
    margin-bottom: 10px;
}

.text-success {
    color: #5cb85c;
}

.text-muted {
    color: #777;
}

.list-unstyled li {
    margin-bottom: 5px;
}

.alert {
    margin-bottom: 15px;
}

.btn-lg {
    padding: 10px 20px;
    font-size: 16px;
}
</style>
                        <ul class="list-unstyled">
                            <li><i class="icon-check text-success"></i> Configuración de múltiples tiendas hijas</li>
                            <li><i class="icon-check text-success"></i> Sincronización automática de pedidos</li>
                            <li><i class="icon-check text-success"></i> Consolidación inteligente de productos</li>
                            <li><i class="icon-check text-success"></i> Sistema de grupos y descuentos</li>
                            <li><i class="icon-check text-success"></i> Gestión avanzada de transportistas</li>
                            <li><i class="icon-check text-success"></i> Panel de administración completo</li>
                            <li><i class="icon-check text-success"></i> Sistema de webhooks y APIs</li>
                            <li><i class="icon-check text-success"></i> Tareas programadas (cron jobs)</li>
                            <li><i class="icon-check text-success"></i> Logs y debugging avanzado</li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-6">
                <div class="panel panel-warning">
                    <div class="panel-heading">
                        <h4 class="panel-title"><i class="icon-list-ol"></i> Próximos Pasos</h4>
                    </div>
                    <div class="panel-body">
                        <ol>
                            <li><strong>Configurar WebService en tiendas hijas</strong>
                                <br><small class="text-muted">Activar WebService y generar claves API</small>
                            </li>
                            <li><strong>Añadir tiendas desde el panel</strong>
                                <br><small class="text-muted">Configurar URLs, claves y parámetros</small>
                            </li>
                            <li><strong>Configurar clientes y grupos</strong>
                                <br><small class="text-muted">Asignar clientes y grupos de precios</small>
                            </li>
                            <li><strong>Probar conexiones</strong>
                                <br><small class="text-muted">Verificar que las tiendas se conectan correctamente</small>
                            </li>
                            <li><strong>Configurar tareas cron</strong>
                                <br><small class="text-muted">Automatizar sincronización y procesamiento</small>
                            </li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
        
        {* Información técnica *}
        <div class="row">
            <div class="col-lg-4">
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h4 class="panel-title"><i class="icon-info-circle"></i> Información del Sistema</h4>
                    </div>
                    <div class="panel-body">
                        <table class="table table-condensed">
                            <tr>
                                <td><strong>Versión del Módulo:</strong></td>
                                <td>1.0.3</td>
                            </tr>
                            <tr>
                                <td><strong>PrestaShop:</strong></td>
                                <td>{$ps_version}</td>
                            </tr>
                            <tr>
                                <td><strong>PHP:</strong></td>
                                <td>{$php_version}</td>
                            </tr>
                            <tr>
                                <td><strong>Estado:</strong></td>
                                <td><span class="badge badge-success">Activo</span></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4">
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h4 class="panel-title"><i class="icon-database"></i> Tablas Instaladas</h4>
                    </div>
                    <div class="panel-body">