# PLAN DE TAREAS DE IMPLEMENTACIÓN · MÓDULO M5: GESTIÓN DE REINTEGROS E IMPORTE RETENIDO / DINERO TRAGADO (TASKS.MD)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** `08-refunds` (Opción E: Atención al Consumidor y Gestión Económica)  
**Documento:** `specs/08-refunds/tasks.md`  
**Referencia Funcional:** [`specs/functional/refunds_spec.md`](../functional/refunds_spec.md) (RF-REF-01 a RF-REF-10, RNF-REF-01 a RNF-REF-05)  
**Contratos Técnicos y DDL:** [`specs/technical/refunds_contracts.md`](../technical/refunds_contracts.md)  
**Contrato de Limpieza de Pruebas:** [`specs/technical/testing_cleanup_contract.md`](../technical/testing_cleanup_contract.md) (`fk_refund_incident` es `ON DELETE RESTRICT`; toda limpieza de `incidents` en `tests/` pasa por `TestDataCleaner`)  
**Plan Técnico:** [`specs/08-refunds/plan.md`](plan.md)  
**Normativa Suprema:** [`constitution.md`](../../constitution.md) (Artículos I al VII)  
**Directrices Operativas:** [`AGENTS.md`](../../AGENTS.md) (SDD, Dogma Vanilla, Dualismo Lingüístico)  
**Sistema de Diseño Visual:** [`docs/design.md`](../../docs/design.md) (Inspiración Docker: Azul eléctrico `#2560ff`, radio binario 4px/8px, fondo canvas `#f9fafb`, alertas `#f8b60f` y éxito `#38bd7d`)  
**Estimación por Tarea:** 20–30 minutos  
**Regla de Ejecución:** Estricto orden de dependencias; no iniciar una tarea sin completar y verificar sus predecesoras.  

---

## Fase 1: Esquema de Base de Datos, Modelos y Excepciones de Dominio

- [x] **T-REF-01: Migración DDL idempotente y ampliación de esquema (`008_refund_management.sql`, `cloud_init.sql`)**
  * **Requisitos:** RF-REF-01, RF-REF-04, RF-REF-10, Constitución Art. III (Inviolabilidad de datos y soft delete)
  * **Dependencias:** Ninguna
  * **Hecho cuando:** La ejecución de `php bin/migrate.php` aplica con éxito `008_refund_management.sql`, creando las tablas `refund_requests` y `unclaimed_cash_findings`, y agregando `has_physical_reception` a `locations`; `database/cloud_init.sql` incorpora el esquema completo. La ampliación correlativa de `audit_log.entity_type` con `REFUND_REQUEST` y `UNCLAIMED_CASH_FINDING` (`009_refund_audit_entity.sql`) forma parte de la misma entrega, exigida por RNF-REF-01 y Art. III.3.

- [x] **T-REF-02: Implementar Enums y Modelos de Dominio (`CompensationMethod`, `RefundStatus`, `TechnicianFinding`, `CashCustodyAction`, `RefundRequest`, `UnclaimedCashFinding`)**
  * **Requisitos:** RF-REF-01, RF-REF-03, RF-REF-04, RF-REF-05, RF-REF-07, RF-REF-10, Constitución Art. VI (Anti-feature creep)
  * **Dependencias:** T-REF-01
  * **Hecho cuando:** Existen en `src/Core/Domain/Model/` los enums tipados (`CompensationMethod`, `RefundStatus`, `TechnicianFinding`, `CashCustodyAction`) y las entidades inmutables con tipado estricto PHP 8.2+ (`RefundRequest`, `UnclaimedCashFinding`) con validaciones de invariantes de dominio (anonimización de nombres, verificación segura de PIN en tiempo constante y regla de supervisión especial).

