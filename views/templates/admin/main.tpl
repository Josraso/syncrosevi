{*
* 2024 SyncroSevi - Template Principal del AdminController
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
*}

{if isset($updater_panel) && $updater_top}{$updater_panel nofilter}{/if}
<div class="syncrosevi-panel">
    
    {* Estadísticas *}
    <div class="row">
        <div class="col-lg-3">
            <div class="panel panel-primary stats-panel">
                <div class="panel-heading">
                    <h3 class="panel-title"><i class="icon-shop"></i> Tiendas Totales</h3>
                </div>
                <div class="panel-body text-center">
                    <h2>{$stats.total_shops}</h2>
                    <small>{$stats.active_shops} activas</small>
                </div>
            </div>
        </div>
        <div class="col-lg-3">
            <div class="panel panel-success stats-panel">
                <div class="panel-heading">
                    <h3 class="panel-title"><i class="icon-check-circle"></i> Tiendas Activas</h3>
                </div>
                <div class="panel-body text-center">
                    <h2>{$stats.active_shops}</h2>
                    <small>de {$stats.total_shops} configuradas</small>
                </div>
            </div>
        </div>
        <div class="col-lg-3">
            <div class="panel panel-warning stats-panel">
                <div class="panel-heading">
                    <h3 class="panel-title"><i class="icon-clock-o"></i> Pedidos Pendientes</h3>
                </div>
                <div class="panel-body text-center">
                    <h2>{$stats.pending_orders}</h2>
                    <small>por procesar</small>
                </div>
            </div>
        </div>
        <div class="col-lg-3">
            <div class="panel panel-info stats-panel">
                <div class="panel-heading">
                    <h3 class="panel-title"><i class="icon-list-alt"></i> Pedidos Procesados</h3>
                </div>
                <div class="panel-body text-center">
                    <h2>{$stats.processed_orders}</h2>
                    <small>completados</small>
                </div>
            </div>
        </div>
    </div>

    {* Acciones rápidas *}
    <div class="panel panel-default">
        <div class="panel-heading">
            <h3 class="panel-title"><i class="icon-flash"></i> Acciones Rápidas</h3>
        </div>
        <div class="panel-body">
            <div class="btn-group" role="group">
                <form method="post" action="{$admin_link}" style="display: inline;">
                    <input type="hidden" name="action" value="sync_orders">
                    <input type="hidden" name="token" value="{$token}">
                    <button type="submit" class="btn btn-primary">
                        <i class="icon-refresh"></i> Sincronizar Pedidos
                    </button>
                </form>
                
                <form method="post" action="{$admin_link}" style="display: inline;">
                    <input type="hidden" name="action" value="process_orders">
                    <input type="hidden" name="token" value="{$token}">
                    <button type="submit" class="btn btn-success">
                        <i class="icon-cogs"></i> Procesar Pedidos
                    </button>
                </form>
                
                <a href="{$admin_link}&action=view_pending&token={$token}" class="btn btn-warning">
                    <i class="icon-list"></i> Ver Pedidos Pendientes ({$stats.pending_orders})
                </a>
                
                <a href="{$admin_link}&action=view_processed&token={$token}" class="btn btn-info">
                    <i class="icon-eye"></i> Ver Pedidos Procesados
                </a>
            </div>
            <a href="{$admin_link}&action=download_sender_clean" class="btn btn-default pull-right" title="Módulo para instalar en una tienda hija y configurarlo a mano (o pegando el código de configuración)">
                <i class="icon-download"></i> SyncroSevi Sender (limpio)
            </a>
        </div>
    </div>

    {* Configuración de tiendas *}
    <div class="panel panel-default">
        <div class="panel-heading">
            <h3 class="panel-title">
                <i class="icon-shop"></i> Configuración de Tiendas Hijas
                <button type="button" class="btn btn-primary btn-sm pull-right" data-toggle="modal" data-target="#addShopModal">
                    <i class="icon-plus"></i> Añadir Tienda
                </button>
            </h3>
        </div>
        <div class="panel-body">
            {if $shops}
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
    <tr>
        <th><i class="icon-tag"></i> Nombre</th>
        <th><i class="icon-globe"></i> URL</th>
        <th><i class="icon-user"></i> Cliente</th>
        <th><i class="icon-group"></i> Grupo</th>
        <th><i class="icon-list"></i> Estado</th>
        <th><i class="icon-truck"></i> Transportista</th>
        <th><i class="icon-power-off"></i> Activo</th>
        <th><i class="icon-flash"></i> Tiempo Real</th>
        <th><i class="icon-cogs"></i> Acciones</th>
    </tr>
