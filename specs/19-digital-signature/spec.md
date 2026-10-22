# ESPECIFICACIÓN FUNCIONAL: FIRMA DIGITAL EN MÓVIL Y ALBARÁN DE SERVICIO

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** 19 · Firma Digital del Responsable de Sede y Albarán de Servicio  
**Documento Espejo Funcional:** `specs/functional/digital_signature_spec.md`  
**Referencia Canónica:** este documento  
**Versión:** 2 · incorpora las respuestas Q1–Q8 del Product Owner (2026-10-22)  
**Estado:** Borrador para aprobación del Product Owner (puerta SDD, fase 2)  
**Fecha:** Octubre 2026  
**Origen:** Feature 4 del catálogo [`docs/features_pendientes.md`](../../docs/features_pendientes.md)  
**Alineación Constitucional:** Art. I (SDD), Art. III.1 y III.3 (inmutabilidad y auditoría), Art. IV.2 (marco de frontend), Art. IV.3 (cero dependencias), Art. V.1 (justificación del cierre), Art. V.4 (privacidad y segregación), Art. V.5 (validación real de imagen), Art. VI.2 (canalización del backlog)

---

## 1. Autorización Formal del Alcance (Art. VI.1)

El Artículo VI.1 prohíbe escribir código para funcionalidades reservadas a fases posteriores mientras el MVP no esté formalmente aprobado. La Fase 1 quedó **formalmente cerrada** por el Product Owner el 2026-10-21 (ver [`specs/18-van-stock/spec.md`](../18-van-stock/spec.md) §1).

Instrucción del Product Owner (2026-10-22), verbatim:

> "revisa el archivo docs/features_pendientes, para empeza la implementacion a venguard de la feature 4. ✍️ Firma Digital en Móvil del Responsable de Sede y Albarán de Servicio"

Consecuencias normativas:

1. El módulo 19 queda autorizado **exclusivamente** en el alcance descrito en §2.1.
2. La feature estaba ya en el backlog declarado (feature 4, Bloque 2 de [`docs/features_pendientes.md`](../../docs/features_pendientes.md)); no es una funcionalidad nacida al margen del análisis (Art. VI.2).
3. La guarda `tests/unit/ConstitutionalAuditTest.php` §7.1 no requiere enmienda: la firma y el albarán de servicio no figuran entre los términos prohibidos (telemetría IoT/MDB/DEX, pasarelas de pago, inventario de furgonetas). Lo que sí exige es que el documento impreso **no** transporte costes ni notas internas (Art. V.4), y eso se verifica en §8.2.

### 1.1. Decisiones del Product Owner que fijan el alcance (2026-10-22)

Tras la revisión QA de la versión 1, el Product Owner aprobó las ocho respuestas propuestas. Son **normativas**: cualquier cambio posterior exige reabrir esta puerta.

| # | Decisión aprobada | Dónde se materializa |
| :--- | :--- | :--- |
| **Q1** | El documento firmado se **congela** en la hoja (`signed_document`): sede, máquina, técnico, diagnóstico, acción, piezas con nombre y cantidad, y fecha de resolución. El albarán imprime el documento congelado, no una proyección viva. | RD-10, RF-03.5, RF-04.1 |
| **Q2** | Tras una reapertura, el albarán de la visita anterior **sigue siendo imprimible como histórico**, con el técnico que la firmó. Una avería abierta no tiene albarán vigente. El técnico firmante conserva el acceso aunque ya no esté asignado. | RD-01, RD-11, RD-17, RF-03.3, RF-05 |
| **Q3** | La unidad de escritura del cierre se **extiende a las piezas y al descuento de furgoneta**, lo que arregla de paso el bug preexistente de piezas duplicadas al reintentar. | RD-16, RF-03.1 |
| **Q4** | La detección de firma en blanco es **solo de cliente** (`getImageData`); no se añade GD a la imagen de producción. Se declara como límite. | RD-12, §11.4 |
| **Q5** | Retención **indefinida** por interés legítimo en la defensa de reclamaciones: no se implementa borrado ni anonimización del firmante. Se documenta finalidad y base legal. | RD-14, RNF-05 |
| **Q6** | El componente nuevo se escribe con **Options API**, como el resto del repositorio, y se declara la desviación preexistente del Art. IV.2. | RD-13, RNF-07 |
| **Q7** | Las visitas preventivas quedan **fuera de alcance**, confirmado por negocio. | §2.2 |
| **Q8** | El documento impreso y la interfaz dicen **«Firma manuscrita digitalizada»**, no «firma digital», para no sugerir una firma electrónica cualificada (eIDAS). | RD-15, RF-04.1 |

---

## 2. Alcance

### 2.1. Dentro del alcance

1. **Captura de la conformidad:** en el modal de resolución correctiva del técnico, un bloque de firma táctil con nombre y documento del responsable de sede que recibe el servicio, con detección de trazo vacío en el cliente.
2. **Atestación de ausencia:** cuando no hay responsable en la sede, el técnico declara el motivo en lugar de la firma. Sin esta salida, la única alternativa del técnico sería inventarse una firma.
3. **Persistencia inmutable y congelada:** una hoja de servicio por resolución, con el documento firmado congelado (`signed_document`), escrita **en la misma transacción** que el cierre técnico y que las piezas y el descuento de furgoneta (Q3).
4. **Albarán de intervención A4:** documento imprimible (o guardable como PDF con la impresión del navegador) que imprime el documento congelado: ticket, sede, máquina, técnico, diagnóstico, solución, piezas sustituidas por nombre y cantidad, y el bloque de firma.
5. **Consulta y reimpresión:** el técnico asignado o firmante y el coordinador pueden volver a abrir el albarán vigente de una avería cerrada y los albaranes **históricos** de visitas anteriores de esa misma avería.

