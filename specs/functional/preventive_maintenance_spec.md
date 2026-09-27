# ESPECIFICACIÓN FUNCIONAL · MANTENIMIENTO PREVENTIVO Y CHECKLISTS SANITARIOS
**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** M1 — Mantenimiento Preventivo y Checklists Sanitarios Periódicos  
**Documento:** `specs/functional/preventive_maintenance_spec.md`  
**Estado:** Propuesta Formal para Aprobación  
**Metodología:** SDD (Specification-Driven Development) · Notación EARS  
**Fundamento Constitucional:** Artículo II (Principio de Precaución y Seguridad Alimentaria) y Artículo III (Inviolabilidad de Datos)  

---

## 1. Contexto y Objetivo

### 1.1 Contexto
VendGuard gestiona de manera excelente la operativa reactiva ante averías (correctivo). Sin embargo, en el sector de la distribución automática de alimentos y bebidas, las máquinas dispensadoras están sujetas a estrictas normativas higiénico-sanitarias y de seguridad alimentaria (Real Decreto de APPCC / Directivas Comunitarias). 

Las máquinas con **alimentos frescos perecederos** (sándwiches, ensaladas, platos preparados, lácteos) requieren garantizar de forma proactiva que la cadena de frío no se degrade antes de que se produzca una rotura térmica. Asimismo, las máquinas de bebidas calientes acumulan incrustaciones calcáreas, residuos en grupos de erogación y desgaste en filtros de agua que deterioran la calidad bromatológica del producto si no se desinfectan y revisan periódicamente.

Actualmente, las inspecciones preventivas se gestionan mediante anotaciones dispersas o calendarios informales no auditables, provocando que los responsables de centros sensibles (hospitales, universidades, residencias) desconozcan si las máquinas están al día con la normativa higiénica y carezcan de certificados formales ante inspecciones sanitarias oficiales.

### 1.2 Objetivo
Definir con precisión el comportamiento funcional del sistema para:
1. **Automatizar la programación de revisiones higiénico-sanitarias y mecánicas periódicas** con frecuencias diferenciadas según la tipología de dispensación (perecederos, bebidas frías, bebidas calientes, snacks).
2. **Guiar al técnico de ruta a través de un checklist digital normativo in situ**, con registro obligatorio de la temperatura real en grados Celsius para máquinas refrigeradas y comprobación estricta de umbrales higiénicos.
3. **Establecer consecuencias operativas inmediatas según el resultado de la inspección**, bloqueando y poniendo en cuarentena sanitaria cualquier máquina que no cumpla con los límites de seguridad alimentaria.
4. **Coexistir limpiamente con las averías correctivas**, generando automáticamente tickets de avería vinculados cuando se detecten desperfectos durante la revisión preventiva.
5. **Dotar a los responsables de las sedes clientes de un semáforo de vigencia sanitaria** y de la capacidad de descargar o imprimir en cualquier momento el Certificado Oficial de Inspección Sanitaria para acreditación ante inspecciones públicas.

---

## 2. Usuarios del Sistema

* **Técnico de Campo / Ruta (Inspector y Operador):** Personal que acude físicamente a las máquinas durante su jornada itinerante, ejecuta el checklist sanitario estandarizado, mide y registra la temperatura con termómetro calibrado, desinfecta puntos críticos y dictamina la aptitud de la máquina.
* **Coordinador del Servicio (Supervisor y Planificador):** Responsable de supervisar el estado sanitario global de la flota, monitorizar máquinas con revisiones próximas a vencer o vencidas, revisar inspecciones no conformes y gestionar las frecuencias preventivas.
* **Responsable de Ubicación (Informador / Cliente):** Encargado o conserje de la sede cliente (hospital, empresa, centro educativo) que necesita certificar que las máquinas de su edificio cumplen la normativa sanitaria, consultar el semáforo de vigencia de cada máquina y descargar los certificados de desinfección para auditorías de calidad o inspecciones de sanidad.
* **Inspector Sanitario Oficial / Auditor Externo (Consumidor de Certificados):** Autoridad de salud pública o auditor de calidad que inspecciona las instalaciones del cliente y exige la trazabilidad documental de desinfecciones, cambio de filtros y control térmico continuo.

---

## 3. Historias de Usuario

