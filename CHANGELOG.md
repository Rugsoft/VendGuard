# Changelog · VendGuard

Registro de los hitos de producto y de los módulos cerrados bajo el ciclo SDD.
El proyecto no publica versiones semánticas independientes: cada entrada corresponde
a un módulo funcional certificado por la batería global.

## [Módulo 24] · Cierre de cobertura del Modo Sin Conexión PWA — 2026-10-28

**Estado:** Cerrado · cobertura EARS completa y documentación alineada con el código.

### Añadido

- Certificación directa del indicador permanente de antigüedad y conectividad (`ageLabel` / `connectionLabel`) del Módulo 24.
- Certificación del orden FIFO de la bandeja y del rechazo 4xx de un comentario intermedio sin bloquear la resolución posterior.
- Certificación de las guardas online-only de login, turno, relevo y avería oportunista, del albarán A4 diferido y de la insignia local de parada.
- Contenido real del paquete `/bootstrap`: ruta, preventiva, catálogo por modelo y saldo de furgoneta, no solo la presencia de las claves.
- Prueba de concurrencia real de la idempotencia (`OfflineSyncMutationConcurrencyTest`, canal `new_engine`): dos conexiones PostgreSQL certifican que la petición perdedora espera en el índice único y responde como repetición sin ejecutar el dominio, y que un veredicto negativo del dominio deshace la reserva.

### Corregido

- Idempotencia atómica: la reserva de la clave `(technician_id, client_mutation_id)` se escribe dentro de la misma transacción que la transición de dominio y **antes** de ejecutarla. Una segunda petición con la misma clave espera al ganador y responde como repetición en lugar de competir por la transición, y un veredicto negativo del dominio deshace la reserva en vez de quemar la clave.
- Rescate de envíos interrumpidos: un elemento que quedó en `SYNCING` porque la pestaña murió a mitad de envío vuelve a la cola al hidratar y en cada pasada de sincronización, en lugar de bloquear el cierre de sesión para siempre sin nada que enviar.
- Veredictos `4xx` permanentes: un `404`, `400` o `410` (antes tratado como caída de red) queda aislado como conflicto y el FIFO continúa; un `413` conserva el trabajo y retira solo la evidencia que el servidor rechazó. Solo `408`, `425`, `429` y los `5xx` se reintentan.
- Contratos del Service Worker: `CACHE_NAME` v2, ausencia de `/` en el App Shell y caída explícita a `/index.html` en navegación sin red.
- Matriz de trazabilidad del Módulo 24: referencias de pruebas, recuentos y estado del DoD 1 alineados con la corrida real.

### Corregido en la revisión del PR #9 — 2026-10-29

- **Alta atómica de la cola (H4):** el `sequence_order`, el alta en `mutation_outbox`, el cierre del inicio previo y la proyección optimista viajan en una sola transacción sobre ambos almacenes. Una colisión del índice único ya no deja el cambio local sin su entrada en la cola: la transacción se deshace entera y el alta se reintenta releyendo el máximo.
- **Copia local transaccional (M2):** toda lectura-modificación-escritura del snapshot (refresco, turno, proyección, reversión de stock) pasa por `mutateSnapshot()`, encadenada dentro de la pestaña y serializada entre pestañas; un mutador asíncrono se rechaza con `SNAPSHOT_MUTATOR_ASYNC` en lugar de cerrar la transacción antes de escribir.
- **Coordinación entre pestañas (H5):** la bandeja se abre bajo `navigator.locks` (`vendguard-offline-sync`) más un mutex local —también sin Web Locks—, de modo que dos pestañas del mismo técnico no vacían la misma cola a la vez; un `BroadcastChannel` avisa del cambio para que la otra relea su estado, sin eco y sin que una pestaña que solo escucha escriba nunca.
- **Plazo propio de las subidas (M1):** las peticiones `multipart/form-data` con evidencia usan `UPLOAD_TIMEOUT_MS = 30000` (5 MB no caben en 5,0 s sobre cobertura móvil débil) y el aviso de plazo agotado informa de 30,0 segundos; el resto de peticiones conserva los 5,0 s y el `ATTR_TIMEOUT` del driver PDO no cambia.
- **Evidencia binaria y purga por sesión (M3):** la base local sube a la v2 y guarda la foto como `Blob` (sin la copia base64 un 33 % mayor), con migración de las evidencias ya encoladas en la v1; la copia descarta el correo y el nombre del contacto de sede, purga las sesiones ajenas sin trabajo pendiente y las copias caducadas vacías, y el cierre de sesión sigue borrando todo el dispositivo.
- **Volcado de conflicto acotado a la ruta del técnico (B1):** un `stop_id` de una avería que nunca fue suya o de una preventiva ajena responde `404` sin escribir Nota Interna ni mutación, en lugar de aceptar cualquier identificador.
- **Validación de la evidencia antes de codificarla (B2):** el tamaño y el formato se comprueban sobre el archivo antes de leer sus bytes; una foto de más de 5 MB se rechaza sin codificarla en memoria.
- **Certificación del cerrojo en navegador real:** `OfflineSyncLockBrowserTest` abre dos pestañas de Chromium sobre el mismo perfil, comparte `IndexedDB` y arranca el vaciado de la misma cola a la vez. Un servidor de pruebas mide los envíos en vuelo: con cerrojo hay 3 envíos y `maxConcurrent = 1`; en el control negativo sin cerrojo se ven más de 3 envíos y solapamiento, de modo que el verde no puede venir de un arnés que no sabe detectar el fallo. La suite vive en el canal `new_engine` porque exige navegador y sale con código 2 si no lo encuentra.
- **El respaldo del cerrojo ya no reejecuta la tarea (R1):** `withWebLock()` solo ejecuta la tarea sin cerrojo cuando el navegador **deniega la concesión**; si el callback ya arrancó, su excepción se propaga tal cual. Antes, cualquier fallo de la tarea se tomaba por un cerrojo rechazado y se reintentaba en el acto: un volcado con la red caída lanzaba una segunda subida de hasta 5 MB y un alta que fallara después de escribir podía duplicar la mutación con una clave nueva.
- **El volcado de conflicto ya no deja fotos huérfanas (R3):** la evidencia se sube antes de saber si algún registro la va a referenciar, así que el controlador la retira cuando el volcado es una repetición idempotente, una orden preventiva (que solo anexa texto) o un rechazo `404` por titularidad. Antes, cada reintento de un volcado ya aplicado dejaba en `public/uploads` un archivo de hasta 5 MB que nadie borraba.

