{*
* 2024 SyncroSevi - Template de Pedidos Procesados
*}

<div class="syncrosevi-panel">
    <div class="panel panel-info">
        <div class="panel-heading">
            <h3 class="panel-title">
                <i class="icon-list-alt"></i> Pedidos Procesados
                <a href="{$admin_link}" class="btn btn-default btn-sm pull-right">
                    <i class="icon-arrow-left"></i> Volver al Panel Principal
                </a>
            </h3>
        </div>
        <div class="panel-body">

            <div class="row" style="margin-bottom: 15px;">
                <div class="col-md-3">
                    <label for="filter-search">Buscar</label>
                    <input type="text" id="filter-search" class="form-control" placeholder="Nº o referencia de pedido (madre o hija), cliente, referencia de producto...">
                </div>
                <div class="col-md-2">
                    <label for="filter-shop">Tienda origen</label>
                    <select id="filter-shop" class="form-control">
                        <option value="">Todas</option>
                        {foreach from=$shops_list item=s}
                            <option value="{$s.id_child_shop|intval}">{$s.name|escape:'html':'UTF-8'}</option>
                        {/foreach}
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="filter-date-from">Desde</label>
                    <input type="date" id="filter-date-from" class="form-control">
                </div>
                <div class="col-md-2">
                    <label for="filter-date-to">Hasta</label>
                    <input type="date" id="filter-date-to" class="form-control">
                </div>
                <div class="col-md-3">
                    <div class="checkbox" style="margin: 0 0 4px;">
                        <label><input type="checkbox" id="filter-missing" value="1"> Solo con productos fuera</label>
                    </div>
                    <div class="btn-group">
                        <button type="button" id="clear-filters" class="btn btn-default" title="Limpiar filtros"><i class="icon-eraser"></i></button>
                        <button type="button" id="refresh-orders" class="btn btn-default" title="Actualizar"><i class="icon-refresh"></i></button>
                        <button type="button" id="export-orders" class="btn btn-default" title="Exportar CSV con los filtros aplicados"><i class="icon-download"></i> CSV</button>
                    </div>
                </div>
            </div>

            <div id="orders-loading" style="text-align: center; padding: 50px;">
                <i class="icon-spinner icon-spin" style="font-size: 24px;"></i>
                <p>Cargando pedidos procesados...</p>
            </div>

            <div id="orders-error" style="display: none;">
                <div class="alert alert-danger">
                    <i class="icon-exclamation-triangle"></i>
                    <strong>Error:</strong> <span class="error-message"></span>
                </div>
            </div>

            <div id="orders-table-container" style="display: none;">
                <div class="table-responsive">
                    <table id="orders-table" class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>ID Pedido</th>
                                <th>Referencia</th>
                                <th>Tienda Origen</th>
                                <th>Cliente</th>
                                <th>Pedidos Orig.</th>
                                <th>Productos</th>
                                <th title="Productos que no entraron porque su referencia no existe en la tienda madre">Fuera</th>
                                <th>Total</th>
                                <th>Fecha</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                        </tbody>
                    </table>
                </div>

                <div id="pagination-container"></div>
                <div id="pagination-info" class="text-muted"></div>

                <div id="orphans-container" class="panel" style="display: none; margin-top: 20px;">
                    <div class="panel-heading">
                        <i class="icon-warning-sign"></i> Lotes sin pedido madre <span class="badge" id="orphans-count"></span>
                        <small class="text-muted" style="text-transform:none;font-weight:normal;margin-left:8px">Ninguna referencia coincidía con la tienda madre, así que no se creó pedido</small>
                    </div>
                    <div class="table-responsive">
                        <table id="orphans-table" class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Pedido Madre</th>
                                    <th>Tienda Origen</th>
                                    <th>Pedidos Orig.</th>
                                    <th title="Productos que no entraron porque su referencia no existe en la tienda madre">Fuera</th>
                                    <th>Fecha</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

{* Ventana de detalle *}
<div class="modal fade" id="orderDetailsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title"><i class="icon-eye"></i> Detalle del pedido</h4>
            </div>
            <div class="modal-body" id="orderDetailsContent"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    if (typeof ProcessedOrders !== 'undefined') {
        ProcessedOrders.init({
            adminLink: '{$admin_link|escape:'javascript':'UTF-8'}',
            token: '{$token|escape:'javascript':'UTF-8'}'
        });
    } else {
        $('#orders-loading').hide();
        $('#orders-error .error-message').text('El módulo JavaScript de pedidos procesados no está disponible');
        $('#orders-error').show();
    }
});
</script>
