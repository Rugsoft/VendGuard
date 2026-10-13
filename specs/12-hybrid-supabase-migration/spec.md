# ESPECIFICACIÓN FUNCIONAL: MIGRACIÓN DE PERSISTENCIA A BASE DE DATOS EN LA NUBE (ENFOQUE HÍBRIDO)

**Documento:** `specs/12-hybrid-supabase-migration/spec.md`  
**Referencia de Módulo:** Módulo 12 (`12-hybrid-supabase-migration`)  
**Estado:** Especificación Formal Ratificada y Blindada tras Auditoría Técnica Adversarial (`docs/auditoria_plan_migracion_supabase_hibrida.md`)  
**Fecha:** Octubre 2026  
**Proyecto:** VendGuard · Gestor de Incidencias de Vending  
**Normativa Suprema:** [`constitution.md`](../../constitution.md) (Artículos I al VII, especialmente Art. I.3, Art. II, Art. III.1, Art. V.1, Art. V.2, Art. V.6 y Art. VI.1)  

---

## 1. Contexto y Objetivo

### 1.1. Contexto Operativo
VendGuard gestiona el ciclo de vida completo de incidencias correctivas, órdenes de mantenimiento preventivo, rutas logísticas de técnicos, trazabilidad de piezas sustituidas, reembolsos económicos por importe retenido e hilos de comentarios bidireccionales. Hasta ahora, el sistema ha dependido de una base de datos relacional ejecutada en entornos locales o contenedores tradicionales con motores MySQL/MariaDB o TiDB.

Para dotar al sistema de persistencia administrada en la nube con coste operativo cero (aprovechando el plan gratuito de Supabase PostgreSQL), se define la migración de la capa de datos bajo una **estrategia híbrida** (Backend PHP 8.2+ Clean Architecture + Frontend Vanilla Vue 3 ESM). La auditoría adversarial de arquitectura ([`docs/auditoria_plan_migracion_supabase_hibrida.md`](../../docs/auditoria_plan_migracion_supabase_hibrida.md)) constató que el plan gratuito no incluye PITR (*Point-In-Time-Recovery*) ni copias continuas automatizadas descargables, por lo que la resiliencia del sistema se asienta en la política de exportación periódica (*logical dump*) y en el desacoplamiento total de dependencias propietarias.

### 1.2. Objetivo
Definir los requisitos funcionales, operacionales y de blindaje normativo para **migrar la persistencia de datos a PostgreSQL administrado en la nube (Supabase)**, asegurando:
1. **Transparencia funcional absoluta para el usuario:** Cero alteraciones en las vistas de técnicos, sedes y ciudadanos, conservando intactos todos los flujos de trabajo de los 11 módulos previos.
2. **Compatibilidad dual transparente (Agnosticismo de persistencia):** El sistema debe operar indistintamente contra PostgreSQL en la nube (vía agrupador transaccional Supavisor en puerto 6543) o contra MariaDB/MySQL local (entornos de desarrollo y ejecución de pruebas offline sin conexión a internet).
3. **Arranque en limpio (*Fresh Start*) y preservación de fixtures:** Inicialización formal del catálogo maestro (sedes, máquinas, usuarios autorizados, catálogo de repuestos y rutas) en producción sin arrastre de expedientes antiguos, garantizando al mismo tiempo que el arnés de pruebas disponga de los fixtures necesarios para certificar la suite al 100% en verde.
4. **Prevención proactiva de suspensión (*Keep-Alive*) y restauración de servicio:** Un latido automático diario (*keep-alive*) evita que el servicio en la nube se suspenda por inactividad tras 7 días. Si el proyecto entrase en suspensión, el flujo incorpora un mecanismo de auto-restauración mediante la API de gestión de Supabase (`POST /v1/projects/{ref}/restore`), descartando la ficción de que una consulta de base de datos despierte un proyecto pausado.
5. **Transaccionalidad compensatoria de archivos:** Las evidencias fotográficas se mantienen en el almacenamiento del servidor de aplicaciones, implementando la reversión y purgado atómico de archivos huérfanos si la inserción en la base de datos remota se interrumpe por fallos de red.
6. **Blindaje constitucional integral y ruidoso en el motor de persistencia:** Protección anti-borrado físico (`DELETE FROM`) mediante disparadores `BEFORE DELETE` que emiten activamente excepción constitucional `SQLSTATE '45000'` (Art. III.1), con vía de escape transaccional segura (`vendguard.purge = on`) para el arnés de limpieza de pruebas ([`TestDataCleaner.php`](../../tests/Support/TestDataCleaner.php)); detección preventiva obligatoria de duplicados complementada con índice atómico parcial que incluye `RESOLVED` durante la ventana de 48 horas de garantía (Art. V.2 y V.6); justificación obligatoria $\ge 20$ caracteres (Art. V.1); anonimización ciudadana (Art. V.4); cotejamiento insensible a mayúsculas mediante `CITEXT` o índices funcionales para preservar la unicidad de logins y códigos; y autoridad canónica única del servicio de dominio PHP para la cuarentena biológica a las 4 horas (Art. II y Art. I.3).

