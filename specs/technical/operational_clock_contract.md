# CONTRATO DEL RELOJ OPERATIVO Y DE LA MARCA DE SEDE

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)
**Documento:** `specs/technical/operational_clock_contract.md`
**Módulo:** `13-single-engine-supabase` · **Tarea de origen:** T-13-11 (lote de comentarios, pausas y reloj sanitario)
**Requisitos:** EARS-13.5, EARS-13.6 y EARS-13.10 (**[`spec.md`](../13-single-engine-supabase/spec.md)**)
**Normativa:** [`constitution.md`](../../constitution.md) Art. I.3 · [`AGENTS.md`](../../AGENTS.md) §4 y §5
**Contratos hermanos:** [`single_engine_dialect_contract.md`](single_engine_dialect_contract.md) (el dialecto) · [`refunds_contracts.md`](refunds_contracts.md) §1.4 (la misma regla, decidida en T-13-10) · [`schema_correspondence_contract.md`](schema_correspondence_contract.md) **D-2** (`DATETIME`/`TIMESTAMP` → `TIMESTAMPTZ`)
**Guarda:** [`tests/unit/HarnessClockContractTest.php`](../../tests/unit/HarnessClockContractTest.php) (el censo y su ratchet)
**Reloj del arnés:** [`tests/Support/EngineClock.php`](../../tests/Support/EngineClock.php)

---

## 1. La regla

Con un solo motor, **una marca temporal es un instante**, y la hora de la jornada —la *pared de sede*— es una
**lectura** de ese instante en la zona operativa, nunca una segunda forma de escribirlo.

1. **Al escribir**, el instante cruza **anclado**: con su desfase (`Y-m-d\TH:i:sP`, p. ej. `2026-10-08T13:45:00+02:00`).
   Las columnas del esquema son `timestamptz` (**D-2**) y el motor interpreta **en la zona de su sesión** (UTC) un
   `'Y-m-d H:i:s'` desnudo: la pared desnuda guarda un instante que depende del `php.ini` del despliegue, no del
   que el servicio quiso escribir.
2. **Al leer**, la marca llega del motor **con su desfase**, y quien la compare la compara **como instante**
   (`strtotime()`, `DateTimeImmutable`), nunca como texto: dos marcas del mismo instante se ordenan al revés como
   cadenas.
3. **Al razonar**, la ventana comercial de la sede (08:00 a 18:00, lunes a viernes) se aplica **sobre la zona
   operativa** `Europe/Madrid`: el instante se convierte a esa zona antes de contar y el vencimiento resultante
   vuelve anclado en ella.
4. **Al publicar**, cada superficie elige la forma que su consumidor necesita, y sólo una de las dos es legible
   para él: **pared de sede** si lo que se documenta es la jornada contractual (vencimiento de SLA, notas y
   fichas del operador) e **instante anclado** si lo que se compara es contra «ahora» (marcas de intervalo,
   candados y ventanas). La pared publicada es siempre la conversión del instante persistido a la zona operativa.

La forma **sin zona** sigue siendo válida como *lectura* —es la pared que sembró el motor retirado y la que
sembraba la batería—, pero **no se admite escribirla** en una columna con zona.

---

## 2. Qué decide este contrato, y por qué hacía falta decidirlo

El arnés declaró su reloj en la zona operativa (EARS-13.5/13.6) y con ello quedó a la vista una frontera que el
motor retirado ocultaba: **la aplicación nunca declaró zona**, de modo que un `new DateTimeImmutable()` desnudo
tomaba la del `php.ini` —UTC en el contenedor— y la pared que escribía coincidía *por casualidad* con la zona de
la sesión del motor. Bajo el arnés esa coincidencia se rompe, y con ella se midió la magnitud del defecto:

| Marca | Qué se escribía | Qué guardaba el motor | Efecto medido |
| :--- | :--- | :--- | :--- |
| `incidents.paused_at` | pared desnuda renderizada en la zona del proceso | el instante, el huso entero por delante | la espera de sede y el umbral de inactividad se medían **dos horas cortos** |
| `incidents.sla_target_at` | pared desnuda del vencimiento **ya resuelto en `Europe/Madrid`** | el instante, el huso por delante | el vencimiento persistido no era el que el calendario calculó, y cada desplazamiento posterior partía de una hora contaminada |

La decisión que estaba pendiente —el [`EngineClock`](../../tests/Support/EngineClock.php) la nombra en su propio
docblock y las actas de T-13-07 a T-13-10 la adjudicaron a este lote— era **si el dominio sigue escribiendo pared
de sede o pasa a escribir instantes**. La respuesta es la de §1: **instantes anclados**, con la pared de sede como
lectura publicada.

---

## 3. Dónde vive cada mitad de la regla

