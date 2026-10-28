# MATRIZ DE TRAZABILIDAD · MÓDULO 24

**Especificación:** [`spec.md`](spec.md) (v2.0) · **Contratos:** [`../technical/offline_pwa_contracts.md`](../technical/offline_pwa_contracts.md) · **Plan:** [`plan.md`](plan.md) · **Tareas:** [`tasks.md`](tasks.md)

**Esquema de referencia:** `RF-01` instalabilidad y carga sin red · `RF-02` copia local y vigencia · `RF-03` encolado, precinto y cuota · `RF-04` sincronización, idempotencia y Δt · `RF-05` conflictos y cuarentena · `RF-06` sesión, turno y baja de cuenta.

**Corrida de certificación:** 28 de octubre de 2026 (PostgreSQL local `vendguard_battery`; canal `new_engine` ejecutado con `PG_TEST_DSN`).

---

## 1. Requisitos Funcionales y criterios EARS

### RF-01 · Instalabilidad y disponibilidad de la interfaz sin red

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **01.1** Metadatos de instalación y iconos escalables | `public/manifest.webmanifest`, `public/assets/icons/vendguard-icon-192.svg`, `vendguard-icon-512.svg`, `public/index.html` | `OfflinePwaAssetsAndUiGuardTest.mjs` 1.1–1.7 | 33/33 ✅ |
| **01.2** Carga completa de la interfaz sin red | `public/sw.js` (`APP_SHELL_ASSETS`, precache activo a activo), `public/index.html` (registro), `TechnicianRouteView.js` (imports estáticos) | `OfflinePwaAssetsAndUiGuardTest.mjs` 2.1–2.5, 2.7–2.12 | 33/33 ✅ — el shell es cerrado: todo módulo importado por un recurso precacheado también se precachea |
| **01.3** Actualización transparente de versión | `public/sw.js` (`CACHE_NAME` versionado, *Stale-While-Revalidate*, `skipWaiting`/`clients.claim`) | `OfflinePwaAssetsAndUiGuardTest.mjs` 2.2, 2.11 | 33/33 ✅ — el activo se sirve de caché y se refresca en segundo plano |

### RF-02 · Copia local de la ruta y control de vigencia (D6, QA 4)

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **02.1** Guardado automático del paquete de ruta | `TechnicianOfflineSyncController::getBootstrap`, `OfflineBootstrapSnapshotDto`, `PdoOfflineSyncMutationRepository::fetchBootstrapSnapshotForTechnician`, `OfflineSyncManager::refreshSnapshot`, `OfflineStorage::saveSnapshot`, `TechnicianRouteView.refreshOfflineSnapshotFromServer` | `OfflinePwaSyncApiTest.php` 1.1–1.6 · `OfflineStorageAndSyncManagerTest.mjs` 3.x, 4.x · `OfflineSyncServiceTest.php` | 23/23 · 47/47 · 36/36 ✅ |
| **02.2** Indicador permanente de antigüedad y conectividad | `OfflineSyncTrayModal.js` (`offline-connection-state`, reloj «Última sincronización»), `TechnicianRouteView.offlineSnapshotLabel` | `OfflinePwaAssetsAndUiGuardTest.mjs` 3.3 · `OfflineStorageAndSyncManagerTest.mjs` 1.1–1.7 | 33/33 · 47/47 ✅ |
| **02.3** Aviso ámbar a las 4 h | `evaluateSnapshotValidity` (`STALE_WARNING`), `OfflineSyncTrayModal.js` (`offline-stale-warning`) | `OfflineStorageAndSyncManagerTest.mjs` 1.2–1.3 · `OfflinePwaAssetsAndUiGuardTest.mjs` 3.3 | 47/47 · 33/33 ✅ |
| **02.4** Solo lectura a las 12 h sin perder la cola | `evaluateSnapshotValidity` (`EXPIRED_READONLY`), `OfflineSyncManager::enqueue` (`SNAPSHOT_EXPIRED_READONLY`), `OfflineSyncTrayModal.js` (`offline-readonly-warning`) | `OfflineStorageAndSyncManagerTest.mjs` 1.4–1.5 · `OfflinePwaAssetsAndUiGuardTest.mjs` 3.3 | 47/47 · 33/33 ✅ |

