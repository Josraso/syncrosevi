{*
* 2024 SyncroSevi - Template de Pedidos Pendientes
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
*}

<div class="syncrosevi-panel">
    <div class="panel panel-warning">
        <div class="panel-heading">
            <h3 class="panel-title">
                <i class="icon-clock-o"></i> Pedidos Pendientes de Procesamiento
                <a href="{$admin_link}&token={$token}" class="btn btn-default btn-sm pull-right">
                    <i class="icon-arrow-left"></i> Volver al Panel Principal
                </a>
            </h3>
        </div>
        <div class="panel-body">
            
            {if $pending_orders}
                <div class="alert alert-info">
                    <i class="icon-info-circle"></i>
                    <strong>Información:</strong> Estos pedidos han sido sincronizados desde las tiendas hijas pero aún no han sido procesados para crear pedidos consolidados en la tienda madre.
                </div>
                
                {* Acciones de procesamiento *}
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h4 class="panel-title"><i class="icon-cogs"></i> Acciones de Procesamiento</h4>
                    </div>
                    <div class="panel-body">
                        <div class="btn-group" role="group">
                            <form method="post" action="{$admin_link}" style="display: inline;">
                                <input type="hidden" name="action" value="process_orders">
                                <input type="hidden" name="token" value="{$token}">
                                <button type="submit" class="btn btn-success btn-lg" 
                                        data-confirm="¿Procesar todos los pedidos pendientes? Esta acción creará pedidos consolidados en la tienda madre.">
                                    <i class="icon-cogs"></i> Procesar Todos los Pedidos
                                </button>
                            </form>
                            
                            <form method="post" action="{$admin_link}" style="display: inline;">
                                <input type="hidden" name="action" value="sync_orders">
                                <input type="hidden" name="token" value="{$token}">
                                <button type="submit" class="btn btn-primary">
                                    <i class="icon-refresh"></i> Sincronizar Nuevos Pedidos
                                </button>
                            </form>
                            
                            <button type="button" class="btn btn-info" onclick="location.reload()">
                                <i class="icon-refresh"></i> Actualizar Vista
                            </button>
                        </div>
                    </div>
                </div>
                
                {* Tabla de pedidos pendientes *}
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h4 class="panel-title">
                            <i class="icon-list"></i> Detalle de Pedidos Pendientes
                            <span class="badge badge-warning pull-right">{$pending_orders|@count} pedidos</span>
                        </h4>
                    </div>
                    <div class="panel-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-bordered table-hover">
                                <thead>
                                    <tr>
                                        <th><i class="icon-shop"></i> Tienda Origen</th>
                                        <th><i class="icon-hashtag"></i> ID Pedido Original</th>
                                        <th><i class="icon-list-ol"></i> Líneas</th>
                                        <th><i class="icon-cubes"></i> Cantidad Total</th>
                                        <th><i class="icon-calendar"></i> Fecha Sincronización</th>
                                        <th><i class="icon-shopping-cart"></i> Productos</th>
                                        <th><i class="icon-cogs"></i> Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {assign var="currentShop" value=""}
                                    {assign var="shopCount" value=0}
                                    {foreach from=$pending_orders item=order}
                                        <tr {if $order.shop_name != $currentShop}class="shop-separator"{/if}>
                                            <td>
                                                {if $order.shop_name != $currentShop}
                                                    <strong class="text-primary">{$order.shop_name}</strong>
                                                    {assign var="currentShop" value=$order.shop_name}
                                                    {assign var="shopCount" value=$shopCount+1}
                                                {else}
                                                    <span class="text-muted">└ {$order.shop_name}</span>
                                                {/if}
                                            </td>
                                            <td>
                                                <span class="badge badge-info">#{$order.id_original_order}</span>
                                            </td>
                                            <td>
                                                <span class="badge badge-secondary">{$order.lines_count}</span>
                                            </td>
                                            <td>
                                                <span class="badge badge-warning">{$order.total_quantity}</span>
                                            </td>
                                            <td>
                                                <small>
                                                    <i class="icon-calendar"></i> 
                                                    {$order.first_sync|date_format:"%d/%m/%Y"}<br>
                                                    <i class="icon-clock-o"></i> 
                                                    {$order.first_sync|date_format:"%H:%M"}
                                                </small>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-xs btn-default" 
                                                        onclick="showOrderProducts({$order.id_child_shop}, {$order.id_original_order})"
                                                        data-toggle="tooltip" title="Ver productos del pedido">
                                                    <i class="icon-eye"></i> Ver Productos
                                                </button>
                                                <small class="text-muted d-block" style="margin-top: 5px;">
                                                    {$order.products|truncate:80:"..."}
                                                </small>
                                                {if !empty($order.clone_error)}
                                                    <div class="alert alert-warning" style="margin:6px 0 0;padding:6px 10px;">
                                                        <i class="icon-warning"></i> {$order.clone_error|escape:'html':'UTF-8'}
                                                    </div>
                                                {/if}
                                            </td>
                                            <td>
    <div class="btn-group-vertical">
        <button type="button" class="btn btn-xs btn-danger" 
                onclick="deletePendingOrder({$order.id_child_shop}, {$order.id_original_order})"
                data-toggle="tooltip" title="Eliminar de pendientes">
            <i class="icon-trash"></i>
        </button>
    </div>
