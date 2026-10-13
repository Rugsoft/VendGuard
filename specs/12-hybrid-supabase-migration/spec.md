# ESPECIFICACIÓN FUNCIONAL: MIGRACIÓN DE PERSISTENCIA A BASE DE DATOS EN LA NUBE (ENFOQUE HÍBRIDO)

**Documento:** `specs/12-hybrid-supabase-migration/spec.md`  
**Referencia de Módulo:** Módulo 12 (`12-hybrid-supabase-migration`)  
**Estado:** Especificación Formal Ratificada y Blindada tras Auditoría QA (Todas las resoluciones ratificadas con Opción A)  
**Fecha:** Octubre 2026  
**Proyecto:** VendGuard · Gestor de Incidencias de Vending  
**Normativa Suprema:** [`constitution.md`](../../constitution.md) (Artículos I al VII, especialmente Art. I.3, Art. II, Art. III.1, Art. V.1, Art. V.2, Art. V.6 y Art. VI.1)  

---

## 1. Contexto y Objetivo

### 1.1. Contexto Operativo
VendGuard gestiona el ciclo de vida completo de incidencias correctivas, órdenes de mantenimiento preventivo, rutas logísticas de técnicos, trazabilidad de piezas sustituidas, reembolsos económicos por importe retenido e hilos de comentarios bidireccionales. Hasta ahora, el sistema ha dependido de una base de datos relacional ejecutada en entornos locales o contenedores tradicionales, lo que requiere gestión manual de infraestructura, tareas periódicas de mantenimiento y añade riesgo de indisponibilidad ante cortes de servicio.

Para dotar al sistema de persistencia administrada en la nube con copias de seguridad continuas y alta disponibilidad, sin incurrir en costes mensuales fijos (aprovechando un plan de uso gratuito), se define la migración de la capa de datos bajo una **estrategia híbrida**.

### 1.2. Objetivo
Definir los requisitos funcionales, operacionales y de blindaje normativo para **migrar la persistencia de datos a una base de datos relacional administrada en la nube**, asegurando:
1. **Transparencia funcional absoluta para el usuario:** Cero alteraciones en las vistas de técnicos, sedes y ciudadanos, conservando intactos todos los flujos de trabajo de los 11 módulos previos.
2. **Compatibilidad dual transparente (Agnosticismo de persistencia):** El sistema debe ser capaz de operar indistintamente contra la base de datos administrada en la nube (entornos de producción/homologación) o contra un motor relacional local (entornos de desarrollo y ejecución de pruebas offline sin conexión a internet).
3. **Arranque en limpio (*Fresh Start*) y preservación de fixtures:** Inicialización formal de las entidades maestras (sedes, máquinas, usuarios, catálogo de repuestos y rutas) en producción sin arrastre de expedientes antiguos, garantizando al mismo tiempo que el entorno de pruebas disponga de los fixtures necesarios para certificar la suite al 100% en verde.
4. **Prevención proactiva de suspensión y gestión de arranque en frío (*Cold Start*):** Un latido automático diario (*keep-alive*) evita que el servicio en la nube se suspenda por inactividad. En caso de despertar tras suspensión, el sistema tolera una ventana de gracia de hasta 25 segundos con feedback explícito, evitando fallos prematuros de tiempo de espera (*timeout*).
5. **Transaccionalidad compensatoria de archivos:** Las evidencias fotográficas se mantienen en el servidor de aplicaciones, implementando la reversión y borrado inmediato de archivos huérfanos si la inserción en la base de datos remota se interrumpe por fallos de red.
6. **Blindaje constitucional integral en el motor de persistencia:** Protección anti-borrado físico (`DELETE FROM`) en **todas** las tablas maestras y operativas (Art. III.1), detección preventiva obligatoria de duplicados complementada con índice atómico que incluye el estado `RESOLVED` durante la ventana de garantía (Art. V.2 y V.6), justificación obligatoria $\ge 20$ caracteres (Art. V.1), anonimización ciudadana (Art. V.4) y salvaguarda insalvable de cuarentena alimentaria a las 4 horas (Art. II).