</thead>
                        <tbody>
                            {foreach from=$shops item=shop}
                                <tr class="shop-row">
                                    <td>
                                        <strong>{$shop.name}</strong>
                                        <br><small class="text-muted">ID: {$shop.id_child_shop}</small>
                                        {if isset($shop.sync_mode) && $shop.sync_mode == 'clone'}<br><span class="label label-info" title="Cada pedido se clona con el cliente final">Clonar pedidos</span>{/if}
                                        {if $shop.pending_count > 0}<br><span class="badge badge-warning" title="Pedidos pendientes de procesar">{$shop.pending_count} pendientes</span>{/if}
                                    </td>
                                    <td>
                                        <small>{$shop.url}</small>
                                    </td>
                                    <td>
                                        <strong>{$shop.firstname} {$shop.lastname}</strong>
                                        <br><small class="text-muted">{$shop.email}</small>
                                    </td>
                                    <td>
                                        <span class="badge badge-info">{$shop.group_name}</span>
                                    </td>
                                    <td>
                                        <span class="badge badge-default">{$shop.state_name}</span>
                                    </td>
                                    <td>
                                        {if $shop.carrier_name}
                                            <span class="badge badge-primary">{$shop.carrier_name}</span>
                                        {else}
                                            <span class="badge badge-secondary">Sin transportista</span>
                                        {/if}
                                    </td>
                                    <td>
    {if $shop.active}
        <span class="badge badge-success"><i class="icon-check"></i> Activa</span>
    {else}
        <span class="badge badge-danger"><i class="icon-times"></i> Inactiva</span>
    {/if}
</td>
<td>
    {if $shop.realtime_enabled}
        <span class="badge badge-success" title="Sincronización en tiempo real activada">
            <i class="icon-flash"></i> Sí
        </span>
        {if $shop.realtime_orders_24h > 0}
            <br><small class="text-success">{$shop.realtime_orders_24h} en 24h</small>
        {/if}
    {else}
        <span class="badge badge-warning" title="Solo sincronización por cron">
            <i class="icon-clock-o"></i> Cron
        </span>
    {/if}
</td>
<td>
    <div class="btn-group">
                                            <button type="button" class="btn btn-sm btn-info" 
                                                    onclick="testConnection({$shop.id_child_shop})" 
                                                    title="Probar Conexión"
                                                    data-toggle="tooltip">
                                                <i class="icon-link"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-primary" 
        onclick="editShop({$shop.id_child_shop})" 
        title="Editar Tienda"
        data-toggle="tooltip">
    <i class="icon-edit"></i>
</button>
<button type="button" class="btn btn-sm btn-info" 
        onclick="showWebhookInfo({$shop.id_child_shop})" 
        title="Información Webhook"
        data-toggle="tooltip">
    <i class="icon-flash"></i>
