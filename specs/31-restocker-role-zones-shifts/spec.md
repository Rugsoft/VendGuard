# ESPECIFICACIÓN FUNCIONAL: ROL DE CARGADOR/REPONEDOR DE RUTA (`RESTOCKER`), ZONIFICACIÓN DE CARGA Y CONTROL DE JORNADA

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)\
**Módulo:** 31 · Rol de Cargador/Reponedor de Ruta (`RESTOCKER`), Zonificación de Carga y Control de Jornada\
**Documento Canónico:** `specs/31-restocker-role-zones-shifts/spec.md` (y su espejo `specs/functional/restocker_role_zones_shifts_spec.md`)\
**Versión:** 2.0 · Incorpora las 3 Decisiones de Diseño (`D1`–`D3`), las 3 Decisiones de Casos Límite y Alcance (`C1`–`C3`) y las 4 Resoluciones de Auditoría QA (`QA 1`–`QA 4`) consensuadas con el Product Owner\
**Estado:** Especificación Funcional Aprobada por el Product Owner el 2026-11-11 · Puerta de Aprobación SDD superada (Fase 2)\
**Fecha:** Noviembre 2026\
**Origen:** Funcionalidad 21 del catálogo [`docs/features_pendientes.md`](../../docs/features_pendientes.md) (Bloque 4 · Operativa de Carga, Reposición de Producto y Gestión de Reponedores)\
**Alineación Constitucional:** Art. I (supremacía de la especificación SDD e inadmisibilidad de código simulado), Art. II (prioridad sanitaria innegociable: la guardia 24/7 para emergencias térmicas electromecánicas permanece exclusiva del rol `TECHNICIAN`), Art. III.1 y III.3 (prohibición estricta de borrado físico en usuarios, cuadrantes de zona, turnos y ausencias; inmutabilidad del rol tras el alta y trazabilidad completa en `audit_log`), Art. IV (minimalismo tecnológico: reutilización de tablas y motores de zonas, turnos, ausencias y horas sin librerías externas), Art. V.3 (no interferencia con la regla de técnico único responsable en averías SAT), Art. V.4 (segregación estricta de permisos RBAC y opacidad total de datos laborales y territoriales frente al Portal de Sede y vistas públicas), Art. VI (delimitación sagrada del alcance sin adelantar planogramas, visitas in situ ni avisos de Nivel 1 reservados a las Features 22–28).

---

## 1. Contexto y Objetivo

### 1.1. El Problema (Por qué)
Hasta el Módulo 30, **VendGuard** ha modelado de extremo a extremo la operativa de mantenimiento técnico correctivo y preventivo (**SAT**) mediante tres actores (`COORDINATOR`, `TECHNICIAN` y `LOCATION_RESPONSIBLE`). Sin embargo, la explotación real de un parque de máquinas de vending requiere una segunda flota operativa de calle: los **Cargadores / Reponedores de Ruta (`RESTOCKER`)**, encargados del abastecimiento de producto y consumibles, control FEFO de caducidades, limpieza diaria y recaudación de efectivo.

Antes de introducir el catálogo de productos y planogramas (Feature 22), las órdenes de carga de furgoneta frigorífica (Feature 23) o las visitas de reposición in situ (Feature 24), es indispensable establecer los cimientos organizativos, territoriales y laborales de esta plantilla sin contaminar ni duplicar la arquitectura del SAT:
1. **Falta de segregación de roles operativos en ruta:** Usar el rol `TECHNICIAN` para los reponedores les daría acceso indebido a resoluciones electromecánicas, stock de repuestos técnicos, bandeja de taller y sugerencias de triaje de averías críticas (vulnerando el principio de mínimo privilegio y el Art. V.3).
2. **Necesidad de cobertura territorial paralela en las mismas Zonas Operativas:** Una misma demarcación territorial (ej. *Zona Norte*) necesita tener simultáneamente su **Técnico SAT Titular/Suplente** para reparar averías y su **Reponedor Titular/Suplente** para abastecer las máquinas, sin que la titularidad de uno desplace o colisione con la del otro.
3. **Control de jornada, ausencias y registro horario legal unificado:** Los reponedores salen a ruta a diario, realizan pausas de comida, sufren bajas o vacaciones (que exigen promover automáticamente al reponedor suplente `BACKUP` de la zona) y están sujetos a la misma obligación legal de registro de jornada y cómputo de horas efectivas que los técnicos (Módulos 21 y 22).

### 1.2. El Objetivo (Qué)
Incorporar el cuarto actor del dominio (**Cargador / Reponedor de Ruta**, `UserRole::RESTOCKER`), dotándolo de su propio cuadrante territorial de carga con promoción automática de suplentes, control de turnos/ausencias y registro horario:
1. **Cuarto Rol Operativo (`RESTOCKER`), RBAC Estricto y Vista Móvil Propia (`D1`, `C1`, `C3`):** Ampliar el dominio y el tipo nativo de base de datos con `RESTOCKER`, permitir su alta y gestión en el CRUD de Personal Interno (con código de operador único, ej. `REP-01`, rol inmutable tras el alta y `is_on_call = FALSE` invariable), blindar las rutas REST para separar las capacidades de SAT y Reposición, y proporcionar la vista móvil dedicada `RestockerRouteView.js` con sus zonas asignadas, barra de control de jornada (`ON_SHIFT`, `MEAL_BREAK`, `OFF_SHIFT`), contador diario en vivo, modal «⏱️ Mis Horas» y cierre de turno directo.
2. **Integración Unificada en Coordinación con Filtro por Rol (`D2`, `QA 2`, `QA 4`):** Integrar a los reponedores en las consolas existentes de Coordinación sin duplicar pestañas:
   * En **Zonas Operativas (Módulo 17):** exponer los contadores `primary_restockers_count` y `backup_restockers_count` en cada zona, mostrar dos cuadrantes independientes (**«🔧 Cuadrante SAT (Técnicos)»** y **«📦 Cuadrante de Carga (Reponedores)»**) y ampliar la consulta inversa por operario.
   * En **Ausencias y Turnos (Módulo 21)** y en **Registro de Jornada y Horas + CSV (Módulo 22):** incorporar un filtro por rol (`Todos`, `Técnicos SAT`, `Reponedores`), respetar la visibilidad completa de activos e inactivos con actividad (`QA 4`) y mostrar la insignia/columna de rol en las tablas y en la exportación oficial CSV.