---

## [Módulo 23] · Órdenes de Carga de Furgoneta y Cruce de Stock en Triaje — 2026-10-26

**Estado:** Cerrado · especificación funcional, contratos técnicos, implementación y certificación en verde.

### Añadido

- Órdenes de carga `van_restock_orders` y líneas `van_restock_order_items` (Tablas 32 y 33) con ciclo
  `PENDING_PICKING` → `DISPATCHED` → `CONFIRMED_BY_TECH` / `CANCELLED`, índice único parcial
  `uq_van_restock_open_per_tech` para una sola orden abierta por técnico (D3) y disparadores
  `trg_no_delete_*` de blindaje constitucional (Art. III.1).
- Propuesta de reposición que combina el umbral mínimo con las solicitudes pendientes de averías activas
  (fórmula aditiva de «Ambos», Resolución QA 3), ordenación determinista por motivo, saldo y referencia,
  y generación en lote que omite técnicos sin déficit, ausentes o con orden abierta.
- Edición de órdenes en preparación con **baja lógica** de líneas (`removed_at` / `removed_by`) y
  reactivación al volver a añadir la pieza, sin `DELETE` físico (Art. III.1 y Resolución QA 1).
- Expedición con cantidades reales servidas (`dispatched_qty`), despacho parcial permitido y tope
  `saldo + cantidad ≤ 9.999`; cancelación con justificación de al menos 20 caracteres reales (`btrim`,
  Art. V.1) y motivo persistido en auditoría.
- **Albarán de Picking A4** imprimible con CSS `@media print` y `window.print()`, cabecera de orden,
  casillas físicas y firmas, sin ningún coste ni precio (Art. V.4).
- Recepción móvil en un toque con independencia del estado de turno, incremento atómico de
  `technician_van_stocks` bajo `SELECT ... FOR UPDATE` y comprobante de la última carga confirmada
  (Resoluciones QA 4 y QA 5).
- Cruce informativo `van_stock_match` en dos niveles (piezas solicitadas y familia compatible) y
  `van_stock_readiness` en las averías activas, sin alterar `TechnicianWorkloadScorer`, `total_score`,
  `score_breakdown` ni el escalado sanitario del Art. II (D2 y C1).
- Aislamiento horizontal de la orden móvil al `user_id` autenticado y opacidad total en el Portal de
  Sede y el canal QR (Art. V.4).

### Corregido

- `AuditEvent` reconoce `VAN_RESTOCK_ORDER` en su whitelist, alineada con el enum `audit_entity_type`
  de la migración 016; sin esta entrada la exportación del historial de auditoría fallaba al hidratar
  los eventos de las órdenes de carga.
