{*
* 2024 SyncroSevi
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
*}

<div class="panel">
    <div class="panel-heading">
        <i class="icon-exchange"></i>
        SyncroSevi - Gestión de Sincronización de Pedidos
    </div>
    <div class="panel-body">
        
        {* Estadísticas *}
        <div class="row">
            <div class="col-lg-3">
                <div class="panel panel-primary">
                    <div class="panel-heading">
                        <h3 class="panel-title">Tiendas Totales</h3>
                    </div>
                    <div class="panel-body text-center">
                        <h2>{$stats.total_shops}</h2>
                    </div>
                </div>
            </div>
            <div class="col-lg-3">
                <div class="panel panel-success">
                    <div class="panel-heading">
                        <h3 class="panel-title">Tiendas Activas</h3>
                    </div>
                    <div class="panel-body text-center">
                        <h2>{$stats.active_shops}</h2>
                    </div>
                </div>
            </div>
            <div class="col-lg-3">
                <div class="panel panel-warning">
                    <div class="panel-heading">
                        <h3 class="panel-title">Pedidos Pendientes</h3>
                    </div>
                    <div class="panel-body text-center">
                        <h2>{$stats.pending_orders}</h2>
                    </div>
                </div>
            </div>
            <div class="col-lg-3">
                <div class="panel panel-info">
                    <div class="panel-heading">
                        <h3 class="panel-title">Pedidos Procesados</h3>
                    </div>
                    <div class="panel-body text-center">
                        <h2>{$stats.processed_orders}</h2>
                    </div>
                </div>
            </div>
        </div>

        {* Acciones rápidas *}
        <div class="row">
            <div class="col-lg-12">
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h3 class="panel-title">Acciones Rápidas</h3>
                    </div>
                    <div class="panel-body">
                        <div class="btn-group" role="group">
                            <a href="{$admin_link}&action=sync_orders&token={$token}" class="btn btn-primary">
                                <i class="icon-refresh"></i> Sincronizar Pedidos
                            </a>
                            <a href="{$admin_link}&action=process_orders&token={$token}" class="btn btn-success">
                                <i class="icon-cogs"></i> Procesar Pedidos
                            </a>
                            <a href="{$admin_link}&action=view_pending&token={$token}" class="btn btn-warning">
                                <i class="icon-list"></i> Ver Pedidos Pendientes
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {* Configuración de tiendas *}
        <div class="row">
            <div class="col-lg-12">
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h3 class="panel-title">
                            Configuración de Tiendas Hijas
                            <button type="button" class="btn btn-primary btn-sm pull-right" data-toggle="modal" data-target="#addShopModal">
                                <i class="icon-plus"></i> Añadir Tienda
                            </button>
                        </h3>
                    </div>
                    <div class="panel-body">
                        {if $shops}
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Nombre</th>
                                            <th>URL</th>
                                            <th>Cliente</th>
                                            <th>Grupo</th>
                                            <th>Estado Pedido</th>
                                            <th>Transportista</th>
                                            <th>Activo</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {foreach from=$shops item=shop}
                                            <tr>
                                                <td>{$shop.name}</td>
                                                <td><small>{$shop.url}</small></td>
                                                <td>{$shop.firstname} {$shop.lastname}</td>
                                                <td>{$shop.group_name}</td>
                                                <td>{$shop.state_name}</td>
                                                <td>{$shop.carrier_name|default:'Sin transportista'}</td>
                                                <td>
                                                    {if $shop.active}
                                                        <span class="badge badge-success">Sí</span>
                                                    {else}
                                                        <span class="badge badge-danger">No</span>
                                                    {/if}
                                                </td>
                                                <td>
                                                    <div class="btn-group">
                                                        <button type="button" class="btn btn-sm btn-info" onclick="testConnection({$shop.id_child_shop})" title="Probar Conexión">
                                                            <i class="icon-link"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-primary" onclick="editShop({$shop.id_child_shop})" title="Editar">
                                                            <i class="icon-edit"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-danger" onclick="deleteShop({$shop.id_child_shop})" title="Eliminar">
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
                            <div class="alert alert-info">
                                No hay tiendas hijas configuradas. Haz clic en "Añadir Tienda" para comenzar.
                            </div>
                        {/if}
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{* Modal para añadir tienda *}
<div class="modal fade" id="addShopModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="post" action="{$admin_link}&token={$token}">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                    <h4 class="modal-title">Añadir Nueva Tienda Hija</h4>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="shop_name">Nombre de la Tienda *</label>
                                <input type="text" class="form-control" id="shop_name" name="shop_name" required>
                            </div>
                            <div class="form-group">
                                <label for="shop_url">URL de la Tienda *</label>
                                <input type="url" class="form-control" id="shop_url" name="shop_url" placeholder="https://ejemplo.com" required>
                                <small class="help-block">URL completa de la tienda hija</small>
                            </div>
                            <div class="form-group">
                                <label for="api_key">Clave API *</label>
                                <input type="text" class="form-control" id="api_key" name="api_key" required>
                                <small class="help-block">Clave de WebService de la tienda hija</small>
                            </div>
                            <div class="form-group">
                                <div class="checkbox">
                                    <label>
                                        <input type="checkbox" name="active" value="1" checked> Activo
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="id_customer">Cliente Asignado *</label>
                                <select class="form-control" id="id_customer" name="id_customer" required>
                                    <option value="">Seleccionar cliente...</option>
                                    {foreach from=$customers item=customer}
                                        <option value="{$customer.id_customer}">{$customer.firstname} {$customer.lastname} ({$customer.email})</option>
                                    {/foreach}
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="id_group">Grupo de Precios *</label>
                                <select class="form-control" id="id_group" name="id_group" required>
                                    <option value="">Seleccionar grupo...</option>
                                    {foreach from=$groups item=group}
                                        <option value="{$group.id_group}">{$group.name}</option>
                                    {/foreach}
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="id_address">Dirección de Facturación *</label>
                                <select class="form-control" id="id_address" name="id_address" required>
                                    <option value="">Seleccionar dirección...</option>
                                    {foreach from=$addresses item=address}
                                        <option value="{$address.id_address}">{$address.alias} - {$address.firstname} {$address.lastname}, {$address.address1}, {$address.city}</option>
                                    {/foreach}
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="id_carrier">Transportista (Opcional)</label>
                                <select class="form-control" id="id_carrier" name="id_carrier">
                                    <option value="">Sin transportista específico</option>
                                    {foreach from=$carriers item=carrier}
                                        <option value="{$carrier.id_carrier}">{$carrier.name}</option>
                                    {/foreach}
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="id_order_state">Estado del Pedido *</label>
                                <select class="form-control" id="id_order_state" name="id_order_state" required>
                                    <option value="">Seleccionar estado...</option>
                                    {foreach from=$order_states item=state}
                                        <option value="{$state.id_order_state}">{$state.name}</option>
                                    {/foreach}
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                    <button type="submit" name="submitAddShop" class="btn btn-primary">Añadir Tienda</button>
                </div>
                <input type="hidden" name="action" value="add_shop">
            </form>
        </div>
    </div>
</div>

{* Modal para editar tienda *}
<div class="modal fade" id="editShopModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="post" action="{$admin_link}&token={$token}" id="editShopForm">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                    <h4 class="modal-title">Editar Tienda Hija</h4>
                </div>
                <div class="modal-body" id="editShopContent">
                    <!-- Contenido cargado dinámicamente -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                    <button type="submit" name="submitEditShop" class="btn btn-primary">Guardar Cambios</button>
                </div>
                <input type="hidden" name="action" value="edit_shop">
                <input type="hidden" name="id_child_shop" id="edit_id_child_shop">
            </form>
        </div>
    </div>
</div>

<script>
var adminLink = '{$admin_link}';
var token = '{$token}';
</script>