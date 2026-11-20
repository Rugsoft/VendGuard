# Informe · Verificación funcional, de humo y E2E de `feat/restocker-sat-synergy`

- **Fecha:** 2026-11-20
- **Rama:** `feat/restocker-sat-synergy`
- **HEAD verificado:** `2777bfd` · `perf(synergy): close the H-36-05 pooler latency budget`
- **Estado del árbol:** limpio antes y después de la corrida inicial de verificación, que **no modificó código ni esquema**. La corrección posterior de V-01 **sí modificó el test** [`tests/integration/IncidentCommentsPerformanceAndSecurityTest.php`](../tests/integration/IncidentCommentsPerformanceAndSecurityTest.php); no se tocó esquema ni código de producto.
- **Alcance:** batería global (`php tests/run_all.php`), canal `new_engine` acotado a las suites de la rama, medición del pooler, arranque de la app local y humo real de UI con los cuatro perfiles (sede, coordinación, técnico SAT y reponedor).
- **Naturaleza del documento:** acta de verificación. La corrida inicial registró el estado observado **sin modificar código**; la corrección de V-01 cambió únicamente el test de rendimiento y seguridad, la de V-02 el login del reponedor y la de V-03 el copy de la ruta de abastecimiento. V-01, V-02 y V-03 quedaron resueltos. La corrida de navegador posterior abrió **H-36-06 a H-36-10**; los cinco hallazgos están corregidos. El detalle está en [hallazgos_pruebas_navegador_spec36.md](hallazgos_pruebas_navegador_spec36.md).
- **Última actualización:** 2026-11-20 · V-01, V-02 y V-03 resueltos; batería global en verde. La verificación de navegador posterior abrió H-36-06 a H-36-10; `H-36-06` se corrigió con hidratación de snapshots y composición HTTP/PostgreSQL, `H-36-07` con selector de motivo en Sede y persistencia en el despacho L1, y `H-36-08` con filtrado de causas por categoría/motivo y rechazo 422 de causas de otra categoría, y `H-36-09` con la derivación de urgencia desde tipo de máquina y categoría y la herencia del perfil SLA de la sede en la rama de ticket SAT nuevo, y `H-36-10` con la publicación de `location_id` en el DTO de máquina y la composición de la Coincidencia en Sede en la vista del reponedor; no quedan hallazgos P1/P2 abiertos (ver [hallazgos_pruebas_navegador_spec36.md](hallazgos_pruebas_navegador_spec36.md)).

---

## 1. Resumen de la corrida

**Corrida inicial (antes de corregir V-01):**

| Comprobación | Comando | Resultado | Estado |
| :--- | :--- | :--- | :--- |
| Batería global | `php tests/run_all.php` con `.env.battery` | 197/197 PHP Unit · 106/106 JS Unit · 134/135 integración · 3/3 E2E · 16.993 aserciones | **exit 1** (1 fallo) |
| E2E por perfil | `E2EVerificationRunner` · `ProductLoadingE2ERunner` · `VanRestockWalkthroughRunner` | 45/45 · 24/24 · 72/72 | verde |
| Canal de la rama | `php tests/run_new_engine.php Restocker` con solo `.env.docker-tls` | 10/10 suites · 267 aserciones | exit 0 |
| Presupuesto de latencia (H-36-05) | `php tests/run_new_engine.php Pooler` | 30/30 aserciones | exit 0 |
| App local | `php -S 127.0.0.1:8000` | `/` → 200 · `/api/health` → 200 | operativa |
| Humo UI real | Navegador sobre los cuatro portales | Carga y autenticación sin errores de consola ni respuestas 500 | verde |

Cifras de H-36-05 confirmadas en Supavisor 6543: «crear» 210,5 ms (p95 271,1), «ruta» 209,4 ms (p95 235,5) y «listar» 210,3 ms, dentro del presupuesto de 250 ms.

**Conclusión tras las correcciones de V-01, V-02 y V-03 (2026-11-20):** la batería global queda en **441 suites · 16.993 aserciones · 0 fallos · exit 0**, con **197/197 PHP Unit, 106/106 JS Unit, 135/135 integración y 3/3 E2E**, y `DocMetricsGuard` declara las cifras coherentes con el README. **La rama queda cerrada:** la verificación de navegador posterior abrió H-36-06 a H-36-10 y los cinco hallazgos ya están corregidos; ver [hallazgos_pruebas_navegador_spec36.md](hallazgos_pruebas_navegador_spec36.md).

---

## 2. Errores encontrados, por orden de importancia

### V-01 · P2 · RESUELTO (2026-11-20) — Aserción frágil de plan de ejecución en `IncidentCommentsPerformanceAndSecurityTest` 1.8

