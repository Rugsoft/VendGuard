# PROPUESTA · POLÍTICA DE REINTENTO DEL BARRIDO SANITARIO ANTE CONTENCIÓN DE FILA
**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)
**Documento:** `docs/propuesta_politica_reintento_barrido_sanitario.md`
**Tarea de origen:** T-PAUSE-39 (RF-03.5.1, RF-03.5.2, Constitución Art. II y Art. III)
**Estado:** **Propuesta — la parte de reintento queda pendiente de ratificación del Product Owner.** Lo que ya se implementó está marcado como tal en §1.
**Documentos relacionados:** [api_contracts.md](../specs/technical/api_contracts.md) §6.2 · [plan.md](../specs/11-pending-info-sla-pause/plan.md) §2.5 y §2.5.1 · [pending_info_sla_pause_spec.md](../specs/functional/pending_info_sla_pause_spec.md)

---

## 1. Lo que ya está implementado (T-PAUSE-39)

El barrido sanitario comparte la fila de la máquina con el cierre técnico y con cualquier otro escritor del parque. Hasta ahora, un candidato que perdía el bloqueo se contaba como **fallo**: la pasada terminaba en rojo y el planificador alertaba en cada solape legítimo, con lo que el aviso dejaba de distinguirse de un problema real.

Lo entregado, sin cambiar ni una regla de negocio:

1. **La contención tiene su propia clave.** El resumen publica `contended` / `contended_count` con el mismo expediente que publicaba `failures` (`incident_id`, `ticket_code`, `reason`).
2. **Clasificación estricta y fail-safe.** Sólo se aplaza con un **código estructurado de bloqueo**: `errorInfo[1]` ∈ {`1205` (lock wait timeout), `1213` (deadlock)} o `SQLSTATE 40001` / `40P01`. Cualquier otro error de persistencia —tabla ausente, columna inexistente, conexión caída— sigue siendo **fallo real y alerta**, aunque su mensaje se parezca al de un bloqueo. En la duda, alerta.
3. **Una sola regla de alerta.** `SanitaryClockSweepResultDto::requiresOperatorAttention()` es la única autoridad sobre qué enciende al planificador (`true` sólo con fallos reales) y el CLI deriva de ahí su código de salida: `0` sin fallos reales (con o sin contención), `1` con fallos reales.
4. **Identidad contable verificada.** `candidates = evaluated + failures_count + contended_count`, comprobada en el constructor del DTO: un resumen que no cuadra no llega al planificador.
5. **La máquina aplazada no se toca.** Un candidato aplazado no escribe estado sanitario ni deja rastro de auditoría: el único consumidor que escribe sigue siendo el que ganó el bloqueo.

**Evidencia ejecutable (2026-10-12):** `php tests/unit/SanitaryClockSweepServiceTest.php` → **35 aserciones** (grupo 6 «contención de fila y fallo real»: `1205` aplazado sin alerta, deadlock `1213`/`40001` aplazado, tabla ausente en `failures` pese a un texto que imita un bloqueo, las dos naturalezas conviviendo en la misma pasada, DTO que rechaza el resumen incoherente y veinte aplazamientos sin alerta). `php tests/integration/SanitaryClockSweepEndpointTest.php` → **33 aserciones**. `php tests/integration/SanitaryClockConsumerRaceTest.php` → **24 aserciones** (grupo 3 reclasificado: la transición del barrido muere en el `1205` real y se publica como aplazamiento, con `failures_count = 0` y la identidad contable cuadrando sobre la respuesta HTTP real).

---

## 2. Qué es —y qué no es— la contención aquí

| | Contención de fila (`contended`) | Fallo real (`failures`) |
| :--- | :--- | :--- |
| **Qué significa** | Otro consumidor sostiene el bloqueo de esa máquina **ahora mismo** | La decisión no se pudo tomar o la escritura no se pudo persistir |
| **¿Hay problema?** | No: es la prueba de que alguien está actuando sobre la fila | Sí: algo exige intervención humana |
| **Estado de la máquina** | Intacta (el que gana escribe su cuarentena y su rastro) | Intacta, y probablemente seguirá publicada como operativa |
| **Alerta del planificador** | No | Sí (`exit 1`) |
| **Qué lo resuelve** | La pasada siguiente, o el reintento de §4 | Diagnóstico y corrección |
| **Riesgo si se ignora** | Hasta una cadencia de latencia añadida | Máquina con riesgo sanitario sin cuarentena y sin que nadie lo sepa |

