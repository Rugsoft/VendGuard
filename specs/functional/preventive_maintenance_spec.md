# ESPECIFICACIÓN FUNCIONAL · MANTENIMIENTO PREVENTIVO Y CHECKLISTS SANITARIOS
**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** M1 — Mantenimiento Preventivo y Checklists Sanitarios Periódicos  
**Documento:** `specs/functional/preventive_maintenance_spec.md`  
**Estado:** Especificación Formal Aprobada · Lista para Plan Técnico y Contratos  
**Metodología:** SDD (Specification-Driven Development) · Notación EARS  
**Fundamento Constitucional:** Artículos I (Supremacía SDD), II (Seguridad Alimentaria), III (Inviolabilidad de Datos), V (Reglas de Negocio) y VI (Anti-Feature Creep)  

---

## 1. Contexto y Objetivo

### 1.1 Contexto
VendGuard gestiona con rigor la operativa reactiva ante averías técnicas (mantenimiento correctivo). Sin embargo, en el sector de la distribución automática de alimentos y bebidas, las máquinas dispensadoras están sujetas a estrictas normativas higiénico-sanitarias y de seguridad alimentaria (sistemas APPCC y reglamentaciones sanitarias vigentes).

Las máquinas que expenden **alimentos frescos perecederos** (sándwiches, ensaladas, platos preparados, yogures y productos lácteos) requieren garantizar de manera proactiva que la cadena de frío jamás se interrumpa antes de que se produzca una rotura térmica perjudicial para la salud del consumidor. Asimismo, las máquinas de bebidas calientes acumulan incrustaciones calcáreas, residuos en grupos de erogación y desgaste en filtros purificadores que degradan la calidad bromatológica y microbiológica del agua si no se desinfectan y renuevan periódicamente.

Hasta ahora, las revisiones preventivas se gestionaban de manera dispersa o mediante calendarios informales no auditables, provocando que los responsables de centros sensibles (hospitales, colegios, universidades, centros corporativos) carecieran de visibilidad sobre la vigencia higiénica de sus máquinas y no dispusieran de certificados oficiales inmediatos ante inspecciones sanitarias de la administración pública.

### 1.2 Objetivo
Definir con precisión el comportamiento funcional del sistema para:
1. **Automatizar la programación de revisiones higiénico-sanitarias y mecánicas periódicas** con frecuencias diferenciadas según la tipología de dispensación (perecederos, bebidas calientes, bebidas frías y snacks).
2. **Guiar al técnico de ruta mediante un checklist digital normativo in situ**, con registro obligatorio de la temperatura real estabilizada en grados Celsius para máquinas refrigeradas y comprobación estricta de umbrales higiénicos.
3. **Establecer consecuencias operativas inmediatas según el resultado de la inspección**, bloqueando y poniendo en cuarentena sanitaria cualquier máquina que no cumpla con los límites de seguridad alimentaria (Artículo II).
4. **Coexistir limpiamente con las averías correctivas**, respetando escrupulosamente las reglas constitucionales de cierre justificado (Artículo V.1) y unicidad de ticket activo sin duplicidades (Artículo V.2).
5. **Dotar a los responsables de las sedes clientes de un semáforo de vigencia sanitaria** y de la capacidad de descargar o imprimir en cualquier momento el Certificado Oficial de Inspección y Desinfección Sanitaria para acreditación ante inspecciones públicas y auditorías de calidad.

---

## 2. Usuarios del Sistema

* **Técnico de Campo / Ruta (Inspector y Operador):** Personal que acude físicamente a las máquinas durante su jornada itinerante, ejecuta el checklist sanitario estandarizado, mide y registra la temperatura con termómetro calibrado, desinfecta puntos críticos y dictamina la aptitud de la máquina. Puede realizar visitas programadas o autoasignarse visitas preventivas de forma oportunista si ya se encuentra interviniendo en la sede.
* **Coordinador del Servicio (Supervisor y Planificador):** Responsable de supervisar el estado sanitario global de la flota, monitorizar máquinas con revisiones próximas a vencer o vencidas, gestionar pausas estacionales justificadas, revisar inspecciones no conformes y planificar asignaciones técnicas.
* **Responsable de Ubicación (Informador / Cliente):** Encargado, gerente o conserje de la sede cliente (hospital, empresa, centro educativo) que necesita certificar que las máquinas de su edificio cumplen la normativa sanitaria, consultar el semáforo de vigencia de cada máquina y descargar los certificados de desinfección. Por mandato constitucional (Art. V.4), no tiene acceso a datos privados del personal técnico.
* **Inspector Sanitario Oficial / Auditor Externo (Consumidor de Certificados):** Autoridad de salud pública o auditor de calidad que inspecciona las instalaciones del cliente y exige la acreditación documental de desinfecciones, cambio de filtros y control térmico continuo.

---

## 3. Historias de Usuario