---

## 2. Usuarios y Perfiles Implicados

| Perfil / Actor | Impacto Visual de la Migración | Responsabilidades Operativas | Comportamiento ante Fallo de Enlace |
| :--- | :--- | :--- | :--- |
| **📱 Técnico de Ruta** | **Cero cambios visuales.** Mismas pantallas de paradas, checklists, repuestos y pausas. | Ejecuta intervenciones in situ, declara piezas y pausas como siempre. | Si pierde cobertura, el borrador de su parte no se destruye. Si la máquina entró en cuarentena sobrevenida, el sistema conserva su informe y le exige checklist térmico antes de cerrar. |
| **🏢 Responsable de Sede** | **Cero cambios visuales.** Mismo portal de sedes con estado de parque y conversación. | Consulta avisos, valida llaves de acceso y aporta respuestas aclaratorias. | La aplicación no muestra volcados de error técnico; presenta un aviso flotante temporal indicando que el servicio está reconectando. |
| **📊 Coordinador de Operaciones** | **Cero cambios en operativa diaria + Verificación técnica.** Mismo cuadro de mandos, triaje y mapa. | Supervisión de flota, asignación de averías, gestión de SLAs y reintegros. | Cuenta con un endpoint técnico de diagnóstico de salud (`/api/health`) para verificar la latencia y disponibilidad del enlace remoto. |
| **👥 Ciudadano / Usuario QR** | **Cero cambios visuales.** Mismo reporte público y seguimiento anónimo. | Escanea el código QR de la máquina, reporta anomalías o solicita reintegros. | En caso de desconexión puntual, visualiza un mensaje amable invitando a reintentar la acción en unos instantes. |
| **🛠️ Administrador de Sistemas / QA** | **Herramientas de entorno.** Configuración y diagnóstico del enlace. | Inicialización de datos maestros, verificación de salud y ejecución de pruebas. | Puede conmutar la aplicación para ejecutar pruebas 100% en local sin internet o validar contra la nube con paridad de resultados. |

---

## 3. Historias de Usuario

### HU-01: Continuidad Técnica Absoluta en Campo
> **Como** Técnico de Campo en ruta,  
> **quiero** gestionar mis órdenes de trabajo, registrar sustitución de piezas y tramitar pausas por falta de acceso con la misma interfaz e inmediatez que hasta ahora,  
> **para** cumplir mis paradas diarias sin sufrir fricciones, cambios de diseño ni retrasos provocados por la infraestructura del sistema.

### HU-02: Garantía Inviolable de Seguridad Alimentaria sin Duplicidad de Reglas (Art. II y Art. I.3)
> **Como** Responsable Sanitario y Coordinador de Operaciones,  
> **quiero** que la detección de rotura de cadena de frío a las 4 horas naturales en máquinas de perecederos se ejecute con la misma lógica canónica tanto en la nube como en local,  
> **para** tener la certeza absoluta de que el comportamiento evaluado y certificado en pruebas es idéntico al que protege la salud de los consumidores en producción.

### HU-03: Desarrollo y Certificación de Pruebas sin Conexión a Internet
> **Como** Ingeniero de Calidad (QA) o Desarrollador,  
> **quiero** ejecutar la batería completa de pruebas automatizadas en mi equipo local desconectado y obtener exactamente los mismos resultados que contra el entorno en la nube,  
> **para** certificar que no existen regresiones de código de forma ágil, autónoma y sin depender de la disponibilidad de un servicio externo.

### HU-04: Resiliencia ante Desconexión y Protección de Borradores Técnicos
> **Como** Técnico de Campo que trabaja en sótanos o zonas con cobertura intermitente,  
> **quiero** que si el enlace con la base de datos remota se interrumpe durante el guardado de mi intervención, el sistema conserve intacto el texto de diagnóstico y repuestos redactados y me permita reintentar el envío al recuperar señal,  
> **para** no perder mi tiempo de trabajo administrativo ni verme obligado a redactar de nuevo los detalles de la reparación.

