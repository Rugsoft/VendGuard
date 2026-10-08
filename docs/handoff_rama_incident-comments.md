# Handoff · Rama `incident-comments`

**Fecha:** 2026-10-08 · **Cierre de contenido:** `c1a8dc1` (el triaje y sus cierres van encima, **sin publicar**: los commits locales posteriores a la última publicación no están en `origin`) · **Estado:** árbol con cambios de esta pasada pendientes de commit + material ajeno no versionado
**Batería:** `php tests/run_all.php` → **batería global: 212 suites · 8.116 aserciones** · 0 fallos · base restaurada a semillas
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

## 3. Triaje de la auditoría arquitectónica (2026-10-08)

El triaje completo, con criterios de aceptación y justificación por hallazgo, vive **versionado** en [specs/technical/auditoria_arquitectura_triage.md](../specs/technical/auditoria_arquitectura_triage.md). Resumen:

| Hallazgo | Decisión | Cierre |
| :--- | :--- | :--- |
| **S-1** Secretos hardcodeados | Cerrado en Fase 1 | Nuevo `src/Infrastructure/Config/SecretProvider.php`: `SECRET_KEY`/`CRON_SECRET` por entorno; en `APP_ENV`/`VENDGUARD_ENV`=`production`/`prod` la ausencia **lanza excepción** (fallo en cerrado); fuera de producción se usa una clave **solo de desarrollo** distinta de la histórica, de modo que los tokens firmados con la clave del repositorio quedan invalidados en todos los entornos. `AuthService` y el middleware del cron lo consumen |
| **S-2** `/api/cron/auto-close` sin middleware | Cerrado en Fase 1 | Nuevo `src/Presentation/Http/Middleware/CronAuthMiddleware.php` registrado en la ruta; `CronController` queda **fail-closed** (sin la marca `cron_authenticated` del middleware responde 401 aunque el secreto sea válido) |
| **S-3** Sin rate-limiting en login | Diferido | Requiere spec funcional (umbrales, ventana, `429`) y puerta de aprobación: es alcance nuevo, no un arreglo |
| **S-4** `X-Site-Code`/`site-login` sin secreto previo | Diferido · decisión de PO | Emitir un secreto por sede o emparejar en la primera visita son cambios de contrato de RF-01. El comportamiento actual sigue fijado por `SiteManagerRefundDataSegregationTest` |
| **H-1** `Core` dependía de `Infrastructure` | Cerrado en Fase 1 | `QrScanService`, `QrReportService` y `QrLabelService` movidos con `git mv` a `src/Application/Service/` (donde ya los situaba el plan de 02); `src/Core/` queda sin ninguna referencia a `VendGuard\Infrastructure` |
| **H-2** Service Locator disperso | Aceptado por diseño | Consecuencia deliberada del Dogma Vanilla; sin contenedor. H-1 elimina las instancias que además rompían la pureza de `Core` |
| **H-3** Alias `class_alias()` de servicios | Cerrado en Fase 1 | Eliminados los tres alias de `Core/Domain/Service/`; `LocationPortalController` importa ya la implementación canónica. El alias de `MachineType` **no se toca**: es API real usada por tres suites |
| **H-4** `PdoIncidentRepository` de 1.396 líneas | Diferido · backlog | Extracción por *concern* sobre el repositorio más crítico; sin riesgo activo y sin requisito que lo exija en Fase 1 |

**Un hallazgo que la propia batería descubrió durante el cierre:** tras mover los servicios QR, `QrReportService` seguía invocando `UrgencyCalculator::calculate()` **sin import**, apoyado en que ambos vivían en `Core\Service`. En su nuevo espacio de nombres esa resolución pasó a `Application\Service\UrgencyCalculator` y el endpoint de reporte QR devolvió **500** (`QrRefundCaptureTest`: 33 fallos). Se corrigió con el `use VendGuard\Core\Service\UrgencyCalculator;` explícito y la suite volvió a 105/105. Es el recordatorio de que un movimiento de espacio de nombres rompe el acoplamiento implícito aunque el código sea idéntico.

## 4. Verificación de esta pasada

* **Batería global:** `212 suites · 8.116 aserciones · 0 fallos · exit 0` (antes 211/8.086: +1 suite de guarda y +30 aserciones entre la guarda y el caso fail-closed del cron).
* **Guarda de cifras:** puesta en rojo sola al detectar el desfase `8.086 → 8.116` en README, handoff, manual E2E y las dos actas; verde tras sincronizarlas.
* **Suites enfocadas del cierre:** `tests/unit/AuditFindingsClosureTest.php` 29/29 (nueva), `tests/integration/CronAutoCloseEndpointTest.php` verde con el caso 1.8 fail-closed, `tests/unit/QrRefundCaptureTest.php` 105/105, `tests/unit/QrScanServiceTest.php` 43/43, `tests/unit/QrSanitaryQuarantineModeTest.php` 35/35, `tests/unit/QrLabelBaseUrlTest.php` 16/16, `tests/integration/LocationPortalControllerTest.php` verde y `tests/unit/ConstitutionalAuditTest.php` 100%.
* **Reproducción del cierre:** `grep -rln "VendGuard..Infrastructure" src/Core --include=*.php` → 0; `grep -rn "class_alias" src/Core/Domain/Service/` → 0; `grep -rn "vendguard-secret-key-change-in-production-rf04\|vendguard-cron-secret-key-2026" src/ public/` → 0.
* **Pruebas de la pasada anterior (siguen verdes):** `CoordinatorDashboardViewTest.mjs` 105/105, `CoordinatorTerritorialMapTabTest.mjs` 92/92, `CoordinatorPreventiveComponentsTest.mjs` 67/67, `CoordinatorRouteMapApiTest.php` 50/50, `CoordinatorPreventiveApiTest.php` 53/53.

