# Informe · Sonda de humo, funcionales y flujo de trabajo del módulo 11 (`PENDING_INFO`)

**Fecha:** 2026-10-10 · **Rama:** `pending-info` · **Alcance:** `specs/11-pending-info-sla-pause/spec.md` (RF-01 … RF-06, RNF-01 … RNF-06)
**Ejecutable:** [`../tests/diagnostics/PendingInfoSmokeWorkflowDiagnostic.php`](../tests/diagnostics/PendingInfoSmokeWorkflowDiagnostic.php)

```bash
php tests/diagnostics/PendingInfoSmokeWorkflowDiagnostic.php
```

**Resultado de la última corrida:** 64 aserciones · **64 en verde** · 0 fallos · **0 observaciones** · **exit 0**. La sonda detectó el defecto **F-1** (61/62, exit 1) y hoy lo certifica ya resuelto: sus aserciones `E.1` a `E.4` son la prueba de la frontera de las 72 h hábiles en las dos orillas, y el parque queda intacto tras cada corrida (§3). Los hallazgos **F-2**, **F-3** y **F-5**, que la sonda documentaba como coherencia, riesgo contractual y ficción de estado, quedaron cerrados con decisión del Product Owner: `B.2.3` dejó de ser la observación viva y es hoy la aserción que certifica el reingreso `PENDING_INFO -> REGISTERED` sin responsable inventado, y `D.3.4` pasó de ser la medición que destapó la dilatación de 4,15× a ser la aserción que certifica que el vencimiento avanza **exactamente** el tiempo hábil perdido. El hallazgo **F-4**, el único que era de entorno, quedó cerrado el 2026-10-11 por la vía del arnés: el contrato de limpieza estrena §8 (prefijo reservado de sedes, purga del subárbol, ganchos de restitución en las suites y red de cierre de la batería) y la guarda `HarnessResidueGuardTest` sustituye al acta. No queda ninguna observación abierta.

La sonda vive en `tests/diagnostics/` a propósito: `tests/run_all.php` descubre sus suites con glob sobre `unit/`, `integration/` y `Manual/`, y una suite más ahí mueve los totales que `DocMetricsGuard` audita. Se promueve a `tests/integration/` cuando el Product Owner decida incorporarla a la batería canónica y sincronizar sus cifras; hoy ya está entera en verde y sin observaciones vivas.

---

## 1. Fallos y riesgos, por orden de importancia

### F-1 · ALTO · **RESUELTO (2026-10-10)** — La cancelación por inactividad no verificaba las 72 h hábiles y la auditoría firmaba como si las hubiera

**Resolución.** El umbral es **precondición** de `IncidentPauseService::cancelByInactivity()`, aplicada dentro de la transacción antes de la primera escritura: fuera de la ventana lanza `InactivityThresholdNotReachedException` (422 `INACTIVITY_THRESHOLD_NOT_REACHED` con la espera medida y el umbral en `details`). La medición sale de un único punto (`pauseBusinessSecondsSince()`), el mismo que enciende la insignia del triaje, y el servicio **devuelve la cifra medida** para que el evento inmutable la firme (`inactivity_wait_business_hours`) en lugar de afirmarla. Specs enmendadas antes del código: RF-04.3 y la nota de contrato de RF-04 en la spec funcional, §2.4 del plan, y las dos decisiones contrarias de T-PAUSE-09/T-PAUSE-13 revocadas por escrito. Verificación: la propia sonda pasa a **62/62 (exit 0)** —`E.1` 71 h sin insignia, `E.2` 71 h rechazadas sin tocar el expediente, `E.3`/`E.4` 80 h autorizadas— y la batería canónica queda en verde con las suites del módulo ampliadas (52 aserciones en la de coordinación, 62 en la unitaria del Algoritmo 5).

**Qué ocurría.** `POST /api/coordinator/incidents/{id}/cancel-inactivity` ejecuta el protocolo completo (cancelación + máquina fuera de servicio por falta de acceso + desvinculación de reintegros) con **cualquier avería en pausa**, sin comprobar el umbral de las 72 horas hábiles que da nombre al protocolo.