### HU-05: Monitorización y Prevención de Suspensión sin Aumento de Alcance (Art. VI.1)
> **Como** Administrador del Sistema,  
> **quiero** que un latido automático diario impida la suspensión de la base de datos en la nube y disponer de una comprobación técnica ligera de salud del servicio,  
> **para** asegurar que el sistema esté operativo 24/7 sin haber tenido que desarrollar paneles ni pantallas complejas que desvíen el foco del MVP.

---

## 4. Requisitos Funcionales (RF) con Criterios de Aceptación (Notación EARS)

### RF-01: Persistencia Dual Transparente y Agnosticismo de Motor
El sistema deberá ser plenamente agnóstico respecto a la plataforma relacional, operando de forma indistinta contra el motor en la nube o contra el motor relacional local.

* **EARS-01.1 (Ubicuo):** El sistema siempre deberá garantizar la integridad referencial, el aislamiento transaccional y la exactitud de los cálculos temporales de SLA y MTTR con independencia del motor relacional activo.
* **EARS-01.2 (Dirigido por Estado):** MIENTRAS el sistema esté configurado para operar contra la base de datos en la nube, el sistema deberá canalizar las operaciones a través de un agrupador transaccional de conexiones (*connection pooler*) para absorber la concurrencia dentro de los límites de recursos del plan gratuito.
* **EARS-01.3 (Dirigido por Estado):** MIENTRAS el sistema esté configurado para operar en el entorno local desconectado, el sistema deberá ejecutar todas las consultas y pruebas sin emitir ninguna llamada de red hacia el exterior.
* **EARS-01.4 (Dirigido por Evento):** CUANDO se modifique la variable de configuración de persistencia del entorno, el sistema deberá conmutar su operativa al motor correspondiente sin requerir cambios en la lógica de las entidades de dominio ni en las pantallas de usuario.

---

### RF-02: Inicialización en Limpio (*Fresh Start*) y Paridad de Fixtures de Prueba
El sistema deberá proveer mecanismos diferenciados de inicialización: carga en limpio de catálogo maestro para producción y sembrado de fixtures para la certificación de pruebas.

* **EARS-02.1 (Dirigido por Evento):** CUANDO se inicialice el entorno de producción en una base de datos vacía, el sistema deberá registrar el catálogo maestro completo (sedes, máquinas, usuarios autorizados, catálogo de repuestos y configuraciones de ruta) sin incidencias activas ni historiales previos.
* **EARS-02.2 (Dirigido por Evento):** CUANDO se inicialice el entorno de ejecución de pruebas automatizadas (tanto en local como en la nube), el sistema deberá sembrar el arnés de datos y fixtures históricos necesarios para que la suite de pruebas completa certifique el ciclo de vida sin discrepancias.
* **EARS-02.3 (Ubicuo):** El sistema siempre deberá inicializar las credenciales de usuarios maestros con contraseñas seguras por defecto validadas mediante algoritmo de hash criptográfico estándar, permitiendo su gestión posterior mediante el módulo de administración existente.

---

### RF-03: Prevención Activa de Suspensión por Inactividad (*Keep-Alive*)
El sistema deberá evitar de forma proactiva que la base de datos en la nube entre en suspensión tras periodos prolongados sin tráfico.

* **EARS-03.1 (Ubicuo):** El sistema siempre deberá contar con un mecanismo de latido periódico desatendido orquestado externamente (mediante flujo automatizado diario a las 06:00 UTC) que consulte el endpoint técnico ligero de salud (`GET /api/health`).
* **EARS-03.2 (Dirigido por Evento):** CUANDO el mecanismo de latido se ejecute, el sistema deberá realizar una consulta ligera de verificación sobre la base de datos para registrar actividad válida ante el proveedor de la nube.
* **EARS-03.3 (No Deseado / Excepción):** SI el mecanismo de latido no recibe respuesta del servicio en la nube tras 3 reintentos consecutivos, ENTONCES el sistema deberá emitir una alerta en el registro de diagnóstico para notificación administrativa.

---

