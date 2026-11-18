# Changelog · VendGuard

Registro de los hitos de producto y de los módulos cerrados bajo el ciclo SDD.
El proyecto no publica versiones semánticas independientes: cada entrada corresponde
a un módulo funcional certificado por la batería global.

## [Módulo 35] · Recaudación de Efectivo, Tubos de Cambio y Arqueo en Cámara — 2026-11-17

**Estado:** Cerrado · los seis hallazgos de la corrida E2E corregidos y certificados.

### Añadido

- Tabla 49 `machine_cash_collections` con precinto único global (`uq_machine_cash_collections_bag_seal`), código `MCC-AAAA-NNNN` y tipo nativo #42 `cash_collection_status` (`SEALED_IN_ROUTE`, `DELIVERED_TO_VAULT`, `RECONCILED_OK`, `DISCREPANCY_FLAGGED`); `audit_entity_type` incorpora `MACHINE_CASH_COLLECTION`, con el censo canónico en **49 tablas y 42 tipos `ENUM`**.
- Ciclo de custodia completo: alta idempotente por `(restocker_id, client_mutation_id)` desde la visita del Módulo 34 o como operación suelta, recepción física en cámara y Arqueo Ciego con tolerancia de `±1,00 €`, justificación real de ≥ 20 caracteres y ≥ 3 bloques alfanuméricos, y re-arqueo bloqueado con `409`; «Recepcionar y Arquear» sella recepción y arqueo en una sola escritura desde `SEALED_IN_ROUTE` (EARS 04.2).
- Correlativo anual `MCC-AAAA-NNNN` reservado en la transacción de captura con `pg_advisory_xact_lock` y `MAX + 1`, relleno de cuatro dígitos y el índice único como última red: dos capturas simultáneas reciben números consecutivos y las semillas `0001`–`0004` siguen su curso por `0005`.
- Opacidad financiera: `expected_cashbox_eur` nunca se proyecta al reponedor, el recibo de recepción expone nueve campos sin importes calculados, y la entrega en conserjería de hasta `10,00 €` se vincula a un reintegro del Módulo 08 con transición atómica a `DEPOSITED_AT_RECEPTION`.
- Sub-vista de Coordinación «🪙 Recaudación y Arqueo» con 6 KPIs reactivos al filtro, filtros por reponedor, máquina, sede, estado, precinto y fechas, detalle con enlace a la visita, recepción en un toque, modal de arqueo ciego, recibo y exportación CSV con etiquetas legibles.
- Captura y cola offline del reponedor: botón rápido por máquina, bloque dentro de la visita, historial sin campos privados y `IndexedDB` FIFO aislada por reponedor con volcado de conflictos `409` a auditoría; Service Worker `vendguard-app-shell-v25`.
- Blindaje constitucional: disparadores de inmutabilidad por etapas (`45000`), guarda sanitaria en aplicación y motor (Art. II.2), catálogo de errores `403`/`409`/`422` y 12 endpoints REST con RBAC.

### Corregido

- **H-1:** el chat de n8n ya no intercepta clics en «Acciones» a 522 px (`pointer-events` aislado y pasillo de 88 px en la tabla). **H-2:** el asistente solo se monta para Coordinación y Técnico, con lista blanca en cliente y retirada al cambiar de rol. **H-3:** CDN y webhook salen del código a `config/chatAssistant.js`, con montaje diferido en inactividad y techo de espera del bundle.
- **H-4:** el CSV entrega la etiqueta legible en `Estado` y conserva el código técnico en la columna final `Código de estado`, sin mover las columnas de dinero. **H-5:** los códigos nuevos son correlativos por año en lugar de aleatorios. **H-6:** el arqueo directo desde `SEALED_IN_ROUTE` tiene aserción propia (200, `RECONCILED_OK` y doble sello persistido).
- El KPI de bolsas pendientes del reponedor se recalcula sobre los filtros activos de máquina, precinto, fechas y estado, y solo cuenta las bolsas que siguen en `SEALED_IN_ROUTE`.
- El volcado de conflicto offline exige una máquina activa cubierta por las zonas del reponedor (`404`/`403`), acota `client_mutation_id`, `reason`, `bag_seal_code` y `offline_created_at`, y audita el hecho bajo la entidad `MACHINE`.
- Los comentarios de la migración 031 alinean el correlativo con el formato real `MCC-AAAA-NNNN`, con guarda de regresión en la suite de esquema.
- `/api/health` degrada a `503 JSON` cuando el motor no responde en vez de provocar un fatal del router, y el visor de auditoría etiqueta las tres acciones de recaudación.

### Certificación

- `CashCollectionVaultAuditApiTest` (50/50), `CashCollectionVaultAuditConstitutionalTest` (25/25), `CashCollectionConcurrencyTest` (10/10), `CashCollectionLayoutBrowserTest` (32/32 a 360 px en navegador real), `PostgresCashCollectionVaultAuditSchemaTest` (47/47), `PdoMachineCashCollectionRepositoryTest` (47/47), `CashCollectionDomainModelsTest` (123/123), `MachineCashCollectionValidatorTest` (114/114) y `MachineCashCollectionServiceTest` (67/67).
- Batería global: **436 suites y 16.807 aserciones**, 0 fallos y `DocMetricsGuard` sin desviaciones (193/193 PHP Unit, 105/105 JS Unit, 135/135 de integración y 3/3 E2E manuales).
- Canal `new_engine`: **66/66 suites y 2.190 aserciones**, 0 no ejecutables, 0 fallos y salida `0`.
## [Certificación] · Batería global y canal new_engine tras los Módulos 25 a 34 — 2026-11-15

**Estado:** Cerrada en verde · cifras canónicas auditadas por `DocMetricsGuard`.

### Certificación

- Batería global: **432 suites y 16.381 aserciones**, 0 fallos y 100 % en verde en 464,23 s; 190/190 suites PHP Unit, 104/104 JS Unit, 135/135 de integración y 3/3 E2E manuales.
- Canal `new_engine`: **60 suites evaluadas, 59 en verde, 1 no ejecutable y 1.938 aserciones**, 0 fallos y salida `0`. La no ejecutable es `PostgresTieredSlaMetricsPerformanceTest`, que declara su propio contrato TLS (`deploy/local/supabase/.env.local`) contra el motor local del puerto 5432; el canal la publica con su causa (`SQLSTATE[08006] · server does not support SSL`) en lugar de omitirla. `PoolerLatencyMeasurementTest` pasa 30/30 aserciones.
- La insignia, la frase canónica y el árbol del README declaran esas mismas cifras y la guarda documental no registra desviaciones.

## [Módulo 34] · Visita de Reposición In Situ, Control FEFO y Limpieza Diaria — 2026-11-15

**Estado:** Cerrado · especificación, contratos, implementación y certificación en verde.

### Añadido