### 2.2. Fuera del alcance (excluido explícitamente)

* **Órdenes preventivas** (Q7): la firma y el albarán cubren la resolución correctiva. Una inspección preventiva no captura firma en este módulo y no emite albarán.
* **Detección de firma en blanco en el servidor** (Q4): la imagen de producción no lleva GD ni Imagick, y añadirlos sería una dependencia de sistema nueva (Art. IV.3). La comprobación vive en el cliente y se declara su límite.
* **Supresión o anonimización de los datos del firmante** (Q5): no se implementa ninguna vía de borrado ni de anonimización; la fila es prueba de una relación contractual y su conservación se declara en RD-14.
* **Envío del albarán por correo o Telegram:** el catálogo de plantillas de notificación está cerrado; añadir una plantilla es otro módulo.
* **PDF generado en servidor, firma electrónica cualificada, sello de tiempo de una TSA y hash de integridad:** el documento se compone en el cliente y se imprime con `window.print()`. La firma es manuscrita digitalizada, no electrónica avanzada (RD-15).
* **Portal de sede:** el responsable de ubicación **no** obtiene en v1 una superficie de descarga. El albarán se firma y se imprime en el dispositivo del técnico, y el coordinador puede reimprimirlo.
* **Contrafirma del técnico y firma múltiple:** un único firmante por hoja.
* **Validación de la letra del DNI o integración con un servicio de identidad:** se guarda el documento tal como lo declara el firmante, porque también firman personas con pasaporte o documento extranjero.
* **Almacenamiento de la firma como fichero en `public/uploads`:** el disco del contenedor en Render es efímero y las evidencias desaparecerían en cada despliegue (ver RD-03).
* **Firma en el cierre por inactividad de sede ni en la cancelación:** solo hay hoja cuando hay intervención.

---

## 3. Usuarios y Perfiles Implicados

| Perfil | Papel en el módulo |
| :--- | :--- |
| **Técnico de campo** | Abre el bloque de firma en su móvil, captura el trazo y los datos del firmante, o declara la ausencia. Imprime o muestra el albarán al terminar. Conserva el acceso a las hojas que firmó aunque la avería se reasigne (Q2). |
| **Responsable de sede / conserje** | Firma en el dispositivo del técnico. **No tiene cuenta ni sesión**: su identidad queda en la hoja, no en el sistema de usuarios. |
| **Coordinador** | Consulta y reimprime el albarán vigente y los históricos de cualquier avería cerrada desde el detalle de la incidencia. |
| **Responsable de ubicación** | Sin acceso a esta superficie en v1 (§2.2). |

---

## 4. Historias de Usuario

### HU-01: Firmar la conformidad del servicio en la sede
**Como** técnico que acaba de reparar una máquina, **quiero** que el responsable de la sede firme en mi móvil, **para** dejar constancia de que estuvo presente y conforme antes de irme.

### HU-02: Cerrar la avería cuando no hay nadie en la sede
**Como** técnico que interviene en una sede sin recepción física, **quiero** declarar el motivo por el que no hay firma, **para** cerrar la avería sin falsificar una conformidad que nadie ha dado.

### HU-03: Entregar el albarán de la intervención
**Como** técnico, **quiero** imprimir o mostrar un albarán A4 con lo que he hecho, **para** dejarlo en la conserjería como justificante del servicio.

### HU-04: Recuperar el justificante desde coordinación
**Como** coordinador, **quiero** reabrir e imprimir el albarán de una avería ya cerrada, **para** atender una reclamación de la sede o una auditoría.

### HU-05: Recuperar el albarán de una visita anterior tras una reapertura
**Como** coordinador, **quiero** imprimir el albarán de la visita anterior aunque la avería se haya vuelto a abrir y cerrar, **para** demostrar qué se firmó aquel día sin que el documento cambie con el tiempo.

---

## 5. Requisitos Funcionales con Criterios de Aceptación (EARS)

### RF-01: Captura de la firma en el modal de resolución