### RF-03 · Encolado de operaciones, precinto sanitario y cuota fotográfica (D1, D3, D5, QA 1, QA 3, QA 4)

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **03.1** Las 9 operaciones encolables y su encadenamiento | `OFFLINE_OPERATIONS` (catálogo cerrado), `OfflineSyncManager::enqueue`, `TechnicianRouteView` (encolado de inicio, reanudación, pausas, resolución, comentario, preventivas y reinspección) | `OfflineStorageAndSyncManagerTest.mjs` 3.1–3.5, 5.4–5.5, 6.1–6.6 · `OfflinePwaSyncApiTest.php` 2.1–2.5 | 47/47 · 23/23 ✅ |
| **03.2** Bloqueo de operaciones en vivo y Albarán A4 diferido | `TechnicianRouteView.guardOnlineOnly`, `ensureOfflineQueueReadyForShift`, aviso `offline-preventive-notice`, aviso «📄 Albarán oficial A4 disponible al sincronizar con el servidor»; `OfflineSyncManager` rechaza operaciones fuera del catálogo | `OfflineStorageAndSyncManagerTest.mjs` 5.4 (catálogo) · `OfflinePwaAssetsAndUiGuardTest.mjs` 3.7 (aviso A4) | 47/47 · 33/33 ✅ — el bloqueo de las operaciones que despacha la vista es duro; las acciones alojadas en componentes hijos se señalizan con el aviso de conexión en vivo |
| **03.3** Identificador único, estado local inmediato y descuento provisional | `OfflineSyncManager::enqueue` (`client_mutation_id`, `sequence_order`, `chain_started_at`), `applyOptimisticStopChange`, `applyStockDeduction`, `OfflineStorage::applyOptimisticMutation` | `OfflineStorageAndSyncManagerTest.mjs` 3.1–3.5 | 47/47 ✅ — Δt exacto y stock de 10 → 8 → 10 tras reversión |
| **03.4** Precinto físico in situ en máquinas perecederas | `requiresSanitarySeal`, `OfflineSyncManager::enqueue` (`SANITARY_SEAL_CONFIRMATION_REQUIRED`), `TechnicianRouteView` (`offline-sanitary-seal-block`, casilla obligatoria), `OfflineSyncService::assertSanitarySeal` | `OfflineStorageAndSyncManagerTest.mjs` 5.1–5.5 · `OfflinePwaAssetsAndUiGuardTest.mjs` 3.1–3.2 · `OfflineSyncClockAndQuotaTest.php` 3.x | 47/47 · 33/33 · 19/19 ✅ |
| **03.5** Cuota de 5 fotos / 25 MB y firma PNG exenta | `validatePhotoAttachment`, `serializePhoto`, `OFFLINE_PHOTO_MAX_*`, `OFFLINE_SIGNATURE_MAX_BYTES`, `OfflineSyncManager::getQueueSummary` | `OfflineStorageAndSyncManagerTest.mjs` 2.1–2.11 · `OfflinePwaSyncApiTest.php` 2.3 | 47/47 · 23/23 ✅ — la firma no consume cupo ni bytes |

