# PLAN DE TAREAS DE IMPLEMENTACIÓN · MÓDULO 14: CENTRO DE NOTIFICACIONES MULTICANAL Y PATRÓN TRANSACCIONAL OUTBOX (TASKS.MD)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** `14-notification-center-outbox`  
**Documento:** `specs/14-notification-center-outbox/tasks.md`  
**Referencia Funcional:** [`specs/14-notification-center-outbox/spec.md`](spec.md) y [`specs/functional/notification_center_outbox_spec.md`](../functional/notification_center_outbox_spec.md)  
**Plan Técnico:** [`specs/14-notification-center-outbox/plan.md`](plan.md)  
**Contratos Técnicos:** [`specs/technical/notification_center_outbox_contracts.md`](../technical/notification_center_outbox_contracts.md)  
**Normativa Suprema:** [`constitution.md`](../../constitution.md) (Artículos I al VII)  
**Directrices Operativas:** [`AGENTS.md`](../../AGENTS.md) (SDD, Dogma Vanilla, Dualismo Lingüístico)  
**Sistema de Diseño Visual:** [`docs/design.md`](../../docs/design.md) (Tokens Docker: Azul `#2560ff`, ámbar `#fef9c3`/`#854d0e`, rojo crítico `#dc2626`, canvas `#f9fafb`, radio 4px/8px)  
**Estimación por Tarea:** 20–30 minutos  
**Regla de Ejecución:** Estricto orden de dependencias; no iniciar una tarea sin completar y verificar sus predecesoras.  

---

## Fase 1: Esquema de Base de Datos PostgreSQL (Supabase) y Modelos de Dominio

- [x] **T-NOTIF-01: Crear migración incremental `002_notification_center_outbox.sql` y converger `supabase_init.sql` y `supabase_seeds.sql`**
  * **Requisitos:** RF-01.1, RF-02.1, RF-02.5, RF-02.6, RF-04.4, RF-04.5, RF-06.5.1, RF-07.2, RF-07.3, Constitución Art. III.1
  * **Dependencias:** Ninguna
  * **Hecho cuando:** Existen en `database/supabase_migrations/002_notification_center_outbox.sql` y en `database/supabase_init.sql`: (1) los 4 tipos `ENUM` (`notification_channel`, `notification_priority`, `notification_status` con los 6 estados canónicos, `notification_template` con las 19 plantillas) y la ampliación de `audit_entity_type` (`NOTIFICATION_OUTBOX`, `NOTIFICATION_CHANNEL_SETTING`); (2) las columnas `locations.contact_email` (con backfill institucional determinista para sedes preexistentes), `users.is_on_call` (con `chk_users_on_call_active_tech`) e `incidents` (`reporter_email`, `cycle_started_at`, `cold_alert_sent_at`, `sla_2h_alert_sent_at`, `sla_3h_alert_sent_at`); (3) las tablas `notification_outbox` y `notification_channel_settings` con sus índices (`idx_outbox_dispatch_queue`, `uq_notification_outbox_dedup_key`) y disparadores constitucionales `trg_no_delete_*` y `trg_touch_*`; y `PostgresMigrationRunner` aplica la migración sin errores.
  * **Acta de Ejecución (2026-10-17):**
    - Creada la migración incremental `database/supabase_migrations/002_notification_center_outbox.sql` implementando los 4 tipos `ENUM` (`notification_channel`, `notification_priority`, `notification_status`, `notification_template`), ampliación de `audit_entity_type` con `NOTIFICATION_OUTBOX` y `NOTIFICATION_CHANNEL_SETTING`, columnas nuevas en `locations`, `users` e `incidents`, y las 2 nuevas tablas del contrato: `notification_outbox` (tabla 21) y `notification_channel_settings` (tabla 22) con sus índices y disparadores constitucionales `BEFORE DELETE` (`prevent_hard_delete`).
    - Convergido `database/supabase_init.sql` y sembrado de canal por defecto y correo de contacto en `database/supabase_seeds.sql`.
    - Ampliado el dominio `AuditEvent::ENTITY_TYPES` y el catálogo visual `AuditActionLabels.js` con las nuevas entidades.
    - Actualizado `TestDataCleaner` y sus pruebas de aislamiento para gestionar la tabla hija operacional `notification_outbox`.
    - Verificado con `PostgresMigrationRunner`: 002 aplicada con éxito en ambos bancos (`vendguard_battery` y `vendguard_pg_test`), convergencia total reportada.
    - Suites de certificación del motor (`PostgresSchemaTest`, `PostgresConstitutionalTest`, `SchemaConvergenceParityTest`) al 100% PASS (22 tablas del contrato y 29 tipos ENUM validados).
    - Batería hermética global (`php tests/run_all.php`): **261 suites · 9.941 aserciones · 0 fallos (100% PASS)** con cifras documentales **COHERENTES**.

