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

- [ ] **T-NOTIF-01: Crear migración incremental `002_notification_center_outbox.sql` y converger `supabase_init.sql` y `supabase_seeds.sql`**
  * **Requisitos:** RF-01.1, RF-02.1, RF-02.5, RF-02.6, RF-04.4, RF-04.5, RF-06.5.1, RF-07.2, RF-07.3, Constitución Art. III.1
  * **Dependencias:** Ninguna
  * **Hecho cuando:** Existen en `database/supabase_migrations/002_notification_center_outbox.sql` y en `database/supabase_init.sql`: (1) los 4 tipos `ENUM` (`notification_channel`, `notification_priority`, `notification_status` con los 6 estados canónicos, `notification_template` con las 19 plantillas) y la ampliación de `audit_entity_type` (`NOTIFICATION_OUTBOX`, `NOTIFICATION_CHANNEL_SETTING`); (2) las columnas `locations.contact_email` (con backfill institucional determinista para sedes preexistentes), `users.is_on_call` (con `chk_users_on_call_active_tech`) e `incidents` (`reporter_email`, `cycle_started_at`, `cold_alert_sent_at`, `sla_2h_alert_sent_at`, `sla_3h_alert_sent_at`); (3) las tablas `notification_outbox` y `notification_channel_settings` con sus índices (`idx_outbox_dispatch_queue`, `uq_notification_outbox_dedup_key`) y disparadores constitucionales `trg_no_delete_*` y `trg_touch_*`; y `PostgresMigrationRunner` aplica la migración sin errores.

- [ ] **T-NOTIF-02: Implementar Value Objects (`NotificationChannel`, `NotificationPriority`, `NotificationStatus`, `NotificationTemplate`) y Entidades (`NotificationOutboxItem`, `NotificationChannelSettings`)**
  * **Requisitos:** RF-01.1, RF-03, RF-04, RF-05, RF-06.1, RF-06.2, RF-06.3
  * **Dependencias:** T-NOTIF-01
  * **Hecho cuando:** Existen en `src/Core/Domain/ValueObject/` los 4 enums nativos PHP 8.2+ con etiquetas en español y matriz de transiciones válidas en `NotificationStatus`, y en `src/Core/Domain/Model/` las entidades `NotificationOutboxItem.php` y `NotificationChannelSettings.php` (además de la extensión de `Location`, `User` e `Incident` con `contactEmail`, `isOnCall`, `reporterEmail` y marcas de ciclo sanitario), verificadas con `php tests/unit/NotificationStatusStateMachineTest.php` al 100% en verde.

- [ ] **T-NOTIF-03: Implementar políticas puras de dominio (`NotificationRetryPolicy`, `WebhookUrlSecurityValidator`, `NotificationPayloadSanitizer`, `NotificationObsolescencePolicy`) y sus tests unitarios**
  * **Requisitos:** RF-05.1, RF-05.3, RF-05.6, RF-06.1, RF-06.2, RF-06.5.1, RF-07.1, RNF-04, Constitución Art. V.4
  * **Dependencias:** T-NOTIF-02
  * **Hecho cuando:** Existen en `src/Core/Service/` las 4 clases puras de dominio y sus suites unitarias (`NotificationRetryPolicyTest.php`, `WebhookUrlSecurityValidatorTest.php`, `NotificationPayloadSanitizerTest.php` y `NotificationObsolescencePolicyTest.php`) pasan al 100% en verde certificando: (1) curva de backoff $60\text{ s}, 300\text{ s}, 900\text{ s}, 3600\text{ s}$ y paso directo a `FAILED_PERMANENT` en rechazo permanente o 5.º intento; (2) bloqueo anti-SSRF de esquemas `http://`, `localhost` e IPs privadas; (3) exclusión total en origen de diagnósticos, costes, notas internas y nombres de técnicos para `LOCATION` y `CITIZEN_QR`, y exclusión de motivos de pausa y textos de terceros para `CITIZEN_QR`; y (4) todas las reglas de obsolescencia de RF-07.1.

---

## Fase 2: Repositorios PDO, Transportes Nativos y Servicios de Aplicación

- [ ] **T-NOTIF-04: Implementar `PdoNotificationOutboxRepository` y `PdoNotificationSettingsRepository` (incluyendo extensiones en `PdoLocationRepository`, `PdoUserRepository` y `PdoIncidentRepository`)**
  * **Requisitos:** RF-01.1, RF-01.3, RF-01.4, RF-01.5, RF-02.1, RF-02.5, RF-02.6, RF-05.4, RF-06.3, RF-06.4, RF-07.2, RNF-06, Constitución Art. II y Art. III.1
  * **Dependencias:** T-NOTIF-01, T-NOTIF-02
  * **Hecho cuando:** Existen las interfaces en `src/Core/Domain/Repository/` y sus implementaciones PDO en `src/Infrastructure/Repository/` soportando: (1) inserción transaccional idempotente (`ON CONFLICT (dedup_key) DO NOTHING`) y actualización de fila `PENDING` existente en ráfagas de `PENDING_INFO_SITE_REPLY`; (2) rescate atómico `rescueStuckProcessingItems()` para filas en `PROCESSING` $> 5\text{ minutos}$; (3) reclamo de lote `claimReadyBatch()` con `FOR UPDATE SKIP LOCKED` ordenado por `priority = 'CRITICAL_SANITARY'` primero; (4) consultas paginadas/filtradas por los 6 estados y contadores KPI; y (5) limpieza automática de `is_on_call = FALSE` y cancelación por obsolescencia al desactivar un técnico o el canal Webhook.

- [ ] **T-NOTIF-05: Implementar transportes nativos sin dependencias (`NativeSmtpEmailTransport`, `NativeHttpsWebhookTransport`) y renderizador `NotificationMessageRenderer`**
  * **Requisitos:** RF-01.5, RF-03.2, RF-05.1 a RF-05.9, RF-06.1, RF-06.2, RF-06.5.2, RNF-03, Constitución Art. IV.3 y Art. V.4
  * **Dependencias:** T-NOTIF-03
  * **Hecho cuando:** Existen en `src/Infrastructure/Notification/` el contrato `NotificationTransportInterface`, el objeto de resultado `TransportDeliveryResult` (distinguiendo éxito, fallo transitorio y fallo permanente), los transportes nativos en PHP puro con timeout estricto de **5 segundos** y adaptador determinista inyectable para el arnés de pruebas hermético sin red, y `NotificationMessageRenderer` generando los asuntos y cuerpos en castellano para las 19 plantillas (incluyendo el aviso destacado `"⚠️ ALERTA SIN TÉCNICO DE GUARDIA DESIGNADO"` de RF-03.2).

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