</button>
                                            <button type="button" class="btn btn-sm btn-danger" 
                                                    onclick="deleteShop({$shop.id_child_shop})" 
                                                    title="Eliminar Tienda"
                                                    data-toggle="tooltip"
                                                    data-confirm="¿Estás seguro de eliminar esta tienda? Esta acción no se puede deshacer.">
                                                <i class="icon-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            {/foreach}
                        </tbody>
                    </table>
                </div>
            {else}
                <div class="alert alert-info text-center" id="no-shops-message">
                    <h4><i class="icon-info-circle"></i> No hay tiendas configuradas</h4>
                    <p>Para comenzar a sincronizar pedidos, añade tu primera tienda hija.</p>
                    <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#addShopModal">
                        <i class="icon-plus"></i> Añadir Primera Tienda
                    </button>
                </div>
            {/if}
        </div>
    </div>

    {* Pedidos pendientes (vista rápida) *}
    {if $pending_orders}
    <div class="panel panel-warning">
        <div class="panel-heading">
            <h3 class="panel-title">
                <i class="icon-clock-o"></i> Últimos Pedidos Pendientes
                <a href="{$admin_link}&action=view_pending&token={$token}" class="btn btn-warning btn-xs pull-right">
                    Ver Todos
                </a>
            </h3>
        </div>
        <div class="panel-body">
            <div class="table-responsive">
                <table class="table table-condensed">
                    <thead>
                        <tr>
                            <th>Tienda</th>
                            <th>Pedido Original</th>
                            <th>Productos</th>
                            <th>Cantidad</th>
                            <th>Fecha Sync</th>
                        </tr>
                    </thead>
                    <tbody>
                        {foreach from=$pending_orders item=order}
                            <tr>
                                <td><strong>{$order.shop_name}</strong></td>
                                <td><span class="badge badge-info">#{$order.id_original_order}</span></td>
                                <td><small>{$order.products|truncate:50:"..."}</small>{if !empty($order.clone_error)}<br><small class="text-warning"><i class="icon-warning"></i> {$order.clone_error|truncate:160:"..."|escape:'html':'UTF-8'}</small>{/if}</td>
                                <td><span class="badge badge-warning">{$order.total_quantity}</span></td>
                                <td><small>{$order.first_sync|date_format:"%d/%m/%Y %H:%M"}</small></td>
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    {/if}

    {* URLs de webhook y tareas programadas *}
    <div class="panel panel-default">
        <div class="panel-heading">
            <h3 class="panel-title"><i class="icon-link"></i> Automatización (tareas cron)</h3>
        </div>
        <div class="panel-body">
            <p class="text-muted">La sincronización y el procesamiento nunca se hacen solos: se lanzan con estos botones, con una tarea cron o con estas URLs.</p>
            <div class="row">
                <div class="col-lg-6">
                    <h5><i class="icon-refresh"></i> URL de sincronización:</h5>
                    <div class="input-group">
                        <input type="text" class="form-control" value="{$webhook_sync_url|escape:'html':'UTF-8'}" readonly id="url-sync">
                        <span class="input-group-btn"><button type="button" class="btn btn-default" onclick="copyToClipboard('url-sync')"><i class="icon-copy"></i></button></span>
                    </div>
                    <small class="text-muted">Descarga los pedidos nuevos de las tiendas hijas (por ejemplo cada 15 minutos)</small>
                </div>
                <div class="col-lg-6">
                    <h5><i class="icon-cogs"></i> URL de procesamiento:</h5>
                    <div class="input-group">
                        <input type="text" class="form-control" value="{$webhook_process_url|escape:'html':'UTF-8'}" readonly id="url-process">
                        <span class="input-group-btn"><button type="button" class="btn btn-default" onclick="copyToClipboard('url-process')"><i class="icon-copy"></i></button></span>
                    </div>
                    <small class="text-muted">Crea los pedidos consolidados en esta tienda (por ejemplo cada hora)</small>
                </div>
            </div>
            <div class="row" style="margin-top: 15px;">
                <div class="col-lg-6">
                    <h5><i class="icon-terminal"></i> O por línea de comandos (PHP CLI):</h5>
                    <pre style="white-space: pre-wrap;">*/15 * * * * {$cron_sync_cmd|escape:'html':'UTF-8'}
0 * * * * {$cron_process_cmd|escape:'html':'UTF-8'}</pre>
                </div>
                <div class="col-lg-6">
                    <div class="alert alert-info" style="margin-top: 25px;">
                        <i class="icon-info-circle"></i>
                        Estas URLs funcionan también en PrestaShop 9 y aunque la tienda esté en mantenimiento.
                        Las URLs antiguas <code>modules/syncrosevi/webhook.php?...</code> siguen funcionando, pero conviene cambiarlas por estas.
                        <a href="{$webhook_test_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener">Probar webhook</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {$settings_form nofilter}
