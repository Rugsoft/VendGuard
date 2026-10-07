# Cierre del hallazgo F-4 · Catálogo de acciones del visor de auditoría

**Módulo:** 03 · Métricas y Auditoría (`T-MET-13`, `T-MET-17`) y módulo 10 (evento `REOPEN_TICKET`)
**Hallazgo de origen:** [verificacion_cierre_hallazgos_f1_f2_f3.md](verificacion_cierre_hallazgos_f1_f2_f3.md) §6.6 y [verificacion_modulo_10_humo_funcionalidad_flujo.md](verificacion_modulo_10_humo_funcionalidad_flujo.md)
**Fecha:** 07/10/2026 · **Rama:** `incident-comments`
**Naturaleza del cambio:** deriva de vocabulario entre backend y frontend (defecto funcional de `EARS 5.3/5.5`), corregida con fuente única de verdad y guarda automática

---

## 1 · El defecto

El visor del registro inmutable (`AuditLogViewer.js`) mantenía **dos listas paralelas escritas a mano**: la de insignias (`formatActionBadge`) y la de opciones del filtro. Ambas usaban un vocabulario que el backend nunca escribe:

| Opción ofrecida por el visor | ¿La escribe el backend? | Acción real |
|---|---|---|
| `RESOLVE_INCIDENT` | Sí | — |
| `REOPEN_TICKET` | Sí (desde F-2) | — |
| `ASSIGN_TECHNICIAN` | **No** | `INCIDENT_ASSIGNED` / `INCIDENT_REASSIGNED` |
| `CANCEL_INCIDENT` | **No** | `INCIDENT_CANCELLED` |
| `CREATE_TICKET` | **No** | *ninguna: el alta del aviso no se audita* |
| `START_INTERVENTION` | **No** | *ninguna: el inicio de intervención no se audita* |
| `PAUSE_INTERVENTION` | **No** | `PAUSE_PENDING_PARTS` |
| `STATUS_CHANGE` | **No** | *ninguna* |
| `UPDATE_MACHINE` | **No** | `MACHINE_UPDATED` |
| `UPDATE_LOCATION` | **No** | `LOCATION_UPDATED` |

Consecuencias medidas sobre el código:

1. **Filtros fantasma.** Ocho de las nueve opciones del desplegable no podían devolver nunca una fila: el coordinador filtraba y obtenía una tabla vacía sin explicación.
2. **Acciones invisibles.** El backend escribe **54 acciones** distintas; el visor solo etiquetaba y filtraba diez. Las otras cuarenta y cuatro se mostraban como códigos en crudo (`ISSUE_SANITARY_CERTIFICATE`, `REFUND_PAID_DIGITAL`, `EVALUATE_PREVENTIVE_CHECKLIST`…) y no eran filtrables, de modo que la trazabilidad exigida por el Art. III.3 era nominal para todo el parque de módulos.
3. **Filtro de entidad incompleto.** El desplegable de entidad ofrecía tres tipos (`TICKET`, `MACHINE`, `LOCATION`) mientras `audit_log.entity_type` admite ocho (migraciones 005 y 009): los eventos de `USER`, `PREVENTIVE_ORDER`, `SANITARY_CERTIFICATE`, `REFUND_REQUEST` y `UNCLAIMED_CASH_FINDING` aparecían en la tabla pero **no se podían acotar**.
4. **Deriva interna.** Al ser dos listas a mano, la insignia de una acción y su opción de filtro podían separarse entre sí, además de respecto al backend.

---

## 2 · Vocabulario real, derivado de los escritores

El conjunto canónico **no** se declara a mano: se extrae de quien escribe en `audit_log`.

* **Llamadas a `AuditLogger::log*Event()`** en `src/`: 45 acciones (argumento posicional o nombrado `action:`).
* **Inserciones directas de los sembradores** en `database/` y `src/Infrastructure/Database/SeedRunner.php`: 9 más.
* **Total: 54 acciones**, de las cuales 7 (`TICKET_CREATED`, `INTERVENTION_STARTED`, `TICKET_RESOLVED`, `TICKET_AUTO_CLOSED`, `TECHNICIAN_ASSIGNED`, `LOCATION_INSPECTED`, `QR_LABEL_GENERATED`) solo las escribe `DemoMetricsSeeder`: la aplicación **todavía no audita** el alta del aviso, el inicio de intervención ni el cierre automático. Se etiquetan con el sufijo «(registro de demo)» en lugar de ocultarse, porque esas filas sí existen y el coordinador las ve.

