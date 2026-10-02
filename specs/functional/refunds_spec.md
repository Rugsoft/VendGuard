# Especificación Funcional: Módulo de Reintegros e Importe Retenido / Dinero Tragado (Módulo M5)

## 1. Contexto y Objetivo

### 1.1 Contexto del Negocio
En el funcionamiento cotidiano de los parques de máquinas dispensadoras de VendGuard, se producen anomalías en los sistemas de cobro y devolución (atasco físico de monedas en el selector o canaleta, fallo del validador de billetes o fallo en la entrega del producto seleccionado tras haber cargado el importe). En el modelo operativo previo:
- Cuando un consumidor final pierde dinero en una máquina, suele acudir al conserje o recepcionista de la sede para presentar una queja verbal, o bien abandona el lugar frustrado, perjudicando la reputación de la empresa operadora.
- Si se emite un reporte de avería, no existe un canal normalizado para vincular a la persona afectada, el importe exacto retenido ni la vía de compensación económica.
- Cuando el técnico acude a reparar la máquina y desatasca el monedero, no queda constancia formal de si recuperó el dinero, cuánto efectivo extrajo ni si lo depositó en recepción o lo custodió para el taller central.
- El servicio de coordinación carece de trazabilidad de cuántas reclamaciones económicas se producen por máquina y modelo, ni de un procedimiento transparente para validar y liquidar los reembolsos legítimos, diferenciándolos de posibles abusos.

### 1.2 Objetivo del Módulo
El Módulo M5 (Gestión de Reintegros e Importe Retenido) tiene como propósito:
1. **Canalizar y registrar reclamaciones de dinero retenido asociadas a averías**, tanto desde el reporte ciudadano público mediante código QR como desde el portal del Responsable de Sede, generando un PIN de recogida seguro y una URL pública de seguimiento.
2. **Exigir un dictamen presencial del técnico de campo**, constatando formalmente si se recuperó efectivo, si hubo fallo verificado sin recuperación de monedas o si no se encontraron evidencias de retención, permitiendo además el registro de sobrantes atascados de oficio.
3. **Ofrecer métodos mixtos y coherentes de liquidación económica**:
   - Liquidación presencial en mano: depósito del efectivo recuperado ($\le 10,00\ \text{€}$) en conserjería para entrega protegida mediante validación de PIN de 4 dígitos.
   - Liquidación digital centralizada: reembolso mediante Bizum o transferencia bancaria aprobado por Coordinación (obligatorio para importes $> 10,00\ \text{€}$ o cuando el usuario solicita pago digital).
4. **Implantar controles antifraude con límites cuantitativos (máximo 50,00 €) y doble autorización** para cantidades superiores a 10,00 € o con discrepancias físicas.
5. **Desacoplar el ciclo de vida del ticket de avería técnica del expediente de reintegro**, garantizando que la máquina vuelva a vender de inmediato sin esperar al pago, sobreviviendo la reclamación si la avería se cancela y preservando la inmutabilidad ante reaperturas (Art. III).
6. **Garantizar la protección de datos, la anonimización y la segregación de la información financiera (Constitución Art. V.4)**, ocultando cuentas bancarias y teléfonos a técnicos y conserjes, y anonimizando los nombres en el portal de sede.

---

## 2. Usuarios del Sistema

| Rol | Descripción | Responsabilidades en este Módulo |
| :--- | :--- | :--- |
| **Consumidor Final / Afectado** | Usuario que utilizó la máquina y no recibió el producto ni el cambio | Registra la reclamación de dinero retenido al escanear el QR físico, guarda su PIN de 4 dígitos, consulta su enlace seguro de seguimiento y recoge el dinero en conserjería o recibe el Bizum/transferencia. |
| **Responsable de Ubicación / Sede** | Conserje o recepcionista del centro donde opera la máquina | Registra reclamaciones comunicadas presencialmente, custodia el sobre con efectivo depositado por el técnico, valida el PIN de 4 dígitos del usuario para la entrega en mano y consulta el estado anonimizado de los reembolsos del centro. |
| **Técnico de Ruta / Campo** | Operario en movilidad asignado a la resolución de la incidencia | Inspecciona el mecanismo de cobro, emite el dictamen de verificación de saldo, declara hallazgos de oficio de monedas no reclamadas y custodia el efectivo según el canal solicitado (en mano o caja central). |
| **Coordinador del Servicio / Finanzas** | Supervisor operativo y administrativo del parque | Evalúa las solicitudes de reintegro, aprueba o deniega reclamaciones (con doble autorización para importes $> 10,00\ \text{€}$ o discrepancias), gestiona incidencias con datos erróneos (`PENDING_CONTACT`) y registra las liquidaciones bancarias con su identificador de transacción. |