3. **Zonificación de Carga (`restocker_zone_assignments`) y Motor Determinista de Cobertura (`D3`, `C2`, `C3`, `QA 1`, `QA 3`, `QA 4`):** Crear la tabla 41 `restocker_zone_assignments` con `role_in_zone` (`PRIMARY` | `BACKUP`) e `is_primary` sincronizados (`QA 1`), baja lógica (`removed_at`, Art. III.1), disparador anti-borrado físico, entidad de auditoría `'RESTOCKER_ZONE'` y titularidad única (`PRIMARY` en máximo 1 zona viva, `BACKUP` en múltiples zonas), junto con el servicio `RestockerZoneCoverageService` que resuelve en tiempo real quién es el reponedor efectivo de cada zona bajo un escalafón determinista de 6 niveles activos (`QA 3`) y promueve automáticamente al `BACKUP` disponible a **`#1 RECOMENDADO ⭐ (Suplente en Cobertura Activa)`** cuando el titular tiene una ausencia vigente o está fuera de turno.

### 1.3. Resumen de Decisiones de Diseño, Casos Límite y Resoluciones QA Consensuados (`D1`–`D3`, `C1`–`C3`, `QA 1`–`QA 4`)
* **D1 (Vista móvil propia `RestockerRouteView.js` y cierre de turno directo sin relevo de averías SAT):** El usuario `RESTOCKER` dispone de su propia vista móvil independiente (`RestockerRouteView.js`) que muestra su cabecera de zonas asignadas (`PRIMARY` / `BACKUP`), su barra de control de turno (`Iniciar Turno`, `Pausa para Comer`, `Reanudar Turno`, `Cerrar Turno`), su contador diario de horas efectivas en vivo y el acceso a «⏱️ Mis Horas». Al pulsar «Cerrar Turno», la sesión se cierra directamente (incluso desde `MEAL_BREAK`), sin abrir el modal de relevo de tickets SAT ni ejecutar protocolos de averías (que permanecen exclusivos de `TECHNICIAN` hasta la Feature 26). En el cuerpo principal se presenta el estado base informativo de ruta de carga listo para las Features 24, 26 y 27.
* **D2 (Consolas unificadas en Coordinación con filtro y desglose por Rol):**
  1. En **Zonas Operativas**, cada zona presenta por separado el **«🔧 Cuadrante SAT (Técnicos)»** y el **«📦 Cuadrante de Carga (Reponedores)»**, además de la consulta inversa filtrable por rol.
  2. En **Ausencias y Turnos** y en **«⏱️ Registro de Jornada y Horas»** (incluyendo el archivo CSV exportado), Coordinación dispone de un selector de rol (**`Todos`**, **`Técnicos SAT`**, **`Reponedores`**) y de una insignia/columna de rol por operario. Para los usuarios `RESTOCKER`, las métricas de *Intervenciones Resueltas* y *Tiempo en Intervención In Situ* computan en `0` (`0s`) hasta la entrada en vigor de las visitas de reposición (Feature 24).
* **D3 (Tabla `restocker_zone_assignments`, guardia `is_on_call = FALSE` y promoción automática del `BACKUP`):**
  1. Tabla 41 `restocker_zone_assignments` independiente de `technician_zone_assignments`, con baja lógica (`removed_at`), prohibición de `DELETE` físico (Art. III.1) e índice único parcial para garantizar que un reponedor activo sea **Titular (`PRIMARY` / `is_primary = TRUE`) de máximo una zona viva** y **Suplente (`BACKUP` / `is_primary = FALSE`)** en cero, una o varias zonas adicionales.
  2. El atributo de guardia 24/7 de emergencias electromecánicas (`is_on_call`) se fija invariablemente en `FALSE` para todo usuario `RESTOCKER`.
  3. El servicio `RestockerZoneCoverageService` calcula en tiempo real la cobertura de carga de cada zona y promueve automáticamente al reponedor `BACKUP` disponible a **`#1 RECOMENDADO ⭐`** cuando el titular `PRIMARY` se encuentra ausente o fuera de turno.
* **C1 (Inmutabilidad estricta del rol tras el alta en el CRUD de Personal Interno):** En el alta de personal (`POST /api/coordinator/staff`) se admite el rol `RESTOCKER` junto a `COORDINATOR` y `TECHNICIAN`. Una vez creado el usuario, su `role` es **estrictamente inmutable** (`409 ROLE_CHANGE_FORBIDDEN` si se intenta alterar en edición), preservando la integridad histórica de auditoría, cuadrantes y métricas por rol (Art. III.3). Si un trabajador cambia de puesto en la empresa, se desactiva su cuenta previa (`is_active = FALSE`) y se registra una nueva ficha con su nuevo rol y código de operador.
* **C2 (Escalafón determinista y precedencia de presencia física en `RestockerZoneCoverageService` — actualizado con `QA 3`):**
  1. **Precedencia de presencia física en turno:** Si un reponedor tiene una ausencia planificada vigente en `technician_absences` pero inicia turno (`ON_SHIFT`), su presencia activa en ruta prevalece y se clasifica como **Disponible en Turno**, registrando el evento de advertencia en auditoría (`SHIFT_STARTED_DURING_ABSENCE`).
  2. **Escalafón determinista de 6 niveles activos (`QA 3`):** Quien ya ha iniciado su jornada hoy (en `ON_SHIFT` o en su pausa de comida `MEAL_BREAK`) precede siempre a quien está `OFF_SHIFT`:
     * **Nivel 1 (`PRIMARY_ON_SHIFT`):** Reponedor **`PRIMARY`** en estado **`ON_SHIFT`**.
     * **Nivel 2 (`BACKUP_PROMOTED_ON_SHIFT`):** Reponedor **`BACKUP`** en estado **`ON_SHIFT`** (promoción automática en turno activo).
     * **Nivel 3 (`PRIMARY_MEAL_BREAK`):** Reponedor **`PRIMARY`** en estado **`MEAL_BREAK`** (en jornada activa hoy, durante pausa de comida).
     * **Nivel 4 (`BACKUP_PROMOTED_MEAL_BREAK`):** Reponedor **`BACKUP`** en estado **`MEAL_BREAK`**.
     * **Nivel 5 (`PRIMARY_PLANNED_OFF_SHIFT`):** Reponedor **`PRIMARY`** en estado **`OFF_SHIFT` sin ausencia activa** (titular planificado cuando ningún efectivo de la zona ha abierto turno hoy).
     * **Nivel 6 (`BACKUP_PROMOTED_OFF_SHIFT`):** Reponedor **`BACKUP`** en estado **`OFF_SHIFT` sin ausencia activa** (suplente promovido automáticamente ante ausencia vigente del titular cuando ningún efectivo de la zona ha abierto turno hoy).
     * **No Disponibles (`UNAVAILABLE`):** Efectivos con **ausencia activa estando `OFF_SHIFT`** o con **cuenta inactiva (`is_active = FALSE`)**, situados al final con la insignia **`NO_DISPONIBLE`** y el motivo explícito.
  3. **Desempate determinista:** A igualdad de nivel, gana la menor marca `assigned_at ASC` en la zona y, en caso de empate exacto, `operator_code ASC` e `id ASC`.