* **HU-PREV-01:** *Como* Coordinador del Servicio, *quiero* que el sistema calcule automáticamente la fecha límite de la próxima inspección de cada máquina según su tipo *para* asegurar que ninguna máquina de alimentos opere sin revisión sanitaria al día.
* **HU-PREV-02:** *Como* Técnico de Ruta, *quiero* ver en mi dispositivo móvil las inspecciones preventivas pendientes en las sedes de mi itinerario *para* coordinar las revisiones con las visitas correctivas en una sola parada física.
* **HU-PREV-03:** *Como* Técnico de Ruta, *quiero* completar un checklist específico según la máquina que estoy inspeccionando (con registro obligatorio de temperatura en máquinas de frío) *para* no saltarme ningún control crítico de desinfección.
* **HU-PREV-04:** *Como* Técnico de Ruta, *quiero* que al detectar una avería durante el checklist preventivo el sistema me permita registrarla y abrir la incidencia técnica vinculada de inmediato *para* no tener que duplicar formularios.
* **HU-PREV-05:** *Como* Coordinador del Servicio, *quiero* que si una máquina de alimentos perecederos supera los 4.0 °C en la inspección quede automáticamente en cuarentena e informe a los usuarios en su QR *para* salvaguardar la salud pública según el Artículo II de la Constitución.
* **HU-PREV-06:** *Como* Responsable de Sede, *quiero* ver en el portal de mi edificio el semáforo sanitario de cada máquina (vigente, próxima a vencer, vencida o en cuarentena) *para* tener plena tranquilidad sobre los alimentos consumidos en mis instalaciones.
* **HU-PREV-07:** *Como* Responsable de Sede, *quiero* descargar o imprimir un Certificado Oficial de Inspección y Desinfección firmado digitalmente *para* presentarlo ante los inspectores de Sanidad o auditorías laborales sin depender de llamadas o esperas por correo.
* **HU-PREV-08:** *Como* Coordinador del Servicio, *quiero* auditar el historial de revisiones higiénicas y mediciones térmicas de cada máquina *para* detectar degradaciones graduales en los grupos de frío antes de que provoquen roturas térmicas.

---

## 4. Requisitos Funcionales (Notación EARS)

### RF-PREV-01: Configuración de Frecuencias y Ciclos Sanitarios por Tipología
*El sistema gestionará la periodicidad máxima permitida entre inspecciones preventivas según la naturaleza sanitaria de los productos dispensados.*

* **EARS 1.1 (Ubícuo):** El sistema deberá mantener las siguientes frecuencias máximas por defecto entre inspecciones consecutivas según la tipología de máquina:
  * **Alimentos Perecederos (`PERISHABLE_FOOD`):** Máximo **15 días naturales** entre revisiones.
  * **Bebidas Calientes (`HOT_DRINKS`):** Máximo **30 días naturales** entre revisiones.
  * **Bebidas Frías (`COLD_DRINKS`):** Máximo **45 días naturales** entre revisiones.
  * **Snacks y No Perecederos (`SNACKS`):** Máximo **60 días naturales** entre revisiones.
  * **Mixta / Combinada (`COMBO`):** Se rige por la periodicidad del componente más restrictivo que contenga (15 días si incluye perecederos, 45 días en caso contrario).
* **EARS 1.2 (Evento):** Cuando el Coordinador modifique la frecuencia periódica de una tipología o asigne una frecuencia personalizada a una máquina concreta (por ejemplo, en entornos hospitalarios de alto riesgo a 7 días), el sistema deberá recalcular la fecha límite de la próxima inspección tomando como referencia la fecha de la última inspección conforme registrada.
* **EARS 1.3 (Excepción):** Si el Coordinador intenta configurar una periodicidad superior a 30 días naturales para una máquina de alimentos perecederos, el sistema deberá rechazar el cambio mostrando el mensaje: *"Por imperativo del Artículo II de la Constitución (Seguridad Alimentaria), las máquinas de alimentos perecederos no pueden superar los 30 días entre revisiones sanitarias."*

---

### RF-PREV-02: Generación y Programación de Órdenes de Inspección Preventiva
*El sistema generará y asignará tareas preventivas de forma proactiva al aproximarse la fecha límite de vigencia sanitaria.*