- Tablas 47 y 48 `machine_restock_visits` / `machine_restock_visit_items` (17 columnas cada una), de solo inserción —los disparadores `trg_immutable_*` rechazan `UPDATE` con `45000`—, código `MRV-AAAA-NNNN` e idempotencia `uq_machine_restock_visits_mutation (restocker_id, client_mutation_id)`.
- Trazabilidad de lote en el carril: `machine_planogram_slots` añade `current_batch_code` y `current_expiration_date`; la visita completada aplica `qty_after = qty_before − retiradas + añadidas`, actualiza la caducidad viva más próxima y descuenta la furgoneta de forma atómica.
- Triple cerrojo sanitario (Art. II): bloqueo por cuarentena, fuera de servicio o avería térmica abierta (`409 SANITARY_RESTOCK_BLOCKED`); lectura de cabina ≤ 4,0 °C en perecederos y mixtas y ≤ 8,0 °C en bebidas frías, con `ABORTED_THERMAL_BREACH` —precinto, foto y nota ≥ 20—, cuarentena y ticket crítico `TEMPERATURE_COLD`; retirada FEFO obligatoria (`422 FEFO_WITHDRAWAL_REQUIRED`) y prohibición de gastar un lote más nuevo (`422 FEFO_OLDER_VAN_BATCH_AVAILABLE`).
- Checklist de higiene adaptativo por tipología (cuatro puntos en café y mixtas; dos en frías, snacks y perecederos), fotografía ≤ 5 MB obligatoria ante aborto o merma y snapshot financiero visible solo para Coordinación (Art. V.4 y V.5).
- Asistente móvil con cola `IndexedDB`, volcado de conflictos `409 OFFLINE_VISIT_CONFLICT` y sub-vista de Coordinación «📋 Visitas de Reposición In Situ y Control FEFO»; la liquidación del Módulo 33 precarga unidades abastecidas y mermas.

### Corregido

- `OUT_OF_SERVICE` se alinea con `machines.is_blocked_no_access` y la avería térmica se persiste como `incident_category = 'TEMPERATURE_COLD'`; el ticket térmico se asigna con el recomendador territorial y encola sus alertas de outbox.

### Certificación

- `InSituRestockVisitApiTest`, `InSituRestockVisitConstitutionalTest`, `PostgresInSituRestockVisitSchemaTest`, `MachineRestockVisitServiceTest`, `MachineRestockVisitValidatorTest` e `InSituRestockVisitDomainModelsTest`.

## [Módulo 33] · Órdenes de Carga de Producto y Picking con Trazabilidad de Frío — 2026-11-14

**Estado:** Cerrado · ciclo completo de carga, recepción y liquidación certificado.

### Añadido

- Tablas 44–46 (`restocker_van_product_stocks`, `product_loading_orders`, `product_loading_order_items`) y enumerados 28–30; orden `PLO-AAAA-NNNN` y una sola activa por reponedor (`uq_product_loading_orders_active_restocker`).
- Propuesta matutina `ALERTS_ONLY` / `ALL_GAPS` con colchón `0..50 %`, descuento del stock útil de la furgoneta y exclusión declarada de máquinas en cuarentena o fuera de servicio.
- Despacho con temperatura de cámara `[-5,0, 4,0] °C` (`422 COLD_CHAMBER_TEMP_EXCEEDED`), lote real y caducidad dentro de `max_shelf_life_days` (`422 EXPIRATION_EXCEEDS_MAX_SHELF_LIFE`), con caducidad inmutable por lote (`409 BATCH_EXPIRATION_MISMATCH`).
- Recepción móvil en un toque, liquidación al cierre con la identidad `stock − abastecidas − mermas = retorno esperado` frente al contado, notas ≥ 20 caracteres ante descuadre y autocierre de líneas con `dispatched_qty = 0`.
- Stock por lote con semáforo `EXPIRED` / `EXPIRING_SOON` / `OK`, ajuste auditado de Coordinación y **Hoja de Picking A4** en dos bloques sin costes ni precios (Art. V.4).

### Corregido

- La liquidación admite el alias `notes`, el alta individual sigue permitida para un reponedor ausente (con aviso), la recepción tolera producto desactivado en tránsito y la cancelación desde `DISPATCHED` conserva la coherencia del ciclo.

### Certificación

- `ProductLoadingColdPickingApiTest`, `ProductLoadingConcurrencyTest`, `ProductLoadingConstitutionalTest`, `PostgresProductLoadingSchemaTest`, `ProductLoadingOrderServiceTest` y `ProductLoadingValidatorTest`.

## [Módulo 32] · Catálogo Maestro de Productos y Planograma por Máquina — 2026-11-13

**Estado:** Cerrado · catálogo, planograma y cerrojo sanitario certificados.

### Añadido

- Tablas 42 y 43 (`vending_products`, `machine_planogram_slots`) y enumerados 26–27: categorías `COLD_DRINK`, `SNACK`, `PERISHABLE_FOOD`, `HOT_BEVERAGE_INGREDIENT` y `CUP_STIRRER_CONSUMABLE`; unidades `UNIT`, `GRAMS` y `SLEEVE`.
- `sku` único e inmutable; coste y precio acotados —precio `0,00 €` solo en ingredientes y consumibles—, cadena de frío obligatoria en perecederos y vida útil máxima para el control de caducidades.
- Planograma con capacidad (1–10.000), umbral menor que la capacidad, existencia estimada dentro de ella y una fila viva por `(máquina, carril)`, con baja lógica.
- Cerrojo sanitario bidireccional: los disparadores impiden carriles incompatibles con el tipo de máquina o con perecedero sin frío, y el cambio de tipología responde `409 PLANOGRAM_INCOMPATIBLE_WITH_MACHINE_TYPE`.
- Presets por modelo normalizado y clonado entre máquinas; el reponedor lee el planograma de sus zonas sin coste, precio ni margen (Art. V.4).

### Corregido

- Los errores de validación se muestran dentro del modal, la selección de máquina respeta los filtros activos, los márgenes no disponibles o negativos se representan correctamente y la insignia de producto retirado unifica su texto.

### Certificación

- `ProductCatalogPlanogramApiTest`, `PlanogramConstitutionalTest`, `PostgresProductPlanogramSchemaTest`, `MachinePlanogramServiceTest`, `PlanogramCompatibilityValidatorTest` y `ModelPlanogramPresetCatalogTest`.

## [Módulo 31] · Rol de Cargador/Reponedor de Ruta, Zonificación de Carga y Jornada — 2026-11-12

**Estado:** Cerrado · cuarto rol operativo con cobertura territorial y registro horario.

### Añadido

- Rol `RESTOCKER` en `user_role`, con alta en el CRUD de personal, código de operador (`REP-01`, `REP-02` en semillas), rol inmutable (`409 ROLE_CHANGE_FORBIDDEN`) y guardia técnica prohibida (`chk_users_restocker_not_on_call`).
- Tabla 41 `restocker_zone_assignments` con `role_in_zone` PRIMARY/BACKUP e `is_primary` sincronizados: un titularato vivo por reponedor, suplencias sin límite, baja lógica y auditoría `RESTOCKER_ZONE`.
- Consolas de Coordinación con contadores `primary_restockers_count`/`backup_restockers_count`, dos cuadrantes independientes (SAT y Carga), lectura inversa por operario y cobertura en vivo con seis niveles y recomendado.
- Filtro por rol en Ausencias, Turnos y Registro de Horas, con columna de rol en el CSV; cierre de turno directo del reponedor, sin traspaso de averías, y promoción automática del suplente al ausentarse.
- Vista móvil `RestockerRouteView.js` con zonas, jornada y modal «⏱️ Mis Horas»; las rutas SAT le responden `403`.