* **C3 (Desactivación de cuenta `RESTOCKER` con cierre automático de turno abierto y preservación de vínculo con insignia «Baja»):**
  1. Al desactivar (`is_active = FALSE`) a un usuario `RESTOCKER` (o `TECHNICIAN`) que tenga un turno abierto (`closed_at IS NULL`), el sistema cierra automáticamente dicho turno en la misma transacción registrando `FORCE_CLOSE_SHIFT` en `technician_shift_logs` y en `audit_log` con el motivo `"Cierre automático por desactivación de cuenta de usuario"`, impidiendo que queden turnos huérfanos acumulando horas.
  2. Sus vinculaciones vivas en `restocker_zone_assignments` (`removed_at IS NULL`) se conservan visibles en el cuadrante de Coordinación con la insignia **«Baja»** y la acción de retirada habilitada (simetría con Resolución QA 10 de Módulo 17), pero quedan excluidas de inmediato de la cobertura activa en `RestockerZoneCoverageService` (`NO_DISPONIBLE · Cuenta inactiva`), promoviendo al `BACKUP` activo de la zona.
* **Resolución QA 1 (Paridad de esquema `role_in_zone` + `is_primary` en Tabla 41 `restocker_zone_assignments` y entidad `'RESTOCKER_ZONE'` en `audit_entity_type`):**
  1. La Tabla 41 `restocker_zone_assignments` incluye tanto `role_in_zone VARCHAR(10) NOT NULL CHECK (role_in_zone IN ('PRIMARY', 'BACKUP'))` (conforme a `docs/features_pendientes.md` §21) como `is_primary BOOLEAN NOT NULL DEFAULT FALSE` con restricción `chk_restocker_zone_role_coherence CHECK ((role_in_zone = 'PRIMARY' AND is_primary = TRUE) OR (role_in_zone = 'BACKUP' AND is_primary = FALSE))`, aceptando cualquiera de los dos campos en el payload de entrada y exponiendo ambos en las respuestas JSON.
  2. El tipo enumerado `audit_entity_type` incorpora el valor `'RESTOCKER_ZONE'` para auditar con identidad propia las altas y bajas del cuadrante de carga (`RESTOCKER_ZONE_ASSIGNED`, `RESTOCKER_ZONE_REMOVED`).
* **Resolución QA 2 (Contadores agregados `primary_restockers_count` y `backup_restockers_count` en el listado y detalle de Zonas Operativas):**
  * Los endpoints `GET /api/coordinator/zones` y `GET /api/coordinator/zones/{id}` incluyen para cada zona operativa los contadores `primary_restockers_count` y `backup_restockers_count` (contabilizando vinculaciones vivas `removed_at IS NULL` de reponedores con `users.is_active = TRUE` y `users.deleted_at IS NULL`), junto a los ya existentes `primary_technicians_count` y `backup_technicians_count`.
* **Resolución QA 3 (Prelación de jornada activa `MEAL_BREAK` sobre `OFF_SHIFT` en `RestockerZoneCoverageService`):**
  * Un reponedor que ya ha iniciado su jornada del día y se encuentra temporalmente en pausa de comida (`MEAL_BREAK`, Niveles 3 y 4) mantiene prelación en la cobertura de la zona sobre cualquier reponedor que esté fuera de turno (`OFF_SHIFT`, Niveles 5 y 6), evitando falsos relevos al mediodía frente a compañeros que no están trabajando en ese turno.
* **Resolución QA 4 (Regla de visibilidad de plantilla `C5` por rol en Registro Horario e idempotencia en baja del cuadrante):**
  1. En `GET /api/coordinator/metrics/work-hours` y su exportación CSV, el filtro `role` (`ALL` | `TECHNICIAN` | `RESTOCKER`, por defecto `ALL`) aplica la regla `C5` del Módulo 22 dentro del subconjunto seleccionado: lista a todos los operarios activos (`is_active = TRUE`) de ese rol aunque tengan `0` sesiones en el periodo, más los operarios inactivos de ese rol que sí hayan registrado actividad en el rango de fechas.
  2. La acción `DELETE /api/coordinator/zones/{id}/restockers/{restocker_id}` es **idempotente**: si la vinculación en esa zona ya estaba extinguida (`removed_at IS NOT NULL`) o no tenía fila viva, devuelve `200 OK` sin duplicar escritura ni entrada en `audit_log`.

---

## 2. Usuarios y Roles Implicados

| Usuario / Rol | Relación con el Módulo | Reglas de Privacidad y Permisos (Art. V.4) |
| :--- | :--- | :--- |
| **📊 Coordinador de Operaciones (`COORDINATOR`)** | Da de alta y administra cuentas `RESTOCKER` en el CRUD de Personal Interno; configura el «📦 Cuadrante de Carga» (Titulares y Suplentes) en cada Zona Operativa; supervisa la cobertura activa y promoción automática del `BACKUP`; gestiona ausencias, cierres forzados y rectificaciones horarias de reponedores filtrando por rol; y exporta el CSV horario. | Control total sobre la plantilla, zonas, ausencias y horas. No puede mutar el rol de un usuario ya creado (`C1`). Sus cierres forzados y ajustes horarios exigen motivo justificado y quedan auditados en `audit_log` (Art. III.3 y Art. V.1). |
| **📦 Cargador / Reponedor de Ruta (`RESTOCKER`)** | Accede a su vista móvil dedicada (`RestockerRouteView.js`), consulta sus zonas asignadas (`PRIMARY` y `BACKUP`), inicia su turno, registra pausas de comida, cierra su jornada directamente y consulta su contador diario en vivo y su historial mensual en «⏱️ Mis Horas». | **Segregación RBAC y aislamiento horizontal (Art. V.4):** Solo puede acceder a los endpoints habilitados para su rol y consultar únicamente sus propios turnos y horas; tiene **estrictamente bloqueado (`403 Forbidden`)** el acceso a colas de averías SAT, stock de repuestos de furgoneta SAT, órdenes preventivas, taller y horas de otros compañeros. |
| **🔧 Técnico de Campo SAT (`TECHNICIAN`)** | Continúa operando su hoja de ruta técnica (`TechnicianRouteView.js`), su cuadrante SAT y su control de turno sin alteraciones. | No se ve afectado por los cuadrantes ni ausencias de los reponedores. Sus sugerencias en `TechnicianAssignmentSuggester` evalúan exclusivamente a usuarios `TECHNICIAN`. |
| **🏢 Responsable de Sede (`LOCATION_RESPONSIBLE`) y Canal Público QR** | Ninguna interacción con este módulo. | **Opacidad total (Art. V.4):** La existencia de zonas de carga, cuadrantes de reponedores, turnos, ausencias y registros horarios es información interna estrictamente confidencial e invisible en el Portal de Sede y en el canal público QR. |

