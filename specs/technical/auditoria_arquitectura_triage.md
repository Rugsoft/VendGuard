# Triaje de hallazgos · Informe de Auditoría Arquitectónica

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)
**Fecha del triaje:** 8 de octubre de 2026
**Documento auditado:** `docs/auditoria_arquitectura.md` (informe del 30 de septiembre de 2026, no versionado)
**Alcance:** hallazgos **S-1..S-4** (seguridad) y **H-1..H-4** (arquitectura), más la segunda tanda **S-5, H-5, V-6, T-1, §5.2 y §6** (§6 de este documento)
**Marco:** [`AGENTS.md`](../../AGENTS.md) §2 (SDD) y §5 (líneas rojas) · [`constitution.md`](../../constitution.md)

> Este documento es la Fase 1 (especificación) del ciclo SDD para el cierre de los hallazgos.
> Los cuatro cierres de §2 y los de la segunda tanda (§6) son refactorizaciones, alineaciones
> documentales y endurecimientos sin alterar contratos de negocio, con una excepción decidida
> por el Product Owner: **V-6** baja la sesión de sede a 24 h (EARS 1.3). S-3 y S-4 quedan
> **explícitamente fuera de Fase 1** (requieren especificación funcional y/o decisión de
> Product Owner, ver §3).

---

## 1. Veredicto de triaje

| # | Hallazgo | Severidad | Disposición | Motivo |
| :--- | :--- | :--- | :--- | :--- |
| **S-1** | Secretos criptográficos hardcodeados (`SECRET_KEY`, `CRON_SECRET`) | 🔴 Crítica | **CERRADO en Fase 1** | Es un arreglo de configuración sin cambio de contrato: la clave de firma deja de estar en el repositorio y en producción el sistema **falla en cerrado** si no se define por entorno. |
| **S-2** | `POST /api/cron/auto-close` sin middleware | 🟡 Media | **CERRADO en Fase 1** | La ruta de un proceso batch quedaba fuera del patrón de las 75 restantes. Se extrae `CronAuthMiddleware` y el controlador pasa a fail-closed. |
| **S-3** | Cero rate-limiting en `loginInternal()` | 🔴 Crítica | **DIFERIDO** (necesita spec) | Introduce comportamiento nuevo (contador de intentos, umbrales, bloqueo, respuestas `429`) y por tanto contrato: exige Fase 1 funcional + puerta de aprobación antes de tocar código (AGENTS.md §2). |
| **S-4** | `X-Site-Code` y `site-login` son la misma puerta sin credencial | 🔴 Crítica | **DIFERIDO** (decisión de PO) | El propio informe lo declara decisión de producto: exige emitir un secreto por sede o emparejar en la primera visita, ambas cambios de contrato para RF-01. No lo decide el agente (AGENTS.md §5.5). |
| **H-1** | Tres servicios de `Core/Service` dependen de `Infrastructure` | 🟡 Media | **CERRADO en Fase 1** | Refactor sin cambio de comportamiento y **alineación con la arquitectura ya aprobada**: `specs/02-qr-codes/plan.md` ya situaba `QrScanService`, `QrReportService` y `QrLabelService` en `Application/Service`. |
| **H-2** | Service Locator disperso (`?? new Pdo...` en 24 ficheros) | 🟡 Media | **ACEPTADO por diseño** | Consecuencia deliberada del Dogma Vanilla (sin contenedor ni framework): los parámetros opcionales son el mecanismo de inyección en tests. H-1 elimina las instancias que además rompían la pureza de `Core`. Sin acción. |
| **H-3** | Alias `class_alias()` en `Core/Domain/Service` | 🟢 Baja | **CERRADO en Fase 1** | Los tres alias de servicios no los usaba nadie salvo un `use` del portal de sede; se eliminan y queda un único camino por clase. |
| **H-4** | `PdoIncidentRepository` de 1.396 líneas | 🟢 Baja | **DIFERIDO** (backlog) | Extracción por *concern* de 6 h sobre el repositorio más crítico del sistema. No hay riesgo activo ni requisito que lo exija en Fase 1; se agenda como tarea propia con su batería. |

