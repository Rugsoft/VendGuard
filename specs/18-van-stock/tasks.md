# TAREAS DE IMPLEMENTACIÓN: STOCK DE REPUESTOS EN FURGONETA (VAN STOCK)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** 18 · Stock de Repuestos en Furgoneta  
**Documento:** `specs/18-van-stock/tasks.md`  
**Spec:** [`spec.md`](spec.md) · **Plan:** [`plan.md`](plan.md) · **Contratos:** [`../../specs/technical/van_stock_contracts.md`](../technical/van_stock_contracts.md)  
**Política de pruebas:** por defecto *test-first*. Cada tarea declara su prueba en ROJO antes de implementar, y la batería hermética (`php tests/run_all.php`) más las suites JS (`tests/unit/*.mjs`) cierran la verificación.

**Rama de trabajo:** `18-van-stock` (una rama de módulo, precedente del repositorio). **Estrategia de entrega:** pendiente de la elección del Product Owner (ver plan §7).

---

## Work Units (rebanadas de commit)

| WU | Tareas | Contenido | Observación |
| :--- | :--- | :--- | :--- |
| **WU-1 · Esquema** | T-VAN-01 | Tabla 26, enum de auditoría, semillas, contrato de esquema y suites de esquema | Toca esquema persistente: rebanada aislada |
| **WU-2 · Dominio y aplicación** | T-VAN-02, T-VAN-03, T-VAN-04 | Entidad, puerto, repositorio PDO, servicio y DTO | Sin acoplamiento aún con el flujo existente |
| **WU-3 · Descuento en el flujo** | T-VAN-05 | `SparePartTraceabilityService` + stubs de pruebas | **Commit propio**: modifica código compartido por toda resolución de avería → `Risk: item 3` |
| **WU-4 · API** | T-VAN-06, T-VAN-07 | Controladores, rutas, saneador de sede y suite constitucional de privacidad | — |
| **WU-5 · Frontend** | T-VAN-08, T-VAN-09 | Panel del técnico y pestaña de coordinación (+ `api.js` del padre) | Dos unidades independientes con superficies disjuntas: candidatas a redacción en paralelo, con `api.js` reservado al padre |
| **WU-6 · Guardas y cierre** | T-VAN-10, T-VAN-11 | Enmienda de la guarda del Art. VI, cifras documentadas, cierre documental y batería final | Cierra el módulo |

---

## Tareas

### T-VAN-01 · Esquema de la tabla de stock y su contrato
* **Refs:** RF-01.1, RF-01.3, RF-05.2 · Contratos §2
* **Ruta:** inline
* **Prueba en rojo:** `tests/integration/PostgresVanStockSchemaTest.php` (canal `new_engine`) + ajuste de las guardas de esquema.
* **Alcance:** `database/supabase_migrations/009_technician_van_stocks.sql`; `database/supabase_init.sql` (sección 7, valor de enum, inventarios comentados y bloque final de recuentos); `database/supabase_seeds.sql`; `SeedRunner::seedVanStocks()`; `specs/technical/schema_correspondence_contract.md`; `CONTRACT_TABLES` de `SchemaConvergenceParityTest` y `PostgresSchemaTest`; `TestDataCleaner` + su suite de aislamiento.
* **Criterio de cierre:** la tabla existe con su unicidad por pareja, sus cotas `CHECK` y su disparador anti-borrado; las guardas de esquema quedan en verde.

### T-VAN-02 · Entidad de dominio `VanStockItem`
* **Refs:** RF-01.2, RF-03.1, RF-03.3
* **Ruta:** inline
* **Prueba en rojo:** `tests/unit/VanStockItemTest.php` (rangos, umbral, negatividad).
* **Alcance:** `src/Core/Domain/Model/VanStockItem.php`, `src/Core/Domain/Exception/VanStockValidationException.php` (catálogo de errores de los contratos §5).