### Certificación

- `RestockerRoleZonesShiftsApiTest`, `RestockerRbacConstitutionalTest`, `RestockerStaffHttpTest`, `PostgresRestockerZoneSchemaTest`, `RestockerZoneCoverageRankerTest` y `RestockerShiftAndWorkHoursTest`.

## [Módulo 30] · Perfiles de SLA Diferenciados por Tipo de Sede — 2026-11-11

**Estado:** Cerrado · contratos escalonados con techo sanitario y sellado inmutable.

### Añadido

- Enumerado `sla_tier` y `locations.sla_tier` (Estándar por defecto), con `422 INVALID_SLA_TIER` fuera del catálogo y auditoría solo del cambio efectivo de contrato.
- Matriz de objetivos por urgencia —Estándar 8/12/24 h, Prioritario 6/8/12 h, Crítico 24x7 4/4/8 h— y techo innegociable de **240 min** para perecederos y mixtas con fallo térmico o prioridad crítica (Art. II).
- `incidents.sla_tier_snapshot` inmutable (`23514 IMMUTABLE_SLA_TIER_SNAPSHOT`), `sla_target_minutes` con `CHECK` de los cinco valores y `sla_target_at`; backfill que conserva el umbral de los expedientes cerrados.
- Recálculo desde `created_at` al cambiar la prioridad en triaje o al agravarse por absorción QR, con descuento de pausas de sede y del intervalo cerrado de una reapertura en garantía (Art. V.6).
- Ruta que promueve a crítico todo vencimiento ≤ 4,0 h con prelación `COLD_CHAIN` sobre `CONTRACTUAL_SLA`, escalado contractual 24/7 con bono +60 y saturación por ≥ 3 paradas vivas.
- Métricas por sede con perfil, objetivo nominal, brechas ticket a ticket y estado SLA; visibilidad en Coordinación, técnico (online y offline) y portal propio, con opacidad en QR y A4 (Art. V.4).

### Certificación

- `TieredSlaDomainTest`, `TieredSlaContractsApiTest`, `PostgresTieredSlaSchemaTest`, `PostgresTieredSlaBackfillTest` y `TieredSlaFrontendTest.mjs`.

## [Módulo 29] · Acta de Merma y Destrucción de Lotes por Cuarentena Sanitaria — 2026-11-07

**Estado:** Cerrado · documento oficial inmutable y libro APPCC certificados.

### Añadido

- Tabla 40 `sanitary_spoilage_records` (22 columnas) con `record_code` único `ACTA-MERMA-AAAA-NNNN`, distinción entre técnico certificador y usuario que tramita, origen de la cuarentena, temperaturas, método de destrucción, foto obligatoria y observaciones de higiene de 20–1000 caracteres.
- Coherencia de las dos ramas —máquina vacía o lotes reales con unidades destruidas y sus `CHECK`—, levantamiento atómico, desbloqueo del QR y relación `1..N` ante reaperturas en garantía.
- Inmutabilidad tras la emisión: solo cabe el ajuste económico auditado, con motivo y rastro; coste visible únicamente para Coordinación (Art. III.1 y V.4).
- Pestaña APPCC con bandeja, filtros y CSV, histórico por máquina y **Acta Oficial A4** imprimible.

### Certificación

- `SanitarySpoilageApiIntegrationTest`, `SanitarySpoilageServiceIntegrationTest`, `PostgresSanitarySpoilageSchemaTest`, `SanitarySpoilageValidatorTest` y `SanitarySpoilageFrontendTest.mjs`.

## [Módulo 28] · Guías Rápidas de Diagnóstico por Modelo — 2026-11-05

**Estado:** Cerrado · catálogo y superficie móvil certificados.

### Añadido

- Tabla 39 `machine_troubleshooting_guides` (14 columnas) con modelo visible y clave plegada, categoría del aviso, tres bloques `JSONB` acotados (0–20 códigos, 2–10 pasos, 0–10 repuestos) y una sola guía viva por modelo/categoría.
- Uso en ruta con conmutación de modelo, acordeón, cruce de repuestos con el stock de la furgoneta, enlace a la causa raíz sugerida y sincronización sin conexión; excluida de las inspecciones preventivas y con aviso de cadena de frío.
- Gobierno en Coordinación con instrucciones ≥ 20 caracteres reales por paso, inmutabilidad de modelo y categoría, auditoría del estado anterior y el nuevo y semillas de los modelos del parque.

### Certificación

- `TroubleshootingGuideApiIntegrationTest`, `TroubleshootingGuideServiceIntegrationTest`, `PostgresTroubleshootingGuideSchemaTest`, `TroubleshootingGuideValidatorTest` y `TroubleshootingGuideSurfacesGuardTest.mjs`.

## [Módulo 27] · Bandeja de Taller Central para Piezas Recuperadas — 2026-11-04

**Estado:** Cerrado · ciclo de reacondicionamiento y economía circular certificados.

### Añadido

- Tabla 38 `workshop_repair_items` (34 columnas): una fila por unidad física, con snapshot de coste y vínculo a la pieza, el expediente y la máquina de origen; diez claves foráneas `ON DELETE RESTRICT` y coherencia terminal entre restauración y baja.
- Ciclo de recepción, inicio en banco y dictamen con diagnóstico ≥ 20 caracteres reales, control de concurrencia sobre la misma pieza y destino de reintegro al inventario o baja.
- Ahorro por reacondicionamiento frente a compra, ordenación de la bandeja, CSV y recordatorio móvil de la pieza pendiente de entregar.
- Opacidad de importes para el técnico y de la bandeja completa para la sede (Art. V.4).

### Certificación

- `WorkshopRefurbishmentApiIntegrationTest`, `WorkshopRefurbishmentServiceIntegrationTest`, `PostgresWorkshopRepairSchemaTest` y `WorkshopRepairDomainAndValidatorTest`.

## [Módulo 26] · Historial de Reasignaciones y Cadena de Custodia — 2026-11-03

**Estado:** Cerrado · tramos inmutables y trazabilidad de custodia certificados.

### Añadido

- Tabla 37 `incident_assignments` con snapshots de nombre y código del técnico y de quien asigna o desasigna, cierre con motivo de 1–1000 caracteres y un solo custodio técnico activo (`uq_incident_assignments_active_custody`).
- Apertura y cierre automático del tramo en las cuatro vías —asignación, reasignación, desasignación y relevo de turno— dentro de la misma transacción, con `409` ante colisión y prohibición de reescribir un tramo cerrado (Art. III.3 y V.3).
- Línea de tiempo «Cadena de Custodia» en el detalle de coordinación con retención y espera en bandeja, además de métricas por técnico; la sede, el QR y el albarán no reciben identidades ni histórico (Art. V.4).
- Enumerados `incident_assignment_source` (9 canales) e `incident_unassignment_reason_type` (10 causas).