---

## 3. Historias de Usuario

### HU-01: Alta y administración de Cargadores/Reponedores de Ruta con RBAC segregado
> **Como** Coordinador de Operaciones,\
> **quiero** dar de alta usuarios con el rol **Cargador / Reponedor de Ruta (`RESTOCKER`)** y código de operador identificativo (ej. `REP-01`), manteniendo su rol inmutable tras la creación y sin guardia técnica 24/7,\
> **para** incorporar formalmente a la plantilla de abastecimiento sin concederles acceso a las herramientas de reparación electromecánica del SAT.

### HU-02: Cuadrante de Carga por Zona Operativa e independencia respecto al SAT
> **Como** Coordinador de Operaciones,\
> **quiero** asignar dentro de cada Zona Operativa qué reponedores son **Titulares (`PRIMARY`)** (máximo 1 zona titular por reponedor) y cuáles son **Suplentes (`BACKUP`)**, viendo en la misma zona tanto el «🔧 Cuadrante SAT» como el «📦 Cuadrante de Carga»,\
> **para** organizar el abastecimiento territorial de cada distrito sin interferir con la asignación de los técnicos de mantenimiento.

### HU-03: Promoción automática del Reponedor Suplente (`BACKUP`) ante ausencia o fin de turno
> **Como** Coordinador de Operaciones,\
> **quiero** que cuando el reponedor titular de una zona tenga una ausencia vigente o esté fuera de turno y exista un suplente disponible, el sistema promueva automáticamente al suplente como **`#1 RECOMENDADO ⭐ (Suplente en Cobertura Activa)`**,\
> **para** saber en todo momento qué reponedor cubre efectivamente cada zona sin tener que editar manualmente los cuadrantes durante vacaciones o bajas.

### HU-04: Control de jornada móvil y autoconsulta de «⏱️ Mis Horas» para el Reponedor
> **Como** Cargador / Reponedor de Ruta (`RESTOCKER`),\
> **quiero** entrar desde mi móvil a mi pantalla de ruta de carga, ver mis zonas asignadas, fichar el inicio de mi turno, mis pausas de comida y el cierre directo de mi jornada, y consultar mis horas del día y del mes en «⏱️ Mis Horas»,\
> **para** registrar mi jornada laboral de forma sencilla y transparente conociendo siempre mis zonas de responsabilidad.

### HU-05: Gestión unificada de ausencias, turnos y registro de horas con filtro por Rol
> **Como** Coordinador de Operaciones,\
> **quiero** gestionar las ausencias, supervisar o forzar el cierre de turnos abiertos y auditar/exportar el informe de «⏱️ Registro de Jornada y Horas» pudiendo filtrar por rol (**`Todos`**, **`Técnicos SAT`**, **`Reponedores`**),\
> **para** administrar las vacaciones, bajas y obligaciones legales de registro horario de toda la plantilla de calle desde una única consola.

---

## 4. Requisitos Funcionales (RF) y Criterios de Aceptación (EARS)

### RF-01 · Rol `RESTOCKER`, CRUD de Personal Interno y Segregación RBAC (`D1`, `C1`, `C3`)
El sistema deberá incorporar el rol `RESTOCKER` en el modelo de datos y en la capa de autorización, garantizando su inmutabilidad tras el alta y el aislamiento respecto a las operaciones exclusivas del Técnico SAT.

* **Criterio EARS 01.1 (Ampliación del catálogo de roles y alta en Personal Interno — D1, D3):**
  1. **EL SISTEMA DEBERÁ** incluir el valor `RESTOCKER` (*Cargador / Reponedor de Ruta*) en el tipo enumerado nativo `user_role` de PostgreSQL y en el enum de dominio `UserRole`.
  2. **CUANDO** Coordinación cree un usuario en el CRUD de Personal Interno (`POST /api/coordinator/staff`) con rol `RESTOCKER`, **EL SISTEMA DEBERÁ** exigir nombre, correo electrónico único, contraseña válida y `operator_code` único (ej. `REP-01`), permitir teléfono y `telegram_chat_id` opcionales, forzar invariablemente `is_on_call = FALSE` (rechazando `is_on_call = TRUE` con `422 RESTOCKER_CANNOT_BE_ON_CALL`) y registrar el evento `STAFF_USER_CREATED` en `audit_log` (Art. III.3).
* **Criterio EARS 01.2 (Inmutabilidad estricta del rol tras el alta — C1 y Art. III.3):** **SI** Coordinación intenta modificar el campo `role` de un usuario existente en la edición de Personal Interno (`PUT /api/coordinator/staff/{id}`) enviando un rol distinto al que ya tiene persistido (p. ej., cambiar `TECHNICIAN` a `RESTOCKER` o viceversa), **ENTONCES EL SISTEMA DEBERÁ** rechazar la operación con código HTTP `409 Conflict` (`ROLE_CHANGE_FORBIDDEN`) sin alterar el registro.
* **Criterio EARS 01.3 (Desactivación de cuenta con cierre automático de turno abierto — C3 y Art. III.1/III.3):** **CUANDO** Coordinación desactive la cuenta de un usuario `RESTOCKER` o `TECHNICIAN` (`is_active = FALSE` mediante `PUT /api/coordinator/staff/{id}` o acción de estado) **Y** dicho usuario tenga en ese instante un turno abierto (`closed_at IS NULL` en `technician_shifts`), **EL SISTEMA DEBERÁ** cerrar automáticamente el turno dentro de la misma transacción (descontando el tramo final de pausa si estaba en `MEAL_BREAK`) y registrar el evento `FORCE_CLOSE_SHIFT` en `technician_shift_logs` y en `audit_log` con el motivo `"Cierre automático por desactivación de cuenta de usuario"`.
* **Criterio EARS 01.4 (Segregación RBAC entre `RESTOCKER` y `TECHNICIAN` — D1 y Art. V.3/V.4):**
  1. **CUANDO** un usuario autenticado con rol `RESTOCKER` intente invocar cualquier endpoint exclusivo del Técnico SAT (gestión o resolución de incidencias correctivas, alta oportunista in situ, stock de repuestos en furgoneta SAT, recepción de órdenes de repuestos, órdenes de mantenimiento preventivo o bandeja de taller), **EL SISTEMA DEBERÁ** denegar el acceso con código HTTP `403 Forbidden`.
  2. **EL SISTEMA DEBERÁ** excluir en todo momento a los usuarios con rol `RESTOCKER` de los selectores de asignación de averías SAT y del motor `TechnicianAssignmentSuggester`, garantizando que una incidencia técnica solo pueda asignarse a un usuario `TECHNICIAN` (Art. V.3).