---

## 2. Usuarios y Perfiles Implicados

| Perfil / Actor | Impacto Visual de la Migración | Responsabilidades Operativas | Comportamiento ante Fallo de Enlace |
| :--- | :--- | :--- | :--- |
| **📱 Técnico de Ruta** | **Cero cambios visuales.** Mismas pantallas de paradas, checklists, repuestos y pausas. | Ejecuta intervenciones in situ, declara piezas y pausas como siempre. | Si pierde cobertura o vence el timeout ordinario (5,0s), el borrador del formulario se retiene íntegro en memoria. Si la máquina entró en cuarentena sobrevenida, el sistema conserva su informe y le exige checklist térmico antes de cerrar. |
| **🏢 Responsable de Sede** | **Cero cambios visuales.** Mismo portal de sedes con estado de parque y conversación. | Consulta avisos, valida llaves de acceso y aporta respuestas aclaratorias. | La aplicación no muestra volcados de error técnico; presenta un aviso flotante temporal indicando que el servicio está reconectando con botón táctil de reintento. |
| **📊 Coordinador de Operaciones** | **Cero cambios en operativa diaria + Diagnóstico.** Mismo cuadro de mandos, triaje y mapa. | Supervisión de flota, asignación de averías, gestión de SLAs y reintegros. | Cuenta con endpoint de diagnóstico de salud (`/api/health`) con telemetría técnica detallada accesible únicamente con credenciales autorizadas. |
| **👥 Ciudadano / Usuario QR** | **Cero cambios visuales.** Mismo reporte público y seguimiento anónimo. | Escanea el código QR de la máquina, reporta anomalías o solicita reintegros. | En caso de desconexión puntual, visualiza un mensaje neutral y amable invitando a reintentar la acción en unos instantes. |
| **🛠️ Administrador de Sistemas / QA** | **Herramientas de entorno.** Configuración y diagnóstico del enlace. | Inicialización de datos maestros, verificación de salud y ejecución de pruebas. | Puede conmutar la aplicación para ejecutar pruebas 100% en local sin internet o validar contra la nube con paridad de resultados. |

---

## 3. Historias de Usuario

### HU-01: Continuidad Técnica Absoluta en Campo
> **Como** Técnico de Campo en ruta,  
> **quiero** gestionar mis órdenes de trabajo, registrar sustitución de piezas y tramitar pausas por falta de acceso con la misma interfaz e inmediatez que hasta ahora,  
> **para** cumplir mis paradas diarias sin sufrir fricciones, cambios de diseño ni retrasos provocados por la infraestructura del sistema.

### HU-02: Garantía Inviolable de Seguridad Alimentaria sin Duplicidad de Reglas (Art. II y Art. I.3)
> **Como** Responsable Sanitario y Coordinador de Operaciones,  
> **quiero** que la detección de rotura de cadena de frío a las 4 horas naturales en máquinas de perecederos se ejecute exclusivamente a través del servicio canónico de dominio en PHP tanto en la nube como en local,  
> **para** evitar divergencias algorítmicas entre procedimientos SQL no certificados y el código de aplicación que protege la salud pública.