---

## 3. Historias de Usuario

### HU-01: Registro de Dinero Retenido en Reporte QR con PIN de Recogida y Enlace de Seguimiento
**Como** Consumidor Final que ha perdido dinero en una máquina dispensadora,  
**quiero** indicar el importe retenido, el producto intentado y mis datos de contacto (solicitando entrega en mano con PIN o reembolso por Bizum/IBAN) al escanear el código QR,  
**para que** reciba en pantalla un localizador con enlace seguro de seguimiento para consultar la evolución de mi dinero y un PIN de 4 dígitos para recogerlo si elegí entrega en conserjería.

### HU-02: Registro de Reclamación por el Responsable de Sede
**Como** Responsable de Sede que atiende a un usuario que acaba de perder dinero en una máquina de mi edificio,  
**quiero** registrar la avería en el portal incorporando el importe reclamado por el usuario y su nombre,  
**para que** quede constancia formal de la retención de saldo para la visita del técnico y se genere el expediente de reintegro correspondiente.

### HU-03: Dictamen Técnico Presencial, Hallazgo de Oficio y Custodia Coherente
**Como** Técnico de Ruta que atiende una avería de pago o monedas,  
**quiero** certificar si recuperé físicamente dinero (o declarar monedas atascadas no reclamadas), dejando el efectivo en conserjería únicamente si se solicitó entrega en mano y es $\le 10,00\ \text{€}$, o custodiándolo para caja central si es digital o supera dicho importe,  
**para que** la custodia física del dinero sea transparente y coherente con la vía de pago autorizada.

### HU-04: Entrega en Mano Protegida mediante Validación de PIN en Conserjería
**Como** Responsable de Sede que custodia un sobre con dinero dejado por el técnico,  
**quiero** introducir en el portal el PIN de 4 dígitos que me muestra el usuario afectado en su teléfono,  
**para que** el sistema verifique la coincidencia, autorice la entrega del efectivo y marque el expediente como reembolsado en mano sin errores ni entregas a terceros.

### HU-05: Aprobación, Gestión de Discrepancias y Pago Digital por Coordinación
**Como** Coordinador del Servicio,  
**quiero** revisar la bandeja de reintegros, autorizar pagos digitales por Bizum/transferencia registrando su referencia bancaria, resolver discrepancias de importe y gestionar solicitudes con datos erróneos,  
**para que** se liquiden los expedientes legítimos con plena justificación y rigor contable.

### HU-06: Rectificación de Datos de Contacto Erróneos por el Usuario Afectado
**Como** Consumidor Final cuyo reintegro ha sido marcado como pendiente de contacto por un error en el IBAN o teléfono,  
**quiero** acceder a mi enlace seguro de seguimiento y actualizar mis datos bancarios o de Bizum,  
**para que** el coordinador pueda emitir el reembolso sin necesidad de reiniciar la reclamación.

---

## 4. Requisitos Funcionales (RF) con Criterios de Aceptación EARS

### 4.1 Captura de Solicitudes y Experiencia del Afectado

#### RF-REF-01: Captura de Reclamación en Reporte Ciudadano QR y Portal de Sede
El sistema debe permitir asociar una solicitud estructurada de reintegro económico durante el reporte de una incidencia en máquina.

* **EARS Ubicuo:** La solicitud de reintegro DEBE contener de forma obligatoria: importe numérico reclamado (en euros, con 2 decimales), nombre del beneficiario, canal de contacto (teléfono o correo electrónico) y vía de compensación solicitada (`EN_MANO_SEDE`, `BIZUM` o `TRANSFERENCIA_BANCARIA`).
* **EARS Evento:** Cuando el solicitante elija `BIZUM`, el sistema DEBE exigir un número de teléfono móvil válido en formato de 9 dígitos.
* **EARS Evento:** Cuando el solicitante elija `TRANSFERENCIA_BANCARIA`, el sistema DEBE exigir un código de cuenta bancaria internacional (IBAN) con checksum estándar (módulo 97) válido.
* **EARS Evento:** La opción de compensación `EN_MANO_SEDE` ÚNICAMENTE DEBE mostrarse disponible en el formulario de reporte si la Sede donde está instalada la máquina cuenta con servicio de conserjería o recepción física registrada.

