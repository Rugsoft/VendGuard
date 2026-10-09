# Handoff · Rama `incident-comments`

**Fecha:** 2026-10-08 · **Cierre de contenido:** `a5005fa` + segunda tanda de triaje + cierre de S-4 (`f968d98`, `29f19ee`, `2ae2b63`) · **Publicado:** `origin/incident-comments` hasta `2ae2b63`; el arreglo del arranque desplegado de esta pasada está en el árbol, pendiente de commit · **Material ajeno no versionado:** `specs/11-*` y `pending_info_sla_pause_spec.md`
**Batería:** `php tests/run_all.php` → **batería global: 218 suites · 8.247 aserciones** · 0 fallos · base restaurada a semillas
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

## 3. Primera tanda del triaje (2026-10-08)

El triaje completo, con criterios de aceptación y justificación por hallazgo, vive **versionado** en [specs/technical/auditoria_arquitectura_triage.md](../specs/technical/auditoria_arquitectura_triage.md). Resumen:

| Hallazgo | Decisión | Cierre |
| :--- | :--- | :--- |
| **S-1** Secretos hardcodeados | Cerrado en Fase 1 | `src/Infrastructure/Config/SecretProvider.php`: `SECRET_KEY`/`CRON_SECRET` por entorno; en `APP_ENV`/`VENDGUARD_ENV`=`production`/`prod` la ausencia **lanza excepción**; fuera de producción se usa una clave **solo de desarrollo** distinta de la histórica, así que los tokens firmados con la clave del repositorio quedan invalidados |
| **S-2** `/api/cron/auto-close` sin middleware | Cerrado en Fase 1 | `CronAuthMiddleware` registrado en la ruta; `CronController` es **fail-closed** (sin la marca `cron_authenticated` responde 401 aunque el secreto sea válido) |
| **S-3** Sin rate-limiting en login | Diferido | Requiere spec funcional (umbrales, ventana, `429`) y puerta de aprobación: es alcance nuevo |
| **S-4** `X-Site-Code`/`site-login` sin secreto previo | **Cerrado en Fase 1 · decisión de PO (2026-10-08)** | El PO eligió la opción A: clave de centro emitida en el alta (solo huella bcrypt, mostrada una vez y entregada en mano), `X-Site-Code` retirada como credencial y freno de intentos por sede. Enmienda en [propuesta_decision_s4_acceso_sede.md](propuesta_decision_s4_acceso_sede.md) §11, migración `014_location_access_code.sql`, suites `SiteAccessCodeTest.php` (32 aserciones) y `SiteAccessCodeGeneratorTest.php` (7) |
| **H-1** `Core` dependía de `Infrastructure` | Cerrado en Fase 1 | Los tres servicios QR movidos a `src/Application/Service/`; `src/Core/` queda sin referencias a `VendGuard\Infrastructure` |
| **H-2** Service Locator disperso | Aceptado por diseño | Consecuencia deliberada del Dogma Vanilla; sin contenedor |
| **H-3** Alias `class_alias()` de servicios | Cerrado en Fase 1 | Eliminados los tres alias; `LocationPortalController` importa la implementación canónica. El alias de `MachineType` no se toca: es API real de tres suites |
| **H-4** `PdoIncidentRepository` de 1.396 líneas | Diferido · backlog | Extracción por *concern* sobre el repositorio más crítico; sin riesgo activo |

## 4. Segunda tanda del triaje (2026-10-08)