* **HU-PREV-01:** *Como* Coordinador del Servicio, *quiero* que el sistema calcule automáticamente la fecha límite de la próxima inspección según la tipología de dispensación *para* asegurar que ninguna máquina de alimentos opere sin revisión sanitaria al día.
* **HU-PREV-02:** *Como* Técnico de Ruta, *quiero* consultar las inspecciones preventivas pendientes en las sedes de mi itinerario y poder autoasignarme tareas preventivas de una sede en la que ya me encuentro in situ *para* optimizar desplazamientos y resolver revisiones en una sola parada física.
* **HU-PREV-03:** *Como* Técnico de Ruta, *quiero* completar un checklist específico según la máquina que estoy inspeccionando (con validación estricta de temperatura en máquinas de frío) *para* no saltarme ningún control crítico de desinfección ni cometer errores de tecleo.
* **HU-PREV-04:** *Como* Técnico de Ruta, *quiero* que al detectar una no conformidad durante el preventivo el sistema cree una avería correctiva vinculada si no existe otra previa, o la incorpore a la bitácora existente si ya hay un ticket activo *para* respetar el principio de ticket único sin duplicidades (Art. V.2).
* **HU-PREV-05:** *Como* Coordinador del Servicio, *quiero* que si una máquina de alimentos perecederos supera los 4.0 °C o presenta un fallo higiénico crítico quede automáticamente en cuarentena e informe a los usuarios en su QR *para* salvaguardar la salud pública según el Artículo II de la Constitución.
* **HU-PREV-06:** *Como* Coordinador del Servicio, *quiero* poder declarar una "Pausa Estacional / Vacía de Perecederos" motivada formalmente en centros cerrados por vacaciones *para* que las máquinas vacías no generen falsas alarmas de vencimiento en el parque.
* **HU-PREV-07:** *Como* Responsable de Sede, *quiero* ver en el portal de mi edificio el semáforo sanitario de cada máquina (vigente, próxima a vencer, vencida, en pausa o en cuarentena) *para* tener plena tranquilidad sobre los productos consumidos en mis instalaciones.
* **HU-PREV-08:** *Como* Responsable de Sede, *quiero* descargar o imprimir un Certificado Oficial de Inspección y Desinfección firmado profesionalmente *para* presentarlo ante inspectores de Sanidad o auditorías laborales sin depender de gestiones manuales.
* **HU-PREV-09:** *Como* Responsable de Sede, *quiero* que el sistema suspenda cautelarmente el certificado emitido si la máquina sufre una posterior avería de frío *para* evitar que se exhiba un documento engañoso mientras la máquina esté rota.
* **HU-PREV-10:** *Como* Técnico de Ruta, *quiero* que tras reparar la avería que provocó la cuarentena sanitaria deba completar obligatoriamente un checklist de reinspección térmica *para* validar que la máquina está apta antes de devolverla al servicio público.

---

## 4. Requisitos Funcionales (Notación EARS)

### RF-PREV-01: Configuración de Frecuencias, Ciclos Sanitarios y Pausas Estacionales
*El sistema gestionará la periodicidad máxima permitida entre inspecciones preventivas según la naturaleza sanitaria de los productos dispensados y el ciclo de vida de la máquina.*

* **EARS 1.1 (Ubícuo · Periodicidad Estándar por Tipología):** El sistema deberá mantener las siguientes frecuencias máximas entre inspecciones consecutivas según la tipología de dispensación:
  * **Alimentos Perecederos (`PERISHABLE_FOOD`):** Frecuencia estricta y tope máximo innegociable de **15 días naturales** entre revisiones.
  * **Bebidas Calientes (`HOT_DRINKS`):** Frecuencia por defecto de **30 días naturales** entre revisiones.
  * **Bebidas Frías (`COLD_DRINKS`):** Frecuencia por defecto de **45 días naturales** entre revisiones.
  * **Snacks y No Perecederos (`SNACKS`):** Frecuencia por defecto de **60 días naturales** entre revisiones.
  * **Mixta / Combinada (`COMBO`):** Se rige por la periodicidad del componente más restrictivo que contenga (15 días si incluye espirales de perecederos refrigerados, 45 días en caso contrario).
