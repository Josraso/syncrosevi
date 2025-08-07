{*
* 2024 SyncroSevi
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
*}

<div class="panel">
    <div class="panel-heading">
        <i class="icon-list"></i>
        Pedidos Pendientes de Procesamiento
        <a href="{$admin_link}&token={$token}" class="btn btn-default btn-sm pull-right">
            <i class="icon-arrow-left"></i> Volver
        </a>
    </div>
    <div class="panel-body">
        
        {if $pending_orders}
            <div class="alert alert-info">
                <strong>Información:</strong> Estos pedidos han sido sincronizados desde las tiendas hijas pero aún no han sido procesados para crear pedidos únicos en la tienda madre.
            </div>
            
            <div class="row">
                <div class="col-lg-12">
                    <div class="btn-group" role="group">
                        <a href="{$admin_link}&action=process_orders&token={$token}" class="btn btn-success">
                            <i class="icon-cogs"></i> Procesar Todos los Pedidos
                        </a>
                        <a href="{$admin_link}&action=sync_orders&token={$token}" class="btn btn-primary">
                            <i class="icon-refresh"></i> Sincronizar Nuevos Pedidos
                        </a>
                    </div>
                </div>
            </div>
            
            <br>
            
            <div class="table-responsive">
                <table class="table table-striped table-bordered">
                    <thead>
                        <tr>
                            <th>Tienda Origen</th>
                            <th>ID Pedido Original</th>
                            <th>Número de Líneas</th>
                            <th>Cantidad Total</th>
                            <th>Fecha Primera Sincronización</th>
                            <th>Productos</th>
                        </tr>
                    </thead>
                    <tbody>
                        {assign var="currentShop" value=""}
                        {assign var="shopCount" value=0}
                        {foreach from=$pending_orders item=order}
                            <tr {if $order.shop_name != $currentShop}class="shop-separator"{/if}>
                                <td>
                                    {if $order.shop_name != $currentShop}
                                        <strong>{$order.shop_name}</strong>
                                        {assign var="currentShop" value=$order.shop_name}
                                        {assign var="shopCount" value=$shopCount+1}
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
                                    <small>{$order.first_sync|date_format:"%d/%m/%Y %H:%M"}</small>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-xs btn-default" onclick="showOrderDetails({$order.id_child_shop}, {$order.id_original_order})">
                                        <i class="icon-eye"></i> Ver Detalles
                                    </button>
                                </td>
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
            </div>
            
            <div class="row">
                <div class="col-lg-12">
                    <div class="alert alert-warning">
                        <strong>Resumen:</strong> 
                        {assign var="totalOrders" value=$pending_orders|@count}
                        {assign var="totalShops" value=0}
                        {assign var="shopNames" value=[]}
                        {foreach from=$pending_orders item=order}
                            {if !in_array($order.shop_name, $shopNames)}
                                {assign var="shopNames" value=$shopNames|@array_merge:[$order.shop_name]}
                                {assign var="totalShops" value=$totalShops+1}
                            {/if}
                        {/foreach}
                        Hay <strong>{$totalOrders}</strong> pedidos pendientes de <strong>{$totalShops}</strong> tiendas hijas.
                    </div>
                </div>
            </div>
            
        {else}
            <div class="alert alert-success">
                <i class="icon-check"></i>
                <strong>¡Excelente!</strong> No hay pedidos pendientes de procesamiento. Todos los pedidos sincronizados han sido procesados correctamente.
            </div>
            
            <div class="text-center">
                <a href="{$admin_link}&action=sync_orders&token={$token}" class="btn btn-primary btn-lg">
                    <i class="icon-refresh"></i> Sincronizar Nuevos Pedidos
                </a>
            </div>
        {/if}
        
    </div>
</div>

{* Modal para mostrar detalles del pedido *}
<div class="modal fade" id="orderDetailsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">Detalles del Pedido</h4>
            </div>
            <div class="modal-body" id="orderDetailsContent">
                <div class="text-center">
                    <i class="icon-spinner icon-spin"></i> Cargando...
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<style>
.shop-separator {
    border-top: 2px solid #ddd !important;
}

.badge {
    font-size: 11px;
}

.table th {
    background-color: #f5f5f5;
    font-weight: bold;
}
</style>

<script>
var adminLink = '{$admin_link}';
var token = '{$token}';

function showOrderDetails(shopId, orderId) {
    $('#orderDetailsModal').modal('show');
    $('#orderDetailsContent').html('<div class="text-center"><i class="icon-spinner icon-spin"></i> Cargando...</div>');
    
    // Aquí se podría hacer una petición AJAX para obtener los detalles
    // Por simplicidad, mostramos información básica
    setTimeout(function() {
        var content = '<div class="alert alert-info">' +
                     '<strong>Tienda:</strong> ID ' + shopId + '<br>' +
                     '<strong>Pedido Original:</strong> #' + orderId + '<br>' +
                     '</div>' +
                     '<p>Para ver los detalles completos del pedido, consulte la tabla principal.</p>';
        $('#orderDetailsContent').html(content);
    }, 500);
}
</script>