| Hallazgo | Decisión | Cierre |
| :--- | :--- | :--- |
| **S-5** `root` / contraseña vacía por defecto en `ConnectionFactory` | Cerrado en Fase 1 | Fail-closed cuando el entorno se declara de producción: exige credenciales explícitas (`DATABASE_URL`/`MYSQL_URL` o `DB_USER`/`DB_PASSWORD`) y rechaza `root` sin contraseña. En desarrollo (XAMPP) nada cambia |
| **H-5** `LIMIT`/`OFFSET` interpolados en preventivos | Cerrado en Fase 1 | `LIMIT :limit OFFSET :offset` con `bindValue(..., PDO::PARAM_INT)`; verificado contra MariaDB con prepares nativos y con filtros combinados |
| **V-6** TTL de sede de 7 días frente a EARS 1.3 (24 h) | **Cerrado en Fase 1 · decisión de PO (2026-10-08)** | El PO eligió alinear el código con la especificación: TTL absoluto de **24 h** en `AuthService::generateSiteToken()`, con el parámetro de TTL explícito intacto. La ventana deslizante de actividad queda declarada como mejora futura (exigiría reemitir el token) |
| **T-1** `GET /api/locations/{code}/incidents` especificado sin implementar | Cerrado en Fase 1 (documental) | La fila de RNF-04 en [specs/technical/plan.md](../specs/technical/plan.md) describe el mecanismo real (`sanitizeIncidentForSite()` + `SiteManagerPartsDataSegregationTest`); la matriz lleva nota de vigencia y queda declarado que el listado por sede no existe por decisión. La reconciliación completa de la matriz (18 de 38 artefactos citados ya no existen) queda como tarea documental propia |
| **§5.2** Informe de cierre constitucional desfasado | Cerrado en Fase 1 (documental) | [constitutional_audit_report.md](constitutional_audit_report.md) pasa a **acta histórica fechada**: conserva sus cifras (43 suites, 906 aserciones, 20 controles), declara que no describe el estado actual y apunta a la fuente viva (`ConstitutionalAuditTest` + batería) |
| **§6** 2.466 literales hex en JS (eran 2.063 el 30/09, +403 en ocho días) | Cierre parcial en Fase 1 + backlog | Trinquete [DesignTokenDebtRatchetTest.mjs](../tests/unit/DesignTokenDebtRatchetTest.mjs) que falla si el contador sube; el refactor por componentes (empezando por los cinco más densos) sigue en backlog |

**Dos defectos que la propia batería descubrió durante esta segunda tanda:**

1. **Quirk de PDO en la paginación.** El primer intento de H-5 combinaba `bindValue()` con `execute($params)`: con el array vacío, PDO descarta los valores ya vinculados y la consulta sin filtros moría con `SQLSTATE[HY093] Invalid parameter number`. Se resolvió vinculando **todos** los parámetros explícitamente, y la paginación se probó con y sin filtros.
2. **Dos suites cargaban la factoría sin autoloader.** `ConnectionFactoryTest` y `SeedDataTest` requerían `ConnectionFactory.php` a mano; al depender ahora de `SecretProvider`, fallaban con *class not found*. Ambas usan ya el bootstrap compartido.

## 5. Verificación de esta pasada