**Hallazgo colateral declarado (fuera de alcance, no corregido):** la ausencia de eventos de auditoría en el alta del aviso, el inicio de intervención y el cierre automático es una brecha de `EARS 5.1` del mismo tipo que F-2, no una decisión de diseño. Corregirla exige especificar el evento, su actor (`SITE_MANAGER` o `SYSTEM`) y su payload.

---

## 3 · Solución implementada

### 3.1 Fuente única de verdad (frontend)

Nuevo módulo **`public/assets/js/utils/AuditActionLabels.js`**, en la línea de `utils/PreventiveLabels.js` (datos congelados, funciones puras, cero dependencias):

| Export | Contenido |
|---|---|
| `AUDIT_ACTION_GROUPS` | Los nueve grupos del desplegable, en orden de presentación |
| `AUDIT_ACTION_CATALOG` | Las 54 acciones reales con `code`, `label`, `tone` y `group` (una entrada por línea: la guarda la parsea) |
| `AUDIT_ENTITY_LABELS` | La etiqueta en español de los ocho tipos de `audit_log.entity_type` |
| `getAuditActionBadge(code)` | Insignia `{label, bg, color, border}` del tono semántico; una acción desconocida conserva su código como etiqueta en vez de desaparecer |
| `getAuditActionGroups()` | Catálogo agrupado para el desplegable |
| `getAuditEntityLabel(type)` | Etiqueta del tipo de entidad, con respaldo legible |

La paleta se declara una sola vez como **tonos semánticos** (`neutral`, `success`, `danger`, `warning`, `info`, `primary`, `purple`) y cada acción elige tono, de modo que el color deja de repetirse en diez literales.

### 3.2 El visor deriva del catálogo

`AuditLogViewer.js`:

* Importa el catálogo y **elimina sus dos listas propias**: `formatActionBadge()` delega en `getAuditActionBadge()` y el `<select>` de acción se renderiza con `v-for` sobre `actionFilterGroups`, agrupado en `<optgroup>` por dominio.
* El `<select>` de entidad se renderiza igualmente con `v-for` sobre `AUDIT_ENTITY_LABELS`, de modo que ofrece el enum completo sin lista paralela.
* `formatEntityName()` usa la etiqueta del catálogo como respaldo, así que un expediente de reintegro se lee «Expediente de Reintegro #7873» en lugar de `REFUND_REQUEST #7873`.

### 3.3 Guarda anti-deriva

Nueva suite **`tests/unit/AuditActionCatalogTest.php`** (26 aserciones, PHP puro con `token_get_all`, sin dependencias). Extrae el vocabulario de los escritores reales y lo contrasta con el catálogo de la interfaz:

| Bloque | Qué certifica |
|---|---|
| 0 · No vacuidad | El extractor encuentra ≥ 50 acciones, resuelve argumentos nombrados, inserciones directas y **las dos ramas de una expresión ternaria**; y detecta una acción sintética inyectada (prueba de que la guarda puede fallar) |
| 1 · Acciones calculadas | Solo existe un sitio con acción no literal (el ternario de triaje) y está declarado explícitamente en la guarda: si aparece otro, la suite lo nombra |
| 2 · Catálogo ↔ backend | Igualdad **bidireccional** (ni acciones sin etiqueta ni filtros fantasma), códigos únicos y bien formados, etiquetas no vacías y sin ambigüedad, tonos y grupos declarados, ningún grupo vacío |
| 3 · Fuente única | El visor importa el catálogo, no conserva opciones ni entidades escritas a mano, no conserva paleta propia y su catálogo de entidades coincide exactamente con `AuditEvent::ENTITY_TYPES` |
| 4 · Datos reales | Toda acción **presente en `audit_log`** está catalogada (MariaDB real) |
| 5 · Integración | La paleta resuelve relleno, texto y borde por tono y la API pública del catálogo está expuesta |

`tests/unit/AuditLogViewerTest.mjs` se amplía a **32 aserciones** con un bloque 6 de integración del catálogo, y **sus datos simulados dejan de usar vocabulario fantasma**: `START_INTERVENTION` → `INCIDENT_ASSIGNED` y `UPDATE_MACHINE` → `MACHINE_UPDATED`. Un fixture con acciones que nadie escribe es justamente lo que permitió que la deriva sobreviviera.

---

## 4 · Verificación

### 4.1 Suite completa

```
======================================================================
 RESUMEN DE EJECUCIÓN GLOBAL (T-39)
======================================================================
 Tiempo de ejecución total : 62.24 segundos
 Suites de pruebas PHP Unit : 93 / 93 pasadas
 Suites de pruebas JS Unit  : 53 / 53 pasadas
 Suites de Integración PHP  : 63 / 63 pasadas
 ──────────────────────────────────────────────────────────────────
 Total Suites Ejecutadas    : 209
 Total Aserciones Evaluadas : 7969
 Fallos Detectados          : 0
 Base de datos restablecida : SÍ (Semillas intactas)
======================================================================
 RESULTADO: 100% EN VERDE. (0 errors, 0 failures)
```