### T-VAN-03 · Puerto y repositorio PDO
* **Refs:** RF-01.1, RF-02.3, RF-03.2 · Contratos §6
* **Ruta:** inline
* **Prueba en rojo:** cobertura vía `VanStockServiceTest` con stub del puerto + `VanStockApiTest`.
* **Alcance:** `src/Core/Domain/Repository/VanStockRepositoryInterface.php`, `src/Infrastructure/Repository/PdoVanStockRepository.php` (upsert absoluto, descuento atómico, listados).

### T-VAN-04 · Servicio de aplicación y DTO
* **Refs:** RF-01.1, RF-03, RF-04, RF-05.1
* **Ruta:** inline
* **Prueba en rojo:** `tests/unit/VanStockServiceTest.php` (ajuste absoluto con auditoría del delta; alerta ordenada con negativos primero; listado por técnico).
* **Alcance:** `src/Application/Service/VanStockService.php`, `src/Application/DTO/VanStockAdjustmentResultDto.php` (saldo, cantidad previa y delta), `src/Application/DTO/VanStockEntryDto.php` (proyecciones de técnico y de coordinador).
* **Nota de diseño:** el servicio valida y delega; la transacción y el evento de auditoría viven en `PdoVanStockRepository::adjust()`, que es donde comparten unidad de escritura con el saldo. La auditoría del delta se certifica contra el motor real en `VanStockApiTest` (WU-4).

### T-VAN-05 · Descuento automático en los dos caminos de declaración
* **Refs:** RF-02.1, RF-02.2, RF-02.3, RF-02.4 (RD-01 a RD-04)
* **Ruta:** inline · **Riesgo:** `item 3` (código compartido por toda resolución) → commit propio
* **Prueba en rojo:** sección 6 de `tests/unit/SparePartTraceabilityServiceTest.php` (reutiliza el escenario completo ya montado en vez de duplicarlo en una suite nueva): descuento por cantidad declarada en correctivo; idéntico en preventivo; fuera de catálogo no descuenta ni altera el flujo; cantidad fuera de rango rechazada sin efectos parciales; aditivo en dos declaraciones; **la declaración no consulta la disponibilidad, así que el inventario no puede impedir el cierre**.
* **Alcance:** `src/Application/Service/SparePartTraceabilityService.php` (inyección obligatoria del servicio de stock y una sola invocación del descuento en ambos bucles) + cableado en `CoordinatorSparePartsController`, `TechnicianController` y `TechnicianPreventiveController` + doble compartido `tests/Support/RecordingVanStockRepository.php`.
* **Invariante:** un solo método de descuento; prohibido duplicar el decremento en el camino preventivo.

### T-VAN-06 · API de stock (técnico y coordinación)
* **Refs:** RF-03.2, RF-04.1, RF-04.2, RF-04.3 · Contratos §4 y §5
* **Ruta:** inline
* **Prueba en rojo:** `tests/integration/VanStockApiTest.php`.
  * **Desvío respecto al plan, decidido al implementar:** las dos suites unitarias de controlador previstas no se escriben. Un controlador que solo lee, delega y traduce excepciones se certifica mejor a través del router real que contra un doble del servicio: la suite de integración fija el mismo JSON y los mismos códigos que habría fijado el doble, y además ejerce el middleware de rol y el motor. Una suite unitaria paralela habría duplicado la misma forma de respuesta sobre un colaborador simulado. La validación de query y de cuerpo —lo único que el controlador decide por su cuenta— queda cubierta en los apartados 4.3, 4.4 y 6.1 a 6.7 de la suite de integración.
* **Alcance:** `TechnicianVanStockController`, `CoordinatorVanStockController`, rutas en `AppRouter.php` tras el middleware de rol, y `listInventory(?int)` en el puerto, el repositorio PDO y el servicio para el modo «inventario» de la Coordinación (contrato §4.2, que declara opcionales los dos parámetros de consulta y por tanto admite la lectura del parque completo).

### T-VAN-07 · Privacidad constitucional (Art. V.4)
* **Refs:** RF-06.1, RF-06.2, RF-06.3
* **Ruta:** inline
* **Prueba en rojo:** `tests/unit/VanStockPrivacyTest.php` (sede recibe `403`; proyecciones de sede sin claves de stock).
* **Alcance:** ampliación de la lista de claves vedadas del saneador de `LocationPortalController`.

