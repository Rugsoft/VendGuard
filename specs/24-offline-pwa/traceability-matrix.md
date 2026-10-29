# MATRIZ DE TRAZABILIDAD · MÓDULO 24

**Especificación:** [`spec.md`](spec.md) (v2.1) · **Contratos:** [`../technical/offline_pwa_contracts.md`](../technical/offline_pwa_contracts.md) · **Plan:** [`plan.md`](plan.md) · **Tareas:** [`tasks.md`](tasks.md) · **Acta manual RNF-01/RNF-04:** [`../../docs/verificacion_modulo_24_rnf01_rnf04.md`](../../docs/verificacion_modulo_24_rnf01_rnf04.md)

**Esquema de referencia:** `RF-01` instalabilidad y carga sin red · `RF-02` copia local y vigencia · `RF-03` encolado, precinto y cuota · `RF-04` sincronización, idempotencia y Δt · `RF-05` conflictos y cuarentena · `RF-06` sesión, turno y baja de cuenta.

**Corrida de certificación:** 29 de octubre de 2026 (PostgreSQL local `vendguard_battery`; canal `new_engine` ejecutado con `PG_TEST_DSN` y Chromium: **29 suites evaluadas, 29 en verde, 0 no ejecutables, 862 aserciones, 0 fallos**, incluida la medición remota con su contrato TLS propio y el cerrojo entre pestañas en navegador real). Batería hermética: **371 suites / 14.140 aserciones / 0 fallos**.

---

## 1. Requisitos Funcionales y criterios EARS

### RF-01 · Instalabilidad y disponibilidad de la interfaz sin red

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **01.1** Metadatos de instalación y iconos escalables | `public/manifest.webmanifest`, `public/assets/icons/vendguard-icon-192.svg`, `vendguard-icon-512.svg`, `public/index.html` | `OfflinePwaAssetsAndUiGuardTest.mjs` 1.1–1.7 | 39/39 ✅ |
| **01.2** Carga completa de la interfaz sin red | `public/sw.js` (`APP_SHELL_ASSETS`, precache activo a activo), `public/index.html` (registro), `TechnicianRouteView.js` (imports estáticos) | `OfflinePwaAssetsAndUiGuardTest.mjs` 2.1–2.5, 2.7–2.12 | 39/39 ✅ — el shell es cerrado: todo módulo importado por un recurso precacheado también se precachea |
| **01.3** Actualización transparente de versión | `public/sw.js` (`CACHE_NAME` versionado, *Stale-While-Revalidate*, `skipWaiting`/`clients.claim`) | `OfflinePwaAssetsAndUiGuardTest.mjs` 2.2, 2.11 | 39/39 ✅ — el activo se sirve de caché y se refresca en segundo plano |

### RF-02 · Copia local de la ruta y control de vigencia (D6, QA 4)

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **02.1** Guardado automático del paquete de ruta | `TechnicianOfflineSyncController::getBootstrap`, `OfflineBootstrapSnapshotDto`, `PdoOfflineSyncMutationRepository::fetchBootstrapSnapshotForTechnician`, `OfflineSyncManager::refreshSnapshot`, `OfflineStorage::saveSnapshot`, `TechnicianRouteView.refreshOfflineSnapshotFromServer` | `OfflinePwaSyncApiTest.php` 1.1–1.8 · `OfflineStorageAndSyncManagerTest.mjs` 3.x, 4.x, **8.1–8.3** · `OfflineSyncServiceTest.php` | 30/30 · 66/66 · 41/41 ✅ — la ruta, la preventiva, el catálogo y la furgoneta reales viajan en el paquete y la copia nace `FRESH` |
| **02.2** Indicador permanente de antigüedad y conectividad | `OfflineSyncTrayModal.js` (`offline-connection-state`, `ageLabel`, `connectionLabel`) | `OfflinePwaAssetsAndUiGuardTest.mjs` 3.3, **5.1–5.5** · `OfflineStorageAndSyncManagerTest.mjs` 1.1–1.7 | 39/39 · 66/66 ✅ — el reloj y el estado de red se ejecutan sobre el componente real, no solo se inspeccionan como texto |
| **02.3** Aviso ámbar a las 4 h | `evaluateSnapshotValidity` (`STALE_WARNING`), `OfflineSyncTrayModal.js` (`offline-stale-warning`) | `OfflineStorageAndSyncManagerTest.mjs` 1.2–1.3 · `OfflinePwaAssetsAndUiGuardTest.mjs` 3.3 | 66/66 · 39/39 ✅ |
| **02.4** Solo lectura a las 12 h sin perder la cola | `evaluateSnapshotValidity` (`EXPIRED_READONLY`), `OfflineSyncManager::enqueue` (`SNAPSHOT_EXPIRED_READONLY`), `OfflineSyncTrayModal.js` (`offline-readonly-warning`) | `OfflineStorageAndSyncManagerTest.mjs` 1.4–1.5 · `OfflinePwaAssetsAndUiGuardTest.mjs` 3.3 | 66/66 · 39/39 ✅ |