**Evidencia (mismo expediente, dos orillas del umbral).**

| Aserción | Premisa | Resultado medido |
| :--- | :--- | :--- |
| `E.1` | Pausa de **71 h hábiles** (a un lado del umbral) | El triaje publica `is_prolonged_inactivity = false` ✔ |
| `E.2` | El mismo expediente, acto seguido | `cancel-inactivity` responde **200**, estado `CANCELLED`, `machine.is_blocked_no_access = true`, `refunds_preserved = true` ✘ |
| `E.3` / `E.4` | Pausa de **80 h hábiles** (control positivo) | Insignia encendida y cancelación autorizada ✔ |

El expediente no cambia entre `E.1` y `E.2`: el sistema afirma en lectura que la espera **no** es prolongada y, en escritura, aplica el protocolo de la espera prolongada.

**Causa raíz (dos puntos, no uno).**

1. `IncidentPauseService::cancelByInactivity()` sólo guarda que el expediente esté en `PENDING_INFO`; no consulta `isProlongedInactivity()`. Esa comprobación sí existe y sí se usa para la insignia del triaje (`is_prolonged_inactivity`) y para la ficha de detalle.
2. `CoordinatorController::cancelInactivity()` sella el evento inmutable con `cancellation_channel: INACTIVITY_72_BUSINESS_HOURS` **como literal**, de modo que la bitácora de auditoría (Art. III, RNF-05) certifica una espera de 72 horas hábiles que nunca existió. Es el daño colateral más grave: el rastro inmutable queda falseado, no sólo la acción.

**Impacto.** Cualquier coordinador puede, en el primer minuto de una pausa, cancelar la avería, dejar la máquina fuera de servicio para el ciudadano y mover el reintegro del consumidor a custodia central, todo ello con un rastro que declara un silencio de tres jornadas laborales del cliente. La salvaguarda de las 72 h deja de existir en la práctica.

**Decisión que corresponde al Product Owner (no se ha tocado el código).** Caben dos lecturas y conviene fijar una por escrito antes de tocar nada:

- **(a) El umbral es una puerta real** (lectura natural de RF-04.3 y del criterio 6 del §8): guardar el protocolo con `isProlongedInactivity()` y responder `422` —código tipo `INACTIVITY_THRESHOLD_NOT_REACHED`— fuera de la ventana. El canal de auditoría debe derivarse de la espera medida, nunca escribirse a mano.
- **(b) La cancelación temprana es una prerrogativa de supervisión deliberada**: entonces el literal del canal de auditoría es un error inequívoco que hay que corregir (registrar la espera real), y la ficha debe dejar de ofrecer la acción bajo el umbral.

En ambos casos, el literal `INACTIVITY_72_BUSINESS_HOURS` es incorrecto tal y como está.

---

### F-2 · MEDIO · **RESUELTO (2026-10-10)** — La ficha de coordinación ofrecía «Cancelar por inactividad» en cuanto había pausa viva

**Resolución.** El umbral pasó a gobernar las dos caras de la misma regla: `CoordinatorIncidentDetailService::buildPauseBlock()` publica `can_cancel_inactivity = $isSlaPaused && $isProlongedInactivity`, de modo que la acción supervisada ya no se ofrece bajo el umbral y la ficha explica la frontera con la etiqueta `pause-inactivity-threshold-pending` («la cancelación se habilita al superar las 72 horas hábiles») en lugar de esconder el botón sin motivo. Es la misma medición —un único `isProlongedInactivitySince()`— que enciende la insignia del triaje y que admite la escritura en `POST /api/coordinator/incidents/{id}/cancel-inactivity`, así que lectura y escritura no pueden discrepar. Enmiendas antes del código: nota de contrato en `specs/technical/api_contracts.md` (bloque `pause`) y en la spec funcional (criterio de contrato de RF-04). Verificación: `CoordinatorIncidentDetailServiceTest` 7.4/7.6.b (frontera exacta: justo en el umbral no se habilita, un segundo después sí), `CoordinatorIncidentDetailModalTest.mjs` 19.1/19.6/19.6.b (computed + plantilla) y `PendingInfoCoordinatorEndpointsTest` 6.4.