* **EARS 2.1 (Estado/Temporal):** Mientras una máquina activa se encuentre a **5 días naturales o menos** de que venza su periodo de vigencia higiénico-sanitaria y no tenga ya una orden preventiva pendiente, el sistema deberá generar automáticamente una orden de inspección en estado `PENDIENTE_ASIGNACION`.
* **EARS 2.2 (Evento):** Cuando el Coordinador asigne una orden preventiva a un técnico de ruta, el sistema deberá actualizar su estado a `PROGRAMADA`, fijar el técnico responsable y hacer visible la tarea en la ruta de trabajo del técnico.
* **EARS 2.3 (Urgencia de Asignación):** Si la fecha límite de una inspección preventiva expira sin haber sido completada, el sistema deberá marcar la orden preventiva en estado `VENCIDA` y elevar una alerta visual destacada en el panel de triaje de coordinación.

---

### RF-PREV-03: Checklist Digital Normativo y Medición Obligatoria de Temperatura
*El técnico completará una lista de comprobación estandarizada in situ adaptada a la tipología de la máquina antes de dictaminar su aptitud.*

* **EARS 3.1 (Evento):** Cuando el técnico de ruta acceda a una orden de inspección preventiva y pulse *"Comenzar Inspección"*, el sistema deberá transicionar la orden a estado `EN_INSPECCION` y desplegar el checklist específico de su tipología.
* **EARS 3.2 (Estado/Perecederos):** Mientras el técnico inspeccione una máquina de alimentos perecederos (`PERISHABLE_FOOD` o `COMBO` refrigerada), el sistema deberá exigir obligatoriamente:
  1. **Temperatura Real de Sonda:** Registro numérico decimal en grados Celsius (°C), con un decimal.
  2. **Estanqueidad y Cierre:** Comprobación del estado de las gomas magnéticas y hermetismo de puerta (Conforme / Defectuoso).
  3. **Evaporador y Escarcha:** Verificación visual de ausencia de bloques de hielo u obstrucción de aire (Conforme / Defectuoso).
  4. **Higienización de Bandejas y Cajón de Caída:** Limpieza y desinfección con producto bactericida apto para uso alimentario (Realizada / Pendiente).
  5. **Fechas de Caducidad:** Comprobación de que no existen productos caducados en las espirales activas (Verificado Conforme / Retirados productos).
* **EARS 3.3 (Estado/Bebidas Calientes):** Mientras el técnico inspeccione una máquina de bebidas calientes (`HOT_DRINKS`), el sistema deberá exigir obligatoriamente:
  1. **Circuito Hidráulico y Caldera:** Ausencia de fugas y verificación de temperatura de erogación de agua caliente (≥ 75.0 °C).
  2. **Desinfección de Batidores y Boquillas:** Desmontaje y lavado de módulos de mezcla de leche y cacao (Realizada).
  3. **Filtro de Agua / Descalcificador:** Verificación del contador de litros y estado del cartucho de filtrado (Vigente / Sustituido / Agotado).
  4. **Bandeja de Residuos Líquidos y Posos:** Vaciado, higienización y test de boya de flotación antirrebosamiento (Conforme).
* **EARS 3.4 (Estado/General y Snacks):** En todas las tipologías, el sistema deberá incluir verificaciones de:
  1. Estado del cableado y toma de tierra eléctrica.
  2. Funcionamiento de sistemas de pago (monedero y datáfono contactless).
  3. Fotocélulas de detección de caída de producto.
  4. Limpieza exterior y botonera de selección.
* **EARS 3.5 (Excepción):** Si el técnico intenta finalizar la inspección sin haber completado todos los puntos obligatorios del checklist o sin introducir la temperatura en máquinas refrigeradas, el sistema deberá bloquear la finalización y resaltar en rojo los puntos omitidos.

---

### RF-PREV-04: Evaluación de Resultados y Cuarentena Sanitaria Automática
*El sistema procesará las respuestas del checklist para determinar la conformidad de la máquina y aplicará salvaguardas inmediatas.*

* **EARS 4.1 (Evento):** Al enviar el checklist completado, el sistema deberá evaluar automáticamente el resultado asignando uno de los siguientes tres dictámenes:
  * **`CONFORME` (Apta):** Todos los puntos obligatorios son correctos y la temperatura está dentro de los márgenes normativos (≤ 4.0 °C en perecederos; ≤ 8.0 °C en bebidas frías).
  * **`CONFORME_CON_OBSERVACIONES` (Apta Condicionada):** La máquina es sanitariamente segura pero presenta advertencias leves que no ponen en riesgo la salud ni la operativa básica (por ejemplo: iluminación estética débil o cartucho de agua al 80% de vida útil).
  * **`NO_CONFORME` (No Apta / Riesgo):** Incumplimiento de cualquier parámetro sanitario crítico (temperatura > 4.0 °C en perecederos, contaminación evidente, fuga de agua en caldera o fallo de desconexión eléctrica).