#### RF-REF-02: Generación de PIN de Recogida y Enlace Público de Seguimiento con Token Seguro
El sistema debe facilitar al consumidor final anónimo los medios para consultar su expediente y acreditar su identidad presencialmente.

* **EARS Evento:** Cuando un usuario registre una solicitud de reintegro desde el escaneo público QR, el sistema DEBE generar un **Código PIN de Recogida de 4 dígitos numéricos** aleatorios y un **Token de Seguimiento Criptográfico Seguro** (URL pública de consulta).
* **EARS Evento:** Al finalizar el envío del reporte QR, el sistema DEBE mostrar una pantalla de confirmación con el resumen de la reclamación, el PIN de 4 dígitos para entrega en mano (si aplicara) y el enlace de seguimiento permanente para consultar el estado del expediente en cualquier momento.
* **EARS Estado:** Mientras el expediente de reintegro se encuentre en estado `PENDING_CONTACT` (datos bancarios erróneos), el enlace seguro de seguimiento DEBE permitir al usuario introducir y corregir su número de Bizum o IBAN bancario.

---

### 4.2 Control Antifraude y Límites Cuantitativos

#### RF-REF-03: Límites Cuantitativos y Doble Autorización de Reintegros
El sistema debe aplicar reglas estrictas de límite cuantitativo y supervisión escalada.

* **EARS Ubicuo:** El sistema DEBE validar que el importe reclamado sea estrictamente mayor que cero ($> 0,00\ \text{€}$) y menor o igual a **50,00 €** (tope máximo bloqueante por reclamación de vending).
* **EARS Excepción:** Si un usuario intenta registrar una reclamación superior a 50,00 €, el sistema NO DEBE admitir la solicitud y DEBE mostrar un mensaje explicativo informando de que para incidencias con importes superiores debe contactarse directamente con el departamento de atención al cliente.
* **EARS Estado:** Mientras el importe supere los **10,00 €** o el importe recuperado por el técnico difiera en más de un 20% del importe reclamado, el sistema DEBE impedir la liquidación directa y exigir la aprobación formal por parte de un usuario con rol `COORDINATOR` con justificación registrada.

---

### 4.3 Inspección Técnica Presencial y Custodia del Efectivo

#### RF-REF-04: Dictamen Técnico Obligatorio de Saldo y Registro de Hallazgo de Oficio
El técnico debe dictaminar obligatoriamente la situación del dinero al intervenir incidencias con reclamación, y disponer de una opción para declarar dinero recuperado de oficio.

* **EARS Ubicuo:** Cuando una incidencia tenga asociada una solicitud de reintegro pendiente, el sistema DEBE exigir al técnico seleccionar una de las tres opciones cerradas de dictamen físico antes o durante la resolución:
  1. `FOUND_PHYSICAL`: Dinero recuperado físicamente en la máquina.
  2. `CONFIRMED_NO_CASH`: Fallo mecánico/electrónico verificado sin recuperación de monedas (saldo retenido en placa sin entrega de producto).
  3. `UNVERIFIED_NO_CASH`: No se localiza dinero atascado ni existen evidencias técnicas de retención de saldo (exige justificación obligatoria $\ge 20$ caracteres).
* **EARS Evento:** Cuando el técnico seleccione `FOUND_PHYSICAL`, el sistema DEBE exigir la introducción del importe exacto recuperado en euros.
* **EARS Evento:** En toda resolución de avería relacionada con sistemas de pago o monedas donde no exista reclamación previa de un usuario, el técnico DEBE poder marcar opcionalmente *"Efectivo atascado recuperado de oficio"*, registrando el importe exacto para su custodia hacia caja central como sobrante no reclamado.

#### RF-REF-05: Reglas de Custodia y Restricción de Depósito Presencial
El sistema debe forzar la coherencia entre la vía de pago elegida por el usuario y el destino del efectivo recuperado por el operario.