</td>
                                        </tr>
                                    {/foreach}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
{* Resumen por tienda - MÉTODO SIMPLIFICADO *}
<div class="panel panel-info">
    <div class="panel-heading">
        <h4 class="panel-title"><i class="icon-bar-chart"></i> Resumen por Tienda</h4>
    </div>
    <div class="panel-body">
        <div class="row">
            {* Crear arrays simples para contar *}
            {assign var="shopCounts" value=[]}
            {assign var="shopQuantities" value=[]}
            {assign var="processedShops" value=[]}
            
            {* Procesar cada pedido *}
            {foreach from=$pending_orders item=order}
                {assign var="shopName" value=$order.shop_name}
                
                {* Contar pedidos por tienda *}
                {if isset($shopCounts[$shopName])}
                    {assign var="shopCounts" value=$shopCounts|@array_merge:[$shopName => $shopCounts[$shopName] + 1]}
                {else}
                    {assign var="shopCounts" value=$shopCounts|@array_merge:[$shopName => 1]}
                {/if}
                
                {* Sumar cantidades por tienda *}
                {if isset($shopQuantities[$shopName])}
                    {assign var="shopQuantities" value=$shopQuantities|@array_merge:[$shopName => $shopQuantities[$shopName] + $order.total_quantity]}
                {else}
                    {assign var="shopQuantities" value=$shopQuantities|@array_merge:[$shopName => $order.total_quantity]}
                {/if}
                
                {* Marcar tienda como procesada *}
                {if !in_array($shopName, $processedShops)}
                    {assign var="processedShops" value=$processedShops|@array_merge:[$shopName]}
                {/if}
            {/foreach}
            
            {* Mostrar resultados *}
            {foreach from=$processedShops item=shopName}
                <div class="col-lg-4 col-md-6">
                    <div class="panel panel-default">
                        <div class="panel-body text-center">
                            <h4 class="text-primary">{$shopName}</h4>
                            <p>
                                <span class="badge badge-warning">{$shopCounts[$shopName]}</span> pedidos<br>
                                <span class="badge badge-info">{$shopQuantities[$shopName]}</span> productos
                            </p>
                        </div>
                    </div>
                </div>
            {/foreach}
        </div>
        
        <div class="alert alert-warning">
            <strong><i class="icon-exclamation-triangle"></i> Resumen Total:</strong> 
            Hay <strong>{$pending_orders|@count}</strong> pedidos pendientes con un total de 
            <strong>{array_sum(array_column($pending_orders, 'total_quantity'))}</strong> productos de <strong>{$processedShops|@count}</strong> tiendas hijas.
        </div>
    </div>
</div>
                
            {else}
                <div class="alert alert-success text-center">
                    <h4><i class="icon-check-circle"></i> ¡Excelente trabajo!</h4>
                    <p>No hay pedidos pendientes de procesamiento. Todos los pedidos sincronizados han sido procesados correctamente.</p>
                    <hr>
                    <div class="btn-group">
                        <form method="post" action="{$admin_link}" style="display: inline;">
                            <input type="hidden" name="action" value="sync_orders">
                            <input type="hidden" name="token" value="{$token}">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="icon-refresh"></i> Sincronizar Nuevos Pedidos
                            </button>
                        </form>
                        
                        <a href="{$admin_link}&action=view_processed&token={$token}" class="btn btn-info btn-lg">
                            <i class="icon-list-alt"></i> Ver Pedidos Procesados
                        </a>
                    </div>
                </div>
            {/if}
            
        </div>
    </div>
</div>

{* Modal para mostrar productos del pedido *}
<div class="modal fade" id="orderProductsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">
                    <i class="icon-shopping-cart"></i> Productos del Pedido
                </h4>
            </div>
            <div class="modal-body" id="orderProductsContent">
                <div class="text-center">
                    <i class="icon-spinner icon-spin"></i> Cargando productos...
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">
                    <i class="icon-times"></i> Cerrar
                </button>
                
            </div>
        </div>
    </div>
</div>



<style>
.shop-separator {
    border-top: 2px solid #f0ad4e !important;
}

.shop-separator td:first-child {
    border-left: 4px solid #f0ad4e;
}

.badge {
    font-size: 11px;
    margin: 2px;
}

.table th {
    background-color: #f8f9fa;
    font-weight: 600;
    border-bottom: 2px solid #dee2e6;
}

.btn-group-vertical .btn {
    margin-bottom: 2px;
}

.panel-body .alert:last-child {
    margin-bottom: 0;
}