### RF-03 · Encolado de operaciones, precinto sanitario y cuota fotográfica (D1, D3, D5, QA 1, QA 3, QA 4)

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **03.1** Las 9 operaciones encolables, su encadenamiento y el turno restaurable | `OFFLINE_OPERATIONS` (catálogo cerrado), `OfflineSyncManager::enqueue`, `TechnicianRouteView` (encolado de inicio, reanudación, pausas, resolución, comentario, preventivas y reinspección; `saveShiftStatus` / `restoreOfflineShiftStatus`), `evaluateOfflineShift` | `OfflineStorageAndSyncManagerTest.mjs` 3.1–3.5, 5.4–5.5, 6.1–6.6, **8.4–8.6, 11.1–11.8** · `TechnicianRouteViewTest.mjs` **22.1–22.8** · `OfflinePwaSyncApiTest.php` 2.1–2.5 | 66/66 · 171/171 · 30/30 ✅ — catálogo exacto y rutas reales; tras recargar sin red el turno activo se restaura y la intervención asignada se encola; ninguna operación en vivo se cuela en la cola |
| **03.2** Bloqueo de operaciones en vivo y Albarán A4 diferido | `TechnicianRouteView.guardOnlineOnly`, `ensureOfflineQueueReadyForShift`, aviso `offline-preventive-notice`; **bloqueo duro en `TechnicianPreventiveRouteTab.claimOrder` y `TechnicianVanStockPanel.confirmActiveOrder`**; aviso «📄 Albarán oficial A4 disponible al sincronizar con el servidor»; `OfflineSyncManager` rechaza operaciones fuera del catálogo | `OfflineStorageAndSyncManagerTest.mjs` 5.4, 8.5 · `TechnicianPreventiveRouteTabTest.mjs` 2.4–2.5 · `TechnicianVanStockPanelTest.mjs` 3.4–3.6 · `OfflinePwaAssetsAndUiGuardTest.mjs` 3.7 · `TechnicianRouteViewTest.mjs` **18.1–18.5, 19.1–19.2** | 66/66 · 22/22 · 26/26 · 39/39 · **171/171** ✅ — los dos componentes hijos bloquean antes de la API, la vista bloquea login/turno/relevo/oportunista y el albarán se habilita al confirmar la resolución offline |
| **03.3** Identificador único, estado local inmediato y descuento provisional | `OfflineSyncManager::enqueue` (`client_mutation_id`, `sequence_order`, `chain_started_at`), `applyOptimisticStopChange`, `applyStockDeduction`, `OfflineStorage::applyOptimisticMutation` | `OfflineStorageAndSyncManagerTest.mjs` 3.1–3.5 | 66/66 ✅ — Δt exacto y stock de 10 → 8 → 10 tras reversión |
| **03.4** Precinto físico in situ en máquinas perecederas | `requiresSanitarySeal`, `OfflineSyncManager::enqueue` (`SANITARY_SEAL_CONFIRMATION_REQUIRED`), `TechnicianRouteView` (`offline-sanitary-seal-block`, casilla obligatoria), `OfflineSyncService::assertSanitarySeal` | `OfflineStorageAndSyncManagerTest.mjs` 5.1–5.5 · `OfflinePwaAssetsAndUiGuardTest.mjs` 3.1–3.2 · `OfflineSyncClockAndQuotaTest.php` 3.x | 66/66 · 39/39 · 19/19 ✅ |
| **03.5** Cuota de 5 fotos / 25 MB y firma PNG exenta | `validatePhotoAttachment`, `serializePhoto`, `OFFLINE_PHOTO_MAX_*`, `OFFLINE_SIGNATURE_MAX_BYTES`, `OfflineSyncManager::getQueueSummary` | `OfflineStorageAndSyncManagerTest.mjs` 2.1–2.11 · `OfflinePwaSyncApiTest.php` 2.3 | 66/66 · 30/30 ✅ — la firma no consume cupo ni bytes |