### RF-04 · Sincronización FIFO, idempotencia y doble sello temporal (D2, QA 4)

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **04.1** FIFO estricto y disparo automático/manual | `OfflineStorage::listMutations` (orden por `sequence_order`), `OfflineSyncManager::processOutboxQueue`, `TechnicianRouteView` (reconexión y «Sincronizar ahora») | `OfflineStorageAndSyncManagerTest.mjs` 6.1–6.6 · `OfflinePwaSyncApiTest.php` 2.x | 47/47 · 23/23 ✅ |
| **04.2** Idempotencia por `(technician_id, client_mutation_id)` | `020_offline_pwa_sync.sql` (`uq_offline_tech_mutation`), `PdoOfflineSyncMutationRepository::findByTechnicianAndMutationId`, `OfflineSyncService::executeIdempotentMutation` | `OfflineSyncServiceTest.php` 1.1–1.5 · `OfflinePwaSyncApiTest.php` 3.1–3.2 · `PostgresOfflineSyncSchemaTest.php` | 36/36 · 23/23 · 22/22 ✅ — el reenvío responde `idempotent_replay` y no vuelve a descontar stock |
| **04.3** Sello efectivo, tolerancia de 5 min, Δt y auditoría dual | `OfflineSyncMetadata::resolveEffectiveTimestamp`, `OfflineSyncService` (`effective_occurred_at`, `synced_at`, `clock_skew_adjusted`), `PdoOfflineSyncMutationRepository::recordAppliedMutation` | `OfflineSyncClockAndQuotaTest.php` 1.1–2.6 · `OfflinePwaSyncApiTest.php` 2.1–2.5 | 19/19 · 23/23 ✅ — Δt de 1.500 s exacto incluso con 24 h de desfase; el cierre se ancla al hito del inicio + Δt |

### RF-05 · Conflictos auditados y blindaje sanitario (D2, QA 1, QA 2, QA 3)

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **05.1** Titularidad vigente, conflicto aislado por parada y comentarios independientes | `OfflineSyncManager::processOutboxQueue` (`failedStateStops`, `STATE_CHAIN_BLOCKED`, comentario `is_state_transition = false`), `handleSyncFailure` (403/409/422), `revertProvisionalStock` | `OfflineStorageAndSyncManagerTest.mjs` 6.1–6.6 | 47/47 ✅ — el 409 conserva la operación; el comentario y la otra parada continúan |
| **05.2** Colisión parcial exclusiva de reintegro | `OfflineSyncService` (`partialRefundCollision`, `skip_refund_block`), `PdoOfflineSyncMutationRepository::recordAppliedMutation` (nota interna automática) | `OfflineSyncServiceTest.php` 2.x · `OfflinePwaSyncApiTest.php` 4.1–4.2 | 36/36 · 23/23 ✅ |
| **05.3** Volcado obligatorio en 1 toque, canceladas/cerradas y preventivas | `OfflineSyncService::dumpConflict`, `PdoOfflineSyncMutationRepository::recordConflictDump`, `TechnicianOfflineSyncController::dumpConflict`, `OfflineSyncTrayModal.js` (`offline-conflict-dump`), `OfflineSyncManager::dumpConflict` | `OfflineStorageAndSyncManagerTest.mjs` 6.7–6.12 · `OfflineSyncServiceTest.php` 3.x · `OfflinePwaSyncApiTest.php` 6.1–6.5 · `OfflinePwaAssetsAndUiGuardTest.mjs` 3.4–3.5 | 47/47 · 36/36 · 23/23 · 33/33 ✅ — sin botón de descarte; `is_internal = TRUE` invisible a la sede |
| **05.4** Cuarentena sanitaria innegociable a las 4 h | `OfflineSyncService` (`preserveSanitaryQuarantine`, `sanitary_quarantine_retained`), `TechnicianController` (resolución offline) | `OfflineSyncServiceTest.php` 4.x · `OfflinePwaSyncApiTest.php` 5.1–5.3 | 36/36 · 23/23 ✅ — la avería se cierra, `machines.status = 'SANITARY_QUARANTINE'` permanece |