* **EARS Evento:** Si el usuario solicitó compensación digital (`BIZUM` o `TRANSFERENCIA_BANCARIA`) o si el importe recuperado supera los **10,00 €**, el sistema NO DEBE permitir al técnico seleccionar depósito en conserjería y DEBE fijar obligatoriamente la custodia física hacia caja central (`HELD_FOR_CENTRAL`).
* **EARS Evento:** Si el usuario solicitó compensación presencial (`EN_MANO_SEDE`) y el importe es $\le 10,00\ \text{€}$, el técnico DEBE poder seleccionar depósito en recepción (`LEFT_AT_RECEPTION`), registrando el nombre del conserje que recibe el sobre.
* **EARS Evento:** Si la sede asociada al expediente **no dispone de conserjería física** (`has_physical_reception = 0`), el sistema NO DEBE admitir el depósito en recepción con independencia del método de compensación y del importe, y DEBE exigir al técnico custodiar el efectivo hacia caja central (`HELD_FOR_CENTRAL`), rechazando con `422 RECEPTION_DELIVERY_NOT_ALLOWED` todo intento explícito de `LEFT_AT_RECEPTION` sobre una sede que no puede recibirlo.
* **EARS Evento:** Al confirmarse el depósito en recepción (`LEFT_AT_RECEPTION`), el expediente de reintegro DEBE transicionar automáticamente al estado **`DEPOSITED_AT_RECEPTION`** (*"Efectivo en conserjería pendiente de recogida"*).

---

### 4.4 Entrega en Sede y Liquidación Digital por Coordinación

#### RF-REF-06: Entrega Presencial en Conserjería Mediante Validación de PIN de 4 Dígitos
La entrega física del dinero al afectado debe protegerse contra entregas indebidas o accidentales.

* **EARS Estado:** Mientras un expediente se encuentre en estado `DEPOSITED_AT_RECEPTION`, el portal de Sede DEBE mostrar la solicitud como pendiente de entrega identificando el importe y el nombre anonimizado del afectado (ej. *"Laura S. · 2,50 €"*), junto con un campo de validación de PIN.
* **EARS Evento:** Cuando el conserje introduzca el PIN de 4 dígitos facilitado por el usuario, el sistema DEBE verificar si coincide exactamente con el código secreto del expediente:
  - Si coincide: el sistema DEBE marcar el expediente como **`REFUNDED_IN_HAND`** (Reembolsado en mano), registrando la fecha y hora de la entrega.
  - Si no coincide: el sistema DEBE rechazar la acción, mostrar un aviso de *"PIN de recogida incorrecto"* y mantener el dinero en custodia en conserjería.

#### RF-REF-07: Ciclo Formal de Estados y Tramitación Digital por Coordinación
El expediente de reintegro debe gestionarse a través de un ciclo de vida transparente administrado desde el panel de Coordinación.

* **EARS Ubicuo:** Todo expediente de reintegro DEBE pasar por los siguientes estados:
  - `PENDING_INSPECTION`: Solicitud registrada, pendiente de la visita y dictamen del técnico.
  - `DEPOSITED_AT_RECEPTION`: Efectivo depositado en conserjería por el técnico, esperando que el usuario lo retire con su PIN.
  - `VERIFIED_PENDING_PAYMENT`: Dictamen técnico completado con custodia central; pendiente de emisión de Bizum o transferencia bancaria por Coordinación.
  - `REQUIRES_COORDINATOR_APPROVAL`: Expediente de importe elevado ($> 10,00\ \text{€}$) o con discrepancias que exige visto bueno expreso antes de pagar.
  - `PENDING_CONTACT`: Datos bancarios o teléfono erróneos; expediente a la espera de rectificación por el usuario o coordinador.
  - `PAID_DIGITAL`: Reembolso abonado mediante Bizum o transferencia bancaria.
  - `REFUNDED_IN_HAND`: Reembolso entregado físicamente en conserjería tras validar el PIN.
  - `REJECTED`: Reclamación desestimada motivadamente por falta de evidencias o duplicidad (con motivo obligatorio $\ge 20$ caracteres).
* **EARS Evento:** Cuando el Coordinador ejecute un reembolso por Bizum o transferencia, el sistema DEBE exigir el registro de la fecha de emisión y el código de justificante o referencia bancaria de la transacción.

#### RF-REF-08: Gestión de Discrepancias y Reparto en Reclamaciones Múltiples
El sistema debe resolver situaciones donde el dinero recuperado no coincide con lo reclamado por uno o varios afectados.