* **EARS 4.2 (Estado/Cuarentena Sanitaria):** Si el resultado de la inspección es `NO_CONFORME` en una máquina de alimentos perecederos:
  1. El sistema deberá poner la máquina de inmediato en estado de **Cuarentena Sanitaria**.
  2. El sistema deberá actualizar en tiempo real la respuesta pública de su código QR físico, mostrando una pantalla roja de alarma sanitaria: *"MÁQUINA FUERA DE SERVICIO POR CONTROL SANITARIO PREVENTIVO. Prohibido el consumo de alimentos de este dispositivo."*
  3. El formulario de reporte y compra ciudadana quedará completamente bloqueado.
* **EARS 4.3 (Evento):** Cuando una inspección concluya con resultado `CONFORME` o `CONFORME_CON_OBSERVACIONES`, el sistema deberá:
  1. Registrar la fecha/hora actual como la última inspección válida.
  2. Calcular y fijar la nueva fecha de vencimiento según la periodicidad de la máquina.
  3. Restablecer el estado operativo de la máquina a plenamente operativa.

---

### RF-PREV-05: Coexistencia con Averías Correctivas y Apertura Automática de Tickets
*Las órdenes de mantenimiento preventivo operarán con su propio ciclo de vida y generarán incidencias correctivas cuando proceda sin vulnerar las reglas anti-duplicados.*

* **EARS 5.1 (Ubícuo):** La orden de inspección preventiva constituirá una entidad de ciclo de vida independiente de la tabla de incidencias correctivas, permitiendo planificar y auditar revisiones periódicas sin entrar en conflicto con la restricción de incidencia única activa por máquina.
* **EARS 5.2 (Evento/Detección de Fallo):** Si durante la ejecución del checklist el técnico marca un fallo operativo o sanitario (por ejemplo: rotura de motor de espiral, fallo de datáfono o pérdida de frío):
  1. El sistema deberá abrir de forma automática una incidencia correctiva vinculada a la máquina y referenciada a la inspección preventiva de origen.
  2. Si el fallo detectado es de temperatura (> 4.0 °C en perecederos), el sistema deberá crear la incidencia directamente con nivel de urgencia **`CRÍTICA`** conforme al Artículo II de la Constitución.
  3. La incidencia correctiva recién abierta quedará asignada de forma inmediata al mismo técnico que está ejecutando la inspección in situ.
* **EARS 5.3 (Excepción/Bloqueo por Avería Preexistente):** Si una máquina ya tiene una incidencia correctiva activa en curso con un técnico distinto, el técnico inspector podrá consultar el historial del parte abierto, pero la orden preventiva quedará en pausa hasta que se resuelva la avería previa o el técnico asuma la intervención conjunta con autorización de coordinación.

---

### RF-PREV-06: Semáforo de Vigencia Sanitaria en Portal de Sede y Ficha de Máquina
*Los responsables de ubicación y coordinadores visualizarán el estado sanitario de las máquinas de forma clara y unificada.*

* **EARS 6.1 (Ubícuo):** El sistema deberá mostrar para cada máquina un distintivo visual en forma de semáforo higiénico-sanitario basado en los días restantes hasta la fecha límite de vencimiento:
  * **Verde (Vigente):** Inspección superada y restan más de 5 días naturales para su vencimiento.
  * **Amarillo (Próxima a Vencer):** Faltan 5 días o menos para el vencimiento de la inspección.
  * **Rojo (Vencida):** Ha expirado la fecha límite sin que se haya registrado una inspección conforme.
  * **Rojo Parpadeante / Alerta (Cuarentena):** Inspección evaluada como `NO_CONFORME` pendiente de subsanación técnica.
* **EARS 6.2 (Estado/Portal de Sede):** Mientras el responsable de ubicación consulte el portal de su edificio, el sistema deberá mostrar el estado higiénico de cada una de sus máquinas junto a la fecha de la última desinfección y la temperatura registrada en la última revisión de frío.
* **EARS 6.3 (Estado/Panel Coordinador):** En el panel de administración y triaje del coordinador, el sistema dispondrá de un filtro específico que permita listar rápidamente todas las máquinas con inspección `Vencida` o en `Cuarentena` en todo el parque.

---

### RF-PREV-07: Emisión y Descarga del Certificado de Inspección Sanitaria Oficial
*El sistema generará documentación formal acreditativa con validez para auditorías de clientes y autoridades sanitarias.*