- [x] **T-NOTIF-02: Implementar Value Objects (`NotificationChannel`, `NotificationPriority`, `NotificationStatus`, `NotificationTemplate`) y Entidades (`NotificationOutboxItem`, `NotificationChannelSettings`)**
  * **Requisitos:** RF-01.1, RF-03, RF-04, RF-05, RF-06.1, RF-06.2, RF-06.3
  * **Dependencias:** T-NOTIF-01
  * **Hecho cuando:** Existen en `src/Core/Domain/ValueObject/` los 4 enums nativos PHP 8.2+ con etiquetas en español y matriz de transiciones válidas en `NotificationStatus`, y en `src/Core/Domain/Model/` las entidades `NotificationOutboxItem.php` y `NotificationChannelSettings.php` (además de la extensión de `Location`, `User` e `Incident` con `contactEmail`, `isOnCall`, `reporterEmail` y marcas de ciclo sanitario), verificadas con `php tests/unit/NotificationStatusStateMachineTest.php` al 100% en verde.
  * **Acta de Ejecución (2026-10-17):**
    - Creados los 4 Value Objects en `src/Core/Domain/ValueObject/`: `NotificationChannel` (EMAIL, WEBHOOK), `NotificationPriority` (CRITICAL_SANITARY, STANDARD), `NotificationStatus` (los 6 estados canónicos, propiedades semánticas y matriz de transiciones exhaustiva) y `NotificationTemplate` (las 19 plantillas con etiquetas en español y prioridades por defecto).
    - Creadas las entidades de dominio en `src/Core/Domain/Model/`: `NotificationOutboxItem.php` (ciclo de vida, estados, reintentos progresivos, rescate, agrupación de ráfagas) y `NotificationChannelSettings.php` (configuración del Webhook y registro de pruebas).
    - Ampliadas las entidades maestras `Location` (`contactEmail`), `User` (`isOnCall`) e `Incident` (`reporterEmail`, `cycleStartedAt`, `coldAlertSentAt`, `sla2hAlertSentAt`, `sla3hAlertSentAt`).
    - Creada suite unitaria `tests/unit/NotificationStatusStateMachineTest.php` certificando 59 aserciones al 100% PASS.
    - Sincronizadas las cifras documentales con `DocMetricsGuard` y ejecutada la batería completa (`php tests/run_all.php`): **262 suites · 10.000 aserciones · 0 fallos (100% PASS)** con cifras documentales **COHERENTES**.