### HU-03: Desarrollo y Certificación de Pruebas sin Conexión a Internet
> **Como** Ingeniero de Calidad (QA) o Desarrollador,  
> **quiero** ejecutar la batería completa de pruebas automatizadas en mi equipo local desconectado y obtener exactamente los mismos resultados que contra el entorno en la nube,  
> **para** certificar que no existen regresiones de código de forma ágil, autónoma y sin depender de la disponibilidad de un servicio externo.

### HU-04: Resiliencia ante Desconexión y Protección de Borradores Técnicos
> **Como** Técnico de Campo que trabaja en sótanos o zonas con cobertura intermitente,  
> **quiero** que si el enlace con la base de datos remota se interrumpe o vence el timeout de 5 segundos durante el guardado de mi intervención, el sistema conserve intacto el texto de diagnóstico y repuestos redactados y me permita reintentar el envío al recuperar señal,  
> **para** no perder mi tiempo de trabajo administrativo ni verme obligado a redactar de nuevo los detalles de la reparación.

### HU-05: Prevención de Suspensión y Restauración Desatendida (Art. VI.1)
> **Como** Administrador del Sistema,  
> **quiero** que un latido automático diario impida la suspensión de la base de datos en la nube y, en caso de suspensión excepcional, invoque la restauración automática,  
> **para** asegurar que el sistema esté operativo 24/7 sin haber tenido que desarrollar paneles complejos que desvíen el foco del MVP.

---

## 4. Requisitos Funcionales (RF) con Criterios de Aceptación (Notación EARS)

### RF-01: Persistencia Dual Transparente y Agnosticismo de Motor
El sistema deberá ser plenamente agnóstico respecto a la plataforma relacional, operando de forma indistinta contra PostgreSQL en la nube o contra MariaDB/MySQL local.

* **EARS-01.1 (Ubicuo):** El sistema siempre deberá garantizar la integridad referencial, el aislamiento transaccional y la exactitud de los cálculos temporales de SLA y MTTR con independencia del motor relacional activo, utilizando sintaxis ANSI estándar en todos los repositorios PDO.
* **EARS-01.2 (Dirigido por Estado):** MIENTRAS el sistema esté configurado para operar contra PostgreSQL en Supabase, el sistema deberá canalizar las operaciones a través del agrupador transaccional Supavisor (puerto 6543) con emulación de sentencias preparadas activada (`PDO::ATTR_EMULATE_PREPARES => true`) para evitar errores de sentencias preparadas en modo transacción.
* **EARS-01.3 (Dirigido por Estado):** MIENTRAS el sistema esté configurado para operar en el entorno local desconectado, el sistema deberá ejecutar todas las consultas y pruebas sin emitir ninguna llamada de red hacia el exterior.
* **EARS-01.4 (Dirigido por Evento):** CUANDO se modifique la variable de configuración `DATABASE_URL` del entorno, el sistema deberá autodeterminar el driver (`pdo_pgsql` vs `pdo_mysql`) y conmutar su operativa sin requerir cambios en la lógica de dominio ni en el frontend.

---

### RF-02: Inicialización en Limpio (*Fresh Start*) y Paridad de Fixtures de Prueba
El sistema deberá proveer mecanismos diferenciados de inicialización: carga en limpio de catálogo maestro para producción y sembrado de fixtures para la certificación de pruebas.

* **EARS-02.1 (Dirigido por Evento):** CUANDO se inicialice el entorno de producción en una base de datos vacía, el sistema deberá registrar el catálogo maestro completo (sedes, máquinas, usuarios autorizados, catálogo de repuestos y configuraciones de ruta) mediante cláusulas estándar `ON CONFLICT DO UPDATE` sin incidencias activas ni historiales previos.
* **EARS-02.2 (Dirigido por Evento):** CUANDO se inicialice el entorno de ejecución de pruebas automatizadas (tanto en local como en la nube), el sistema deberá sembrar el arnés de datos y fixtures históricos necesarios para que la suite de pruebas completa certifique el ciclo de vida sin discrepancias.
* **EARS-02.3 (Ubicuo):** El sistema siempre deberá inicializar las credenciales de usuarios maestros con contraseñas seguras por defecto validadas mediante algoritmo de hash criptográfico estándar (`password_hash`), permitiendo su gestión posterior mediante el módulo de administración existente.