- [x] **T-REF-03: Implementar Excepciones de Dominio y suite unitaria (`RefundExceptionsTest.php`, `RefundDomainModelsTest.php`)**
  * **Requisitos:** RF-REF-01, RF-REF-02, RF-REF-03, RF-REF-05, RF-REF-06, RF-REF-10, Constitución Art. V.4
  * **Dependencias:** T-REF-02
  * **Hecho cuando:** Existen en `src/Core/Domain/Exception/` las clases tipadas (`InvalidRefundAmountException`, `InvalidIbanFormatException`, `InvalidBizumPhoneException`, `InvalidPickupPinException`, `ReceptionDeliveryNotAllowedException`, `InvalidRefundStateTransitionException`, `SiteRefundDataForbiddenException`), y `php tests/unit/RefundExceptionsTest.php` junto con `php tests/unit/RefundDomainModelsTest.php` pasan al 100% en verde evaluando códigos HTTP (`403`, `409`, `422`), mensajes en castellano, límites antifraude y anonimización de datos.

---

## Fase 2: Repositorios PDO y Servicios de Aplicación

- [x] **T-REF-04: Implementar `RefundRequestRepositoryInterface`, `PdoRefundRequestRepository.php`, `UnclaimedCashFindingRepositoryInterface` y `PdoUnclaimedCashFindingRepository.php`**
  * **Requisitos:** RF-REF-01, RF-REF-04, RF-REF-07, RF-REF-10, RNF-REF-01, Constitución Art. III
  * **Dependencias:** T-REF-01, T-REF-02, T-REF-03
  * **Hecho cuando:** `PdoRefundRequestRepository` implementa creación de expediente, búsqueda por ID y token de seguimiento (`findByTrackingToken`), consulta por incidencia y sede, actualización atómica de estados y listados filtrados para coordinación; `PdoUnclaimedCashFindingRepository` persiste hallazgos de monedas de oficio; y las consultas omiten columnas sensibles (`iban`, `bizum_phone`) en los métodos para roles restringidos.

- [x] **T-REF-05: Implementar `IbanValidationService.php` (Módulo 97 nativo en PHP puro) y suite unitaria (`IbanValidationServiceTest.php`)**
  * **Requisitos:** RF-REF-01, RF-REF-10, RNF-REF-03, Constitución Art. IV
  * **Dependencias:** Ninguna
  * **Hecho cuando:** `IbanValidationService` implementa la validación ISO 7064 Módulo 97 mediante aritmética por bloques sin dependencias Composer y validación de teléfonos Bizum (9 dígitos); `php tests/unit/IbanValidationServiceTest.php` pasa al 100% en verde verificando IBANs españoles e internacionales válidos, erróneos y con checksum falso.

- [x] **T-REF-06: Implementar `RefundManagementService.php` y suite unitaria (`RefundStateMachineTest.php`)**
  * **Requisitos:** RF-REF-01, RF-REF-02, RF-REF-03, RF-REF-05, RF-REF-06, RF-REF-07, RF-REF-08, RNF-REF-01, Constitución Art. III
  * **Dependencias:** T-REF-02, T-REF-04, T-REF-05
  * **Hecho cuando:** `RefundManagementService` gestiona la creación de expedientes con generación segura de PIN de 4 dígitos y token de 64 caracteres, transiciones legales de la máquina de estados, clasificación automática como `REQUIRES_COORDINATOR_APPROVAL` si $> 10,00\ \text{€}$, validación de PIN en entrega presencial, autorización y registro de liquidación digital con referencia bancaria; `php tests/unit/RefundStateMachineTest.php` pasa al 100% en verde.

- [x] **T-REF-07: Implementar `TechnicianRefundService.php` y pruebas unitarias de dictamen y custodia**
  * **Requisitos:** RF-REF-03, RF-REF-04, RF-REF-05, RF-REF-08, RNF-REF-04
  * **Dependencias:** T-REF-04, T-REF-06
  * **Hecho cuando:** `TechnicianRefundService` valida y procesa el dictamen de saldo (`FOUND_PHYSICAL`, `CONFIRMED_NO_CASH`, `UNVERIFIED_NO_CASH`), forzando la custodia física hacia caja central (`HELD_FOR_CENTRAL`) si el importe es $> 10,00\ \text{€}$ o el método es digital, y registrando hallazgos de monedas de oficio sin reclamación previa.

---