**Correcciones factuales al informe** (verificadas leyendo el código y no solo el informe):

* H-3 habla de **cinco** ficheros con `class_alias()`. Hoy existen **cuatro**: los tres alias de
  servicios (`UrgencyCalculator`, `ResolutionValidator`, `IncidentStateMachine`) y
  `Core/Domain/ValueObject/MachineType.php`. Este último **no se toca**: es API real usada por
  `DomainEnumsTest`, `SanitaryCertificateServiceTest` y `TechnicianMachineHistoryServiceTest`.
* H-1 dice que son cinco alias; realmente son tres ficheros los que importan infraestructura
  (`QrScanService`, `QrReportService`, `QrLabelService`), que es lo que cierra este triaje.

---

## 2. Cierres de Fase 1 (criterios de aceptación)

### 2.1 S-2 · Middleware de autenticación del cron

**Decisión:** la validación de credenciales sale del controlador y pasa a
`src/Presentation/Http/Middleware/CronAuthMiddleware.php`, registrado como tercer argumento de
la ruta en `AppRouter` (patrón de las demás rutas protegidas).

| # | Criterio | Verificación |
| :--- | :--- | :--- |
| S-2.1 | La ruta `POST /api/cron/auto-close` se registra con un middleware y ya no "desnuda". | Guarda estructural en suite de cierre. |
| S-2.2 | Acepta `X-Cron-Secret`, `Bearer <CRON_SECRET>` y `Bearer <token de COORDINATOR>`; rechaza técnicos y credenciales inválidas con `401 UNAUTHORIZED`. | `CronAutoCloseEndpointTest` (casos 1.1–1.7). |
| S-2.3 | El controlador es **fail-closed**: sin contexto de autorización en la petición responde `401` aunque el secreto sea válido (defensa en profundidad si alguien registra la ruta sin middleware). | Caso nuevo en `CronAutoCloseEndpointTest`. |
| S-2.4 | La ruta conserva el contrato: `200` con `closed_count` / `closed_tickets`. | Casos 2.x y 3.x existentes. |

### 2.2 S-1 · Secretos por entorno con fallo en cerrado

**Decisión:** el proveedor `src/Infrastructure/Config/SecretProvider.php` resuelve las dos claves.
Ninguna clave utilizable queda en el repositorio.

| # | Criterio | Verificación |
| :--- | :--- | :--- |
| S-1.1 | `SECRET_KEY` se lee de entorno (`SECRET_KEY`) y `CRON_SECRET` de `CRON_SECRET`. | Suite unitaria de `SecretProvider`. |
| S-1.2 | Con `APP_ENV`/`VENDGUARD_ENV` en `production`/`prod` y variable ausente, la resolución **lanza excepción** (fallo en cerrado); no hay default que funcione. | Suite unitaria (caso rojo explícito). |
| S-1.3 | Fuera de producción se usa una clave **solo de desarrollo** distinta de la histórica, para que cualquier token firmado con la clave del repositorio deje de ser válido. | Regresión conductual: un token firmado con la clave histórica es rechazado. |
| S-1.4 | La firma concreta ya no contiene literales criptográficos utilizables (`grep` del informe). | Guarda estructural en suite de cierre. |
| S-1.5 | La inyección por constructor de `AuthService`/`CronController` sigue teniendo prioridad sobre el entorno (contratos de test intactos). | Suites existentes. |

### 2.3 H-1 · Servicios QR fuera de `Core`

**Decisión:** `QrScanService`, `QrReportService` y `QrLabelService` se mueven de
`src/Core/Service/` a `src/Application/Service/` (casos de uso que orquestan repositorios),
tal y como ya describía `specs/02-qr-codes/plan.md`.