---

### RF-03: Prevención Activa de Suspensión por Inactividad (*Keep-Alive*) y Auto-Restauración
El sistema deberá evitar de forma proactiva que la base de datos en la nube entre en suspensión tras 7 días sin tráfico, proveyendo auto-restauración en caso de contingencia.

* **EARS-03.1 (Ubicuo):** El sistema siempre deberá contar con un mecanismo de latido periódico desatendido orquestado externamente (mediante flujo automatizado diario en GitHub Actions a las 06:00 UTC) que consulte el endpoint técnico ligero de salud (`GET /api/health`).
* **EARS-03.2 (Dirigido por Evento):** CUANDO el mecanismo de latido se ejecute, el sistema deberá realizar una consulta ligera (`SELECT 1`) sobre la base de datos para registrar actividad real en el motor de persistencia ante el proveedor de la nube.
* **EARS-03.3 (No Deseado / Excepción):** SI el mecanismo de latido detecta que el servicio en la nube devuelve código HTTP 540 o error de proyecto suspendido, ENTONCES el flujo automatizado deberá invocar la API de restauración de Supabase (`POST /v1/projects/{ref}/restore`) utilizando un token de gestión autenticado y emitir una notificación en el log de ejecución.

---

### RF-04: Degradación Visual Controlada, Resiliencia de Red y Protección de Borradores
La interfaz de usuario deberá responder con resiliencia ante cortes de red o saturación temporal, protegiendo los datos introducidos por los operarios.

* **EARS-04.1 (Dirigido por Estado):** MIENTRAS se curse cualquier petición HTTP contra el backend, el sistema aplicará un tiempo de espera (*timeout*) ordinario estricto de **5,0 segundos** tanto en el cliente (`AbortSignal.timeout(5000)`) como en el tiempo de conexión del driver PDO (`ATTR_TIMEOUT => 5`), garantizando coherencia temporal extremo a extremo.
* **EARS-04.2 (Dirigido por Evento):** CUANDO se pulse el botón de envío de un formulario, el sistema deberá deshabilitarlo temporalmente y mostrar un indicador de carga para impedir peticiones duplicadas accidentales.
* **EARS-04.3 (No Deseado / Excepción):** SI una petición agota el tiempo de espera de 5,0 segundos o sufre una interrupción física de conectividad, ENTONCES el sistema deberá mostrar un banner flotante global no intrusivo (`ConnectionStateBanner.js` en estado `disconnected`) preservando íntegramente los datos y textos redactados en el formulario en memoria reactiva.
* **EARS-04.4 (Dirigido por Evento):** CUANDO se presente el banner de desconexión, el sistema deberá ofrecer un botón táctil accesible ($\ge 44\text{ px}$) para reintentar el envío de forma inmediata sin recargar la página ni perder el borrador.

---

### RF-05: Acotamiento de Datos Relacionales y Transaccionalidad Compensatoria de Archivos
La migración de persistencia se circunscribe a los datos tabulares, manteniendo las evidencias multimedia en el servidor de aplicaciones y evitando fugas de archivos huérfanos.

* **EARS-05.1 (Ubicuo):** El sistema siempre deberá almacenar en la base de datos en la nube únicamente datos estructurados, cadenas de texto, cifras y marcas temporales, quedando expresamente prohibido el almacenamiento de imágenes binarias en columnas de la base de datos.
* **EARS-05.2 (Dirigido por Evento):** CUANDO un usuario adjunte una fotografía o evidencia gráfica, el sistema deberá guardar físicamente el archivo en el almacenamiento local del servidor de aplicaciones e insertar la ruta relativa en la base de datos.
* **EARS-05.3 (No Deseado / Excepción):** SI tras guardar físicamente el archivo en el servidor, la inserción del registro en la base de datos remota falla por error de red o timeout, ENTONCES el servicio compensatorio ([`FileStorageCompensator.php`](../../src/Infrastructure/Storage/FileStorageCompensator.php)) deberá eliminar inmediatamente el archivo recién creado en disco con `unlink()`, garantizando que no queden archivos huérfanos.
* **EARS-05.4 (Ubicuo):** El sistema siempre deberá servir las imágenes a través del servidor web propio, impidiendo que la entrega de fotografías consuma la cuota de transferencia saliente (*egress*) de la base de datos en la nube.