### T-VAN-08 · Panel de stock del técnico (frontend)
* **Refs:** RF-04.1, RF-01 (lectura)
* **Ruta:** inline o escritor delegado (superficie disjunta de T-VAN-09; `api.js` lo edita el padre)
* **Prueba en rojo:** `tests/unit/TechnicianVanStockPanelTest.mjs`.
* **Alcance:** `public/assets/js/components/TechnicianVanStockPanel.js` + montaje en `public/assets/js/views/TechnicianRouteView.js`.
  * **Decisión de montaje:** el panel entra en un bloque `<details>` nativo al principio de la sección *Averías*, no como cuarta pestaña. La barra de navegación móvil tiene tres botones a ancho completo; un cuarto comprimiría las etiquetas que ya existen («Mis Métricas») hasta partir el texto en pantallas de 360 px. El bloque plegable deja el stock a un toque y no toca una navegación ya certificada.

### T-VAN-09 · Pestaña de stock en coordinación (frontend)
* **Refs:** RF-03.2, RF-04.2
* **Ruta:** inline o escritor delegado (superficie disjunta de T-VAN-08; `api.js` lo edita el padre)
* **Prueba en rojo:** `tests/unit/CoordinatorVanStockTabTest.mjs`.
* **Alcance:** `public/assets/js/components/CoordinatorVanStockTab.js` + montaje de la pestaña en la vista de coordinación que aloja repuestos.
  * **El ajuste entra en esta tarea:** RF-04.2 exige que el panel permita «cargar y ajustar cantidades y umbrales». La línea de referencias de la tarea citaba solo las dos lecturas, pero la especificación manda: sin formulario de ajuste el módulo solo sería operable por API. Entra como panel en línea sobre la propia pestaña, con el saldo actual precargado al abrirse desde una fila.
  * **Objetivo táctil:** este componente cae dentro del barrido de `CoordinatorButtonVariantsGuardTest`, que prohíbe la geometría en línea en los botones del panel. Los botones usan la escalera del sistema (36 px) y el objetivo de 44 px se reserva para la vista móvil del técnico, que está fuera de ese barrido.

### T-VAN-10 · Guardas de alcance y cifras documentadas
* **Refs:** spec §8.2 · RNF-04
* **Ruta:** inline
* **Alcance:** `tests/unit/ConstitutionalAuditTest.php` §7.1 (retirada de `van_inventory` con la autorización citada); sincronización de cifras en `README.md`/`CHANGELOG.md` conforme a `DocMetricsGuard`; verificación de que el trinquete de tokens sigue en su techo.

### T-VAN-11 · Cierre documental y verificación final
* **Refs:** spec §9
* **Ruta:** inline
* **Alcance:** espejo `specs/functional/van_stock_spec.md`; estado real de la feature #5 en `docs/features_pendientes.md` (documento no versionado, `.gitignore:21`); ejecución de la batería completa y reporte honesto de toda prueba no ejecutada con su causa.
* **Límite declarado (advisoria de la verificación de WU-3):** el saldo de una pareja técnico/pieza no puede bajar de `-999` (`chk_van_stock_quantity_range`). Un descuadre acumulado que rebasara ese suelo abortaría el cierre de la avería, que es justo lo que RD-04 quiere impedir; el rango admite 999 sustituciones sin reponer, así que el caso es alcanzable en teoría y debe quedar escrito como límite conocido del módulo, no descubierto en producción.

---

## Dependencias

```
T-VAN-01 ─┐
T-VAN-02 ─┼─> T-VAN-04 ─> T-VAN-05 ─> T-VAN-06 ─┬─> T-VAN-07
T-VAN-03 ─┘                                     ├─> T-VAN-08 ─┐
                                                └─> T-VAN-09 ─┴─> T-VAN-10 ─> T-VAN-11
```

---

## Bitácora de ejecución

