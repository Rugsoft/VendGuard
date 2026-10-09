# Verificación de los Criterios de Finalización del Mantenimiento Preventivo

**Fecha:** 2026-10-08 · **Rama:** `incident-comments` · **Pliego:** `specs/functional/preventive_maintenance_spec.md` §8 (Definition of Done)
**Batería:** `php tests/run_all.php` → **batería global: 230 suites · 8.797 aserciones** · 0 fallos · exit 0 · base restaurada a semillas

## 1. Método

Misma regla que el acta del MVP: **sólo se marca la casilla demostrada**, con evidencia ejecutable, medición reproducible o verificación manual registrada. Lo que no se ha demostrado queda sin marcar con su bloqueo nombrado, y no se reescriben cifras de informes históricos ni se debilitan aserciones para cuadrar el acta.

## 2. Evidencia por casilla

| # | Criterio (resumen) | Evidencia verificada | Estado |
| :--- | :--- | :--- | :--- |
| 1 | El 100% de los RF-PREV-01 a RF-PREV-08 implementados y cubiertos por pruebas EARS | Matriz de trazabilidad de `specs/05-preventive-maintenance/plan.md` §7 (requisito → backend → UI → suites) y batería completa en verde | ✅ Marcada |
| 2 | Perecederos con ciclo de 15 días y rechazo de configuraciones superiores (Art. II) | `tests/integration/CoordinatorPreventiveApiTest.php` 8.4 (`PERISHABLE_FOOD` con `max_allowed_days <= 15`) y 9.1 (violación del Art. II → 422) + `tests/unit/PreventiveSettingsServiceTest.php` y `tests/unit/CoordinatorPreventiveSettingsModalTest.mjs` | ✅ Marcada |
| 3 | Checklist exige temperatura en rango físico y > 4.0 °C en perecederos declara `NO_CONFORME` con cuarentena inmediata en el QR | `tests/unit/PreventiveChecklistEvaluationServiceTest.php`, `tests/integration/QrSanitaryQuarantineIntegrationTest.php` y `tests/integration/QrSanitaryModeApiTest.php` | ✅ Marcada |
| 4 | Ante una no conformidad: incidencia correctiva vinculada (CRÍTICA si es térmica) o comentario en la bitácora sin duplicar el ticket, con elevación de urgencia | `tests/unit/PreventiveCoexistenceBridgeServiceTest.php` (Arte. V.1/V.2: un único ticket activo por máquina y escalado de urgencia) | ✅ Marcada |
| 5 | El cierre de la incidencia vinculada exige diagnóstico y solución (≥ 20 caracteres) y dispara la reinspección sanitaria | `tests/integration/ResolveIncidentEndpointTest.php` 2.1 (umbral de 20) y `tests/integration/TechnicianPreventiveApiTest.php` (reinspección tras subsanación) | ✅ Marcada |
| 6 | Semáforo sanitario (verde, amarillo, rojo, cuarentena y pausa estacional) en la ficha de máquina y en el portal de sede | `tests/integration/SiteSanitaryApiTest.php` (estado sanitario por sede y por máquina) + `tests/unit/SiteSanitaryComponentsTest.mjs` + `tests/unit/CoordinatorPreventiveComponentsTest.mjs` | ✅ Marcada |
| 7 | Certificado Sanitario Oficial en A4 (individual y consolidado con dictamen `CONDICIONADO`) con privacidad del técnico por Código de Operador | `tests/unit/SanitaryCertificateServiceTest.php` (emisión y `operator_code`), `tests/integration/SiteSanitaryApiTest.php` (certificado individual y global, ausencia de datos sensibles) y la vista de impresión del servidor con `@page { size: A4 portrait }` / `A4 landscape` + `@media print` (`src/Presentation/Controller/SiteSanitaryController.php:205`, `:221`, `:335`) | ⬜ **No marcada** |
| 8 | Una avería posterior de frío suspende cautelarmente el certificado emitido | `tests/unit/SanitaryCertificateServiceTest.php` (tránsito a `SUSPENDIDO`) y `tests/integration/SiteSanitaryApiTest.php` | ✅ Marcada |
| 9 | Ninguna instrucción de borrado físico en el ciclo preventivo; cancelaciones por traslado o baja con borrado lógico | Medición: `grep -rn "DELETE FROM" src/ \| wc -l` → **0** (2026-10-08) + `tests/unit/PreventiveCoexistenceBridgeServiceTest.php` y `tests/unit/PreventiveOrderSchedulerServiceTest.php` (cancelación lógica `CANCELLED` del ciclo) | ✅ Marcada |
| 10 | Estricta observancia del Dualismo Lingüístico (código, nombres y pruebas en inglés; interfaz, mensajes y certificados en español) | Medición: las tres suites frontend tocadas por esta pasada declaran sus aserciones en inglés (105, 92 y 67 aserciones) mientras la copy de interfaz nueva está en español en las tres superficies (`CoordinatorDashboardView.js`, `CoordinatorTerritorialMapTab.js`, `CoordinatorPreventiveOrdersTab.js`), verificada además en navegador | ✅ Marcada |

## 3. Casilla no marcada y bloqueo

**#7 — Certificado A4.** La emisión, la visualización, el dictamen `CONDICIONADO` del consolidado y la privacidad por Código de Operador están demostrados por las suites citadas, y la maquetación A4 existe como hoja de impresión real (`@page size: A4`, `@media print`, botón «🖨️ Imprimir / Guardar PDF (A4)»). Lo que **no** se ha verificado en esta pasada es el **acto físico de impresión en papel A4** (y su resultado en un dispositivo real), que es la parte literal del criterio.

**Bloqueo:** requiere una impresora o una exportación a PDF revisada por una persona; no es automatizable con la batería actual sin un motor de renderizado de PDF, que sería una dependencia externa no autorizada (AGENTS.md §5.3). Queda por tanto pendiente de verificación manual de cierre.

## 4. Trazabilidad de esta verificación

* Acta redactada junto con el cierre de los pendientes del handoff (`docs/handoff_rama_incident-comments.md` §3.4).
* Las cifras de esta acta están sincronizadas con la batería: cualquier desfase futuro pone en rojo `tests/Support/DocMetricsGuard.php`.
* El umbral y el vocabulario de motivo de reasignación preventiva (EARS 2.6, añadido en esta pasada) quedan cubiertos por `tests/integration/CoordinatorPreventiveApiTest.php` 6.6–6.11 y `tests/unit/CoordinatorPreventiveComponentsTest.mjs` 2.32–2.41.