* **RF-01.1:** *Cuando* el técnico abre el modal de resolución de una incidencia `IN_PROGRESS`, el sistema *deberá* ofrecer un bloque de firma con tres datos: nombre del firmante, documento y trazo sobre `<canvas>`.
* **RF-01.2:** *Cuando* el técnico traza con el dedo o el puntero, el sistema *deberá* dibujar el trazo con resistencia a la pérdida de eventos táctiles (seguimiento por `pointer` con captura), sin scroll de la página mientras se firma.
* **RF-01.3:** El sistema *deberá* permitir borrar el trazo y volver a firmar; el borrado limpia el lienzo y descarta el PNG emitido, y **no** toca el nombre ni el documento ya escritos.
* **RF-01.4:** *Si* el técnico envía el formulario con firma pero sin nombre, sin documento o con un trazo sin tinta, *entonces* el sistema *deberá* impedir el envío y señalar el campo que falta.
* **RF-01.5:** El nombre *deberá* tener al menos 3 caracteres tras recortar espacios; el documento se normaliza a mayúsculas y **sin ningún espacio** (se eliminan también los internos) antes de exigir entre 5 y 20 caracteres del alfabeto `[A-Z0-9-]`. Un documento que no encaje tras la normalización se rechaza con el campo señalado.
* **RF-01.6:** La detección de trazo vacío *deberá* hacerse en el cliente contando píxeles oscuros sobre el lienzo: menos de 150 píxeles con luminancia inferior a 200 significa «sin tinta». El servidor **no** puede repetir esta comprobación (RD-12).
* **RF-01.7:** *Cuando* el lienzo cambia de tamaño (rotación del dispositivo, teclado, reflujo), el sistema *deberá* conservar el trazo: copia el mapa de bits antes de redimensionar el lienzo y lo redibuja escalado. Si la copia no es posible, el sistema *deberá* limpiar el lienzo y pedir al firmante que vuelva a firmar, en lugar de imprimir un trazo cortado.
* **RF-01.8:** El lienzo *deberá* rellenarse con fondo blanco opaco y el trazo dibujarse en `#111`, de modo que el PNG exportado sea opaco y legible en papel con independencia de la configuración de impresión de fondos del navegador.

### RF-02: Atestación de ausencia de responsable

* **RF-02.1:** El bloque *deberá* ofrecer una segunda vía: «No había responsable disponible en la sede», con un motivo obligatorio de al menos 10 caracteres.
* **RF-02.2:** Las dos vías son **mutuamente excluyentes**: el sistema *deberá* rechazar un envío que traiga firma y motivo de ausencia a la vez, o ninguno de los dos. En la interfaz, activar la vía de ausencia deshabilita el lienzo y vacía el nombre y el documento, y volver a la firma vacía el motivo.
* **RF-02.3:** El motivo de ausencia es texto libre (por ejemplo, «sede sin conserjería, acceso por llave»), no un catálogo cerrado de causas.

### RF-03: Persistencia atómica y congelada

* **RF-03.1:** *Cuando* la resolución técnica se persiste, el sistema *deberá* escribir **en una única transacción** la línea de cada pieza sustituida, el descuento de stock de la furgoneta, la actualización de las solicitudes de repuestos, la transición a `RESOLVED`, el encolado de la notificación de cierre y la hoja de servicio. Si cualquier paso falla, **nada** de lo anterior queda escrito: ni piezas, ni movimiento de stock, ni cierre (RD-16).
* **RF-03.2:** Una hoja es **inmutable**: no admite `UPDATE` ni `DELETE` (Art. III.1). Una corrección se hace con una resolución nueva, no editando la anterior.
* **RF-03.3:** *Cuando* una avería se reabre dentro de la ventana de 48 h y se vuelve a resolver, el sistema *deberá* añadir una **nueva** hoja y conservar las anteriores. El albarán **vigente** es el de la última resolución; si esa última resolución no tiene hoja, el albarán muestra «Sin firma del responsable» y **nunca** reutiliza la firma de una visita anterior (RD-11).
* **RF-03.4:** La hoja *deberá* registrar al técnico que resolvió, tanto por su identificador como por el nombre congelado en el documento, aunque la avería se reasigne después de una reapertura.
* **RF-03.5:** La hoja *deberá* congelar el documento firmado (`signed_document`) a partir de las filas escritas en la misma transacción: código de ticket, fecha de resolución, sede, máquina, técnico, diagnóstico, acción y piezas con nombre y cantidad (RD-10).
* **RF-03.6:** La hoja *deberá* quedar anclada a la resolución que la produjo mediante un instante propio (`resolution_at`), copia del `resolved_at` vigente en el momento de la inserción. Ese ancla es lo que permite distinguir la hoja vigente de las históricas sin depender de que la avería siga en el mismo estado.

### RF-04: Albarán de intervención

* **RF-04.1:** El sistema *deberá* exponer los datos del albarán de una avería con este contenido exacto y ningún otro:
  * código de ticket y fecha de resolución;
  * nombre y dirección de la sede;
  * código y modelo de la máquina;
  * nombre del técnico que resolvió;
  * diagnóstico y solución aplicada;
  * piezas sustituidas: **nombre y cantidad** (las de catálogo y las declaradas fuera de catálogo); si no hubo sustitución, la mención «Sin sustitución de componentes»;
  * bloque de firma: nombre, documento, trazo, fecha de registro y la etiqueta «Firma manuscrita digitalizada», **o** el motivo de ausencia.
* **RF-04.2:** El albarán *no deberá* contener costes unitarios ni totales, notas internas de taller, datos de SLA, reclamaciones de saldo, datos de otras averías de la sede ni ningún identificador interno que no sea el código de ticket (Art. V.4).
* **RF-04.3:** *Si* la avería tiene hoja, el cuerpo del albarán *deberá* imprimirse desde el **documento congelado** de esa hoja, de modo que una sede renombrada, una máquina reasignada o una pieza renombrada en el catálogo **no** alteren un papel ya firmado.
* **RF-04.4:** *Si* la avería no tiene ninguna hoja (resuelta antes de este módulo o por un camino que no la capturó), el albarán *deberá* componerse con los datos vivos de la incidencia y renderizarse con la mención «Sin firma del responsable», sin inventar datos.
* **RF-04.5:** *Si* la avería no está resuelta y no tiene ninguna hoja, el sistema *deberá* responder con el error `INCIDENT_NOT_RESOLVED` y no componer un documento.
* **RF-04.6:** *Si* la avería está reabierta (o vuelta a cerrar) y la hoja más reciente pertenece a una resolución anterior, el albarán *deberá* marcarse como **histórico** (`current: false`) e indicar la fecha de la intervención que documenta, sin presentarse como el estado actual de la avería.
* **RF-04.7:** El documento *deberá* fluir en formato A4 admitiendo varias páginas (diagnósticos largos, listas de piezas extensas), con salto de página permitido entre secciones pero **nunca** dentro del bloque de firma.