## Fase 3: Controladores REST y Registro en AppRouter

- [ ] **T-REF-08: Implementar `PublicRefundController.php` (`GET/PATCH /api/public/refunds/track`)**
  * **Requisitos:** RF-REF-02, RF-REF-07
  * **Dependencias:** T-REF-05, T-REF-06
  * **Hecho cuando:** El endpoint público permite consultar de forma anónima el estado del expediente mediante token seguro en la URL, y permite rectificar IBAN o teléfono cuando el estado es `PENDING_CONTACT`, rechazando peticiones con tokens inexistentes (`404`) o formatos inválidos (`422`).

- [ ] **T-REF-09: Modificar `QrIncidentController.php` para captura opcional de reintegro en `POST /api/qr/report`**
  * **Requisitos:** RF-REF-01, RF-REF-02, RF-REF-03
  * **Dependencias:** T-REF-06, T-REF-08
  * **Hecho cuando:** El endpoint existente `POST /api/qr/report` procesa los campos de reintegro si `refund_requested = true`, validando importes ($\le 50,00\ \text{€}$), método y datos de contacto, devolviendo en la respuesta el resguardo con PIN de 4 dígitos y la URL de seguimiento con token.

- [ ] **T-REF-10: Implementar `TechnicianRefundController.php` y actualizar `TechnicianController::resolveIncident`**
  * **Requisitos:** RF-REF-04, RF-REF-05, RF-REF-09, RF-REF-10, Constitución Art. V.4
  * **Dependencias:** T-REF-07
  * **Hecho cuando:** `GET /api/technician/incidents/{id}/refund` retorna las solicitudes asociadas omitiendo IBAN y teléfonos privados; y `POST /api/technician/incidents/{id}/resolve` procesa obligatoriamente el bloque `refund_inspection` si hay solicitudes activas, resolviendo la avería técnica y actualizando el expediente de saldo.

- [ ] **T-REF-11: Implementar `LocationRefundController.php` (`GET /api/location/refunds`, `POST .../deliver`)**
  * **Requisitos:** RF-REF-06, RF-REF-10, Constitución Art. V.4
  * **Dependencias:** T-REF-06
  * **Hecho cuando:** `GET /api/location/refunds` lista los reintegros del centro con nombres anonimizados y sin datos bancarios; y `POST /api/location/refunds/{id}/deliver` valida el PIN de 4 dígitos del usuario, transicionando a `REFUNDED_IN_HAND` si coincide o respondiendo `422 INVALID_PICKUP_PIN` si es incorrecto.

- [ ] **T-REF-12: Implementar `CoordinatorRefundController.php` (`GET /api/coordinator/refunds`, `approve`, `pay`, `reject`)**
  * **Requisitos:** RF-REF-03, RF-REF-07, RF-REF-08, RF-REF-10
  * **Dependencias:** T-REF-06
  * **Hecho cuando:** Los endpoints de coordinación permiten listar expedientes con detalle financiero completo, dar visto bueno formal ante importes $> 10,00\ \text{€}$ (`approve`), registrar pagos con justificante bancario (`pay`), y rechazar motivadamente con justificación $\ge 20$ caracteres (`reject`).

- [ ] **T-REF-13: Registrar rutas y protección RBAC en `AppRouter.php`**
  * **Requisitos:** RF-REF-01 a RF-REF-10, Constitución Art. V.4
  * **Dependencias:** T-REF-08, T-REF-09, T-REF-10, T-REF-11, T-REF-12
  * **Hecho cuando:** Todas las rutas públicas, de técnico, de sede y de coordinación están registradas en `AppRouter.php` con sus correspondientes middlewares de autenticación y rol, bloqueando accesos no autorizados (`401`, `403`).

---

## Fase 4: Componentes Frontend Vanilla (Vue.js 3 ES Modules)

- [ ] **T-REF-14: Implementar `QrRefundRequestBlock.js` e integrar en `QrReportView.js`**
  * **Requisitos:** RF-REF-01, RF-REF-02, RNF-REF-05
  * **Dependencias:** T-REF-09
  * **Hecho cuando:** El formulario de reporte ciudadano QR permite desplegar la sección de dinero retenido, validar en vivo teléfono Bizum e IBAN, y tras enviar muestra la tarjeta de resguardo con el PIN de 4 dígitos y el enlace permanente de seguimiento.