### RF-04 · Sincronización FIFO, idempotencia y doble sello temporal (D2, QA 4)

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **04.1** FIFO estricto, disparo automático/manual y cerrojo entre pestañas | `OfflineStorage::listMutations` (orden por `sequence_order`), `OfflineStorage::recoverInterruptedMutations` (`syncing_since`, `SYNCING_STALE_AFTER_MS`), `OfflineSyncManager::processOutboxQueue` / `withSyncLock` (`navigator.locks` + mutex local) / `BroadcastChannel`, `TechnicianRouteView` (reconexión, «Sincronizar ahora» y rescate al hidratar) | `OfflineStorageAndSyncManagerTest.mjs` **9.1–9.2**, 6.1–6.6, **12.1–12.3, 13.1–13.9, 14.1–14.7** · `TechnicianRouteViewTest.mjs` **21.1–21.3** · `OfflinePwaSyncApiTest.php` 2.x | 93/93 · 171/171 · 34/34 ✅ — la bandeja ordena inserciones desordenadas, el envío respeta 101 → 102 → 103, la reconexión dispara la cola sin esperar a un toque, un envío huérfano de una pestaña muerta vuelve al FIFO sin tocar el que otra pestaña está enviando y dos pestañas no vacían la misma cola a la vez **mientras exista cerrojo de dispositivo** (Web Locks o arriendo); en el modo degradado `tab-only` la bandeja lo advierte y la deduplicación del servidor queda como red de seguridad |
| **04.2** Idempotencia por `(technician_id, client_mutation_id)` | `020_offline_pwa_sync.sql` (`uq_offline_tech_mutation`), `PdoOfflineSyncMutationRepository::findByTechnicianAndMutationId` / `reserveAppliedMutation` / `completeReservedMutation`, `OfflineSyncService::executeIdempotentMutation` (reserva y transición en la misma transacción), `PdoTransactionManager` | `OfflineSyncServiceTest.php` 1.1–1.5 · `OfflinePwaSyncApiTest.php` 3.1–3.2 · `PostgresOfflineSyncSchemaTest.php` · **`OfflineSyncMutationConcurrencyTest.php` 1.1–1.8, 2.1–2.4** | 41/41 · 30/30 · 22/22 · **12/12** ✅ — el reenvío responde `idempotent_replay` y no vuelve a descontar stock; con dos conexiones reales, la reserva se decide antes de la transición y la petición perdedora espera y recibe el cuerpo del ganador sin ejecutar el dominio, y un veredicto negativo deshace la reserva |
| **04.3** Sello efectivo, tolerancia de 5 min, Δt y auditoría dual | `OfflineSyncMetadata::resolveEffectiveTimestamp`, `OfflineSyncService::chainStartAnchor` (ancla en la última mutación `START_*`/`RESUME_*`), `PdoOfflineSyncMutationRepository::findLatestAppliedChainStart`, `PdoIncidentRepository::startIntervention` (persiste el instante efectivo), `PdoOfflineSyncMutationRepository::recordAppliedMutation` | `OfflineSyncServiceTest.php` 6.1–6.5 · `OfflineSyncClockAndQuotaTest.php` 1.1–2.6 · `OfflinePwaSyncApiTest.php` 2.1–2.6 | 41/41 · 19/19 · 30/30 ✅ — Δt de 1.500 s exacto con 24 h de desfase, ancla en la reanudación y tope en `synced_at`; `incidents.started_at` conserva el instante efectivo |

### RF-05 · Conflictos auditados y blindaje sanitario (D2, QA 1, QA 2, QA 3)

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **05.1** Titularidad vigente, conflicto aislado por parada y comentarios independientes | `OfflineSyncManager::processOutboxQueue` (`failedStateStops`, `STATE_CHAIN_BLOCKED`, comentario `is_state_transition = false`), `handleSyncFailure` (todo `4xx` salvo `408`/`425`/`429` es veredicto; `413` retira solo la evidencia), `revertProvisionalStock`; `TechnicianController` responde `409 INCIDENT_OWNERSHIP_MISMATCH` desde la cola | `OfflineStorageAndSyncManagerTest.mjs` 6.1–6.6, **10.1–10.3**, **12.4–12.8** · `OfflinePwaSyncApiTest.php` 6.6–6.7 · `TechnicianRouteViewTest.mjs` 17.6 | 75/75 · 30/30 · 171/171 ✅ — el cambio de titular no escribe estado ni mutación; un `404`/`400`/`410` queda en conflicto sin interrumpir el FIFO, el `413` conserva el trabajo sin la foto y solo `5xx`/`408`/`429` se reintentan |
| **05.2** Colisión parcial exclusiva de reintegro | `OfflineSyncService` (`partialRefundCollision`, `skip_refund_block`), `PdoOfflineSyncMutationRepository::recordAppliedMutation` (nota interna automática) | `OfflineSyncServiceTest.php` 2.x · `OfflinePwaSyncApiTest.php` 4.1–4.2 | 41/41 · 30/30 ✅ |
| **05.3** Volcado obligatorio en 1 toque, canceladas/cerradas y preventivas | `OfflineSyncService::dumpConflict`, `PdoOfflineSyncMutationRepository::recordConflictDump`, `TechnicianOfflineSyncController::dumpConflict`, `OfflineSyncTrayModal.js` (`offline-conflict-dump`), `OfflineSyncManager::dumpConflict` | `OfflineStorageAndSyncManagerTest.mjs` 6.7–6.12 · `OfflineSyncServiceTest.php` 4.x · `OfflinePwaSyncApiTest.php` 6.1–6.5 · `OfflinePwaAssetsAndUiGuardTest.mjs` 3.4–3.5 | 66/66 · 41/41 · 30/30 · 39/39 ✅ — sin botón de descarte; `is_internal = TRUE` invisible a la sede |
| **05.4** Cuarentena sanitaria innegociable a las 4 h | `OfflineSyncService` (`shouldPreserveQuarantine`, `sanitary_quarantine_retained`), `TechnicianController` (resolución offline) | `OfflineSyncServiceTest.php` 3.x · `OfflinePwaSyncApiTest.php` 5.1–5.3 | 41/41 · 30/30 ✅ — la avería se cierra, `machines.status = 'SANITARY_QUARANTINE'` permanece |