### Certificación

- `CustodyChainApiTest`, `CustodyChainConstitutionalTest`, `CustodyChainEdgeCasesTest`, `CustodyChainPerformanceTest`, `ShiftHandoverCustodyChainTest` y `OpportunisticCustodyChainTest`.

## [Módulo 25] · Taxonomía de Causas Raíz y Análisis Pareto 80/20 — 2026-10-31

**Estado:** Cerrado · catálogo gobernado y Pareto certificados.

### Añadido

- Tablas 35 y 36 (`root_causes`, `standard_solutions`) con 16 causas y 34 soluciones estandarizadas en cinco categorías; código y categoría inmutables, baja lógica y una única predeterminada activa por causa (`uq_standard_solutions_default_per_cause`).
- Alta atómica de la causa con su primera solución, guardas que impiden dejar una causa activa sin predeterminada y auditoría del estado anterior y el nuevo (Art. III.3).
- Captura obligatoria en la resolución del técnico, la avería oportunista y el cierre de coordinación (`require_taxonomy`), con compatibilidad para las llamadas históricas sin claves.
- Cinco snapshots congelados en el cierre, conservación de la clasificación anterior en la línea de tiempo al reabrir en garantía y exención de la retirada por 3.ª reincidencia crónica.
- Diagrama Pareto con cierre exacto en `100,00 %`, Zona Vital al cruzar el `80,00 %`, cruce de MTTR y coste de repuestos, filtros, contador de históricas sin clasificar y CSV de dos secciones auditado; certificado bajo 500 ms con 10.000 incidencias.
- Acceso restringido: Coordinación gobierna y exporta; el técnico solo lee catálogo y etiquetas; sede, QR y albarán reciben `403` (Art. V.4).

### Certificación

- `RootCauseTaxonomyIntegrationTest`, `RootCauseTaxonomyServiceTest`, `RootCauseParetoServiceTest`, `ResolutionTaxonomyValidatorTest`, `PostgresRootCauseTaxonomySchemaTest`, `RootCauseParetoPerformanceTest` y `RootCauseTaxonomyFrontendAndPwaTest.mjs`.

## [Módulo 24] · Cierre de cobertura del Modo Sin Conexión PWA — 2026-10-28

**Estado:** Cerrado · cobertura EARS completa y documentación alineada con el código.

### Añadido

- Certificación directa del indicador permanente de antigüedad y conectividad (`ageLabel` / `connectionLabel`) del Módulo 24.
- Certificación del orden FIFO de la bandeja y del rechazo 4xx de un comentario intermedio sin bloquear la resolución posterior.
- Certificación de las guardas online-only de login, turno, relevo y avería oportunista, del albarán A4 diferido y de la insignia local de parada.
- Contenido real del paquete `/bootstrap`: ruta, preventiva, catálogo por modelo y saldo de furgoneta, no solo la presencia de las claves.
- Prueba de concurrencia real de la idempotencia (`OfflineSyncMutationConcurrencyTest`, canal `new_engine`): dos conexiones PostgreSQL certifican que la petición perdedora espera en el índice único y responde como repetición sin ejecutar el dominio, y que un veredicto negativo del dominio deshace la reserva.

### Corregido

- Idempotencia atómica: la reserva de la clave `(technician_id, client_mutation_id)` se escribe dentro de la misma transacción que la transición de dominio y **antes** de ejecutarla. Una segunda petición con la misma clave espera al ganador y responde como repetición en lugar de competir por la transición, y un veredicto negativo del dominio deshace la reserva en vez de quemar la clave.
- Rescate de envíos interrumpidos: un elemento que quedó en `SYNCING` porque la pestaña murió a mitad de envío vuelve a la cola al hidratar y en cada pasada de sincronización, en lugar de bloquear el cierre de sesión para siempre sin nada que enviar.
- Veredictos `4xx` permanentes: un `404`, `400` o `410` (antes tratado como caída de red) queda aislado como conflicto y el FIFO continúa; un `413` conserva el trabajo y retira solo la evidencia que el servidor rechazó. Solo `408`, `425`, `429` y los `5xx` se reintentan.
- Contratos del Service Worker: `CACHE_NAME` v2, ausencia de `/` en el App Shell y caída explícita a `/index.html` en navegación sin red.
- Matriz de trazabilidad del Módulo 24: referencias de pruebas, recuentos y estado del DoD 1 alineados con la corrida real.

### Corregido en la revisión del PR #9 — 2026-10-29

