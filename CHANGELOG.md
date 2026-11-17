# Changelog — SyncroSevi

## 1.4.0 — 2026-11-17
- Entra en la tienda madre **todo producto cuya referencia coincida**, aunque esté desactivado, no esté disponible para pedidos, no tenga stock, no llegue a la cantidad mínima o sea un pack sin stock en sus componentes. Vale para el modo consolidado y para el modo clon.
- **Ya no se suma stock en la madre** para que quepa el pedido: el stock baja con el pedido como en cualquier venta (puede quedar en negativo).
- El pedido madre se queda siempre con el **estado configurado** en la tienda hija: si PrestaShop lo pasa a «En espera de reposición» por falta de stock, se vuelve a poner el estado configurado.
- Solo se queda fuera lo que no coincide por referencia (o viene sin referencia), y **queda apuntado línea a línea**.
- Mensaje del pedido madre con la fecha en castellano (dd/mm/aaaa) y, debajo, los productos no importados agrupados por referencia: referencia, descripción, unidades y pedidos de la hija. Si la lista es muy larga se corta con «… y N más».
- Pedidos procesados:
  - columna «Fuera» con las referencias que no entraron, y filtro «Solo con productos fuera»;
  - en el detalle del pedido, la lista de no importados;
  - la búsqueda encuentra también por número o referencia del pedido de la hija y por referencia de un producto que se quedó fuera;
  - la columna de pedidos de la hija muestra sus números;
  - se ven los lotes que no crearon pedido madre (ninguna referencia coincidía);
  - fechas en dd/mm/aaaa;
  - el CSV incluye los productos fuera y los lotes sin pedido madre.
- En el registro del módulo, una línea por cada producto que se queda fuera (con su pedido de la hija) y un resumen por lote.
- La referencia del pedido de la hija se guarda desde esta versión (para buscar por ella). Los pedidos anteriores se buscan por su número.

## 1.3.3 — 2026-11-12
- El cartel de licencia con el botón sale desde la primera vez.

## 1.3.2 — 2026-11-12
- La licencia la decide el panel del servidor de actualizaciones.

## 1.3.1 — 2026-11-11
- Cartel de licencia con botón de activar; se instala desactivado si no hay licencia.

## 1.3.0 — 2026-11-11
- Versión 1.2.5 con modo clonar y conexión al servidor de actualizaciones.