### RF-06 · Sesión, turno y baja sobrevenida de cuenta (D4, QA 5)

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **06.1** Bloqueo del cierre de sesión con cola viva | `AppNavbar::handleLogout` (`logout-blocked`), `OfflineSyncManager::getQueueSummary`, insignia `navbar-offline-queue-badge` | `OfflineStorageAndSyncManagerTest.mjs` 7.1–7.3 · `OfflinePwaAssetsAndUiGuardTest.mjs` 3.6 | 66/66 · 39/39 ✅ |
| **06.2** Sincronización previa a pausar/cerrar turno | `TechnicianRouteView::ensureOfflineQueueReadyForShift` (sincroniza, exige volcar conflictos y bloquea el cambio de turno) | `TechnicianRouteViewTest.mjs` 17.1–17.3 | 171/171 ✅ — sin red no se llama al servidor; con conflictos vivos se exige el volcado; con la bandeja vacía el turno tramita |
| **06.3** Re-autenticación del mismo técnico ante 401 | `OfflineSyncManager::handleSyncFailure` (`reauth_required`, cola intacta en `PENDING`), `TechnicianRouteView.handleReauthRequired` / `requestOfflineReauth` / `handleInternalLogin`, `OfflineSyncTrayModal.js` (`offline-reauth-owner`) | `TechnicianRouteViewTest.mjs` 17.4–17.6 · `OfflinePwaAssetsAndUiGuardTest.mjs` 3.7 | 171/171 · 39/39 ✅ — la cola se conserva, se nombra al titular y una cuenta ajena no puede asumirla |
| **06.4** Volcado automático por baja de cuenta | `OfflineSyncManager::dumpDeactivatedAccountQueue`, `TechnicianOfflineSyncController::dumpDeactivatedAccount`, `PdoOfflineSyncMutationRepository::recordDeactivatedAccountDumpBatch` | `OfflineSyncServiceTest.php` 5.x · `OfflinePwaSyncApiTest.php` 7.1–7.3 | 41/41 · 30/30 ✅ — `clear_local_storage = true` y `DEACTIVATED_ACCOUNT_DUMP` |
| **06.5** Limpieza de la copia local al salir sin pendientes | `AppNavbar::handleLogout` (`clearTechnician`), `OfflineStorage::clearTechnician` | `OfflineStorageAndSyncManagerTest.mjs` 4.2, 7.4–7.5 | 66/66 ✅ |

---

## 2. Requisitos No Funcionales

| RNF | Artefacto | Test / evidencia | Resultado medido |
|---|---|---|---|
| **RNF-01** Carga sin red < 2 s y encolado local < 150 ms | `sw.js` (precache del shell, raíz excluida), `OfflineStorage` (`IndexedDB`/respaldo en memoria), `TechnicianRouteView` (hidratación local) | `OfflinePwaAssetsAndUiGuardTest.mjs` 2.1–2.12 · `OfflineStorageAndSyncManagerTest.mjs` · **acta reproducible [`docs/verificacion_modulo_24_rnf01_rnf04.md`](../../docs/verificacion_modulo_24_rnf01_rnf04.md) §6** | ✅ **Medido en Chromium real**: apertura sin red 157,4 ms de mediana (5 recargas, `transferSize = 0`, servidor detenido) y encolado local 5,85 ms de mediana / 7,6 ms p95 (10 acciones, `IndexedDB` incluido). Ambos por debajo de 2.000 ms y 150 ms |
| **RNF-02** Cero dependencias externas (Art. IV.3) | `public/sw.js`, `OfflineStorage.js`, `OfflineSyncManager.js`, `OfflineSyncTrayModal.js`; sin `package.json`/Composer en el frontend | `RefundsModuleClosureTest.php` 1.9 · `OfflinePwaAssetsAndUiGuardTest.mjs` | ✅ 60/60 · 39/39 — `src/` solo importa el espacio propio y built-ins; el DTO evita `stdClass` |
| **RNF-03** Sistema de diseño (`docs/design.md`) | `OfflineSyncTrayModal.js`, `TechnicianRouteView.js`, `TechnicianPreventiveRouteTab.js` (avisos de bloqueo), `public/assets/css/design-tokens.css` | `DesignTokenDebtRatchetTest.mjs` · `OfflinePwaAssetsAndUiGuardTest.mjs` 4.1–4.4 | ✅ 3/3 (techo 2.268, contador 2.268) · 39/39 — cero hexadecimales nuevos, 44 px, radios 4/8 px y borde hairline |
| **RNF-04** Dualidad lingüística | Literales de UI, mensajes de `OFFLINE_MESSAGES`, avisos de bloqueo en vivo, notas de volcado y comentarios de código | `OfflineStorageAndSyncManagerTest.mjs` (mensajes de precinto, cuota y catálogo) · `TechnicianRouteViewTest.mjs` 17.x · `TechnicianPreventiveRouteTabTest.mjs` 2.5 · `TechnicianVanStockPanelTest.mjs` 3.5 · **acta §5** | ✅ mensajes en castellano neutro; identificadores y códigos en inglés |