- **Alta atómica de la cola (H4):** el `sequence_order`, el alta en `mutation_outbox`, el cierre del inicio previo y la proyección optimista viajan en una sola transacción sobre ambos almacenes. Una colisión del índice único ya no deja el cambio local sin su entrada en la cola: la transacción se deshace entera y el alta se reintenta releyendo el máximo.
- **Copia local transaccional (M2):** toda lectura-modificación-escritura del snapshot (refresco, turno, proyección, reversión de stock) pasa por `mutateSnapshot()`, encadenada dentro de la pestaña y serializada entre pestañas; un mutador asíncrono se rechaza con `SNAPSHOT_MUTATOR_ASYNC` en lugar de cerrar la transacción antes de escribir.
- **Coordinación entre pestañas (H5):** la bandeja se abre bajo `navigator.locks` (`vendguard-offline-sync`) más un mutex local —también sin Web Locks—, de modo que dos pestañas del mismo técnico no vacían la misma cola a la vez **mientras exista un cerrojo de dispositivo**; en un origen no seguro, sin Web Locks, la exclusión la sostiene el arriendo compartido (`R2`) y, si tampoco hay almacén compartido, la bandeja declara `tab-only` en lugar de prometer una garantía que no puede dar. Un `BroadcastChannel` avisa del cambio para que la otra relea su estado, sin eco y sin que una pestaña que solo escucha escriba nunca.
- **Plazo propio de las subidas (M1):** las peticiones `multipart/form-data` con evidencia usan `UPLOAD_TIMEOUT_MS = 30000` (5 MB no caben en 5,0 s sobre cobertura móvil débil) y el aviso de plazo agotado informa de 30,0 segundos; el resto de peticiones conserva los 5,0 s y el `ATTR_TIMEOUT` del driver PDO no cambia.
- **Evidencia binaria y purga por sesión (M3):** la base local sube a la v2 y guarda la foto como `Blob` (sin la copia base64 un 33 % mayor), con migración de las evidencias ya encoladas en la v1; la copia descarta el correo y el nombre del contacto de sede, purga las sesiones ajenas sin trabajo pendiente y las copias caducadas vacías, y el cierre de sesión sigue borrando todo el dispositivo.
- **Volcado de conflicto acotado a la ruta del técnico (B1):** un `stop_id` de una avería que nunca fue suya o de una preventiva ajena responde `404` sin escribir Nota Interna ni mutación, en lugar de aceptar cualquier identificador.
- **Validación de la evidencia antes de codificarla (B2):** el tamaño y el formato se comprueban sobre el archivo antes de leer sus bytes; una foto de más de 5 MB se rechaza sin codificarla en memoria.
- **Certificación del cerrojo en navegador real:** `OfflineSyncLockBrowserTest` abre dos pestañas de Chromium sobre el mismo perfil, comparte `IndexedDB` y arranca el vaciado de la misma cola a la vez. Un servidor de pruebas mide los envíos en vuelo: con cerrojo hay 3 envíos y `maxConcurrent = 1`; en el control negativo sin cerrojo se ven más de 3 envíos y solapamiento, de modo que el verde no puede venir de un arnés que no sabe detectar el fallo. La suite vive en el canal `new_engine` porque exige navegador y sale con código 2 si no lo encuentra.
- **El respaldo del cerrojo ya no reejecuta la tarea (R1):** `withWebLock()` solo ejecuta la tarea sin cerrojo cuando el navegador **deniega la concesión**; si el callback ya arrancó, su excepción se propaga tal cual. Antes, cualquier fallo de la tarea se tomaba por un cerrojo rechazado y se reintentaba en el acto: un volcado con la red caída lanzaba una segunda subida de hasta 5 MB y un alta que fallara después de escribir podía duplicar la mutación con una clave nueva.
- **El volcado de conflicto ya no deja fotos huérfanas (R3):** la evidencia se sube antes de saber si algún registro la va a referenciar, así que el controlador la retira cuando el volcado es una repetición idempotente, una orden preventiva (que solo anexa texto) o un rechazo `404` por titularidad. Antes, cada reintento de un volcado ya aplicado dejaba en `public/uploads` un archivo de hasta 5 MB que nadie borraba.
- **Las copias guardadas por la v1 se podan al actualizar (R4):** la minimización llegaba al normalizar el paquete, así que un dispositivo que actualizaba y seguía sin red conservaba el correo y el nombre del contacto de sede hasta el siguiente bootstrap —que podía no llegar en días—. La actualización a la v2 pasa ahora las copias por `minimizeSnapshot()` dentro de la misma transacción `versionchange` que migra la evidencia: la poda y la migración son un solo paso, una fila ya limpia no se reescribe y el error de una fila concreta no tumba la actualización.
- **Los límites de la protección en reposo quedan declarados (R5):** la copia, la cola y las fotos viven en `IndexedDB` sin cifrado adicional; mientras la sesión está abierta y el dispositivo desbloqueado, son legibles con las herramientas del navegador. La protección es de ciclo de vida —aislamiento por técnico, purga al cerrar sesión, purga de sesiones ajenas y de copias caducadas vacías, minimización— y el cifrado en el cliente queda explícitamente fuera de alcance (`RNF-05`).
- **La coordinación entre pestañas ya no depende en silencio del contexto seguro (R2):** `navigator.locks` solo existe en origen seguro y sobre HTTP plano dos ventanas podían vaciar la misma cola sin que nada lo advirtiera. `withDeviceLock()` elige el mejor mecanismo disponible y `lockMode` declara cuál es: `web-locks`, `storage-lease` (arriendo en `localStorage` con token, caducidad de 5 s, latido y sondeo, que espera como esperaría Web Locks) o `tab-only`, que la bandeja advierte con `offline-lock-warning` pidiendo sincronizar desde una sola ventana. En el servidor, `HttpsPolicy` responde `308` a cualquier petición pública por HTTP plano —exime solo el bucle local y la sonda `/api/health`—, emite `Strict-Transport-Security` cuando ya hay TLS (directo o por `X-Forwarded-Proto`) y sanea `Host` antes de construir `Location`; la única excepción es `VENDGUARD_ALLOW_INSECURE_HTTP=1`, explícita y pensada para una demo en LAN sin terminador TLS.

---

## [Módulo 23] · Órdenes de Carga de Furgoneta y Cruce de Stock en Triaje — 2026-10-26

**Estado:** Cerrado · especificación funcional, contratos técnicos, implementación y certificación en verde.

### Añadido

- Órdenes de carga `van_restock_orders` y líneas `van_restock_order_items` (Tablas 32 y 33) con ciclo
  `PENDING_PICKING` → `DISPATCHED` → `CONFIRMED_BY_TECH` / `CANCELLED`, índice único parcial
  `uq_van_restock_open_per_tech` para una sola orden abierta por técnico (D3) y disparadores
  `trg_no_delete_*` de blindaje constitucional (Art. III.1).
- Propuesta de reposición que combina el umbral mínimo con las solicitudes pendientes de averías activas
  (fórmula aditiva de «Ambos», Resolución QA 3), ordenación determinista por motivo, saldo y referencia,
  y generación en lote que omite técnicos sin déficit, ausentes o con orden abierta.
- Edición de órdenes en preparación con **baja lógica** de líneas (`removed_at` / `removed_by`) y
  reactivación al volver a añadir la pieza, sin `DELETE` físico (Art. III.1 y Resolución QA 1).
- Expedición con cantidades reales servidas (`dispatched_qty`), despacho parcial permitido y tope
  `saldo + cantidad ≤ 9.999`; cancelación con justificación de al menos 20 caracteres reales (`btrim`,
  Art. V.1) y motivo persistido en auditoría.
- **Albarán de Picking A4** imprimible con CSS `@media print` y `window.print()`, cabecera de orden,
  casillas físicas y firmas, sin ningún coste ni precio (Art. V.4).
- Recepción móvil en un toque con independencia del estado de turno, incremento atómico de
  `technician_van_stocks` bajo `SELECT ... FOR UPDATE` y comprobante de la última carga confirmada
  (Resoluciones QA 4 y QA 5).
- Cruce informativo `van_stock_match` en dos niveles (piezas solicitadas y familia compatible) y
  `van_stock_readiness` en las averías activas, sin alterar `TechnicianWorkloadScorer`, `total_score`,
  `score_breakdown` ni el escalado sanitario del Art. II (D2 y C1).
- Aislamiento horizontal de la orden móvil al `user_id` autenticado y opacidad total en el Portal de
  Sede y el canal QR (Art. V.4).

### Corregido

- `AuditEvent` reconoce `VAN_RESTOCK_ORDER` en su whitelist, alineada con el enum `audit_entity_type`
  de la migración 016; sin esta entrada la exportación del historial de auditoría fallaba al hidratar
  los eventos de las órdenes de carga.
- La suite de jornada cierra su rango de informe en el día real de la corrida, de modo que las sesiones
  creadas con la fecha actual no queden fuera al cambiar el día.

### Certificación

- Recorrido funcional del módulo **versionado como suite de la batería**: `tests/Manual/VanRestockWalkthroughRunner.php`
  ejercita el ciclo completo por HTTP real —cURL contra `php -S`, con el sobre `{success, data}` y los códigos de
  estado que ve el navegador— sobre base propia (purga y re-siembra en cada corrida): propuesta, alta, bloqueo de
  la segunda orden abierta, cancelación justificada, albarán A4, baja lógica, expedición parcial, recepción móvil,
  doble toque rechazado, comprobante, privacidad de sede e insignias de triaje, en **72 aserciones**.
- 362 suites y 13.829 aserciones ejecutadas en esa corrida · 0 fallos · base restaurada a semillas.
  *(La cifra vigente se declara una sola vez, en el README, y la audita `DocMetricsGuard`.)*