- [x] **T-NOTIF-03: Implementar políticas puras de dominio (`NotificationRetryPolicy`, `WebhookUrlSecurityValidator`, `NotificationPayloadSanitizer`, `NotificationObsolescencePolicy`) y sus tests unitarios**
  * **Requisitos:** RF-05.1, RF-05.3, RF-05.6, RF-06.1, RF-06.2, RF-06.5.1, RF-07.1, RNF-04, Constitución Art. V.4
  * **Dependencias:** T-NOTIF-02
  * **Hecho cuando:** Existen en `src/Core/Service/` las 4 clases puras de dominio y sus suites unitarias (`NotificationRetryPolicyTest.php`, `WebhookUrlSecurityValidatorTest.php`, `NotificationPayloadSanitizerTest.php` y `NotificationObsolescencePolicyTest.php`) pasan al 100% en verde certificando: (1) curva de backoff $60\text{ s}, 300\text{ s}, 900\text{ s}, 3600\text{ s}$ y paso directo a `FAILED_PERMANENT` en rechazo permanente o 5.º intento; (2) bloqueo anti-SSRF de esquemas `http://`, `localhost` e IPs privadas; (3) exclusión total en origen de diagnósticos, costes, notas internas y nombres de técnicos para `LOCATION` y `CITIZEN_QR`, y exclusión de motivos de pausa y textos de terceros para `CITIZEN_QR`; y (4) todas las reglas de obsolescencia de RF-07.1.
  * **Resultado de Ejecución:**
    - Creadas las 4 clases puras de dominio en `src/Core/Service/`:
      - `NotificationRetryPolicy.php`: Cálculo de backoff progresivo (60s, 300s, 900s, 3600s) y transición a `FAILED_PERMANENT` al 5.º fallo o por rechazo permanente.
      - `WebhookUrlSecurityValidator.php`: Validación estricta HTTPS y bloqueo anti-SSRF exhaustivo (IPv4 privadas, localhost, loopback, metadatos 169.254, CGNAT, IPv6 fc00/fe80).
      - `NotificationPayloadSanitizer.php`: Blindaje en origen de confidencialidad Art. V.4 para roles `LOCATION` y `CITIZEN_QR`, con sustitución por identificador técnico neutro y bloqueo de plantillas ilegales.
      - `NotificationObsolescencePolicy.php`: Evaluación pura de obsolescencia cubriendo las 6 causísticas de RF-07.1 antes del despacho.
    - Creadas las 4 suites unitarias dedicadas:
      - `tests/unit/NotificationRetryPolicyTest.php` (20 aserciones, 100% PASS).
      - `tests/unit/WebhookUrlSecurityValidatorTest.php` (29 aserciones, 100% PASS).
      - `tests/unit/NotificationPayloadSanitizerTest.php` (25 aserciones, 100% PASS).
      - `tests/unit/NotificationObsolescencePolicyTest.php` (21 aserciones, 100% PASS).
    - Sincronizadas las métricas documentales con `DocMetricsGuard` y ejecutada la batería global completa (`php tests/run_all.php`): **266 suites · 10.095 aserciones · 0 fallos (100% PASS)** con cifras documentales **COHERENTES**.

---

## Fase 2: Repositorios PDO, Transportes Nativos y Servicios de Aplicación

