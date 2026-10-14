# VendGuard · Gestor de Incidencias de Vending

[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B%20Vanilla%20POO-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net/)
[![Frontend](https://img.shields.io/badge/Vue.js%203-ES%20Modules%20(No%20Bundler)-4FC08D?style=flat-square&logo=vue.js&logoColor=white)](https://vuejs.org/)
[![Database](https://img.shields.io/badge/MariaDB-10.11%2B%20%7C%20MySQL%208.0-003545?style=flat-square&logo=mariadb&logoColor=white)](https://mariadb.org/)
[![Design System](https://img.shields.io/badge/Design%20System-Docker%20Tokens%20(%232560ff)-2496ED?style=flat-square&logo=docker&logoColor=white)](docs/design.md)
[![Tests Status](https://img.shields.io/badge/Tests-257%20Suites%20%7C%209.816%20Pass%20(100%25)-38bd7d?style=flat-square)](tests/)
[![Constitutional Status](https://img.shields.io/badge/Constitution-Audited%20%26%20Certified-003db5?style=flat-square)](constitution.md)

**VendGuard** es una plataforma web integral de nivel industrial para la gestión, triaje, intervención técnica, métricas de SLA y auditoría inmutable de averías en parques de máquinas de vending (bebidas calientes, frías, snacks y comida perecedera).

El sistema erradica de raíz los problemas críticos del sector vending mediante seis pilares:
1. **Preservación de la cadena de frío (Art. II Constitución):** Detección inmediata y forzado de prioridad máxima (**CRÍTICA / Innegociable**) en máquinas con alimentos perecederos con objetivo estricto de SLA < 4.0 horas.
2. **Prevención atómica de duplicados:** Índice atómico en base de datos (`uq_machine_active_ticket`) que imposibilita la creación de tickets concurrentes para una misma máquina.
3. **Justificación obligatoria de intervenciones (Art. V.1):** Prohibición terminante de resolver incidencias sin registrar diagnóstico técnico real ($\ge 20$ caracteres) y acción correctiva demostrable ($\ge 20$ caracteres).
4. **Trazabilidad y auditoría permanente (Art. III):** Borrado físico estrictamente prohibido (*Soft Delete* obligatorio) y registro inmutable *Append-Only* (`audit_log`) con ventana de garantía de 48 horas y detección de averías crónicas.
5. **Trazabilidad económica de repuestos (Módulo M2):** Catálogo maestro por modelo de máquina con *snapshot* inmutable de coste en cada intervención, clasificación cerrada de destino (`DESGUACE`/`TALLER`) y segregación estricta de datos de piezas y costes para el Responsable de Sede (Art. V.4).
6. **Cartografía territorial y optimización de rutas (Módulo M4):** Proyección pura Web Mercator y secuenciación heurística en 4 fases sin librerías de terceros ni claves de API privadas (Dogma Vanilla), con visión unificada para Coordinación y navegación GPS móvil asistida para Técnicos de Campo, garantizando estricta segregación de datos frente a clientes (Art. V.4).

---

## 🏛️ Filosofía de Ingeniería: Dogma Vanilla & Dualismo Lingüístico

El desarrollo se rige incondicionalmente por la [Constitución del Proyecto](constitution.md) y [AGENTS.md](AGENTS.md):

* **Dogma Vanilla (Cero Bloatware):**
  * **Backend:** PHP 8.2+ moderno puro orientado a objetos (*Clean Architecture*), tipado estricto en el 100% de ficheros (`declare(strict_types=1);`), persistencia nativa con **PDO** y generación algorítmica de códigos QR en SVG sin extensiones externas (`imagick` no requerida). Cero dependencias externas (`composer.json` / `vendor/` ausentes en runtime).
  * **Frontend:** Vue.js 3 estándar modularizado mediante **ES Modules** nativos (`type="module"`), consumiendo directamente la API REST con `fetch()`. Cero empaquetadores o dependencias pesadas (`node_modules/` ausente en runtime).
* **Dualismo Lingüístico Estricto:**
  * **Inglés:** Clases, métodos, variables, contratos de API, base de datos, suites de pruebas y commits (*Conventional Commits*).
  * **Castellano:** Documentación de negocio, análisis funcional, mensajes de validación y diálogo de cara al usuario final.

---

## 🧩 Módulos del Sistema

### 1. Núcleo Operativo de Incidencias (MVP)
* **Portal de Responsable de Sede:** Catálogo visual de máquinas del centro, reporte guiado de averías con subida de imágenes, bitácora de evidencias y reapertura justificada dentro de garantía de 48h.
* **Panel de Triaje y Coordinación 24/7:** Bandeja global en tiempo real con banner de advertencia visual para averías críticas sin asignar (> 60 min), asignación técnica con justificación de urgencia y descarte lógico (*Soft Delete*).
* **Modal de Detalle Integral en Triaje:** Ficha completa de solo lectura de cada avería (máquina y sede, bitácora de evidencias, piezas, SLA y cronología de auditoría con atribución) conforme a la especificación [`specs/09-incident-detail-modal/`](specs/09-incident-detail-modal/spec.md).
* **Vista Móvil "Mi Ruta" para Técnicos:** Interfaz vertical optimizada para smartphone (uso con una sola mano), inicio de intervención in situ, pausa estructurada por repuestos, resolución técnica justificada con declaración de piezas sustituidas y consulta de métricas individuales.
* **Cron Automatizado de Garantía:** Cierre definitivo de tickets resueltos transcurridas 48h sin reclamaciones (`/api/cron/auto-close`).
* **Barrido Automático de Cuarentena Sanitaria:** Consumo de fondo del Reloj Sanitario Biológico cada 15 min (`/api/cron/sanitary-clock-sweep` y `bin/sanitary_clock_sweep.php`): activa la cuarentena de las máquinas de frío con más de 4 h naturales sin frío confirmado **sin** esperar a un intento de cierre (RF-03.5.1, Art. II). La contención de fila frente al cierre técnico se publica como **aplazamiento** (`contended`), no como fallo: informa, no alerta, y se reintenta en la pasada siguiente. La pasada corre además con una **cota de espera de bloqueo de 2 s** —medida contra la transacción real del cierre (~787× su p95)— de modo que un tenedor que se alarga aparece como aplazamiento visible en lugar de dejar el lote parado.

### 2. Ecosistema de Códigos QR Físicos
* **Generador Nativo de QR en SVG:** Renderizado vectorial puro conforme a la especificación ISO/IEC 18004 con corrección de errores Reed-Solomon (Nivel M/Q).
* **Diseño e Impresión de Etiquetas Industriales:** Modales de previsualización e impresión de etiquetas resistentes para máquinas (formatos compacto, estándar y grande), con indicación de zona de máquina, aviso sanitario de perecederos y teléfono de asistencia.
* **Impresión Masiva por Sede (*Batch Print*):** Generación en lote de todas las etiquetas de un centro para su pegado en ruta.
* **Escaneo y Reporte Ciudadano:** Escaneo directo con cámara móvil (`?qr=...` o `?code=...`) que precarga la máquina y permite reportar incidencias en < 30 segundos sin necesidad de login previo.

### 3. Métricas de Rendimiento, SLAs y Auditoría Inmutable (Módulo 03)
* **Cuadro de Mando de KPIs Globales:**
  * **MTTR Promedio Global** (tiempo medio de resolución 24/7 en minutos y horas) con cálculo de variación de tendencia porcentual frente al periodo equivalente anterior.
  * **Monitor de Alimentos Perecederos (SLA 4h, Art. II):** Evaluación en tiempo real de cumplimiento contractual (*Compliant* vs *Breached*).
  * **Tasa de Resolución Formal:** Porcentaje de incidencias resueltas frente a creadas y control de *backlog* activo.
* **Desglose Multidimensional Combinable:** Análisis cruzado por Sede, Técnico resolutor, Tipología de máquina y Categoría de avería con detección de entidades inactivas. Incluye el indicador de **reintervenciones por garantía 48 h** por técnico (EARS 2.3.1): averías reabiertas dentro de la ventana del Art. V.6, atribuidas al técnico que firmó la resolución efectiva, como evidencia de reincidencia para auditoría de calidad (no es carga pendiente ni altera el MTTR original, EARS 1.3).
* **Autoconsulta Segregada del Técnico (Principio de Mínimo Privilegio, Art. V.4):**
  * Consulta protegida de MTTR personal, averías resueltas en el período, incidencias actualmente en curso y tiempo medio de primera respuesta técnica. Bloqueo estricto de acceso a métricas globales o de otros compañeros.
* **Visor del Registro Inmutable de Auditoría (*Append-Only*):**
  * Tabla `audit_log` con trazabilidad permanente de creación, asignación, inicio, repuestos y resoluciones.
  * Visor cronológico con filtros reactivos (entidad, acción, usuario, fechas), paginación y modal de inspección del payload JSON completo.
* **Exportación y Reportería Ejecutiva:**
  * **Descarga CSV con UTF-8 BOM:** Exportación instantánea de métricas agregadas y registro de auditoría (acotado por seguridad a 10.000 filas).
  * **Informe Resumen Ejecutivo Imprimible:** Maquetación lista para impresión física o PDF en formato A4 (`metrics-print.css`), limpia de menús y barras laterales.

### 4. Administración Integral del Parque (Módulo 04)
* **CRUD de Sedes Clientes:** Alta, edición, baja lógica y reactivación de centros con aislamiento de datos por sede.
* **CRUD de Máquinas:** Gestión del parque con tipologías normativas, planta/ala de ubicación y transferencias entre sedes auditadas.
* **CRUD de Personal Interno:** Altas de coordinadores y técnicos con operador oficial, reseteo de contraseñas y bajas lógicas con triple salvaguarda. La carga del directorio reporta solo averías pendientes de trabajo (`ASSIGNED`/`IN_PROGRESS`/`PENDING_PARTS`, Decisión QA 2) junto a un contador informativo de averías en garantía 48 h (solo lectura, no bloquea la baja); el bloqueo de baja comparte el mismo criterio.

### 5. Mantenimiento Preventivo y Certificación Sanitaria (Módulo M1)
* **Configuración de frecuencias normativas por tipología de máquina** (perecederos cada 15 días, Art. II) con alertas de vencimiento y generación automática de órdenes.
* **Ciclo completo de órdenes preventivas:** creación, asignación, reclamo técnico, inspección con checklist normativo por puntos de control, reinspección y cierre.
* **Dictamen de inspección con cuarentena sanitaria automática** para máquinas no conformes (sin levantamiento automático: exige certificado conforme).
* **Certificados Sanitarios Oficiales Individual y Global de Sede**, imprimibles en A4, con semáforo higiénico y Código de Operador Técnico Oficial (Art. V.4).

### 6. Catálogo de Repuestos y Trazabilidad de Piezas (Módulo M2)
* **Catálogo Maestro por Modelo de Máquina:** códigos únicos, categorías técnicas, fabricante, coste de referencia y compatibilidades múltiples con baja lógica preservando histórico (Art. III).
* **Pausa técnica estructurada:** selección de piezas compatibles con el modelo intervenido (sin texto libre) o pieza fuera de catálogo con justificación mínima de 20 caracteres.
* **Resolución con declaración obligatoria de sustitución (Sí/No):** registro de piezas instaladas, cantidades (1–50), destino reglamentario `DESGUACE`/`TALLER` y **congelación del coste unitario (*snapshot* inmutable)**.
* **Coexistencia con preventivos (M1):** consumo de piezas en inspecciones sin levantar cuarentenas sanitarias (Art. II).
* **Analítica de taller:** ranking de piezas, costes acumulados por modelo/sede, alertas de fallo crónico (> 3 sustituciones en 90 días en la misma máquina), bandeja de homologación de piezas fuera de catálogo y exportación CSV.
* **Blindaje constitucional Art. V.4 certificado por suite automática** (`SiteManagerPartsDataSegregationTest`).

### 7. Mapa Interactivo de Rutas y Georreferenciación Territorial (Módulo M4)
* **Proyección Cartográfica Web Mercator Pura (EPSG:3857):** Renderizado de teselas estándar (OpenStreetMap / IGN) mediante matemática vectorial pura en JavaScript ESM (`Mercator.js`), con encuadre dinámico adaptativo (*bounding box*) y controlador gestual fluido (`MapZoomPan.js`) de rueda, doble clic, arrastre táctil y *pinch-to-zoom* con elección de tesela *pixel-aware* para evitar distorsiones en pantallas panorámicas.
* **Optimización Heurística de Ruta de Campo en 4 Fases:** Secuenciación determinista sin servicios externos de pago de enrutamiento:
  1. Parada actualmente en curso (`IN_PROGRESS`) inamovible como primera parada (`#1`).
  2. Paradas críticas de alimentos perecederos ordenadas por proximidad al vencimiento de SLA (< 4h).
  3. Paradas ordinarias optimizadas mediante heurística geométrica de *Nearest Neighbor* (Vecino Más Cercano) calculada con trigonometría esférica de Haversine (`GeoDistanceService.php`).
  4. Exclusión automática de incidencias en espera de repuestos (`PENDING_PARTS`).
* **Modal Cartográfico Móvil para Técnico:** Visualización interactiva en smartphone con marcadores circulares numerados correlativos (1, 2, 3...), códigos semánticos de color (crítico, ordinario, en curso, preventivo), ficha contextual al tocar el marcador o la lista, recentrado reactivo (`focusOn`), y botones de navegación GPS directa mediante enlaces universales de Google Maps.
* **Cuadro Territorial de Coordinación:** Pestaña "Mapa Territorial" con vista panorámica de centros en ruta, matriz de averías activas por severidad, detección de concurrencia multi-técnico y reencuadre interactivo.
* **Geocodificación Asistida de Sedes:** Selector y validación de coordenadas latitud/longitud en la administración de sedes con geocodificación abierta para sugerir ubicaciones automáticamente.
* **Blindaje Constitucional de Privacidad (Art. V.4):** Imposibilidad estricta de acceso al mapa y a la posición de las rutas por parte de los Responsables de Sede, y prohibición de rastreo continuo en segundo plano del personal técnico (`SiteManagerRouteDataSegregationTest`).

### 8. Gestión de Reintegros e Importe Retenido / Dinero Tragado (Módulo M5)
* **Implementación Completa Certificada:** 8 suites específicas en verde (flujo del coordinador, entrega en sede, inspección técnica, seguimiento público, segregación Art. V.4, repositorio y extremo a extremo) sobre la especificación formal [`specs/functional/refunds_spec.md`](specs/functional/refunds_spec.md), los contratos técnicos [`specs/technical/refunds_contracts.md`](specs/technical/refunds_contracts.md), el plan de arquitectura [`specs/08-refunds/plan.md`](specs/08-refunds/plan.md) y el desglose de tareas [`specs/08-refunds/tasks.md`](specs/08-refunds/tasks.md).
* **Protocolo de Reclamación Ciudadana y PIN Secreto:** Solicitud opcional de reintegro en el reporte QR por fallo de pago o dinero tragado, con generación de PIN secreto de 4 dígitos para entrega presencial en conserjería o transferencia digital (Bizum/IBAN validado nativamente mediante Módulo 97 ISO 7064). Seguimiento ciudadano anónimo por token con rectificación de datos de cobro, y freno antifuerza del PIN: bloqueo temporal del expediente (`423 PICKUP_PIN_LOCKED`) tras cinco intentos fallidos de entrega.
* **Custodia y Desacoplamiento (Art. II y III):** Dictamen económico desacoplado de la resolución técnica del ticket, custodia central forzada para importes $> 10,00\ \text{€}$ y bandeja de coordinación para liquidación administrativa con registro inmutable.

### 9. Hilo de Conversación del Expediente y Evidencias Selladas (Módulo 10)
* **Conversación bidireccional sobre el propio expediente:** sede, técnico y coordinación dialogan en un único hilo auditable, sin canales paralelos, con contadores de conversación segregados por rol y paginación por cursor para históricos extensos (RF-01).
* **Privacidad del técnico y notas internas (Art. V.4):** el portal de sede **jamás** recibe notas internas de taller ni la identidad del técnico; el técnico y la coordinación ven el hilo íntegro, y la interfaz móvil preselecciona «Nota Interna de Taller» para las anotaciones de campo.
* **Evidencias fotográficas endurecidas (Art. III y V.5):** el servidor valida la cabecera real del archivo y el límite de **5 MB**; un fichero que no sea un gráfico válido se rechaza con `422` **sin dejar residuo en disco**.
* **Sellado y trazabilidad (Art. III):** cada publicación deja su evento inmutable `INCIDENT_COMMENT_ADDED` con autoría, y el hilo de un expediente cerrado queda sellado a nuevas publicaciones conservando la ventana de garantía de 48 h.
* **Señalización visual y resiliencia:** los contadores de conversación activa aparecen en la tarjeta de máquina y en el triaje sin filtrar contenido, y un envío fallido avisa y conserva el borrador en pantalla (RF-07).

### 10. Pausa Contractual de Espera de Sede (Módulo 11)
* **Estado operativo `PENDING_INFO` («Pendiente de información»):** el técnico o el coordinador aparcan la avería cuando falta acceso al inmueble o información de la sede, con causa tipificada y motivo de ≥ 20 caracteres. La pausa **congela el reloj contractual de SLA** y su vencimiento se desplaza en horario comercial de la sede.
* **Reanudación siempre humana y coherente:** la reactivación automática por comentario de sede quedó derogada; sin técnico asignado el expediente vuelve a `REGISTERED` (cola de asignación) y **nunca** a `ASSIGNED`/`IN_PROGRESS`, para no declarar un responsable inexistente.
* **Cancelación supervisada por inactividad:** superadas **72 horas hábiles** de silencio, la coordinación puede cancelar el expediente de forma justificada sin que la máquina vuelva a figurar como «Operativa», desvinculando con seguridad los reintegros pendientes (Art. V.1 y V.2).
* **Doble reloj sanitario (Art. II):** sobre máquinas perecederas y mixtas corre en paralelo el Reloj Sanitario Biológico — **4 horas naturales** sin frío confirmado activan la cuarentena y bloquean el cierre hasta declarar temperatura real, retirada y destrucción del stock y checklist de higienización.
* **Barrido de fondo y su superficie operativa:** la activación automática no espera a un intento de cierre (`php bin/sanitary_clock_sweep.php` o `POST /api/cron/sanitary-clock-sweep` cada 15 minutos, con cota de espera de bloqueo de 2 s y la contención publicada como aplazamiento, nunca como fallo); la pestaña *🩺 Barrido Sanitario* publica el latido, la tendencia de 24 h y el **escalado cuando el aplazamiento persiste** (RF-07).
* **Segregación pública y transición prohibida (Art. V.4 y V.1):** el escaneo ciudadano muestra un estado neutro («En proceso de atención técnica») y **nunca** el motivo de la pausa, y queda prohibido cerrar directamente desde `PENDING_INFO` sin registrar la intervención física.

---

## 👥 Actores del Sistema y Credenciales de Demostración

La aplicación cuenta con un conmutador de perfiles en la barra superior para alternar de forma inmediata entre roles:

| Perfil / Actor | Identificador / Email | Contraseña / Código | Función Principal |
| :--- | :--- | :--- | :--- |
| **🏢 1. Responsable de Sede** | `SEDE-BCN-01` *(Hospital del Mar)*<br>`SEDE-BCN-02` *(Torre Glòries)* | Código + `DEV-<site_code>` *(clave de desarrollo, ver nota)* | Supervisa máquinas del edificio, reporta averías (< 2 min), anexa evidencias y reabre en garantía. |
| **📊 2. Coordinador de Operaciones** | `coordinacion@vendguard.internal` | `Password123!` | Triaje central, supervisión 24/7 de SLAs, administración del parque y personal, preventivos y certificados sanitarios, catálogo y analítica de repuestos, cuadro de mando de métricas y visor de auditoría. Supervisa además la pausa de espera de sede (reanudación manual y cancelación por inactividad) y la superficie operativa del barrido sanitario. |
| **📱 3. Técnico de Ruta de Campo** | `jordi.ruta@vendguard.internal`<br>`marta.ruta@vendguard.internal` | `Password123!` | Interfaz vertical *mobile-first*, gestión de averías en ruta, inicio de intervención, pausa estructurada por repuestos o por falta de acceso al inmueble (pausa de sede), resolución justificada con piezas sustituidas, hilo de conversación del expediente, inspecciones preventivas, consulta del historial de la máquina y métricas individuales. |
| **🤳 4. Usuario / Consumidor Final** | Enlace QR (`?code=VEND-0101`) | *Público / Sin registro* | Reporte inmediato in situ escaneando la pegatina de la máquina, con feedback de estado si ya estaba reportada. |

> **Clave de centro de desarrollo (cierre de S-4).** En pruebas, las semillas emiten una clave determinista por sede; para el trabajo manual en local hay que pedirla de forma explícita (`VENDGUARD_DEV_SITE_KEYS=1 php bin/seed.php`). La regla es `DEV-<site_code>` (p. ej. `DEV-SEDE-BCN-01`). En producción el campo no se siembra jamás: cada sede recibe su clave aleatoria al alta o al reemitirse desde coordinación, se muestra una sola vez y solo se guarda su huella bcrypt. Sin clave emitida, el acceso de la sede falla en cerrado.

---

## 🚀 Puesta en Marcha Rápida

### Requisitos Previos
* **PHP:** 8.2 o superior con extensiones `pdo_mysql` y `mbstring` habilitadas (la imagen oficial ya compila `mbstring`; el [Dockerfile](Dockerfile) instala `pdo_mysql`).
* **Base de Datos:** MySQL 8.0+ o MariaDB 10.5+ (local, Docker o Cloud como TiDB Serverless).
* **Navegador:** Chrome, Edge, Firefox o Safari con soporte de módulos ES.

### 1. Configuración de Base de Datos y Semillas

#### Entorno Local (MariaDB / MySQL):
```powershell
# Crear la base de datos:
mysql -u root -e "CREATE DATABASE IF NOT EXISTS vendguard_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Opción recomendada: migrador DDL idempotente (esquema base + migraciones 003-018
# de auditoría, CRUD, preventivos, repuestos, mapa, reintegros, freno del PIN, clave
# de centro (S-4), bloqueo de login, pausa de sede, cancelación por inactividad y
# visibilidad del barrido sanitario, con verificación de integridad final):
php bin/migrate.php

# Cargar semillas (sedes, máquinas, usuarios, preventivos y catálogo de repuestos).
# Opción recomendada: sembrador CLI con verificación de integridad T-04 y contraseñas demo:
php bin/seed.php

# Alternativa equivalente en una sola línea:
php -r "require 'src/autoload.php'; require 'src/Infrastructure/Database/ConnectionFactory.php'; require 'src/Infrastructure/Database/SeedRunner.php'; \$pdo = VendGuard\Infrastructure\Database\ConnectionFactory::getConnection(); \$s = new VendGuard\Infrastructure\Database\SeedRunner(\$pdo); \$s->seedAll(); echo 'Base de datos inicializada.';"

# Sembrar histórico representativo de métricas, reparaciones y log de auditoría:
php bin/seed_demo_metrics.php
```

#### Entorno Cloud / Producción (Render, TiDB Cloud, Aiven):
El script [`bin/init_cloud_db.php`](bin/init_cloud_db.php) ejecuta automáticamente el esquema DDL, **aplica todas las migraciones incrementales** (hoy 003-018, de ahí que una base de datos ya desplegada converja en cada arranque) y siembra sedes, máquinas, técnicos, averías históricas y eventos de auditoría de forma tolerante e idempotente:
```powershell
DB_HOST=gateway... DB_USER=xxx DB_PASSWORD=yyy DB_NAME=vendguard DB_PORT=4000 DB_SSL=1 php bin/init_cloud_db.php
```

### 2. Iniciar el Servidor Web Local
```powershell
php -S 127.0.0.1:8000 -t public public/index.php
```

### 3. Acceder a la Aplicación
Abre en tu navegador:
👉 **[http://127.0.0.1:8000/](http://127.0.0.1:8000/)**

### 4. Programar los Procesos de Fondo (Cron)

Los dos procesos batch del sistema se disparan desde fuera de la aplicación: VendGuard **no** lleva planificador propio, de modo que su activación real depende de la tarea programada que se configure aquí. La credencial es la cabecera `X-Cron-Secret` (variable de entorno `CRON_SECRET`; en desarrollo vale la clave local `vendguard-dev-cron-key-local-only`) y las dos rutas son fail-closed: sin la marca del middleware responden `401` aunque la ruta se registre mal.

| Proceso | Cadencia ratificada | Comando |
| :--- | :--- | :--- |
| **Barrido del Reloj Sanitario Biológico** (RF-03.5.1, Art. II): activa la cuarentena de las máquinas bajo vigilancia con más de 4 h naturales sin frío confirmado **sin** esperar a un intento de cierre; una fila sostenida por otro consumidor se aplaza en 2 s en lugar de bloquear la pasada. | **Cada 15 minutos** — acota la latencia de detección en «4 h naturales + hasta 15 min» (+ una cadencia si otro consumidor sostiene la fila). | `php bin/sanitary_clock_sweep.php` *(o `POST /api/cron/sanitary-clock-sweep`)* |
| **Cierre definitivo de garantía** (RF-10): archiva a `CERRADA` los expedientes resueltos que superan las 48 h sin reclamación. | Una vez al día | `POST /api/cron/auto-close` |

```bash
# crontab -e  (Linux / macOS)
*/15 * * * * cd /ruta/a/gestor-incidencias-vending && CRON_SECRET=... php bin/sanitary_clock_sweep.php >> /var/log/vendguard-sweep.log 2>&1
0 3 * * *     curl -s -X POST -H "X-Cron-Secret: $CRON_SECRET" https://tu-dominio/api/cron/auto-close
```

El equivalente de línea de comandos del barrido devuelve código de salida **distinto de cero** si alguna máquina no pudo evaluarse, de modo que un planificador o un supervisor de tareas puede alertar sobre él; el mismo barrido sigue disponible por HTTP para entornos sin acceso a la consola del servidor. La cadencia se **comprueba** desde el panel de coordinación: la pestaña *🩺 Barrido Sanitario* publica el latido (última pasada y minutos transcurridos), la tendencia de 24 h y los expedientes aplazados por contención de fila, con la alerta de escalado cuando un aplazamiento persiste tres pasadas consecutivas (RF-07).

### 5. Despliegue en Contenedor (Render) y Variables de Entorno
El contenedor declara dos pasos y ninguno es opcional:
```dockerfile
CMD ["sh", "-c", "php bin/init_cloud_db.php ; php -S 0.0.0.0:${PORT} -t public public/index.php"]
```
* **Paso 1 — convergencia de la base de datos:** `init_cloud_db.php` ejecuta `cloud_init.sql` (creación de tablas con `CREATE TABLE IF NOT EXISTS`) **y todas las migraciones de `database/migrations/` en orden**, sentencia a sentencia y sin abortar el resto si un motor cloud no soporta triggers. Es lo que hace que una base **ya existente** reciba las columnas y tablas nuevas de cada despliegue; si este paso falla, el servidor arranca igual (el `;` no corta la cadena) pero el esquema se queda atrás.
* **Paso 2 — servidor web:** `php -S` sirviendo `public/index.php`, que despacha tanto la API como los assets estáticos con su MIME y `Cache-Control: no-cache` para JS/CSS (un despliegue no se queda a medias por caché del navegador). El puerto lo inyecta la plataforma (`PORT`, por defecto 8080).
* **Imagen y extensiones:** `php:8.2-cli-alpine` trae `mbstring` compilada de serie y el [Dockerfile](Dockerfile) instala `pdo_mysql`; no hace falta ninguna otra extensión (Dogma Vanilla: cero `composer`, cero `npm` en runtime).

**Variables de entorno** (única fuente de verdad: [`SecretProvider`](src/Infrastructure/Config/SecretProvider.php) y [`ConnectionFactory`](src/Infrastructure/Database/ConnectionFactory.php)):

| Variable | Obligatoria en producción | Efecto |
| :--- | :--- | :--- |
| `SECRET_KEY` | **Sí** con `APP_ENV=production` | Firma HMAC de los tokens de sesión. Sin ella y sin entorno declarado se usa una clave **exclusiva de desarrollo**. |
| `CRON_SECRET` | **Sí** con `APP_ENV=production` | Credencial `X-Cron-Secret` de los dos procesos batch. |
| `APP_ENV` ó `VENDGUARD_ENV` | Recomendada | `production`/`prod` activan el **fallo en cerrado**: sin claves el sistema no arranca y `root` sin contraseña se rechaza (hallazgos S-1 y S-5). |
| `DATABASE_URL` / `MYSQL_URL` | Alternativa | Cadena única del PaaS (`mysql://usuario:clave@host:puerto/base`). Tiene prioridad sobre `DB_*`. |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | Sí (o la cadena única) | Credenciales sueltas; se aceptan también `DB_DATABASE`, `DB_USERNAME` y `DB_PASS`. |
| `DB_SSL` | Según proveedor | `1`/`true` fuerza TLS; se activa solo si el host contiene `tidbcloud`/`aivencloud`. |
| `PORT` | La inyecta la plataforma | Puerto del servidor embebido. |
| `APP_BASE_URL` | Opcional | Host público canónico para las URLs impresas en las etiquetas QR (si falta, se resuelve por la petición). |
| `VENDGUARD_DEV_SITE_KEYS` | **Nunca** en producción | Solo en local: siembra claves de centro deterministas `DEV-<site_code>`. En producción no se siembra ninguna: cada sede recibe su clave aleatoria al alta o al reemitirse desde coordinación. |

**Cron en la plataforma:** un *Cron Job* con la misma imagen ejecutando `php bin/sanitary_clock_sweep.php` cada 15 minutos (o una llamada externa a `POST /api/cron/sanitary-clock-sweep` con la cabecera `X-Cron-Secret`) y `POST /api/cron/auto-close` una vez al día. Sin el barrido programado, la pestaña *🩺 Barrido Sanitario* lo delata con el latido envejecido: la superficie distingue «no hay nada que hacer» de «el barrido no está corriendo».

> **Credenciales de demostración en el despliegue.** El paso 1 siembra el personal interno con la contraseña de demostración, de modo que `coordinacion@vendguard.internal` / `Password123!` funciona nada más desplegar. Las claves de centro, en cambio, **no** se siembran en producción (arriba).

---

## 🔌 Resumen de Endpoints de la API REST

Todas las respuestas cumplen con la envolvente canónica JSON (`{ success: true, data: ... }` o `{ success: false, error: ... }`):

| Método | Endpoint | Rol Autorizado | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `GET` | `/` y `/api/health` | Público | Comprobación de salud operativa y estado del sistema. |
| `POST`| `/api/auth/site-login` | Público | Acceso de responsables mediante código de sede (`site_code`) y clave de centro (`access_code`). |
| `POST`| `/api/auth/login` | Interno | Login para personal interno emitiendo token Bearer criptográfico. |
| `GET` | `/api/locations/{code}/machines` | `LOCATION_MANAGER` | Catálogo de máquinas del centro con estado de ticket activo. |
| `POST`| `/api/incidents` | `LOCATION_MANAGER` | Registro de avería con cálculo de urgencia y prevención de duplicados (409). |
| `POST`| `/api/incidents/{code}/comments`| `LOCATION_MANAGER` | Anexa comentarios o evidencias fotográficas a la bitácora. |
| `GET` | `/api/incidents/{code}/comments` | `LOCATION_MANAGER` | Consulta la bitácora de comentarios y evidencias de la avería. |
| `POST`| `/api/incidents/{code}/reopen` | `LOCATION_MANAGER` | Reapertura dentro de garantía (< 48h) desasignando al técnico. |
| `GET` | `/api/coordinator/incidents` | `COORDINATOR` | Listado global con filtros combinados y evaluación de SLA > 60m. |
| `PATCH`| `/api/coordinator/incidents/{id}/assign`| `COORDINATOR`| Asignación técnica a técnico de ruta (justificación si varía urgencia). |
| `PATCH`| `/api/coordinator/incidents/{id}/cancel`| `COORDINATOR`| Descarte lógico justificado (*Soft Delete*). |
| `GET` | `/api/coordinator/locations` | `COORDINATOR` | Listado de sedes con total de máquinas y averías activas. |
| `GET` | `/api/coordinator/locations/{id}/machines` | `COORDINATOR` | Parque de máquinas de una sede con estado operativo. |
| `GET` | `/api/coordinator/machines/{id}/qr-label` | `COORDINATOR` | Datos y SVG vectorial de etiqueta QR para máquina. |
| `GET` | `/api/coordinator/locations/{id}/qr-batch` | `COORDINATOR` | Lote completo de etiquetas QR de una sede para impresión masiva. |
| `GET` | `/api/coordinator/metrics/summary` | `COORDINATOR` | KPIs globales, MTTR, comparativa de tendencia y alertas de SLA. |
| `GET` | `/api/coordinator/metrics/breakdown` | `COORDINATOR` | Desglose multidimensional (sedes, técnicos, máquinas, averías) con reintervenciones por garantía 48h por técnico (`warranty_reopens`). |
| `GET` | `/api/coordinator/metrics/export` | `COORDINATOR` | Descarga CSV con codificación UTF-8 BOM de métricas agregadas. |
| `GET` | `/api/coordinator/audit-log` | `COORDINATOR` | Consulta cronológica paginada y filtrable del registro inmutable. |
| `GET` | `/api/coordinator/audit-log/export` | `COORDINATOR` | Descarga CSV del registro inmutable (máx. 10.000 filas). |
| `GET` | `/api/coordinator/sanitary-sweep/contention` | `COORDINATOR` | Superficie operativa del barrido sanitario: latido, tendencia de 24 h, expedientes aplazados por contención de fila y alerta de escalado cuando el aplazamiento persiste (RF-07). |
| `GET` | `/api/coordinator/incidents/{id}/detail` | `COORDINATOR` | Ficha integral de solo lectura del expediente: sede y máquina, ciclo de vida, SLA de frío, intervención, bitácora, piezas y matriz de permisos (Módulo 09). |
| `GET` | `/api/technician/my-route` | `TECHNICIAN` | Hoja de ruta móvil ordenada por criticidad. |
| `GET` | `/api/technician/my-metrics` | `TECHNICIAN` | Autoconsulta individual protegida de rendimiento (Art. V.4). |
| `GET` | `/api/technician/machines/{id}/history` | `TECHNICIAN` | Historial de averías de la máquina de la ruta (solo lectura, Art. V.4). |
| `PATCH`| `/api/technician/incidents/{id}/start` | `TECHNICIAN` | Inicio de intervención física in situ (`started_at`). |
| `PATCH`| `/api/technician/incidents/{id}/pause` | `TECHNICIAN` | Pausa estructurada por repuestos (`PENDING_PARTS`) con selección compatible o pieza fuera de catálogo. |
| `POST`| `/api/technician/incidents/{id}/resolve`| `TECHNICIAN` | Resolución técnica documentada ($\ge 20$ chars diagnóstico y acción). |
| `POST`| `/api/cron/auto-close` | Clave Cron | Cierre definitivo automático tras 48h de garantía. |
| `POST`| `/api/cron/sanitary-clock-sweep` | Clave Cron | Barrido de fondo del Reloj Sanitario Biológico: activa la cuarentena sin intento de cierre (latencia ≤ 15 min). |
| `GET` | `/api/qr/scan/{code}` | Público | Consulta ciudadana de estado de máquina por código QR. |
| `POST`| `/api/qr/report` | Público | Envío directo de incidencia ciudadana desde lectura QR. |

#### Administración Integral (Módulo 04, rol `COORDINATOR`)
| Método | Endpoint | Descripción del Recurso |
| :--- | :--- | :--- |
| `GET/POST/PATCH` | `/api/coordinator/locations[/{id}][/deactivate\|/reactivate]` | CRUD de sedes clientes con bajas lógicas. |
| `POST` | `/api/coordinator/locations/{id}/access-code` | Reemisión de la clave de centro de una sede: se muestra una sola vez y solo se guarda su huella bcrypt (S-4). |
| `GET/POST/PATCH` | `/api/coordinator/machines[/{id}][/transfer\|/deactivate\|/reactivate]` | CRUD de máquinas con transferencias auditadas entre sedes. |
| `GET/POST/PATCH` | `/api/coordinator/users[/{id}][/reset-password\|/deactivate\|/reactivate]` | CRUD de personal interno con reseteo de credenciales y contador informativo de garantía 48h (`warranty_incidents_count`). |

#### Mantenimiento Preventivo y Certificación Sanitaria (Módulo M1)
| Método | Endpoint | Rol | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/coordinator/preventive/dashboard` | `COORDINATOR` | Cuadro de vencimientos y alertas de inspección por tipología. |
| `GET/POST` | `/api/coordinator/preventive/orders` | `COORDINATOR` | Listado y creación de órdenes preventivas. |
| `GET` | `/api/coordinator/preventive/orders/{id}/detail` | `COORDINATOR` | Detalle integral de solo lectura de la orden (checklist, cronología de auditoría y cumplimiento). |
| `POST` | `/api/coordinator/preventive/generate-due` | `COORDINATOR` | Generación automática de órdenes por vencimiento normativo. |
| `PATCH` | `/api/coordinator/preventive/orders/{id}/assign\|/cancel` | `COORDINATOR` | Asignación técnica o cancelación justificada de la orden. |
| `GET/PATCH` | `/api/coordinator/preventive/settings` y `/api/coordinator/machines/{id}/preventive-config` | `COORDINATOR` | Frecuencias normativas por tipología (globales y por máquina). |
| `GET` | `/api/technician/preventive/route` | `TECHNICIAN` | Ruta preventiva móvil del técnico. |
| `POST` | `/api/technician/preventive/orders/{id}/claim\|/start\|/complete\|/reinspect` | `TECHNICIAN` | Ciclo de inspección: reclamo, inicio, checklist con dictamen y reinspección. |
| `GET` | `/api/technician/preventive/orders/{id}/checklist` | `TECHNICIAN` | Puntos de control normativos de la inspección. |
| `GET` | `/api/site/sanitary-status` | `LOCATION_MANAGER` | Semáforo higiénico de la sede (última desinfección y temperatura). |
| `GET` | `/api/site/certificates/machine/{code}` y `/api/site/certificates/global` | `LOCATION_MANAGER` | Certificados sanitarios oficial individual y global consolidado (JSON o A4 imprimible). |

#### Catálogo de Repuestos y Trazabilidad (Módulo M2)
| Método | Endpoint | Rol | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `GET/POST` | `/api/coordinator/spare-parts` | `COORDINATOR` | Listado filtrable y creación de repuestos con modelos compatibles (409 si duplicado). |
| `GET` | `/api/coordinator/spare-parts/models` | `COORDINATOR` | Modelos únicos del parque para los selectores de compatibilidad. |
| `GET/PUT` | `/api/coordinator/spare-parts/{id}` | `COORDINATOR` | Detalle con unidades instaladas y edición (código inmutable). |
| `DELETE` | `/api/coordinator/spare-parts/{id}` | `COORDINATOR` | Baja lógica del repuesto sin borrado físico (Art. III). |
| `PATCH` | `/api/coordinator/spare-parts/{id}/status` | `COORDINATOR` | Baja lógica y reactivación sin borrado físico (Art. III). |
| `GET` | `/api/coordinator/spare-parts/analytics` | `COORDINATOR` | Ranking de piezas, costes por modelo/sede y alertas de fallo crónico. |
| `GET` | `/api/coordinator/spare-parts/export` | `COORDINATOR` | Exportación CSV de consumos con snapshots congelados. |
| `GET` | `/api/coordinator/spare-parts/requests/pending-review` | `COORDINATOR` | Bandeja de piezas fuera de catálogo pendientes de homologación. |
| `GET` | `/api/technician/spare-parts/catalog?machine_id={id}` | `TECHNICIAN` | Catálogo compatible con la máquina intervenida (< 250 ms). |
| `PATCH` | `/api/technician/incidents/{id}/pause` | `TECHNICIAN` | Pausa estructurada con `requested_parts` (1–50 uds.) o pieza fuera de catálogo. |
| `POST` | `/api/technician/incidents/{id}/resolve` | `TECHNICIAN` | Resolución con `replaced_parts_declared`, destino `DESGUACE`/`TALLER` y snapshot de coste. |
| `POST` | `/api/technician/preventive/orders/{id}/complete` | `TECHNICIAN` | Cierre preventivo con registro opcional de piezas sustituidas. |

#### Logística de Rutas y Cartografía Territorial (Módulo M4)
| Método | Endpoint | Rol | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/technician/route/map` | `TECHNICIAN` | Hoja de ruta secuenciada con paradas, criticidades, desglose correctivo/preventivo y enlaces GPS. |
| `GET` | `/api/coordinator/map/active-incidents` | `COORDINATOR` | Matriz territorial de sedes con averías activas, severidad máxima y marcas multi-técnico. |
| `GET/PUT` | `/api/coordinator/route/settings` | `COORDINATOR` | Consulta y actualización de parámetros de la Base Central (coordenadas y radio operativo). |
| `POST/PUT` | `/api/coordinator/locations` | `COORDINATOR` | Alta y edición de sedes con latitud/longitud validadas dentro del marco operativo territorial. |

#### Reintegros e Importe Retenido (Módulo M5)
| Método | Endpoint | Rol | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `POST` | `/api/qr/report` | Público | Reporte ciudadano con solicitud opcional de reintegro (fallo de pago o dinero tragado). |
| `GET` | `/api/public/refunds/track` | Público | Seguimiento anónimo del reintegro por token con estado y Liquidación. |
| `PATCH` | `/api/public/refunds/track` | Público | Rectificación ciudadana de datos de cobro (Bizum/IBAN Módulo 97 ISO 7064). |
| `GET` | `/api/technician/incidents/{id}/refund` | `TECHNICIAN` | Dictamen económico desacoplado de la resolución técnica del ticket. |
| `GET` | `/api/location/refunds` | `LOCATION_MANAGER` | Reintegros de la sede pendientes de entrega en conserjería. |
| `POST` | `/api/location/refunds/{id}/deliver` | `LOCATION_MANAGER` | Entrega presencial validando el PIN secreto de 4 dígitos (bloqueo 423 tras 5 intentos). |
| `GET` | `/api/coordinator/refunds` | `COORDINATOR` | Bandeja de liquidación administrativa de reintegros custodiados. |
| `POST` | `/api/coordinator/refunds/{id}/approve\|/pay\|/reject\|/regularize` | `COORDINATOR` | Aprobación, pago, rechazo y regularización con registro inmutable. |

#### Hilo de Conversación del Expediente (Módulo 10)
| Método | Endpoint | Rol | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `GET/POST` | `/api/location/incidents/{id}/comments` | `LOCATION_MANAGER` | Hilo del expediente desde el portal de sede, con alias retrocompatible por código de ticket (`/api/incidents/{ticket_code}/comments`). **Nunca** incluye notas internas ni el nombre del técnico (Art. V.4). |
| `GET/POST` | `/api/technician/incidents/{id}/comments` | `TECHNICIAN` | Hilo íntegro para el técnico, notas internas incluidas, con selector de privacidad preseleccionado en «Nota Interna de Taller». |
| `GET/POST` | `/api/coordinator/incidents/{id}/comments` | `COORDINATOR` | Inspección total del diálogo y publicación de notas internas o públicas durante el triaje. |

#### Pausa Contractual de Espera de Sede (Módulo 11)
| Método | Endpoint | Rol | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `POST` | `/api/technician/incidents/{id}/pause-pending-info` | `TECHNICIAN` | Pausa por falta de acceso o información del inmueble: causa tipificada, motivo ≥ 20 caracteres y **reloj de SLA congelado**. |
| `POST` | `/api/technician/incidents/{id}/resume-pending-info` | `TECHNICIAN` | Reanudación manual in situ con el vencimiento desplazado en horario comercial. |
| `POST` | `/api/coordinator/incidents/{id}/pause-pending-info` | `COORDINATOR` | Pausa supervisada sobre cualquier ticket activo. |
| `POST` | `/api/coordinator/incidents/{id}/resume-pending-info` | `COORDINATOR` | Reanudación manual: devuelve el expediente a `ASSIGNED` o a `REGISTERED` según haya responsable o no. |
| `POST` | `/api/coordinator/incidents/{id}/cancel-inactivity` | `COORDINATOR` | Cancelación justificada tras **72 horas hábiles** de silencio, desvinculando los reintegros pendientes (Art. V.1 y V.2). |

---

## 🧪 Batería Completa de Pruebas Automatizadas (T-39 & Módulos 02, 03, 04, M1, M2 y M4)

La integridad de VendGuard está certificada mediante un ejecutor de pruebas automatizado nativo, ejecutado en fases continuas (unitarias PHP, unitarias reactivas JS, integración HTTP real, guion E2E de los tres perfiles y guarda de coherencia entre las cifras documentadas y las ejecutadas):

```powershell
# Ejecutar la suite completa (Unitarias PHP, Unitarias JS e Integración PHP):
php tests/run_all.php
```

### Resumen de Ejecución Global (Octubre 2026)

La cifra vigente se declara una sola vez y la propia batería la audita (`tests/Support/DocMetricsGuard.php`), de modo que un desfase documental la pone en rojo:

batería global: 257 suites · 9.816 aserciones

```text
======================================================================
 RESUMEN DE EJECUCIÓN GLOBAL (T-39)
======================================================================
 Tiempo de ejecución total : 142.00 segundos  (variable en cada corrida)
 Suites de pruebas PHP Unit : 111 / 111 pasadas
 Suites de pruebas JS Unit  : 66 / 66 pasadas
 Suites de Integración PHP  : 79 / 79 pasadas
 Suites E2E Manuales (T-40): 1 / 1 pasadas
 ──────────────────────────────────────────────────────────────────
 Total Suites Ejecutadas    : 257
 Total Aserciones Evaluadas : 9.816
 Fallos Detectados          : 0
 Cifras documentadas        : COHERENTES
 Base de datos restablecida : SÍ (Semillas intactas)
======================================================================
 RESULTADO: 100% EN VERDE. (0 errors, 0 failures)
 CONDICIÓN T-39 CUMPLIDA SATISFACTORIAMENTE.
======================================================================
```

Entre las suites de certificación destacan:
* **`ConstitutionalAuditTest`** — auditoría automática de los Artículos I al VII (cero `DELETE FROM` en producción, tipado estricto, tokens visuales Docker).
* **`SiteManagerPartsDataSegregationTest` (T-SPARE-20)** — blindaje del Art. V.4: ningún endpoint del portal de sede expone piezas, destinos ni costes.
* **`SiteManagerRefundDataSegregationTest`** — blindaje del Art. V.4 en reintegros: ningún rol ajeno consulta el dictamen económico (403) y la entrega en sede exige el PIN de recogida.
* **`SiteManagerRouteDataSegregationTest` (T-MAP-19)** — blindaje del Art. V.4: bloqueo 403 Forbidden para responsables de sede frente a rutas de campo y prohibición de rastreo en segundo plano del personal.
* **`RouteOptimizationServiceTest` (T-MAP-07)** — verificación matemática de la heurística de optimización de rutas (Haversine, paradas prioritarias por SLA < 4h, parada activa `#1` y exclusión de `PENDING_PARTS`).
* **`MapZoomPanUtilTest.mjs` (T-MAP-21)** — controlador gestual reactivo puro: anclaje, límites de escala, clamp de encuadre, gestos táctiles *pinch-to-zoom* y recentrado interactivo `focusOn`.
* **`CoordinatorRouteMapApiTest` / `TechnicianRouteMapApiTest` (T-MAP-17/18)** — integración HTTP contra MariaDB del ciclo cartográfico completo, fallback a Base Central y cálculo de enlaces universales de navegación.
* **`CoordinatorButtonVariantsGuardTest.mjs`** — los 149 botones del panel del coordinador visten la escalera `--control-*` y ningún botón declara geometría ni color en línea (sí una colocación o un color de estado); la guarda se prueba contra muestras sintéticas y cae también si alguna variante declarada queda muerta.
* **`CoordinatorActionsWrapGuardTest.mjs`** — ningún grupo de acciones del panel depende de tener pocos controles: el grupo envuelve y la etiqueta nunca se parte dentro de su propio botón.
* **`SanitarySweepVisibilityServiceTest` / `CoordinatorSanitarySweepEndpointTest` (T-PAUSE-41)** — la superficie del barrido sanitario: umbrales de escalado a las tres pasadas consecutivas, cierre del seguimiento **por identidad** y el ciclo completo contra MariaDB sobre dos conexiones PDO reales (aplazamiento → cuarentena → cierre → alerta apagada).

> **Nota operativa:** las suites de integración incluyen pruebas de HTTP real contra `127.0.0.1:8000`. Si el puerto está ocupado por un servidor obsoleto, arránquelo antes con `php -S 127.0.0.1:8000 -t public public/index.php`.

---

## 📂 Estructura del Repositorio

```text
gestor-incidencias-vending/
├── constitution.md               # Ley Suprema del proyecto (Artículos I al VII)
├── AGENTS.md                     # Directrices operativas de desarrollo y SDD
├── Dockerfile                    # Contenedor para despliegues cloud en Render
├── router.php                    # Router del servidor embebido de PHP (php -S ... router.php)
├── README.md                     # Esta guía maestra de entrega y operaciones
├── bin/
│   ├── init_cloud_db.php         # Inicializador automático para MySQL/TiDB Cloud
│   ├── migrate.php               # Migrador DDL idempotente (esquema + migraciones 003-013)
│   ├── seed.php                  # Sembrador CLI del catálogo base
│   ├── seed_demo_metrics.php     # Sembrador de métricas históricas y auditoría
│   ├── create_bizum_case.php     # Script CLI de caso demo de reintegro por Bizum
│   └── sanitary_clock_sweep.php  # Barrido CLI del Reloj Sanitario Biológico (mismo reloj que el endpoint cron)
├── database/
│   ├── schema.sql                # DDL MariaDB del núcleo operativo
│   ├── cloud_init.sql            # Script unificado integral para despliegues cloud
│   ├── migrations/               # DDL incrementales idempotentes 003-018 (auditoría, CRUD, preventivos, repuestos, mapa, reintegros y PIN, bloqueo de login, pausa de sede, cancelación por inactividad y visibilidad del barrido sanitario)
│   ├── seeds.sql                 # Semillas SQL del catálogo base (referencia)
│   └── DemoMetricsSeeder.php     # Generador de histórico de averías, reaperturas por garantía y auditoría
├── docs/
│   ├── design.md                 # Especificación de tokens visuales Docker
│   ├── manual_0_analisis_problema.md # Análisis de negocio y reglas de vending
│   ├── manual_e2e_verification.md    # Guion de verificación E2E (T-40)
│   ├── manual_modulo_m2_repuestos.md # Manual de usuario del Módulo M2 (coordinador y técnico)
│   ├── features_pendientes.md        # Catálogo de features pendientes y roadmap
│   ├── auditoria_arquitectura.md     # Auditoría de arquitectura
│   ├── verificacion_*.md             # Informes de verificación (criterios de cierre, hitos H2/H4, humo de flujos, usabilidad 360px)
│   ├── propuesta_*.md                # Propuestas y decisiones pendientes del Product Owner (política de reintento del barrido, accesos de sede)
│   ├── informe_sonda_humo_flujo_modulo_11.md # Sonda de humo del flujo de pausa de sede
│   ├── handoff_rama_incident-comments.md     # Handoff de la rama de trabajo
│   └── constitutional_audit_report.md# Dictamen formal de auditoría
├── specs/
│   ├── 02-qr-codes/              # Especificación del módulo de códigos QR
│   ├── 03-metrics-audit/         # Especificación del módulo de métricas y auditoría
│   ├── 04-admin-crud/            # Especificación del panel de administración integral
│   ├── 05-preventive-maintenance/ # Especificación del mantenimiento preventivo y certificación sanitaria (M1)
│   ├── 06-spare-parts/           # Especificación del catálogo de repuestos y trazabilidad (M2)
│   ├── 07-route-map/             # Especificación del mapa interactivo de rutas y logística territorial (M4)
│   ├── 08-refunds/               # Especificación y plan del módulo de reintegros y dinero tragado (M5)
│   ├── 09-incident-detail-modal/ # Especificación del modal de detalle integral de incidencias
│   ├── 10-incident-comments/     # Especificación del hilo de conversación del expediente
│   ├── 11-pending-info-sla-pause/# Especificación de la pausa contractual de sede y del barrido sanitario
│   ├── functional/               # Especificaciones funcionales EARS transversales
│   └── technical/                # Contratos de API, DDL y esquemas de base de datos
├── src/
│   ├── Core/                     # Entidades inmutables, Enums, DTOs y Servicios de Dominio (Haversine, SLA, Repuestos)
│   ├── Application/              # Casos de uso de autenticación, optimización de rutas, métricas y auditoría
│   ├── Infrastructure/           # Repositorios PDO, conexión DB, uploader de imágenes
│   └── Presentation/             # Router frontal, Middlewares RBAC y Controladores REST
├── public/                       # Raíz pública web (DocumentRoot)
│   ├── index.html                # Contenedor SPA del Frontend Vue.js
│   ├── index.php                 # Front Controller y despachador de assets
│   ├── docs/                     # Guías rápidas imprimibles para taller (A4, técnico de campo)
│   └── assets/
│       ├── css/
│       │   ├── design-tokens.css # Tokens CSS de Docker (#2560ff, tipografías, bordes, escalera --control-* de botones)
│       │   ├── metrics-print.css # Reglas de maquetación de informe A4 para impresión
│       │   └── qr-print.css      # Estilos de impresión de etiquetas QR industriales
│       └── js/
│           ├── app.js            # Montaje raíz Vue 3 con conmutador de perfiles
│           ├── api.js            # Cliente HTTP nativo fetch con descargas autenticadas
│           ├── store.js          # Almacén reactivo de sesión y alertas
│           ├── vendor/           # Vue 3 ESM embebido (sin CDN ni npm)
│           ├── utils/            # Cartografía Web Mercator pura (Mercator.js) y gestos (MapZoomPan.js)
│           ├── components/       # Componentes UI (MetricCards, RouteMapModal, TerritorialMapTab, etc.)
│           └── views/            # Vistas (CoordinatorDashboardView, TechnicianRouteView, etc.)
└── tests/
    ├── run_all.php               # Ejecutor global de la batería de 257 suites (100% verde)
    ├── bootstrap.php             # Autoloader compartido de las suites
    ├── Manual/                   # Ejecutor de verificación E2E manual (T-40)
    ├── unit/                     # Pruebas unitarias de lógica pura, geometría y contratos
    └── integration/              # Pruebas de persistencia real en MariaDB y API HTTP
```

---

## 📜 Licencia y Metodología
Proyecto desarrollado bajo la metodología **Specification-Driven Development (SDD)** conforme a la Constitución de VendGuard.
Octubre 2026.