- [ ] **T-REF-15: Implementar vista pública `PublicRefundTrackingView.js`**
  * **Requisitos:** RF-REF-02, RF-REF-07, RNF-REF-05
  * **Dependencias:** T-REF-08
  * **Hecho cuando:** La aplicación carga la vista `PublicRefundTrackingView` al acceder mediante `?track={token}`, renderizando la línea de tiempo del estado, la tarjeta de recogida con PIN si está en conserjería, y el formulario de rectificación si está en `PENDING_CONTACT`.

- [ ] **T-REF-16: Implementar `TechnicianResolutionRefundBlock.js` e integrar en el modal de resolución móvil**
  * **Requisitos:** RF-REF-04, RF-REF-05, RNF-REF-04, RNF-REF-05
  * **Dependencias:** T-REF-10
  * **Hecho cuando:** El modal de resolución técnica de `TechnicianRouteView.js` despliega el bloque táctil de saldo si hay solicitudes pendientes, bloqueando el depósito en conserjería si $> 10,00\ \text{€}$ o digital, y permitiendo registrar monedas de oficio.

- [ ] **T-REF-17: Implementar `LocationRefundsTab.js` e integrar en `LocationPortalView.js` y suite frontend (`LocationRefundsTabTest.mjs`)**
  * **Requisitos:** RF-REF-06, RF-REF-10, RNF-REF-05, Constitución Art. V.4
  * **Dependencias:** T-REF-11
  * **Hecho cuando:** El portal de sede incluye la pestaña "Reintegros" con lista de avisos anonimizada y botón para abrir el modal con teclado numérico que valida el PIN de 4 dígitos; `node tests/unit/LocationRefundsTabTest.mjs` pasa al 100% en verde.

- [ ] **T-REF-18: Implementar `CoordinatorRefundsTab.js` e integrar en `CoordinatorDashboardView.js` y `app.js` y suite frontend (`CoordinatorRefundsTabTest.mjs`)**
  * **Requisitos:** RF-REF-03, RF-REF-07, RF-REF-08, RNF-REF-05
  * **Dependencias:** T-REF-12
  * **Hecho cuando:** El panel de Coordinación incluye la pestaña "Reintegros" con filtros por estado, modales reactivos de visto bueno, rechazo motivado y registro de justificante de pago digital; `node tests/unit/CoordinatorRefundsTabTest.mjs` pasa al 100% en verde.

---

## Fase 5: Pruebas de Integración HTTP, Blindaje Constitucional y Cierre

- [ ] **T-REF-19: Pruebas de integración HTTP de seguimiento público y reporte QR (`PublicRefundTrackingApiTest.php`)**
  * **Requisitos:** RF-REF-01, RF-REF-02, RF-REF-03
  * **Dependencias:** T-REF-08, T-REF-09, T-REF-13
  * **Hecho cuando:** `php tests/run_all.php` ejecuta `PublicRefundTrackingApiTest.php` contra MariaDB real pasando al 100% en verde en flujos de creación QR con PIN, consulta de estado por token seguro y rectificación en `PENDING_CONTACT`.

- [ ] **T-REF-20: Pruebas de integración HTTP de dictamen técnico en resolución (`TechnicianRefundInspectionApiTest.php`)**
  * **Requisitos:** RF-REF-04, RF-REF-05, RF-REF-09, RNF-REF-04
  * **Dependencias:** T-REF-10, T-REF-13
  * **Hecho cuando:** `php tests/run_all.php` ejecuta `TechnicianRefundInspectionApiTest.php` contra MariaDB real pasando al 100% en verde comprobando dictamen físico, regla de custodia forzada para caja central si $> 10\ \text{€}$, desacoplamiento del cierre técnico y registro de monedas atascadas de oficio.