- La suite de jornada cierra su rango de informe en el día real de la corrida, de modo que las sesiones
  creadas con la fecha actual no queden fuera al cambiar el día.

### Certificación

- Recorrido funcional del módulo **versionado como suite de la batería**: `tests/Manual/VanRestockWalkthroughRunner.php`
  ejercita el ciclo completo por HTTP real —cURL contra `php -S`, con el sobre `{success, data}` y los códigos de
  estado que ve el navegador— sobre base propia (purga y re-siembra en cada corrida): propuesta, alta, bloqueo de
  la segunda orden abierta, cancelación justificada, albarán A4, baja lógica, expedición parcial, recepción móvil,
  doble toque rechazado, comprobante, privacidad de sede e insignias de triaje, en **72 aserciones**.
- 362 suites y 13.829 aserciones ejecutadas en esa corrida · 0 fallos · base restaurada a semillas.
  *(La cifra vigente se declara una sola vez, en el README, y la audita `DocMetricsGuard`.)*
- Canal `new_engine`: 25 suites evaluadas, 24 en verde y 744 aserciones; `PoolerLatencyMeasurementTest`
  sigue declarando otro contrato por falta de `DB_SSL_ROOT_CERT` y no se omite en silencio.

## [Módulo 17] · Corrección de los ocho hallazgos de la verificación funcional — 2026-10-21

**Estado:** Cerrado · H-1 a H-8 corregidos y certificados por la batería global.

### Corregido

- **H-1 · Edición sustitutiva del territorio:** guardar los códigos postales de una zona
  envía la colección final; los que ya eran suyos son un no-op (reenviar la lista completa
  nunca falla) y los ausentes se liberan con `released_at`, sin `DELETE` (Art. III.1). Las
  sedes heredadas de un código liberado recalculan su zona, las forzadas a mano no se tocan,
  y el resultado publica `released_postal_codes` junto al balance de traslados.
- **H-2 · Semilla territorial:** `SeedRunner::seedAll()` siembra las tres zonas de Barcelona,
  sus diez códigos postales, el cuadrante demo y la herencia de cada sede; el resumen publica
  el territorio canónico en vigor y `TestDataCleaner` drena `operational_zones` como raíz para
  que ninguna corrida deje el catálogo vacío ni acumule zonas entre suites.
- **H-3 · Cifras documentales:** batería global **307 suites · 11.985 aserciones · 0 fallos**,
  con la frase canónica, la insignia y los cuatro informes vivos sincronizados.
- **H-4 · Canal `new_engine`:** `tests/run_new_engine.php` ejecuta la categoría con un comando
  propio, exige `PG_TEST_DSN` y un motor alcanzable (código `2` si falta cualquiera de los
  dos), descubre las suites por su marca y lista aparte las que declaran otro contrato de
  entorno en lugar de omitirlas. Documentado en el README y en `deploy/local/postgres/`.
- **H-5 · Salvaguarda de frío en la UI:** el cliente exige la justificación de descarte con
  la misma condición que `ColdChainOverridePolicy` —primer puesto en guardia—, tanto en el
  despacho unitario como en el lote del mapa; ya no pide un texto que el servidor no reclamaría.
- **H-6/H-7 · Alta de avería desde la sede:** `POST /api/incidents` certifica JSON puro
  (`application/json`) además de multipart, y el `201` publica el contexto del equipo
  (`machine_code`, `machine_model`, `machine_type`) y de la sede (`location_site_code`,
  `location_name`) que el contrato §3.2 prometía.
- **H-8 · Alcance de la sugerencia del lote:** la referencia del ranking se elige por urgencia
  real (crítica primero) y el panel rotula sobre qué ticket se calculó y a cuántos avisos de
  la sede se aplicará el técnico elegido.

## [Módulo 17] · Zonificación Operativa y Asignación Asistida — 2026-10-20

**Estado:** Cerrado · especificación funcional, contratos técnicos y batería de pruebas en verde.

### Añadido

- Zonas operativas delimitadas por códigos postales, con nombre, código único y color
  corporativo `#RRGGBB`, y liberación lógica del CP (`released_at`) para que reasignar
  un código nunca exija borrarlo (Art. III).
- Herencia automática de zona en la sede por su CP, con excepción manual atómica
  (`is_manual_override`) y auditoría del cambio; el panel de sedes señala los centros
  sin demarcación.
- Cuadrante de cobertura con zona principal y suplencias, protegido por el índice
  parcial `uq_technician_primary_zone`: un técnico no puede tener dos titularidades
  vivas y la degradación de la anterior queda registrada.