---

### RF-02 · Zonificación de Carga (`restocker_zone_assignments`) y Cuadrante Dual en Zonas (`D2`, `D3`, `C3`, `QA 1`, `QA 2`, `QA 4`)
El sistema deberá permitir vincular reponedores como Titulares (`PRIMARY`) o Suplentes (`BACKUP`) a las Zonas Operativas existentes sin colisionar con el cuadrante de Técnicos SAT.

* **Criterio EARS 02.1 (Estructura e invariantes de `restocker_zone_assignments` — D3, QA 1 y Art. III.1):**
  1. **EL SISTEMA DEBERÁ** persistir las asignaciones territoriales de carga en la Tabla 41 `restocker_zone_assignments` (`id`, `restocker_id`, `zone_id`, `role_in_zone` [`'PRIMARY'` | `'BACKUP'`], `is_primary`, `assigned_at`, `removed_at`), protegida por `chk_restocker_zone_role_coherence` y por el disparador en base de datos `trg_no_delete_restocker_zone_assignments` (`prevent_hard_delete()`, Art. III.1), auditando sus cambios bajo la entidad `'RESTOCKER_ZONE'` en `audit_entity_type` (`QA 1`).
  2. **EL SISTEMA DEBERÁ** garantizar mediante un índice único parcial (`uq_restocker_primary_zone WHERE is_primary = TRUE AND removed_at IS NULL`) que un reponedor solo pueda ser **Titular Principal (`PRIMARY` / `is_primary = TRUE`) de como máximo una zona operativa viva**, pudiendo ser **Suplente (`BACKUP` / `is_primary = FALSE`)** en cero, una o varias zonas operativas adicionales, y existiendo a lo sumo un vínculo vivo por pareja `(restocker_id, zone_id)` (`uq_restocker_zone_live WHERE removed_at IS NULL`).
* **Criterio EARS 02.2 (Validación de rol y traslado confirmado de titularidad — D3):**
  1. **SI** Coordinación intenta vincular al cuadrante de carga de una zona a un usuario cuyo rol no sea `RESTOCKER` o cuya cuenta esté inactiva (`is_active = FALSE`), **ENTONCES EL SISTEMA DEBERÁ** rechazar la operación con `422 Unprocessable Entity` (`INVALID_RESTOCKER_USER`).
  2. **SI** Coordinación intenta asignar a un reponedor como Titular (`PRIMARY` / `is_primary = TRUE`) de una zona cuando ya es Titular vivo de otra zona distinta y no envía `confirm_primary_transfer = true`, **ENTONCES EL SISTEMA DEBERÁ** responder con `409 Conflict` (`PRIMARY_ZONE_ALREADY_ASSIGNED`) informando de la zona previa; **CUANDO** se confirme con `confirm_primary_transfer = true`, **EL SISTEMA DEBERÁ** extinguir lógicamente la titularidad anterior (`removed_at = NOW()`) y registrar la nueva en una única transacción auditada (`RESTOCKER_ZONE_ASSIGNED`).
* **Criterio EARS 02.3 (Baja lógica idempotente del cuadrante y liberación al desactivar zona — D3, C3, QA 4 y Art. III.1):**
  1. **CUANDO** Coordinación retire a un reponedor del cuadrante de carga de una zona (`DELETE /api/coordinator/zones/{id}/restockers/{restocker_id}`), **EL SISTEMA DEBERÁ** sellar `removed_at = NOW()` sin borrar la fila y registrar el evento `RESTOCKER_ZONE_REMOVED` en `audit_log`; **SI** la vinculación ya estaba extinguida, **ENTONCES EL SISTEMA DEBERÁ** responder `200 OK` de forma idempotente sin escribir un nuevo evento de auditoría (`QA 4`).
  2. **CUANDO** Coordinación desactive una Zona Operativa que tenga códigos postales, técnicos SAT o reponedores vinculados (`removed_at IS NULL`), **EL SISTEMA DEBERÁ** exigir `confirm_release = true` y extinguir lógicamente todas las vinculaciones de carga de dicha zona (`removed_at = NOW()`) en la misma transacción.
* **Criterio EARS 02.4 (Contadores en zonas, visualización del Cuadrante de Carga, insignia «Baja» y consulta inversa — D2, C3, QA 2):**
  1. **EL SISTEMA DEBERÁ** exponer en `GET /api/coordinator/zones` y `GET /api/coordinator/zones/{id}` los contadores `primary_restockers_count` y `backup_restockers_count` de cada zona (`QA 2`).
  2. **CUANDO** Coordinación consulte el detalle de una Zona Operativa, **EL SISTEMA DEBERÁ** mostrar de forma separada el **«🔧 Cuadrante SAT (Técnicos)»** y el **«📦 Cuadrante de Carga (Reponedores)»**, listando primero a los Titulares (`PRIMARY`) y después a los Suplentes (`BACKUP`) por orden alfabético, con su nombre, `operator_code`, estado de turno actual, fecha de vinculación y botón de retirada.
  3. **MIENTRAS** exista una vinculación viva (`removed_at IS NULL`) de un reponedor cuya cuenta haya sido desactivada (`is_active = FALSE`) o que tenga una ausencia activa, **EL SISTEMA DEBERÁ** seguir mostrándolo en el cuadrante con la insignia **«Baja»** o **«Ausente»** y permitir su retirada manual (`C3`).
  4. **EL SISTEMA DEBERÁ** ofrecer en la consola territorial la consulta inversa por reponedor (`GET /api/coordinator/restockers/quadrants` y selector en UI), enumerando para cada reponedor activo su zona titular viva, sus zonas suplentes vivas o la alerta **«Sin zona asignada»**.

---

### RF-03 · Motor Determinista de Cobertura de Carga y Promoción Automática del `BACKUP` (`D3`, `C2`, `C3`, `QA 3`)
El sistema deberá resolver en tiempo real quién es el reponedor efectivo de cada Zona Operativa y promover automáticamente al suplente (`BACKUP`) cuando el titular (`PRIMARY`) esté ausente o fuera de turno.

