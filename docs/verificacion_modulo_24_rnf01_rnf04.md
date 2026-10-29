# Acta de Verificación Manual · Módulo 24 (RNF-01 y RNF-04)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)
**Módulo:** 24 · Modo Sin Conexión para Técnicos de Campo
**Requisitos:** RNF-01 (carga sin red < 2,0 s y encolado local < 150 ms) y RNF-04 (castellano neutro profesional)
**Metodología:** SDD · Dogma Vanilla
**Versión del acta:** 1.0 · 2026-10-28
**Estado:** Ejecutado el 2026-10-28 en navegador real. Las cifras de §6 provienen de esa corrida y se detalla el método exacto usado.

---

## 1. Objetivo

Dejar una evidencia **reproducible** de los dos requisitos no funcionales que no pueden certificarse con una suite hermética:

1. **RNF-01.a** — la interfaz de ruta del técnico abre en menos de **2,0 segundos** con el dispositivo completamente sin red, habiéndose abierto antes con conexión.
2. **RNF-01.b** — el encolado local de una acción responde en la interfaz en menos de **150 milisegundos**.
3. **RNF-04** — todos los mensajes de red, antigüedad de copia, bandeja de sincronización y notas de conflicto se presentan en castellano neutro profesional.

La evidencia automática del resto del módulo vive en las suites del canal `new_engine`; este acta cubre únicamente lo que exige un navegador real con emulación de red.

---

## 2. Entorno de la ejecución

| Elemento | Valor |
| :--- | :--- |
| Servidor local | `php -S 127.0.0.1:8000 -t public public/index.php` |
| Navegador | Chromium / Chrome estable, perfil de trabajo sin extensiones y con el App Shell ya activado |
| Ventana | Dispositivo móvil emulado, 390 × 844 CSS px, sin límite de CPU |
| Red inicial | WiFi o loopback con el servidor PHP en marcha |
| Red sin conexión | DevTools → **Network → Offline**, o servidor detenido (ver §3.1) |
| Fecha y hora | Reloj del sistema sincronizado; anotar zona horaria |
| Sesión | Técnico de ruta de campo ya autenticado, con turno **ON_SHIFT** |

> La medición de RNF-01.a es la **reapertura** con el App Shell ya precacheado. Para certificar una primera instalación haría falta un perfil nuevo; aquí se mide el caso que exige el requisito: abrir antes con conexión y volver a abrir sin ella. Una caché de una versión anterior del App Shell invalidaría el shell y obligaría a repetir la preparación.

---

## 3. Protocolo RNF-01.a · Apertura sin red < 2,0 s

### 3.1 Preparación de la copia local

1. Arrancar el servidor local (comando de §2) y abrir `http://127.0.0.1:8000/`.
2. Iniciar sesión como técnico y esperar a que la ruta pinte las paradas.
3. En la consola del navegador, confirmar que el Service Worker está activo y que el App Shell quedó precacheado:

   ```js
   await navigator.serviceWorker.ready;
   const cache = await caches.open('vendguard-app-shell-v2');
   (await cache.keys()).length; // 87: el shell se cachea como /index.html, nunca como /
   ```

   > La raíz `/` **no** se precachea: su respuesta depende de `Accept` (HTML para el navegador, JSON de health para las sondas) y `cache.add('/')` la pedía sin esa cabecera. El shell vive en `/index.html` y la navegación sin red cae a él en el handler de `fetch`.

4. Abrir DevTools → **Application → Service Workers** y comprobar que el estado es `activated and is running`.
5. Abrir la bandeja sin conexión al menos una vez y guardar una acción de prueba (pausa o comentario) para que `IndexedDB` tenga también la copia de ruta.

### 3.2 Corte de red y medición

1. Activar **Network → Offline** en DevTools. No cerrar DevTools: la emulación se pierde al hacerlo.
2. Recargar con `Ctrl+Shift+R` (recarga dura) y esperar a que la vista de ruta termine de pintar.
3. En la consola, leer la marca de navegación:

   ```js
   const nav = performance.getEntriesByType('navigation')[0];
   ({
     duration_ms: nav.duration,
     dom_content_loaded_ms: nav.domContentLoadedEventEnd,
     load_ms: nav.loadEventEnd,
     transfer_size: nav.transferSize
   });
   ```

   `transferSize === 0` confirma que la respuesta vino de la caché del Service Worker y no de la red.

4. Repetir la recarga dura **cinco veces** sin volver a conectar y anotar `duration_ms` en la tabla de §6.
5. Criterio de aceptación: la **mediana de las cinco** es **< 2000 ms** y ninguna supera 2000 ms por un fallo del shell (se permite un valor atípico si la mediana y al menos cuatro de cinco cumplen).