- Motor `TechnicianAssignmentSuggester` con `TechnicianWorkloadScorer`: afinidad
  territorial, presencia in situ, paradas en la misma zona, carga viva, pausas por
  falta de acceso y guardia sanitaria 24/7, con desempate determinista y fallback
  Haversine medido desde la parada actual, la última resuelta o la Base Central.
- Endpoint `GET /api/coordinator/incidents/{id}/suggested-technicians` con el ranking
  desglosado, y consola de administración territorial como cuarta subpestaña de
  Administración (zonas, CPs, cuadrante y traslados en bloque).
- Panel de asignación con despacho del técnico recomendado en dos toques
  (`[⚡ Asignar Sugerido]`), banner de emergencia sanitaria y campo obligatorio
  `override_justification` de 20 caracteres reales al descartar la recomendación de
  frío, auditado como `INCIDENT_ASSIGNMENT_SUGGESTION_OVERRIDDEN` sin tocar el
  `reassignment_reason` del flujo de reasignación (Art. II y Art. V.1).
- Filtro por zona operativa en el mapa territorial de coordinación, con los centros
  teñidos por el color de su demarcación y resuelto en cliente para no alterar el
  contrato cartográfico.
- Transferencia transaccional de códigos postales entre zonas: bloqueo `FOR UPDATE`
  de las sedes heredadas, reasignación en bloque y auditoría dual matriz-sede.

### Añadido (2026-10-20, cuadrante legible)

- El cuadrante de una zona se lee **con identidad**: nombre, código de operador,
  condición de titular o suplente, guardia 24/7, fecha de alta y estado, servido bajo
  demanda en `GET /api/coordinator/zones/{id}/technicians`. El listado del catálogo
  sigue publicando solo contadores para no pagar una consulta por zona (RNF-01), y la
  proyección deja de incluir el correo del técnico (QA 15).
- La baja del cuadrante se ejecuta desde la fila del técnico que el coordinador está
  viendo; desaparece el selector con todo el directorio. Una vinculación viva de un
  usuario desactivado se muestra con la insignia «Baja» y sigue siendo retirable
  (QA 10).
- Nueva lectura inversa «Por técnico» en la consola territorial
  (`GET /api/coordinator/technicians/quadrants`): zonas vivas por profesional con su
  rol en cada una, coloreadas con el color de la demarcación, y señalización expresa de
  quien no cubre ninguna. Se resuelve en una sola sentencia con `LEFT JOIN` y solo se
  carga cuando el coordinador la pide.

### Corregido (2026-10-20, auditoría de cierre)

- El panel de sedes incorpora la columna de zona operativa con el color de la
  demarcación y la insignia «Sin Zona Asignada» para los centros cuyo código postal
  no resuelve ninguna zona activa (RF-03.4).
- La edición de sede permite forzar una zona, dejarla sin zona o restablecer la
  herencia por código postal; la excepción viaja al servidor después del guardado y
  solo cuando el coordinador la cambia (RF-03.2, RF-03.3).
- El «cupo máximo de paradas» de RF-06.1 deja de ser una cláusula sin magnitud: se
  fija en diez paradas vivas por técnico (Resolución QA 14) y una zona con todo el
  cuadrante en el cupo se declara desatendida sin excluir a sus técnicos del ranking.
- La preservación de averías que exige RF-03.3 y los casos límite 3 y 4 queda
  certificada con aserciones sobre el traslado de CP, la desactivación de la zona y
  la baja del técnico del cuadrante.

### Corregido (2026-10-20, despliegue en Render)

- El arranque del contenedor vuelve a levantar sobre una base que ya servía antes de
  este módulo: `locations` existía sin `zone_id`, `CREATE TABLE IF NOT EXISTS` no
  añade columnas y el bloque de la clave foránea territorial abortaba el paso 3/6 con
  `SQLSTATE 42703` en los tres reintentos. El DDL base declara ahora las tres columnas
  territoriales de forma idempotente junto a la tabla, así que los índices y la clave
  foránea se materializan en el mismo paso y la migración `007` solo confirma.
- `CloudDeploySchemaParityTest` gana el caso 6: reproduce un `locations` anterior al
  módulo, reaplica el DDL base y certifica columnas, índice y clave foránea antes de
  la capa incremental, además de la convergencia final.

### Corregido (2026-10-20, modales territoriales)

- Los cinco diálogos de la consola territorial (alta, edición, liberación, códigos
  postales y cuadrante) se pintaban sobre la cáscara `vg-modal` a pelo: esa clase no
  declara relleno ni anatomía, así que el título y los controles tocaban el borde de la
  tarjeta y las acciones quedaban dentro del área que se desplaza. Ahora los cinco
  entran por el componente compartido `ModalDialog` —cabecera con hairline, cuerpo de
  20 px y pie separado—, igual que el resto de diálogos del panel, y ganan cierre con
  `Escape`, clic en el fondo, foco en el diálogo y bloqueo del desplazamiento de fondo.