* **Criterio EARS 03.1 (Escalafón de 6 niveles activos y promoción automática del `BACKUP` — D3, C2, QA 3):** **CUANDO** se consulte la cobertura de carga de una Zona Operativa (`GET /api/coordinator/zones/{id}/restocker-coverage` o en el propio detalle de zona), **EL SISTEMA DEBERÁ** evaluar a todos los reponedores con vínculo vivo en la zona (`removed_at IS NULL`) y clasificarlos según el siguiente orden estricto de prelación:
  1. **Nivel 1 (`PRIMARY_ON_SHIFT`):** Reponedor activo (`is_active = TRUE`) que es **Titular (`PRIMARY`)** de la zona y tiene turno abierto en estado **`ON_SHIFT`**.
  2. **Nivel 2 (`BACKUP_PROMOTED_ON_SHIFT`):** Reponedor activo (`is_active = TRUE`) que es **Suplente (`BACKUP`)** de la zona y tiene turno abierto en estado **`ON_SHIFT`** (promovido automáticamente cuando ningún `PRIMARY` está `ON_SHIFT`).
  3. **Nivel 3 (`PRIMARY_MEAL_BREAK`):** Reponedor activo (`is_active = TRUE`) que es **Titular (`PRIMARY`)** de la zona y tiene turno abierto en estado **`MEAL_BREAK`** (`QA 3`).
  4. **Nivel 4 (`BACKUP_PROMOTED_MEAL_BREAK`):** Reponedor activo (`is_active = TRUE`) que es **Suplente (`BACKUP`)** de la zona y tiene turno abierto en estado **`MEAL_BREAK`** (`QA 3`).
  5. **Nivel 5 (`PRIMARY_PLANNED_OFF_SHIFT`):** Reponedor activo (`is_active = TRUE`) que es **Titular (`PRIMARY`)**, está **`OFF_SHIFT`** y **NO tiene ninguna ausencia vigente** en `technician_absences` en el instante de evaluación.
  6. **Nivel 6 (`BACKUP_PROMOTED_OFF_SHIFT`):** Reponedor activo (`is_active = TRUE`) que es **Suplente (`BACKUP`)**, está **`OFF_SHIFT`** y **NO tiene ninguna ausencia vigente** (promovido automáticamente cuando el `PRIMARY` tiene ausencia vigente y aún no hay efectivos con turno abierto).
  7. **No Disponibles (`UNAVAILABLE`):** Reponedores en `OFF_SHIFT` con **ausencia vigente** en `technician_absences` o con **cuenta inactiva (`is_active = FALSE`)**, marcados con la insignia **`NO_DISPONIBLE`** y el motivo explícito (*«Ausencia activa: [Motivo]»* o *«Cuenta inactiva»*).
* **Criterio EARS 03.2 (Designación del `#1 RECOMENDADO ⭐` y desempate determinista — D3, C2, QA 3):**
  1. **EL SISTEMA DEBERÁ** marcar al primer candidato de los Niveles 1 a 6 como **`#1 RECOMENDADO ⭐`**; si dicho candidato es un `BACKUP` (Niveles 2, 4 o 6), **EL SISTEMA DEBERÁ** incluir la etiqueta explicativa **«Suplente en Cobertura Activa (Promoción automática)»**.
  2. **SI** dos o más reponedores de la zona empatan en el mismo nivel del escalafón, **ENTONCES EL SISTEMA DEBERÁ** desempatar por mayor antigüedad en la zona (`assigned_at ASC`), seguido de `operator_code ASC` e `id ASC`.
* **Criterio EARS 03.3 (Precedencia de presencia física al iniciar turno durante una ausencia — C2):** **CUANDO** un reponedor que tenga una ausencia planificada vigente en `technician_absences` pulse «Iniciar Turno» (`ON_SHIFT`), **EL SISTEMA DEBERÁ** permitir la apertura del turno, considerarlo disponible en los Niveles 1 o 2 por precedencia de presencia física y registrar el evento de advertencia en `audit_log` (`SHIFT_STARTED_DURING_ABSENCE`).

---

### RF-04 · Control de Jornada y Vista Móvil del Reponedor (`RestockerRouteView.js`) (`D1`)
El reponedor dispondrá de su propia interfaz móvil para gestionar su turno y consultar sus zonas y sus horas trabajadas, sin acoplamiento con las averías del SAT.

* **Criterio EARS 04.1 (Autenticación y enrutamiento móvil del rol `RESTOCKER` — D1):** **CUANDO** un usuario con rol `RESTOCKER` inicie sesión en la aplicación, **EL SISTEMA DEBERÁ** cargar exclusivamente la vista móvil dedicada **`RestockerRouteView.js`**, mostrando en su cabecera el nombre del operario, su `operator_code`, las insignias de sus zonas operativas asignadas (**Titular** y **Suplente**) y un estado informativo de su ruta de abastecimiento.
* **Criterio EARS 04.2 (Ciclo completo de turno móvil y cierre directo sin modal de relevo SAT — D1):**
  1. **EL SISTEMA DEBERÁ** permitir al usuario `RESTOCKER` ejecutar desde su vista móvil las acciones **«Iniciar Turno»** (`OFF_SHIFT` → `ON_SHIFT`), **«Pausa para Comer»** (`ON_SHIFT` → `MEAL_BREAK`), **«Reanudar Turno»** (`MEAL_BREAK` → `ON_SHIFT`) y **«Cerrar Turno»** (`ON_SHIFT` o `MEAL_BREAK` → `OFF_SHIFT`), persistiendo las sesiones y eventos inmutables en `technician_shifts` y `technician_shift_logs` (con soporte de múltiples sesiones diarias y acumulación exacta de múltiples pausas al segundo).
  2. **CUANDO** el usuario `RESTOCKER` pulse **«Cerrar Turno»** (ya sea desde `ON_SHIFT` o directamente desde `MEAL_BREAK`), **EL SISTEMA DEBERÁ** cerrar inmediatamente su jornada sin abrir el modal de relevo de averías técnicas ni invocar el protocolo de traspaso de tickets SAT.
* **Criterio EARS 04.3 (Contador diario en vivo y autoconsulta «⏱️ Mis Horas» con aislamiento horizontal — D1 y Art. V.4):**
  1. **EL SISTEMA DEBERÁ** mostrar en la barra de jornada de `RestockerRouteView.js` el tiempo efectivo acumulado en el día actual y el botón de acceso al modal de solo lectura **«⏱️ Mis Horas»** con el desglose de sesiones del mes en curso (incluyendo insignias `Cierre forzado` o `Ajustado por Coordinación` y marcas originales cuando existan).
  2. **EL SISTEMA DEBERÁ** filtrar las consultas móviles de turno y horas estrictamente por el `user_id` del token autenticado, impidiendo que un reponedor consulte datos de cualquier otro compañero (`Art. V.4`).