### RF-05: Impresión, consulta y acceso

* **RF-05.1:** El albarán se compondrá en el navegador sobre una hoja A4 y se imprimirá con `window.print()`, sin librerías ni generación de PDF en servidor (Art. IV.3).
* **RF-05.2:** La hoja de estilos `service-sheet.css` *deberá* ocultar todo lo que no sea el documento al imprimir (navegación, botones, fondo) y respetar los márgenes A4, sin depender de que el navegador imprima fondos.
* **RF-05.3:** El técnico *podrá* leer el albarán de una avería **si está asignada a él o si firmó alguna de sus hojas** (Q2); el coordinador, el de cualquiera. Cualquier otro acceso responde `403 SERVICE_SHEET_FORBIDDEN`.
* **RF-05.4:** El albarán *deberá* poder abrirse desde el detalle de la incidencia en coordinación y desde la ruta del técnico tras el cierre.
* **RF-05.5:** La respuesta *deberá* incluir la lista de hojas de la avería (`history`) con su identificador, su fecha de registro, su fecha de resolución, si está firmada y si es la vigente; y el parámetro opcional `?sheet_id=` *deberá* permitir imprimir una hoja histórica concreta, rechazando con `404 SERVICE_SHEET_NOT_FOUND` un identificador que no pertenezca a esa avería.
* **RF-05.6:** Cada lectura del documento *deberá* dejar un evento de auditoría `VIEW_SERVICE_SHEET` sobre el ticket, con el actor, la hoja leída y si era la vigente. Imprimir el papel de una intervención es un acceso a datos personales de un tercero y tiene que poder auditarse.

### RF-06: Auditoría y trazabilidad

* **RF-06.1:** El evento de auditoría del cierre (`RESOLVE_INCIDENT`) *deberá* registrar si hubo firma, quién firmó (nombre y documento) y cuándo, o el motivo de ausencia. El trazo **no** viaja al registro de auditoría.
* **RF-06.2:** La hoja *no* genera una segunda transición de estado ni una notificación nueva; el cierre sigue siendo el único evento que mueve la avería.

---

## 6. Requisitos No Funcionales (RNF)

* **RNF-01 · Atomicidad:** una única unidad de escritura para piezas, stock, solicitudes, cierre y hoja; cero estados intermedios observables. Un fallo en cualquier punto no deja rastro parcial.
* **RNF-02 · Validación real de la imagen (Art. V.5):** el Artículo V.5 fija un tope de 5 MB y una lista de formatos gráficos seguros para las imágenes que el usuario adjunta; la firma es una de ellas, así que **el artículo aplica como cota superior**. El módulo la endurece: solo PNG, y **300 KiB (307 200 bytes)** como tope operativo. El servidor *deberá* decodificar el base64 en modo estricto (tras eliminar espacios y saltos de línea), aceptar el prefijo `data:image/png;base64,`, comprobar la firma binaria PNG, verificar el MIME real con `finfo`, rechazar más de 300 KiB decodificados y más de **2 000 000** píxeles leídos del `IHDR` (defensa frente a bombas de descompresión). Nada de confiar en el `Content-Type` ni en la extensión.
* **RNF-03 · Sin dependencias:** cero paquetes nuevos en backend o frontend; canvas nativo y `window.print()`. La detección de trazo vacío no puede introducir GD (Q4).
* **RNF-04 · Móvil:** el bloque funciona con guante y en pantalla táctil; el trazo se captura con `pointerdown/pointermove/pointerup` y `setPointerCapture`, el lienzo se escala por `devicePixelRatio` para no imprimir una firma borrosa y sobrevive a la rotación sin perder el trazo (RF-01.7).
* **RNF-05 · Datos personales y conservación (Q5):** el nombre, el documento y el trazo del firmante son datos personales de un tercero. Finalidad única: acreditar la conformidad del servicio recibido y sostener la defensa de la empresa ante una reclamación. Base legal: interés legítimo en la prueba de la ejecución contractual. Conservación: indefinida, ligada a la vida del expediente de la avería, **sin** vía de supresión ni de anonimización en v1. Se muestran solo a técnico asignado o firmante, y a coordinación; no se incluyen en ninguna notificación, ni en el portal de sede, ni en exportaciones de negocio.
* **RNF-06 · Rendimiento:** el cierre añade **una** sentencia de inserción (que compone el documento congelado en la propia base) dentro de la transacción ya existente; el albarán se sirve con dos consultas acotadas a una incidencia (documento e historial).
* **RNF-07 · Marco de frontend:** el componente nuevo se escribe con **Options API**, como los demás componentes del repositorio. El Art. IV.2 nombra la Composition API; el repositorio nunca la adoptó. Se declara la desviación como **preexistente y ajena a este módulo** (RD-13): el coste de migrar sesenta componentes no pertenece a esta feature, y escribir uno solo en otro estilo rompería la consistencia.