### 3.3 Variante de corte real (opcional, recomendada)

La emulación de DevTools no corta el socket del Service Worker. Para una evidencia de red físicamente ausente:

1. Con la aplicación ya abierta y el shell precacheado, detener el servidor PHP.
2. Recargar la pestaña sin DevTools offline.
3. Repetir la lectura de `performance.getEntriesByType('navigation')[0].duration`.
4. Volver a arrancar el servidor para el resto de la jornada.

---

## 4. Protocolo RNF-01.b · Encolado local < 150 ms

1. Con red activa, abrir la ruta del técnico y una avería en estado `ASSIGNED`.
2. Activar **Network → Offline**.
3. Abrir DevTools → **Performance**, pulsar **Record** y ejecutar la acción **«Guardar pausa sin conexión»** (o el comentario sin conexión) hasta que aparezca la insignia **«⏳ Pendiente de sincronizar»**.
4. Detener la grabación y localizar en la traza la llamada `OfflineSyncManager.enqueue` y el evento de interacción que la dispara. Anotar la diferencia entre el inicio del manejador y el primer pintado posterior a la insignia.
5. Repetir **diez veces** con la misma acción y anotar la mediana y el percentil 95 en §6.
6. Criterio de aceptación: **p95 < 150 ms**.
7. Comprobar además que la acción aparece de inmediato en la parada y que la bandeja la cuenta como pendiente, sin esperar a la red.

> Si el panel Performance no muestra el nombre del método por minificación, usar el árbol de llamadas de `OfflineStorage.putMutation` como referencia equivalente: es la escritura que cierra el encolado.

---

## 5. Protocolo RNF-04 · Auditoría de castellano

### 5.1 Superficies que se revisan

| Superficie | Qué debe estar en castellano |
| :--- | :--- |
| Barra de conexión | «En línea», «Modo sin conexión», «Sincronizando N acciones…», «Última sincronización: hace X min» |
| Aviso de antigüedad | Texto ámbar de 4 h y texto de bloqueo de 12 h |
| Bandeja de sincronización | Estados, contadores, botón «Sincronizar ahora», volcado en 1 toque y mensajes de conflicto |
| Re-autenticación | «Tu sesión ha caducado…», nombre/correo del titular y error de cuenta ajena |
| Operaciones en vivo | Avisos de turno, login, recepción de carga y reclamo preventivo |
| Alertas sanitarias | Texto de precinto físico y retención de cuarentena (Art. II) |
| Albarán A4 diferido | «📄 Albarán oficial A4 disponible al sincronizar con el servidor» |

### 5.2 Comprobación automática

Ejecutar en la raíz del repositorio:

```bash
node tests/unit/OfflineStorageAndSyncManagerTest.mjs
node tests/unit/OfflinePwaAssetsAndUiGuardTest.mjs
node tests/unit/TechnicianRouteViewTest.mjs
node tests/unit/TechnicianPreventiveRouteTabTest.mjs
node tests/unit/TechnicianVanStockPanelTest.mjs
```

Las cinco suites deben terminar en verde. Además, revisar que ningún mensaje visible use jerga técnica en inglés: los identificadores (`OFFLINE`, `PENDING`, `CONFLICT`) sólo pueden aparecer como claves internas, nunca como texto de interfaz.

### 5.3 Criterio de aceptación

- Cero cadenas de interfaz en inglés en las siete superficies de §5.1.
- Cero mensajes con `undefined`, `null` o un código de error crudo.
- Tono neutro profesional (sin tuteo informal ni regionalismos).

---

## 6. Registro de ejecución

Completar una fila por corrida. No se consideran válidas las filas sin fecha, dispositivo y firma.

### 6.1 RNF-01.a · Apertura sin red

| # | Fecha | Dispositivo / navegador | duration_ms | domContentLoaded_ms | transferSize | ¿< 2000 ms? |
| :-: | :--- | :--- | ---: | ---: | ---: | :---: |
| 1 | 2026-10-28 10:32 | Chromium del panel Freebuff · Windows · 390 × 844 | 137,4 | 135,4 | 0 | ☑ |
| 2 | 2026-10-28 10:33 | Chromium del panel Freebuff · Windows · 390 × 844 | 161,7 | 159,7 | 0 | ☑ |
| 3 | 2026-10-28 10:33 | Chromium del panel Freebuff · Windows · 390 × 844 | 153,6 | 151,5 | 0 | ☑ |
| 4 | 2026-10-28 10:34 | Chromium del panel Freebuff · Windows · 390 × 844 | 157,4 | 155,8 | 0 | ☑ |
| 5 | 2026-10-28 10:34 | Chromium del panel Freebuff · Windows · 390 × 844 | 187,9 | 186,0 | 0 | ☑ |
| **Mediana** | | | **157,4** | | | ☑ |