</div>

{* Modal para añadir tienda *}
<div class="modal fade" id="addShopModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="post" action="{$admin_link}" id="addShopForm">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                    <h4 class="modal-title"><i class="icon-plus"></i> Añadir Nueva Tienda Hija</h4>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Modo de importación *</label>
                        <div class="radio" style="margin-top:0">
                            <label><input type="radio" name="sync_mode" value="consolidated" checked> <strong>Consolidado</strong> — varios pedidos de la hija en un solo pedido, a nombre del cliente asignado (revendedores)</label>
                        </div>
                        <div class="radio">
                            <label><input type="radio" name="sync_mode" value="clone"> <strong>Clonar pedido</strong> — cada pedido entra igual que en la hija, con su cliente, direcciones, precios y transporte (tiendas propias)</label>
                        </div>
                        <small class="help-block" id="clone-add-help" style="display:none">En modo clonar no hace falta cliente, grupo ni dirección. Al añadir la tienda se abre su ficha para terminar de configurar el clonado (productos que falten, fecha, estados e impuestos).</small>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="shop_name">Nombre de la Tienda *</label>
                                <input type="text" class="form-control" id="shop_name" name="shop_name" required>
                                <small class="help-block">Nombre descriptivo para identificar la tienda</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="shop_url">URL de la Tienda *</label>
                                <input type="url" class="form-control" id="shop_url" name="shop_url" 
                                       placeholder="https://tienda-hija.com" required>
                                <small class="help-block">URL completa de la tienda hija (con https://)</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="api_key">Clave API *</label>
                                <input type="text" class="form-control" id="api_key" name="api_key" required>
                                <small class="help-block">Clave de WebService generada en la tienda hija</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="start_order_id">ID Pedido Inicial</label>
                                <input type="number" class="form-control" id="start_order_id" name="start_order_id" 
                                       value="1" min="1">
                                <small class="help-block">Desde qué pedido empezar a sincronizar</small>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="form-group syncrosevi-consolidated">
                                <label for="customer-search">Cliente Asignado *</label>
                                <div class="customer-search">
                                    <input type="text" class="form-control" id="customer-search" 
                                           placeholder="Buscar cliente..." 
                                           onkeyup="searchCustomers(this.value, 'customer-results', 'id_customer')">
                                    <div id="customer-results" class="customer-results"></div>
                                </div>
                                <input type="hidden" id="id_customer" name="id_customer" required>
                                <small class="help-block">Cliente que se asignará a los pedidos consolidados</small>
                            </div>
                            
                            <div class="form-group syncrosevi-consolidated">
                                <label for="id_group">Grupo de Precios *</label>
                                <select class="form-control" id="id_group" name="id_group" required>
                                    <option value="">Seleccionar grupo...</option>
                                    {foreach from=$groups item=group}
                                        <option value="{$group.id_group}">{$group.name}</option>
                                    {/foreach}
                                </select>
                                <small class="help-block">Grupo de precios para aplicar descuentos</small>
                            </div>
                            
                            <div class="form-group syncrosevi-consolidated">
                                <label for="id_address">Dirección de Facturación *</label>
                                <select class="form-control" id="id_address" name="id_address" required>
                                    <option value="">Primero selecciona un cliente...</option>
                                </select>
                                <small class="help-block">Dirección que se usará para facturación y envío</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="id_order_state">Estado del Pedido *</label>
                                <select class="form-control" id="id_order_state" name="id_order_state" required>
                                    <option value="">Seleccionar estado...</option>
                                    {foreach from=$order_states item=state}
                                        <option value="{$state.id_order_state}">{$state.name}</option>
                                    {/foreach}
                                </select>
                                <small class="help-block">Estado inicial de los pedidos creados</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <div class="checkbox">
                                    <label>
                                        <input type="checkbox" id="use_carrier" onchange="toggleTransport(this.checked)"> 
                                        Configurar transportista específico
                                    </label>
                                </div>
                                <div id="transport-options" class="transport-options">
                                    <select class="form-control" id="id_carrier" name="id_carrier">
                                        <option value="">Sin transportista específico</option>
                                        {foreach from=$carriers item=carrier}
                                            <option value="{$carrier.id_carrier}">{$carrier.name}</option>
                                        {/foreach}
                                    </select>
                                    <small class="help-block">Si no se especifica, se usará el transportista por defecto</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="form-group">
    <div class="checkbox">
        <label>
            <input type="checkbox" name="active" value="1" checked> 
            Activar tienda inmediatamente
        </label>
    </div>
    <div class="checkbox">
        <label>
            <input type="checkbox" name="realtime_enabled" value="1" checked> 
            Habilitar sincronización en tiempo real
        </label>
        <small class="help-block">Si está desactivado, solo sincronizará por cron</small>
    </div>
    <div class="checkbox">
        <label>
            <input type="checkbox" id="load_states_check" onchange="toggleStatesLoad(this.checked)"> 
            Cargar estados específicos de la tienda
        </label>
    </div>
</div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <button type="button" id="load-states-btn" class="btn btn-info btn-sm" 
        onclick="loadStatesFromShopModal()" style="display: none;">
    <i class="icon-download"></i> Cargar Estados de la Tienda Hija
</button>
                            <div id="import-states-container" class="import-states-container">
                                <!-- Estados se cargan aquí dinámicamente -->
                            </div>
                            <!-- Estados por defecto si no se cargan específicos -->
                            <input type="hidden" id="default_import_states" value="2,3,4,5">
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">
                        <i class="icon-times"></i> Cancelar
                    </button>
                    <button type="submit" name="submitAddShop" class="btn btn-primary">
                        <i class="icon-plus"></i> Añadir Tienda
                    </button>
                </div>
                
                <input type="hidden" name="action" value="add_shop">
                <input type="hidden" name="token" value="{$token}">
            </form>
        </div>
    </div>
</div>
{* Modal para información de webhook *}
<div class="modal fade" id="webhookInfoModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">
                    <i class="icon-flash"></i> Información de Webhook - <span id="webhook-shop-name"></span>
                </h4>
            </div>
            <div class="modal-body" id="webhookInfoContent">
                <div class="text-center">
                    <i class="icon-spinner icon-spin"></i> Cargando información de webhook...
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-warning" onclick="regenerateWebhookSecret()" id="regenerate-secret-btn">
                    <i class="icon-refresh"></i> Regenerar Clave Secreta
                </button>
                <button type="button" class="btn btn-default" data-dismiss="modal">
                    <i class="icon-times"></i> Cerrar
                </button>
            </div>
        </div>
    </div>
</div>
{if isset($updater_panel) && !$updater_top}{$updater_panel nofilter}{/if}

<script>
// Variables globales para que funcione la búsqueda de direcciones
window.addresses_all = {$addresses|json_encode};
window.customers_all = {$customers|json_encode};
// Mostrar información de webhook
function showWebhookInfo(shopId) {
    $('#webhookInfoModal').modal('show');
    $('#webhookInfoContent').html('<div class="text-center"><i class="icon-spinner icon-spin"></i> Cargando información...</div>');
    
    $.ajax({
        url: SyncroSeviAdmin.adminLink,
        method: 'GET',
        data: {
            action: 'get_webhook_info',
            id_child_shop: shopId,
            token: SyncroSeviAdmin.token
        },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.shop) {
                renderWebhookInfo(response);
            } else {
                $('#webhookInfoContent').html('<div class="alert alert-danger">Error: ' + (response.message || 'Error desconocido') + '</div>');
            }
        },
        error: function() {
            $('#webhookInfoContent').html('<div class="alert alert-danger">Error de comunicación</div>');
        }
    });
}