---

## 7. Resoluciones de Diseño (cierran ambigüedades antes de escribir código)

### RD-01 · Una hoja por resolución, con el documento congelado
La tabla no impone `UNIQUE (incident_id)`. Una reapertura dentro de las 48 h genera una resolución nueva y, con ella, una hoja nueva: la anterior no se borra ni se sobrescribe. La hoja vigente es la de la **última resolución**; las anteriores quedan como histórico consultable.
*Por qué:* borrar la firma anterior destruiría la prueba de la primera intervención (Art. III.1); bloquear la segunda impediría cerrar una avería legítimamente reabierta.

### RD-02 · La firma es opcional en la API y obligatoria en la interfaz
El bloque `service_sheet` viaja en el cuerpo de la resolución y el servidor lo valida **si viene**. Si el bloque llega presente pero **vacío** (`{}`), el sistema responde `422 INVALID_SERVICE_SHEET`: un bloque vacío es un error del cliente, no una renuncia silenciosa. La ausencia de firma solo se declara con la rama de ausencia.
*Por qué:* hacerlo obligatorio rompe el contrato con cualquier cliente que ya resuelve (y con la veintena de suites que lo ejercitan), y no aporta nada mientras el único cliente que cierra averías sea la interfaz del técnico, que sí lo exige. **Límite declarado en §11.1**; el camino de endurecimiento es una validación nueva y el ajuste de las suites que resuelven sin bloque.

### RD-03 · La firma vive en PostgreSQL, no en `public/uploads`
Se guarda como `BYTEA` en la tabla de hojas.
*Por qué:* el contenedor de Render no tiene disco persistente; `public/uploads` se vacía en cada despliegue y las evidencias fotográficas ya conviven con ese riesgo. Una firma que desaparece a los tres días no acredita nada. El coste (≈30 KB por hoja) es despreciable frente a la base.

### RD-04 · El albarán no lleva dinero ni vida interna
Sin `unit_cost_snapshot`, sin `total_cost_snapshot`, sin notas internas, sin reclamaciones, sin SLA.
*Por qué:* Art. V.4 es explícito y el documento sale de las instalaciones en papel. Se verifica con una suite que recorre la carga útil y falla si aparece cualquier clave de coste (Art. III.3 y V.4).

### RD-05 · El documento se compone en el cliente
`@media print` + `window.print()`, siguiendo el patrón ya usado por `SanitaryCertificateModal.js` y `SiteGlobalCertificateModal.js`.
*Por qué:* generar PDF en PHP sin dependencias es escribir un motor de PDF; el navegador ya lo trae y el usuario obtiene «Guardar como PDF» gratis.

### RD-06 · Sin sello criptográfico ni hash de integridad en v1
No se almacena un `sha256` del contenido ni se sella con TSA.
*Por qué:* un hash impreso que nadie puede verificar es decoración, y verificarlo exigiría un endpoint de comprobación que es otro módulo. La garantía de que el papel no cambia con el tiempo la da ahora el **documento congelado** (RD-10) sobre una fila inmutable, más el `audit_log`; el hash solo añadiría una comprobación que nadie hace.

### RD-07 · El documento de identidad se normaliza y se guarda tal cual
Se recorta, se pasa a mayúsculas y se eliminan **todos** los espacios (también los internos), admitiendo el guion; el resultado debe tener entre 5 y 20 caracteres `[A-Z0-9-]`. No se comprueba la letra.
*Por qué:* el firmante puede ser extranjero o firmar con pasaporte; rechazar un documento válido por no encajar en el patrón español bloquearía el cierre en la puerta de la sede. Normalizar antes de validar evita rechazar «12345678 Z» por un espacio, y deja en la base una única forma canónica.

### RD-08 · La ausencia se declara con motivo libre, no con catálogo
Un texto de 10 caracteres como mínimo.
*Por qué:* las causas reales («sin conserje», «acceso por llave», «turno de noche») no caben en un enum sin dejarse la mitad fuera, y el motivo libre es lo que un auditor lee.

### RD-09 · El evento de auditoría del cierre se enriquece, no se duplica
No se crea una transición ni una acción de resolución nueva: el `RESOLVE_INCIDENT` ya existente incorpora el bloque `service_sheet` en su `new_state`. La lectura del documento usa la acción `VIEW_SERVICE_SHEET` sobre la misma entidad `TICKET`, así que **no** hace falta ampliar el enum `audit_entity_type` ni la migración necesita `ALTER TYPE`.
*Por qué:* la hoja no es una entidad de negocio independiente, es un atributo del cierre; dos filas de auditoría para un solo acto complican la lectura del expediente.

### RD-10 · El documento firmado se congela en la hoja (Q1)
La hoja guarda un `signed_document` JSONB que contiene exactamente lo que el albarán imprime y que hoy se lee en vivo:

```json
{
  "version": 1,
  "ticket_code": "INC-000123",
  "resolved_at": "2026-10-22T17:42:11+02:00",
  "location": { "name": "Hospital Sant Pau", "address": "C/ Sant Quintí 89" },
  "machine": { "code": "MAQ-014", "model": "VendMaster 600" },
  "technician": { "name": "Jordi Serra" },
  "diagnosis": "Bobina del relé térmico quemada por pico de tensión.",
  "action": "Sustituido relé térmico y verificado ciclo de frío a 4 ºC.",
  "parts": [ { "name": "Relé térmico 16 A", "quantity": 1 } ]
}
```