* **Batería global:** `218 suites · 8.247 aserciones · 0 fallos · exit 0` (antes 214/8.135: +2 suites y +59 aserciones con el cierre de S-4, +4 más con la guarda del arranque desplegado, +1 suite y +32 aserciones con el arranque del módulo 11).
* **Arranque desplegado, verificado en un proceso limpio:** el contenedor abortaba en el paso 1/6 con «Class `VendGuard\Infrastructure\Config\SecretProvider` not found» mientras la batería seguía verde — `bin/init_cloud_db.php` no usa el autoloader y su lista de `require` no había crecido con las dependencias de S-5 (`ConnectionFactory` → `SecretProvider`) ni de S-4 (`SeedRunner` → `SiteAccessCodeGenerator`). Ambas se declaran ya en el propio script, y `CloudDeploySchemaParityTest` **Caso 1c** (aserciones 5–8) lanza una sonda en proceso limpio con los requires exactos del arranque, certifica que toda su cadena resuelve y que la guardia **muerde** cuando falta un require. Arranque real contra una base de sonda: exit 0, 6/6 pasos, 4/4 columnas de la clave de centro y **0 claves sembradas** en el despliegue. El mismo defecto estaba latente en `bin/seed.php` y `bin/seed_demo_metrics.php` (morían con «Class `SiteAccessCodeGenerator` not found» al sembrar con claves de desarrollo): ambas listas quedan completas y **Caso 1c-bis** recorre `bin/*.php` certificando en proceso limpio que cada herramienta resuelve la cadena que declara. `php bin/seed.php` y `php bin/seed_demo_metrics.php` se han ejecutado de nuevo con exit 0.
* **Cierre de S-4 verificado en comportamiento:** `SiteAccessCodeTest.php` cubre acceso con las dos credenciales, error genérico idéntico, fallo en cerrado (sede sin clave o inactiva), retirada de `X-Site-Code` (401 sin nombrar la vía antigua), freno de cinco intentos con bloqueo temporal de 15 minutos, emisión en el alta y reemisión que invalida la clave anterior al instante con rastro en `audit_log` sin la clave en claro. `SiteAccessCodeGeneratorTest.php` fija formato `XXXXX-XXXXX` y alfabeto sin caracteres ambiguos.
* **Suites migradas a token de sede:** `AuthControllerTest`, `AuthMiddlewareTest`, `LocationPortalControllerTest`, `LocationRefundDeliveryApiTest`, `LocationRefundDeliveryTest`, `LocationPortalReopenAuditTest`, `SiteManagerPartsDataSegregationTest`, `SiteManagerRefundDataSegregationTest`, `SiteSanitaryApiTest`, `SiteSanitaryControllerTest`, `FrontendApiStoreTest` (.php y .mjs), `LocationPortalViewTest.mjs`, `IncidentCommentRoutesVerificationTest` y el guion E2E: la cabecera retirada pasa a certificarse como rechazada (401) en todas ellas.
* **Guarda de cifras:** se puso en rojo sola al detectar el desfase `8.116 → 8.132 → 8.135` y volvió a verde tras sincronizar README, handoff, manual E2E y las dos actas.
* **Suites enfocadas del cierre:** `AuditFindingsSecondWaveClosureTest.php` 16/16 (S-5, H-5, V-6, T-1, §5.2, §6), `DesignTokenDebtRatchetTest.mjs` 3/3, `ConnectionFactoryTest` verde, `SeedDataTest` verde, `CoordinatorPreventiveApiTest.php` 53/53, `AuditFindingsClosureTest.php` 29/29 (primera tanda, sigue verde).
* **V-6 verificado en comportamiento:** un token de sede recién emitido declara `exp = ahora + 86400` y el parámetro de TTL explícito sigue funcionando; la guarda estructural impide que vuelva el valor de 7 días.
* **Comprobaciones manuales de S-5:** con `APP_ENV=production` y sin credenciales, `ConnectionFactory` lanza la política; con `root`/'' explícito también; en desarrollo conecta igual que antes.
* **Pruebas de la pasada anterior (siguen verdes):** `CoordinatorDashboardViewTest.mjs` 105/105, `CoordinatorTerritorialMapTabTest.mjs` 92/92, `CoordinatorPreventiveComponentsTest.mjs` 67/67, `CoordinatorRouteMapApiTest.php` 50/50.

## 6. Riesgos abiertos y pendientes vivos

1. **V-6 cerrado con decisión de PO:** la sesión de sede dura 24 h. El coste asumido es la reidentificación diaria con el código de sede; si resulta gravosa, la mejora natural es la ventana deslizante de 24 h de actividad (reemitir el token), que necesita especificación de contrato propia.
2. **S-3 (rate-limiting del login interno) sigue abierto:** el triaje lo difiere con su motivo. El login de sede ya tiene freno propio desde el cierre de S-4 (5 intentos → 15 minutos de bloqueo por sede).
3. **Fallo en cerrado ligado a la declaración de entorno:** `SecretProvider` (S-1) y `ConnectionFactory` (S-5) solo exigen configuración cuando `APP_ENV`/`VENDGUARD_ENV` vale `production`/`prod`. Hoy el `Dockerfile` **no** declara el entorno, así que las guardas quedan inertes en el contenedor hasta que el despliegue lo declare junto con `SECRET_KEY`, `CRON_SECRET` y las credenciales de base de datos. No se tocó el `Dockerfile` a propósito: activarlo sin configurar habría tumbado un despliegue vivo.
4. **Certificado A4 (casilla 7 del pliego preventivo):** la maquetación está cubierta, la **impresión física** no se ha verificado y por eso la casilla no se marca.
5. **`T-QR-20`** (validación física en Android) continúa abierta en el módulo 02.
6. **Preselección del técnico en el lote:** el modal propone `technicians[0]`; si ya es el responsable de alguna fila, el backend responde `TECHNICIAN_ALREADY_ASSIGNED` y esa fila queda como error parcial. Mejora de experiencia pendiente.
7. **Backlog declarado:** H-4 (`PdoIncidentRepository` por *concern*), reconciliación completa de la matriz de trazabilidad (§7 de [plan.md](../specs/technical/plan.md)) y refactor de tokens de los cinco componentes más densos (§6 del triaje).
8. **Documentos no versionados:** `docs/auditoria_arquitectura.md` (`.gitignore:20`) y `docs/features_pendientes.md` (`.gitignore:21`); el triaje ya versionado cubre la decisión y la evidencia, pero el detalle original de S-3/S-4 solo existe en local.
9. **Material ajeno en el árbol:** `specs/11-pending-info-sla-pause/` y `specs/functional/pending_info_sla_pause_spec.md` están sin versionar y **no forman parte de esta serie de commits** (otra línea de trabajo en curso).
10. **La cadena de los scripts de `bin/` es una lista manual:** cualquier dependencia nueva de `ConnectionFactory`, `SqlScriptSplitter`, `SeedRunner` o `DemoMetricsSeeder` tiene que añadirse a los `require` del script que la use. La batería ya lo detecta antes de que lo descubra un despliegue: `CloudDeploySchemaParityTest` Caso 1c (arranque del contenedor, con la cadena derivada de los propios `use`) y Caso 1c-bis (resto de herramientas de `bin/`).