### WU-1 · T-VAN-01 (esquema) — cerrado
* **Commit:** `feat(van-stock): add the technician van stock schema with its guards`
* **Rojo observado:** `PostgresVanStockSchemaTest` 3 fallos — relación `technician_van_stocks` inexistente (`42P01`).
* **Verde:** suite nueva 18/18 · `SchemaConvergenceParityTest` 18/18 · `PostgresSchemaTest` 83/83 · `PostgresConstitutionalTest` 63/63 · `TestDataCleanerIsolationTest` 50/50 · batería completa 307 suites / 11 985 aserciones / 0 fallos, cifras documentadas coherentes.
* **Canal `new_engine`:** el DDL base y la migración `009` se aplican en un esquema aislado y convergen; la base de la batería se reprovisionó con DDL base + `009` + semillas (`VAN_STOCK_ROWS=5`).
* **Desvío corregido dentro de la rebanada:** el enumerado `audit_entity_type` ganó `TECHNICIAN_VAN_STOCK`, así que `AuditEvent::ENTITY_TYPES` y la etiqueta del visor (`AuditActionLabels.js`) debían incorporarlo en el mismo commit: `AuditEntityTypesTest` y `AuditActionCatalogTest` exigen que el catálogo del dominio y el del visor no divergan del enumerado de la base.
* **Recuento de esquema:** 26 tablas · 39 disparadores · 40 restricciones nombradas · 14 `CHECK` · 41 claves foráneas · 63 índices secundarios.
* **Riesgo:** `none` — todo es aditivo: tabla nueva, valor de enumerado nuevo y semillas nuevas; ninguna fila ni columna existente cambia de forma o de significado.

### WU-2 · T-VAN-02, T-VAN-03 y T-VAN-04 (dominio y aplicación) — cerrado
* **Commit:** `feat(van-stock): add the van stock domain, its port and its application service`
* **Rojo observado:** `VanStockItemTest` y `VanStockServiceTest` mueren con «Class/Interface not found» antes de escribir las clases.
* **Verde:** `VanStockItemTest` 17/17 · `VanStockServiceTest` 27/27 · `SqlDialectPortabilityTest` 97/97 · `AuditActionCatalogTest` 26/26 · batería completa 309 suites / 12 029 aserciones / 0 fallos con las cifras documentadas coherentes.
* **Sonda contra el motor real** (script fuera del repositorio, dentro de una transacción revertida, sin dejar rastro en la base de la batería): `findTechnician` (rol y código de operador), `sparePartExists` (pieza existente e inexistente), `findByPair` sin fila, alta con `RETURNING` e identificador, ajuste con cantidad previa y delta `-3`, evento `VAN_STOCK_ADJUSTED` con `previous_state` y `metadata.delta`, consumo sobre fila existente (`1 - 2 = -1`) y consumo sin fila previa (creada en `-3`), inventario del técnico (4 filas), alerta (5 filas, el descuadre primero) y filtro por técnico. La sonda corrió además dentro de una transacción externa: el guardián de `adjust()` no anida transacción.
* **Decisiones tomadas al implementar:** el actor viaja como `?int $actorUserId` (el repositorio resuelve rol y nombre) y el ajuste devuelve `VanStockAdjustmentResultDto` con la cantidad previa y el delta; ambas cosas quedaron reflejadas en los contratos §3.2. El puerto asume las dos lecturas de destino (`findTechnician`, `sparePartExists`) en vez de depender de los puertos completos de usuarios y repuestos, que suman más de veinte métodos para dos comprobaciones de existencia.
* **Pendiente de certificar:** la auditoría del delta contra motor real queda para `VanStockApiTest` (WU-4), que es la suite que levanta el esquema completo y ejerce el camino HTTP. La sonda manual de esta rebanada es evidencia, no cobertura.
* **Riesgo:** `none` — todo es código nuevo más una entrada aditiva en el catálogo del visor (`VAN_STOCK_ADJUSTED`); ningún camino existente cambia de comportamiento.