**Resolución.** La aserción 1.8 ya no interpreta el plan del motor. Certifica en el catálogo que `idx_comments_incident` existe, está `indisvalid` e `indisready`, y que sus dos columnas líderes son `incident_id` y `created_at`, que son el filtro y el orden del hilo. La latencia de RNF-02 sigue medida en 1.3–1.7 (mediana 23,81 ms con 1.000 mensajes) y 2.4 conserva la trampa de plan a escala. Verificación: suite focalizada 28/28 y batería global 441 suites · 16.993 aserciones · 0 fallos · exit 0, con `DocMetricsGuard` coherente.

- **Archivo:** [`tests/integration/IncidentCommentsPerformanceAndSecurityTest.php`](../tests/integration/IncidentCommentsPerformanceAndSecurityTest.php)
- **Aserción original:** 1.8 · exigía que la consulta de comentarios usara `idx_comments_incident` (`Index Scan`) con 1.000 comentarios por incidencia.
- **Resultado observado (antes de la corrección):** la aserción fallaba porque PostgreSQL elige `Seq Scan` para ese volumen.
- **Medición real:**

| Volumen | Plan elegido | Latencia |
| :--- | :--- | :--- |
| 1.000 comentarios | `Seq Scan` (falla 1.8) | 23,84 ms |
| 10.000 comentarios | `Index Scan Backward using idx_comments_incident` (2.4 en verde) | 45,24 ms |

- **Causa:** no falta el índice ni se incumple el RNF-02. El índice existe y está definido en [`database/supabase_init.sql`](../database/supabase_init.sql) como `idx_comments_incident ON incident_comments (incident_id, created_at)`. Con 1.000 filas el planificador estima más barato el recorrido secuencial; a partir de 10.000 elige el índice. La aserción mide una decisión del planificador, no una garantía contractual.
- **Efecto derivado:** `DocMetricsGuard` detecta la desviación entre el README (que declara 135/135 integraciones) y la corrida real (134/135), por lo que la batería termina con `exit 1`.
- **Impacto:** ruido de certificación. Un fallo determinista en la suite global bloquea la puerta de calidad aunque el producto cumpla latencia y exista el índice.
- **Corrección aplicada:** validación en catálogo (`pg_indexes` + `pg_index`) de existencia, validez, disponibilidad y columnas líderes (`incident_id`, `created_at`), sin leer el plan. La latencia se mantiene en 1.3–1.7 y la trampa de plan a 10.000 mensajes en 2.4. La cobertura no se debilita: la nueva comprobación es más estricta que buscar el nombre del índice en el texto del plan.

---

### V-02 · P2 · RESUELTO (2026-11-20) — Login del reponedor sin etiquetado accesible

**Resolución.** Las etiquetas «Correo electrónico» y «Contraseña» se asocian con sus inputs mediante `for`/`id` (`restocker-login-email` y `restocker-login-password`), conservando los `data-testid` existentes. Evidencia en el árbol de accesibilidad real del navegador: los dos campos aparecen como `textbox "Correo electrónico"` y `textbox "Contraseña"`, ambos con nombre accesible. La suite frontend `restocker_route_view.test.mjs` sigue en **236/236, exit 0**. No se tocó esquema ni código de producto ajeno al login.

- **Archivo:** [`public/assets/js/components/RestockerRouteView.js`](../public/assets/js/components/RestockerRouteView.js) · líneas 2665–2679
- **Qué ocurre:** los `<label>` «Correo electrónico» y «Contraseña» no tienen `for` ni `id` asociado, y los `<input>` (`data-testid="restocker-login-email"` y `restocker-login-password`) no llevan `id`, `aria-label` ni `aria-labelledby`.
- **Evidencia:** en el árbol de accesibilidad los dos `textbox` del formulario aparecen **sin nombre accesible**. El login visual y funcional sí opera correctamente.
- **Impacto:** un lector de pantalla anuncia campos sin nombre; afecta a la usabilidad del acceso exclusivo del reponedor (RF-04.1) y a criterios de accesibilidad del sistema de diseño.
- **Recomendación (no aplicada):** asociar cada etiqueta con su input mediante `id`/`for` (o `aria-labelledby`), manteniendo los `data-testid` actuales para no romper las pruebas.

---

### V-03 · P4 · RESUELTO (2026-11-20) — Copy obsoleto en la ruta de abastecimiento