El **firmante no se duplica** en el snapshot: vive en sus columnas propias (`signer_name`, `signer_document`, `signature_png`, `absence_reason`), que son inmutables por construcción y no pueden derivar. El snapshot congela solo lo que cambiaría con el tiempo: nombres de sede y de máquina, nombre del técnico, textos de resolución y nombres de piezas.
*Por qué:* sin él, el papel firmado ayer se recompone hoy con datos distintos —una pieza renombrada, una sede renombrada, un segundo cierre— y la firma acabaría acreditando un contenido que nadie firmó (Art. III.3). El snapshot se compone **en la misma sentencia de inserción**, leyendo las filas recién escritas en la misma transacción: no hay hueco entre lo que se firma y lo que se guarda.

### RD-11 · Vigente frente a histórico (Q2)
Una hoja es **vigente** cuando su `resolution_at` coincide con el `resolved_at` de la incidencia y la incidencia está en `RESOLVED` o `CLOSED`. En cualquier otro caso —avería reabierta, avería vuelta a cerrar con otra resolución, o el usuario pidiendo expresamente una hoja anterior— la hoja es **histórica**.
*Por qué:* comparar solo «la más reciente» imprimiría la firma de la visita 1 junto al diagnóstico de la visita 2 cuando la segunda se cerró sin firma por API. El ancla `resolution_at` convierte esa ambigüedad en una comparación exacta, sin depender de reconstruir la resolución desde el historial.

### RD-12 · La firma en blanco se detecta en el cliente (Q4)
El bloque cuenta píxeles oscuros antes de emitir el PNG y se niega a enviar un lienzo vacío. El servidor valida todo lo demás (base64, PNG, tamaño, píxeles, ramas) pero **no** puede distinguir un trazo real de un PNG blanco: la imagen de producción no trae GD ni Imagick.
*Por qué:* añadir una extensión de sistema a la imagen es una dependencia nueva (Art. IV.3) y el riesgo real —que un técnico envíe sin firmar por descuido— lo cubre el cliente, que es quien tiene los píxeles. Un cliente deliberadamente hostil puede saltárselo: límite declarado en §11.4.

### RD-13 · Options API y desviación declarada del Art. IV.2 (Q6)
El componente se escribe con Options API, como los demás componentes del repositorio.
*Por qué:* la consistencia del sistema de componentes es un valor operativo real y migrar sesenta componentes no pertenece a esta feature. Se declara aquí que el repositorio **ya** incumplía el Art. IV.2 antes de este módulo: la desviación es preexistente, consciente y ajena a la firma.

### RD-14 · Conservación y base legal del firmante (Q5)
Retención indefinida, sin borrado y sin anonimización. Finalidad: acreditar la conformidad del servicio y sostener la defensa ante una reclamación. Base legal: interés legítimo en la prueba de la ejecución de un contrato.
*Por qué:* el dato es la prueba de un acto contractual y borrarla dejaría la firma sin firmante, que es lo mismo que no tener albarán. La decisión es del Product Owner y queda documentada aquí para que no se descubra en una auditoría.

### RD-15 · El documento dice «Firma manuscrita digitalizada» (Q8)
La etiqueta visible en el albarán y en la interfaz del técnico describe lo que realmente ocurre: una firma manuscrita capturada como imagen. No se usa «firma digital», que en el marco eIDAS designa una firma electrónica con garantías criptográficas y de identidad que este módulo no ofrece.
*Por qué:* un documento que se entrega a una administración no debe sugerir una validez que no tiene. El título del módulo conserva el nombre de la feature en el backlog, pero el artefacto que sale por la puerta usa la etiqueta exacta.

### RD-16 · La unidad de escritura se extiende a piezas y furgoneta (Q3) — enmienda del Módulo 18
`resolveIncidentWithParts()` pasa a ejecutar **todo** su camino de escritura dentro de una única transacción: inserción de cada pieza, descuento de stock de la furgoneta, actualización de solicitudes, transición a `RESOLVED`, encolado de la notificación y hoja de servicio. La validación (catálogo, cantidades, compatibilidad) sigue ocurriendo **antes** de abrir la transacción.
*Por qué:* hoy las piezas se escriben con autocommit y la transacción solo cubre la transición. Un fallo al escribir la hoja dejaría piezas y descuentos sin cierre, y el reintento del técnico las duplicaría —un bug preexistente que el albarán convertiría en un documento con piezas repetidas—. Esta decisión **enmienda RD-02 del Módulo 18**, que deliberadamente dejaba el descuento fuera de la transacción; la enmienda se registra también en [`specs/18-van-stock/spec.md`](../18-van-stock/spec.md) y en [`specs/technical/van_stock_contracts.md`](../technical/van_stock_contracts.md) §1.3.
*Consecuencia declarada:* un fallo de infraestructura al descontar stock ahora revierte el cierre. No contradice la regla de negocio del Módulo 18 («el inventario nunca frena un cierre»): `consume` hace un `INSERT ... ON CONFLICT ... DO UPDATE` que admite saldos negativos y no consulta disponibilidad, así que la única forma de que falle es un error de base de datos —y entonces la transacción entera debe caer, no dejar un cierre a medias—.