### RF-06 · Sesión, turno y baja sobrevenida de cuenta (D4, QA 5)

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **06.1** Bloqueo del cierre de sesión con cola viva | `AppNavbar::handleLogout` (`logout-blocked`), `OfflineSyncManager::getQueueSummary`, insignia `navbar-offline-queue-badge` | `OfflineStorageAndSyncManagerTest.mjs` 7.1–7.3 · `OfflinePwaAssetsAndUiGuardTest.mjs` 3.6 | 47/47 · 33/33 ✅ |
| **06.2** Sincronización previa a pausar/cerrar turno | `TechnicianRouteView::ensureOfflineQueueReadyForShift` (sincroniza, exige volcar conflictos y bloquea el cambio de turno) | Revisión estática del flujo y `OfflineStorageAndSyncManagerTest.mjs` 6.5–6.6, 7.1–7.3 | 47/47 ✅ (flujo de turno sin suite ESM específica) |
| **06.3** Re-autenticación del mismo técnico ante 401 | `OfflineSyncManager::handleSyncFailure` (`reauth_required`, cola intacta en `PENDING`), `OfflineSyncTrayModal.js` (`offline-reauth-owner`) | `OfflinePwaAssetsAndUiGuardTest.mjs` 3.7 · revisión del flujo `401` | 33/33 ✅ (sin aserción HTTP específica de re-login) |
| **06.4** Volcado automático por baja de cuenta | `OfflineSyncManager::dumpDeactivatedAccountQueue`, `TechnicianOfflineSyncController::dumpDeactivatedAccount`, `PdoOfflineSyncMutationRepository::recordDeactivatedAccountDumpBatch` | `OfflineSyncServiceTest.php` 5.x · `OfflinePwaSyncApiTest.php` 7.1–7.3 | 36/36 · 23/23 ✅ — `clear_local_storage = true` y `DEACTIVATED_ACCOUNT_DUMP` |
| **06.5** Limpieza de la copia local al salir sin pendientes | `AppNavbar::handleLogout` (`clearTechnician`), `OfflineStorage::clearTechnician` | `OfflineStorageAndSyncManagerTest.mjs` 4.2, 7.4–7.5 | 47/47 ✅ |

---

## 2. Requisitos No Funcionales

| RNF | Artefacto | Test / evidencia | Resultado medido |
|---|---|---|---|
| **RNF-01** Carga sin red < 2 s y encolado local < 150 ms | `sw.js` (precache del shell), `OfflineStorage` (`IndexedDB`/respaldo en memoria), `TechnicianRouteView` (hidratación local) | `OfflinePwaAssetsAndUiGuardTest.mjs` 2.1–2.12 · `OfflineStorageAndSyncManagerTest.mjs` | ✅ funcional; **no se dispone de medición cronometrada en dispositivo físico**. La guarda certifica que el shell está completo y que la API nunca se cachea |
| **RNF-02** Cero dependencias externas (Art. IV.3) | `public/sw.js`, `OfflineStorage.js`, `OfflineSyncManager.js`, `OfflineSyncTrayModal.js`; sin `package.json`/Composer en el frontend | `RefundsModuleClosureTest.php` 1.9 · `OfflinePwaAssetsAndUiGuardTest.mjs` | ✅ 60/60 · 33/33 — `src/` solo importa el espacio propio y built-ins; el DTO evita `stdClass` |
| **RNF-03** Sistema de diseño (`docs/design.md`) | `OfflineSyncTrayModal.js`, `TechnicianRouteView.js`, `public/assets/css/design-tokens.css` | `DesignTokenDebtRatchetTest.mjs` · `OfflinePwaAssetsAndUiGuardTest.mjs` 4.1–4.4 | ✅ 3/3 (techo 2.268, contador 2.268) · 33/33 — cero hexadecimales nuevos, 44 px, radios 4/8 px y borde hairline |
| **RNF-04** Dualidad lingüística | Literales de UI, mensajes de `OFFLINE_MESSAGES`, notas de volcado y comentarios de código | `OfflineStorageAndSyncManagerTest.mjs` (mensajes de precinto y cuota) · revisión de literales | ✅ mensajes en castellano neutro; identificadores y códigos en inglés |

---

## 3. Decisiones técnicas y resoluciones QA