### RF-04: Degradación Visual Controlada, Tolerancia a *Cold Start* y Resiliencia de Borradores
La interfaz de usuario deberá responder con resiliencia ante tiempos de arranque en frío o fallos de conectividad, protegiendo los datos introducidos por los operarios.

* **EARS-04.1 (Dirigido por Estado):** MIENTRAS la base de datos en la nube se encuentre en proceso de reactivación tras suspensión (*Cold Start*), el sistema deberá admitir una ventana de gracia de conexión de hasta **25 segundos**, mostrando un aviso informativo no bloqueante (*"Iniciando servicio en la nube, por favor espera unos segundos..."*).
* **EARS-04.2 (Dirigido por Estado):** MIENTRAS la conexión se encuentre establecida y activa, el sistema deberá aplicar un tiempo de espera (*timeout*) ordinario de **5,0 segundos** para todas las operaciones de consulta y guardado.
* **EARS-04.3 (No Deseado / Excepción):** SI una petición agota el tiempo de espera ordinario o sufre una interrupción de red, ENTONCES el sistema deberá mostrar un banner flotante global no intrusivo (*"Servicio temporalmente no disponible / Reconectando..."*) preservando íntegramente los datos y textos redactados en el formulario en memoria.
* **EARS-04.4 (Dirigido por Evento):** CUANDO se presente el banner de desconexión, el sistema deberá ofrecer un botón táctil accesible ($\ge 44\text{ px}$) para reintentar el envío de forma inmediata sin recargar la página.
* **EARS-04.5 (Dirigido por Evento):** CUANDO se pulse el botón de envío de un formulario, el sistema deberá deshabilitarlo temporalmente y mostrar un indicador de carga para impedir peticiones duplicadas accidentales.

---

### RF-05: Acotamiento de Datos Relacionales y Transaccionalidad Compensatoria de Archivos
La migración de persistencia se circunscribe a los datos tabulares, manteniendo las evidencias multimedia en el servidor y evitando fugas de archivos huérfanos.

* **EARS-05.1 (Ubicuo):** El sistema siempre deberá almacenar en la base de datos en la nube únicamente datos estructurados, cadenas de texto, cifras y marcas temporales, quedando expresamente prohibido el almacenamiento de imágenes binarias en columnas de la base de datos.
* **EARS-05.2 (Dirigido por Evento):** CUANDO un usuario adjunte una fotografía o evidencia gráfica, el sistema deberá guardar físicamente el archivo en el almacenamiento del servidor de aplicaciones e insertar la ruta relativa en la base de datos.
* **EARS-05.3 (No Deseado / Excepción):** SI tras guardar físicamente el archivo en el servidor, la inserción del registro en la base de datos remota falla por error de red o timeout, ENTONCES el sistema deberá eliminar inmediatamente el archivo recién creado en el servidor, garantizando que no queden archivos huérfanos ocupando espacio en disco.
* **EARS-05.4 (Ubicuo):** El sistema siempre deberá servir las imágenes a través de los canales habituales del servidor de aplicaciones, impidiendo que la entrega de fotografías consuma la cuota de transferencia saliente (*egress*) de la base de datos en la nube.

---

### RF-06: Automatización del Barrido Sanitario con Fuente Canónica Única (Art. II y Art. I.3)
La inspección biológica y la activación automática de cuarentena sanitaria a las 4 horas en máquinas de perecederos deberán regirse por una única lógica canónica idéntica en todos los entornos.

* **EARS-06.1 (Ubicuo):** El sistema siempre deberá concentrar la lógica y reglas de cálculo del barrido sanitario en el servicio de dominio de la aplicación como única fuente de verdad (Art. I.3), prohibiendo divergencias de cálculo entre procedimientos almacenados de base de datos y código de aplicación.
* **EARS-06.2 (Dirigido por Estado):** MIENTRAS la aplicación opere en el entorno de nube, un programador de tareas periódico del motor invocará de forma autónoma cada 15 minutos el servicio de barrido sanitario para evaluar si existen máquinas de alimentos perecederos con más de 4 horas continuas de avería sin frío confirmado.
* **EARS-06.3 (Dirigido por Evento):** CUANDO el barrido detecte una máquina de perecederos que alcance las 4 horas naturales continuas sin frío, el sistema deberá declarar de inmediato el estado `QUARANTINE` en la máquina, registrar la fecha de inicio de cuarentena y asentar un evento inmutable en el historial de la incidencia.
* **EARS-06.4 (Dirigido por Estado):** MIENTRAS la aplicación opere en el entorno local desconectado, el sistema deberá mantener la invocación periódica de este mismo servicio mediante el comando programado habitual, garantizando exactamente el mismo resultado y la misma auditoría.

