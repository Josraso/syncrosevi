# Changelog — SyncroSevi

## 1.4.3 — 2026-11-27
- Vuelven a saltar solos los crons de la noche. Desde la 1.4.2, traer pedidos (`sync`) y consolidar (`process`) compartían el mismo bloqueo: si los dos crons saltaban a la vez, el segundo veía «ya hay una en marcha» y no hacía nada. Al lanzarlos a mano de uno en uno sí funcionaban.
- Ahora cada uno tiene su propio bloqueo: pueden ir a la vez, como antes de la 1.4.2, y lo único que se evita es que vayan dos pasadas de la misma acción a la vez.

## 1.4.2 — 2026-11-25
- El cron por URL (webhook `sync` y `process`) recibe la respuesta al momento («Recibido») y la sincronización sigue en segundo plano. Se acaba el «Status: 503 Service Temporarily Unavailable» de Plesk en las pasadas largas, que salía aunque la sincronización se hacía bien.
- No pueden ir dos pasadas del webhook a la vez: si llega otra mientras una trabaja, contesta «ya hay una en marcha», no hace nada y lo apunta en el registro. Evita pedidos madre repetidos si una pasada se alarga.
- El resultado de cada pasada sigue quedando en el registro del módulo, como siempre. Para ver el resultado completo en la respuesta (como antes), añade `&wait=1` a la URL.
- No cambia nada de cómo sincroniza ni procesa, ni los botones del panel, ni el cron por línea de comandos, ni el aviso en tiempo real de las tiendas hijas.

## 1.4.1 — 2026-11-17
- Pedidos procesados: los lotes sin pedido madre salen en una tabla igual que la de pedidos (tienda, pedidos de la hija, «Fuera», fecha) con el botón de ver detalle, en vez del resumen de texto.
- La ventana de detalle muestra los no importados en una tabla (referencia, producto, unidades, pedidos de la hija).
- Junto a cada referencia que no existe, pista con las referencias parecidas que sí hay en la madre (por ejemplo «41109B → en la madre hay 41109»), o «Nada parecido en la madre». Solo informa: no cambia lo que entra.

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