- [x] **T-NOTIF-04: Implementar `PdoNotificationOutboxRepository` y `PdoNotificationSettingsRepository` (incluyendo extensiones en `PdoLocationRepository`, `PdoUserRepository` y `PdoIncidentRepository`)**
  * **Requisitos:** RF-01.1, RF-01.3, RF-01.4, RF-01.5, RF-02.1, RF-02.5, RF-02.6, RF-05.4, RF-06.3, RF-06.4, RF-07.2, RNF-06, Constitución Art. II y Art. III.1
  * **Dependencias:** T-NOTIF-01, T-NOTIF-02
  * **Hecho cuando:** Existen las interfaces en `src/Core/Domain/Repository/` y sus implementaciones PDO en `src/Infrastructure/Repository/` soportando: (1) inserción transaccional idempotente (`ON CONFLICT (dedup_key) DO NOTHING`) y actualización de fila `PENDING` existente en ráfagas de `PENDING_INFO_SITE_REPLY`; (2) rescate atómico `rescueStuckProcessingItems()` para filas en `PROCESSING` $> 5\text{ minutos}$; (3) reclamo de lote `claimReadyBatch()` con `FOR UPDATE SKIP LOCKED` ordenado por `priority = 'CRITICAL_SANITARY'` primero; (4) consultas paginadas/filtradas por los 6 estados y contadores KPI; y (5) limpieza automática de `is_on_call = FALSE` y cancelación por obsolescencia al desactivar un técnico o el canal Webhook.
  * **Acta de Ejecución (2026-10-17):**
    - Creados los puertos en `src/Core/Domain/Repository/`: `NotificationOutboxRepositoryInterface` (encolado idempotente, reclamo, rescate, listado, KPI y cancelación lógica) y `NotificationSettingsRepositoryInterface` (fila única vigente y guardado). Las extensiones se declaran como **contratos de capacidad** —patrón ya usado por `LocationBatchRepositoryInterface` y `UserLockoutRepositoryInterface`— para no engordar los contratos maestros: `LocationContactEmailRepositoryInterface`, `OnCallTechnicianRepositoryInterface` e `IncidentNotificationCycleRepositoryInterface`.
    - Implementado `src/Infrastructure/Repository/PdoNotificationOutboxRepository.php`: encolado idempotente con `ON CONFLICT (dedup_key) DO NOTHING` para claves de deduplicación y agrupación de ráfagas de `PENDING_INFO_SITE_REPLY` serializada con `pg_advisory_xact_lock`; `rescueStuckProcessingItems()` atómico (> 5 min, intento contabilizado y quinto intento directo a `FAILED_PERMANENT`); `claimReadyBatch()` con `FOR UPDATE SKIP LOCKED`, prioridad `CRITICAL_SANITARY` primero y reordenación determinista en PHP (el orden de `RETURNING` no está garantizado por el motor); `findForCoordinator()`/`countForCoordinator()` con filtros por los 6 estados, canal, prioridad y ticket, más paginación; `countByStatus()` con los contadores KPI (`in_queue`, `sent_last_24h`); y cancelación por obsolescencia por incidencia (con plantillas preservables), por destinatario interno y del canal Webhook, siempre lógica (Art. III.1).
    - Implementado `src/Infrastructure/Repository/PdoNotificationSettingsRepository.php`: `findCurrent()` crea la fila institucional si faltara y `save()` persiste la configuración; al desactivar el Webhook cancela en la MISMA transacción los envíos Webhook pendientes (RF-07.1.f).
    - Extensiones PDO: `PdoLocationRepository` expone `updateContactEmail()` y publica `contact_email` en sus lecturas y altas/ediciones; `PdoUserRepository` publica `is_on_call`, añade `setOnCall()`, `findOnCallTechnicians()` y `countOnCallTechnicians()`, y en la baja lógica retira la marca y cancela los avisos pendientes del destinatario; `PdoIncidentRepository` persiste `reporter_email` y `cycle_started_at` en el alta, reinicia las marcas de alerta en la reapertura en garantía (RF-04.5) y añade `markColdAlertSent()`/`markSlaAlertSent()`.
    - **Defecto detectado y reparado durante la tarea:** el cerco `chk_webhook_url_https` de la migración 002 quedó con la barra invertida duplicada (`\\\\.`), de modo que la expresión regular exigía una barra literal y **ninguna** URL `https://` podía guardarse (RF-06.5 bloqueado de facto). Como una migración aplicada no se reescribe (huella del libro `schema_migrations`), se añadió la migración de reparación `database/supabase_migrations/003_notification_webhook_url_check_repair.sql` y se corrigió el mismo cerco en `database/supabase_init.sql` para instalaciones nuevas; la batería del motor único (`SchemaConvergenceParityTest`, `PostgresSchemaTest`, `PostgresConstitutionalTest`) vuelve a estar al 100%.
    - Creadas 3 suites de integración: `tests/integration/PdoNotificationOutboxRepositoryTest.php` (41 aserciones), `tests/integration/PdoNotificationSettingsRepositoryTest.php` (15 aserciones) y `tests/integration/NotificationRecipientExtensionsTest.php` (31 aserciones), todas al 100% PASS, más la actualización de `tests/integration/SchemaConvergenceParityTest.php` al tercer fichero de migración.
    - Suites de categoría `new_engine` reejecutadas con `PG_TEST_DSN`: `SchemaConvergenceParityTest` (18/18), `PostgresSchemaTest` (71/71) y `PostgresConstitutionalTest` (59/59) en verde; `CloudDeploySchemaParityTest` (45/45) y `HarnessResidueGuardTest` (25/25) en verde.
    - Sincronizadas las métricas documentales con `DocMetricsGuard` y ejecutada la batería global completa (`php tests/run_all.php`): **269 suites · 10.182 aserciones · 0 fallos (100% PASS)** con cifras documentales **COHERENTES**.