### RD-17 · Quién puede leer el albarán (Q2)
El técnico autenticado puede leer el documento si la avería está asignada a él **o** si figura como técnico de alguna de sus hojas. El coordinador, siempre. Cualquier otro caso es `403 SERVICE_SHEET_FORBIDDEN`.
*Por qué:* una reapertura desasigna al técnico; si la regla fuera «solo el asignado», el profesional que firmó el papel se quedaría sin poder demostrar su propia intervención.

### RD-18 · La lectura del documento se audita
Cada `GET` del albarán escribe `VIEW_SERVICE_SHEET` sobre el ticket, con el actor, el identificador de hoja y si era la vigente. No hay lectura sin rastro.
*Por qué:* el documento contiene el nombre y el documento de identidad de un tercero; saber quién lo ha mirado es parte de la trazabilidad que exige el Art. III.3, y el coste es una fila por impresión.

---

## 8. Impacto en Artefactos Existentes y Guardas

### 8.1. Artefactos que el módulo debe tocar

| Capa | Artefacto | Cambio |
| :--- | :--- | :--- |
| Base de datos | `database/supabase_init.sql` | Tabla `incident_service_sheets` con `signed_document`, `resolution_at`, `CHECK` de rama y de tamaño, índice descendente y trigger de borrado duro (forma canónica para bases nuevas). |
| Base de datos | `database/supabase_migrations/010_incident_service_sheets.sql` | Misma tabla para bases ya desplegadas (idempotente). |
| Core | `src/Core/Domain/Model/IncidentServiceSheet.php` | Entidad inmutable con las dos ramas (firma / ausencia) y el técnico que resolvió. |
| Core | `src/Core/Domain/Repository/IncidentServiceSheetRepositoryInterface.php` | Puerto: `insert()`, `findDocumentByIncidentId()` y `listByIncidentId()`. |
| Core | `src/Core/Service/ServiceSheetValidator.php` | Validación pura del bloque: base64, PNG, tamaño, píxeles, ramas, normalización del documento y rechazo del bloque vacío. |
| Core | `src/Core/Domain/Exception/InvalidServiceSheetException.php` | Error de dominio con la lista de campos. |
| Application | `src/Application/Service/SparePartTraceabilityService.php` | Transacción única (piezas, stock, solicitudes, cierre, hoja); argumento obligatorio del repositorio; auditoría enriquecida. |
| Application | `src/Application/DTO/ServiceSheetDto.php` | Metadatos de la hoja en la respuesta del cierre (nunca el trazo ni el snapshot). |
| Infrastructure | `src/Infrastructure/Repository/PdoIncidentServiceSheetRepository.php` | `INSERT ... SELECT` que compone el documento congelado; consulta del documento vigente o histórico; listado de hojas. |
| Presentation | `src/Presentation/Controller/TechnicianController.php` | Captura del bloque en `resolveIncident`; `GET` del albarán con regla de acceso de RD-17 y auditoría de lectura. |
| Presentation | `src/Presentation/Controller/CoordinatorController.php` | `GET` del albarán de cualquier avería (clase real de las rutas `/api/coordinator/incidents/*`). |
| Presentation | `src/Presentation/Routing/AppRouter.php` | Dos rutas `GET` nuevas; ninguna `POST` nueva. |
| Frontend | `public/assets/js/components/TechnicianServiceSheetBlock.js` | Canvas (fondo blanco, trazo `#111`, `devicePixelRatio`, conservación del trazo al redimensionar), nombre, documento, borrado, detección de tinta y vía de ausencia. |
| Frontend | `public/assets/js/components/ServiceSheetModal.js` | Hoja A4 (vigente o histórica), selector de hojas anteriores, etiqueta «Firma manuscrita digitalizada», `window.print()`. |
| Frontend | `public/assets/js/views/TechnicianRouteView.js` | Montaje del bloque y botón «Ver albarán» tras el cierre. |
| Frontend | `public/assets/js/components/CoordinatorIncidentDetailModal.js` | Botón «Albarán de servicio». |
| Frontend | `public/assets/js/api.js` | Métodos de lectura del albarán (con `sheet_id` opcional). |
| Frontend | `public/assets/css/service-sheet.css` + `public/index.html` | Estilos de impresión A4 y su `<link>`. |
| Tests | `tests/Support/PostgresFixtureBuilder.php` | Crea la tabla nueva para las suites de integración. |
| Docs | `README.md` (tabla de endpoints, cifras de batería) | Endpoints nuevos y cifras actualizadas por el guardián de métricas. |

### 8.2. Guardas que el módulo debe dejar en verde

1. **`ConstitutionalAuditTest`** — sin cambios de términos. La comprobación de que la carga útil del albarán no contiene claves de coste (`unit_cost`, `total_cost`, `reference_cost`) se implementa en la suite de privacidad (§4.1 del plan), no en el test estático, que solo lee ficheros.
2. **`DocMetricsGuard`** — las cifras de la batería cambian al añadir suites: hay que actualizar la frase canónica, la insignia y el bloque de fases en los documentos auditados.
3. **`SchemaConvergenceParityTest`** — la migración 010 y la forma canónica de `supabase_init.sql` deben describir la misma tabla.
4. **`PostgresFixtureBuilder`** — la base de pruebas debe crear la tabla nueva; si no, las suites que insertan hojas fallan con un error de relación inexistente.
5. **`SqlDialectPortabilityTest`** — la tabla nueva y el `INSERT ... SELECT` con `jsonb_build_object`/`jsonb_agg` son dialecto PostgreSQL; el módulo no promete otro motor y la guarda debe seguir en verde.

