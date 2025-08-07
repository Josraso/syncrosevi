/**
 * 2024 SyncroSevi
 * 
 * JavaScript para el panel de administración - CORREGIDO
 */

var customers_cache = [];
var addresses_cache = {};
var adminLink = "";

$(document).ready(function() {
    // Inicializar tooltips
    $('[data-toggle="tooltip"]').tooltip();
    
    // Configurar confirmaciones de eliminación
    setupDeleteConfirmations();
    
    // Ocultar resultados al hacer clic fuera
    $(document).on("click", function(e) {
        if (!$(e.target).closest(".customer-search").length) {
            $(".customer-results").hide();
        }
    });
});

/**
 * Búsqueda de clientes
 */
function searchCustomers(query, resultId, customerId) {
    if (query.length < 2) {
        $("#" + resultId).hide();
        return;
    }
    
    var filtered = customers_cache.filter(function(c) {
        return (c.firstname + " " + c.lastname + " " + c.email).toLowerCase().indexOf(query.toLowerCase()) !== -1;
    });
    
    showCustomerResults(filtered.slice(0, 10), resultId, customerId);
}

function showCustomerResults(customers, resultId, customerId) {
    var resultsDiv = $("#" + resultId);
    resultsDiv.html("");
    
    customers.forEach(function(customer) {
        var div = $("<div>")
            .addClass("customer-item")
            .html("<strong>" + customer.firstname + " " + customer.lastname + "</strong><br><small>" + customer.email + "</small>")
            .click(function() { 
                selectCustomer(customer, resultId, customerId); 
            });
        resultsDiv.append(div);
    });
    
    resultsDiv.toggle(customers.length > 0);
}

function selectCustomer(customer, resultId, customerId) {
    var searchField = $("#" + resultId.replace("-results", "-search"));
    searchField.val(customer.firstname + " " + customer.lastname);
    $("#" + customerId).val(customer.id_customer);
    $("#" + resultId).hide();
    loadCustomerAddresses(customer.id_customer, resultId.replace("customer-results", "id_address"));
}

function loadCustomerAddresses(customerId, addressSelectId) {
    if (addresses_cache[customerId]) {
        populateAddresses(addresses_cache[customerId], addressSelectId);
        return;
    }
    
    // Simular carga (en implementación real sería AJAX)
    var allAddresses = typeof addresses_all !== 'undefined' ? addresses_all : [];
    var customerAddresses = allAddresses.filter(function(addr) {
        return addr.id_customer == customerId;
    });
    
    addresses_cache[customerId] = customerAddresses;
    populateAddresses(customerAddresses, addressSelectId);
}

function populateAddresses(addresses, selectId) {
    var select = $("#" + selectId);
    select.html('<option value="">Seleccionar dirección...</option>');
    
    addresses.forEach(function(address) {
        var option = $("<option>")
            .val(address.id_address)
            .text(address.alias + " - " + address.address1 + ", " + address.city + " (" + address.postcode + ")");
        select.append(option);
    });
    
    if (addresses.length === 0) {
        select.html('<option value="">Este cliente no tiene direcciones</option>');
    }
}

/**
 * Toggle transporte
 */
function toggleTransport(show, containerId) {
    var container = $("#" + (containerId || "transport-options"));
    container.toggle(show);
    if (!show) {
        container.find("select").val("");
    }
}

/**
 * Probar conexión CORREGIDO
 */
function testConnection(shopId) {
    if (!shopId) {
        showAlert('error', 'ID de tienda inválido');
        return;
    }
    
    var btn = $('button[onclick*="testConnection(' + shopId + ')"]');
    var originalHtml = btn.html();
    
    btn.html('<i class="icon-spinner icon-spin"></i>').prop('disabled', true);
    
    $.ajax({
        url: adminLink + "&action=test&id_shop=" + shopId,
        method: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert("✓ Conexión exitosa con la tienda\n\nInfo: " + (response.shop_info.name || "Tienda conectada"));
            } else {
                alert("✗ Error de conexión\n\n" + response.message);
            }
        },
        error: function() {
            alert("Error de comunicación");
        },
        complete: function() {
            btn.html(originalHtml).prop('disabled', false);
        }
    });
}

/**
 * Cargar estados de la tienda hija - CORREGIDO
 */
function loadStatesFromShop() {
    var url = $("#shop_url").val();
    var apiKey = $("#api_key").val();
    
    if (!url || !apiKey) {
        alert("Completa primero URL y API Key");
        return;
    }
    
    var btn = $("#load-states-btn");
    btn.html('<i class="icon-spinner icon-spin"></i> Cargando...').prop('disabled', true);
    
    $.ajax({
        url: adminLink + "&action=get_states",
        method: 'GET',
        data: {
            shop_url: url,
            api_key: apiKey
        },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.states) {
                var container = $("#import-states-container");
                container.html("");
                
                response.states.forEach(function(state) {
                    var div = $("<div>")
                        .addClass("checkbox")
                        .html('<label><input type="checkbox" name="import_states[]" value="' + state.id + '"> ' + state.name + '</label>');
                    container.append(div);
                });
                
                container.show();
                showAlert('success', 'Estados cargados correctamente');
            } else {
                showAlert('error', 'Error cargando estados: ' + (response.message || 'Error desconocido'));
            }
        },
        error: function() {
            showAlert('error', 'Error de comunicación al cargar estados');
        },
        complete: function() {
            btn.html('Cargar Estados de la Tienda Hija').prop('disabled', false);
        }
    });
}
/**
 * Editar tienda
 */
function editShop(shopId) {
    window.location.href = adminLink + "&action=edit&id_shop=" + shopId;
}

/**
 * Eliminar tienda
 */
function deleteShop(shopId) {
    if (confirm('¿Estás seguro de eliminar esta tienda? Esta acción no se puede deshacer.')) {
        window.location.href = adminLink + "&action=delete&id_shop=" + shopId;
    }
}

/**
 * Mostrar alertas
 */
function showAlert(type, message) {
    var alertClass = 'alert-info';
    var icon = 'icon-info';
    
    switch(type) {
        case 'success':
            alertClass = 'alert-success';
            icon = 'icon-check';
            break;
        case 'error':
            alertClass = 'alert-danger';
            icon = 'icon-warning';
            break;
        case 'warning':
            alertClass = 'alert-warning';
            icon = 'icon-exclamation';
            break;
    }
    
    var alertHtml = '<div class="alert ' + alertClass + ' alert-dismissible fade-in">' +
                   '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                   '<i class="' + icon + '"></i> ' + message +
                   '</div>';
    
    $('.panel-body').first().prepend(alertHtml);
    
    if (type !== 'error') {
        setTimeout(function() {
            $('.alert').first().fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
    }
}

/**
 * Configurar confirmaciones de eliminación
 */
function setupDeleteConfirmations() {
    $(document).on('click', '[data-confirm]', function(e) {
        var message = $(this).data('confirm') || '¿Estás seguro?';
        if (!confirm(message)) {
            e.preventDefault();
            return false;
        }
    });
}