- [x] **T-NOTIF-05: Implementar transportes nativos sin dependencias (`NativeSmtpEmailTransport`, `NativeHttpsWebhookTransport`) y renderizador `NotificationMessageRenderer`**
  * **Requisitos:** RF-01.5, RF-03.2, RF-05.1 a RF-05.9, RF-06.1, RF-06.2, RF-06.5.2, RNF-03, Constitución Art. IV.3 y Art. V.4
  * **Dependencias:** T-NOTIF-03
  * **Hecho cuando:** Existen en `src/Infrastructure/Notification/` el contrato `NotificationTransportInterface`, el objeto de resultado `TransportDeliveryResult` (distinguiendo éxito, fallo transitorio y fallo permanente), los transportes nativos en PHP puro con timeout estricto de **5 segundos** y adaptador determinista inyectable para el arnés de pruebas hermético sin red, y `NotificationMessageRenderer` generando los asuntos y cuerpos en castellano para las 19 plantillas (incluyendo el aviso destacado `"⚠️ ALERTA SIN TÉCNICO DE GUARDIA DESIGNADO"` de RF-03.2).
  * **Acta de Ejecución (2026-10-17):**
    - Creado `src/Infrastructure/Notification/Transport/`: `NotificationTransportInterface` (contrato con `TIMEOUT_SECONDS = 5` y canal), `TransportDeliveryResult` (tres outcomes canónicos, reintentabilidad y diagnóstico acotado a 500 caracteres para `last_error`), `NativeSmtpEmailTransport`, `NativeHttpsWebhookTransport` e `InMemoryNotificationTransport` (adaptador determinista del arnés: sin sockets, sin DNS y sin reloj; secuencias programables por destinatario y registro de entregas).
    - **SMTP nativo:** `stream_socket_client` con TLS implícito (`ssl`), STARTTLS (`tls`) o texto plano restringido a loopback; `AUTH LOGIN` sólo si hay usuario; mensaje `multipart/alternative` con asunto RFC 2047, cuerpos en texto y HTML, dot-stuffing y `Message-ID`. Clasificación `2xx` éxito · `4xx` transitorio · `5xx` permanente · socket/TLS/timeout transitorio. Rechazo previo de destinatarios inválidos y de inyección de cabeceras, sin abrir conexión.
    - **Webhook nativo:** `stream_context_create` + `file_get_contents` sobre HTTPS con `verify_peer`, `follow_location = 0` (una redirección saltaría la validación anti-SSRF) y revalidación de la URL en cada entrega mediante `WebhookUrlSecurityValidator`. JSON canónico `source/event/priority/subject/text/html`; `2xx` éxito, `408/425/429/5xx` transitorio, `3xx` y demás `4xx` permanente. El diagnóstico depura la URL de destino para no filtrar tokens.
    - **Timeout estricto:** ambos transportes rechazan por constructor cualquier valor superior a 5 s (`RNF-03`); el adaptador hermético no tiene timeout porque no abre red.
    - **Renderizador:** `src/Infrastructure/Notification/Template/NotificationMessageRenderer.php` compone las 19 plantillas desde un único borrador estructurado (texto y HTML no pueden divergir). Trabaja con lista blanca de claves neutras, de modo que aunque el `payload_json` llegara sin sanitizar jamás lee diagnósticos, acciones, costes, notas internas ni identidades de técnicos (`Art. V.4`); el Ciudadano QR sólo recibe ticket, máquina, categoría y fecha, y enlaza a `/?qr={machine_code}`. La Sede recibe `/?site={site_code}&ticket={ticket_code}` y el alias `technical_service_label` que emite `NotificationPayloadSanitizer`.
    - **RF-03.2:** cuando la alerta de frío llega sin técnico asignado y con `on_call_technician_count = 0` (o `no_on_call_technician = true`), el asunto y el encabezado incluyen el literal completo `"⚠️ ALERTA SIN TÉCNICO DE GUARDIA DESIGNADO: Requiere asignación manual inmediata"`; con técnico asignado o guardia activa no se emite.
    - **HTML:** maquetación responsiva de 640 px con los tokens del sistema (`#2560ff`, `#f9fafb`, `#2c333f`, hairline `#c8cfda`, radios de 4/8 px) y alertas en ámbar `#f8b60f`/rojo `#dc2626`; todos los datos del expediente se escapan con `htmlspecialchars`.
    - Creadas 2 suites unitarias: `tests/unit/NotificationTransportTest.php` (**98 aserciones**, incluida una entrega SMTP real contra un servidor falso efímero en loopback) y `tests/unit/NotificationMessageRendererTest.php` (**108 aserciones**, 19 plantillas, blindaje Art. V.4, enlaces y aviso RF-03.2), ambas al 100% PASS.
    - Batería global reejecutada (`php tests/run_all.php`): **271 suites · 10.388 aserciones · 0 fallos (100% PASS)** con cifras documentales **COHERENTES**.
    - **Limitación declarada:** no se ha probado una entrega real contra un proveedor SMTP/Webhook externo (no hay credenciales ni destino autorizado en el arnés). Se certifican el protocolo SMTP completo contra loopback, la clasificación de respuestas, el preflight anti-SSRF y la construcción del JSON; el camino HTTPS externo queda cubierto por T-NOTIF-07 con el botón «Probar conexión» y la configuración real.