- Canal `new_engine`: 25 suites evaluadas, 24 en verde y 744 aserciones; `PoolerLatencyMeasurementTest`
  sigue declarando otro contrato por falta de `DB_SSL_ROOT_CERT` y no se omite en silencio.

## [Módulo 22] · Registro de Jornada y Horas Efectivas — 2026-10-25

**Estado:** Cerrado · bitácora inmutable, informe y rectificación auditada certificados.

### Añadido

- Tabla 31 `technician_shift_logs`, de solo inserción —sin `UPDATE` ni `DELETE`—, con los eventos `START_SHIFT`, `PAUSE_MEAL`, `RESUME_SHIFT`, `CLOSE_SHIFT`, `FORCE_CLOSE_SHIFT` y `ADJUST_SHIFT`, el actor y los instantes de cada transición; el motivo de ajuste exige 20 caracteres reales.
- `technician_shifts` amplía pausas de comida acumuladas al segundo, banderas de cierre forzado y ajuste, hora efectiva de salida y referencias al ajustador, conservando los instantes originales aunque el turno se rectifique.
- Informe por periodo con horas brutas, comida, horas efectivas netas, intervención in situ, tiempo en ruta con suelo cero, intervenciones resueltas, turnos de más de 12 h y turnos abiertos huérfanos.
- Rectificación con validación cuádruple —turno cerrado, motivo, coherencia temporal y actor autorizado—, anulación a `0s` sin borrar el registro y auditoría del cambio.
- CSV UTF-8 BOM con dos secciones en `Europe/Madrid` y sub-pestañas «⏱️ Registro de Jornada y Horas» (Coordinación) y «⏱️ Mis Horas» (técnico), aisladas por el `user_id` del token (Art. V.4).

### Certificación

- `PostgresTimeTrackingSchemaTest`, `TechnicianTimeTrackingApiTest`, `PdoWorkHoursRoleFilterTest`, `TechnicianWorkHoursServiceTest`, `TechnicianShiftTimeTrackingTest`, `TechnicianShiftLogDomainTest` y `CoordinatorWorkHoursTabTest.mjs`.

## [Módulo 21] · Ausencias Temporales y Relevo de Fin de Turno — 2026-10-25

**Estado:** Cerrado · jornada móvil y traspaso atómico con salvaguarda sanitaria.

### Añadido

- Tabla 29 `technician_absences` con vacaciones, baja, formación y fuera de turno por ventana temporal, motivo y cancelación con rastro, sin tocar `users.is_active`; la restricción `EXCLUDE USING gist` impide solapamientos por técnico.
- Tabla 30 `technician_shifts` con estados `ON_SHIFT`, `MEAL_BREAK` y `OFF_SHIFT`, turno partido mediante el índice parcial de turnos abiertos, pausa acumulada al segundo y cierre directo desde la comida.
- Relevo atómico: cerrar turno exige resolver las averías `IN_PROGRESS`; los tickets `ASSIGNED`, `PENDING_PARTS` y `PENDING_INFO` se transfieren con `FOR UPDATE` y nota interna obligatoria, con `409` ante colisión.
- Salvaguarda de frío (Art. II): si una máquina perecedera queda sin relevo manual, el protocolo escala a la guardia 24/7 o desasigna a `REGISTERED` con alarma crítica.
- El recomendador territorial marca `NO_DISPONIBLE` al titular ausente y asciende al suplente de zona; Coordinación dispone de bandeja de ausencias, estado de jornada y cierre forzado asistido, y el técnico solo ve los compañeros disponibles para el relevo.

### Certificación

- `PostgresAbsenceShiftSchemaTest`, `AbsenceShiftE2ETest`, `CoordinatorAbsenceApiTest`, `TechnicianAbsenceAtomicityTest`, `TechnicianAbsenceConcurrencyTest`, `ShiftLifecycleAtomicityTest`, `ShiftHandoverConcurrencyTest`, `ShiftHandoverSanitaryTest` y `CoordinatorAbsencesTabTest.mjs`.

## [Módulo 20] · Encuesta de Satisfacción Post-Resolución y Superficie CSAT — 2026-10-23

**Estado:** Cerrado · encuesta por episodio, cuadro de mando y lectura del técnico (incremento 20.1) certificados.

### Añadido

- Tabla 28 `incident_satisfaction_surveys` con calificación de 1–5, comentario de hasta 500 caracteres, snapshot del técnico y unicidad por episodio `(incident_id, resolution_at)`: una reapertura abre un voto nuevo sin sobrescribir el anterior.
- Encuesta y evento de auditoría en la misma transacción, sin correcciones posteriores; la tabla rechaza `UPDATE` (Art. III.1 y V.3).
- Modal de la sede con estrellas, contador `pending_total` real, `409` ante episodio ya valorado y conservación de lo escrito ante fallo de red.
- Bloque CSAT del cuadro de mando: media, respuestas, distribución 5→1, tabla por sede, tabla por técnico con nombre congelado y tendencia mensual bajo el filtro temporal.
- Incremento 20.1: superficie de solo lectura `/api/technician/my-metrics/csat`, aislada por el `user_id` del token y con DTO propio, sin filtro territorial ni exportación (Art. V.4).

### Certificación

- `PostgresSatisfactionSurveySchemaTest`, `LocationSatisfactionApiTest`, `CoordinatorCsatMetricsApiTest`, `TechnicianCsatApiTest`, `CsatPrivacyConstitutionalTest`, `SatisfactionSurveyServiceTest` y `SatisfactionSurveyModalTest.mjs`.

## [Módulo 19] · Firma Digital del Responsable de Sede y Albarán de Servicio — 2026-10-22

**Estado:** Cerrado · captura táctil, hoja inmutable y documento congelado certificados.

### Añadido

- Tabla 27 `incident_service_sheets` con dos vías excluyentes: firma manuscrita digitalizada —firmante, documento y PNG— o declaración de ausencia con motivo ≥ 10 caracteres, y `CHECK` que impide mezclar ambas.
- Validación real del PNG en servidor (firma binaria, MIME con `finfo`, cabecera `IHDR`), techo de 300 KiB y 2 Mpx; el cliente solo decide si el lienzo tiene tinta (< 150 píxeles con luminancia < 200) y dibuja a `devicePixelRatio`.
- Hoja insertada en la misma transacción que piezas, descuento de furgoneta, solicitudes, transición a `RESOLVED` y notificación; sin `UPDATE` ni `DELETE`, una corrección exige resolución nueva (Art. III.1).
- `signed_document` congela ticket, fecha, sede, máquina, técnico, diagnóstico, acción y piezas con nombre y cantidad: renombrar una sede o recatalogar una pieza no altera el papel firmado.
- Albarán A4 imprimible con salto de página controlado y bloque de firma indivisible, sin costes, notas internas, SLA ni saldos; tras una reapertura, el vigente es el del último cierre y los anteriores quedan como históricos.
- Autorización por titularidad de la hoja o del expediente, `403 SERVICE_SHEET_FORBIDDEN` para el resto y evento `VIEW_SERVICE_SHEET` en cada lectura, sin guardar nunca el trazo.