* **EARS 1.2 (Evento · Inicio de Ciclo en Máquinas Nuevas):** Cuando se dé de alta una nueva máquina en el catálogo o se active en una sede, el sistema deberá iniciar el cómputo de la primera inspección preventiva a partir de su fecha de alta/puesta en marcha (`created_at`), programando su primera revisión en la fecha resultante de sumar la frecuencia de su tipología.
* **EARS 1.3 (Excepción · Blindaje Constitucional Art. II):** Si el Coordinador intenta configurar una periodicidad superior a **15 días naturales** para una máquina de alimentos perecederos (`PERISHABLE_FOOD` o combo con perecederos), el sistema deberá rechazar la operación mostrando el mensaje de error: *"Por imperativo del Artículo II de la Constitución (Seguridad Alimentaria), las máquinas dispensadoras de alimentos perecederos no pueden superar los 15 días naturales entre inspecciones sanitarias."*
* **EARS 1.4 (Evento · Recálculo por Cambio de Frecuencia):** Cuando el Coordinador modifique la frecuencia periódica permitida de una máquina o tipología (ej. reducción a 7 días en recintos hospitalarios de alta exigencia), el sistema deberá recalcular la fecha límite de la próxima inspección tomando como referencia la fecha de la última inspección conforme registrada.
* **EARS 1.5 (Evento · Pausa Estacional / Vaciado Sanitario):** Cuando una sede suspenda su actividad temporalmente (ej. centros educativos en periodo estival u oficinas cerradas por vacaciones colectivas), el Coordinador podrá marcar la máquina en estado de **Pausa Estacional**, registrando de forma obligatoria el motivo y la fecha estimada de reanudación. Mientras permanezca en pausa estacional justificada:
  * La máquina no computará como vencida ni se teñirá de rojo en el panel de control.
  * El código QR público informará: *"Dispositivo en pausa estacional programada. Sin productos perecederos almacenados."*
  * Al reactivarse el servicio, el sistema exigirá una inspección preventiva higiénica previa antes de permitir la reintroducción de alimentos perecederos.

---

### RF-PREV-02: Generación, Programación, Asignación y Traslados
*El sistema generará y asignará tareas preventivas de forma proactiva, permitiendo asignación centralizada, visitas oportunistas y cancelaciones lógicas auditables.*

* **EARS 2.1 (Estado/Temporal · Generación Anticipada):** Mientras una máquina activa se encuentre a **5 días naturales o menos** de que expire su vigencia sanitaria y no tenga ya una orden preventiva pendiente o en curso, el sistema deberá generar automáticamente una orden de inspección en estado `PENDIENTE_ASIGNACION`.
* **EARS 2.2 (Evento · Asignación Coordinada):** Cuando el Coordinador asigne una orden preventiva a un técnico de ruta, el sistema deberá actualizar su estado a `PROGRAMADA`, fijar el técnico responsable e incorporar la tarea en la ruta de trabajo del técnico.
* **EARS 2.3 (Evento · Visita Oportunista):** Cuando un técnico de ruta se encuentre físicamente en una sede (ej. atendiendo una avería correctiva o realizando reposición autorizada), el sistema deberá permitirle consultar y **autoasignarse** cualquier orden preventiva en estado `PENDIENTE_ASIGNACION` perteneciente a esa misma sede, transicionándola a `PROGRAMADA` bajo su responsabilidad inmediata.
* **EARS 2.4 (Urgencia de Asignación):** Si la fecha límite de una inspección preventiva expira sin haber sido completada satisfactoriamente, el sistema deberá marcar la orden preventiva en estado `VENCIDA` y elevar una alerta visual prioritaria en el panel de triaje de coordinación.
* **EARS 2.5 (Evento · Traslado de Máquina o Baja Lógica · Art. III):** Si una máquina con orden de inspección preventiva pendiente es trasladada físicamente de sede o dada de baja lógica:
  * El sistema deberá cancelar de forma puramente lógica la orden preventiva previa (`status = 'CANCELLED'`), preservando íntegramente su registro histórico sin ejecutar borrados destructivos (`DELETE FROM`), en estricto cumplimiento del Artículo III de la Constitución.
  * En caso de traslado de sede, el sistema generará automáticamente una nueva orden preventiva inicial asociada a la nueva ubicación física.

---

### RF-PREV-03: Checklist Digital Normativo y Validación Térmica Rigurosa
*El técnico completará una lista de comprobación estandarizada in situ adaptada a la tipología de la máquina, con control estricto de valores físicos y severidades tipificadas.*

* **EARS 3.1 (Evento):** Cuando el técnico acceda a una orden de inspección preventiva y pulse *"Comenzar Inspección"*, el sistema deberá transicionar la orden a estado `EN_INSPECCION` y desplegar el checklist normativo correspondiente a su tipología.
* **EARS 3.2 (Validación Física de Temperatura):** En toda máquina de frío (`PERISHABLE_FOOD`, `COLD_DRINKS` o `COMBO`), el sistema deberá exigir el registro de la temperatura de sonda estabilizada en grados Celsius (°C), imponiendo:
  * Formato numérico con exactamente **un decimal** (ej. `3.4`).
  * Rango físico estricto admisible de **[-5.0 °C, +25.0 °C]**. Si el técnico teclea un valor fuera de dicho intervalo (ej. `40` por error tipográfico al omitir la coma), el sistema rechazará la entrada exigiendo corrección antes de continuar.