### WU-3 · T-VAN-05 (descuento en el flujo de declaración) — cerrado
* **Commit:** `feat(van-stock): deduct the van stock from every declared catalogue part`
* **Rojo observado:** con el descuento desactivado fallan 3 de las 7 aserciones nuevas —correctivo, preventivo y aditividad—; las de fuera de catálogo siguen verdes en ambos estados, que es justo lo que deben certificar.
* **Verde:** `SparePartTraceabilityServiceTest` 37/37 · `CoordinatorSparePartsControllerTest` 20/20 · `TechnicianSparePartsControllerTest` 19/19 · `TechnicianPreventiveSparePartsAndSegregationTest` 10/10 · batería completa 309 suites / 12 036 aserciones / 0 fallos con las cifras documentadas coherentes.
* **Inyección obligatoria:** `SparePartTraceabilityService` recibe `VanStockService` como parámetro **requerido**, sin valor por defecto. Un colaborador opcional habría convertido un cableado olvidado en un descuento que no ocurre y nadie ve; el compilador lo impide. El coste es el doble compartido `tests/Support/RecordingVanStockRepository.php`, que las cuatro suites del flujo usan.
* **Un solo punto de descuento:** la invocación vive justo después de `insertReplacedPart()` en los dos bucles y está guardada por `!$inserted->isOutOfCatalog()`. El preventivo no duplica la regla: llama al mismo método del mismo servicio (RF-02.3).
* **Sin consulta de disponibilidad:** la suite cuenta las llamadas a `findByPair()` del doble y exige cero. El flujo nunca pregunta cuánto hay, así que no existe un camino por el que el inventario frene una resolución (RD-04, Art. V.1).
* **Riesgo:** `item 3` — modifica la ruta compartida por toda resolución de avería y por toda sustitución preventiva. Cobertura propia observada en rojo y verde; se solicita verificación independiente de esta rebanada antes de cerrar el módulo.

### WU-3 · Cierre tras la verificación independiente — cerrado
* **Verificación independiente:** agente verificador sobre el rango `deda778..16a180a`, en su propia copia de trabajo dentro del directorio temporal. **Sin bloqueos.** RD-01, RD-02, RD-03, RD-04 y RF-02.3 verificados con evidencia `path:line`, e invariantes (a)-(f) cumplidos.
* **Sondas adoptadas como regresión:** sección 7 de `tests/unit/SparePartTraceabilityServiceTest.php` (S1 a S11) más el doble `tests/Support/ThrowingVanStockRepository.php` que S11 necesita. Son once aserciones escritas desde un ángulo que el autor no eligió: dos líneas de la misma pieza, cantidades límite 50 y 51, declaración parcial y declaración mixta.
* **Rojo observado con el descuento desactivado:** 10 fallos de 48 aserciones. Las de ausencia —fuera de catálogo, cantidad rechazada— siguen verdes en ambos estados, que es exactamente lo que deben certificar.
* **Advisoria de duplicación resuelta:** el par `!isOutOfCatalog()` + `consume()` estaba escrito dos veces, una por bucle. Ahora es `deductVanStock(IncidentReplacedPart $part): void`, el punto único con nombre que exige RF-02.3; el técnico se toma de la propia línea persistida, no del parámetro del flujo, de modo que el descuento cae siempre sobre el técnico que la línea registra.
* **Advisoria de aserción vacua resuelta:** la aserción «no consulta la disponibilidad» del apartado 6.1 pasaba también en el estado rojo, porque solo miraba que no se leyera el saldo. Ahora exige además un consumo registrado; se comprobó que falla con el descuento desactivado.
* **Límite declarado trasladado a T-VAN-11:** el saldo no puede bajar de `-999`, así que un descuadre acumulado que rebasara ese suelo abortaría el cierre. Queda escrito como límite conocido del módulo en la tarea de cierre, con su razón.
* **Evaluación nativa de riesgo:** rango `061e7b9..16a180a` → `risk: medium`, `review_due: false` (`under_budget`). La porción revisable sigue abierta con frontera en `061e7b9`; la revisión nativa llegará cuando el acumulado alcance el presupuesto.
* **Verde:** `SparePartTraceabilityServiceTest` 48/48 · batería completa 309 suites / 12 047 aserciones / 0 fallos con las cifras documentadas coherentes.