---

## 3. Decisiones técnicas y resoluciones QA

| Elemento | Escenario | Artefacto / Test | Resultado |
|---|---|---|---|
| **D1** Frontera de operaciones | 9 operaciones encolables; login, turno, relevo, carga, reclamo libre y oportunistas fuera de ruta exigen red | `OFFLINE_OPERATIONS` · `OfflineStorageAndSyncManagerTest.mjs` 5.4, 8.4–8.6 · `TechnicianRouteView.guardOnlineOnly` · `TechnicianRouteViewTest.mjs` **18.1–18.5** · bloqueo duro de `claimOrder` y `confirmActiveOrder` (`TechnicianPreventiveRouteTabTest.mjs` 2.4–2.5, `TechnicianVanStockPanelTest.mjs` 3.4–3.6) | ✅ |
| **D2** Conflictos sin pérdida y doble sello | 409 conserva la operación, aísla la parada y permite volcar; auditoría con `client_occurred_at`, `effective_occurred_at` y `synced_at` | `OfflineStorageAndSyncManagerTest.mjs` 6.1–6.9 · `OfflinePwaSyncApiTest.php` 2.1–3.2, 6.1–6.7 · `OfflineSyncServiceTest.php` 6.1–6.5 | ✅ |
| **D3** Cuota de evidencias | 5 fotos / 25 MB; firma PNG exenta; formatos seguros y 5 MB por archivo | `OfflineStorageAndSyncManagerTest.mjs` 2.1–2.11 | ✅ |
| **D4** Protección de sesión | Logout bloqueado con cola; re-autenticación del mismo titular; limpieza al salir vacío | `OfflineStorageAndSyncManagerTest.mjs` 7.1–7.5 · `TechnicianRouteViewTest.mjs` 17.4–17.6 · `OfflineSyncTrayModal.js` | ✅ |
| **D5** Encadenamiento y stock provisional | Inicio → comentario → resolución; estado local y descuento/reversión de furgoneta | `OfflineStorageAndSyncManagerTest.mjs` 3.1–3.5, 6.3 | ✅ |
| **D6** Vigencia 4 h / 12 h | Aviso ámbar y solo lectura, conservando la cola ya encolada | `OfflineStorageAndSyncManagerTest.mjs` 1.1–1.7 | ✅ |
| **QA 1** Precinto y no descarte | Casilla obligatoria en perecederos; botón único de volcado; sin vía de borrado | `OfflineStorageAndSyncManagerTest.mjs` 5.1–5.5, 6.10–6.12 · `OfflinePwaAssetsAndUiGuardTest.mjs` 3.1–3.5 | ✅ |
| **QA 2** Canceladas/cerradas, preventivas y reintegro | Excepción de auditoría a 48 h, notas en orden preventiva y colisión parcial | `OfflineSyncServiceTest.php` 2.x, 4.x · `OfflinePwaSyncApiTest.php` 4.1–4.2, 6.1–6.5 | ✅ |
| **QA 3** Firma exenta y comentarios independientes | La firma no ocupa cupo; un comentario fallido no bloquea la transición posterior | `OfflineStorageAndSyncManagerTest.mjs` 2.11, 6.3, **10.1–10.3** | ✅ |
| **QA 4** Δt, reclamo en vivo y Albarán diferido | Δt exacto con reloj desajustado; reanudación como ancla; operaciones libres bloqueadas; aviso de albarán | `OfflineSyncServiceTest.php` 6.1–6.5 · `OfflineSyncClockAndQuotaTest.php` 2.1–2.6 · `OfflinePwaSyncApiTest.php` 2.1–2.6 · `TechnicianPreventiveRouteTabTest.mjs` 2.4–2.5 · `TechnicianVanStockPanelTest.mjs` 3.4–3.6 · `OfflinePwaAssetsAndUiGuardTest.mjs` 3.7 | ✅ |
| **QA 5** Turno y baja de cuenta | Sincronización previa al turno; volcado automático y limpieza del dispositivo | `TechnicianRouteViewTest.mjs` 17.1–17.3 · `OfflinePwaSyncApiTest.php` 7.1–7.3 | ✅ |