* **EARS 3.3 (Catálogo Normativo por Tipología y Severidades):** El checklist contendrá ítems estandarizados con severidad prefijada por normativa:
  * **Ítems Críticos (Fallo = `NO_CONFORME` y Cuarentena):**
    1. Temperatura de sonda > 4.0 °C en alimentos perecederos (`PERISHABLE_FOOD` o combo con perecederos).
    2. Fuga activa en caldera o circuito hidráulico con riesgo de quemadura/inundación en bebidas calientes.
    3. Derivación, cable pelado o ausencia de toma de tierra eléctrica (riesgo electrocución en cualquier máquina).
    4. Presencia de plagas, insectos o contaminación biológica evidente en el interior de la cabina.
  * **Ítems Secundarios (Fallo = `CONFORME_CON_OBSERVACIONES`):**
    1. Desgaste incipiente o suciedad leve en carcasas exteriores o botonera sin contacto alimentario.
    2. Iluminación LED interior parcialmente degradada o tenue.
    3. Cartucho de filtro de agua próximo a agotar ciclo (vida útil entre 75% y 95%).
    4. Goma de cierre con microdesgaste que no compromete la temperatura interior.
* **EARS 3.4 (Excepción · Checklist Incompleto):** Si el técnico intenta finalizar la inspección sin responder la totalidad de los ítems obligatorios o sin registrar la temperatura en máquinas refrigeradas, el sistema deberá impedir el envío resaltando los campos pendientes.

---

### RF-PREV-04: Evaluación de Resultados y Cuarentena Sanitaria Automática
*El sistema procesará automáticamente las respuestas del checklist para dictaminar la aptitud de la máquina y aplicará salvaguardas inmediatas.*

* **EARS 4.1 (Evento · Dictamen Automático):** Al remitir el checklist completado, el sistema deberá evaluar de forma unívoca el resultado asignando uno de los tres siguientes dictámenes:
  * **`CONFORME` (Apta):** El 100% de los puntos son conformes y la temperatura está en margen (≤ 4.0 °C en perecederos; ≤ 8.0 °C en bebidas frías).
  * **`CONFORME_CON_OBSERVACIONES` (Apta Condicionada):** Todos los ítems críticos son conformes, pero existen observaciones en ítems secundarios leves. La máquina permanece en servicio normal y se programan revisiones de seguimiento.
  * **`NO_CONFORME` (No Apta / Peligro Sanitario o Eléctrico):** Incumplimiento de al menos un ítem crítico (rotura de frío > 4.0 °C, fuga de caldera, riesgo eléctrico o plagas) en cualquier tipología de máquina.
* **EARS 4.2 (Estado · Cuarentena Sanitaria y Bloqueo Público · Art. II):** Si el dictamen de la inspección es `NO_CONFORME`:
  1. El sistema deberá situar la máquina en estado inmediato de **Cuarentena Sanitaria**.
  2. El sistema deberá actualizar en tiempo real la pantalla del código QR público de la máquina, mostrando una alerta visual roja prominente: *"MÁQUINA FUERA DE SERVICIO POR CONTROL HIGIÉNICO-SANITARIO. Por imperativo del Artículo II de la Constitución (Seguridad Alimentaria), queda prohibida la adquisición y consumo de productos de esta unidad."*
  3. El formulario de reporte ciudadano y compra quedará bloqueado para evitar dispensaciones indebidas.
* **EARS 4.3 (Evento · Inspección Satisfactoria):** Cuando la inspección concluya con resultado `CONFORME` o `CONFORME_CON_OBSERVACIONES`:
  1. El sistema registrará la fecha/hora actual como la última inspección válida.
  2. Calculará y fijará la nueva fecha de vencimiento sumando la periodicidad establecida.
  3. Restablecerá la máquina a servicio operativo normal si se encontraba bloqueada por causas preventivas.

---

### RF-PREV-05: Coexistencia con Averías Correctivas (Cumplimiento Art. V.1 y Art. V.2)
*Las órdenes preventivas operarán de forma armónica con las incidencias correctivas, garantizando la inviolabilidad del ticket único activo y el cierre documentado.*

* **EARS 5.1 (Ubícuo · Independencia Estructural):** La orden de inspección preventiva operará con ciclo de vida e identificadores independientes de la tabla de incidencias correctivas, garantizando auditoría higiénica continua.
* **EARS 5.2 (Evento · Apertura de Correctivo sin Ticket Previo):** Si durante la inspección preventiva se detecta un desperfecto (crítico o secundario) y la máquina **NO** tiene ninguna incidencia correctiva activa:
  1. El sistema deberá generar automáticamente una nueva incidencia correctiva vinculada a la máquina y referenciada a la inspección preventiva de origen.
  2. Si el fallo detectado implica rotura térmica (> 4.0 °C en perecederos) o riesgo crítico, la incidencia se creará directamente con nivel de urgencia **`CRÍTICA`** (Artículo II).
  3. La incidencia correctiva recién creada se asignará de inmediato al mismo técnico inspector presente en la máquina.
  4. Para resolver la incidencia en el futuro, el técnico deberá consignar obligatoriamente un diagnóstico real (≥ 20 caracteres) y una acción correctora (≥ 20 caracteres), en estricto cumplimiento del Artículo V.1.