| Elemento | Escenario | Artefacto / Test | Resultado |
|---|---|---|---|
| **D1** Frontera de operaciones | 9 operaciones encolables; login, turno, relevo, carga, reclamo libre y oportunistas fuera de ruta exigen red | `OFFLINE_OPERATIONS` · `OfflineStorageAndSyncManagerTest.mjs` 5.4 · `TechnicianRouteView.guardOnlineOnly` | ✅ |
| **D2** Conflictos sin pérdida y doble sello | 409 conserva la operación, aísla la parada y permite volcar; auditoría con `client_occurred_at`, `effective_occurred_at` y `synced_at` | `OfflineStorageAndSyncManagerTest.mjs` 6.1–6.9 · `OfflinePwaSyncApiTest.php` 2.1–3.2, 6.1–6.5 | ✅ |
| **D3** Cuota de evidencias | 5 fotos / 25 MB; firma PNG exenta; formatos seguros y 5 MB por archivo | `OfflineStorageAndSyncManagerTest.mjs` 2.1–2.11 | ✅ |
| **D4** Protección de sesión | Logout bloqueado con cola; re-autenticación del mismo titular; limpieza al salir vacío | `OfflineStorageAndSyncManagerTest.mjs` 7.1–7.5 · `OfflineSyncTrayModal.js` | ✅ |
| **D5** Encadenamiento y stock provisional | Inicio → comentario → resolución; estado local y descuento/reversión de furgoneta | `OfflineStorageAndSyncManagerTest.mjs` 3.1–3.5, 6.3 | ✅ |
| **D6** Vigencia 4 h / 12 h | Aviso ámbar y solo lectura, conservando la cola ya encolada | `OfflineStorageAndSyncManagerTest.mjs` 1.1–1.7 | ✅ |
| **QA 1** Precinto y no descarte | Casilla obligatoria en perecederos; botón único de volcado; sin vía de borrado | `OfflineStorageAndSyncManagerTest.mjs` 5.1–5.5, 6.10–6.12 · `OfflinePwaAssetsAndUiGuardTest.mjs` 3.1–3.5 | ✅ |
| **QA 2** Canceladas/cerradas, preventivas y reintegro | Excepción de auditoría a 48 h, notas en orden preventiva y colisión parcial | `OfflineSyncServiceTest.php` 2.x, 3.x · `OfflinePwaSyncApiTest.php` 4.1–4.2, 6.1–6.5 | ✅ |
| **QA 3** Firma exenta y comentarios independientes | La firma no ocupa cupo; un comentario fallido no bloquea la transición posterior | `OfflineStorageAndSyncManagerTest.mjs` 2.11, 6.3 | ✅ |
| **QA 4** Δt, reclamo en vivo y Albarán diferido | Δt exacto con reloj desajustado; operaciones libres señalizadas; aviso de albarán | `OfflineSyncClockAndQuotaTest.php` 2.1–2.6 · `OfflinePwaSyncApiTest.php` 2.1–2.5 · `OfflinePwaAssetsAndUiGuardTest.mjs` 3.7 | ✅ |
| **QA 5** Turno y baja de cuenta | Sincronización previa al turno; volcado automático y limpieza del dispositivo | `OfflinePwaSyncApiTest.php` 7.1–7.3 · `TechnicianRouteView.ensureOfflineQueueReadyForShift` | ✅ |

---

## 4. Casos límite de la especificación (§6)

| Caso | Cobertura | Resultado |
|---|---|---|
| **§6.1** Cadena cuya primera transición falla frente a comentario intermedio fallido | `OfflineStorageAndSyncManagerTest.mjs` 6.1–6.6 | ✅ |
| **§6.2** Corte de señal en mitad de la sincronización e idempotencia del reintento | `OfflinePwaSyncApiTest.php` 3.1–3.2 · `OfflineSyncServiceTest.php` | ✅ |
| **§6.3** Reloj atrasado 24 h con Δt de 35/25 min | `OfflineSyncClockAndQuotaTest.php` 2.1–2.6 · `OfflinePwaSyncApiTest.php` 2.5 | ✅ Δt exacto |
| **§6.4** Reintegro ya regularizado por Coordinación | `OfflineSyncServiceTest.php` · `OfflinePwaSyncApiTest.php` 4.1–4.2 | ✅ |
| **§6.5** Cuenta desactivada mientras trabajaba sin conexión | `OfflinePwaSyncApiTest.php` 7.1–7.3 · `OfflineSyncServiceTest.php` | ✅ |

---

## 5. Definición de Hecho (§8)