// Renderizar información de webhook
function renderWebhookInfo(data) {
    var shop = data.shop;
    var stats = data.webhook_stats;
    
    $('#webhook-shop-name').text(shop.name);
    shop.last_webhook = shop.last_webhook ? $('<span>').text(shop.last_webhook).html() : null;
    
    var content = '<div class="row">';
    
    // Estado del webhook
    content += '<div class="col-md-6">';
    content += '<h5><i class="icon-info-circle"></i> Estado del Webhook</h5>';
    content += '<table class="table table-condensed">';
    content += '<tr><td><strong>Tiempo Real:</strong></td><td>';
    if (shop.realtime_enabled == 1) {
        content += '<span class="badge badge-success">Habilitado</span>';
    } else {
        content += '<span class="badge badge-warning">Deshabilitado</span>';
    }
    content += '</td></tr>';
    content += '<tr><td><strong>Último Webhook:</strong></td><td>' + (shop.last_webhook || 'Nunca') + '</td></tr>';
    content += '<tr><td><strong>Webhooks Totales:</strong></td><td><span class="badge badge-info">' + stats.total_received + '</span></td></tr>';
    content += '<tr><td><strong>Últimas 24h:</strong></td><td><span class="badge badge-warning">' + stats.last_24h + '</span></td></tr>';
    content += '</table>';
    content += '</div>';
    
    // Configuración
    content += '<div class="col-md-6">';
    content += '<h5><i class="icon-cogs"></i> Configuración</h5>';
    content += '<div class="form-group">';
    content += '<label>URL del Webhook:</label>';
    content += '<div class="input-group">';
    content += '<input type="text" class="form-control" readonly id="webhook-url-copy">';
    content += '<span class="input-group-btn">';
    content += '<button type="button" class="btn btn-info btn-sm" onclick="copyToClipboard(\'webhook-url-copy\')">';
    content += '<i class="icon-copy"></i></button></span></div></div>';
    
    content += '<div class="form-group">';
    content += '<label>Clave Secreta:</label>';
    content += '<div class="input-group">';
    content += '<input type="text" class="form-control" readonly id="webhook-secret-copy">';
    content += '<span class="input-group-btn">';
    content += '<button type="button" class="btn btn-info btn-sm" onclick="copyToClipboard(\'webhook-secret-copy\')">';
    content += '<i class="icon-copy"></i></button></span></div></div>';
    content += '</div>';
    
    content += '</div>';
    
    // Módulo para la tienda hija
    content += '<div class="alert alert-info">';
    content += '<h5><i class="icon-download"></i> SyncroSevi Sender para la tienda hija</h5>';
    content += '<p><a class="btn btn-primary btn-sm" href="' + data.download_url + '"><i class="icon-download"></i> Descargar preconfigurado</a> ';
    content += '<a class="btn btn-default btn-sm" href="' + data.download_clean_url + '"><i class="icon-download"></i> Descargar limpio</a></p>';
    content += '<p>El preconfigurado se configura solo al instalarlo. Con el limpio, en la tienda hija pega este código en Módulos &gt; SyncroSevi Sender &gt; Configurar:</p>';
    content += '<div class="input-group"><input type="text" class="form-control" readonly id="config-code-copy">';
    content += '<span class="input-group-btn"><button type="button" class="btn btn-info btn-sm" onclick="copyToClipboard(\'config-code-copy\')"><i class="icon-copy"></i></button></span></div>';
    content += '</div>';
    
    $('#webhookInfoContent').html(content);
    $('#webhook-url-copy').val(data.webhook_url);
    $('#webhook-secret-copy').val(shop.webhook_secret);
    $('#config-code-copy').val(data.config_code);
    SyncroSeviAdmin.currentWebhookShop = shop.id_child_shop;
}

// Copiar al portapapeles
function copyToClipboard(elementId) {
    var element = document.getElementById(elementId);
    element.select();
    element.setSelectionRange(0, 99999);
    document.execCommand('copy');
    
    showAlert('success', 'Copiado al portapapeles');
}
// Inicializar admin con datos específicos
$(document).ready(function() {
    SyncroSeviAdmin.init({
        customers: {$customers|json_encode},
        addresses: {$addresses|json_encode},
        adminLink: '{$admin_link}',
        token: '{$token}'
    });
    
});
</script>