* **EARS 5.3 (Excepción · Concurrencia con Incidencia Activa Preexistente · Art. V.2):** Si la máquina **YA TIENE** una incidencia correctiva abierta o en curso en el momento de detectar el fallo en el preventivo:
  1. Para impedir la generación de tickets duplicados (Artículo V.2), el sistema **NO** creará una segunda incidencia correctiva.
  2. El sistema incorporará automáticamente los hallazgos de la inspección como un comentario formal auditado dentro de la bitácora de la incidencia activa (`incident_comments`), adjuntando las evidencias fotográficas registradas durante el preventivo.
  3. Si la no conformidad del preventivo es crítica (ej. pérdida de frío sobrevenida), el sistema **elevará de forma automática la urgencia del ticket existente a `CRÍTICA`**, notificando de inmediato a Coordinación.

---

### RF-PREV-06: Semáforo de Vigencia Sanitaria en Portal de Sede y Paneles
*Los responsables de ubicación y coordinadores visualizarán el estado sanitario de las máquinas de forma unificada e intuitiva.*

* **EARS 6.1 (Ubícuo · Estados del Semáforo Higiénico):** El sistema deberá reflejar para cada máquina el estado higiénico-sanitario mediante el siguiente código unificado:
  * **Verde (Vigente):** Inspección conforme superada y restan más de 5 días naturales para su vencimiento.
  * **Amarillo (Próxima a Vencer):** Faltan 5 días naturales o menos para expirar la vigencia sanitaria.
  * **Rojo (Vencida):** Ha expirado la fecha límite sin que se haya completado una inspección conforme.
  * **Rojo Parpadeante / Cuarentena:** Inspección evaluada como `NO_CONFORME` o máquina en cuarentena sanitaria por riesgo alimentario.
  * **Azul / Gris Neutro (Pausa Estacional):** Máquina en suspensión vacacional justificada sin perecederos almacenados.
* **EARS 6.2 (Estado · Portal de Responsable de Sede):** Mientras el responsable de ubicación consulte el portal de su edificio, el sistema deberá mostrar la lista de máquinas con su semáforo de vigencia, la fecha de la última desinfección y el último registro térmico.
* **EARS 6.3 (Estado · Panel del Coordinador):** El panel de triaje de coordinación dispondrá de un filtro específico para listar y ordenar con un clic todas las máquinas con inspección `Vencida` o en `Cuarentena` en todo el parque de máquinas.

---

### RF-PREV-07: Emisión, Descarga y Suspensión Cautelar de Certificados Sanitarios
*El sistema generará documentación formal acreditativa con validez jurídica para clientes y autoridades sanitarias, protegiendo los datos del personal (Art. V.4).*

* **EARS 7.1 (Evento · Descarga de Certificado Individual):** Cuando un responsable de ubicación o coordinador pulse *"Descargar Certificado Sanitario"* en una máquina con inspección vigente:
  * El sistema deberá generar un certificado formal en formato A4 imprimible / PDF.
  * El documento contendrá: código único de certificado, código de máquina, modelo y número de serie, sede cliente y dirección, fecha/hora exacta de la inspección, desglose de verificaciones (temperatura de sonda, desinfección, filtros), dictamen (`CONFORME`) y fecha límite de vigencia.
  * **Privacidad (Artículo V.4):** El documento identificará al inspector exclusivamente mediante su nombre profesional y **Código de Operador Técnico Oficial** (ej: `OP-02`), omitiendo DNI privado, teléfono personal o datos de contacto particulares.
* **EARS 7.2 (Evento · Certificado Global Consolidado de Sede):** Cuando se solicite el *"Certificado Global de Sede"*, el sistema generará un documento único desglosando cada una de las máquinas instaladas en el edificio:
  * Si todas las máquinas están vigentes y conformes, el dictamen global del edificio será `CONFORME`.
  * Si alguna máquina presenta observaciones secundarias sin fallos críticos, el dictamen global figurará como `CONFORME_CON_OBSERVACIONES`.
  * Si al menos una máquina se encuentra vencida, en cuarentena sanitaria o con una avería de frío activa, el dictamen global del certificado de sede figurará de forma transparente como **`CONDICIONADO`**, detallando la máquina afectada y la no conformidad detectada.