---

## 4. Casos límite de la especificación (§6)

| Caso | Cobertura | Resultado |
|---|---|---|
| **§6.1** Cadena cuya primera transición falla frente a comentario intermedio fallido | `OfflineStorageAndSyncManagerTest.mjs` 6.1–6.6, 10.1–10.3 | ✅ |
| **§6.2** Corte de señal en mitad de la sincronización e idempotencia del reintento | `OfflinePwaSyncApiTest.php` 3.1–3.2 · `OfflineSyncServiceTest.php` | ✅ |
| **§6.3** Reloj atrasado 24 h con Δt de 35/25 min | `OfflineSyncClockAndQuotaTest.php` 2.1–2.6 · `OfflinePwaSyncApiTest.php` 2.5 | ✅ Δt exacto |
| **§6.4** Reintegro ya regularizado por Coordinación | `OfflineSyncServiceTest.php` · `OfflinePwaSyncApiTest.php` 4.1–4.2 | ✅ |
| **§6.5** Cuenta desactivada mientras trabajaba sin conexión | `OfflinePwaSyncApiTest.php` 7.1–7.3 · `OfflineSyncServiceTest.php` | ✅ |

---

## 5. Definición de Hecho (§8)

| DoD | Evidencia | Estado |
|---|---|---|
| **1.** Instalación y carga de la interfaz sin red | `OfflinePwaAssetsAndUiGuardTest.mjs` 1.1–2.12 · **acta §6.1** | ✅ **Medido en Chromium real**: apertura sin red 157,4 ms de mediana (5 recargas, `transferSize = 0`, servidor detenido), por debajo de 2.000 ms |
| **2.** Copia local por técnico, antigüedad visible y bloqueo a las 12 h | `OfflineStorageAndSyncManagerTest.mjs` 1.1–1.7, 4.1–4.2, 8.1–8.3 · `OfflinePwaSyncApiTest.php` 1.1–1.8 | ✅ |
| **3.** Flujo completo sin conexión con precinto y cuota | `OfflineStorageAndSyncManagerTest.mjs` 2.x, 3.x, 5.x · `OfflinePwaSyncApiTest.php` 2.x | ✅ |
| **4.** Operaciones online-only y Albarán A4 diferido | `TechnicianRouteView.guardOnlineOnly`, `ensureOfflineQueueReadyForShift` · `TechnicianRouteViewTest.mjs` **18.1–18.5, 19.1–19.2, 20.1–20.4, 21.1–21.3** · `TechnicianPreventiveRouteTabTest.mjs` 2.4–2.5 · `TechnicianVanStockPanelTest.mjs` 3.4–3.6 · `OfflinePwaAssetsAndUiGuardTest.mjs` 3.7 | ✅ bloqueo duro en vista y en los dos componentes hijos; el albarán diferido se enciende con la resolución confirmada |
| **5.** FIFO, idempotencia, Δt y cuarentena | `OfflineSyncClockAndQuotaTest.php` 19/19 · `OfflineSyncServiceTest.php` 41/41 · `OfflinePwaSyncApiTest.php` 30/30 | ✅ |
| **6.** Conflictos volcados en 1 toque, sin descarte | `OfflineStorageAndSyncManagerTest.mjs` 6.7–6.12 · `OfflinePwaSyncApiTest.php` 6.1–6.7 | ✅ |
| **7.** Sesión, turno, re-autenticación y baja | `OfflineStorageAndSyncManagerTest.mjs` 7.1–7.5 · `TechnicianRouteViewTest.mjs` 17.1–17.6, 18.1–18.5, 21.1–21.3 · `OfflinePwaSyncApiTest.php` 7.1–7.3 | ✅ |
| **8.** Batería global al 100 % | `php tests/run_all.php` + `php tests/run_new_engine.php` | ✅ 371 suites / 14.140 aserciones / 0 fallos (hermética) · canal 29/29 / 862 aserciones / 0 fallos |

---

## 6. Certificación global y limitaciones declaradas

**Suites del módulo ejecutadas y en verde:**