---

### RF-06: Automatización del Barrido Sanitario con Fuente Canónica Única (Art. II y Art. I.3)
La inspección biológica y la activación automática de cuarentena sanitaria a las 4 horas en máquinas de perecederos deberán regirse por una única lógica canónica idéntica en todos los entornos, prohibiendo terminantemente la duplicación de reglas en procedimientos almacenados SQL.

* **EARS-06.1 (Ubicuo):** El sistema siempre deberá concentrar el 100% de la lógica y reglas de cálculo del barrido sanitario en el servicio de aplicación [`SanitaryClockSweepService.php`](../../src/Application/Service/SanitaryClockSweepService.php) y políticas de escalado como única fuente de verdad (Art. I.3), prohibiendo divergencias de cálculo entre procedimientos almacenados de base de datos y código de aplicación.
* **EARS-06.2 (Dirigido por Estado):** MIENTRAS la aplicación opere en el entorno de nube, un programador periódico (`pg_cron` en conjunto con extensión HTTP `pg_net` o ejecutor cron del servidor) invocará cada 15 minutos el endpoint interno autenticado `POST /api/cron/sanitary-clock-sweep`, ejecutando la lógica canónica de [`SanitaryClockSweepService.php`](../../src/Application/Service/SanitaryClockSweepService.php).
* **EARS-06.3 (Dirigido por Evento):** CUANDO el barrido detecte una máquina de perecederos que alcance las 4 horas naturales continuas (14.400 segundos) sin resolución, el sistema deberá declarar de inmediato el estado `QUARANTINE` en la máquina, registrar la fecha de inicio de cuarentena y asentar un evento inmutable en el historial y en las tablas de auditoría de barrido.
* **EARS-06.4 (Dirigido por Estado):** MIENTRAS la aplicación opere en el entorno local desconectado, el sistema deberá mantener la invocación periódica de este mismo servicio mediante el comando binario CLI habitual (`bin/sanitary_clock_sweep.php`), garantizando exactamente el mismo resultado y la misma auditoría forense.

---

### RF-07: Comprobación Técnica Ligera de Salud del Enlace (*Health Check*, Art. VI.1 y Art. V.4)
El sistema deberá proveer un mecanismo ligero de verificación de conectividad y latencia sin exponer topología interna a actores no autorizados.

* **EARS-07.1 (Dirigido por Evento):** CUANDO un monitor externo o cliente emita una petición `GET /api/health`, el sistema deberá verificar la conectividad activa contra el motor de persistencia mediante una consulta ligera (`SELECT 1`) y responder en menos de 1,0 segundo.
* **EARS-07.2 (Ubicuo):** Ante accesos públicos no autenticados, la respuesta de salud únicamente informará de campos mínimos (`status: "HEALTHY" | "DEGRADED"`, `timestamp_utc`), prohibiendo la exposición pública de nombres de driver, agrupadores, nombres de host o latencias exactas para cumplir con la no fuga de información técnica (Art. V.4).
* **EARS-07.3 (Dirigido por Estado):** MIENTRAS la petición cuente con cabecera de autenticación interna autorizada (`X-Internal-Token` o sesión de Coordinador), el sistema incluirá en la respuesta la telemetría detallada (`database_driver`, `connection_pooler`, `response_time_ms`).
* **EARS-07.4 (No Deseado / Excepción):** SI la comprobación no puede conectar con la base de datos activa, ENTONCES el sistema deberá devolver un código HTTP 503 con indicación de servicio no disponible, absteniéndose de exponer credenciales, contraseñas, URLs internas o volcados de excepciones técnicas.

---

### RF-08: Blindaje Constitucional Integral y Ruidoso en el Motor de Persistencia
El motor de datos deberá aplicar de forma activa e inviolable las reglas de la Constitución de VendGuard.