* **EARS 7.3 (Estado/Evento · Suspensión Cautelar por Avería Sobrevenida):** Si una máquina que contaba con un certificado de inspección conforme sufre con posterioridad una avería correctiva de rotura de cadena de frío o riesgo sanitario, el sistema deberá situar el certificado de forma automática en estado **`SUSPENDIDO`**, inhabilitando su descarga pública y mostrando la condición suspendida tanto en el portal de sede como en el escaneo QR hasta la total subsanación y reinspección.
* **EARS 7.4 (Excepción · Bloqueo de Emisión en No Aptas):** Si el usuario intenta generar el certificado individual de una máquina en cuarentena o vencida, el sistema bloqueará la emisión de cualquier credencial de aptitud, emitiendo en su lugar un *Informe Técnico de No Conformidad*.

---

### RF-PREV-08: Reinspección tras Subsanación y Levantamiento de Cuarentena
*El sistema regulará el procedimiento estricto para restituir máquinas que habían sido declaradas no conformes.*

* **EARS 8.1 (Evento · Desencadenamiento Obligatorio de Reinspección):** Cuando el técnico complete y cierre la incidencia correctiva vinculada que originó la cuarentena sanitaria (consignando diagnóstico y solución técnica con ≥ 20 caracteres cada uno, Art. V.1), el sistema deberá abrir de forma obligatoria el flujo de **Reinspección Sanitaria Inmediata** antes de permitir la reactivación de la máquina.
* **EARS 8.2 (Estado · Medición Térmica de Verificación):** Durante la reinspección de una máquina de alimentos perecederos tras avería de frío, el técnico deberá registrar una nueva medición de temperatura estabilizada; únicamente si el valor registrado se sitúa en el rango normativo (≤ 4.0 °C), el sistema permitirá dar por superada la reinspección.
* **EARS 8.3 (Evento · Levantamiento de Bloqueo Condicionado):** Al confirmar una reinspección satisfactoria, el sistema restituirá la máquina a estado `Operativa` en el QR público, emitirá un nuevo certificado de inspección conforme y archivará todo el expediente de forma inmutable (Art. III), **siempre y cuando no existan otras incidencias correctivas activas pendientes ni garantías técnicas abiertas sobre la máquina**.

---

## 5. Requisitos No Funcionales (RNF)

* **RNF-01 (Agilidad Operativa Móvil):** El formulario de checklist en el dispositivo móvil del técnico deberá completarse en menos de **90 segundos** en condiciones habituales mediante controles táctiles optimizados (selectores rápidos de un toque y teclado numérico automático para temperatura).
* **RNF-02 (Trazabilidad e Inmutabilidad · Art. III):** Queda terminantemente prohibido modificar o eliminar retrospectivamente cualquier informe de inspección o reinspección firmado y cerrado. Queda prohibida cualquier sentencia destructiva (`DELETE FROM`). Las anulaciones por traslado o baja se gestionan mediante estados lógicos (`status = 'CANCELLED'`).
* **RNF-03 (Impresión y Exportación Nativa):** Los certificados higiénico-sanitarios (individuales y globales de sede) deberán contar con hoja de estilo optimizada para impresión en formato estándar A4 (`@media print`), legibles tanto en pantalla como al imprimirse en papel para exhibición en dependencias del cliente.
* **RNF-04 (Consistencia Horaria y Auditoría):** Todas las marcas de tiempo de inspecciones, mediciones de temperatura y vencimientos se registrarán con resolución al segundo en tiempo universal coordinado (UTC), mostrándose al usuario convertidas a su huso horario local.
* **RNF-05 (Seguridad y Privacidad de Datos · Art. V.4):** Los certificados y vistas accesibles para los responsables de sede o clientes ocultarán los datos personales privados de los técnicos (DNI, teléfono personal), exhibiendo únicamente su nombre profesional y su Código de Operador Técnico Oficial.
* **RNF-06 (Integridad Térmica y Blindaje Anti-Errata):** La entrada de temperatura de sonda estará restringida en frontend y backend al rango físico estricto de **`[-5.0 °C, +25.0 °C]`** con un único decimal, impidiendo el almacenamiento de valores inverosímiles debidos a errores humanos de digitación.

---

## 6. Casos Límite Operativos (Edge Cases)