---

## 9. Criterios de Aceptación / Definition of Done

1. Un técnico resuelve una avería con firma capturada y el albarán resultante muestra nombre, documento, trazo, la etiqueta «Firma manuscrita digitalizada», diagnóstico, solución y piezas con su cantidad.
2. Un técnico resuelve sin responsable presente declarando el motivo, y el albarán lo imprime en lugar del bloque de firma.
3. El intento de enviar firma y ausencia a la vez, o un bloque `{}`, devuelve `422 INVALID_SERVICE_SHEET` y la avería **sigue** `IN_PROGRESS`.
4. Un fallo en cualquier escritura del cierre (pieza, descuento de stock, transición o hoja) revierte **todo**: no quedan piezas sueltas, ni movimiento de stock, ni cierre, y el reintento no duplica piezas.
5. El albarán no contiene costes ni notas internas; la suite que lo audita está en verde.
6. Una avería resuelta antes del módulo sigue imprimiendo su albarán con «Sin firma del responsable».
7. Tras una reapertura y un segundo cierre firmado, existen dos hojas: el albarán devuelve la segunda como vigente y la primera se imprime como histórica con su técnico y su contenido congelado.
8. Tras una reapertura y un segundo cierre **sin** hoja, el albarán **no** muestra la firma de la primera visita: aparece «Sin firma del responsable».
9. Renombrar la sede o una pieza del catálogo después de firmar **no** cambia el albarán ya firmado.
10. El técnico firmante sigue leyendo su hoja tras ser desasignado; otro técnico recibe `403 SERVICE_SHEET_FORBIDDEN`; cada lectura deja un evento `VIEW_SERVICE_SHEET`.
11. El borrado directo de una hoja en base de datos falla por el trigger de protección (Art. III.1).
12. Impresión A4 verificada en navegador de escritorio y en móvil, incluido un albarán de varias páginas (E2E manual documentado).
13. Batería global en verde, cifras documentadas coherentes y rama con un único commit por unidad de trabajo.

---

## 10. Trazabilidad Documental

* Feature origen: [`docs/features_pendientes.md`](../../docs/features_pendientes.md) §4.
* Decisiones del Product Owner: §1.1 (Q1–Q8, 2026-10-22).
* Reglas constitucionales: Art. III.1, III.3, IV.2, IV.3, V.1, V.4, V.5, VI.2.
* Contratos técnicos: [`specs/technical/digital_signature_contracts.md`](../technical/digital_signature_contracts.md).
* Plan técnico: [`specs/19-digital-signature/plan.md`](plan.md).
* Tareas y bitácora: [`specs/19-digital-signature/tasks.md`](tasks.md).
* Enmienda del Módulo 18 (Q3): [`specs/18-van-stock/spec.md`](../18-van-stock/spec.md) RD-02 y [`specs/technical/van_stock_contracts.md`](../technical/van_stock_contracts.md) §1.3.
* Precedente de patrón de impresión: `public/assets/js/components/SanitaryCertificateModal.js`.
* Precedente de escritura atómica en el cierre: `SparePartTraceabilityService::resolveIncidentWithParts()` (Módulo 18).

---

## 11. Límites Conocidos y Verificación Final

1. **La API no exige la firma** (RD-02): la obligación vive en la interfaz del técnico. Un cliente directo puede cerrar sin hoja. Endurecerlo es una validación nueva en `resolveIncidentWithParts` y el ajuste de las suites que resuelven sin bloque.
2. **Sin verificación criptográfica del documento** (RD-06): no hay hash ni sello de tiempo externo. La inmutabilidad la dan la fila, el trigger de borrado y el documento congelado.
3. **Sin superficie para el responsable de ubicación** (§2.2): la descarga desde el portal de sede queda para una revisión posterior, con su propia prueba de privacidad.
4. **La firma en blanco solo se detecta en el cliente** (RD-12): el servidor no puede inspeccionar píxeles sin GD, que la imagen de producción no trae. Un cliente hostil puede colar un PNG blanco.
5. **La tabla de piezas conserva el nombre vivo:** fuera del albarán, `incident_replaced_parts` sigue sin congelar el nombre de la pieza de catálogo. Este módulo lo congela **en la hoja firmada**, que es lo que se imprime; ampliar el snapshot a la tabla de piezas exigiría enmendar el Módulo 2 y queda fuera de alcance.
6. **La reimpresión no regenera la firma**: imprime el trazo guardado y el documento congelado, que es exactamente lo que se firmó.
7. **Sin vía de supresión ni anonimización del firmante** (Q5, RD-14): la conservación es indefinida y la base legal está declarada. Cualquier cambio futuro exige reabrir esta puerta.
8. **Desviación preexistente del Art. IV.2** (Q6, RD-13): el repositorio usa Options API; este módulo la mantiene por consistencia en lugar de migrar sesenta componentes.
9. **Las evidencias fotográficas siguen almacenadas en el sistema de ficheros efímero**; este módulo no lo corrige (fuera de alcance), pero deja constancia del riesgo.