La contención del barrido **no puede duplicar** nada: la transición del reloj es un compare-and-set idempotente y el rastro inmutable es único por máquina. Está certificado con **dos conexiones PDO reales** y los dos consumidores por sus caminos de producción en `SanitaryClockConsumerRaceTest` (carrera en los dos sentidos, contención por fila con `1205` real y un solo `SANITARY_QUARANTINE_AUTO_TRIGGERED` por máquina).

---

## 3. La decisión abierta

El barrido **ya no teñirá de rojo la pasada por contención**, pero sigue sin responder a una pregunta operativa: *cuando un candidato queda aplazado, ¿qué debe hacer el sistema —y qué debe hacer el planificador— para que la máquina no se quede publicada como operativa más de lo necesario?* Esta propuesta la responde en cuatro capas, de la más barata a la que requiere decisión.

---

## 4. Capas de la política propuesta

### Capa A — Red de seguridad por cadencia (**ya operativa, sin coste**)

El prefiltro del repositorio vuelve a publicar al candidato en la pasada siguiente **mientras siga vencido y sin cuarentena**: la contención no lo excluye. La pérdida máxima es, por tanto, **una cadencia** (≤ 15 min), y el criterio de contrato de RF-03.5 pasa a leerse «4 h naturales + hasta 15 min, + una cadencia si otro consumidor sostiene la fila» (ya registrado en `spec.md` y en el spec funcional).

*Qué falta para que esto baste:* nada de código. Es el mínimo entregado y ya está en producción de la rama.

### Capa B — Reintento acotado dentro de la pasada (**recomendada · pendiente**)

**Qué:** cuando un candidato se aplaza, el barrido lo reintenta en la misma pasada, con espera creciente y jitter, hasta **1 + 2 intentos**. Cada reintento es una **unidad de trabajo nueva** y una **relectura fresca del reloj**: no reutiliza la lectura ni el estado de la intentona anterior.

| Parámetro | Valor recomendado | Por qué |
| :--- | :--- | :--- |
| Intentos por candidato | **3** (1 + 2 reintentos) | El tenedor es una transacción corta (compare-and-set + `INSERT` de auditoría); dos reintentos cubren el solape real sin convertir la pasada en una espera |
| Espera entre intentos | **50 ms** y **200 ms** (+ jitter aleatorio de 0–50 %) | Creciente y con jitter para que dos barridos solapados no reintenten al unísono |
| Coste máximo añadido | **≤ 0,5 s por candidato aplazado** | Acota la pasada: con la cota de 500 candidatos el peor caso teórico sigue siendo de minutos, no de horas |
| Qué pasa al agotar los intentos | El candidato se publica en `contended` **como hoy** | El aplazamiento residual sigue siendo visible y no alerta |

**Por qué es seguro:** perder la carrera no abre ninguna puerta y ganarla dos veces no duplica nada. Si el reintento llega **después** de que el otro consumidor confirme, el reloj encuentra la cuarentena ya escrita, su compare-and-set responde «no es mío» y el candidato se resuelve como `skipped` —**sin segundo rastro**—; es exactamente el comportamiento ya certificado en `SanitaryClockConsumerRaceTest` (1.4/1.5 en el lado del cierre, 3.6 en el del barrido).

**Qué mejora:** la máquina que hoy esperaría hasta 15 minutos vuelve a la cuarentena en la misma pasada en la inmensa mayoría de los solapes, con lo que el límite efectivo de detección se queda donde estaba («4 h + hasta 15 min») en vez de duplicarse.

### Capa C — Cota del tiempo de espera de bloqueo (**decidida con medición · implementada en T-PAUSE-40**)

Con el valor de servidor de `innodb_lock_wait_timeout` (**50 s** por defecto en MariaDB; medido en el entorno del proyecto: `SELECT @@innodb_lock_wait_timeout` ⇒ `50`), una fila contendida **no** produce un `1205` inmediato: el barrido **espera**. Eso tiene dos lecturas opuestas:

* **A favor de dejarlo como está (opción C1):** en operación normal la contención se resuelve sola —el tenedor confirma en milisegundos— y el barrido acaba entrando sin aplazar a nadie. Hoy ya se comporta así en producción.
* **A favor de acotarlo (opción C2, recomendada):** si un escritor retiene la fila (una transacción que no confirma, un bloqueo largo), el barrido queda **parado hasta 50 s por cada fila** y la pasada deja de ser predecible; con una cota baja de sesión (p. ej. **3–5 s**) la contención se convierte en un aplazamiento **visible y acotado**, que es justamente lo que esta tarea hace legible.

**Medición del caso extremo (2026-10-12, instrumento no versionado `scratch/_cli_contention_proof.php`, retirado tras la corrida).** Con la fila de la máquina sostenida por el cierre técnico **sin confirmar**, el CLI real (`php bin/sanitary_clock_sweep.php`) como **proceso aparte** esperó el bloqueo **51,27 s** (los 50 s del servidor más el coste de la pasada: `duration_ms` = 51.154,97) antes de morir en el `1205`. Ese es, hoy, el precio por candidato contendido cuando el tenedor se alarga: no lo acota el código, lo acota el servidor. Con la Capa C2 ese tiempo pasaría a ser el de la cota elegida.

**Medición del tenedor legítimo (2026-10-12, instrumento no versionado `scratch/_winner_transaction_proof.php`, retirado tras la corrida).** La unidad de trabajo que sostiene la fila en el caso normal —`SELECT` de ajustes + compare-and-set `UPDATE machines` + `INSERT` en `audit_log` + `COMMIT`, por el camino real de producción— se midió **120 veces** sobre las máquinas del parque sembrado, con 120/120 cuarentenas escritas de verdad:

| Ventana medida | min | p50 | p90 | p95 | p99 | máx |
| :--- | ---: | ---: | ---: | ---: | ---: | ---: |
| `begin` → `commit` (lo que espera un contendiente peor situado) | 1,47 ms | 1,68 ms | 2,31 ms | **2,54 ms** | 3,11 ms | **3,97 ms** |
| Trabajo → `commit` (cola, cota inferior de la ventana) | 0,49 ms | 0,58 ms | 0,74 ms | 0,83 ms | 1,17 ms | 1,30 ms |

**Decisión (C2, implementada):** la pasada usa una cota de **2 s** para su sesión (`SanitaryClockSweepService::LOCK_WAIT_BUDGET_SECONDS`) y **restituye** el valor anterior al terminar, sin elevar nunca una cota ya más estricta. El número se elige con el dato, no a ojo:

* **Cota inferior:** 2 s son **~787× el p95** medido (2,54 ms) y ~500× el máximo (3,97 ms), de modo que ninguna contención legítima se convierte en aplazamiento: el barrido sigue esperando al tenedor corto, como hoy.
* **Cota superior:** 25× por debajo del valor de servidor (50 s). El peor caso de la pasada queda acotado en `candidates × cota` —500 × 2 s ≈ 16,7 min teóricos, frente a las ~7 h de hoy—, y la regla de escalado de la Capa D avisa mucho antes de llegar a ese extremo.
* **Margen por latencia de producción:** la medición es contra una base **local**; el margen de ~500-800× absorbe con holgura el coste de red y de `fsync` de un entorno desplegado, donde la misma transacción son unos pocos milisegundos más.
* **Una sola constante:** el valor vive en el servicio, es trivial de revisar y su efecto está certificado por la suite de carrera y por la medición del CLI como proceso aparte (51,27 s antes → **~2 s** después, §4 y T-PAUSE-40).

### Capa D — Regla de escalado del planificador (**recomendación operativa**)

| Señal en el resumen | Acción del planificador |
| :--- | :--- |
| `failures_count > 0` (o `exit 1`) | **Alerta inmediata.** Es lo único que exige intervención |
| `contended_count > 0` (o la línea `APLAZADO` del CLI) | **Aviso, sin alerta.** Registrar la cifra para poder ver tendencia |
| `contended_count` **> 5 en una pasada** | **Escalada:** señal de que hay un escritor reteniendo filas de máquina, no de azar |
| `contended_count > 0` en **3 pasadas consecutivas** (≈ 45 min) | **Escalada:** el aplazamiento dejó de ser transitorio y la latencia de detección se está duplicando de verdad |
| Resumen que no cuadra | **No puede ocurrir:** el DTO lo rechaza antes de publicarse (§1.4) |