Línea base antes de este trabajo: 208 suites / 7.932 aserciones.

### 4.2 Comprobación en la interfaz real (navegador, sesión de coordinación)

Servicio en `http://127.0.0.1:8000`, perfil Coordinador, sección «Métricas y Auditoría» → «Pistas de Auditoría»:

| Comprobación | Resultado observado |
|---|---|
| Opciones del filtro de acción | **55** (54 acciones + «Todas las Acciones») |
| Agrupación del desplegable | 9 grupos: Incidencias (14), Máquinas (5), Sedes (5), Usuarios (5), Preventivo (12), Sanitario (2), Reintegros (9), Repuestos (1), QR (1) = 54 |
| Opciones fantasma | **Ninguna**: `ASSIGN_TECHNICIAN`, `CANCEL_INCIDENT`, `STATUS_CHANGE`, `CREATE_TICKET`, `PAUSE_INTERVENTION`, `START_INTERVENTION`, `UPDATE_MACHINE` y `UPDATE_LOCATION` han desaparecido del desplegable |
| Etiquetado de filas | Acciones antes en crudo ahora se leen en español: «Emisión de Certificado Sanitario», «Evaluación de Checklist Preventivo», «Repuestos Sustituidos en Preventivo» |
| Filtro por acción real antes inexistente | `action=ISSUE_SANITARY_CERTIFICATE` → **25 filas**, todas con esa acción (`HTTP 200`) |
| Filtro por entidad antes inexistente | `entity_type=REFUND_REQUEST` → **25 filas**, todas de «Expediente de Reintegro» con sus acciones etiquetadas |
| Consola del navegador | Sin errores de plantilla ni avisos de Vue |

### 4.3 Fase roja previa

La guarda se escribió antes del catálogo y fallaba con 12 aserciones en rojo, entre ellas `2.2 Toda acción que el backend escribe tiene etiqueta y filtro en el visor` (44 acciones sin catalogar) y `3.4 El filtro de entidad ofrece el enum completo de audit_log`. La primera corrida también destapó dos defectos de la propia guarda, corregidos antes de continuar: confundía las **definiciones** de `AuditLogger` con llamadas (se saltan con `T_FUNCTION`) y una expresión regular sin el modificador `m` solo leía el primer tono de la paleta.

---

## 5 · Limitaciones declaradas

1. **Sin capturas de pantalla.** El *webview* de previsualización sigue sin componer fotogramas; la evidencia de interfaz es estructural (DOM y red reales del navegador), no gráfica.
2. **Carrera de respuestas en el visor.** Si se cambian dos filtros en el mismo ciclo, la respuesta que llega última pinta la tabla, aunque no corresponda al último filtro aplicado. Es un defecto **preexistente del visor** (no introducido aquí) que la comprobación en navegador destapó: exige cancelación de peticiones obsoletas o un contador de secuencia.
3. **`TELEMETRY`-style acciones no auditadas.** El alta del aviso, el inicio de intervención y el cierre automático siguen sin evento propio (apartado 2).
4. **La guarda cubre el vocabulario, no la semántica.** Certifica que una acción existe y está etiquetada; no verifica que su `previous_state`/`new_state` sean correctos (eso lo cubren las suites de cada módulo).
5. **`tests/unit/AuditLoggerTest.php` conserva acciones ficticias** (`CREATE_TICKET`, `UPDATE_MACHINE_LOCATION`) como entradas sintéticas de la normalización del logger. No afectan a la guarda (que solo mira escritores reales) y se dejaron intactas para no alterar una suite ajena al hallazgo.

---

## 6 · Trazabilidad de artefactos

**Especificación:** `specs/functional/metrics_audit_spec.md` (EARS 5.3, puntos 5 y 7), `specs/technical/metrics_audit_contracts.md` (§3.4 parámetros y ejemplos, §3.4.1 catálogo canónico y guarda, ejemplos CSV), `specs/03-metrics-audit/tasks.md` (T-MET-17).
**Código:** `public/assets/js/utils/AuditActionLabels.js` (nuevo), `public/assets/js/components/AuditLogViewer.js`.
**Pruebas:** `tests/unit/AuditActionCatalogTest.php` (nueva), `tests/unit/AuditLogViewerTest.mjs` (ampliada).
**Documentación:** este informe y la nota de cierre en [verificacion_cierre_hallazgos_f1_f2_f3.md](verificacion_cierre_hallazgos_f1_f2_f3.md).
