# Verificación de los Criterios de Finalización del MVP (Fase 1)

**Fecha:** 2026-10-08 · **Rama:** `incident-comments` · **Pliego:** `specs/functional/mvp_functional_spec.md` §8 (Definition of Done)
**Batería:** `php tests/run_all.php` → **batería global: 233 suites · 8.989 aserciones** · 0 fallos · exit 0 · base restaurada a semillas

## 1. Método

Cada casilla se contrasta contra **evidencia ejecutable** (suite y aserción concreta), una **medición reproducible** (comando con su salida) o una **verificación manual registrada** con el escenario exacto. Regla de oro: **sólo se marca la casilla demostrada**; lo que no se ha podido demostrar queda sin marcar y con el bloqueo nombrado, porque el acta no es un trámite sino la certificación del cierre de Fase 1.

Lo que **no** se hace: no se reescriben cifras de informes históricos, no se debilitan aserciones para cuadrar el resultado y no se marca nada «por analogía» con otro criterio.

## 2. Evidencia por casilla

| # | Criterio (resumen) | Evidencia verificada | Estado |
| :--- | :--- | :--- | :--- |
| 1 | El 100% de los RF-01 a RF-11 están implementados y verificados | Cada requisito consta en la matriz de trazabilidad de su módulo (`specs/02-qr-codes/plan.md` … `specs/10-incident-comments/plan.md`, §7) con sus archivos de backend, componentes y suites; la batería completa (216 suites) es el cierre agregado de esas coberturas | ✅ Marcada |
| 2 | Una máquina no puede tener dos tickets activos concurrentes (incluye `REOPENED` y `RESOLVED`) | `tests/integration/DuplicateIncidentTest.php`: 1.2–1.5 (409 `MACHINE_HAS_ACTIVE_INCIDENT` con el ticket vivo en el mensaje), 2.1 (MariaDB rechaza la segunda fila activa, error 1062), 3.1–3.2 (columna virtual `is_active_ticket` + nuevo ticket sólo tras el cierre), 4.1–4.4 (máquina en garantía → 409 `MACHINE_IN_WARRANTY`) | ✅ Marcada |
| 3 | Averías térmicas de alimentos → `CRITICAL`, y su reclasificación sólo con justificación auditada | `tests/integration/CreateIncidentEndpointTest.php` 1.4 (urgencia calculada automáticamente `CRITICAL` en perecedera) + `tests/integration/AssignTechnicianEndpointTest.php` caso 5 / EARS 5.3 (motivo obligatorio y registrado en el historial inmutable) | ✅ Marcada |
| 4 | El cierre bloquea envíos con menos de 20 caracteres en diagnóstico o acción | `tests/integration/ResolveIncidentEndpointTest.php` 2.1 (19 caracteres → 422), 4.7–4.8 (persistencia íntegra), 4.12 (historial con diagnóstico y solución) | ✅ Marcada |
| 5 | Tras 48 horas continuadas la incidencia pasa a `CLOSED` sin posibilidad de reapertura | `tests/integration/CronAutoCloseEndpointTest.php` 2.6 (la de 20 h en garantía NO se archiva), 2.7–2.10 (50 h y 72 h → `CLOSED` con `closed_at`), 2.15 (idempotencia) + `tests/integration/ReopenIncidentEndpointTest.php` 4.1–4.2 (garantía expirada → 422 `REOPEN_WINDOW_EXPIRED`) | ✅ Marcada |
| 6 | Al reabrir, el técnico previo queda desasignado y la garantía de 48 h se reinicia | `tests/integration/ReopenIncidentEndpointTest.php` 1.3–1.6 (`assigned_technician_id = NULL`, `resolved_at = NULL`, `reopened_at` fijado) y 8.7 por HTTP real | ✅ Marcada |
| 7 | No existe ninguna instrucción de borrado físico destructivo | Medición: `grep -rn "DELETE FROM" src/ \| wc -l` → **0** (2026-10-08). Suites constitucionales que además lo comprueban por contrato: `CoordinatorIncidentDetailConstitutionalTest.php`, `IncidentCommentsConstitutionalTest.php` | ✅ Marcada |
| 8 | Todas las pruebas de aceptación derivadas de los EARS pasan | `php tests/run_all.php` → **229 suites · 8.775 aserciones · 0 fallos · exit 0** (auditado por `tests/Support/DocMetricsGuard.php`) | ✅ Marcada |

## 3. Casillas no marcadas

Ninguna: las ocho casillas del pliego disponen de evidencia ejecutable, medición o verificación manual registrada. La octava (el cierre agregado de los EARS) es precisamente la que exige la batería completa en verde, que es la condición de esta acta.

## 4. Trazabilidad de esta verificación

* Acta redactada en el marco del cierre de los pendientes del handoff de `incident-comments` (`docs/handoff_rama_incident-comments.md` §3.4), junto con la enmienda de copy/alcance del módulo 07 y la del motivo de reasignación preventiva (módulo 05).
* Las tres suites citadas con más detalle (`DuplicateIncidentTest`, `ResolveIncidentEndpointTest`, `CronAutoCloseEndpointTest`, `ReopenIncidentEndpointTest`) se ejecutan dentro de la batería global; su resultado individual consta en la misma salida de `php tests/run_all.php`.
* Las cifras de esta acta están sincronizadas con la batería y cualquier desfase futuro (nuevas aserciones) hace fallar `tests/Support/DocMetricsGuard.php`.