* **EARS-08.1 (Ubicuo):** El motor de datos siempre deberá impedir mediante disparadores `BEFORE DELETE` cualquier sentencia destructiva (`DELETE FROM`) sobre **todas** las entidades maestras y operativas (máquinas, sedes, usuarios, incidencias, repuestos, reintegros, historial y auditoría), emitiendo de forma ruidosa una excepción con `SQLSTATE '45000'` y mensaje constitucional (Art. III.1). El disparador respetará la variable de configuración transaccional `vendguard.purge = on` para permitir la purga legítima y aislada del arnés de pruebas ([`TestDataCleaner.php`](../../tests/Support/TestDataCleaner.php)).
* **EARS-08.2 (Ubicuo):** El sistema siempre deberá comprobar preventivamente en la capa de aplicación y advertir al usuario si una máquina ya cuenta con una incidencia abierta (Art. V.2); como red de seguridad final ante concurrencia, el motor de datos rechazará atómicamente la inserción si ya existe un ticket donde el estado sea distinto de `CLOSED` y `CANCELLED` mediante índice único parcial `uq_machine_active_ticket` (abarcando el estado `RESOLVED` durante la ventana de 48 horas de garantía según Art. V.6).
* **EARS-08.3 (Ubicuo):** El motor de datos siempre deberá rechazar a nivel de restricción `CHECK` cualquier intento de actualizar una incidencia al estado `RESOLVED` o declarar una pausa `PENDING_INFO` si el texto de justificación o diagnóstico cuenta con menos de 20 caracteres reales (Art. V.1).
* **EARS-08.4 (Dirigido por Evento):** CUANDO un ciudadano consulte el estado público de una máquina mediante su código QR, el sistema siempre proyectará una vista neutral y anonimizada que muestre únicamente *"En proceso de atención técnica"*, sin revelar motivos de pausa, identidades de operarios ni notas de taller (Art. V.4).
* **EARS-08.5 (Ubicuo):** El motor de datos siempre deberá garantizar la insensibilidad a mayúsculas y acentos en campos clave de unicidad (`email` en `users`, `site_code` en `locations`) mediante la extensión `CITEXT` o índices funcionales `UNIQUE (LOWER(...))`, replicando la semántica de `utf8mb4_unicode_ci` y evitando colisiones de identidad en login.

---

### RF-09: Normalización Horaria Estricta en UTC y Presupuestos de Espera
El sistema deberá garantizar la coherencia absoluta en los cómputos temporales y tiempos de bloqueo transaccionales.

* **EARS-09.1 (Ubicuo):** El motor de datos siempre deberá almacenar todas las marcas temporales (`created_at`, `paused_at`, `resolved_at`, etc.) en formato estandarizado con zona horaria UTC (`TIMESTAMPTZ`).
* **EARS-09.2 (Dirigido por Evento):** CUANDO el sistema deba evaluar el horario hábil comercial de una sede o computar las 4 horas biológicas del reloj de frío, el sistema normalizará la marca temporal UTC a la zona horaria oficial del parque de máquinas (`Europe/Madrid`), contemplando automáticamente las transiciones entre horario de invierno (CET, UTC+1) y horario de verano (CEST, UTC+2).
* **EARS-09.3 (Ubicuo):** El adaptador de espera de bloqueo ([`PdoLockWaitBudget.php`](../../src/Infrastructure/Database/PdoLockWaitBudget.php)) detectará de forma transparente el driver en uso: en MariaDB utilizará `innodb_lock_wait_timeout`, y en PostgreSQL utilizará `SET LOCAL lock_timeout` dentro de la transacción, garantizando la compatibilidad con el agrupador transaccional.

---

### RF-10: Salvaguarda del Parte Técnico ante Cuarentena Biológica Sobrevenida (Art. II)
El sistema deberá gestionar los casos en los que una máquina entre en cuarentena mientras el técnico trabaja sin cobertura in situ, priorizando la salud pública sin destruir su trabajo administrativo.