`CoordinatorIncidentDetailService::buildPauseBlock()` publica `can_cancel_inactivity = $isSlaPaused`: la acción supervisada se ofrece desde el primer segundo de pausa, aunque el propio bloque de la ficha publique `is_prolonged_inactivity = false` y `inactivity_threshold_business_hours = 72` en la línea inmediatamente anterior. Es la cara de interfaz de F-1 y comparte causa raíz: o el umbral gobierna las dos cosas, o hay que declarar que no gobierna ninguna.

**Repro:** aserción `E.1` (fila del triaje) y `C.13` (insignia a 12 días) frente al bloque de pausa de la ficha.

---

### F-3 · MEDIO · **RESUELTO (2026-10-10)** — El SLA se dilataba ~4× cuando la pausa cruzaba noche o fin de semana

**Decisión del Product Owner.** De las dos definiciones que convivían en el pliego manda la del **reloj contractual congelado** (RF-03.2): lo que se desplaza en la reanudación es el **tiempo hábil realmente perdido** durante la pausa, no su duración de pared reinterpretada como horas comerciales. Una espera de viernes 18:00 a lunes 08:00 —el escenario de RF-01.1— no mueve la fecha límite, porque la jornada de la sede no corrió; el caso de las 62 h de calendario que medía `D.3.4` dejó de dilatarse y ese mismo punto pasó a ser una **aserción** que cuenta los segundos hábiles del tramo recorrido con el calendario independiente del arnés.

**Qué se tocó.** (1) La medición vive en un único punto, `IncidentPauseService::pauseBusinessSecondsSince()`, del que ahora dependen las tres caras: el vencimiento que graba la reanudación (`closePauseInterval()`), la fecha recalculada que publica la ficha (`CoordinatorIncidentDetailService::recalculatedSlaTargetAt()`, que antes sumaba el acumulador de pared más el intervalo vivo y ofrecía una fecha que el cierre real no iba a escribir) y la auditoría. (2) La duración de pared sigue donde siempre estuvo —bitácora del intervalo, `total_pending_info_seconds` y descuento de MTTR (RF-03.1)—, que es lo que permite exigir en las aserciones que el desplazamiento **nunca exceda** la espera real. (3) Specs enmendadas antes de cerrar: nota de contrato de RF-03.3 en la spec funcional, §3.3 del plan (qué recibe el Algoritmo 3 como `minutosPausa`) y notas de revocación en T-PAUSE-06/T-PAUSE-08/T-PAUSE-19.

**Consecuencia en la batería y cómo se hizo determinista.** Cuatro suites de integración y dos unitarias afirmaban `desplazamiento == segundos de pared del intervalo`, que sólo es cierto cuando la pausa cae entera dentro de la jornada: de madrugada o en fin de semana la medición hábil es cero. En vez de debilitar la aserción, se le dio su propia herramienta: `tests/Support/BusinessCalendar.php` implementa la jornada 08:00-18:00 de lunes a viernes **de forma independiente del servicio** (contar segundos hábiles en un tramo y situar un instante a N segundos hábiles atrás), y las suites calculan con ella la expectativa exacta, acotada entre las dos orillas del reloj real. `IncidentPauseServiceTest` ancla además su reloj virtual dentro de la jornada y `handleSiteCommentReactivation()` estrenó la costura de inyectabilidad `?DateTimeImmutable $now` —la misma que ya usaban `isProlongedInactivitySince()` y la ficha de detalle— para que la reactivación de sede sea verificable al segundo sea cual sea el día de la corrida.

Medido sobre el sistema real (aserción `D.3.4`): una espera de **62 h** de calendario (viernes 18:00 ➔ lunes 08:00, el escenario exacto de RF-01.1) desplaza la fecha límite **257 h de calendario**, un factor **4,15×** sobre el tiempo realmente perdido.

