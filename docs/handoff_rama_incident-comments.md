# Handoff · Rama `incident-comments`

**Fecha:** 2026-10-08 · **Cierre de contenido:** `c875559` (este handoff va en un commit de documentación encima; `origin/incident-comments` se quedó en `bf9fbfe`, **sin publicar**) · **Estado:** árbol limpio salvo material ajeno no versionado
**Batería:** `php tests/run_all.php` → **batería global: 211 suites · 8.086 aserciones** · 0 fallos · base restaurada a semillas
*(la propia batería audita esta cifra: un desfase documental la pone en rojo vía `tests/Support/DocMetricsGuard.php`)*

---

## 1. Qué contiene la rama

El MVP Fase 1 completo, módulo a módulo, con su especificación en `specs/`: 02 códigos QR, 03 métricas y auditoría, 04 CRUD de administración, 05 mantenimiento preventivo y certificados sanitarios, 06 repuestos, 07 mapa de rutas y mapa territorial, 08 reintegros, 09 ficha integral de detalle y 10 hilo de comentarios, más los contratos técnicos transversales.

Todas las tareas de `specs/*/tasks.md` están marcadas como hechas salvo **`T-QR-20`** (regresión global y validación física en Android), que sigue abierta en [specs/02-qr-codes/tasks.md](../specs/02-qr-codes/tasks.md).

## 2. Los cuatro pendientes de aprobación, cerrados (2026-10-08)

| Pendiente (§3 del handoff anterior) | Estado | Qué se hizo y cómo se verificó |
| :--- | :--- | :--- |
| **1. Copy del flujo de consolidación** | ✅ Aplicada | Enmienda en [route_map_spec.md](../specs/functional/route_map_spec.md) (tres EARS nuevos + EARS de sede multi-técnico), EARS 5.5 de [mvp_functional_spec.md](../specs/functional/mvp_functional_spec.md), §7.2 de [incident_detail_modal_spec.md](../specs/functional/incident_detail_modal_spec.md), plan/contratos de 07 y nueva Fase 7 de tareas con `T-MAP-23`/`T-MAP-24`/`T-MAP-25`. Copy state-aware en el MODAL 1B: título, entradilla con `M ya asignada(s)`, distintivo por fila, botón `Reasignar N incidencia(s)` y alertas de consolidación. Grupo 15 de `CoordinatorDashboardViewTest.mjs` (8 aserciones) y **verificación en navegador** |
| **2. `T-MAP-25` (consolidación alcanzable en sedes multi-técnico sin trabajo sin asignar)** | ✅ Implementada | `isConsolidableSite()` + rótulo `Consolidar visita` en [CoordinatorTerritorialMapTab.js](../public/assets/js/components/CoordinatorTerritorialMapTab.js); nota de interfaz de `has_unassigned` en [route_map_contracts.md](../specs/technical/route_map_contracts.md) §4.2.1 (sin cambio de contrato). Aserciones 3.6–3.8 y reescritura de 6.5/6.11 en `CoordinatorTerritorialMapTabTest.mjs`, caso 4.2b/4.2c en `CoordinatorRouteMapApiTest.php` y **verificación en navegador** |
| **3. Motivo obligatorio en la reasignación preventiva** | ✅ Nueva enmienda | **EARS 2.6** en [preventive_maintenance_spec.md](../specs/functional/preventive_maintenance_spec.md) y Fase 8 de [specs/05-preventive-maintenance/tasks.md](../specs/05-preventive-maintenance/tasks.md) (`T-PREV-32`–`34`); campo documentado en [preventive_maintenance_contracts.md](../specs/technical/preventive_maintenance_contracts.md) (sin cambios de esquema: el motivo vive en `audit_log`). Backend en `CoordinatorPreventiveController::assignOrder`, interfaz en `CoordinatorPreventiveOrdersTab.js` y `api.js`. Casos 6.6–6.11 de la suite HTTP y 2.32–2.41 de la suite de componentes |
| **4. Las 18 casillas de aceptación** | ✅ Acta con 17 marcadas y 1 bloqueada | [verificacion_criterios_finalizacion_mvp.md](verificacion_criterios_finalizacion_mvp.md) (8/8 demostradas) y [verificacion_criterios_finalizacion_preventivo.md](verificacion_criterios_finalizacion_preventivo.md) (9/10; la del certificado **A4** queda sin marcar por el bloqueo declarado: el acto físico de impresión no se ha verificado). Las casillas marcadas en los propios pliegos con nota al acta |

**Regla de la enmienda preventiva:** sólo exige motivo cuando el técnico **cambia**; la asignación inicial de una orden sin responsable y la reprogramación de fecha del mismo técnico quedan exentas (casos 6.7 y 2.38).

## 3. Verificación de esta pasada