* **EARS-10.1 (No Deseado / Excepción):** SI un técnico intenta registrar la resolución ordinaria de una avería pero la máquina entró en cuarentena sanitaria biológica en la central mientras trabajaba sin cobertura, ENTONCES el sistema deberá suspender el cierre definitivo, almacenar en borrador seguro el diagnóstico y repuestos ya redactados, y notificar al técnico mediante error HTTP 422 (`SANITARY_QUARANTINE_OVERTAKEN`) que la máquina requiere el protocolo obligatorio de higienización y comprobación térmica (Art. II).
* **EARS-10.2 (Dirigido por Evento):** CUANDO el técnico complete y firme la declaración sanitaria obligatoria, el sistema deberá consolidar el cierre definitivo y registrar el certificado correspondiente sin forzarle a redactar de nuevo los datos técnicos previos.

---

## 5. Requisitos No Funcionales (RNF)

* **RNF-01 (Latencia Transaccional):** Las operaciones habituales de lectura y escritura a través del agrupador de conexiones Supavisor deberán resolverse con una mediana inferior a **250 ms**.
* **RNF-02 (Ajuste al Límite Gratuito de Almacenamiento):** El volumen total ocupado por los datos tabulares de las 20 tablas no deberá exceder de **100 MB** (20% del límite de 500 MB de Supabase Free) para una flota de 10.000 expedientes completos.
* **RNF-03 (Consumo de Transferencia Saliente):** La transferencia mensual generada por las consultas de datos entre la nube y el servidor de aplicaciones no deberá superar **1 GB al mes** (20% del presupuesto de 5 GB total compartido de la organización en Supabase Free).
* **RNF-04 (Independencia de Red en Pruebas):** La totalidad de la batería de pruebas del proyecto (`php tests/run_all.php`) deberá poder ejecutarse al 100% en modo desconectado contra el motor local, certificando exactamente las mismas reglas de negocio sin requerir conexión a internet.
* **RNF-05 (Seguridad en Tránsito):** Toda conexión entre el servidor de aplicaciones y la base de datos en la nube deberá cifrarse obligatoriamente mediante protocolo seguro TLS/SSL con verificación de certificado (`sslmode=require`).
* **RNF-06 (Ergonomía del Banner de Desconexión):** El banner flotante de desconexión deberá cumplir con las normas de contraste y accesibilidad vigentes del proyecto, y su botón de reintento deberá contar con una altura táctil mínima de 44 px para uso ergonómico en movilidad.

---

## 6. Casos Límite y Comportamientos Excepcionales

1. **Suspensión por inactividad tras 7 días en Supabase Free:**  
   Si el proyecto de Supabase se suspende por falta de actividad, las consultas de base de datos fallan (HTTP 540 o error de conexión). El flujo automatizado en GitHub Actions detecta el estado y ejecuta la restauración mediante la API de gestión (`POST /v1/projects/{ref}/restore`). El cliente web no intenta despertar el motor con reintentos mágicos; muestra el banner de desconexión con botón de reintento.
2. **Fallo de conectividad tras almacenar físicamente una fotografía:**  
   Si una imagen se guarda en el disco del servidor pero la inserción en la base de datos remota agota el timeout de 5 segundos, la rutina de captura elimina el fichero del disco de forma atómica ([`FileStorageCompensator.php`](../../src/Infrastructure/Storage/FileStorageCompensator.php)) y notifica al usuario del fallo para reintentar la subida completa, impidiendo fugas de espacio.
3. **Reporte ciudadano concurrente durante la ventana de garantía de 48 horas (`RESOLVED`):**  
   Si una máquina tiene una avería en `RESOLVED` y un usuario escanea el QR reportando un nuevo fallo, el índice único parcial `uq_machine_active_ticket` impide duplicados a nivel de motor, mientras la aplicación asocia la información como reapertura justificada o aviso de garantía sobre el expediente preexistente.
4. **Saturación puntual del agrupador de conexiones (*Supavisor*):**  
   Ante una ráfaga simultánea de peticiones que supere temporalmente las conexiones del pool, el backend aplica hasta 2 reintentos con retraso progresivo (*backoff* y *jitter*) antes de emitir un error 503 controlado.