* **EARS Evento:** Si una avería cuenta con múltiples solicitudes de reintegro y el dinero físico recuperado por el técnico es inferior a la suma de lo reclamado, el sistema DEBE derivar automáticamente todos los expedientes al estado `REQUIRES_COORDINATOR_APPROVAL` con un aviso destacado de discrepancia (*"Recuperado X € vs Reclamado Y €"*).
* **EARS Evento:** El Coordinador DEBE poder validar individualmente la cuantía final a devolver a cada solicitante según el histórico de ventas y el precio de los productos de la máquina intervenida.

---

### 4.5 Desacoplamiento, Inmutabilidad y Segregación Constitucional

#### RF-REF-09: Desacoplamiento Operativo, Supervivencia ante Cancelación e Inmutabilidad en Reaperturas (Art. III)
El ciclo técnico del parque de máquinas debe ser independiente del ciclo contable de reembolsos.

* **EARS Ubicuo:** El técnico DEBE poder resolver la incidencia técnica (`RESOLVED`) en cuanto la máquina se encuentre físicamente operativa y haya registrado su dictamen de inspección de saldo.
* **EARS Ubicuo:** La transición de la incidencia a estado `RESOLVED` o `CLOSED` NO DEBE cerrar automáticamente el expediente de reintegro si este aún se encuentra pendiente de liquidación digital o entrega en mano.
* **EARS Evento:** Si el coordinador cancela una incidencia técnica (`CANCELLED`) por falsa alarma o descarte, la solicitud de reintegro asociada NO DEBE borrarse ni cancelarse automáticamente; DEBE permanecer activa en la bandeja de Reintegros de Coordinación para verificación administrativa.
* **EARS Evento:** Si una incidencia se reabre dentro de las 48 horas posteriores a su resolución (Art. V.6), el expediente de reintegro previamente cerrado de la primera intervención DEBE mantenerse inalterado e inmutable; si se produce una nueva pérdida de dinero en la reincidencia, el sistema DEBE registrar un nuevo expediente independiente.

#### RF-REF-10: Segregación Estricta de Datos Financieros, Anonimización y Blindaje Constitucional (Art. III, IV, V.4 y VI)
El sistema debe proteger la privacidad de los datos personales y bancarios de los consumidores afectados según el perfil que consulta la información.

* **EARS Ubicuo:** Queda terminantemente PROHIBIDO el borrado físico (`DELETE FROM`) sobre la tabla de expedientes de reintegro; las solicitudes rechazadas, desestimadas o archivadas deben conservarse inmutablemente para auditoría histórica en `audit_log` (Art. III).
* **EARS Ubicuo:** Los datos de cuenta bancaria (IBAN) y teléfono de Bizum ÚNICAMENTE DEBEN ser accesibles y visibles para el rol de **Coordinador del Servicio** en los paneles de administración y pago (Art. V.4).
* **EARS Ubicuo:** El **Técnico de Campo** NO DEBE tener visibilidad sobre el IBAN ni sobre el teléfono privado del solicitante; únicamente visualiza el importe reclamado y la indicación de custodia requerida (Art. V.4).
* **EARS Ubicuo:** El **Responsable de Sede** ÚNICAMENTE DEBE visualizar el nombre anonimizado del afectado (ej. *"Nombre Inicial."*), el importe y el estado del reembolso, quedando terminantemente oculta cualquier información de IBAN o cuentas bancarias (Art. V.4).
* **EARS Ubicuo:** La plataforma DEBE validar la sintaxis estándar de Bizum e IBAN (módulo 97) de forma nativa en PHP puro sin librerías externas de pago ni conexiones telemáticas directas a pasarelas bancarias en tiempo de ejecución (Art. IV y Art. VI).

#### RF-REF-11: Una sola Reclamación Viva por Avería y Consumidor
El sistema debe impedir que un mismo consumidor acumule varios expedientes de reintegro vivos sobre una misma avería, sin llegar a impedir que distintos reclamen sobre una avería compartida.