**Por qué no lo cuento como fallo:** RF-03.3 manda desplazar «los minutos pausados» aplicando el desplazamiento dentro de la jornada comercial, y la batería ratifica esa mecánica en `IncidentPauseServiceSlaShiftTest` (caso 3.6: «el fin de semana no consume ni un segundo»). El efecto, sin embargo, es que los segundos pausados de noche y de fin de semana **se vuelven a conceder como minutos comerciales**, y el compromiso de SLA se infla precisamente en el caso más habitual de la pausa. Es la comprobación de que las dos definiciones que conviven en el pliego —«reloj contractual congelado» (RF-03.2) y «desplazar los minutos pausados» (RF-03.3)— no coinciden; conviene fijar cuál manda antes de que alguien firme un SLA con esta aritmética.

---

### F-4 · MEDIO (entorno) · **RESUELTO (2026-10-11)** — «Fuera de servicio» son dos marcas, no una: quien ejerce RF-04.4 deja el parque cojo en silencio

**Resolución.** El hallazgo tenía dos mitades y ambas quedan cerradas con la causa arreglada, no con el síntoma.

*(1) Las dos marcas de «fuera de servicio».* La sonda, que ejerce RF-04.4, restituye ya `is_blocked_no_access` **e** `is_active`, y lo prueba con dos corridas consecutivas que dejan el parque intacto.

*(2) El residuo de sedes del banco de pruebas.* El contrato de limpieza estrena §8: **toda sede cuyo `site_code` empiece por `SEDE-TEST-` o `SEDE-MAP` pertenece al arnés** —el seed canónico usa `SEDE-BCN-*` y sólo `SEDE-BCN-*`—, y `TestDataCleaner` gana el par `countTestLocations()` / `purgeTestLocations()`. La purga no es un `DELETE FROM locations`: drena **el subárbol completo** derivado otra vez del grafo real de claves foráneas, encadenando subconsultas hacia la condición raíz, porque las seis tablas que referencian `locations` y las que cuelgan de ellas lo hacen con `ON DELETE RESTRICT` (un `DELETE` directo fracasa con el 1451 en cuanto la suite creó una máquina o una avería sobre su sede). Cada suite creadora registra un `register_shutdown_function` que la drena —corre **también tras un error fatal**, que es precisamente el camino por el que un `finally` no llega y por el que el residuo se acumulaba—, y la Fase 5 de `run_all.php` añade la red de cierre, que drena y **avisa** si encontró algo. La deuda se saldó de una vez: las **206 sedes** residuales (69 `SEDE-TEST-*`, 137 `SEDE-MAP*`) y sus 5 máquinas quedaron drenadas en una pasada, y el parque volvió a sus **10 sedes canónicas**.

**La guarda, no el acta.** `tests/integration/HarnessResidueGuardTest.php` (14 aserciones) es lo que impide que el limpiador se degrade en un helper que «parece» funcionar: planta residuo vivo y en baja lógica, comprueba que el detector lo ve, que el `DELETE` ingenuo **fracasa** con el 1451, que la purga drena el subárbol entero y que las diez sedes semilla siguen activas e intactas. Verificado por sabotaje: plantando una sede `SEDE-MAP-LEAK-99` a mano, la guarda se pone en rojo nombrando la familia de residuo (exit 1); drenada, vuelve a verde.

**El acoplamiento que el cierre destapó.** Al restituir el parque de verdad, `PendingInfoConstitutionalTest` se cayó: exigía **dos** máquinas de snacks libres y el seed sólo tiene una. Pasaba porque contaba con el parque residual de `TechnicianRouteMapApiTest` (las `SEDE-MAP18-*`), es decir, su verde dependía del defecto que este cierre arregla. La suite no se debilitó: se aprovisiona lo que le falta en una sede del banco de pruebas y la drena en su propio gancho (30/30 en verde). Es la prueba de que el residuo había dejado de ser cosmético.