| Suite | Aserciones |
|---|---:|
| `OfflineSyncClockAndQuotaTest.php` | 19/19 |
| `OfflineSyncServiceTest.php` | 41/41 |
| `OfflineStorageAndSyncManagerTest.mjs` | 86/86 |
| `OfflineDumpPhotoCleanupTest.php` | 10/10 |
| `OfflineStorageAtomicEnqueueTest.mjs` | 11/11 |
| `OfflineStorageSessionPurgeTest.mjs` | 21/21 |
| `OfflinePwaAssetsAndUiGuardTest.mjs` | 39/39 |
| `TechnicianRouteViewTest.mjs` | 171/171 |
| `TechnicianPreventiveRouteTabTest.mjs` | 22/22 |
| `TechnicianVanStockPanelTest.mjs` | 26/26 |
| `OfflinePwaSyncApiTest.php` (canal `new_engine`) | 34/34 |
| `OfflineSyncLockBrowserTest.php` (canal `new_engine`, navegador real) | 12/12 |
| `PostgresOfflineSyncSchemaTest.php` (canal `new_engine`) | 22/22 |
| `OfflineSyncMutationConcurrencyTest.php` (canal `new_engine`) | 12/12 |
| `PostgresSchemaTest.php` (canal `new_engine`) | 107/107 |
| `SchemaConvergenceParityTest.php` (canal `new_engine`) | 18/18 |
| `DesignTokenDebtRatchetTest.mjs` | 3/3 |

**Limitaciones declaradas (no se presentan como verdes):**

1. **Carga < 2 s y encolado < 150 ms (RNF-01):** medidos en navegador real según el acta §6: 157,4 ms de mediana para la apertura sin red (5 recargas con el servidor detenido y `transferSize = 0`) y 5,85 ms de mediana / 7,6 ms p95 para el encolado local (10 acciones). La cifra procede de una única máquina (Chromium/Windows, 390 × 844) y no se extrapola a hardware móvil de gama baja; la guarda automática certifica además el shell cerrado y que la API nunca se cachea. El cerrojo entre pestañas sí queda certificado en navegador real (`OfflineSyncLockBrowserTest`), con dos pestañas del mismo perfil y control negativo; lo que no se mide es la latencia del cerrojo ni su comportamiento con más de dos pestañas.
2. **Corrida global:** `php tests/run_all.php` cierra con 371 suites / 14.140 aserciones, 0 fallos, cifras del README coherentes y código de salida 0. `php tests/run_new_engine.php` cierra el canal con 29 suites evaluadas, 29 en verde, 0 no ejecutables, 862 aserciones y 0 fallos. `PoolerLatencyMeasurementTest` aporta 30 de esas aserciones y resuelve su contrato TLS propio (`deploy/local/supabase/.env.local`) solo para su proceso hijo, sin que el resto del canal herede el motor remoto.
3. **Doble sello temporal:** la suite de integración verifica `effective_occurred_at`, `client_occurred_at` y `synced_at`; la visualización del sello dual en el visor de auditoría no forma parte de este módulo.

---

## 7. Revisión de código del PR #9 (2026-10-29)

