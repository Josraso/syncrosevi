/**
 * 2024 SyncroSevi - Processed Orders JavaScript
 * 
 * JavaScript específico para la gestión de pedidos procesados
 */

var ProcessedOrders = {
    adminLink: "",
    token: "",
    currentPage: 1,
    ordersPerPage: 25,
    totalOrders: 0,
    filters: {
        shop: '',
        dateFrom: '',
        dateTo: '',
        search: ''
    },
    
    /**
     * Inicialización
     */
    init: function(config) {
        this.adminLink = config.adminLink || "";
        this.token = config.token || "";
        
        this.bindEvents();
        this.loadProcessedOrders();
    },
    
    /**
     * Eventos
     */
    bindEvents: function() {
        var self = this;
        
        // Filtros
        $('#filter-shop').on('change', function() {
            self.filters.shop = $(this).val();
            self.currentPage = 1;
            self.loadProcessedOrders();
        });
        
        $('#filter-date-from').on('change', function() {
            self.filters.dateFrom = $(this).val();
            self.currentPage = 1;
            self.loadProcessedOrders();
        });
        
        $('#filter-date-to').on('change', function() {
            self.filters.dateTo = $(this).val();
            self.currentPage = 1;
            self.loadProcessedOrders();
        });
        
        $('#filter-search').on('keyup input', this.debounce(function() {
            self.filters.search = $(this).val();
            self.currentPage = 1;
            self.loadProcessedOrders();
        }, 500));
        
        // Limpiar filtros
        $('#clear-filters').on('click', function() {
            self.clearFilters();
        });
        
        // Exportar
        $('#export-orders').on('click', function() {
            self.exportOrders();
        });
        
        // Actualizar
        $('#refresh-orders').on('click', function() {
            self.loadProcessedOrders();
        });
    },
    
    /**
     * Cargar pedidos procesados
     */
    loadProcessedOrders: function() {
        var self = this;
        
        $('#orders-loading').show();
        $('#orders-table-container').hide();
        $('#orders-error').hide();
        
        var params = {
            action: 'get_processed_orders',
            page: this.currentPage,
            limit: this.ordersPerPage,
            shop: this.filters.shop,
            dateFrom: this.filters.dateFrom,
            dateTo: this.filters.dateTo,
            search: this.filters.search,
            token: this.token
        };
        
        $.ajax({
            url: this.adminLink,
            method: 'GET',
            data: params,
            dataType: 'json',
            timeout: 30000,
            success: function(response) {
                if (response.success && response.orders) {
                    self.renderOrdersTable(response.orders);
                    self.updatePagination(response.total || response.orders.length);
                    self.updateStats(response.stats);
                } else {
                    self.showError('Error al cargar pedidos: ' + (response.message || 'Error desconocido'));
                }
            },
            error: function(xhr, status, error) {
                var message = 'Error de comunicación';
                if (status === 'timeout') {
                    message += ' (tiempo agotado)';
                } else if (xhr.responseJSON && xhr.responseJSON.message) {
                    message += ': ' + xhr.responseJSON.message;
                }
                self.showError(message);
            },
            complete: function() {
                $('#orders-loading').hide();
            }
        });
    },
    
    /**
     * Renderizar tabla de pedidos
     */
    renderOrdersTable: function(orders) {
        var tableBody = $('#orders-table tbody');
        tableBody.empty();
        
        if (orders.length === 0) {
            var noDataRow = '<tr><td colspan="9" class="text-center text-muted">' +
                           '<i class="icon-info-circle"></i> No se encontraron pedidos con los filtros aplicados' +
                           '</td></tr>';
            tableBody.html(noDataRow);
            $('#orders-table-container').show();
            return;
        }
        
        orders.forEach(function(order) {
            var row = '<tr>' +
                     '<td><a href="' + order.order_url + '" target="_blank" class="btn btn-link btn-sm">' +
                     '<i class="icon-external-link"></i> #' + order.id_mother_order + '</a></td>' +
                     '<td>' + this.esc(order.reference) + '</td>' +
                     '<td>' + this.esc(order.shop_name) + '</td>' +
                     '<td>' + this.esc(order.customer_name) + '<br><small class="text-muted">' + this.esc(order.customer_email) + '</small></td>' +
                     '<td><span class="badge badge-info">' + order.original_orders + '</span></td>' +
                     '<td><span class="badge badge-warning">' + order.total_products + '</span></td>' +
                     '<td><strong>' + order.total_paid + '€</strong></td>' +
                     '<td>' + this.formatDate(order.date_processed) + '</td>' +
                     '<td>' +
                     '<div class="btn-group">' +
                     '<button type="button" class="btn btn-xs btn-info" onclick="ProcessedOrders.viewOrderDetails(' + order.id_mother_order + ')" title="Ver detalles">' +
                     '<i class="icon-eye"></i>' +
                     '</button>' +
                     '<a href="' + order.order_url + '" target="_blank" class="btn btn-xs btn-primary" title="Abrir pedido">' +
                     '<i class="icon-external-link"></i>' +
                     '</a>' +
                     '</div>' +
                     '</td>' +
                     '</tr>';
            tableBody.append(row);
        }.bind(this));
        
        $('#orders-table-container').show();
    },
    
    /**
     * Actualizar paginación
     */
    updatePagination: function(total) {
        this.totalOrders = total;
        var totalPages = Math.ceil(total / this.ordersPerPage);
        
        var paginationHtml = '';
        
        if (totalPages > 1) {
            paginationHtml += '<ul class="pagination">';
            
            // Anterior
            if (this.currentPage > 1) {
                paginationHtml += '<li><a href="#" onclick="ProcessedOrders.goToPage(' + (this.currentPage - 1) + '); return false;">&laquo;</a></li>';
            }
            
            // Páginas
            var startPage = Math.max(1, this.currentPage - 2);
            var endPage = Math.min(totalPages, this.currentPage + 2);
            
            for (var i = startPage; i <= endPage; i++) {
                var activeClass = (i === this.currentPage) ? ' class="active"' : '';
                paginationHtml += '<li' + activeClass + '><a href="#" onclick="ProcessedOrders.goToPage(' + i + '); return false;">' + i + '</a></li>';
            }
            
            // Siguiente
            if (this.currentPage < totalPages) {
                paginationHtml += '<li><a href="#" onclick="ProcessedOrders.goToPage(' + (this.currentPage + 1) + '); return false;">&raquo;</a></li>';
            }
            
            paginationHtml += '</ul>';
        }
        
        $('#pagination-container').html(paginationHtml);
        
        // Información de paginación
        var startItem = ((this.currentPage - 1) * this.ordersPerPage) + 1;
        var endItem = Math.min(this.currentPage * this.ordersPerPage, total);
        if (total === 0) {
            $('#pagination-info').text('');
            return;
        }
        var paginationInfo = 'Mostrando ' + startItem + ' - ' + endItem + ' de ' + total + ' pedidos';
        $('#pagination-info').text(paginationInfo);
    },
    
    /**
     * Ir a página específica
     */
    goToPage: function(page) {
        this.currentPage = page;
        this.loadProcessedOrders();
    },
    
    /**
     * Actualizar estadísticas
     */
    updateStats: function(stats) {
        if (stats) {
            $('#stats-total').text(stats.total || 0);
            $('#stats-today').text(stats.today || 0);
            $('#stats-this-month').text(stats.thisMonth || 0);
            $('#stats-revenue').text((stats.revenue || 0).toFixed(2) + '€');
        }
    },
    
    /**
     * Ver detalles del pedido
     */
    viewOrderDetails: function(orderId) {
        var self = this;
        
        $('#orderDetailsModal').modal('show');
        $('#orderDetailsContent').html('<div class="text-center"><i class="icon-spinner icon-spin"></i> Cargando detalles...</div>');
        
        $.ajax({
            url: this.adminLink,
            method: 'GET',
            data: {
                action: 'get_order_details',
                id_order: orderId,
                token: this.token
            },
            dataType: 'json',
            success: function(response) {
                if (response.success && response.order) {
                    self.renderOrderDetails(response.order);
                } else {
                    $('#orderDetailsContent').html('<div class="alert alert-danger">Error al cargar detalles: ' + (response.message || 'Error desconocido') + '</div>');
                }
            },
            error: function() {
                $('#orderDetailsContent').html('<div class="alert alert-danger">Error de comunicación al cargar detalles</div>');
            }
        });
    },
    
    /**
     * Renderizar detalles del pedido
     */
    renderOrderDetails: function(order) {
        var self = this;
        var content = '<div class="row">';
        
        // Información general
        content += '<div class="col-md-6">';
        content += '<h5><i class="icon-info-circle"></i> Información General</h5>';
        content += '<table class="table table-condensed">';
        content += '<tr><td><strong>ID Pedido:</strong></td><td>#' + order.id_order + '</td></tr>';
        content += '<tr><td><strong>Referencia:</strong></td><td>' + order.reference + '</td></tr>';
        content += '<tr><td><strong>Estado:</strong></td><td><span class="badge badge-default">' + order.state_name + '</span></td></tr>';
        content += '<tr><td><strong>Total:</strong></td><td><strong>' + order.total_paid + '</strong></td></tr>';
        content += '<tr><td><strong>Fecha:</strong></td><td>' + this.formatDate(order.date_add) + '</td></tr>';
        content += '</table>';
        content += '</div>';
        
        // Información del cliente
        content += '<div class="col-md-6">';
        content += '<h5><i class="icon-user"></i> Cliente</h5>';
        content += '<table class="table table-condensed">';
        content += '<tr><td><strong>Nombre:</strong></td><td>' + order.customer_name + '</td></tr>';
        content += '<tr><td><strong>Email:</strong></td><td>' + order.customer_email + '</td></tr>';
        content += '<tr><td><strong>Dirección:</strong></td><td>' + order.delivery_address + '</td></tr>';
        if (order.carrier_name) {
            content += '<tr><td><strong>Transportista:</strong></td><td>' + order.carrier_name + '</td></tr>';
        }
        content += '</table>';
        content += '</div>';
        
        content += '</div>';
        
        // Información de sincronización
        if (order.sync_info) {
            content += '<div class="row"><div class="col-md-12">';
            content += '<h5><i class="icon-refresh"></i> Información de Sincronización</h5>';
            content += '<table class="table table-condensed">';
            content += '<tr><td><strong>Tienda Origen:</strong></td><td>' + order.sync_info.shop_name + '</td></tr>';
            content += '<tr><td><strong>Pedidos Originales:</strong></td><td><span class="badge badge-info">' + order.sync_info.original_orders + '</span>' + (order.sync_info.original_ids ? ' <small class="text-muted">#' + this.esc(order.sync_info.original_ids) + '</small>' : '') + '</td></tr>';
            content += '<tr><td><strong>Fecha Sincronización:</strong></td><td>' + this.formatDate(order.sync_info.date_sync) + '</td></tr>';
            content += '<tr><td><strong>Fecha Procesamiento:</strong></td><td>' + this.formatDate(order.sync_info.date_processed) + '</td></tr>';
            content += '</table>';
            content += '</div></div>';
        }
        
        // Productos
        if (order.products && order.products.length > 0) {
            content += '<div class="row"><div class="col-md-12">';
            content += '<h5><i class="icon-shopping-cart"></i> Productos (' + order.products.length + ')</h5>';
            content += '<div class="table-responsive">';
            content += '<table class="table table-striped table-condensed">';
            content += '<thead><tr><th>Producto</th><th>Referencia</th><th>Cantidad</th><th>Precio Unit.</th><th>Total</th></tr></thead>';
            content += '<tbody>';
            
            order.products.forEach(function(product) {
                content += '<tr>';
                content += '<td>' + self.esc(product.product_name) + '</td>';
                content += '<td><code>' + self.esc(product.product_reference) + '</code></td>';
                content += '<td><span class="badge badge-info">' + product.product_quantity + '</span></td>';
                content += '<td>' + parseFloat(product.unit_price).toFixed(2) + '€</td>';
                content += '<td><strong>' + parseFloat(product.total_price).toFixed(2) + '€</strong></td>';
                content += '</tr>';
            });
            
            content += '</tbody></table>';
            content += '</div>';
            content += '</div></div>';
        }
        
        $('#orderDetailsContent').html(content);
    },
    
    /**
     * Limpiar filtros
     */
    clearFilters: function() {
        this.filters = {
            shop: '',
            dateFrom: '',
            dateTo: '',
            search: ''
        };
        
        $('#filter-shop').val('');
        $('#filter-date-from').val('');
        $('#filter-date-to').val('');
        $('#filter-search').val('');
        
        this.currentPage = 1;
        this.loadProcessedOrders();
    },
    
    /**
     * Exportar pedidos
     */
    exportOrders: function() {
        var params = {
            action: 'export_processed',
            shop: this.filters.shop,
            dateFrom: this.filters.dateFrom,
            dateTo: this.filters.dateTo,
            search: this.filters.search,
            token: this.token
        };
        
        var url = this.adminLink + '&' + $.param(params);
        window.location.href = url;
    },
    
    /**
     * Mostrar error
     */
    showError: function(message) {
        $('#orders-error .error-message').text(message);
        $('#orders-error').show();
        $('#orders-table-container').hide();
    },
    
    /**
     * Formatear fecha
     */
    formatDate: function(dateString) {
        if (!dateString) return '';
        var date = new Date(dateString);
        return date.toLocaleDateString('es-ES', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    },
    
    esc: function(text) {
        return $('<div>').text(text == null ? '' : String(text)).html();
    },

    /**
     * Debounce para búsqueda
     */
    debounce: function(func, wait) {
        var timeout;
        return function executedFunction() {
            var later = function() {
                clearTimeout(timeout);
                func.apply(this, arguments);
            }.bind(this);
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }
};

// Función global para mantener compatibilidad
function viewOrderDetails(orderId) {
    ProcessedOrders.viewOrderDetails(orderId);
}

// Inicialización cuando el DOM esté listo
$(document).ready(function() {
    console.log('ProcessedOrders JS cargado');
});