Durante esta sesión la batería pasó de verde a rojo (25 suites / 8.683 aserciones) y de vuelta a verde **sin que se tocara una sola línea de `src/`**. El detonante no era el módulo 11 sino dos máquinas canónicas dadas de baja (`is_active = 0`): con `SEDE-BCN-01` y `SEDE-BCN-03` reducidas a una sola máquina activa, toda suite que toma `$machines[1]` de la sede sembrada muere con `Call to a member function getId() on null` y la corrida entera se arrastra.

**Mecanismo (medido, no inferido).** `RF-04.4` saca la máquina de servicio con **dos** marcas: `IncidentPauseService::cancelByInactivity()` llama a `machines()->blockForNoAccess()`, que pone `is_blocked_no_access = 1` **y** `is_active = 0`, y la máquina no vuelve sola a «Operativa». Quien deshaga solo el bloqueo deja media reparación hecha y el parque pierde capacidad en cada corrida.

**La sonda estaba entre los culpables y ya no lo está.** Su bloque E ejerce precisamente ese protocolo, y su limpieza sólo devolvía `is_blocked_no_access = 0`: las dos máquinas `HOT_DRINKS` del seed —las primeras de la preferencia `'AUTO'`— quedaban de baja, una por corrida. La prueba está en el repositorio y en el rastro medido. En el repositorio: la misma sentencia `UPDATE` escribe las dos marcas ([`PdoMachineRepository.php:583-584`](../src/Infrastructure/Repository/PdoMachineRepository.php#L583-L584)), y la limpieza de la sonda deshacía una sola. En el rastro: al reanudar esta sesión el parque tenía **16 activas con exactamente `VEND-0102` y `VEND-0302`** —las dos únicas `HOT_DRINKS` del seed y las primeras que elige la preferencia `'AUTO'` de la sonda— de baja, y tras corregir la limpieza para restituir también `is_active` **dos corridas consecutivas** dejan el parque en **18 activas / 0 bloqueadas / 0 de baja**. La distinción importa: una baja administrativa deliberada sella `deleted_at`, y el restablecimiento la excluye (`deleted_at IS NULL`).

**Segundo rastro, este sí ajeno a la sonda:** tras una batería 100% verde la base conserva residuo de sedes que ninguna suite retira —189 sedes frente a las 10 canónicas—, repartido en `SEDE-TEST-*` (60), `SEDE-MAP-*` (60), `SEDE-MAP17-*` (55) y `SEDE-MAP18-*` (4). Es acumulativo entre corridas y hoy no rompe nada porque las suites se auto-aislan, pero es la misma fragilidad en otra tabla.

Recomendación: que cada suite que bloquee, dé de baja o cree sedes restituya el estado en su `finally` —las dos marcas de RF-04.4 incluidas—, o que el banco de pruebas trabaje contra un esquema propio en lugar del sembrado compartido.

---

### F-5 · MEDIO-BAJO · **RESUELTO (2026-10-11)** — Reanudación sin técnico asignado: «Asignada» sin responsable

**Resolución.** La reanudación de un expediente sin técnico responsable reingresa ahora a `REGISTERED` —la cola de asignación— y nunca a `ASSIGNED`/`IN_PROGRESS`, que describirían a un profesional inexistente. La regla vive en el agregado (`Incident::resumePendingInfo()`), así que manda en todas las puertas: la reactivación automática por comentario de sede (`handleSiteCommentReactivation()`), la reanudación manual de coordinación —cuyo `target_status` suma `REGISTERED` y cuyo destino por defecto se rechaza con `422` cuando afirmaría un responsable que no existe— y la ficha, que elige el destino con `technician.assigned`. En el sentido inverso, un expediente con técnico tampoco puede reingresar a `REGISTERED`. Specs enmendadas antes de cerrar: RF-02.1/RF-02.3, el caso límite §6.4 y el criterio 4 del §8 de la especificación funcional, el espejo del módulo 11, §2.2.B/§2.3/§3.1/§3.2 del plan, la nota del bloque `pause` en `api_contracts.md` y §7.2 del contrato de permisos. La sonda pasa a **64/64 con 0 observaciones**: `B.2.3` dejó de ser la observación viva y es hoy la aserción que certifica el rastro `PENDING_INFO -> REGISTERED` sin responsable inventado. Cobertura: `PendingInfoSiteReactivationTest` 7.1–7.5 (reactivación real por HTTP y MariaDB), `PendingInfoCoordinatorEndpointsTest` 3.9–3.11 (destino por defecto rechazado, reingreso autorizado y disfraz rechazado), `IncidentPauseReactivationTest` 5.1–5.3, `IncidentPauseDomainModelTest` 4.5.b/4.5.c, `IncidentPauseServiceTest` 1.11.b/4.10/4.10.b y `CoordinatorIncidentDetailModalTest.mjs` 19.14.b.

**Qué ocurría.** Caso límite §6.4 de la spec: si la sede responde sobre un expediente que perdió su técnico, la reactivación devolvía `ASSIGNED` con `assigned_technician_id = NULL` (observación `B.2.3`; la aserción `B.2.1` confirmaba que, al menos, no llegaba a declarar `IN_PROGRESS`). El §6.4 admitía «`ASSIGNED` o `REGISTERED`», así que no contradecía al pliego, pero la etiqueta «Asignada» describía un responsable inexistente y otras vistas leían ese estado como «hay un técnico que irá».

---

## 2. Qué se probó y pasó (64 de las 64: 10 de humo y 54 de comportamiento)

**Bloque A — humo (10).** El servidor vivo se identifica como VendGuard y sirve la portada; los cuatro assets del módulo se sirven con 200 y MIME JavaScript; la API sin sesión responde 401 (no 404 ni 500); el barrido de las 11 rutas del módulo no produce ni un 5xx y ninguna filtra trabajo sin autenticación.

**Bloque B — funcionales (16).** Ramas que la batería no cubría: técnico ocupado en otra parada obliga a `ASSIGNED` aunque la respuesta llegue en caliente (RF-02.1); reactivación sin técnico reingresa a `REGISTERED` —ni `IN_PROGRESS` ni `ASSIGNED`— con su intervalo contabilizado y su rastro (RF-02.1, §6.4, cierre de F-5); la nota **interna** de coordinación no reactiva la avería (§6.5); resolver desde `PENDING_INFO` se rechaza sin escribir (RF-06.2); la segunda reanudación no añade filas al historial ni altera el acumulador (RF-02.4, RNF-05); el MTTR del desglose es el bruto menos las pausas de sede, con descuento material verificado contra SQL independiente (RF-03.1).

**Bloque C — flujo de trabajo completo (23).** Reporte de sede ➔ triaje ➔ asignación ➔ pausa del técnico ➔ banner de sede con causa tipificada ➔ parada visible en «Mi Ruta» con contador ➔ reactivación automática en caliente a `IN_PROGRESS` ➔ reloj descongelado con minutos descontados ➔ resolución documentada ➔ reapertura en garantía con desasignación ➔ **pausa desde `REOPENED` declarada por coordinación** (origen legal que la batería no cubría) ➔ 72 h hábiles ➔ insignia de espera prolongada ➔ cancelación por inactividad ➔ máquina fuera de servicio ➔ aviso nuevo interceptado sin la casilla y aceptado con ella ➔ hilo sellado ➔ seguimiento ciudadano neutral ➔ invariantes de historial de sólo adición.

**Bloque D — validación hostil (9).** Una justificación de 25 espacios se rechaza sin pausar el expediente (RF-01.3); tampoco cuela una de tabuladores y saltos; una sede ajena no puede comentar el expediente (403, Art. V.4); el desplazamiento del vencimiento nunca sale de la jornada 08:00–18:00 ni retrocede (RF-03.3).

**Bloque E — fronteras (6).** 71 h hábiles no encienden la insignia y 80 h sí (descarta una cuenta por horas de calendario); a 47 h de la resolución la sede todavía comenta y a 49 h el hilo está sellado (RF-05.4).

---

## 3. Estado del entorno dejado por esta sesión

- **Parque restaurado:** las 13 máquinas `VEND-*` canónicas devueltas a `is_active = 1`, `deleted_at = NULL` e `is_blocked_no_access = 0`, que es lo que siembra `database/cloud_init.sql`; verificado en consulta: **18 activas / 0 bloqueadas / 0 de baja**.
- **La sonda se auto-repara:** libera las averías vivas, devuelve a servicio las máquinas que quedaron sin avería viva (las dos marcas de RF-04.4) y re-siembra al empezar y al terminar. Comprobado con **dos corridas consecutivas** en el cierre de F-4 y de nuevo en la corrida de cierre de F-5, ya con **64/64**, dejando el parque intacto después de cada una.
- **Batería canónica:** verde en la comprobación final del cierre de F-4 — **239 suites · 9.210 aserciones · 0 fallos · exit 0** (105/105 unit PHP, 60/60 unit JS, 73/73 integración, 1/1 E2E manual), con las cifras documentadas coherentes por la guarda de `DocMetricsGuard` y la base re-sembrada al cierre. El cierre de F-5 aportó 15 aserciones nuevas a las suites tocadas (enum, servicio, modelo de dominio, reactivación, ficha y las dos de integración de la reactivación); el de F-4 suma **una suite y 14 aserciones** (la guarda del residuo), y las 30 de `PendingInfoConstitutionalTest` vuelven a contar como antes del cierre —la suite se aprovisiona su propio parque en lugar de heredarlo, sin cambiar su número de aserciones—.
- **Residuo de sedes: cero (cerrado el 2026-10-11).** Las 206 sedes del banco de pruebas que el informe dejaba declaradas —69 `SEDE-TEST-*` y 137 `SEDE-MAP*`, con sus 5 máquinas— quedaron drenadas y el arnés ya no las deja: cada suite creadora las restituye en su gancho de apagado, la Fase 5 de la batería cierra el residuo que se le escape y `HarnessResidueGuardTest` lo vigila. El parque queda en las **10 sedes canónicas** al terminar cada corrida.

## 4. Límites declarados

- Los bloques B, C, D y E van por el **enrutador y los middlewares reales** contra MariaDB, pero en proceso (no por socket), que es lo que hace la batería de integración del repositorio; el bloque A sí ejerce el servidor HTTP vivo.
- No se ejecuta JavaScript de navegador: la cara JS de RF-05.1/05.2/05.5 sigue certificada por las suites ESM existentes, no por esta sonda.
- La frontera de 71/80 h hábiles se coloca con un ayudante que recorre el calendario comercial hacia atrás; su calibración se valida contra la insignia del propio sistema en las dos orillas.
- El tiempo hábil del cierre de F-3 (`D.3.4`) se cuenta con `tests/Support/BusinessCalendar.php`, la implementación independiente que el arnés usa también en las suites de integración; la sonda la carga por el bootstrap de pruebas, no la reimplementa.
- El literal del canal de auditoría de F-1 se verifica **leyendo el controlador**; no se descodifica la fila de `audit_log` desde la sonda.
- La muestra de MTTR se contrasta replicando la consulta analítica con el mismo ámbito (técnico y ventana), no con un expediente aislado.

---

**Nota de vigencia (2026-10-15).** El §8 del contrato de limpieza que cita este informe se
rectificó. El criterio de residuo ya no es el prefijo reservado de sedes sino la **identidad
contra el catálogo canónico del sembrador** (contrato de limpieza §8.1, §8.5), porque el
prefijo era una lista paralela al catálogo que dejaba fuera familias como `SEDE-TEMP-*` y,
sobre todo, todo lo que una suite colgase de una **sede semilla**. La guarda
`HarnessResidueGuardTest` mide hoy el criterio nuevo y conserva el retirado como testigo.