| Hallazgo | Riesgo | Corrección | Test que lo cubre |
|---|---|---|---|
| **H4** | Concurrencia / integridad | `OfflineStorage::enqueueMutation()` escribe `sequence_order`, el alta, el cierre del inicio previo y la proyección optimista en **una sola** transacción sobre `[mutation_outbox, route_snapshots]`; una colisión del índice único deshace todo y el alta se reintenta hasta tres veces releyendo el máximo | `OfflineStorageAtomicEnqueueTest.mjs` 1.1–1.5, 2.1–2.2 (11/11) |
| **H5** | Concurrencia entre pestañas | `OfflineSyncManager::withSyncLock()` encadena un mutex local (también sin Web Locks) con `navigator.locks.request('vendguard-offline-sync')`; `BroadcastChannel` avisa del cambio sin eco y la pestaña receptora solo relee. La garantía vale mientras exista cerrojo de dispositivo: sin Web Locks la cubre el arriendo de `R2` y, sin almacén compartido, el modo `tab-only` se declara en la bandeja (`R6`) | `OfflineStorageAndSyncManagerTest.mjs` 13.1–13.7, 14.1–14.7 · `OfflineSyncLockBrowserTest.php` 1.1–5.4 (dos pestañas de Chromium reales, con control negativo sin cerrojo y sin arriendo) |
| **M1** | UX offline | Las subidas `multipart/form-data` usan `UPLOAD_TIMEOUT_MS = 30000` y su aviso habla de 30,0 s; el resto conserva 5,0 s y el `ATTR_TIMEOUT` de PDO no cambia | `ApiClientTimeoutTest.mjs` 7.1–7.5 (42/42) |
| **M2** | Concurrencia | Toda lectura-modificación-escritura del snapshot pasa por `mutateSnapshot()` (transacción única, encadenada en la pestaña y serializada entre pestañas); un mutador asíncrono se rechaza con `SNAPSHOT_MUTATOR_ASYNC` | `OfflineStorageAtomicEnqueueTest.mjs` 2.1–2.5, 3.1 (11/11) |
| **M3** | Seguridad / privacidad | Base local v2 con la evidencia como `Blob` (migración de la v1), purga de sesiones ajenas y copias caducadas vacías, y copia minimizada sin correo ni nombre del contacto de sede; la actualización poda además las copias que la v1 ya había guardado (`R4`) | `OfflineStorageSessionPurgeTest.mjs` 1.1–5.2, 4.3–4.5 (24/24) |
| **B1** | Integridad | El volcado de conflicto exige que la parada haya sido del técnico: avería ajena e inexistente responden `404` y no escriben Nota Interna ni mutación; la preventiva ajena responde `404` | `OfflinePwaSyncApiTest.php` 6.8–6.11 (34/34) |
| **B2** | UX / rendimiento | La evidencia se valida (tipo y tamaño) **antes** de leer sus bytes, de modo que una foto de más de 5 MB se rechaza sin codificarse | `OfflineStorageAndSyncManagerTest.mjs` 2.12 |
| **R1** | Concurrencia | El respaldo de `withWebLock()` solo actúa cuando el navegador **deniega la concesión**; si el callback ya arrancó, la excepción de la tarea se propaga sin volver a ejecutarla, de modo que un envío fallido no se reintenta en el acto ni el alta se duplica con una clave nueva | `OfflineStorageAndSyncManagerTest.mjs` 13.8–13.9 |
| **R3** | Integridad / almacenamiento | La evidencia del volcado se retira cuando ningún registro la referencia: repetición idempotente, orden preventiva (que solo anexa texto) y rechazo `404`; solo se conserva la foto de la Nota Interna recién insertada | `OfflineDumpPhotoCleanupTest.php` 1.1–4.2 (10/10) |
| **R2** | Concurrencia / despliegue | Sin Web Locks (origen no seguro) la exclusión entre pestañas no puede desaparecer en silencio: `withDeviceLock()` usa un arriendo en `localStorage` (token, caducidad de 5 s, latido y sondeo) y, si tampoco hay almacén compartido, `lockMode` queda en `tab-only` y la bandeja lo advierte. El frontal corrige o rechaza el transporte: `HttpsPolicy` responde `308`, emite HSTS cuando ya hay TLS y sanea `Host`; solo `VENDGUARD_ALLOW_INSECURE_HTTP=1` admite HTTP plano | `OfflineStorageAndSyncManagerTest.mjs` 14.1–14.7 · `HttpsPolicyTest.php` 1.1–5.1 (23/23) · `OfflinePwaAssetsAndUiGuardTest.mjs` 6.1–6.3 (42/42) · `OfflineSyncLockBrowserTest.php` 5.1–5.4 (dos pestañas reales sin Web Locks, `maxConcurrent = 1`) · acta contra proxy TLS real: [`docs/verificacion_transporte_tls_modulo_24.md`](../../docs/verificacion_transporte_tls_modulo_24.md) |
| **R4** | Privacidad | La actualización v1 → v2 re-minimiza las copias ya guardadas dentro de la misma transacción `versionchange`: el correo y el nombre del contacto de sede desaparecen sin esperar al siguiente bootstrap, y una fila ya limpia no se reescribe | `OfflineStorageSessionPurgeTest.mjs` 4.3–4.5 (24/24) |
| **R5** | Privacidad (límite declarado) | Sin cambio de código: la copia, la cola y las fotos viven en `IndexedDB` sin cifrado adicional y la protección es de ciclo de vida, no criptográfica. Queda escrito como `RNF-05` y como exclusión explícita del alcance para que nadie lea el módulo como si cifrara en reposo | `specs/24-offline-pwa/spec.md` · `RNF-05` y §7.6 |
| **R6** | Transparencia | La promesa «dos pestañas nunca vacían la misma cola» se acota a «mientras exista cerrojo de dispositivo», con el modo `tab-only` declarado y la deduplicación del servidor como red de seguridad | `OfflinePwaAssetsAndUiGuardTest.mjs` 6.1–6.3 (42/42) · contrato §3.3 (tabla de `lockMode`) |

---

## 8. Mantenimiento

Esta matriz es un artefacto vivo. Cualquier cambio en un `RF`, criterio EARS, `RNF`, decisión `D` o resolución `QA` debe actualizar la fila correspondiente y su resultado medido en la misma entrega. Las cifras globales de la batería se declaran en el `README.md`; esta matriz solo conserva las cifras de las suites del Módulo 24.