1. **Temperatura en el umbral exacto (4.0 °C vs 4.1 °C):** Si la sonda mide exactamente 4.0 °C, el sistema la evaluará como `CONFORME` (límite superior reglamentario). A partir de 4.1 °C, se activará de forma automática el dictamen `NO_CONFORME`, la cuarentena sanitaria y la alerta en el QR.
2. **Entrada de temperatura errónea (omisión de coma decimal):** Si un técnico introduce `40` en lugar de `4.0`, el sistema interceptará el valor por exceder el límite físico de 25.0 °C (RNF-06), impidiendo el registro y solicitando al técnico la corrección inmediata.
3. **Pausa Estacional en vacaciones colectivas:** En sedes cerradas en agosto o vacaciones escolares, la máquina se marca en pausa estacional documentada; no genera órdenes vencidas ni alertas rojas, y el QR informa de la ausencia temporal de alimentos.
4. **Coexistencia con ticket correctivo abierto previo (Art. V.2):** Si la máquina ya tiene una incidencia correctiva activa, el preventivo no abre un nuevo ticket; en su lugar, incorpora sus anotaciones y fotografías a la bitácora del ticket activo (`incident_comments`) y eleva su urgencia a `CRÍTICA` si hay fallo de frío.
5. **Traslado físico de máquina entre sedes (Art. III):** Al cambiar la sede de una máquina, la orden preventiva pendiente de la sede origen se cancela lógicamente (`status = 'CANCELLED'`) sin borrado físico, y se genera una nueva orden preventiva inicial en la sede de destino.
6. **Inspección no evaluable por causas de fuerza mayor:** Si la máquina está inaccesible por obras del cliente o corte eléctrico externo ajeno al operador, el técnico registra la inspección como `NO_EVALUABLE_POR_CAUSA_EXTERNA`, lo que no emite certificado pero deja constancia justificada para Coordinación sin imputar negligencia al técnico.
7. **Oscilación térmica transitoria por reposición:** Si la temperatura se eleva temporalmente debido a la apertura de puerta para la reposición de producto, el técnico debe esperar a que el compresor restablezca la temperatura de régimen antes de consignar la lectura oficial en el checklist.
8. **Avería de frío sobrevenida tras inspección conforme:** Si una máquina con certificado vigente sufre posteriormente una avería técnica de refrigeración, el certificado emitido pasa de forma automática a estado `SUSPENDIDO` hasta que se resuelva la avería y se supere la reinspección obligatoria.

---

## 7. Fuera de Alcance (Out of Scope)

Para salvaguardar el **Artículo VI de la Constitución (Anti-Feature Creep)**, quedan formalmente excluidas de este módulo las siguientes áreas funcionales:

* **Telemetría telemática continua IoT por sensores automáticos:** No se integran sondas MQTT ni buses MDB en tiempo real. Todas las mediciones y verificaciones provienen exclusivamente de la inspección física in situ ejecutada por el técnico de ruta con termómetro calibrado.
* **Integración con laboratorios bromatológicos externos:** No se contemplan pasarelas de intercambio telemático con laboratorios microbiológicos ni subida de informes de cultivos de patógenos.
* **Gestión de pedidos automáticos y stock de consumibles químicos:** No se incluye control de inventario de botellas de producto bactericida en vehículos de ruta ni pedidos automáticos a proveedores de químicos.
* **Encuestas periódicas de satisfacción de consumidores:** El módulo se circunscribe con rigor a la aptitud higiénico-sanitaria y mecánica reglamentaria, excluyendo encuestas mercadotécnicas a usuarios finales.

---

## 8. Criterios de Finalización (Definition of Done)

* [ ] El 100% de los requisitos funcionales (RF-PREV-01 al RF-PREV-08) están formalmente implementados y cubiertos por pruebas de aceptación automatizadas en notación EARS.
* [ ] Se verifica que toda máquina de perecederos genera su orden preventiva con ciclo de 15 días y rechaza configuraciones superiores a 15 días por imperativo constitucional (Art. II).
* [ ] Se comprueba que el checklist exige obligatoriamente la temperatura en máquinas refrigeradas dentro del rango físico `[-5.0 °C, +25.0 °C]` y que valores > 4.0 °C en perecederos declaran `NO_CONFORME`, activando la cuarentena sanitaria inmediata en el código QR público.
* [ ] Se valida que ante una no conformidad detectada: si no hay ticket previo se abre una incidencia correctiva vinculada (urgencia `CRÍTICA` si es térmica); y si ya existe un ticket activo, se incorpora a los comentarios de la bitácora sin crear duplicados (Art. V.2) y se eleva la urgencia a `CRÍTICA`.
* [ ] Se comprueba que el cierre de la incidencia vinculada exige diagnóstico y solución (≥ 20 caracteres cada uno, Art. V.1) y que desencadena obligatoriamente la reinspección sanitaria.
* [ ] Se prueba el correcto funcionamiento del semáforo sanitario (Verde, Amarillo, Rojo, Cuarentena y Pausa Estacional) tanto en la ficha de máquina como en el portal de sede del cliente.
* [ ] Se valida la emisión, visualización e impresión en formato A4 del Certificado Sanitario Oficial (individual y consolidado de sede con dictamen `CONDICIONADO` ante incidencias), respetando la privacidad del técnico mediante su Código de Operador Técnico (Art. V.4).
* [ ] Se comprueba que una avería posterior de frío suspende cautelarmente el certificado emitido.
* [ ] Se confirma que no existe ninguna instrucción de borrado físico (`DELETE FROM`) en todo el ciclo preventivo, gestionándose cancelaciones por traslado o baja mediante borrado lógico (Art. III).
* [ ] Se comprueba la estricta observancia del Dualismo Lingüístico (código, nombres técnicos y pruebas en inglés; interfaz gráfica, mensajes y certificados en español).