- [ ] **T-NOTIF-06: Implementar `NotificationOutboxEnqueuerService` y conectar los disparadores transaccionales del ciclo de vida**
  * **Requisitos:** RF-01.1, RF-01.2, RF-02.3, RF-02.4, RF-03.1, RF-03.2, RF-03.3, RF-03.4, RF-04.5, RF-05.1 a RF-05.9, RNF-01, Constitución Art. II, Art. III.3 y Art. V.4
  * **Dependencias:** T-NOTIF-03, T-NOTIF-04, T-NOTIF-05
  * **Hecho cuando:** `NotificationOutboxEnqueuerService` encola de forma atómica dentro de la transacción de negocio los 19 eventos del catálogo: apertura (Sede con doble destinatario si `reporter_email` difiere de `contact_email` + Ciudadano QR si dejó correo), Alerta Inmediata de Frío (en creación, reapertura o reclasificación a `CRITICAL` en máquina bajo vigilancia sanitaria), asignación/reasignación (técnico entrante y saliente), pausa `PENDING_INFO` (solo a Sede), respuesta de Sede en `PENDING_INFO` (reutilizando fila `PENDING` ante ráfagas), resolución con garantía de 48h, reapertura en garantía, bloqueo por 3.ª reincidencia (`CHRONIC_BREAKDOWN_ALERT`), cancelación (incluyendo advertencia de máquina bloqueada tras 72h hábiles), acuse puntual al 2.º ciudadano QR concurrente, Cuarentena Sanitaria 4h y Escalado de Contención del Barrido Sanitario (`ESCALATED`).