| # | Criterio | Verificación |
| :--- | :--- | :--- |
| H-1.1 | `src/Core/` no importa `VendGuard\Infrastructure\*` en ningún fichero. | Guarda estructural (`grep` del informe) + suite de cierre. |
| H-1.2 | Los tres servicios viven en `src/Application/Service/` y los consumidores (`QrScanController`, `QrLabelController`, suites) apuntan al nuevo espacio de nombres. | Batería: `QrScanServiceTest`, `QrRefundCaptureTest`, `QrSanitaryQuarantineModeTest`, `QrLabelBaseUrlTest`, `QrEndpointsIntegrationTest`. |
| H-1.3 | Cero cambio de comportamiento: los guiones QR (`QrScanServiceTest`, `QrSanitaryModeApiTest`, `QrInactiveMachineScanTest`, `QrEndpointsIntegrationTest`, `LocationPortalControllerTest`… ) siguen en verde. | Batería global. |
| H-1.4 | El árbol de `specs/02-qr-codes/plan.md` refleja la ubicación real (incluido el renderizador, que es puro y permanece en `Core/Domain/Service`). | Revisión documental. |

### 2.4 H-3 · Fin de los alias de servicios

**Decisión:** se eliminan `IncidentStateMachine.php`, `ResolutionValidator.php` y
`UrgencyCalculator.php` de `src/Core/Domain/Service/`; el único consumidor del alias
(`LocationPortalController`) pasa a importar `VendGuard\Core\Service\UrgencyCalculator`.

| # | Criterio | Verificación |
| :--- | :--- | :--- |
| H-3.1 | No queda ningún `class_alias()` en `src/Core/Domain/Service/`. | Guarda estructural. |
| H-3.2 | Cada clase tiene una sola ruta canónica y ningún fichero referencia `Core\Domain\Service\{UrgencyCalculator, ResolutionValidator, IncidentStateMachine}`. | Guarda estructural. |
| H-3.3 | `UrgencyCalculatorTest`, `ResolutionValidatorTest`, `IncidentStateMachineTest` y la batería global siguen en verde. | Batería global. |

---

## 3. Hallazgos diferidos (qué hace falta para reabrirlos)

* **S-3 (rate-limiting del login).** Requiere spec funcional con umbrales (intentos por IP y por
  email), ventana temporal, estrategia de bloqueo y contrato de la respuesta `429`, más el uso de
  `audit_log` (append-only) como almacén del contador. Es un incremento de alcance: no se implementa
  sin puerta de aprobación (AGENTS.md §2 Fase 2 y §5.1).
* **S-4 (secreto por sede).** Requiere decisión de Product Owner entre emitir un secreto en el alta
  de sede y emparejarlo en la primera visita. Hasta entonces el comportamiento actual está fijado
  por `SiteManagerRefundDataSegregationTest` para que no se cambie por descuido.
* **H-4 (`PdoIncidentRepository`).** Requiere tarea propia (extracción por *concern*: historia,
  resolución, reapertura, auto-cierre) con su batería de caracterización previa. Es refactor de
  6 h sobre el repositorio más usado; sin riesgo activo, no entra en el cierre de Fase 1.

Riesgo residual aceptado y declarado: mientras S-3 y S-4 sigan abiertos, la enumeración de sedes
y la fuerza bruta sobre el login no tienen freno técnico. Ambos están ya documentados en el informe
de auditoría; este triaje **no los mitiga**.

---

## 4. Lo que este cierre NO toca

* Nada del dominio: ni estados, ni SLA, ni la ventana de 48 h, ni la segregación de datos de sede.
* Ningún contrato REST existente: la ruta del cron mantiene entrada y salida; solo cambia **dónde**
  se valida la credencial.
* La clave de firma no cambia en desarrollo salvo por ser distinta de la histórica (los tokens
  emitidos antes de este cambio dejan de valer, efecto buscado).
* `MachineType`, `InternalAuthMiddleware`, `SiteAuthMiddleware` y el resto de middleware, intactos.

---

## 5. Reproducción