---

## 9. Registro de Resoluciones y Decisiones Formales

* [x] **Decisión 1 (Frecuencia Máxima de Perecederos · Art. II):** Se fija como límite absoluto e innegociable **15 días naturales** para `PERISHABLE_FOOD` y combos refrigerados. El sistema rechaza cualquier intento de configurar periodos superiores a 15 días en perecederos.
* [x] **Decisión 2 (Coexistencia con Averías · Art. V.1 y V.2):** Si no hay ticket activo previo, se abre una incidencia correctiva vinculada cuya resolución exige obligatoriamente diagnóstico y acción (≥ 20 caracteres cada uno). Si ya existe una incidencia activa, no se duplica el ticket; se añade la evidencia a la bitácora (`incident_comments`) y se eleva la urgencia a `CRÍTICA` ante roturas térmicas.
* [x] **Decisión 3 (Visita Oportunista y Asignación):** Se faculta al técnico para autoasignarse órdenes preventivas pendientes de la sede en la que se encuentra trabajando físicamente para optimizar jornadas de ruta.
* [x] **Decisión 4 (Reinspección y Cierre de Cuarentena):** El levantamiento de la cuarentena exige obligatoriamente resolver el correctivo justificado (Art. V.1), superar el checklist de reinspección (temperatura ≤ 4.0 °C) y constatar que no existen otras incidencias activas pendientes en la máquina.
* [x] **Decisión 5 (Catálogo Normativo y Rango Térmico Físico):** Se acota la entrada de temperatura a `[-5.0 °C, +25.0 °C]` con 1 decimal. Se categorizan los ítems en Críticos (fuerzan `NO_CONFORME` y cuarentena en cualquier tipología: temperatura > 4.0 °C, fugas de caldera, riesgo eléctrico o plagas) y Secundarios (`CONFORME_CON_OBSERVACIONES`).
* [x] **Decisión 6 (Privacidad del Técnico · Art. V.4):** En los certificados para sedes se ocultan teléfonos personales y DNI del personal técnico, identificándolos mediante nombre profesional y **Código de Operador Técnico Oficial** (ej: `OP-02`).
* [x] **Decisión 7 (Suspensión Cautelar de Certificados):** Si tras una inspección conforme la máquina sufre una avería de frío posterior, el certificado queda automáticamente `SUSPENDIDO` en QR y portal de sede hasta su subsanación y reinspección.
* [x] **Decisión 8 (Certificado Global de Sede Condicionado):** El certificado consolidado desglosa cada máquina y dictamina `CONDICIONADO` si alguna de las máquinas de la sede se encuentra en cuarentena o con inspección vencida.
* [x] **Decisión 9 (Pausa Estacional Justificada):** Se permite la suspensión estacional motivada para sedes cerradas en vacaciones, evitando falsos positivos de vencimiento en máquinas vacías de perecederos.
* [x] **Decisión 10 (Traslados y Cancelación Lógica · Art. III):** El traslado de una máquina cancela lógicamente la orden preventiva de la sede origen (`status = 'CANCELLED'`) sin borrado físico, programando una nueva orden en la sede destino.

---

## 10. Ampliaciones Formalizadas

* [x] **Ampliación 11 (Ficha Integral de Detalle de Orden Preventiva · Módulo 09 espejo):** Se formaliza la especificación [`preventive_order_detail_modal_spec.md`](preventive_order_detail_modal_spec.md) (RF-PD-01 a RF-PD-10 y RNF-PD-01 a RNF-PD-07) y su contrato técnico [`preventive_order_detail_contracts.md`](../technical/preventive_order_detail_contracts.md). La ficha es **estrictamente de consulta**: consolida cabecera, sede/máquina, vigencia sanitaria, técnico inspector, checklist normativo respondido, dictamen con temperatura y notas, avería correctiva vinculada (Art. V.2), certificado sanitario consultado (Art. V.4) y traza de auditoría (Art. III). No introduce acciones de escritura, no emite certificados y no altera ni una fila del histórico.
* [x] **Ampliación 12 (Composición de la trazabilidad de la ficha preventiva · Decisión 12):** La cronología de auditoría del bloque `audit_trail` se compone del recorrido `MACHINE` de la máquina de la orden —el camino que escriben realmente los controladores y servicios del módulo mediante `AuditLogger::logMachineEvent`— filtrado por el vocabulario preventivo y atribuido por `metadata.order_code` / `metadata.order_id` (contrato §1.2). No se lee ni se siembra bajo `PREVENTIVE_ORDER`: la migración 004 estrecha el enum y, sin `STRICT_TRANS_TABLES`, vaciaría en silencio a `''` cualquier fila previa, abortando el arranque en la migración 012; el histórico nuevo bajo esa entidad queda reservado por `AuditEntityTypesTest` (4.9) y la documentación de `AuditLogger`.
