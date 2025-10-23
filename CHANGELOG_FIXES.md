# Changelog - Correcciones del Módulo SyncroSevi

## Fecha: 2025-10-23

### 🔧 Problemas Corregidos

#### 1. **Token de seguridad variable (URLs del cron cambian con el tiempo)**

**Problema:**
- El token de seguridad se generaba usando `date('Y-m')`, lo que causaba que cambiara cada mes
- Las URLs configuradas en el cron dejaban de funcionar al inicio de cada mes
- Era necesario regenerar las URLs manualmente cada mes

**Solución:**
- Token de seguridad FIJO basado en datos de la tienda que no cambian
- Nueva fórmula: `md5('syncrosevi_' . PS_SHOP_NAME . '_' . PS_SHOP_EMAIL . '_FIXED_TOKEN_2024')`
- Las URLs ahora son permanentes y no necesitan actualizarse

**Archivos modificados:**
- `webhook.php` (línea 37)
- `syncrosevi.php` (líneas 1062 y 1099)

**Acción requerida:**
- **IMPORTANTE**: Deberás regenerar las URLs del cron una última vez desde el panel de administración
- Después de esto, las URLs serán permanentes

---

#### 2. **Saturación al procesar muchos pedidos**

**Problema:**
- El método `processOrders()` procesaba TODOS los pedidos pendientes de TODAS las tiendas en una sola ejecución
- Con muchos pedidos causaba:
  - Timeout de PHP
  - Errores de memoria
  - Bloqueos en la base de datos
  - Errores 500 en el navegador al usar el botón manual

**Solución:**
- Implementado procesamiento por lotes con límites configurables
- Nuevos parámetros opcionales:
  - `$batchSize`: Número máximo de tiendas a procesar (0 = todas, mantiene compatibilidad)
  - `$maxExecutionTime`: Tiempo máximo de ejecución en segundos (0 = sin límite)
- Control automático de tiempo para evitar timeouts
- Logs informativos del progreso

**Archivos modificados:**
- `syncrosevi.php` (método `processOrders()`, líneas 367-414)
- `cron/syncrosevi_cron.php` (método `processOrders()` y función `main()`)
- `webhook.php` (soporte para parámetros de batch)

---

### 📋 Nuevas Funcionalidades

#### Procesamiento por Lotes en Cron

**Uso sin límites (comportamiento original):**
```bash
*/15 * * * * /usr/bin/php /ruta/modules/syncrosevi/cron/syncrosevi_cron.php process
```

**Uso CON límites (recomendado para evitar saturación):**
```bash
# Procesa máximo 5 tiendas cada 30 minutos, con timeout de 60 segundos
*/30 * * * * /usr/bin/php /ruta/modules/syncrosevi/cron/syncrosevi_cron.php process 5 60

# Procesa máximo 10 tiendas cada hora, con timeout de 120 segundos
0 * * * * /usr/bin/php /ruta/modules/syncrosevi/cron/syncrosevi_cron.php process 10 120
```

**Parámetros:**
1. Primer parámetro: Número de tiendas a procesar
2. Segundo parámetro: Tiempo máximo en segundos

#### Webhook con Límites

**URL sin límites (comportamiento original):**
```
https://tutienda.com/modules/syncrosevi/webhook.php?action=process&security_token=XXXXX
```

**URL CON límites:**
```
https://tutienda.com/modules/syncrosevi/webhook.php?action=process&security_token=XXXXX&batch_size=5&max_time=60
```

**Parámetros adicionales:**
- `batch_size`: Número máximo de tiendas a procesar
- `max_time`: Tiempo máximo en segundos

---

### 🎯 Recomendaciones de Uso

#### Para tiendas con POCOS pedidos (1-10 pendientes normalmente):
```bash
# Procesar todo cada hora
0 * * * * /usr/bin/php /ruta/syncrosevi_cron.php process
```

#### Para tiendas con MUCHOS pedidos (más de 20 pendientes):
```bash
# Procesar 5 tiendas cada 30 minutos
*/30 * * * * /usr/bin/php /ruta/syncrosevi_cron.php process 5 60

# O procesar 10 tiendas cada hora
0 * * * * /usr/bin/php /ruta/syncrosevi_cron.php process 10 120
```

#### Para usar el botón manual sin errores:
El botón "Procesar Pedidos" en el panel de administración sigue funcionando igual, procesando todos los pedidos pendientes. Si experimentas timeouts al usarlo:

1. **Opción A**: Usa el cron programado con límites en lugar del botón manual
2. **Opción B**: Aumenta el `max_execution_time` en tu PHP (php.ini)
3. **Opción C**: Procesa menos tiendas por ejecución usando el cron con batch

---

### ✅ Verificación de Funcionamiento

#### Comprobar que el token funciona:
```bash
# Ejecutar el cron manualmente
php /ruta/modules/syncrosevi/cron/syncrosevi_cron.php process

# Si funciona correctamente, verás:
# === INICIANDO PROCESAMIENTO DE PEDIDOS ===
# ✓ [Nombre Tienda]: Pedido #XXX creado...
# === PROCESAMIENTO COMPLETADO ===
```

#### Comprobar el procesamiento por lotes:
```bash
# Ejecutar con límite de 2 tiendas
php /ruta/modules/syncrosevi/cron/syncrosevi_cron.php process 2 30

# Deberías ver en el log:
# Modo BATCH activado: máximo 2 tiendas por ejecución
# Tiempo máximo: 30 segundos
```

#### Ver los logs:
```bash
# Los logs se guardan en:
/ruta/modules/syncrosevi/cron/logs/syncrosevi_cron_YYYY-MM-DD.log

# Ejemplo:
tail -f /ruta/modules/syncrosevi/cron/logs/syncrosevi_cron_2025-10-23.log
```

---

### 🔄 Migración y Compatibilidad

**¿Necesito cambiar algo en mi código?**
- **NO** si usas el cron o botón sin parámetros (comportamiento original mantenido)
- **SÍ** necesitas regenerar las URLs del cron una última vez (por el cambio de token)

**¿Rompe algo existente?**
- **NO**, todos los cambios son retrocompatibles
- Los parámetros nuevos son opcionales (valores por defecto = comportamiento original)
- El botón manual sigue funcionando igual

**Pasos para migrar:**
1. Accede al panel de administración de SyncroSevi
2. Copia las nuevas URLs del webhook (el token habrá cambiado)
3. Actualiza tus crons con las nuevas URLs
4. (Opcional) Añade los parámetros de batch si tienes muchos pedidos

---

### 📊 Logs Mejorados

Ahora los logs muestran información más detallada:

**Antes:**
```
[2025-10-23 10:00:00] === INICIANDO PROCESAMIENTO ===
[2025-10-23 10:05:00] === PROCESAMIENTO COMPLETADO ===
```

**Ahora:**
```
[2025-10-23 10:00:00] === INICIANDO PROCESAMIENTO DE PEDIDOS ===
[2025-10-23 10:00:00] Modo BATCH activado: máximo 5 tiendas por ejecución
[2025-10-23 10:00:00] Tiempo máximo: 60 segundos
[2025-10-23 10:00:05] ✓ Tienda A: Pedido #123 creado con 5 productos
[2025-10-23 10:00:10] ✓ Tienda B: Pedido #124 creado con 3 productos
[2025-10-23 10:00:15] ℹ SYSTEM: Límite de lotes alcanzado. Procesadas 5 tiendas.
[2025-10-23 10:00:15] === PROCESAMIENTO COMPLETADO ===
[2025-10-23 10:00:15] Total procesados: 5 pedidos
```

---

### 🐛 Debugging

Si algo no funciona:

1. **Verificar el token:**
   ```bash
   # En el webhook deberías ver el mismo token que en el panel de admin
   ```

2. **Verificar los logs:**
   ```bash
   tail -100 /ruta/modules/syncrosevi/cron/logs/syncrosevi_cron_*.log
   ```

3. **Probar el webhook manualmente:**
   ```bash
   curl "https://tutienda.com/modules/syncrosevi/webhook.php?action=test&security_token=XXXXX"
   # Debería responder: {"success":true,"action":"test"...}
   ```

4. **Verificar parámetros:**
   ```bash
   # Ver ayuda del cron
   php /ruta/syncrosevi_cron.php
   ```

---

### 📝 Notas Técnicas

- El token ahora es independiente de la fecha
- Los parámetros de batch se aplican por TIENDA, no por pedido
- El control de tiempo verifica antes de procesar cada tienda
- Si se alcanza el límite, los pedidos restantes se procesan en la siguiente ejecución
- La transacción SQL garantiza que o se procesa completamente una tienda o no se procesa nada
- Los pedidos marcados como procesados no se reprocesarán aunque den error

---

**Desarrollado por:** Claude AI
**Fecha:** 23 de Octubre 2025
**Versión del módulo:** 1.0.2+