### Certificación

- `PostgresServiceSheetSchemaTest`, `ServiceSheetAtomicityTest`, `ServiceSheetDocumentTest`, `ServiceSheetPrivacyTest`, `ServiceSheetValidatorTest`, `ServiceSheetModalTest.mjs` y `TechnicianServiceSheetBlockTest.mjs`.

## [Módulo 18] · Stock de Repuestos en Furgoneta — 2026-10-21

**Estado:** Cerrado · inventario por técnico, descuento automático y alerta de reposición certificados.

### Añadido

- Tabla 26 `technician_van_stocks` con una fila por pareja `(técnico, pieza)` (`uq_van_stock_technician_part`), cantidad disponible entre −999 y 9.999 y umbral mínimo entre 0 y 9.999.
- Coordinación fija el saldo absoluto y cada ajuste deja el delta en auditoría; el técnico consulta su furgoneta en solo lectura desde la ruta.
- Descuento automático de una unidad al declarar una pieza del catálogo en la resolución —correctivo o preventivo—; la referencia fuera de catálogo no descuenta y el inventario **nunca** bloquea el cierre.
- Saldo negativo admitido y marcado como descuadre, y bandeja de Coordinación ordenada por severidad para reponer; el picking, el albarán de reposición y la valoración monetaria quedan para el Módulo 23.

### Certificación

- `PostgresVanStockSchemaTest`, `VanStockApiTest`, `VanStockServiceTest`, `VanStockReadinessCalculatorTest`, `VanStockPrivacyTest`, `TechnicianVanStockPanelTest.mjs` y `CoordinatorVanStockTabTest.mjs`.

## [Módulo 17] · Corrección de los ocho hallazgos de la verificación funcional — 2026-10-21

**Estado:** Cerrado · H-1 a H-8 corregidos y certificados por la batería global.

### Corregido

- **H-1 · Edición sustitutiva del territorio:** guardar los códigos postales de una zona
  envía la colección final; los que ya eran suyos son un no-op (reenviar la lista completa
  nunca falla) y los ausentes se liberan con `released_at`, sin `DELETE` (Art. III.1). Las
  sedes heredadas de un código liberado recalculan su zona, las forzadas a mano no se tocan,
  y el resultado publica `released_postal_codes` junto al balance de traslados.
- **H-2 · Semilla territorial:** `SeedRunner::seedAll()` siembra las tres zonas de Barcelona,
  sus diez códigos postales, el cuadrante demo y la herencia de cada sede; el resumen publica
  el territorio canónico en vigor y `TestDataCleaner` drena `operational_zones` como raíz para
  que ninguna corrida deje el catálogo vacío ni acumule zonas entre suites.
- **H-3 · Cifras documentales:** batería global **307 suites · 11.985 aserciones · 0 fallos**,
  con la frase canónica, la insignia y los cuatro informes vivos sincronizados.
- **H-4 · Canal `new_engine`:** `tests/run_new_engine.php` ejecuta la categoría con un comando
  propio, exige `PG_TEST_DSN` y un motor alcanzable (código `2` si falta cualquiera de los
  dos), descubre las suites por su marca y lista aparte las que declaran otro contrato de
  entorno en lugar de omitirlas. Documentado en el README y en `deploy/local/postgres/`.
- **H-5 · Salvaguarda de frío en la UI:** el cliente exige la justificación de descarte con
  la misma condición que `ColdChainOverridePolicy` —primer puesto en guardia—, tanto en el
  despacho unitario como en el lote del mapa; ya no pide un texto que el servidor no reclamaría.
- **H-6/H-7 · Alta de avería desde la sede:** `POST /api/incidents` certifica JSON puro
  (`application/json`) además de multipart, y el `201` publica el contexto del equipo
  (`machine_code`, `machine_model`, `machine_type`) y de la sede (`location_site_code`,
  `location_name`) que el contrato §3.2 prometía.
- **H-8 · Alcance de la sugerencia del lote:** la referencia del ranking se elige por urgencia
  real (crítica primero) y el panel rotula sobre qué ticket se calculó y a cuántos avisos de
  la sede se aplicará el técnico elegido.

## [Módulo 17] · Zonificación Operativa y Asignación Asistida — 2026-10-20

**Estado:** Cerrado · especificación funcional, contratos técnicos y batería de pruebas en verde.

### Añadido

- Zonas operativas delimitadas por códigos postales, con nombre, código único y color
  corporativo `#RRGGBB`, y liberación lógica del CP (`released_at`) para que reasignar
  un código nunca exija borrarlo (Art. III).
- Herencia automática de zona en la sede por su CP, con excepción manual atómica
  (`is_manual_override`) y auditoría del cambio; el panel de sedes señala los centros
  sin demarcación.
- Cuadrante de cobertura con zona principal y suplencias, protegido por el índice
  parcial `uq_technician_primary_zone`: un técnico no puede tener dos titularidades
  vivas y la degradación de la anterior queda registrada.
- Motor `TechnicianAssignmentSuggester` con `TechnicianWorkloadScorer`: afinidad
  territorial, presencia in situ, paradas en la misma zona, carga viva, pausas por
  falta de acceso y guardia sanitaria 24/7, con desempate determinista y fallback
  Haversine medido desde la parada actual, la última resuelta o la Base Central.
- Endpoint `GET /api/coordinator/incidents/{id}/suggested-technicians` con el ranking
  desglosado, y consola de administración territorial como cuarta subpestaña de
  Administración (zonas, CPs, cuadrante y traslados en bloque).
- Panel de asignación con despacho del técnico recomendado en dos toques
  (`[⚡ Asignar Sugerido]`), banner de emergencia sanitaria y campo obligatorio
  `override_justification` de 20 caracteres reales al descartar la recomendación de
  frío, auditado como `INCIDENT_ASSIGNMENT_SUGGESTION_OVERRIDDEN` sin tocar el
  `reassignment_reason` del flujo de reasignación (Art. II y Art. V.1).
- Filtro por zona operativa en el mapa territorial de coordinación, con los centros
  teñidos por el color de su demarcación y resuelto en cliente para no alterar el
  contrato cartográfico.
- Transferencia transaccional de códigos postales entre zonas: bloqueo `FOR UPDATE`
  de las sedes heredadas, reasignación en bloque y auditoría dual matriz-sede.

### Añadido (2026-10-20, cuadrante legible)

- El cuadrante de una zona se lee **con identidad**: nombre, código de operador,
  condición de titular o suplente, guardia 24/7, fecha de alta y estado, servido bajo
  demanda en `GET /api/coordinator/zones/{id}/technicians`. El listado del catálogo
  sigue publicando solo contadores para no pagar una consulta por zona (RNF-01), y la
  proyección deja de incluir el correo del técnico (QA 15).
- La baja del cuadrante se ejecuta desde la fila del técnico que el coordinador está
  viendo; desaparece el selector con todo el directorio. Una vinculación viva de un
  usuario desactivado se muestra con la insignia «Baja» y sigue siendo retirable
  (QA 10).