```bash
# S-2: la ruta ya no está desnuda (debe devolver los middlewares, no una lista vacía)
php -r 'require "tests/bootstrap.php"; $r = VendGuard\Presentation\Routing\AppRouter::create();'  # ver suite de cierre

# S-1: no debe quedar ningún secreto utilizable en el código
grep -rn "vendguard-secret-key-change-in-production-rf04\|vendguard-cron-secret-key-2026" src/

# H-1: Core no depende de Infrastructure
grep -rln "VendGuard..Infrastructure" src/Core --include=*.php   # → 0 resultados

# H-3: sin alias de clases
grep -rn "class_alias" src/Core/Domain/Service/                  # → 0 resultados

# Cierre completo
php tests/run_all.php
```

---

## 6. Segunda tanda (S-5, H-5, V-6, T-1, §5.2 y §6)

| # | Hallazgo | Severidad | Disposición | Motivo |
| :--- | :--- | :--- | :--- | :--- |
| **S-5** | `ConnectionFactory` cae a `root` / contraseña vacía | 🟡 Media | **CERRADO en Fase 1** | Sin cambio de contrato: en desarrollo nada cambia; cuando el entorno se declara de producción, la conexión **falla en cerrado** si no hay credenciales explícitas o si el usuario es `root` sin contraseña. |
| **H-5** | `LIMIT`/`OFFSET` interpolados en `PdoPreventiveOrderRepository` | 🟢 Baja | **CERRADO en Fase 1** | Placeholders nombrados con `PDO::PARAM_INT`, que es la convención del proyecto. La conexión usa prepares nativos (`ATTR_EMULATE_PREPARES => false`), así que el binding se verifica con una consulta real paginada. |
| **V-6** | TTL de sede de 7 días frente a EARS 1.3 (24 h) | 🟡 Media | **CERRADO en Fase 1 · decisión de PO (2026-10-08)** | El Product Owner eligió alinear el código con la especificación: **TTL absoluto de 24 h**. La ventana deslizante de actividad que sugiere el texto queda como mejora futura declarada (exigiría reemitir el token). |
| **T-1** | `GET /api/locations/{code}/incidents` especificado sin implementar | 🔴 Alta (según informe) | **CERRADO en Fase 1 (documental)** | La regla de negocio (RNF-04) está cubierta **de forma más restrictiva** por `sanitizeIncidentForSite()` y su suite; el plan técnico describía artefactos inexistentes. Se alinea el plan con el mecanismo real en lugar de crear un endpoint que nadie consume. |
| **§5.2** | Informe de cierre constitucional desfasado | 🟡 Media | **CERRADO en Fase 1 (documental)** | Se reescribe como **histórico fechado** con la cifra de su momento y un puntero a la fuente viva (`ConstitutionalAuditTest` + batería), en lugar de presentar como cierre vigente un estado de hace seis módulos. |
| **§6** | 2.466 literales hex en JS (era 2.063 el 30/09) | 🟢 Baja | **CIERRE PARCIAL + backlog** | El refactor de 8 h no cabe en Fase 1 y necesita regresión visual, pero la deuda **está creciendo** (+403 en ocho días): se congela con un trinquete que falla si el contador sube, y el refactor por componentes queda en backlog. |

### 6.1 Criterios de aceptación

**S-5 · credenciales de base de datos**

| # | Criterio | Verificación |
| :--- | :--- | :--- |
| S-5.1 | En producción, sin `DATABASE_URL`/`MYSQL_URL` ni `DB_USER`/`DB_PASSWORD`, la conexión lanza excepción antes de intentar conectar. | Suite de segunda tanda. |
| S-5.2 | En producción, `root` con contraseña vacía se rechaza aunque venga en configuración explícita. | Suite de segunda tanda. |
| S-5.3 | En desarrollo la configuración por defecto sigue funcionando igual (XAMPP local). | `ConnectionFactoryTest` + suite. |
| S-5.4 | El contenedor de producción no se rompe por este cambio: la declaración de entorno sigue siendo decisión del despliegue y queda documentada. | README y nota de despliegue. |

**H-5 · paginación de preventivos**