| Punto | Qué hace | Dónde |
| :--- | :--- | :--- |
| **Ancla del intervalo de pausa** | `paused_at` se graba con el desfase de la zona que declaró la pausa | [`Incident::pausePendingInfo()`](../../src/Core/Domain/Model/Incident.php) |
| **Ancla del vencimiento** | `sla_target_at` se graba con el desfase de la zona en que el calendario lo resolvió | [`Incident::shiftSlaTarget()`](../../src/Core/Domain/Model/Incident.php) |
| **Lectura del vencimiento** | la marca del motor se compara como instante y se convierte a la zona operativa antes de aplicar la ventana comercial | [`IncidentPauseService::shiftSlaTargetInBusinessHours()`](../../src/Application/Service/IncidentPauseService.php) y `pauseBusinessSecondsSince()` |
| **Anuncio del vencimiento** | la respuesta de la pausa y la nota inmutable documentan la **pared de sede** del instante | `IncidentPauseService::siteWall()` y `closePauseInterval()` |
| **Anuncio de la marca de intervalo** | `paused_at` viaja **con su desfase**, que es como lo devuelve el motor y como lo lee el consumidor que mide minutos de espera | [`IncidentPauseService`](../../src/Application/Service/IncidentPauseService.php) y los bloques de detalle |
| **Siembra y expectativa del arnés** | la batería siembra con el reloj del motor (`EngineClock`) y mide instantes; el censo de paredes locales queda congelado en el ratchet de la guarda | [`EngineClock`](../../tests/Support/EngineClock.php) y `HarnessClockContractTest` |

**Lo que este contrato no admite:** una pared desnuda **escrita** en una columna con zona, ni una comparación
**lexicográfica** entre dos marcas temporales del motor (medida y reparada en T-13-10 para el candado del PIN), ni
una expectativa de suite que fije la forma de pared de una marca que el motor publica con desfase.

---

## 4. Evidencia medida (2026-10-16, motor único PostgreSQL)

**Censo del arnés** —`HarnessClockContractTest` §4— sobre los ficheros de prueba con conexión viva al motor:

| Estado | Sitios | Ficheros | Naturaleza declarada |
| :--- | ---: | ---: | :--- |
| Línea base del lote (cierre de T-13-10) | **15** | **7** | deuda del contrato del vencimiento de sede |
| Cierre del lote (T-13-11) | **4** | **4** | **pared de sede publicada** (sin dueño: no hay tarea que la cierre) |

Los cuatro sitios que quedan **no son deuda**: son la mitad de *lectura* de la misma regla. Las cinco suites y el
diagnóstico que sembraban una pared local para el contrato del vencimiento y del libro de pausas pasan a sembrar
**anclado** y a medir **instantes**; lo que sobrevive es la expectativa que compara la respuesta con la **hora de
la jornada** que §4 fija como forma publicada: el vencimiento comercial de `PendingInfoApiTest` y de sus dos suites
gemelas de endpoint —la marca que esas suites **siembran anclada** y leen después como expectativa de la
respuesta—, y el vencimiento del viernes de la sonda de humo del módulo 11. Una entrada de este ratchet vuelve a
ser deuda en cuanto un lote repara la mitad que falta: su naturaleza se declara **por entrada**, y el ratchet no
admite una entrada sin naturaleza, sin motivo o sin dueño.

**Magnitud del defecto, medida contra el motor:** el censo de la guarda (§2 de `HarnessClockContractTest`) mide
un render de pared local contra el motor: la marca queda exactamente `offset` segundos **en el futuro** —el huso
operativo entero, 7.200 s con el horario de verano— y el motor mide esa antigüedad como un valor negativo. Es el
defecto que desplazaba «hace 49 horas» a 47 y el que impedía que el reloj biológico de 4 h disparara la
cuarentena de una avería sembrada «hace 4 h 05 min».

**Corrida completa** (`php tests/run_all.php`): se anota al cerrar la tarea en
[`tasks.md`](../13-single-engine-supabase/tasks.md), junto con el parque canónico, el banco restablecido y el
censo de las clases drenadas de este lote.

---

## 5. Lo que este contrato NO decide

* **Los renders de pared del resto de la aplicación.** El censo de la clase sobre `src` es más ancho que este lote
  (inspecciones, códigos de acceso, semillas, filtros de métricas y marcas de repuestos escriben todavía con
  `date('Y-m-d H:i:s')`): la regla de §1 los alcanza a todos, pero cada uno se mide y se porta en su lote —el de
  métricas y despliegue, T-13-13, y el de la retirada del motor heredado, T-13-14/T-13-16—. Repararlos aquí
  habría mezclado dos clases y ocultado cuál se estaba midiendo (EARS-13.10).
* **La zona declarada del despliegue.** La corrección de §1 es independiente de la zona del proceso; declarar
  `date.timezone` en el contenedor es una decisión de arranque (T-13-15), no una condición de esta regla.
* **El contrato de calendario de la sede** (ventana 08:00-18:00, festivos, calendarios por sede): pertenece a
  [`pending_info_sla_pause_spec.md`](../functional/pending_info_sla_pause_spec.md) y a
  [`11-pending-info-sla-pause/plan.md`](../11-pending-info-sla-pause/plan.md).
* **Las cifras del README y de los informes de cierre**, que se actualizan de una vez en T-13-13.

---

## 6. Cómo se mide y se reproduce

```bash
# La guarda del reloj: la declaración, la sesión del motor, la magnitud del defecto y el censo con su ratchet.
php tests/unit/HarnessClockContractTest.php

# La corrida completa (la guarda va dentro, como suite unitaria):
php tests/run_all.php
```

La guarda imprime el saldo de la clase —fichero, sitios y dueño de cada entrada del ratchet— de modo que la
evidencia de este contrato se puede volver a leer sin depender de ningún instrumento externo al repositorio.