5. **Intento de borrado masivo por script accidental:**  
   Cualquier sentencia de eliminación física (`DELETE FROM machines`, `DELETE FROM incidents`, etc.) es rechazada en voz alta por los disparadores `BEFORE DELETE` con `SQLSTATE '45000'`, manteniendo la integridad histórica inalterable (Art. III.1) salvo que se declare explícitamente `SET LOCAL vendguard.purge = 'on'` dentro de una transacción del arnés de pruebas.

---

## 7. Fuera de Alcance (*Out of Scope*)

* **Trasvase de incidencias e historiales previos de producción:** Los expedientes cerrados, comentarios y pistas forenses de la base de datos anterior no se migran; quedan archivados en el sistema previo como registro histórico de consulta (*Fresh Start*).
* **Migración de evidencias fotográficas a servicios de almacenamiento de objetos en la nube:** Las imágenes continúan residiendo en el servidor de aplicaciones para preservar la cuota de transferencia saliente de la base de datos.
* **Rediseño de pantallas de usuario:** Ningún modal, cabecera ni vista existente de técnico, sede o ciudadano sufre modificaciones estéticas. La interfaz se mantiene 100% idéntica, añadiéndose únicamente el banner global no intrusivo de desconexión y el endpoint técnico de salud.
* **Eliminación del motor relacional local:** Se mantiene de forma permanente la compatibilidad dual para garantizar el desarrollo, CI/CD y testeo desconectado sin internet.

---

## 8. Criterios de Aceptación y Finalización

La especificación se considerará formalmente satisfecha cuando se cumplan todos los siguientes criterios objetivos:

1. **Certificación de la Suite Global al 100% en Verde:** La totalidad de las suites de prueba del proyecto (`php tests/run_all.php`), con las cifras documentadas debidamente actualizadas en [`README.md`](../../README.md) según exige [`DocMetricsGuard.php`](../../tests/Support/DocMetricsGuard.php), ejecutan y pasan sin un solo fallo en local y en la nube.
2. **Verificación de Blindaje Constitucional en Base de Datos:** Se demuestra mediante pruebas automatizadas que el motor de persistencia:
   * Rechaza con excepción ruidosa `SQLSTATE '45000'` sentencias `DELETE FROM` en todas las tablas maestras y operativas (Art. III.1), respetando la vía de escape de `TestDataCleaner`.
   * Impide la creación de más de una avería activa simultánea por máquina física, abarcando `RESOLVED` (Art. V.2 y V.6).
   * Rechaza diagnósticos de resolución con menos de 20 caracteres reales (Art. V.1).
   * Devuelve respuestas anonimizadas ante consultas ciudadanas no autenticadas (Art. V.4).
   * Preserva la unicidad insensible a mayúsculas en emails y códigos de sede mediante `CITEXT` o `LOWER()`.
3. **Verificación del Barrido Sanitario Autónomo:** La invocación periódica en la nube ejecuta la misma lógica canónica del servicio de dominio [`SanitaryClockSweepService.php`](../../src/Application/Service/SanitaryClockSweepService.php) (Art. II y Art. I.3).
4. **Verificación del Latido Preventivo (*Keep-Alive*) y Auto-Restauración:** El flujo desatendido diario ejecuta la llamada al endpoint técnico `/api/health` para evitar la suspensión por 7 días, y cuenta con capacidad de restauración por API ante suspensión.
5. **Verificación de Resiliencia ante Desconexión y Archivos Huérfanos:**
   * Se simula una caída de red o timeout de 5 segundos y se comprueba que el banner accesible aparece, que los formularios retienen sus datos en memoria y que se recuperan en 1 clic.
   * Se simula un fallo de inserción de base de datos tras subida de imagen y se certifica que el archivo en disco es eliminado inmediatamente por la transacción compensatoria.
6. **Verificación de Despliegue en Contenedor:** La imagen en [`Dockerfile`](../../Dockerfile) instala `pdo_pgsql` junto a `pdo_mysql`, y el script de arranque [`bin/init_cloud_db.php`](../../bin/init_cloud_db.php) detecta el driver activo y ejecuta el DDL adecuado de forma tolerante.

---

## 9. Dudas Abiertas

* *Ninguna duda abierta tras la integración formal de las 11 correcciones de la auditoría técnica adversarial.*