* **Batería global:** `211 suites · 8.086 aserciones · 0 fallos` (antes 8.060: las 26 aserciones nuevas son las de este trabajo).
* **Caso rojo de la guarda de cifras:** la batería se puso en rojo sola al detectar el desfase `8.060 → 8.086` en README, handoff y manual E2E, y volvió a verde tras sincronizarlos. `features_pendientes.md` quedó fuera de esa auditoría porque está ignorado por git (ver §4).
* **Navegador (servidor local, coordinador real):** el mapa territorial rotula **«Asignar técnico»** en la sede con lote mixto y **«Consolidar visita»** en la sede multi-técnico sin trabajo sin asignar; el MODAL 1B abre con título `Reasignar / Consolidar Sede en un Único Técnico`, entradilla `2 incidencia(s) · 2 ya asignada(s)`, distintivo por fila con el responsable y botón `Reasignar 2 incidencia(s)`; confirmar sin motivo no emite ninguna petición (validación nativa) y, saltándola, pinta el error de RF-07.3 y mantiene el diálogo abierto; la consolidación real de dos incidencias dejó `INCIDENT_REASSIGNED` con el motivo en `audit_log` y la nota en `incident_history`. En preventivos: asignación inicial sin campo de motivo, y al cambiar de técnico el campo aparece, bloquea la confirmación sin él y la reasignación deja el evento `ASSIGN_PREVENTIVE_ORDER` con `reassignment_reason`, técnico previo y nuevo.
* **Pruebas enfocadas:** `CoordinatorDashboardViewTest.mjs` 105/105, `CoordinatorTerritorialMapTabTest.mjs` 92/92, `CoordinatorPreventiveComponentsTest.mjs` 67/67, `CoordinatorRouteMapApiTest.php` 50/50, `CoordinatorPreventiveApiTest.php` 53/53.

## 4. Riesgos abiertos y pendientes vivos

1. **Certificado A4 (casilla 7 del pliego preventivo):** la emisión, la visualización, el dictamen `CONDICIONADO` y la privacidad por Código de Operador están demostrados; la **impresión física** sigue sin verificar y por eso la casilla no se marca.
2. **`T-QR-20`** (validación física en Android) continúa abierta en el módulo 02.
3. **Preselección del técnico en el lote:** el modal propone `technicians[0]`; si ese técnico ya es el responsable de alguna fila, el backend responde `TECHNICIAN_ALREADY_ASSIGNED` y esa fila queda como error parcial (ahora sí, con copy de consolidación y motivo obligatorio, así que el coordinador entiende el rechazo). Sigue siendo la mejora de experiencia pendiente.
4. **Título preventivo poco preciso:** el diálogo rotula «Reasignar Orden Preventiva» cuando la orden tiene técnico vivo aunque sólo se reprograme la fecha; el campo de motivo, en cambio, es exacto (sólo aparece si el técnico cambia). Cosmético, sin requisito que lo cubra.
5. **Documentos no versionados:** `docs/auditoria_arquitectura.md` (`.gitignore:20`) y `docs/features_pendientes.md` (`.gitignore:21`) no viajan con el repositorio, así que los hallazgos de seguridad del primero y la cifra del segundo no son visibles para quien clone ni auditables por la guarda de cifras.
6. **Triaje de seguridad pendiente:** S-1..S-4 / H-1..H-4 del informe de arquitectura sin decisión (comprobado que S-2 sigue vigente: `/api/cron/auto-close` registrado sin middleware).
7. **Material ajeno en el árbol:** `specs/11-pending-info-sla-pause/` y `specs/functional/pending_info_sla_pause_spec.md` están sin versionar y **no forman parte de esta serie de commits** (otra línea de trabajo en curso).

## 5. Cómo verificar

```bash
# Batería completa (requiere MariaDB local; reutiliza o arranca el servidor en 127.0.0.1:8000)
php tests/run_all.php          # esperado: 211/211 suites, 8.086 aserciones, 0 fallos, exit 0

# Suites concretas de esta pasada
node tests/unit/CoordinatorDashboardViewTest.mjs        # 105/105
node tests/unit/CoordinatorTerritorialMapTabTest.mjs    # 92/92
node tests/unit/CoordinatorPreventiveComponentsTest.mjs # 67/67
php tests/integration/CoordinatorRouteMapApiTest.php    # 50/50
php tests/integration/CoordinatorPreventiveApiTest.php  # 53/53
```

La batería restablece la base a semillas al terminar, así que no deja datos de prueba.

> **Aviso operativo:** la batería asume una base **en semillas al arrancar**. En esta pasada, los datos creados a mano para la verificación manual en navegador (una preventiva ya asignada en VEND-0102 y dos incidencias de prueba) hicieron fallar `integration/TechnicianSparePartsApiTest.php` en la ejecución siguiente; la suite pasa en solitario sobre la base recién sembrada. Si has tocado datos a mano, purga y re-siembra antes de lanzar la batería completa.

> **Nota de certificación:** sólo el HEAD final de la serie está certificado por la batería. Los commits intermedios cambian el número de aserciones antes de la sincronización documental del último commit, de modo que sus cifras documentadas no coinciden con su propia ejecución.

## 6. Convenciones que no conviene romper

* **SDD:** ninguna línea de código sin especificación aprobada; los cambios de comportamiento pasan por enmienda y puerta de aprobación (AGENTS.md §2 y §5).
* **Idioma:** documentación, specs e interfaz en español; código, tests y mensajes de Git en inglés (Conventional Commits con scope y código de tarea); cuerpo de commit sin acentos.
* **Dogma Vanilla:** cero dependencias (sin Composer, npm ni bundlers en runtime); PHP 8 puro con `strict_types` y Vue 3 en ES Modules.
* **Constitución:** nada de borrado físico, `audit_log` append-only, motivo obligatorio en reasignaciones (de averías y, desde hoy, también de órdenes preventivas) y ventana de garantía de 48 h.

## 7. Siguientes pasos sugeridos (por valor)

1. Decidir el triaje de los hallazgos S-1..S-4 / H-1..H-4 y versionar (o archivar en un lugar sincronizado) el informe de arquitectura.
2. Verificar la impresión física en A4 del certificado para cerrar la última casilla del pliego preventivo.
3. Completar `T-QR-20` (validación física en Android) como cierre de la Fase 1.
4. Resolver la preselección del técnico en el lote (evitar el rechazo fila a fila).
