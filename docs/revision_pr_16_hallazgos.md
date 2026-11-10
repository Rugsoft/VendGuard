# Revisión de la PR #16 · Hallazgos priorizados

| Campo | Valor |
| :--- | :--- |
| **Proyecto** | Gestor de Incidencias de Vending (*VendGuard*) |
| **PR** | [#16 · feat(sla): perfiles de SLA diferenciados por tipo de sede y sellado contractual (Módulo 30)](https://github.com/Rugsoft/VendGuard/pull/16) |
| **Rama origen** | `tiered-sla-contracts` |
| **Rama destino** | `main` |
| **Commit revisado** | `ae8c64af732f4285fd0a79c7678b7b0908a51dac` (remoto) · 4 commits locales pendientes |
| **Fecha de revisión** | 2026-11-10 |
| **Estado de la PR** | abierta, `MERGEABLE` / `CLEAN`, sin checks de CI asociados. |

---

## Veredicto

La PR remota está sincronizada con su rama y no tiene conflictos; la rama local suma 4 commits pendientes de publicación. Los hallazgos funcionales 1 y 2 y la contradicción documental 4 están corregidos; las cifras y el recuento de commits se han unificado. Quedan la batería global (hallazgo 3) y la limpieza de espacios finales (hallazgo 5).

> **Actualización (2026-11-10):** los hallazgos 1, 2 y 4 quedan corregidos en esta rama. El alta y la elevación del puente sellan el perfil, los minutos y el vencimiento; H-04 fija `DATABASE_URL` desde `PG_TEST_DSN` antes del bootstrap y pasa 34/34 con solo ese contrato. El recuento de commits se alinea en 21 para la PR remota (4 commits locales pendientes de publicación) y las cifras de H-04 se unifican en la corrida del 2026-11-10: 97,0 / 160,9 / 162,3 ms. Las regresiones del puente están en `tests/unit/PreventiveCoexistenceBridgeServiceTest.php` (60 aserciones).

---

## Resumen de hallazgos

| # | Severidad | Hallazgo | Estado |
| :--- | :--- | :--- | :--- |
| 1 | **Alta** | El puente preventivo no sella el SLA y degrada objetivos existentes. | Corregido en código; pendiente de revisión |
| 2 | **Alta/media** | La suite H-04 no es reproducible con el comando que documenta. | Corregido en código; verificado |
| 3 | **Media** | La batería global no pudo completarse por el estado del banco TLS local. | Limitación de entorno |
| 4 | **Baja** | La auditoría se contradice sobre el estado de H-05/H-06. | Corregido en documentación |
| 5 | **Baja** | Cifras, recuento de commits y `git diff --check` desactualizados. | Cifras y recuento corregidos; espacios finales pendientes |

---

## 1. Alta · El puente preventivo no sella el SLA y degrada objetivos existentes

**Archivo:** `src/Application/Service/PreventiveCoexistenceBridgeService.php`

**Estado:** corregido. El alta resuelve el perfil de la sede y sella `sla_tier_snapshot`, `sla_target_minutes` y `sla_target_at`; la elevación conserva el perfil y recalcula el objetivo sin reiniciar el reloj ni relajarlo.

El puente de coexistencia entre inspección preventiva e incidencia correctiva es un canal de apertura real: lo invoca `TechnicianPreventiveController` cuando una revisión detecta una no conformidad. La PR no lo incluye en el sellado contractual del Módulo 30.

### Caso A · Alta de un correctivo nuevo

En la línea ~154 se construye un `Incident` sin `slaTierSnapshot`, `slaTargetMinutes` ni `slaTargetAt`:

```php
$newIncident = new Incident(
    id: null,
    ticketCode: $ticketCode,
    machineId: $machineId,
    locationId: $locationId,
    category: $category,
    description: $description,
    urgency: $urgency,
    status: IncidentStatus::IN_PROGRESS,
    assignedTechnicianId: $technicianId,
    reporterName: 'Sistema Preventivo (VendGuard)',
    reporterPhone: '000000000',
    assignedAt: date('Y-m-d H:i:s'),
    startedAt: date('Y-m-d H:i:s')
);
```

El constructor de `Incident` aplica los valores por defecto `SlaTier::STANDARD` y **1440 minutos**, y deja `slaTargetAt` nulo (`src/Core/Domain/Model/Incident.php:245-252`). Para una máquina `PERISHABLE_FOOD` o un `COMBO` con rotura de frío, el servicio calcula urgencia `CRITICAL`, pero el expediente nace con **24 horas en lugar del techo sanitario de 240 minutos**. Incumple RF-01.2 y RF-02.1.

### Caso B · Elevación de urgencia sobre un ticket activo

En la línea ~263 se reconstruye el `Incident` para elevarlo a `CRITICAL` sin copiar los campos de SLA. `PdoIncidentRepository::update()` escribe `sla_target_minutes` (`PdoIncidentRepository.php:2853`) y `sla_target_at` (`:2851`), de modo que:

- `sla_target_minutes` vuelve al valor por defecto **1440**.
- `sla_target_at` conserva el vencimiento anterior.
- El expediente queda con un vencimiento de 4/8/12 horas y un objetivo declarado de 24 horas.

El `UPDATE` no toca `sla_tier_snapshot`, así que el disparador de inmutabilidad no salta; la degradación es silenciosa. Las métricas de brecha y la prelación de ruta pasan a medir mal.

### Cobertura

`TicketCodeRaceRetryTest` ejercita el puente, pero no verifica el SLA. No hay una regresión que detecte ninguno de los dos casos.

### Corrección esperada

- Resolver el perfil de la sede y la matriz RF-01 en el puente, igual que en Portal, QR, Oportunista y Coordinación.
- Pasar `slaTierSnapshot`, `slaTargetMinutes` y `slaTargetAt` al alta.
- En el caso B, preservar el perfil y recalcular el objetivo con `withRecalculatedSlaTarget()` y `SlaTargetCalculator`, sin reiniciar `created_at`.
- Añadir tests con máquina perecedera y con ticket prioritario existente.

---

## 2. Alta/media · La suite H-04 no es reproducible con el comando que documenta

**Archivo:** `tests/integration/PostgresTieredSlaMetricsPerformanceTest.php`

**Estado:** corregido. La suite fija `DATABASE_URL` desde `PG_TEST_DSN` antes de cargar el bootstrap y descarta la instancia previa de `ConnectionFactory`, de modo que el fixture, el router y la conexión compartida usan el motor declarado. Verificada con solo `PG_TEST_DSN`: **34/34** y canal `new_engine` **1/1**.

La cabecera de la suite declara que usa `PG_TEST_DSN` y recomienda:

```bash
source deploy/local/postgres/.env.local && php tests/integration/PostgresTieredSlaMetricsPerformanceTest.php
```

Sin embargo:

- `tests/bootstrap.php:44-52` carga `deploy/local/postgres/.env.battery` y define `DATABASE_URL` cuando el proceso no lo trae.
- `ConnectionFactory` resuelve `DATABASE_URL` antes que cualquier otra fuente.
- El fixture y la API comparten el PDO de `ConnectionFactory`, por lo que la suite se ejecuta realmente contra el banco de la batería, no contra `PG_TEST_DSN`.
- `PG_TEST_DSN` solo actúa como puerta de presencia al principio (`PostgresTieredSlaMetricsPerformanceTest.php:95`).

Con solo `.env.local`, el runner `new_engine` falló con:

```text
[NO EJECUTABLE] PostgresTieredSlaMetricsPerformanceTest.php · la suite declara otro contrato de entorno
[CATEGORÍA NO EJECUTABLE] El motor nuevo no responde:
SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 55434 failed: Connection refused
```

La suite pasó **34/34** cuando fijé `DATABASE_URL` y `PG_TEST_DSN` a la **misma** base limpia. La lógica de medición es correcta; el contrato de entorno y el comando no. En un entorno donde `.env.battery` apunte a otra base o esté caído, la certificación falla o mide un motor distinto del declarado.

### Corrección esperada

- Usar un PDO explícito desde `PG_TEST_DSN` para el fixture y para `ConnectionFactory`, o derivar `DATABASE_URL` de `PG_TEST_DSN` dentro de la suite.
- Actualizar el comando de la cabecera y la documentación del canal `new_engine`.
- Verificar que la suite falla de forma explícita si ambos contratos apuntan a motores distintos.

---

## 3. Media · La batería global no pudo completarse por el estado del banco TLS local

**No es un defecto de código de la PR**, pero impide declarar la verificación completa.

- El contenedor `vendguard-pg-tls-opp16` (puerto 55434, el que usaba `.env.battery`) estaba caído.
- El contenedor `vendguard-pg-tls` (puerto 55433) tiene un volumen antiguo y `provision-tls-engine.sh` falla al inicializar porque la migración **025** ya aplicada tiene una huella distinta a la actual:

```text
La migración «025_sanitary_spoilage_records.sql» ya estaba aplicada y su contenido ha cambiado
(huella 8b96b29884447c1527ebd023782311a8fc822e688efa74dea79b6d21e479c328
≠ 1335de1d87d896cf22c8892a3610ad00b867760bdda762a6c675640418e7ec5f)
```

Para aislar el problema creé una base limpia `vendguard_pr16` en el contenedor TLS de 55433. La inicialización aplicó correctamente las **26 migraciones** y el catálogo maestro (10 sedes, 13 máquinas, 6 usuarios).

La corrida global de `run_all.php` sobre esa base pasó muchas suites, pero se cortó a los ~295 s sin marcador de cierre. **No se cuenta como verde**: no hay resumen final ni código de salida. No se identificó una suite fallida; la ejecución no terminó.

### Corrección esperada

- Reconciliar o recrear el volumen TLS local para que la migración 025 tenga la huella vigente.
- Repetir `php tests/run_all.php` con `DATABASE_URL` apuntando al banco de la batería y dejar el resumen completo.
- Mantener `vendguard_pr16` como banco aislado si se quiere evitar tocar el volumen antiguo.

---

## 4. Baja · La auditoría se contradice sobre el estado de H-05/H-06

**Archivo:** `docs/auditoria_modulo_30_hallazgos.md`

**Estado:** corregido: la línea 300 ya remite al cierre de H-05 y H-06.

- Línea 10: la Spec 30 está **100 % cumplida** y H-01 a H-06 quedaron resueltos.
- Línea 300: «La Spec 30 sigue pendiente de H-05 y H-06.»

La segunda frase es residuo de una versión anterior y contradice el veredicto que la propia PR presenta como evidencia de cierre.

### Corrección esperada

Sustituir la línea 300 por una referencia al cierre de H-05 y H-06, o eliminarla si el párrafo ya no aporta.

---

## 5. Baja · Cifras, recuento de commits y `git diff --check` desactualizados

**Estado:** cifras y recuento corregidos; espacios finales pendientes.

- El cuerpo de la PR declaraba **22 commits**; la PR remota tiene **21** y la rama local suma **4** pendientes de publicación. El cuerpo ya publica 21.
- Cifra canónica H-04: **97,0 / 160,9 / 162,3 ms** (summary, breakdown, export; corrida del 2026-11-10 con `PG_TEST_DSN`), con p95 **100,4 / 166,1 / 168,9 ms**. Está unificada en el cuerpo de la PR, la auditoría y la matriz de trazabilidad.
- `git diff --check origin/main...origin/tiered-sla-contracts` sigue detectando espacios finales en Markdown, casi todos saltos de línea intencionales con dos espacios. Si el proyecto usa ese check como puerta, hay que limpiarlos o documentar la convención.

### Corrección esperada

- ~~Unificar el recuento de commits y las medianas en el cuerpo de la PR y en la auditoría.~~ Corregido.
- Limpiar los espacios finales o documentar la convención Markdown si son intencionales.

---

## Comprobaciones ejecutadas

| Comprobación | Resultado |
| :--- | :--- |
| `gh pr view 16` | `OPEN`, `MERGEABLE`, `CLEAN`, base `main`, head `tiered-sla-contracts`. |
| Rama local vs `origin/tiered-sla-contracts` | 4 commits locales por delante; árbol limpio. |
| PHP lint de los 92 archivos PHP modificados | Sin errores. |
| `PostgresTieredSlaMetricsPerformanceTest` | **34/34**, exit 0 (solo `PG_TEST_DSN`; la suite alinea `DATABASE_URL`). |
| `TieredSlaDomainTest` | **66/66**, exit 0. |
| `TieredSlaFrontendTest.mjs` | **47/47**, exit 0. |
| `IncidentPauseServiceSlaShiftTest` | **28/28**, exit 0. |
| `PostgresTieredSlaSchemaTest` | **17/17**, exit 0. |
| `PostgresTieredSlaBackfillTest` | **80/80**, exit 0. |
| `TieredSlaContractsApiTest` | **45/45**, exit 0. |
| Inicialización de `vendguard_pr16` | 26 migraciones + semillas; 10 sedes, 13 máquinas, 6 usuarios. |
| Batería global `run_all.php` | Incompleta: cortada a los ~295 s sin resumen final. |
| Secretos, `.env`, claves o volcados en el diff | Ninguno. |
| `var_dump` / `print_r` / depuración añadida | Ninguno. |

---

## Recomendación

1. ~~Corregir el sellado del puente preventivo y añadir sus regresiones.~~ Corregido en esta rama.
2. ~~Alinear la suite H-04 con `PG_TEST_DSN` y su comando de ejecución.~~ Corregido en esta rama.
3. Repetir la batería global completa sobre un banco TLS sano.
4. ~~Corregir la contradicción H-05/H-06 y unificar las cifras documentales.~~ Corregido en esta rama.
5. Limpiar los espacios finales señalados por `git diff --check`.