* **EARS 7.1 (Evento):** Cuando un responsable de ubicación o un coordinador pulse el botón *"Descargar Certificado Sanitario"* en la ficha de una máquina o sede:
  * El sistema deberá generar un documento formal en formato imprimible/PDF.
  * El certificado incluirá: identificador único de certificado, código y modelo de la máquina, nombre y dirección de la sede cliente, fecha y hora exacta de la última inspección, nombre y número de carné profesional del técnico inspector, desglose de los puntos verificados (temperatura de sonda, desinfección, filtros), dictamen (`CONFORME`) y fecha límite de validez.
* **EARS 7.2 (Evento/Certificado Agrupado de Sede):** Cuando el responsable de ubicación solicite el *"Certificado Global de Sede"*, el sistema deberá emitir un documento único consolidando el estado sanitario de todas las máquinas instaladas en dicho edificio, facilitando su entrega inmediata a inspectores de sanidad pública.
* **EARS 7.3 (Excepción):** Si el usuario intenta generar el certificado de una máquina con inspección vencida o en cuarentena, el sistema deberá impedir la emisión del certificado de aptitud y generar en su lugar un informe informativo de estado desfavorable con las no conformidades detectadas.

---

### RF-PREV-08: Reinspección tras Subsanación y Levantamiento de Cuarentena
*El sistema regulará el procedimiento estricto para restituir máquinas que habían sido declaradas no aptas.*

* **EARS 8.1 (Evento):** Cuando una máquina en estado de Cuarentena Sanitaria haya sido reparada y su incidencia correctiva vinculada haya sido marcada como `RESUELTA`, el sistema deberá exigir una **Reinspección Sanitaria Inmediata** antes de levantar el bloqueo público.
* **EARS 8.2 (Estado/Medición Térmica de Comprobación):** Durante la reinspección de una máquina de alimentos perecederos tras avería de frío, el técnico deberá volver a registrar la temperatura estabilizada; solo si la nueva medición es ≤ 4.0 °C, el sistema permitirá levantar la cuarentena.
* **EARS 8.3 (Evento):** Al confirmarse la reinspección satisfactoria, el sistema deberá restituir automáticamente la máquina al estado de servicio normal en el escaneo QR, emitir un nuevo certificado de aptitud y archivar de forma inmutable todo el expediente de la no conformidad y su subsanación.

---

## 5. Requisitos No Funcionales (RNF)

* **RNF-01 (Agilidad del Checklist Móvil):** El formulario de checklist en el smartphone del técnico deberá poder completarse en menos de **90 segundos** en condiciones normales mediante controles táctiles rápidos (botones sí/no de un solo toque y teclado numérico automático para temperatura).
* **RNF-02 (Trazabilidad e Inmutabilidad · Art. III):** Queda terminantemente prohibido modificar o eliminar retrospectivamente cualquier informe de inspección ya firmado y cerrado. Cualquier discrepancia o corrección se gestionará mediante una nueva reinspección con auditoría de cambios.
* **RNF-03 (Impresión y Exportación Nativa):** Los certificados higiénico-sanitarios deberán generarse con diseño limpio, cabeceras profesionales y estilos de impresión optimizados para papel A4, legibles tanto en pantalla como al imprimir físicamente para exhibir en el establecimiento.
* **RNF-04 (Consistencia Horaria y Auditoría):** Todas las marcas de tiempo de las inspecciones, mediciones de temperatura y vencimientos se almacenarán con resolución al segundo en tiempo universal coordinado (UTC), mostrándose al usuario en su huso horario local.
* **RNF-05 (Seguridad y Privacidad de Datos · Art. V):** Los certificados visibles para los responsables de sede no mostrarán datos personales internos sensibles del técnico (como DNI privado o teléfono personal), exhibiendo únicamente su nombre profesional y código identificativo de operador técnico.

---

## 6. Casos Límite Operativos (Edge Cases)