* **EARS Ubicuo:** La unidad de la regla es la pareja **(avería, consumidor)**, nunca la avería a secas. Cuando una avería afecte a varias personas (por ejemplo, una máquina que retiene el saldo de cinco usuarios), CADA uno de esos consumidores DEBE poder abrir su propia reclamación, porque son reintegros legítimos y distintos.
* **EARS Excepción:** Si un consumidor ya tiene un expediente **vivo** (no terminal) sobre una avería y vuelve a solicitar un reintegro por ella, el sistema NO DEBE crear un segundo expediente y DEBE responder con el código `409 DUPLICATE_REFUND_CLAIM`.
* **EARS Evento:** Ante ese rechazo, el sistema DEBE incluir en la respuesta el **token de seguimiento del expediente ya abierto**, de modo que el consumidor recupere el acceso a su solicitud en curso en lugar de perderlo.
* **EARS Excepción:** Se ADMITE un expediente nuevo cuando el anterior se encuentre en estado terminal (`PAID_DIGITAL`, `REFUNDED_IN_HAND` o `REJECTED`), ya que en ese caso el consumidor tiene derecho a reclamar de nuevo, por ejemplo tras una desestimación con el fin de corregir sus datos.
* **EARS Ubicuo:** La comparación de identidad entre dos solicitudes se DEBE realizar sobre el medio de contacto normalizado (minúsculas, sin espacios, guiones ni puntos en teléfonos), de modo que `600 123 456`, `600-123-456` y `600123456` se reconozcan como el mismo reclamante y no como tres distintos.

---


---

## 5. Requisitos No Funcionales (RNF)

* **RNF-REF-01 (Trazabilidad e Inmutabilidad Contable):** Toda acción sobre un expediente de reintegro (creación, dictamen técnico, cambio a depósito en conserjería, validación de PIN, aprobación, rechazo y pago con referencia bancaria) debe quedar registrada de forma permanente e inmutable en el historial de auditoría (`audit_log`), en cumplimiento del Artículo III de la Constitución.
* **RNF-REF-02 (Rendimiento en Consulta y Registro):** La consulta de la bandeja de reintegros y el procesamiento de cambios de estado debe completarse en un tiempo inferior a 150 milisegundos en el servidor.
* **RNF-REF-03 (Confidencialidad y Seguridad de Datos):** Los datos bancarios (IBAN) deben transmitirse siempre mediante canales seguros y su serialización en respuestas JSON debe quedar estrictamente restringida al rol de Coordinación mediante DTOs específicos.
* **RNF-REF-04 (Usabilidad Táctil en Movilidad):** La declaración del dictamen técnico sobre el dinero en la vista móvil del técnico debe poder completarse en menos de 20 segundos mediante selectores táctiles grandes y campos optimizados para smartphone.
* **RNF-REF-05 (Consistencia con el Sistema de Diseño):** Todos los componentes visuales de reintegros (tarjetas de importe, modales de autorización, estados semánticos e insignias de saldo) deben adoptar la paleta y radios institucionales del proyecto (`docs/design.md`).

---

## 6. Casos Límite y Manejo de Situaciones Excepcionales

1. **Importe recuperado por el técnico distinto al reclamado por el usuario:**  
   Si el usuario reclamó 2,00 € pero el técnico encontró 5,00 € (o viceversa, encontró 1,00 €), el sistema registra ambas cifras de forma independiente. El expediente pasa a revisión de Coordinación (`REQUIRES_COORDINATOR_APPROVAL`) para autorizar la cuantía exacta a devolver según el dictamen y los precios del producto.
2. **Técnico no localiza dinero atascado y la máquina funciona con normalidad:**  
   El técnico emite dictamen `UNVERIFIED_NO_CASH`. El Coordinador revisa el histórico de la máquina; si no constan otros avisos y no hay evidencia de fallo, puede desestimar la solicitud (`REJECTED`) aportando la justificación técnica al usuario, o bien autorizar un abono comercial de cortesía.
3. **Solicitud de reintegro con datos de contacto o IBAN erróneos:**  
   Si el Coordinador detecta que el IBAN es incorrecto o el teléfono no dispone de Bizum activo, el expediente pasa al estado transitorio `PENDING_CONTACT`. El usuario puede actualizar sus datos desde su enlace seguro de seguimiento o el Coordinador puede rectificarlos tras contactar con el afectado.
4. **Múltiples reclamaciones de saldo sobre la misma avería técnica:**  
   Si varios usuarios registran reclamaciones tras fallar la misma máquina antes de la llegada del técnico, el sistema genera expedientes de reintegro individuales vinculados al mismo ticket matriz de avería técnica, permitiendo al técnico y al coordinador gestionar cada reembolso por separado.
5. **Caducidad de efectivo depositado en conserjería no reclamado:**  
   Si el técnico depositó el dinero en conserjería pero transcurren más de 30 días sin que el afectado acuda a retirarlo con su PIN, el Responsable de Sede puede registrar el reingreso del saldo para que sea recogido por el técnico en la siguiente visita de ruta y devuelto a la caja central.