## 7. Cómo verificar

```bash
# Batería completa (requiere MariaDB local; reutiliza o arranca el servidor en 127.0.0.1:8000)
php tests/run_all.php          # esperado: 216/216 suites, 8.199 aserciones, 0 fallos, exit 0

# Guardas de las dos tandas de triaje
php tests/unit/AuditFindingsClosureTest.php             # 29/29 (S-1, S-2, H-1, H-3)
php tests/unit/AuditFindingsSecondWaveClosureTest.php   # 16/16 (S-5, H-5, V-6, T-1, §5.2, §6)
node tests/unit/DesignTokenDebtRatchetTest.mjs          # 3/3 (deuda de tokens congelada)
php tests/integration/CloudDeploySchemaParityTest.php   # 27/27 (esquema y cadena de arranque del despliegue)

# Suites tocadas por la segunda tanda
php tests/integration/ConnectionFactoryTest.php         # política de credenciales intacta
php tests/integration/SeedDataTest.php                  # semillas
php tests/integration/CoordinatorPreventiveApiTest.php  # 53/53 (paginación real)
```

La batería restablece la base a semillas al terminar, así que no deja datos de prueba.

> **Aviso operativo:** la batería asume una base **en semillas al arrancar**. Si has tocado datos a mano, purga y re-siembra antes de lanzar la batería completa: el fallo se manifiesta en suites ajenas y confunde el diagnóstico.

> **Nota de certificación:** sólo el HEAD final de la serie está certificado por la batería. Los commits intermedios cambian el número de aserciones antes de la sincronización documental del último commit, de modo que sus cifras documentadas no coinciden con su propia ejecución.

## 8. Convenciones que no conviene romper

* **SDD:** ninguna línea de código sin especificación aprobada; los cambios de comportamiento pasan por enmienda y puerta de aprobación (AGENTS.md §2 y §5). El triaje de la auditoría es la Fase 1 de estos cierres.
* **Idioma:** documentación, specs e interfaz en español; código, tests y mensajes de Git en inglés (Conventional Commits con scope y código de tarea); cuerpo de commit sin acentos.
* **Dogma Vanilla:** cero dependencias (sin Composer, npm ni bundlers en runtime); PHP 8 puro con `strict_types` y Vue 3 en ES Modules.
* **Constitución:** nada de borrado físico, `audit_log` append-only, motivo obligatorio en reasignaciones, ventana de garantía de 48 h, **secretos y credenciales por entorno** y **colores por token** (sin aumentar la deuda congelada).

## 9. Siguientes pasos sugeridos (por valor)

1. Declarar `APP_ENV=production` y las claves (`SECRET_KEY`, `CRON_SECRET`) en el despliegue para que las guardas de S-1 y S-5 dejen de estar inertes.
2. Especificar S-3 (rate-limiting del login interno) antes de implementarlo; S-4 quedó cerrado con decisión de PO el 2026-10-08.
3. Medir el impacto de la sesión de 24 h en las sedes y, si procede, especificar la ventana deslizante como mejora (V-6 opción 3).
4. Verificar la impresión física en A4 del certificado para cerrar la última casilla del pliego preventivo.
5. Completar `T-QR-20` (validación física en Android) como cierre de la Fase 1.