| DoD | Evidencia | Estado |
|---|---|---|
| **1.** Instalación y carga de la interfaz sin red | `OfflinePwaAssetsAndUiGuardTest.mjs` 1.1–2.12 | ✅ funcional; sin medición cronometrada en dispositivo |
| **2.** Copia local por técnico, antigüedad visible y bloqueo a las 12 h | `OfflineStorageAndSyncManagerTest.mjs` 1.1–1.7, 4.1–4.2 · `OfflinePwaSyncApiTest.php` 1.1–1.6 | ✅ |
| **3.** Flujo completo sin conexión con precinto y cuota | `OfflineStorageAndSyncManagerTest.mjs` 2.x, 3.x, 5.x · `OfflinePwaSyncApiTest.php` 2.x | ✅ |
| **4.** Operaciones online-only y Albarán A4 diferido | `TechnicianRouteView.guardOnlineOnly`, `ensureOfflineQueueReadyForShift` · `OfflinePwaAssetsAndUiGuardTest.mjs` 3.7 | ✅ con la frontera declarada en RF-03.2 |
| **5.** FIFO, idempotencia, Δt y cuarentena | `OfflineSyncClockAndQuotaTest.php` 19/19 · `OfflineSyncServiceTest.php` 36/36 · `OfflinePwaSyncApiTest.php` 23/23 | ✅ |
| **6.** Conflictos volcados en 1 toque, sin descarte | `OfflineStorageAndSyncManagerTest.mjs` 6.7–6.12 · `OfflinePwaSyncApiTest.php` 6.1–6.5 | ✅ |
| **7.** Sesión, turno, re-autenticación y baja | `OfflineStorageAndSyncManagerTest.mjs` 7.1–7.5 · `OfflinePwaSyncApiTest.php` 7.1–7.3 | ✅ |
| **8.** Batería global al 100 % | `php tests/run_all.php` | ✅ 366 suites / 13.964 aserciones, 0 fallos |

---

## 6. Certificación global y limitaciones declaradas

**Suites del módulo ejecutadas y en verde:**

| Suite | Aserciones |
|---|---:|
| `OfflineSyncClockAndQuotaTest.php` | 19/19 |
| `OfflineSyncServiceTest.php` | 36/36 |
| `OfflineStorageAndSyncManagerTest.mjs` | 47/47 |
| `OfflinePwaAssetsAndUiGuardTest.mjs` | 33/33 |
| `OfflinePwaSyncApiTest.php` (canal `new_engine`) | 23/23 |
| `PostgresOfflineSyncSchemaTest.php` (canal `new_engine`) | 22/22 |
| `PostgresSchemaTest.php` (canal `new_engine`) | 107/107 |
| `SchemaConvergenceParityTest.php` (canal `new_engine`) | 18/18 |
| `DesignTokenDebtRatchetTest.mjs` | 3/3 |

**Limitaciones declaradas (no se presentan como verdes):**

1. **Carga < 2 s (RNF-01):** no se mide con cronómetro en un dispositivo físico; la guarda certifica el shell completo y la ausencia de caché de API.
2. **Operaciones online-only alojadas en componentes hijos (RF-03.2):** la vista bloquea las que despacha y señaliza las demás; el bloqueo duro dentro de los componentes hijos queda fuera del alcance de T-OFFLINE-12.
3. **Corrida global:** `php tests/run_all.php` cierra con 366 suites / 13.964 aserciones, 0 fallos, cifras del README coherentes y código de salida 0. La guarda 4.9 de `AuditEntityTypesTest.php` se acotó al histórico anterior a la habilitación de los tipos preventivos, que es lo que declara vigilar: el residuo append-only de la batería ya no se confunde con un reetiquetado.
4. **Doble sello temporal:** la suite de integración verifica `effective_occurred_at`; la visualización del sello dual en el visor de auditoría no forma parte de este módulo.

---

## 7. Mantenimiento

Esta matriz es un artefacto vivo. Cualquier cambio en un `RF`, criterio EARS, `RNF`, decisión `D` o resolución `QA` debe actualizar la fila correspondiente y su resultado medido en la misma entrega. Las cifras globales de la batería se declaran en el `README.md`; esta matriz solo conserva las cifras de las suites del Módulo 24.