---

### RF-05 · Gestión Unificada de Ausencias, Turnos y Registro de Jornada con Filtro por Rol en Coordinación (`D2`, `QA 4`)
Coordinación gestionará las ausencias, el estado de turno y la analítica legal de horas de Técnicos SAT y Reponedores desde las consolas existentes mediante filtrado e identificación de rol.

* **Criterio EARS 05.1 (Gestión de ausencias y supervisión de turnos de Reponedores — D2):**
  1. **EL SISTEMA DEBERÁ** permitir a Coordinación registrar, listar y cancelar lógicamente ausencias (`VACATION`, `SICK_LEAVE`, `TRAINING`, `PERSONAL_LEAVE`, `COMPENSATORY`, `OTHER`) en `technician_absences` tanto para usuarios `TECHNICIAN` como para usuarios `RESTOCKER`, incorporando un filtro de rol (**`Todos`**, **`Técnicos SAT`**, **`Reponedores`**) y mostrando la insignia del rol junto a cada operario.
  2. **CUANDO** Coordinación registre una ausencia para un usuario `RESTOCKER`, **EL SISTEMA DEBERÁ** omitir el asistente de reasignación de averías SAT (aplicable solo a `TECHNICIAN`) e informar de las zonas operativas de carga en las que se activará la promoción automática del reponedor `BACKUP`.
  3. **EL SISTEMA DEBERÁ** permitir a Coordinación ejecutar cierres forzados de turno (en tiempo real con motivo $\ge 10$ caracteres o con `effective_closed_at` retroactivo con motivo $\ge 20$ caracteres reales, Art. V.1) sobre cualquier turno abierto de un `RESTOCKER`.
* **Criterio EARS 05.2 (Sub-pestaña «⏱️ Registro de Jornada y Horas» y exportación CSV con filtro y columna de Rol — D2, QA 4):**
  1. **CUANDO** Coordinación consulte la sub-pestaña «⏱️ Registro de Jornada y Horas» (Módulo 22), **EL SISTEMA DEBERÁ** ofrecer un selector de filtro por rol (**`Todos`** [`ALL`], **`Técnicos SAT`** [`TECHNICIAN`], **`Reponedores`** [`RESTOCKER`]), aplicar la regla de visibilidad `C5` sobre el rol seleccionado (todos los activos de ese rol + inactivos con sesiones en el periodo, `QA 4`), mostrar la insignia del rol de cada trabajador (`Técnico SAT` vs `Reponedor`), incluir en los avisos de turnos abiertos huérfanos y de duración anómala (`> 12h`) a los reponedores, y permitir la rectificación auditada de sus turnos cerrados bajo las mismas reglas de validación cuádruple y motivo de $\ge 20$ caracteres reales (Art. V.1 y Art. III.3).
  2. **PARA** todo usuario con rol `RESTOCKER`, **EL SISTEMA DEBERÁ** reportar en este módulo `0` en *Intervenciones Resueltas* y `0s` en *Tiempo en Intervención In Situ*, imputando el 100 % de sus *Horas Efectivas Netas* como *Tiempo en Ruta / Tránsito* hasta que se implementen las visitas de reposición in situ (Feature 24).
  3. **CUANDO** Coordinación descargue la exportación CSV de registro de jornada (`GET /api/coordinator/metrics/work-hours/export`), **EL SISTEMA DEBERÁ** respetar el filtro de rol seleccionado (`role=ALL|TECHNICIAN|RESTOCKER`) e incluir la columna **`Rol`** (`TECHNICIAN` / `RESTOCKER`) tanto en la *Sección 1 (Resumen por Operario)* como en la *Sección 2 (Desglose de Sesiones)*, manteniendo el formato UTF-8 BOM (`\xEF\xBB\xBF`), separador `;` y neutralización contra inyección de fórmulas.

---

## 5. Requisitos No Funcionales (RNF)

* **RNF-01 · Inviolabilidad de Datos y Prohibición de Borrado Físico (Art. III.1 y III.3):** Ninguna fila de `users`, `restocker_zone_assignments`, `technician_shifts`, `technician_shift_logs` ni `technician_absences` podrá ser eliminada mediante `DELETE FROM`. Toda baja de cuadrante utiliza `removed_at`, toda cancelación de ausencia utiliza `cancelled_at` y toda desactivación de usuario utiliza `is_active = FALSE`.
* **RNF-02 · Determinismo y Rendimiento:** El cálculo de cobertura en `RestockerZoneCoverageService` será 100 % determinista y se resolverá en menos de $100\text{ ms}$ por zona.
* **RNF-03 · Fidelidad al Sistema de Diseño (`docs/design.md`) y Dogma Vanilla (Art. IV):** `RestockerRouteView.js` y las mejoras en Coordinación se construirán en Vue 3 ES Modules nativos sin bundlers ni librerías externas, empleando la paleta oficial (`#2560ff`, `#f9fafb`, `#2c333f`, bordes `#c8cfda`), radios conservadores (`4px` en botones/chips, `8px` en tarjetas) y diseño *mobile-first* sin desbordamiento horizontal a `390px`.

---

## 6. Casos Límite