- Nueva lectura inversa «Por técnico» en la consola territorial
  (`GET /api/coordinator/technicians/quadrants`): zonas vivas por profesional con su
  rol en cada una, coloreadas con el color de la demarcación, y señalización expresa de
  quien no cubre ninguna. Se resuelve en una sola sentencia con `LEFT JOIN` y solo se
  carga cuando el coordinador la pide.

### Corregido (2026-10-20, auditoría de cierre)

- El panel de sedes incorpora la columna de zona operativa con el color de la
  demarcación y la insignia «Sin Zona Asignada» para los centros cuyo código postal
  no resuelve ninguna zona activa (RF-03.4).
- La edición de sede permite forzar una zona, dejarla sin zona o restablecer la
  herencia por código postal; la excepción viaja al servidor después del guardado y
  solo cuando el coordinador la cambia (RF-03.2, RF-03.3).
- El «cupo máximo de paradas» de RF-06.1 deja de ser una cláusula sin magnitud: se
  fija en diez paradas vivas por técnico (Resolución QA 14) y una zona con todo el
  cuadrante en el cupo se declara desatendida sin excluir a sus técnicos del ranking.
- La preservación de averías que exige RF-03.3 y los casos límite 3 y 4 queda
  certificada con aserciones sobre el traslado de CP, la desactivación de la zona y
  la baja del técnico del cuadrante.

### Corregido (2026-10-20, despliegue en Render)

- El arranque del contenedor vuelve a levantar sobre una base que ya servía antes de
  este módulo: `locations` existía sin `zone_id`, `CREATE TABLE IF NOT EXISTS` no
  añade columnas y el bloque de la clave foránea territorial abortaba el paso 3/6 con
  `SQLSTATE 42703` en los tres reintentos. El DDL base declara ahora las tres columnas
  territoriales de forma idempotente junto a la tabla, así que los índices y la clave
  foránea se materializan en el mismo paso y la migración `007` solo confirma.
- `CloudDeploySchemaParityTest` gana el caso 6: reproduce un `locations` anterior al
  módulo, reaplica el DDL base y certifica columnas, índice y clave foránea antes de
  la capa incremental, además de la convergencia final.

### Corregido (2026-10-20, modales territoriales)

- Los cinco diálogos de la consola territorial (alta, edición, liberación, códigos
  postales y cuadrante) se pintaban sobre la cáscara `vg-modal` a pelo: esa clase no
  declara relleno ni anatomía, así que el título y los controles tocaban el borde de la
  tarjeta y las acciones quedaban dentro del área que se desplaza. Ahora los cinco
  entran por el componente compartido `ModalDialog` —cabecera con hairline, cuerpo de
  20 px y pie separado—, igual que el resto de diálogos del panel, y ganan cierre con
  `Escape`, clic en el fondo, foco en el diálogo y bloqueo del desplazamiento de fondo.
- Los botones de envío viven en el pie pero siguen enviando el formulario del cuerpo
  (atributo `form`), de modo que el Enter del teclado no pierde su comportamiento.
- `OperationalZoneManagementTabTest` gana dos aserciones (1.10 y 1.11) que impiden
  reintroducir un diálogo fuera del componente compartido o sin pie de acciones.

### Corregido (2026-10-21, ranking territorial legible)

- El ranking de asignación amontonaba cinco datos en dos renglones: nombre y afinidad
  compartían línea, las insignias caían cuatro píxeles debajo y la puntuación —centrada
  a media altura por el `align-items-center` de la fila— se montaba sobre ellas. La fila
  pasa a una rejilla de dos pistas (`vg-ranking-row`): identidad arriba a la izquierda,
  puntuación anclada arriba a la derecha e insignias en su propio renglón, con 6 px entre
  fichas, 8 px bajo el nombre y 6 px entre filas.
- Las insignias dejan de ser `<span class="vg-chip">` en línea, sin borde ni envoltura:
  el bloque compartido `vg-ranking-badges` / `vg-suggestion-badges` las envuelve con
  separación y les da su filo hairline, un peldaño por debajo del chip suelto.
- La ficha del técnico recomendado recibe el mismo tratamiento, porque sufría el mismo
  amontonamiento en el mismo modal, y los bloques del panel se separan con la escala del
  sistema (`vg-suggestion-block`) en lugar de la utilidad de empaquetado `mb-3`.
- `CoordinatorDashboardAssignPanelTest` reescribe la aserción 1.7 y añade la 1.8: la
  geometría de la fila vive en la hoja de tokens (clase, no `style` en línea) y las pistas
  declaran su holgura, para que la próxima plantilla no vuelva al amontonamiento.

### Seguridad

- El portal de la sede no recibe el nombre, código ni color de su zona operativa, ni
  la afinidad de los técnicos (Art. V.4).

### Verificado

- Batería global: 307 suites y 11.985 aserciones al 100 % en verde, con la guarda de
  coherencia documental activa y la base del arnés restaurada a semillas.
- Canal `new_engine` contra PostgreSQL: esquema territorial, repositorio, servicio de
  aplicación, traslado de CPs, API de zonas, sugerencias, salvaguarda de frío y
  privacidad de sede, todos en verde.
- Superficies Vue: consola territorial (51 aserciones), panel de asignación (32),
  mapa territorial con filtro por zona (101) y las 72 suites ESM sin fallos.

## [Módulo 16] · Avería Oportunista In Situ — 2026-10-19

**Estado:** Cerrado · especificación funcional, contratos técnicos y batería de pruebas en verde.

### Añadido

- Alta y asunción de averías sobre otras máquinas de la sede en la que el técnico ya
  está interviniendo, con modalidades `NEW` y `ASSUMED` y trazabilidad en auditoría.
- Ventana de gracia de 30 minutos tras resolver o pausar una parada, con tarjeta
  temporal «Sede recién visitada (30 min)» y margen de 5 minutos si el formulario ya
  estaba abierto.
- Flujo móvil de tres toques (`TechnicianOpportunisticModal`) con catálogo de máquinas
  de la sede, aviso sanitario de otra sede y bloqueo de convivencia entre sedes.
- Reapertura in situ en garantía de 48 horas y sustitución atómica del ticket por un
  sucesor limpio en la tercera reincidencia (avería crónica).
- Insignias «Oportunista In Situ · Nueva/Asumida» en triaje y ficha de detalle, KPI de
  desplazamientos evitados y desglose por submotivo.
- Aviso oportunista en el portal de sede con el técnico anonimizado como
  «Servicio Técnico Oficial · Operador #[Código]» (Art. V.4).
- Notificaciones de la nueva modalidad en el centro de salida: alerta prioritaria a
  Coordinación al posponer una parada de frío, aviso a la sede en reaperturas y
  desasignación por correo al técnico saliente (sin Telegram, RF-TG-06).

### Verificado

- Batería global: 293 suites y 11.507 aserciones al 100 % en verde, con la guarda de
  coherencia documental activa y la base del arnés restaurada a semillas.
- Cobertura específica del módulo: esquema, políticas puras, escrituras atómicas,
  notificaciones y privacidad, servicio de aplicación, API y superficies Vue.