6. **Avería técnica cancelada por falsa alarma o duplicado:**  
   La incidencia técnica pasa a `CANCELLED`, pero el expediente de reintegro permanece activo en la bandeja de Coordinación para verificación administrativa independiente, evitando que una cancelación de avería deje sin respuesta a un consumidor que perdió dinero.
7. **Monedas falsas, fichas o moneda extranjera atascada:**  
   El técnico emite dictamen `CONFIRMED_NO_CASH` con justificación descriptiva indicando el hallazgo de objetos no válidos en el selector, permitiendo a Coordinación desestimar motivadamente la solicitud.

---

## 7. Fuera de Alcance (Exclusiones Explícitas)

Para cumplir con el Artículo VI de la Constitución (**Anti-Feature Creep**) y acotar el módulo a la operativa real de vending:
1. **Integración bancaria directa con pasarelas automáticas (APIs PSD2 / Redsys / Bizum Empresas):** La ejecución del pago se realiza externamente por los canales bancarios habituales de la empresa operadora; el sistema VendGuard gestiona el flujo de control, validación, autorización y registro del identificador de transferencia, sin requerir conexión técnica en tiempo real con pasarelas de pago bancarias.
2. **Monederos virtuales (*wallets*) de saldo en cuenta de usuario:** No se crean cuentas de monedero electrónico recargable ni custodia de depósitos digitales de clientes.
3. **Compensaciones por lucro cesante o daños y perjuicios:** El sistema gestiona exclusivamente el reintegro del importe nominal exacto retenido en la máquina.
4. **Facturación rectificativa o conciliación contable ERP general:** La plataforma documenta la trazabilidad del gasto de reintegro, pero no sustituye al software contable ni emite facturas oficiales rectificativas de venta.

---

## 8. Criterios de Finalización (Done Criteria)

El Módulo M5 se considerará completado cuando se satisfagan las siguientes condiciones:
1. Los formularios de reporte ciudadano QR y reporte de Sede permitan opcionalmente solicitar el reintegro de dinero retenido con validación de datos (nombre, canal, Bizum/IBAN), límite bloqueante de 50,00 €, generación de PIN de 4 dígitos y URL pública de seguimiento con token seguro.
2. La vista de resolución del técnico móvil exija dictaminar de forma obligatoria el estado de la retención de efectivo (`FOUND_PHYSICAL`, `CONFIRMED_NO_CASH`, `UNVERIFIED_NO_CASH`), permitiendo además declarar hallazgos de monedas atascadas de oficio.
3. El técnico solo pueda seleccionar depósito en conserjería si la compensación solicitada fue `EN_MANO_SEDE` y el importe es $\le 10,00\ \text{€}$; en cualquier otro caso el dinero recuperado se custodia obligatoriamente para caja central (`HELD_FOR_CENTRAL`).
4. El portal de Sede permita al conserje verificar y liquidar la entrega presencial tecleando el PIN de 4 dígitos facilitado por el usuario, mostrando nombres anonimizados y ocultando cualquier dato bancario.
5. El panel de Coordinación disponga de una bandeja de Reintegros para aprobar, rechazar y liquidar devoluciones digitales registrando la referencia de pago, con doble autorización obligatoria para importes $> 10,00\ \text{€}$ o con discrepancias.
6. La resolución técnica de la máquina sea independiente del abono del reintegro (desacoplamiento total del estado de avería frente al pago), sobreviviendo la reclamación si la avería se cancela y preservando la inmutabilidad de expedientes en reaperturas dentro de 48h.
7. Se certifique mediante prueba automatizada de integración que el rol `LOCATION_MANAGER` y el rol `TECHNICIAN` no tienen acceso bajo ninguna circunstancia al campo `iban` ni a datos bancarios privados del solicitante (Constitución Art. V.4) y que está prohibido el borrado físico (`DELETE FROM`) de solicitudes (Constitución Art. III).
8. Todas las suites de pruebas asociadas (unitarias, reactivas e integración) pasen al 100% en verde con cero fallos y cero regresiones sobre los módulos previos.

---

## 9. Dudas Abiertas

*Actualmente no existen dudas abiertas pendientes. Todas las ambigüedades respecto a canales de reporte, entrega presencial mediante PIN, dictamen técnico, custodia coherente, desacoplamiento operativo, límites antifraude y blindaje constitucional han quedado formalmente resueltas y aprobadas durante la ronda de especificación.*