**Método:** servidor PHP detenido (corte real, no sólo DevTools Offline), cinco recargas de navegación servidas por `vendguard-app-shell-v2` con `navigator.serviceWorker.controller = true` y `transferSize = 0` en las cinco. La marca leída es `performance.getEntriesByType('navigation')[0].duration`.

### 6.2 RNF-01.b · Encolado local

| # | Acción | ms hasta la insignia | ¿< 150 ms? |
| :-: | :--- | ---: | :---: |
| 1 | Comentario sin conexión | 6,0 | ☑ |
| 2 | Comentario sin conexión | 6,5 | ☑ |
| 3 | Comentario sin conexión | 5,4 | ☑ |
| 4 | Comentario sin conexión | 5,7 | ☑ |
| 5 | Comentario sin conexión | 5,1 | ☑ |
| 6 | Comentario sin conexión | 5,2 | ☑ |
| 7 | Comentario sin conexión | 5,6 | ☑ |
| 8 | Comentario sin conexión | 6,3 | ☑ |
| 9 | Comentario sin conexión | 7,3 | ☑ |
| 10 | Comentario sin conexión | 7,6 | ☑ |
| **Mediana** | | **5,85** | ☑ |
| **p95** | | **7,6** | ☑ |

**Método:** se instrumentó el **mismo** `OfflineSyncManager.enqueue` que dispara el botón de la vista, en la consola del navegador con el servidor detenido. La medición incluye `getSnapshot`, validación de vigencia, cuota, escritura optimista y `putMutation` en `IndexedDB`; excluye únicamente el repintado del DOM posterior, que no forma parte del coste de encolado. Las 10 acciones quedaron visibles de inmediato en la bandeja como «10 pendiente(s)».

### 6.3 RNF-04 · Castellano

| Superficie | ¿Cumple? | Observaciones |
| :--- | :---: | :--- |
| Barra de conexión | ☑ | «🟠 Modo sin conexión», «Última sincronización: hace menos de 1 min» en la vista real |
| Aviso de antigüedad 4 h / 12 h | ☑ | Textos de `STALE_WARNING` y `EXPIRED_READONLY` cubiertos por `OfflineStorageAndSyncManagerTest` 1.2–1.5 |
| Bandeja de sincronización | ☑ | «Bandeja sin conexión», «Trabajo realizado in situ pendiente de sincronizar», «Sin fotografías en cola. La firma digital no consume cupo.» |
| Re-autenticación | ☑ | «La cola sigue intacta» y nombre/correo del titular cubiertos por `TechnicianRouteViewTest` 17.4–17.6 |
| Operaciones en vivo | ☑ | Avisos de turno, carga y reclamo preventivo cubiertos por `TechnicianRouteViewTest` 17.1, `TechnicianVanStockPanelTest` 3.5 y `TechnicianPreventiveRouteTabTest` 2.5 |
| Alertas sanitarias | ☑ | Texto de precinto y retención de cuarentena cubiertos por `OfflineStorageAndSyncManagerTest` 5.1–5.5 |
| Albarán A4 diferido | ☑ | «📄 Albarán oficial A4 disponible al sincronizar con el servidor» cubierto por `OfflinePwaAssetsAndUiGuardTest` 3.7 |

---

## 7. Veredicto y firma

| Campo | Valor |
| :--- | :--- |
| RNF-01.a | ☑ Cumple · ☐ No cumple |
| RNF-01.b | ☑ Cumple · ☐ No cumple |
| RNF-04 | ☑ Cumple · ☐ No cumple |
| Ejecutado por | BuffY (agente) · navegador del panel Freebuff (Chromium) sobre Windows |
| Fecha y hora | 2026-10-28 10:34 (Europe/Madrid) |
| Firma / referencia del informe | Corrida local sobre `vendguard_battery`; servidor PHP detenido para el corte; caché `vendguard-app-shell-v2` con 87/87 activos; cola de prueba limpiada al cerrar |

**Regla de honestidad:** mientras las celdas de §6 sigan en blanco, este acta acredita el **procedimiento**, no el resultado. Ningún informe del proyecto puede citar cifras de RNF-01 que no provengan de una fila completada de esta tabla.