---

### RF-07: Comprobación Técnica Ligera de Salud del Enlace (*Health Check*, Art. VI.1)
El sistema deberá proveer un mecanismo ligero de verificación de conectividad y latencia sin incorporar interfaces complejas que desvirtúen el alcance del MVP.

* **EARS-07.1 (Dirigido por Evento):** CUANDO un coordinador o monitor externo emita una petición `GET /api/health`, el sistema deberá verificar la conectividad activa contra el motor de persistencia y responder con un diagnóstico estructurado en menos de 1,0 segundo.
* **EARS-07.2 (Ubicuo):** La respuesta de salud siempre deberá informar del estado operativo (operativo o degradado), el motor de persistencia activo y el tiempo de respuesta de la consulta de comprobación.
* **EARS-07.3 (No Deseado / Excepción):** SI la comprobación no puede conectar con la base de datos activa, ENTONCES el sistema deberá devolver un código HTTP 503 con indicación de servicio no disponible, absteniéndose de exponer credenciales, contraseñas, URLs internas o volcados de excepciones técnicas.

---

### RF-08: Blindaje Constitucional Integral en el Motor de Persistencia
El motor de datos deberá aplicar de forma activa e inviolable las reglas de la Constitución de VendGuard.

* **EARS-08.1 (Ubicuo):** El motor de datos siempre deberá impedir mediante reglas o triggers anti-delete cualquier sentencia destructiva (`DELETE FROM`) sobre **todas** las entidades maestras y operativas (máquinas, sedes, usuarios, incidencias, repuestos, reintegros, historial y auditoría), preservando de forma inviolable el Artículo III.1.
* **EARS-08.2 (Ubicuo):** El sistema siempre deberá comprobar preventivamente en la capa de aplicación y advertir al usuario si una máquina ya cuenta con una incidencia abierta (Art. V.2); como red de seguridad final ante concurrencia, el motor de datos rechazará atómicamente la inserción si ya existe un ticket donde el estado sea distinto de `CLOSED` y `CANCELLED` (abarcando el estado `RESOLVED` durante la ventana de 48 horas de garantía según Art. V.6).
* **EARS-08.3 (Ubicuo):** El motor de datos siempre deberá rechazar a nivel de restricción de columna cualquier intento de actualizar una incidencia al estado `RESOLVED` o declarar una pausa `PENDING_INFO` si el texto de justificación o diagnóstico cuenta con menos de 20 caracteres reales (Art. V.1).
* **EARS-08.4 (Dirigido por Evento):** CUANDO un ciudadano consulte el estado público de una máquina mediante su código QR, el sistema siempre deberá proyectar una vista anonimizada que muestre únicamente *"En proceso de atención técnica"*, sin revelar motivos de pausa, desencuentros con el edificio, datos de operarios ni notas internas de taller (Art. V.4).

---

### RF-09: Normalización Horaria Estricta en UTC y Conversión a Huso Operativo
El sistema deberá garantizar la coherencia absoluta en los cómputos temporales frente a transiciones horarias o servidores remotos.

* **EARS-09.1 (Ubicuo):** El motor de datos siempre deberá almacenar todas las marcas temporales (`created_at`, `paused_at`, `resolved_at`, etc.) en formato estandarizado con zona horaria UTC (`TIMESTAMPTZ`).
* **EARS-09.2 (Dirigido por Evento):** CUANDO el sistema deba evaluar el horario hábil comercial de una sede (08:00 a 18:00, lunes a viernes) o computar las 4 horas biológicas del reloj de frío, el sistema deberá normalizar la marca temporal UTC a la zona horaria oficial del parque de máquinas (`Europe/Madrid`), contemplando automáticamente las transiciones entre horario de invierno (CET, UTC+1) y horario de verano (CEST, UTC+2).