### WU-4 · T-VAN-06 y T-VAN-07 (API de stock y frontera de privacidad) — cerrado
* **Commit:** `feat(van-stock): expose the van stock API and seal the site privacy boundary`
* **Rojo observado (API):** con las tres rutas retiradas del router, `VanStockApiTest` cae en **22 de sus 30 aserciones**; las 8 que siguen verdes son las de la superficie del técnico, cuya ruta seguía registrada. Es el rojo correcto: la suite distingue las dos superficies en vez de caer en bloque por un error de arranque.
* **Rojo observado (privacidad):** con las claves de stock fuera de la lista vedada del saneador, el apartado 1.1 falla y nombra la fuga: `is_negative`. Las demás claves del módulo (`quantity_available`, `min_threshold`, `is_below_threshold`, `van_stock`) ya caían por el filtro por nombre; la marca de descuadre no lleva prefijo que la delate, así que la lista exacta no es redundante: es la que cierra esa fuga.
* **Verde:** `VanStockApiTest` 30/30 · `VanStockPrivacyTest` 9/9 · `VanStockServiceTest` 29/29 · batería completa 310 suites / 12 058 aserciones / 0 fallos, con README y las cuatro actas sincronizadas a esas cifras.
* **Auditoría del delta certificada contra motor real:** el apartado 5 de la suite de integración comprueba en `audit_log` la entidad `TECHNICIAN_VAN_STOCK`, la acción `VAN_STOCK_ADJUSTED`, el actor de coordinación, `previous_state = {"quantity_available": 4}` y `metadata = {"delta": -3}`. La certificación que WU-2 dejó pendiente queda cerrada, y la sonda manual de aquella rebanada deja de ser la única evidencia.
* **Desvío declarado:** las dos suites unitarias de controlador del plan se sustituyen por la suite de integración, con la razón escrita en la propia T-VAN-06.
* **Adición de alcance:** `listInventory(?int)` en el puerto, el repositorio PDO y el servicio. El contrato §4.2 declara opcionales los dos parámetros de consulta, de modo que la lectura sin filtros —el parque completo en modo inventario— necesita una consulta que el puerto no tenía. Se descartó reutilizar la alerta para ese modo: habría respondido `below_threshold: false` sobre una lista que sí está bajo umbral, y el panel habría mostrado como inventario lo que es una alerta.
* **Riesgo:** `item 2` — abre tres endpoints nuevos y añade un método de lectura al puerto que comparten el repositorio y sus dobles. Las dos superficies quedan detrás del middleware de rol, la Sede no gana ninguna ruta y la lista vedada del saneador se amplía, no se relaja.