**Resolución.** El estado vacío dice ahora «Sin paradas asignadas por ahora. Las órdenes de carga y las visitas a máquinas aparecerán aquí cuando coordinación las asigne.», sin referencias a números de feature. Se actualizaron también el comentario de sección y el bullet 5 del docblock del componente, y la aserción de plantilla de `restocker_route_view.test.mjs` exige el copy nuevo y la ausencia del antiguo. Evidencia en la interfaz real (tras limpiar la caché del service worker): la sección `restocker-route-base` muestra el texto nuevo y `Features 24, 26 y 27` ya no aparece; suite frontend **236/236, exit 0**.

- **Archivo:** [`public/assets/js/components/RestockerRouteView.js`](../public/assets/js/components/RestockerRouteView.js) · líneas 3085–3087
- **Texto anterior (antes de la corrección):** «Sin paradas asignadas por ahora. Las órdenes de carga y las visitas a máquinas llegarán con las Features 24, 26 y 27.»
- **Qué ocurre:** el mensaje anuncia como futuras funcionalidades que ya están implementadas (órdenes de carga del Módulo 33, visitas y planograma). El estado vacío describe un producto anterior.
- **Impacto:** copy engañoso para el operario; no afecta a la operación ni a la seguridad. Solo se muestra cuando `routeStops` está vacío.
- **Corrección aplicada:** estado vacío reescrito sin números de feature, indicando que las órdenes y visitas aparecerán cuando coordinación las asigne.

---

## 3. Falso positivo descartado (entorno)

Cargar `.env.docker-tls` y `.env.local` juntos sobrescribe `DATABASE_URL` y hace fallar `RestockerRbacConstitutionalTest` con el mensaje «falta la tabla locations». **No es un defecto de la rama:** con solo `.env.docker-tls` la suite pasa 27/27 y el bloque completo 10/10. La verificación debe ejecutarse con un único archivo de entorno.

---

## 4. Humo de UI y app local

- Servidor levantado en `127.0.0.1:8000`: `/` y `/api/health` responden 200.
- Portal de sede, coordinación, técnico SAT y reponedor: cargan y autentican sin errores de consola ni respuestas 500.
- Modal de escalado: valida la nota mínima y la foto obligatoria.
- El servidor se detuvo al terminar y no quedaron procesos de prueba.
- No se tomaron capturas ni se alteraron datos del parque.
- La corrida de navegador posterior (2026-11-20) encontró los hallazgos H-36-06 a H-36-10; `H-36-06` a `H-36-10` se corrigieron después. Su detalle, evidencia y criterio de cierre están en [hallazgos_pruebas_navegador_spec36.md](hallazgos_pruebas_navegador_spec36.md).

---

## 5. Criterio de cierre de V-01 a V-03

| Hallazgo | Prioridad | ¿Bloquea la rama? | Acción |
| :--- | :--- | :--- | :--- |
| V-01 · aserción 1.8 | P2 | No · resuelto (2026-11-20) | Validación por catálogo con latencia y plan a escala conservados |
| V-02 · etiquetado del login | P2 | No · resuelto (2026-11-20) | Etiquetas asociadas con `for`/`id`; `data-testid` conservados |
| V-03 · copy obsoleto | P4 | No · resuelto (2026-11-20) | Estado vacío sin números de feature; guarda en la suite frontend |

Este criterio cubre solo los hallazgos V-01, V-02 y V-03 de la corrida inicial. **Los hallazgos de navegador se cerraron después:** `H-36-06` (P1) se corrigió con hidratación de snapshots y una prueba de composición HTTP/PostgreSQL; `H-36-07` (P2) se corrigió con el selector de motivo en Sede y su persistencia en el despacho L1; `H-36-08` (P2) se corrigió con el filtrado de causas por categoría/motivo y la validación 422 de categoría; `H-36-09` (P2) se corrigió derivando la urgencia de tipo de máquina y categoría y heredando el perfil SLA de la sede en la rama de ticket SAT nuevo; `H-36-10` (P2) se corrigió publicando `location_id` en el DTO de máquina y componiendo la Coincidencia en Sede en la vista del reponedor. El detalle está en [hallazgos_pruebas_navegador_spec36.md](hallazgos_pruebas_navegador_spec36.md).

El README ya declara 135/135 integraciones y 441 suites · 16.993 aserciones, cifras que la corrida posterior confirma.

---

## 6. Reproducción

```bash
# Batería global
set -a; source deploy/local/postgres/.env.battery; set +a
php tests/run_all.php

# Canal acotado de la rama (un solo archivo de entorno)
set -a; source deploy/local/postgres/.env.docker-tls; set +a
php tests/run_new_engine.php Restocker

# Presupuesto de latencia H-36-05
set -a; source deploy/local/postgres/.env.docker-tls; set +a
php tests/run_new_engine.php Pooler

# App local
php -S 127.0.0.1:8000 -t public
```