---

### RF-10: Salvaguarda del Parte Técnico ante Cuarentena Biológica Sobrevenida (Art. II)
El sistema deberá gestionar los casos en los que una máquina entre en cuarentena mientras el técnico trabaja sin cobertura in situ, priorizando la salud pública sin destruir su trabajo administrativo.

* **EARS-10.1 (No Deseado / Excepción):** SI un técnico intenta registrar la resolución ordinaria de una avería pero la máquina entró en cuarentena sanitaria biológica en la central mientras trabajaba sin cobertura, ENTONCES el sistema deberá suspender el cierre definitivo, almacenar en borrador seguro el diagnóstico y repuestos ya redactados, y notificar al técnico de forma clara que la máquina requiere el protocolo obligatorio de higienización y comprobación térmica (Art. II).
* **EARS-10.2 (Dirigido por Evento):** CUANDO el técnico complete y firme la declaración sanitaria obligatoria (confirmación de retirada y destrucción de producto descompuesto y verificación de temperatura), el sistema deberá consolidar el cierre definitivo y registrar el certificado correspondiente sin forzarle a redactar de nuevo los datos técnicos previos.

---

## 5. Requisitos No Funcionales (RNF)

* **RNF-01 (Latencia Transaccional):** Las operaciones habituales de lectura y escritura a través del agrupador de conexiones de la nube deberán resolverse con una mediana inferior a **250 ms**.
* **RNF-02 (Ajuste al Límite Gratuito de Almacenamiento):** El volumen total ocupado por los datos tabulares de las 20 tablas no deberá exceder de **100 MB** (20% del límite de 500 MB) para una flota de 10.000 expedientes completos.
* **RNF-03 (Consumo de Transferencia Saliente):** La transferencia mensual generada por las consultas de datos entre la nube y el servidor de aplicaciones no deberá superar **1 GB al mes** (50% del límite gratuito de 2 GB).
* **RNF-04 (Independencia de Red en Pruebas):** La totalidad de la batería de pruebas del proyecto (`php tests/run_all.php`) deberá poder ejecutarse al 100% en modo desconectado contra el motor local, certificando exactamente las mismas reglas de negocio sin requerir conexión a internet.
* **RNF-05 (Seguridad en Tránsito):** Toda conexión entre el servidor de aplicaciones y la base de datos en la nube deberá cifrarse obligatoriamente mediante protocolo seguro TLS/SSL con verificación de certificado.
* **RNF-06 (Ergonomía del Banner de Desconexión):** El banner flotante de desconexión deberá cumplir con las normas de contraste y accesibilidad vigentes del proyecto, y su botón de reintento deberá contar con una altura táctil mínima de 44 px para uso ergonómico en movilidad con una sola mano.

---

## 6. Casos Límite y Comportamientos Excepcionales

1. **Reactivación de la base de datos tras suspensión prolongada (*Cold Start* de 15 a 20 s):**  
   Si el latido hubiera fallado y la base de datos en la nube estuviera suspendida, la aplicación no aborta a los 5 segundos; entra en la ventana de gracia de 25 segundos mostrando el aviso explicativo y completa la petición con normalidad en cuanto el motor responde.
2. **Fallo de conectividad tras almacenar físicamente una fotografía:**  
   Si una imagen se guarda en el disco del servidor pero la inserción en la base de datos remota agota el timeout, la rutina de captura elimina el fichero del disco de forma atómica y notifica al usuario del fallo para reintentar la subida completa, impidiendo fugas de espacio.
3. **Reporte ciudadano concurrente durante la ventana de garantía de 48 horas (`RESOLVED`):**  
   Si una máquina tiene una avería en `RESOLVED` y un usuario escanea el QR reportando un nuevo fallo, el sistema no crea un ticket duplicado ni arroja un error técnico; asocia la información como reapertura justificada o aviso de garantía sobre el expediente preexistente.