Si se aprueba la Capa B, los umbrales deben contarse sobre el **residuo** (lo que queda tras los reintentos), no sobre el primer choque.

---

## 5. Lo que esta propuesta NO propone

1. **No** cambia la cadencia ratificada de 15 minutos ni el umbral de 4 h naturales.
2. **No** reintenta indefinidamente ni bloquea la pasada esperando al otro consumidor.
3. **No** convierte la contención en fallo para «avisar por si acaso»: eso es precisamente el ruido que esta tarea elimina.
4. **No** clasifica por el texto del mensaje de error: sólo por códigos estructurados (fail-safe).
5. **No** toca el valor de `innodb_lock_wait_timeout` del servidor.
6. **No** introduce dependencias externas ni migraciones de esquema.

---

## 6. Decisiones que quedan en manos del Product Owner

| # | Decisión | Opciones | Recomendación |
| :--- | :--- | :--- | :--- |
| D1 | ¿Se implementa el reintento dentro de la pasada? | Sí / No (sólo Capa A) | **Sí** (Capa B) |
| D2 | ¿Se acota el tiempo de espera de bloqueo de la sesión del barrido? | C1 mantener 50 s · C2 cota baja | **C2 · decidida e implementada: 2 s** (≈787× el p95 medido del tenedor ganador) |
| D3 | Umbrales de escalado de la Capa D | Los propuestos / otros | **Los propuestos**, sobre el residuo |
| D4 | ¿Se añade un código de salida propio para «pasada con contención» (p. ej. `3`)? | Sí / No | **No**: la contención no alerta; el detalle ya viaja en el resumen y en la línea informativa del CLI. Un código propio invitaría a confundirlo con un fallo |

---

## 7. Límites declarados (lo que esta propuesta no certifica)

1. **El reintento de la Capa B no está implementado**: hoy todo aplazamiento se resuelve en la pasada siguiente (Capa A).
2. **La contención certificada es la de la carrera contra el cierre técnico** (dos conexiones PDO reales, `1205` real). No se ha medido la contención contra otros escritores (reasignación de coordinador, edición de la configuración preventiva, reintegros), que deberían comportarse igual por construcción —el bloqueo es de la misma fila— pero no están certificados caso por caso.
3. **La cadencia de 15 minutos es criterio de contrato, no una promesa del código**: depende de que el planificador esté configurado fuera del repositorio.
4. La duración del tenedor ganador **ya está medida** (n=120: p95 2,54 ms · máx 3,97 ms), pero **contra una base local** y no contra la latencia de un despliegue real; el margen de ~787× de la cota elegida existe precisamente por eso, y el dato no cubre a otros escritores del parque (una reasignación o un reintegro que retenga una fila se aplaza, no se mide).
5. El artefacto de línea de comandos **sí** se ha ejecutado como proceso aparte contra una fila realmente bloqueada (§4, Capa C: `exit 0`, línea `APLAZADO`, `Fallos reales: 0` y un solo rastro tras la confirmación del dueño) — pero fue una **medición puntual con instrumento no versionado**, no una suite de la batería: la regresión permanente de esa semántica son la clasificación unitaria (`grupo 6`) y la carrera HTTP (`3.3`), más las aserciones de contrato del CLI (`6.3`/`6.4`).

---

## 8. Criterios de aceptación si se aprueba (para la fase de tests, antes del código)

1. Con la fila sostenida por otro consumidor **durante menos de 50 ms**, el candidato se resuelve **dentro de la misma pasada** sin publicarse como `contended`.
2. Con la fila sostenida **más allá de los reintentos**, el candidato se publica como `contended` con `failures_count = 0` y **sin** rastro duplicado (una sola cuarentena y un solo evento por máquina).
3. El reintento que llega **después** de que otro consumidor confirme no escribe nada nuevo y se resuelve como `skipped`.
4. La pasada mantiene la identidad contable `candidates = evaluated + failures + contended` en todos los casos.
5. La pasada **no** puede quedar bloqueada más allá de la cota fijada (2 s): se certifica con una transacción que retiene la fila (`SanitaryClockConsumerRaceTest`, grupo 3, que ahora mide la pasada viva en lugar de ajustar la sesión a mano) y con el CLI real como proceso aparte contra la misma fila bloqueada.