### WU-5 · T-VAN-08 y T-VAN-09 (superficies de cliente) — cerrado
* **Commit:** `feat(van-stock): add the technician stock panel and the coordinator stock tab`
* **Rojo observado:** con el contador de alertas contando todas las filas en vez de solo las que el servidor marca, `TechnicianVanStockPanelTest` cae en 2 aserciones (2.4 y 2.6); con la severidad mirando primero el bajo umbral en vez del descuadre, `CoordinatorVanStockTabTest` cae en la 3.1. Ambas reversiones se retiraron antes del commit.
* **Verde:** `TechnicianVanStockPanelTest` 20/20 · `CoordinatorVanStockTabTest` 30/30 · `DesignTokenDebtRatchetTest` 3/3 con el contador intacto —los dos componentes nuevos entran sin un solo literal hexadecimal— · `CoordinatorButtonVariantsGuardTest` en verde · `CoordinatorActionsWrapGuardTest` 7/7 · batería completa 312 suites / 12 108 aserciones / 0 fallos.
* **Dos guardas del panel obligaron a corregir el diseño:** `CoordinatorButtonVariantsGuardTest` prohíbe la geometría en línea en los botones del panel y `CoordinatorActionsWrapGuardTest` exige que todo grupo de botones de fila o barra envuelva sus controles. El primer intento declaraba `min-height: 44px` en cuatro botones y dejaba dos grupos sin `flex-wrap`; los dos defectos se corrigieron en el código, no en las guardas: los botones visten la escalera del sistema y los grupos envuelven.
* **Adición de alcance justificada:** el formulario de ajuste entra en T-VAN-09 porque RF-04.2 exige que el panel permita «cargar y ajustar cantidades y umbrales». La línea de referencias de la tarea citaba solo las lecturas, pero la especificación manda: sin formulario la pestaña habría sido informativa y el módulo solo operable por API. El ajuste abre con el saldo actual precargado y envía el valor absoluto, no un delta.
* **Decisión de montaje del técnico:** bloque `<details>` al principio de la sección *Averías*, no una cuarta pestaña. La barra de navegación móvil ya tiene tres botones a ancho completo y un cuarto habría partido las etiquetas existentes por debajo de 400 px.
* **Catálogo de técnicos del formulario:** sale de `/api/coordinator/users` con rol técnico activo, no de las filas ya cargadas. Derivarlo del inventario habría dejado fuera al técnico sin ninguna pareja declarada, que es justo al primero que hay que cargarle la furgoneta.
* **`api.js` editado por el padre**, como preveía el plan: `coordinator.getVanStocks`, `coordinator.setVanStock` y `technician.getVanStock`.
* **Fallo intermitente ajeno observado:** en una de las pasadas de la batería cayó `HealthCheckServiceTest` (1 suite, y con ella 1 aserción); aislada vuelve a 31/31 y la pasada siguiente cerró 312 suites / 12 108 aserciones sin fallos. Es una intermitencia previa del diagnóstico de salud contra la base de la batería, no de este módulo; queda anotada para que nadie la atribuya al Módulo 18 ni a sus cifras.
* **Riesgo:** `item 1` — dos superficies nuevas de cliente, sin migración ni cambio del contrato HTTP. La de coordinación queda detrás del mismo middleware que el resto del panel y la del técnico no muta nada: no tiene ni un `<input>`.

### WU-6 · T-VAN-10 y T-VAN-11 (guardas de alcance y cierre) — cerrado
* **Commit:** `docs(van-stock): close the module and retire the authorised scope guard`
* **Enmienda del Art. VI:** `van_inventory` sale de la lista de términos vedados de `ConstitutionalAuditTest` §7.1, con la autorización del Product Owner citada en el propio test (21/10/2026, alcance mínimo). La guarda sigue mirando lo que continúa sin autorización: telemetría MDB/DEX y pasarelas de pago. Auditoría constitucional 44/44.
* **Trinquete de tokens:** contador 2268 con el techo en 2269 — no sube; los ficheros de cliente del módulo (dos componentes, las dos vistas tocadas y `api.js`) no añaden ni un literal hexadecimal.
* **Cifras documentadas:** README y las cuatro actas sincronizadas a 312 suites / 12 108 aserciones; `DocMetricsGuard` cierra en COHERENTES.
* **Cierre documental:** la spec canónica y su espejo funcional pasan a «implementada y verificada», los trece criterios de aceptación de §9 se marcan cumplidos, §10 refleja los artefactos realmente redactados y un §11 nuevo declara los dos límites conocidos: el suelo `-999` y el albarán automático no implementado.
* **Feature #5 de `docs/features_pendientes.md`:** estado real con el alcance mínimo y, explícito, lo que quedó fuera. El documento está ignorado por git (`.gitignore:21`), así que el cambio no entra en el commit; queda en el árbol de trabajo como registro local.
* **Pruebas no ejecutadas:** ninguna del módulo. Las dos suites del canal `new_engine` se corrieron aparte contra el motor local (`PostgresVanStockSchemaTest` 18/18 y `VanStockApiTest` 30/30); el resto entra en la batería global.
* **Riesgo:** `item 1` — solo toca pruebas y documentación; no altera código de producción.