- [ ] **T-REF-21: Pruebas de integración HTTP de entrega presencial con PIN en conserjería (`LocationRefundDeliveryApiTest.php`)**
  * **Requisitos:** RF-REF-06, RF-REF-10, Constitución Art. V.4
  * **Dependencias:** T-REF-11, T-REF-13
  * **Hecho cuando:** `php tests/run_all.php` ejecuta `LocationRefundDeliveryApiTest.php` pasando al 100% en verde verificando listado anonimizado, éxito ante PIN correcto transicionando a `REFUNDED_IN_HAND` y rechazo con `422` ante PIN erróneo.

- [ ] **T-REF-22: Pruebas de integración HTTP de Coordinación (`CoordinatorRefundWorkflowApiTest.php`)**
  * **Requisitos:** RF-REF-03, RF-REF-07, RF-REF-08, RNF-REF-01, RNF-REF-02
  * **Dependencias:** T-REF-12, T-REF-13
  * **Hecho cuando:** `php tests/run_all.php` ejecuta `CoordinatorRefundWorkflowApiTest.php` pasando al 100% en verde en flujos de visto bueno (> 10 €), desestimación motivada ($\ge 20$ chars), liquidación digital con referencia bancaria e inmutabilidad en `audit_log`.

- [ ] **T-REF-23: Pruebas de blindaje constitucional y segregación de sede (`SiteManagerRefundDataSegregationTest.php`)**
  * **Requisitos:** RF-REF-10, Constitución Art. III y Art. V.4
  * **Dependencias:** T-REF-13
  * **Hecho cuando:** `php tests/run_all.php` ejecuta `SiteManagerRefundDataSegregationTest.php` pasando al 100% en verde, certificando que los endpoints de coordinación devuelven `403 Forbidden` a roles no autorizados, que las respuestas de sede y técnico nunca proyectan las columnas `iban` ni `bizum_phone`, y que la tabla `refund_requests` prohíbe el borrado físico (`DELETE FROM`).

- [ ] **T-REF-24: Verificación global de regresión, Dogma Vanilla y cierre de módulo**
  * **Requisitos:** Todos (RF-REF-01 a RF-REF-10, RNF-REF-01 a RNF-REF-05, Constitución Art. I a VII)
  * **Dependencias:** T-REF-01 a T-REF-23
  * **Hecho cuando:** La ejecución de `php tests/run_all.php` completa todas las suites unitarias PHP, unitarias reactivas frontend e integración con 0 fallos y 0 errores; se verifica la ausencia de dependencias externas npm/composer y el cumplimiento estricto del Dualismo Lingüístico.

---

## Fase 5: Deuda Técnica Cerrada (post T-REF-04)

- [x] **T-REF-25: Contrato de limpieza de datos de prueba y barredor de suites (`TestDataCleaner`, `TestDataCleanerIsolationTest`, guardia Fase 0)**
  * **Requisitos:** Constitución Art. III (Inviolabilidad de datos), RF-REF-01 (FK `fk_refund_incident` con `ON DELETE RESTRICT`)
  * **Dependencias:** T-REF-01
  * **Incidencia:** `fk_refund_incident` y `fk_unclaimed_incident` bloquearon el `DELETE FROM incidents` que repetían 29 suites. Una sola fila de reintegro huérfana reventaba 22 suites a la vez con el error 1451, aunque la primera grieta ya venía de `006_spare_parts` (`fk_requests_incident`, `fk_replaced_incident`).
  * **Hecho cuando:** `php tests/run_all.php` aborta en Fase 0 si cualquier suite de `tests/integration/` o `tests/Manual/` borra `incidents` sin usar `TestDataCleaner`; el orden de borrado se deriva del grafo real de `information_schema` (12 tablas, `incidents` la última) de modo que una FK `RESTRICT` futura queda cubierta sin tocar código; `TestDataCleanerIsolationTest` demuestra que el orden inverso falla con 1451; y la Fase 4 purga y re-siembra para dejar la base en estado conocido.
  * **Documentación:** [`specs/technical/testing_cleanup_contract.md`](../technical/testing_cleanup_contract.md)