- Los botones de envío viven en el pie pero siguen enviando el formulario del cuerpo
  (atributo `form`), de modo que el Enter del teclado no pierde su comportamiento.
- `OperationalZoneManagementTabTest` gana dos aserciones (1.10 y 1.11) que impiden
  reintroducir un diálogo fuera del componente compartido o sin pie de acciones.

### Corregido (2026-10-21, ranking territorial legible)

- El ranking de asignación amontonaba cinco datos en dos renglones: nombre y afinidad
  compartían línea, las insignias caían cuatro píxeles debajo y la puntuación —centrada
  a media altura por el `align-items-center` de la fila— se montaba sobre ellas. La fila
  pasa a una rejilla de dos pistas (`vg-ranking-row`): identidad arriba a la izquierda,
  puntuación anclada arriba a la derecha e insignias en su propio renglón, con 6 px entre
  fichas, 8 px bajo el nombre y 6 px entre filas.
- Las insignias dejan de ser `<span class="vg-chip">` en línea, sin borde ni envoltura:
  el bloque compartido `vg-ranking-badges` / `vg-suggestion-badges` las envuelve con
  separación y les da su filo hairline, un peldaño por debajo del chip suelto.
- La ficha del técnico recomendado recibe el mismo tratamiento, porque sufría el mismo
  amontonamiento en el mismo modal, y los bloques del panel se separan con la escala del
  sistema (`vg-suggestion-block`) en lugar de la utilidad de empaquetado `mb-3`.
- `CoordinatorDashboardAssignPanelTest` reescribe la aserción 1.7 y añade la 1.8: la
  geometría de la fila vive en la hoja de tokens (clase, no `style` en línea) y las pistas
  declaran su holgura, para que la próxima plantilla no vuelva al amontonamiento.

### Seguridad

- El portal de la sede no recibe el nombre, código ni color de su zona operativa, ni
  la afinidad de los técnicos (Art. V.4).

### Verificado

- Batería global: 307 suites y 11.985 aserciones al 100 % en verde, con la guarda de
  coherencia documental activa y la base del arnés restaurada a semillas.
- Canal `new_engine` contra PostgreSQL: esquema territorial, repositorio, servicio de
  aplicación, traslado de CPs, API de zonas, sugerencias, salvaguarda de frío y
  privacidad de sede, todos en verde.
- Superficies Vue: consola territorial (51 aserciones), panel de asignación (32),
  mapa territorial con filtro por zona (101) y las 72 suites ESM sin fallos.

## [Módulo 16] · Avería Oportunista In Situ — 2026-10-19

**Estado:** Cerrado · especificación funcional, contratos técnicos y batería de pruebas en verde.

### Añadido

- Alta y asunción de averías sobre otras máquinas de la sede en la que el técnico ya
  está interviniendo, con modalidades `NEW` y `ASSUMED` y trazabilidad en auditoría.
- Ventana de gracia de 30 minutos tras resolver o pausar una parada, con tarjeta
  temporal «Sede recién visitada (30 min)» y margen de 5 minutos si el formulario ya
  estaba abierto.
- Flujo móvil de tres toques (`TechnicianOpportunisticModal`) con catálogo de máquinas
  de la sede, aviso sanitario de otra sede y bloqueo de convivencia entre sedes.
- Reapertura in situ en garantía de 48 horas y sustitución atómica del ticket por un
  sucesor limpio en la tercera reincidencia (avería crónica).
- Insignias «Oportunista In Situ · Nueva/Asumida» en triaje y ficha de detalle, KPI de
  desplazamientos evitados y desglose por submotivo.
- Aviso oportunista en el portal de sede con el técnico anonimizado como
  «Servicio Técnico Oficial · Operador #[Código]» (Art. V.4).
- Notificaciones de la nueva modalidad en el centro de salida: alerta prioritaria a
  Coordinación al posponer una parada de frío, aviso a la sede en reaperturas y
  desasignación por correo al técnico saliente (sin Telegram, RF-TG-06).

### Verificado

- Batería global: 293 suites y 11.507 aserciones al 100 % en verde, con la guarda de
  coherencia documental activa y la base del arnés restaurada a semillas.
- Cobertura específica del módulo: esquema, políticas puras, escrituras atómicas,
  notificaciones y privacidad, servicio de aplicación, API y superficies Vue.