| # | Criterio | Verificación |
| :--- | :--- | :--- |
| H-5.1 | La consulta usa `LIMIT :limit OFFSET :offset` con `bindValue(... PDO::PARAM_INT)`. | Guarda estructural. |
| H-5.2 | `findForCoordinatorList(['limit' => …, 'offset' => …])` se ejecuta sin error contra MariaDB con prepares nativos. | Suite de segunda tanda + `CoordinatorPreventiveApiTest` (per_page real). |

**T-1 · alineación del plan**

| # | Criterio | Verificación |
| :--- | :--- | :--- |
| T-1.1 | La matriz de trazabilidad cita el mecanismo real (`sanitizeIncidentForSite`, `SiteManagerPartsDataSegregationTest`) y no los artefactos inexistentes. | Guarda estructural sobre `specs/technical/plan.md`. |
| T-1.2 | El endpoint no existe en el router y queda declarado como decisión (si alguna vez se necesita un listado por sede, será especificación nueva). | Guarda estructural del router. |

**§5.2 · informe de cierre**

| # | Criterio | Verificación |
| :--- | :--- | :--- |
| §5.2.1 | El documento se declara histórico y fechado, conserva su cifra original y apunta a la fuente viva. | Guarda estructural. |
| §5.2.2 | No se presenta como cierre vigente: el estado actual se consulta en la suite constitucional y en la batería. | Revisión documental. |

**§6 · trinquete de colores**

| # | Criterio | Verificación |
| :--- | :--- | :--- |
| §6.1 | El contador de literales hex en `public/assets/js` (sin `vendor/`) no supera el techo congelado. | `DesignTokenDebtRatchetTest.mjs`. |
| §6.2 | El techo es explícito y rebajarlo es el camino esperado; subirlo exige tocar la suite de forma visible en revisión. | La propia suite imprime el contador actual. |
| §6.3 | El refactor por componentes sigue en backlog, empezando por los cinco más densos (`CoordinatorIncidentDetailModal`, `CoordinatorSparePartsAnalyticsTab`, `CoordinatorSparePartsTab`, `CoordinatorPreventiveOrdersTab`, `CoordinatorPreventiveOrderDetailModal`). | Acta de triaje (§6). |

**V-6 · sesión de sede de 24 h (decisión de PO del 2026-10-08)**

Decisión: **Alinear el código con EARS 1.3** mediante un TTL absoluto de 24 h (86400 s).

| # | Criterio | Verificación |
| :--- | :--- | :--- |
| V-6.1 | El token de sede por defecto expira a las 24 h. | Suite de segunda tanda (grupo 6). |
| V-6.2 | El parámetro de TTL explícito sigue respetándose para tests y flujos internos. | Suite de segunda tanda (grupo 6). |
| V-6.3 | El valor de 7 días no reaparece en la implementación. | Guarda estructural (sin `604800`). |
| V-6.4 | La ventana deslizante de actividad queda declarada como mejora futura, no como comportamiento implementado. | Este acta y el docblock de `AuthService::generateSiteToken()`. |

Riesgo residual aceptado: la sede se reidentifica a diario con su código (un solo factor, por
diseño de RF-01). Si la experiencia resulta gravosa, la mejora es la opción 3 —ventana
deslizante— con su propia especificación de contrato.

### 6.2 Reproducción de la segunda tanda

```bash
# S-5: la política de producción (nada de root sin contraseña)
php tests/unit/AuditFindingsSecondWaveClosureTest.php

# H-5: sin interpolación en la paginación de preventivos
grep -n "LIMIT {" src/Infrastructure/Repository/PdoPreventiveOrderRepository.php   # → 0 resultados
php tests/integration/CoordinatorPreventiveApiTest.php

# T-1 y §5.2: plan alineado e informe declarado histórico
grep -n "IncidentTimeline\|DataSegregationTest" specs/technical/plan.md               # → 0 resultados

# §6: el trinquete de colores
node tests/unit/DesignTokenDebtRatchetTest.mjs

# V-6: la sesión de sede dura 24 h
grep -n "ttlSeconds = 86400" src/Application/Service/AuthService.php
```