4. **Saturación puntual del agrupador de conexiones (*Connection Pooler*):**  
   Ante una ráfaga simultánea de peticiones que supere temporalmente las conexiones activas del pool, el backend aplica hasta 2 reintentos con retraso progresivo (*backoff* y *jitter*) antes de emitir un error 503 controlado.
5. **Intento de borrado masivo por script accidental:**  
   Cualquier sentencia de eliminación física (`DELETE FROM machines`, `DELETE FROM incidents`, etc.) es neutralizada por las reglas anti-delete a nivel de motor de datos, devolviendo cero filas eliminadas y manteniendo la integridad histórica inalterable.

---

## 7. Fuera de Alcance (*Out of Scope*)

* **Trasvase de incidencias e historiales previos de producción:** Los expedientes cerrados, comentarios y pistas forenses de la base de datos anterior no se migran; quedan archivados en el sistema previo como registro histórico de consulta.
* **Migración de evidencias fotográficas a servicios de almacenamiento de objetos en la nube:** Las imágenes continúan residiendo en el servidor de aplicaciones para preservar la cuota de transferencia saliente gratuita de la base de datos.
* **Rediseño de pantallas de usuario:** Ningún modal, cabecera ni vista existente de técnico, sede o ciudadano sufre modificaciones estéticas. La interfaz se mantiene 100% idéntica, añadiéndose únicamente el banner global no intrusivo de desconexión y el endpoint técnico de salud.
* **Eliminación del motor relacional local:** Se mantiene de forma permanente la compatibilidad dual para garantizar el desarrollo, CI/CD y testeo desconectado sin internet.

---

## 8. Criterios de Aceptación y Finalización

La especificación se considerará formalmente satisfecha cuando se cumplan todos los siguientes criterios objetivos:

1. **Certificación de la Suite Global al 100% en Verde:** La totalidad de las 249 suites de prueba del proyecto (`php tests/run_all.php`) ejecutan y pasan sin un solo fallo tanto en el entorno local desconectado como contra la base de datos en la nube.
2. **Verificación de Blindaje Constitucional en Base de Datos:** Se demuestra mediante pruebas automatizadas que el motor de persistencia:
   * Bloquea sentencias `DELETE FROM` en todas las tablas maestras y operativas (Art. III.1).
   * Impide la creación de más de una avería activa simultánea por máquina física, abarcando `RESOLVED` (Art. V.2 y V.6).
   * Rechaza diagnósticos de resolución con menos de 20 caracteres reales (Art. V.1).
   * Devuelve respuestas anonimizadas ante consultas ciudadanas no autenticadas (Art. V.4).
3. **Verificación del Barrido Sanitario Autónomo:** El procedimiento periódico en la nube declara puntualmente la cuarentena a las 4 horas en perecederos basándose en la misma lógica canónica del servicio de dominio (Art. II y Art. I.3).
4. **Verificación del Latido Preventivo (*Keep-Alive*):** El flujo desatendido diario ejecuta la llamada al endpoint técnico `/api/health` certificando que la base de datos en la nube no entra en suspensión por inactividad tras 7 días.
5. **Verificación de Resiliencia ante Desconexión y Archivos Huérfanos:**
   * Se simula una caída de red y se comprueba que el banner accesible aparece, que los formularios retienen sus datos en memoria y que se recuperan en 1 clic.
   * Se simula un fallo de inserción de base de datos tras subida de imagen y se certifica que el archivo en disco es eliminado inmediatamente por la transacción compensatoria.
6. **Verificación de Tolerancia a *Cold Start*:** Una petición emitida con la base de datos suspendida espera de forma controlada hasta 25 segundos con aviso informativo y finaliza con éxito en cuanto el servicio despierta.

---

## 9. Dudas Abiertas

* *Ninguna duda abierta pendiente de resolución tras la ratificación formal de las 5 decisiones de desambiguación con el Product Owner (1-A, 2-A, 3-A, 4-A, 5-A).*