1. **Temperatura en el límite exacto (4.0 °C vs 4.1 °C):** Si la sonda mide exactamente 4.0 °C, el sistema la evaluará como `CONFORME` (límite superior aceptable). A partir de 4.1 °C, el sistema activará automáticamente la condición `NO_CONFORME` y la alerta sanitaria por rotura térmica.
2. **Máquina con puerta abierta durante la medición:** Si la temperatura sube transitoriamente por apertura de puerta durante la reposición, el técnico debe esperar a que el compresor recupere la temperatura de régimen antes de registrar el valor definitivo de la inspección.
3. **Máquina temporalmente apagada por obras en el edificio:** Si el técnico acude y la máquina está desconectada por causas ajenas, registra la inspección como `NO_EVALUABLE_POR_CAUSA_EXTERNA`, lo que no emite certificado pero mantiene informada a la coordinación sin penalizar el cumplimiento del técnico.
4. **Vencimiento de inspección durante un fin de semana o festivo:** La fecha límite se computa en días naturales continuos. Si vence en sábado, la orden preventiva se generará 5 días antes (lunes previo) para que el técnico pueda planificarla y completarla durante la semana laboral ordinaria.
5. **Máquina retirada o dada de baja lógica:** Al marcar una máquina como inactiva en el catálogo general, todas sus órdenes de inspección preventivas pendientes se cancelan automáticamente y se excluye del cómputo de semáforos de sede.

---

## 7. Fuera de Alcance (Out of Scope)

Para blindar el **Artículo VI de la Constitución (Anti-Feature Creep)**, quedan formalmente excluidas de este módulo las siguientes funcionalidades:

* **Telemetría telemática continua IoT por sensores automáticos:** No se integran sondas MQTT ni buses MDB en tiempo real. Todas las lecturas y verificaciones provienen exclusivamente de la inspección física humana in situ del técnico de ruta.
* **Integración con laboratorios bromatológicos externos:** No se contemplan pasarelas con laboratorios microbiológicos ni subida de informes de cultivos de laboratorio.
* **Gestión de pedidos automáticos de productos químicos de limpieza:** No se incluye control de stock de botes de desinfectante en furgoneta ni compra automática a proveedores de químicos.
* **Encuestas periódicas de satisfacción de clientes:** El módulo se centra estrictamente en la aptitud higiénico-sanitaria y mecánica, no en encuestas comerciales a consumidores.

---

## 8. Criterios de Finalización (Definition of Done)

* [ ] El 100% de los requisitos funcionales (RF-PREV-01 al RF-PREV-08) están formalmente implementados y cubiertos por pruebas de aceptación automatizadas (notación EARS).
* [ ] Se verifica que toda máquina de perecederos genera su orden preventiva con ciclo de 15 días y bloquea configuraciones superiores a 30 días.
* [ ] Se comprueba que el checklist exige obligatoriamente la temperatura en máquinas refrigeradas y que temperaturas > 4.0 °C declaran `NO_CONFORME`, activando la cuarentena sanitaria en el código QR público.
* [ ] Se valida que ante una no conformidad térmica se abre automáticamente una incidencia correctiva vinculada con nivel de urgencia `CRÍTICA`.
* [ ] Se prueba el correcto funcionamiento del semáforo sanitario (Verde, Amarillo, Rojo, Cuarentena) en el portal de sede del cliente.
* [ ] Se valida la emisión, visualización e impresión en formato A4 del Certificado Oficial de Inspección Sanitaria tanto individual como consolidado por sede.
* [ ] Se confirma que no existe ninguna instrucción de borrado físico (`DELETE FROM`) en todo el ciclo de inspecciones preventivas (cumplimiento Art. III).
* [ ] Se comprueba la vigencia del Dualismo Lingüístico (código y pruebas en inglés, interfaces de usuario y certificados en español).

---

## 9. Registro de Dudas y Aclaraciones Técnicas

* [x] **Aclaración 1 (Generación de Tareas):** Resuelta. Frecuencia periódica configurable por tipología con generación automática anticipada de la orden de trabajo.
* [x] **Aclaración 2 (Relación con Correctivo):** Resuelta. Entidad independiente con ciclo de vida propio que genera tickets correctivos vinculados ante anomalías detectadas.
* [x] **Aclaración 3 (Estructura de Checklists):** Resuelta. Catálogo normativo fijo por tipología con validación estricta de temperatura en frío.
* [x] **Aclaración 4 (Resultados y Cuarentena):** Resuelta. Tres estados (`CONFORME`, `CONFORME_CON_OBSERVACIONES`, `NO_CONFORME`) con cuarentena y bloqueo público en QR ante fallo crítico.
* [x] **Aclaración 5 (Acceso a Certificados):** Resuelta. Acceso para responsables de sede en su portal mediante semáforo de colores y descarga/impresión de certificados oficiales.
* [x] **Aclaración 6 (Límites del Módulo):** Resuelta. Exclusión explícita de sensores telemáticos continuos, laboratorios externos y compras automáticas de consumibles.
