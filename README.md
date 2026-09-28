# VendGuard · Gestor de Incidencias de Vending

[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B%20Vanilla%20POO-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net/)
[![Frontend](https://img.shields.io/badge/Vue.js%203-ES%20Modules%20(No%20Bundler)-4FC08D?style=flat-square&logo=vue.js&logoColor=white)](https://vuejs.org/)
[![Database](https://img.shields.io/badge/MariaDB-10.11%2B%20%7C%20MySQL%208.0-003545?style=flat-square&logo=mariadb&logoColor=white)](https://mariadb.org/)
[![Design System](https://img.shields.io/badge/Design%20System-Docker%20Tokens%20(%232560ff)-2496ED?style=flat-square&logo=docker&logoColor=white)](docs/design.md)
[![Tests Status](https://img.shields.io/badge/Tests-120%20Suites%20%7C%203.792%20Pass%20(100%25)-38bd7d?style=flat-square)](tests/)
[![Constitutional Status](https://img.shields.io/badge/Constitution-Audited%20%26%20Certified-003db5?style=flat-square)](constitution.md)

**VendGuard** es una plataforma web integral de nivel industrial para la gestión, triaje, intervención técnica, métricas de SLA y auditoría inmutable de averías en parques de máquinas de vending (bebidas calientes, frías, snacks y comida perecedera).

El sistema erradica de raíz los problemas críticos del sector vending mediante cinco pilares:
1. **Preservación de la cadena de frío (Art. II Constitución):** Detección inmediata y forzado de prioridad máxima (**CRÍTICA / Innegociable**) en máquinas con alimentos perecederos con objetivo estricto de SLA < 4.0 horas.
2. **Prevención atómica de duplicados:** Índice atómico en base de datos (`uq_machine_active_ticket`) que imposibilita la creación de tickets concurrentes para una misma máquina.
3. **Justificación obligatoria de intervenciones (Art. V.1):** Prohibición terminante de resolver incidencias sin registrar diagnóstico técnico real ($\ge 20$ caracteres) y acción correctiva demostrable ($\ge 20$ caracteres).
4. **Trazabilidad y auditoría permanente (Art. III):** Borrado físico estrictamente prohibido (*Soft Delete* obligatorio) y registro inmutable *Append-Only* (`audit_log`) con ventana de garantía de 48 horas y detección de averías crónicas.
5. **Trazabilidad económica de repuestos (Módulo M2):** Catálogo maestro por modelo de máquina con *snapshot* inmutable de coste en cada intervención, clasificación cerrada de destino (`DESGUACE`/`TALLER`) y segregación estricta de datos de piezas y costes para el Responsable de Sede (Art. V.4).

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
* **Vista Móvil "Mi Ruta" para Técnicos:** Interfaz vertical optimizada para smartphone (uso con una sola mano), inicio de intervención in situ, pausa estructurada por repuestos, resolución técnica justificada con declaración de piezas sustituidas y consulta de métricas individuales.
* **Cron Automatizado de Garantía:** Cierre definitivo de tickets resueltos transcurridas 48h sin reclamaciones (`/api/cron/auto-close`).

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
* **Desglose Multidimensional Combinable:** Análisis cruzado por Sede, Técnico resolutor, Tipología de máquina y Categoría de avería con detección de entidades inactivas.
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
* **CRUD de Personal Interno:** Altas de coordinadores y técnicos con operador oficial, reseteo de contraseñas y bajas lógicas.

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

---

## 👥 Actores del Sistema y Credenciales de Demostración

La aplicación cuenta con un conmutador de perfiles en la barra superior para alternar de forma inmediata entre roles:

| Perfil / Actor | Identificador / Email | Contraseña / Código | Función Principal |
| :--- | :--- | :--- | :--- |
| **🏢 1. Responsable de Sede** | `SEDE-BCN-01` *(Hospital del Mar)*<br>`SEDE-BCN-02` *(Torre Glòries)* | *N/A (Acceso por código de sede)* | Supervisa máquinas del edificio, reporta averías (< 2 min), anexa evidencias y reabre en garantía. |
| **📊 2. Coordinador de Operaciones** | `coordinacion@vendguard.internal` | `Password123!` | Triaje central, supervisión 24/7 de SLAs, administración del parque y personal, preventivos y certificados sanitarios, catálogo y analítica de repuestos, cuadro de mando de métricas y visor de auditoría. |
| **📱 3. Técnico de Ruta de Campo** | `jordi.ruta@vendguard.internal`<br>`marta.ruta@vendguard.internal` | `Password123!` | Interfaz vertical *mobile-first*, gestión de averías en ruta, inicio de intervención, pausa estructurada por repuestos, resolución justificada con piezas sustituidas, inspecciones preventivas y consulta de métricas individuales. |
| **🤳 4. Usuario / Consumidor Final** | Enlace QR (`?code=VEND-0101`) | *Público / Sin registro* | Reporte inmediato in situ escaneando la pegatina de la máquina, con feedback de estado si ya estaba reportada. |

---

## 🚀 Puesta en Marcha Rápida

### Requisitos Previos
* **PHP:** 8.2 o superior con extensiones `pdo_mysql`, `mbstring`, `curl` habilitadas.
* **Base de Datos:** MySQL 8.0+ o MariaDB 10.5+ (local, Docker o Cloud como TiDB Serverless).
* **Navegador:** Chrome, Edge, Firefox o Safari con soporte de módulos ES.

### 1. Configuración de Base de Datos y Semillas

#### Entorno Local (MariaDB / MySQL):
```powershell
# Crear la base de datos:
mysql -u root -e "CREATE DATABASE IF NOT EXISTS vendguard_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Opción recomendada: migrador DDL idempotente (esquema base + migraciones 003-006
# de auditoría, preventivos y repuestos, con verificación de integridad final):
php bin/migrate.php

# Cargar semillas (sedes, máquinas, usuarios, preventivos y catálogo de repuestos):
php -r "require 'src/autoload.php'; require 'src/Infrastructure/Database/ConnectionFactory.php'; require 'src/Infrastructure/Database/SeedRunner.php'; \$pdo = VendGuard\Infrastructure\Database\ConnectionFactory::getConnection(); \$s = new VendGuard\Infrastructure\Database\SeedRunner(\$pdo); \$s->seedAll(); echo 'Base de datos inicializada.';"

# Sembrar histórico representativo de métricas, reparaciones y log de auditoría:
php bin/seed_demo_metrics.php
```

#### Entorno Cloud / Producción (Render, TiDB Cloud, Aiven):
El script [`bin/init_cloud_db.php`](bin/init_cloud_db.php) ejecuta automáticamente el esquema DDL y siembra todas las sedes, máquinas, técnicos, averías históricas y eventos de auditoría de forma tolerante e idempotente:
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

---

## 🔌 Resumen de Endpoints de la API REST

Todas las respuestas cumplen con la envolvente canónica JSON (`{ success: true, data: ... }` o `{ success: false, error: ... }`):

| Método | Endpoint | Rol Autorizado | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `GET` | `/` y `/api/health` | Público | Comprobación de salud operativa y estado del sistema. |
| `POST`| `/api/auth/site-login` | Público | Acceso de responsables mediante código de sede (`site_code`). |
| `POST`| `/api/auth/login` | Interno | Login para personal interno emitiendo token Bearer criptográfico. |
| `GET` | `/api/locations/{code}/machines` | `LOCATION_MANAGER` | Catálogo de máquinas del centro con estado de ticket activo. |
| `POST`| `/api/incidents` | `LOCATION_MANAGER` | Registro de avería con cálculo de urgencia y prevención de duplicados (409). |
| `POST`| `/api/incidents/{code}/comments`| `LOCATION_MANAGER` | Anexa comentarios o evidencias fotográficas a la bitácora. |
| `POST`| `/api/incidents/{code}/reopen` | `LOCATION_MANAGER` | Reapertura dentro de garantía (< 48h) desasignando al técnico. |
| `GET` | `/api/coordinator/incidents` | `COORDINATOR` | Listado global con filtros combinados y evaluación de SLA > 60m. |
| `PATCH`| `/api/coordinator/incidents/{id}/assign`| `COORDINATOR`| Asignación técnica a técnico de ruta (justificación si varía urgencia). |
| `PATCH`| `/api/coordinator/incidents/{id}/cancel`| `COORDINATOR`| Descarte lógico justificado (*Soft Delete*). |
| `GET` | `/api/coordinator/locations` | `COORDINATOR` | Listado de sedes con total de máquinas y averías activas. |
| `GET` | `/api/coordinator/locations/{id}/machines` | `COORDINATOR` | Parque de máquinas de una sede con estado operativo. |
| `GET` | `/api/coordinator/machines/{id}/qr-label` | `COORDINATOR` | Datos y SVG vectorial de etiqueta QR para máquina. |
| `GET` | `/api/coordinator/locations/{id}/qr-batch` | `COORDINATOR` | Lote completo de etiquetas QR de una sede para impresión masiva. |
| `GET` | `/api/coordinator/metrics/summary` | `COORDINATOR` | KPIs globales, MTTR, comparativa de tendencia y alertas de SLA. |
| `GET` | `/api/coordinator/metrics/breakdown` | `COORDINATOR` | Desglose multidimensional (sedes, técnicos, máquinas, averías). |
| `GET` | `/api/coordinator/metrics/export` | `COORDINATOR` | Descarga CSV con codificación UTF-8 BOM de métricas agregadas. |
| `GET` | `/api/coordinator/audit-log` | `COORDINATOR` | Consulta cronológica paginada y filtrable del registro inmutable. |
| `GET` | `/api/coordinator/audit-log/export` | `COORDINATOR` | Descarga CSV del registro inmutable (máx. 10.000 filas). |
| `GET` | `/api/technician/my-route` | `TECHNICIAN` | Hoja de ruta móvil ordenada por criticidad. |
| `GET` | `/api/technician/my-metrics` | `TECHNICIAN` | Autoconsulta individual protegida de rendimiento (Art. V.4). |
| `PATCH`| `/api/technician/incidents/{id}/start` | `TECHNICIAN` | Inicio de intervención física in situ (`started_at`). |
| `PATCH`| `/api/technician/incidents/{id}/pause` | `TECHNICIAN` | Pausa estructurada por repuestos (`PENDING_PARTS`) con selección compatible o pieza fuera de catálogo. |
| `POST`| `/api/technician/incidents/{id}/resolve`| `TECHNICIAN` | Resolución técnica documentada ($\ge 20$ chars diagnóstico y acción). |
| `POST`| `/api/cron/auto-close` | Clave Cron | Cierre definitivo automático tras 48h de garantía. |
| `GET` | `/api/qr/scan/{code}` | Público | Consulta ciudadana de estado de máquina por código QR. |
| `POST`| `/api/qr/report` | Público | Envío directo de incidencia ciudadana desde lectura QR. |

#### Administración Integral (Módulo 04, rol `COORDINATOR`)
| Método | Endpoint | Descripción del Recurso |
| :--- | :--- | :--- |
| `GET/POST/PATCH` | `/api/coordinator/locations[/{id}][/deactivate\|/reactivate]` | CRUD de sedes clientes con bajas lógicas. |
| `GET/POST/PATCH` | `/api/coordinator/machines[/{id}][/transfer\|/deactivate\|/reactivate]` | CRUD de máquinas con transferencias auditadas entre sedes. |
| `GET/POST/PATCH` | `/api/coordinator/users[/{id}][/reset-password\|/deactivate\|/reactivate]` | CRUD de personal interno con reseteo de credenciales. |

#### Mantenimiento Preventivo y Certificación Sanitaria (Módulo M1)
| Método | Endpoint | Rol | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/coordinator/preventive/dashboard` | `COORDINATOR` | Cuadro de vencimientos y alertas de inspección por tipología. |
| `GET/POST` | `/api/coordinator/preventive/orders` | `COORDINATOR` | Listado y creación de órdenes preventivas. |
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
| `PATCH` | `/api/coordinator/spare-parts/{id}/status` | `COORDINATOR` | Baja lógica y reactivación sin borrado físico (Art. III). |
| `GET` | `/api/coordinator/spare-parts/analytics` | `COORDINATOR` | Ranking de piezas, costes por modelo/sede y alertas de fallo crónico. |
| `GET` | `/api/coordinator/spare-parts/export` | `COORDINATOR` | Exportación CSV de consumos con snapshots congelados. |
| `GET` | `/api/coordinator/spare-parts/requests/pending-review` | `COORDINATOR` | Bandeja de piezas fuera de catálogo pendientes de homologación. |
| `GET` | `/api/technician/spare-parts/catalog?machine_id={id}` | `TECHNICIAN` | Catálogo compatible con la máquina intervenida (< 250 ms). |
| `PATCH` | `/api/technician/incidents/{id}/pause` | `TECHNICIAN` | Pausa estructurada con `requested_parts` (1–50 uds.) o pieza fuera de catálogo. |
| `POST` | `/api/technician/incidents/{id}/resolve` | `TECHNICIAN` | Resolución con `replaced_parts_declared`, destino `DESGUACE`/`TALLER` y snapshot de coste. |
| `POST` | `/api/technician/preventive/orders/{id}/complete` | `TECHNICIAN` | Cierre preventivo con registro opcional de piezas sustituidas. |

---

## 🧪 Batería Completa de Pruebas Automatizadas (T-39 & Módulos 02, 03, 04, M1 y M2)

La integridad de VendGuard está certificada mediante un ejecutor de pruebas automatizado nativo en 3 fases continuas:

```powershell
# Ejecutar la suite completa (Unitarias PHP, Unitarias JS e Integración PHP):
php tests/run_all.php
```

### Resumen de Ejecución Global (Septiembre 2026, cierre del Módulo M2):
```text
======================================================================
 RESUMEN DE EJECUCIÓN GLOBAL (120 Suites / 3.792 Aserciones)
======================================================================
 Suites de pruebas PHP Unit : 52 / 52 pasadas (100%)
 Suites de pruebas JS Unit  : 30 / 30 pasadas (100%)
 Suites de Integración PHP  : 38 / 38 pasadas (100%)
 ──────────────────────────────────────────────────────────────────
 Total Suites Ejecutadas    : 120
 Total Aserciones Evaluadas : 3.792
 Fallos Detectados          : 0 (100% en verde)
 Base de datos restablecida : SÍ (Semillas intactas)
======================================================================
 RESULTADO: 100% EN VERDE. (0 errors, 0 failures)
 CONDICIÓN DE CALIDAD Y CERTIFICACIÓN CONSTITUCIONAL CUMPLIDA.
======================================================================
```

Entre las suites de certificación destacan:
* **`ConstitutionalAuditTest`** — auditoría automática de los Artículos I al VII (cero `DELETE FROM` en producción, tipado estricto, tokens visuales Docker).
* **`SiteManagerPartsDataSegregationTest` (T-SPARE-20)** — blindaje del Art. V.4: ningún endpoint del portal de sede expone piezas, destinos ni costes.
* **`SparePartsModuleComplianceTest` (T-SPARE-21)** — verificación global de regresión, Dogma Vanilla (cero dependencias npm/composer) y Dualismo Lingüístico.
* **`CoordinatorSparePartsApiTest` / `TechnicianSparePartsApiTest` (T-SPARE-18/19)** — integración HTTP del ciclo completo de repuestos con congelación inmutable de costes.

> **Nota operativa:** las suites de integración incluyen pruebas de HTTP real contra `127.0.0.1:8000`. Si el puerto está ocupado por un servidor obsoleto, arránquelo antes con `php -S 127.0.0.1:8000 -t public public/index.php`.

---

## 📂 Estructura del Repositorio

```text
gestor-incidencias-vending/
├── constitution.md               # Ley Suprema del proyecto (Artículos I al VII)
├── AGENTS.md                     # Directrices operativas de desarrollo y SDD
├── Dockerfile                    # Contenedor para despliegues cloud en Render
├── README.md                     # Esta guía maestra de entrega y operaciones
├── bin/
│   ├── init_cloud_db.php         # Inicializador automático para MySQL/TiDB Cloud
│   ├── migrate.php               # Migrador DDL idempotente (esquema + migraciones 003-006)
│   ├── seed.php                  # Sembrador CLI del catálogo base
│   └── seed_demo_metrics.php     # Sembrador de métricas históricas y auditoría
├── database/
│   ├── schema.sql                # DDL MariaDB del núcleo operativo
│   ├── cloud_init.sql            # Script unificado integral para despliegues cloud
│   ├── migrations/               # DDL incrementales (003 auditoría, 004 CRUD, 005 preventivos, 006 repuestos)
│   └── DemoMetricsSeeder.php     # Generador de histórico de averías y auditoría
├── docs/
│   ├── design.md                 # Especificación de tokens visuales Docker
│   ├── manual_0_analisis_problema.md # Análisis de negocio y reglas de vending
│   ├── manual_e2e_verification.md    # Guion de verificación E2E (T-40)
│   ├── manual_modulo_m2_repuestos.md # Manual de usuario del Módulo M2 (coordinador y técnico)
│   └── constitutional_audit_report.md# Dictamen formal de auditoría
├── specs/
│   ├── 02-qr-codes/              # Especificación del módulo de códigos QR
│   ├── 03-metrics-audit/         # Especificación del módulo de métricas y auditoría
│   ├── 04-admin-crud/            # Especificación del panel de administración integral
│   ├── 05-preventive-maintenance/ # Especificación del mantenimiento preventivo y certificación sanitaria (M1)
│   ├── 06-spare-parts/           # Especificación del catálogo de repuestos y trazabilidad (M2)
│   ├── functional/               # Especificaciones funcionales EARS transversales (núcleo MVP, admin, preventivos, repuestos)
│   └── technical/                # Contratos de API, DDL y esquema de base de datos
├── src/
│   ├── Core/                     # Entidades inmutables, Enums, DTOs y Servicios de Dominio
│   ├── Application/              # Casos de uso de autenticación, métricas y auditoría
│   ├── Infrastructure/           # Repositorios PDO, conexión DB, uploader de imágenes
│   └── Presentation/             # Router frontal, Middlewares RBAC y Controladores REST
├── public/                       # Raíz pública web (DocumentRoot)
│   ├── index.html                # Contenedor SPA del Frontend Vue.js
│   ├── index.php                 # Front Controller y despachador de assets
│   └── assets/
│       ├── css/
│       │   ├── design-tokens.css # Tokens CSS de Docker (#2560ff, tipografías, bordes)
│       │   ├── metrics-print.css # Reglas de maquetación de informe A4 para impresión
│       │   └── qr-print.css      # Estilos de impresión de etiquetas QR industriales
│       └── js/
│           ├── app.js            # Montaje raíz Vue 3 con conmutador de perfiles
│           ├── api.js            # Cliente HTTP nativo fetch con descargas autenticadas
│           ├── store.js          # Almacén reactivo de sesión y alertas
│           ├── vendor/           # Vue 3 ESM embebido (sin CDN ni npm)
│           ├── components/       # Componentes UI (MetricCards, AuditLogViewer, SpareParts, etc.)
│           └── views/            # Vistas (CoordinatorDashboardView, TechnicianRouteView, etc.)
└── tests/
    ├── run_all.php               # Ejecutor global de la batería de 120 suites
    ├── bootstrap.php             # Autoloader compartido de las suites
    ├── Manual/                   # Ejecutor de verificación E2E manual (T-40)
    ├── unit/                     # Pruebas unitarias de lógica pura y contratos
    └── integration/              # Pruebas de persistencia real en MariaDB y API HTTP
```

---

## 📜 Licencia y Metodología
Proyecto desarrollado bajo la metodología **Specification-Driven Development (SDD)** conforme a la Constitución de VendGuard.
Septiembre 2026.