- [ ] **T-NOTIF-07: Implementar `NotificationDispatcherService` y `CoordinatorNotificationService`**
  * **Requisitos:** RF-01.3, RF-01.4, RF-01.5, RF-04.1 a RF-04.5, RF-06.1 a RF-06.5, RF-07.1, RF-07.3, RNF-02, RNF-03, RNF-06
  * **Dependencias:** T-NOTIF-04, T-NOTIF-05, T-NOTIF-06
  * **Hecho cuando:** (1) `NotificationDispatcherService` ejecuta las 4 fases atómicas (rescate $> 5\text{ min}$, evaluación 24/7 de umbrales SLA 2h sin asignar y 3h sin iniciar con marcas `sla_2h_alert_sent_at` / `sla_3h_alert_sent_at`, reclamo por prioridad `CRITICAL_SANITARY` y despacho con verificación previa de obsolescencia y backoff progresivo); y (2) `CoordinatorNotificationService` gestiona el listado y KPIs de supervisión, el reintento manual individual/masivo refrescando el `contact_email` o `webhook_url` vigente, la cancelación manual, la actualización del Webhook y la prueba directa `testWebhook()` en $\le 5\text{ s}$ registrando todo en `audit_log`.

---

## Fase 3: Controladores REST, Worker CLI y Rutas en AppRouter

- [ ] **T-NOTIF-08: Implementar `CoordinatorNotificationController` y extender `CoordinatorAdminController`, `LocationPortalController`, `QrScanController` y `CronController`**
  * **Requisitos:** RF-01.2, RF-02.1, RF-02.2, RF-02.5, RF-02.6, RF-06.3, RF-06.4, RF-06.5, RF-04
  * **Dependencias:** T-NOTIF-06, T-NOTIF-07
  * **Hecho cuando:** (1) `CoordinatorNotificationController` expone `listOutbox`, `retryItem`, `retryAllFailed`, `cancelItem`, `getSettings`, `updateSettings` y `testWebhook`; (2) `CoordinatorAdminController` valida y persiste `contact_email` en sedes y expone `updateUserOnCall` (`PATCH /api/coordinator/users/{id}/on-call`); (3) `LocationPortalController` y `QrScanController` aceptan y validan `reporter_email` opcional; y (4) `CronController` incorpora `dispatchNotifications` (`POST /api/cron/notifications-dispatch`) protegido por `CronAuthMiddleware`.

- [ ] **T-NOTIF-09: Crear el Worker CLI `bin/send_notifications.php` y registrar todas las rutas en `AppRouter.php`**
  * **Requisitos:** RF-01.2, RF-01.3, RF-04, RF-06.3, RF-06.4, RF-06.5, RNF-02
  * **Dependencias:** T-NOTIF-08
  * **Hecho cuando:** (1) `bin/send_notifications.php` ejecuta `NotificationDispatcherService::runPass()` desde línea de comandos aceptando `--batch-size` opcional e imprimiendo el resumen JSON con código de salida `0`; y (2) `AppRouter.php` registra las 9 rutas nuevas bajo `$coordinatorAuth` y `$cronAuth` sin romper ninguna ruta preexistente.

---

## Fase 4: Pruebas de Integración Backend y Blindaje Constitucional

- [ ] **T-NOTIF-10: Implementar `NotificationOutboxEnqueueIntegrationTest.php` y `NotificationDispatcherAndSlaSweepTest.php`**
  * **Requisitos:** RF-01, RF-02, RF-03, RF-04, RF-05, RF-06.1, RF-06.2, RF-07.1, RNF-01, RNF-06
  * **Dependencias:** T-NOTIF-09
  * **Hecho cuando:** Ambas suites de integración contra PostgreSQL local hermético pasan al 100% en verde verificando: encolado transaccional en los 19 eventos, alerta sin técnico de guardia, agrupación de ráfagas en `PENDING_INFO`, orden estricto de despacho (`CRITICAL_SANITARY` antes que `STANDARD`), rescate de filas `PROCESSING` $> 5\text{ min}$, evaluación 24/7 de umbrales 2h y 3h sin spam y su reinicio tras reapertura en garantía, backoff progresivo de 5 intentos y cancelación automática por obsolescencia.