## 5. Riesgos abiertos y pendientes vivos

1. **S-3 (rate-limiting del login) y S-4 (secreto por sede)** siguen abiertos: el triaje los difiere con su motivo, pero mientras no se decidan, la enumeración de sedes y la fuerza bruta no tienen freno técnico.
2. **Fallo en cerrado ligado a la declaración de entorno:** `SecretProvider` solo exige las claves cuando `APP_ENV`/`VENDGUARD_ENV` vale `production`/`prod`. Un despliegue que no declare el entorno seguirá arrancando con la clave de desarrollo (distinta de la histórica, así que la clave filtrada no sirve, pero no hay garantía de clave propia). Declarar el entorno es ya un requisito operativo de despliegue.
3. **Certificado A4 (casilla 7 del pliego preventivo):** la emisión, la visualización, el dictamen `CONDICIONADO` y la privacidad por Código de Operador están demostrados; la **impresión física** sigue sin verificar y por eso la casilla no se marca.
4. **`T-QR-20`** (validación física en Android) continúa abierta en el módulo 02.
5. **Preselección del técnico en el lote:** el modal propone `technicians[0]`; si ese técnico ya es el responsable de alguna fila, el backend responde `TECHNICIAN_ALREADY_ASSIGNED` y esa fila queda como error parcial (con copy de consolidación y motivo obligatorio, así que el coordinador entiende el rechazo). Sigue siendo la mejora de experiencia pendiente.
6. **H-4** (`PdoIncidentRepository`) queda en backlog con su refactor de 6 h.
7. **Documento de auditoría no versionado:** `docs/auditoria_arquitectura.md` (`.gitignore:20`) sigue sin viajar con el repositorio; el **triaje ya versionado** cubre la decisión y la evidencia, pero el informe original con el detalle de S-3/S-4 solo existe en local. `docs/features_pendientes.md` (`.gitignore:21`) sigue igual.
8. **Material ajeno en el árbol:** `specs/11-pending-info-sla-pause/` y `specs/functional/pending_info_sla_pause_spec.md` están sin versionar y **no forman parte de esta serie de commits** (otra línea de trabajo en curso).

## 6. Cómo verificar

```bash
# Batería completa (requiere MariaDB local; reutiliza o arranca el servidor en 127.0.0.1:8000)
php tests/run_all.php          # esperado: 212/212 suites, 8.116 aserciones, 0 fallos, exit 0

# Guarda de cierre de la auditoría (S-1, S-2, H-1, H-3)
php tests/unit/AuditFindingsClosureTest.php             # 29/29

# Suites tocadas por el cierre
php tests/integration/CronAutoCloseEndpointTest.php     # seguridad del cron + auto-cierre
php tests/unit/QrRefundCaptureTest.php                  # 105/105
php tests/unit/QrScanServiceTest.php                    # 43/43
php tests/unit/ConstitutionalAuditTest.php              # auditoría constitucional 100%
```

La batería restablece la base a semillas al terminar, así que no deja datos de prueba.

> **Aviso operativo:** la batería asume una base **en semillas al arrancar**. Si has tocado datos a mano (como pasó en la pasada anterior con una preventiva asignada y dos incidencias de prueba), purga y re-siembra antes de lanzar la batería completa: el fallo se manifiesta en suites ajenas y confunde el diagnóstico.

> **Nota de certificación:** sólo el HEAD final de la serie está certificado por la batería. Los commits intermedios cambian el número de aserciones antes de la sincronización documental del último commit, de modo que sus cifras documentadas no coinciden con su propia ejecución.

## 7. Convenciones que no conviene romper

* **SDD:** ninguna línea de código sin especificación aprobada; los cambios de comportamiento pasan por enmienda y puerta de aprobación (AGENTS.md §2 y §5). El triaje de la auditoría es la Fase 1 de este cierre.
* **Idioma:** documentación, specs e interfaz en español; código, tests y mensajes de Git en inglés (Conventional Commits con scope y código de tarea); cuerpo de commit sin acentos.
* **Dogma Vanilla:** cero dependencias (sin Composer, npm ni bundlers en runtime); PHP 8 puro con `strict_types` y Vue 3 en ES Modules.
* **Constitución:** nada de borrado físico, `audit_log` append-only, motivo obligatorio en reasignaciones (de averías y de órdenes preventivas), ventana de garantía de 48 h y **secretos por entorno, nunca en el código**.

## 8. Siguientes pasos sugeridos (por valor)

1. Decidir S-4 con el Product Owner (secreto por sede o emparejamiento en la primera visita) y especificar S-3 antes de implementarlo.
2. Verificar la impresión física en A4 del certificado para cerrar la última casilla del pliego preventivo.
3. Completar `T-QR-20` (validación física en Android) como cierre de la Fase 1.
4. Resolver la preselección del técnico en el lote (evitar el rechazo fila a fila).
5. Versionar o archivar de forma sincronizada el informe de arquitectura y `docs/features_pendientes.md`, hoy invisibles para quien clona.