@media (max-width: 768px) {
    .btn-group .btn {
        margin-bottom: 5px;
        display: block;
        width: 100%;
    }
    
    .table-responsive {
        border: none;
    }
    
    .btn-group-vertical {
        display: flex;
        flex-direction: row;
    }
    
    .btn-group-vertical .btn {
        margin-right: 2px;
        margin-bottom: 0;
    }
}
</style>

<script>
var adminLink = '{$admin_link}';
var token = '{$token}';
var currentOrderShop = null;
var currentOrderId = null;

// Mostrar productos del pedido
function showOrderProducts(shopId, orderId) {
    currentOrderShop = shopId;
    currentOrderId = orderId;
    
    $('#orderProductsModal').modal('show');
    $('#orderProductsContent').html('<div class="text-center"><i class="icon-spinner icon-spin"></i> Cargando productos...</div>');
    
    // Llamada AJAX real
    $.ajax({
        url: adminLink,
        method: 'GET',
        data: {
            action: 'get_order_products',
            shop_id: shopId,
            order_id: orderId,
            token: token
        },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.products) {
                renderOrderProducts(response);
            } else {
                $('#orderProductsContent').html('<div class="alert alert-danger">Error: ' + (response.message || 'Error desconocido') + '</div>');
            }
        },
        error: function() {
            $('#orderProductsContent').html('<div class="alert alert-danger">Error de comunicación</div>');
        }
    });
}

// Renderizar productos del pedido
function renderOrderProducts(data) {
    var content = '<div class="alert alert-info">';
    content += '<strong>Tienda:</strong> ' + data.shop.name + '<br>';
    content += '<strong>Pedido Original:</strong> #' + currentOrderId + '<br>';
    content += '<strong>Total Productos:</strong> ' + data.total_products + ' (' + data.total_quantity + ' unidades)<br>';
    content += '</div>';
    
    content += '<div class="table-responsive">';
    content += '<table class="table table-striped">';
    content += '<thead><tr><th>Producto</th><th>Referencia</th><th>Cantidad</th><th>Fecha Sync</th></tr></thead>';
    content += '<tbody>';
    
    data.products.forEach(function(product) {
        content += '<tr>';
        content += '<td>' + product.product_name + '</td>';
        content += '<td><code>' + product.product_reference + '</code></td>';
        content += '<td><span class="badge badge-info">' + product.quantity + '</span></td>';
        content += '<td><small>' + formatDate(product.date_add) + '</small></td>';
        content += '</tr>';
    });
    
    content += '</tbody></table></div>';
    
    $('#orderProductsContent').html(content);
}


// Eliminar pedido pendiente
function deletePendingOrder(shopId, orderId) {
    if (confirm('¿Estás seguro de eliminar este pedido de la cola de pendientes?\n\nEsta acción no se puede deshacer.')) {
        
        // Mostrar loading en el botón
        var btn = $('button[onclick*="deletePendingOrder(' + shopId + ',' + orderId + ')"]');
        var originalHtml = btn.html();
        btn.html('<i class="icon-spinner icon-spin"></i>').prop('disabled', true);
        
        $.ajax({
            url: adminLink,
            method: 'POST',
            data: {
                action: 'delete_pending_order',
                shop_id: shopId,
                order_id: orderId,
                token: token
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
    showAlert('success', '✓ Pedido eliminado correctamente. Actualizando vista...');
    
    // Refresh automático después de 1.5 segundos
    setTimeout(function() {
        location.reload();
    }, 1500);
} else {
    showAlert('error', 'Error eliminando pedido: ' + response.message);
    btn.html(originalHtml).prop('disabled', false);
}
            },
            error: function() {
                showAlert('error', 'Error de comunicación al eliminar pedido');
                btn.html(originalHtml).prop('disabled', false);
            }
        });
    }
}

// Función de alerta mejorada
function showAlert(type, message) {
    var alertClass = 'alert-info';
    var icon = 'icon-info-circle';
    
    switch(type) {
        case 'success':
            alertClass = 'alert-success';
            icon = 'icon-check-circle';
            break;
        case 'error':
            alertClass = 'alert-danger';
            icon = 'icon-exclamation-triangle';
            break;
        case 'warning':
            alertClass = 'alert-warning';
            icon = 'icon-exclamation-circle';
            break;
    }
    
    var alertHtml = '<div class="alert ' + alertClass + ' alert-dismissible fade-in">' +
                   '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                   '<i class="' + icon + '"></i> ' + message +
                   '</div>';
    
    $('.panel-body').first().prepend(alertHtml);
    
    // Auto-ocultar después de 5 segundos
    setTimeout(function() {
        $('.alert').first().fadeOut(function() { $(this).remove(); });
    }, 5000);
}

// Formatear fecha
function formatDate(dateString) {
    if (!dateString) return '';
    var date = new Date(dateString);
    return date.toLocaleDateString('es-ES', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

// Inicialización
$(document).ready(function() {
    // Inicializar tooltips
    $('[data-toggle="tooltip"]').tooltip();
    

});
</script>