- [ ] **T-NOTIF-11: Implementar `CoordinatorNotificationApiTest.php` y `NotificationConstitutionalTest.php`**
  * **Requisitos:** RF-02.1, RF-02.3, RF-02.5, RF-02.6, RF-05.6, RF-06.3, RF-06.4, RF-06.5, RF-07.2, RF-07.3, Constitución Art. II, Art. III.1, Art. III.3 y Art. V.4
  * **Dependencias:** T-NOTIF-10
  * **Hecho cuando:** Ambas suites pasan al 100% en verde certificando: endpoints de supervisión Outbox con filtros de los 6 estados, reintento manual tomando el `contact_email` o `webhook_url` recién corregido, cancelación manual, bloqueo anti-SSRF en configuración y test de Webhook, toggle de `is_on_call` y su limpieza al desactivar técnico, rechazo con `SQLSTATE 45000` (`ART_III_1_VIOLATION`) ante cualquier `DELETE FROM` en `notification_outbox` y `notification_channel_settings`, doble destinatario en sede y cero fugas de datos privados en payloads de Sede y Ciudadano QR.

---

## Fase 5: Componentes Frontend Vue 3 ES Modules y Pruebas de Interfaz

- [ ] **T-NOTIF-12: Extender cliente HTTP `public/assets/js/api.js` e implementar componente `CoordinatorNotificationCenterModal.js`**
  * **Requisitos:** RF-06.3, RF-06.4, RF-06.5, RNF-05, docs/design.md
  * **Dependencias:** T-NOTIF-09
  * **Hecho cuando:** (1) `public/assets/js/api.js` incorpora los métodos para consultar/filtrar la bandeja Outbox, reintentar individual o masivamente, cancelar envíos, obtener/guardar la configuración del Webhook, probar la conexión y alternar `is_on_call`; y (2) `public/assets/js/components/CoordinatorNotificationCenterModal.js` renderiza las pestañas de *Bandeja de Salida* (con tarjetas KPI, filtros de los 6 estados canónicos, insignias semánticas y acciones de reintento/cancelación) y *Canal Corporativo de Emergencias* (con validación `https://` y botón *"🔌 Probar conexión"*).

- [ ] **T-NOTIF-13: Integrar gestión de guardia, correo de sede, correo opcional de reporte y alerta Outbox en `CoordinatorDashboardView.js`, `LocationPortalView.js` y `QrReportView.js`**
  * **Requisitos:** RF-02.1, RF-02.2, RF-02.5, RF-06.2, RF-06.3, RNF-05
  * **Dependencias:** T-NOTIF-12
  * **Hecho cuando:** (1) `CoordinatorDashboardView.js` muestra el botón **"🔔 Notificaciones"** con insignia de alerta roja si `failed_permanent_count > 0`, el selector **"🛡️ En guardia"** en la tabla de Técnicos y el campo obligatorio **"Correo oficial de la sede"** (`contact_email`) en el formulario de Sedes; y (2) `LocationPortalView.js` y `QrReportView.js` incluyen el campo opcional **"Correo electrónico para notificaciones (opcional)"** (`reporter_email`) con validación reactiva.

- [ ] **T-NOTIF-14: Implementar suite de pruebas frontend `tests/unit/CoordinatorNotificationCenterModalTest.mjs` y ejecutar batería global de certificación**
  * **Requisitos:** RF-01 a RF-07, RNF-01 a RNF-06
  * **Dependencias:** T-NOTIF-11, T-NOTIF-13
  * **Hecho cuando:** La suite `node tests/unit/CoordinatorNotificationCenterModalTest.mjs` y la batería global completa (`php tests/run_all.php`) pasan al 100% en verde con cero residuos en el arnés (`HarnessResidueGuardTest`) y con las guardas de documentación y esquema actualizadas.