1. **Intento de cambiar el rol de un Técnico a Reponedor o viceversa en edición (`C1`):** Coordinación edita la ficha de un `TECHNICIAN` e intenta cambiar su rol a `RESTOCKER`. El backend rechaza la petición con `409 ROLE_CHANGE_FORBIDDEN` y preserva intacto el rol original.
2. **Intento de activar la guardia técnica 24/7 (`is_on_call = TRUE`) en un Reponedor (`D3`):** En el alta o edición de un `RESTOCKER`, si se envía `is_on_call = true`, el sistema lo rechaza con `422 RESTOCKER_CANNOT_BE_ON_CALL`, ya que la guardia 24/7 del Art. II corresponde exclusivamente a los Técnicos SAT.
3. **Coexistencia en una misma Zona Operativa de un Técnico Titular y un Reponedor Titular (`D2`, `D3`):** La *Zona Centro* tiene a `TEC-01` como Titular en `technician_zone_assignments` y a `REP-01` como Titular en `restocker_zone_assignments`. Ambos coexisten sin conflicto; al triar una avería SAT solo se evalúa a `TEC-01`, y al consultar la cobertura de carga solo se evalúa a `REP-01`.
4. **Traslado de titularidad de zona de un Reponedor (`RF-02.2`):** `REP-01` ya es Titular (`PRIMARY`) de *Zona Norte*. Coordinación intenta marcarlo como Titular de *Zona Sur*. El sistema advierte con `409 PRIMARY_ZONE_ALREADY_ASSIGNED`; al confirmar el traslado, la titularidad previa en *Zona Norte* se extingue lógicamente (`removed_at = NOW()`) y nace la titularidad en *Zona Sur* dentro de la misma transacción.
5. **Promoción automática del `BACKUP` antes de abrir turno vs. en turno activo y pausa de comida (`C2`, `QA 3`):**
   * A las 07:00 h (todos `OFF_SHIFT`), el titular `REP-01` está de vacaciones (`technician_absences` vigente) y el suplente `REP-02` no tiene ausencia: `RestockerZoneCoverageService` sitúa a `REP-02` como `#1 RECOMENDADO ⭐ (Suplente en Cobertura Activa)` (Nivel 6) y a `REP-01` como `NO_DISPONIBLE`.
   * A las 14:00 h, si `REP-01` está en jornada activa pero en su pausa de comida (`MEAL_BREAK`, Nivel 3) y el suplente `REP-02` sigue en su casa (`OFF_SHIFT`, Nivel 6), `REP-01` conserva el `#1 RECOMENDADO ⭐` por estar en jornada activa hoy (`QA 3`); en cambio, si `REP-02` está `ON_SHIFT` (Nivel 2), `REP-02` encabeza la cobertura en vivo.
6. **Reponedor con ausencia planificada que inicia turno físicamente (`C2`):** `REP-01` tenía registrado un día de permiso personal pero acude a trabajar y pulsa «Iniciar Turno» en su móvil. El sistema abre el turno, audita `SHIFT_STARTED_DURING_ABSENCE` y lo coloca en el Nivel 1 de cobertura de su zona por precedencia de presencia física.
7. **Desactivación de cuenta de un Reponedor con turno abierto y zona asignada (`C3`):** `REP-01` está en estado `ON_SHIFT` (o `MEAL_BREAK`) y Coordinación desactiva su usuario (`is_active = FALSE`). En la misma transacción, su turno abierto se cierra automáticamente (`FORCE_CLOSE_SHIFT` con motivo de baja de cuenta), su fila en el cuadrante de zona muestra la insignia «Baja» y `RestockerZoneCoverageService` lo marca como `NO_DISPONIBLE (Cuenta inactiva)` promoviendo inmediatamente al `BACKUP` de la zona.
8. **Cierre de turno directo del Reponedor desde pausa de comida (`D1`):** `REP-01` está en `MEAL_BREAK` desde hace 40 minutos y pulsa «Cerrar Turno». El turno se cierra sin pedir relevo ni mostrar modales de averías, y esos 40 minutos se suman automáticamente al acumulador `meal_break_seconds` para que no computen como tiempo efectivo de trabajo.

---

## 7. Fuera de Alcance

Quedan explícitamente excluidos del alcance de este Módulo 31 (reservados a las Features 22 a 28 del Bloque 4 según el Art. VI):
1. **Catálogo de productos, consumibles y planogramas por máquina:** Reservado a la **Feature 22**.
2. **Órdenes de carga de furgoneta de producto y control de salida de cámara frigorífica:** Reservado a la **Feature 23**.
3. **Ejecución de visitas de reposición in situ, control FEFO de caducidades y checklist de higiene diaria:** Reservado a la **Feature 24**.
4. **Recaudación de efectivo, carga de tubos de cambio y bolsas precintadas:** Reservado a la **Feature 25**.
5. **Despacho de avisos de Nivel 1 (sin vasos, sin producto, atasco leve) al reponedor y botón de escalado al SAT:** Reservado a la **Feature 26**.
6. **Planificador de rutas periódicas de carga (L-M-V, M-J, diaria) y mapa de paradas de reposición:** Reservado a la **Feature 27**.
7. **Cuadro de mando de roturas de stock (*Stockout Rate*) y mermas comerciales:** Reservado a la **Feature 28**.

---

## 8. Criterios de Finalización (Definition of Done)

1. El rol `RESTOCKER` existe en el enum nativo `user_role` de PostgreSQL y en `UserRole` de PHP, se puede crear y administrar desde el CRUD de Personal Interno con `is_on_call = FALSE` forzoso e inmutabilidad estricta de `role` en edición (`C1`), y cierra automáticamente cualquier turno abierto si la cuenta se desactiva (`C3`).
2. Las guardas RBAC impiden con `403 Forbidden` que un `RESTOCKER` acceda a endpoints exclusivos de reparación SAT y garantizan que un `RESTOCKER` jamás aparezca como asignable en averías electromecánicas (Art. V.3 y V.4).
3. La Tabla 41 `restocker_zone_assignments` está creada con `role_in_zone` + `is_primary` sincronizados (`QA 1`), disparador anti-borrado físico (`Art. III.1`), índice único parcial de titularidad única (`PRIMARY` en máximo 1 zona viva), entidad `'RESTOCKER_ZONE'` en `audit_entity_type`, contadores `primary_restockers_count`/`backup_restockers_count` en zonas (`QA 2`) y endpoints CRUD idempotentes y auditados en `audit_log` (`QA 4`).
4. El servicio `RestockerZoneCoverageService` y su endpoint evalúan el escalafón determinista de 6 niveles activos (`C2`, `QA 3`), promoviendo automáticamente al reponedor `BACKUP` disponible a `#1 RECOMENDADO ⭐` cuando el `PRIMARY` tiene ausencia vigente o está fuera de turno.
5. La vista móvil `RestockerRouteView.js` permite al reponedor ver sus zonas asignadas, gestionar su ciclo de turno (`ON_SHIFT`, `MEAL_BREAK`, `OFF_SHIFT`) con cierre directo sin modal de relevo SAT (`D1`), ver su contador diario en vivo y abrir «⏱️ Mis Horas».
6. Las vistas de Coordinación de **Zonas Operativas**, **Ausencias y Turnos** y **«⏱️ Registro de Jornada y Horas»** (incluida la exportación CSV) integran al `RESTOCKER` con filtro por rol (`Todos`, `Técnicos SAT`, `Reponedores`), regla de visibilidad `C5` (`QA 4`) e insignia/columna identificativa (`D2`).
7. Toda la batería de pruebas unitarias, de integración y guardas constitucionales pasa al **100 % en verde**.

---

## 9. Dudas Abiertas

* Ninguna. Las 3 decisiones de diseño (`D1`–`D3`), los 3 casos límite (`C1`–`C3`) y las 4 resoluciones de auditoría QA (`QA 1`–`QA 4`) han sido ratificadas por el Product Owner.
