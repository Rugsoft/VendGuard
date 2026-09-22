# VendGuard · Gestor de Incidencias de Vending

[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B%20Vanilla%20POO-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net/)
[![Frontend](https://img.shields.io/badge/Vue.js%203-ES%20Modules%20(No%20Bundler)-4FC08D?style=flat-square&logo=vue.js&logoColor=white)](https://vuejs.org/)
[![Database](https://img.shields.io/badge/MariaDB-10.11%2B%20%7C%20MySQL%208.0-003545?style=flat-square&logo=mariadb&logoColor=white)](https://mariadb.org/)
[![Design System](https://img.shields.io/badge/Design%20System-Docker%20Tokens%20(%232560ff)-2496ED?style=flat-square&logo=docker&logoColor=white)](docs/design.md)
[![Tests Status](https://img.shields.io/badge/Tests-44%20Suites%20%7C%20926%20Pass%20(100%25)-38bd7d?style=flat-square)](tests/)
[![Constitutional Status](https://img.shields.io/badge/Constitution-Audited%20%26%20Certified-003db5?style=flat-square)](constitution.md)

**VendGuard** es un sistema web integral para la gestión, triaje, intervención técnica y trazabilidad de averías en parques de máquinas de vending (bebidas frías, calientes, snacks y comida perecedera).

El proyecto resuelve de raíz los cuatro grandes dolores operativos del sector:
1. **Pérdida de la cadena de frío y riesgo alimentario:** Detección inmediata y forzado de criticidad máxima (**CRÍTICA / Innegociable**) en máquinas con alimentos perecederos.
2. **Duplicidad de intervenciones técnicas:** Bloqueo preventivo a nivel atómico en base de datos para impedir que dos personas generen tickets independientes para la misma máquina.
3. **Cierre injustificado de averías:** Imposibilidad de dar por resuelto un ticket sin registrar un diagnóstico real (>= 20 caracteres) y una acción correctiva demostrable (>= 20 caracteres).
4. **Dispersión de canales y falta de trazabilidad:** Flujo unificado con borrado físico terminantemente prohibido (**Soft Delete** obligatorio), historial inmutable de cambios y ventana de garantía de 48 horas con detección de **Avería Crónica**.

---

## 🏛️ Filosofía de Ingeniería: Dogma Vanilla & Dualismo Lingüístico

El desarrollo de VendGuard se rige incondicionalmente por la [Constitución del Proyecto](constitution.md) y las directrices operativas de [AGENTS.md](AGENTS.md):

* **Dogma Vanilla (Cero Bloatware):**
  * **Backend:** PHP 8.2+ moderno puro con Programación Orientada a Objetos, separación en capas (*Clean Architecture*), tipado estricto en el 100% de ficheros (`declare(strict_types=1);`), y acceso a datos mediante **PDO** nativo. Cero dependencias externas (`composer.json` / `vendor/` ausentes).
  * **Frontend:** Vue.js 3 estándar (Composition API nativa) modularizado mediante **ES Modules** del navegador (`type="module"`), consumiendo directamente la API REST con `fetch()`. Cero empaquetadores o dependencias pesadas (`node_modules/` ausente en producción).
* **Dualismo Lingüístico Estricto:**
  * **Inglés:** Clases, métodos, variables, contratos de API, base de datos, suites de pruebas y commits bajo el estándar *Conventional Commits*.
  * **Castellano:** Toda la documentación de negocio, análisis funcional, mensajes de validación y diálogo de cara al usuario final.

---

## 👥 Actores del Sistema y Credenciales de Demostración

La aplicación web cuenta con un **conmutador interactivo de perfiles** en su cabecera superior para alternar de forma inmediata entre los 3 roles del flujo operativo:

| Perfil / Actor | Identificador / Email | Contraseña / Código | Función Principal |
| :--- | :--- | :--- | :--- |
| **🏢 1. Responsable de Sede** | `SEDE-BCN-01` *(Hospital del Mar)*<br>`SEDE-BCN-02` *(Torre Glòries)* | *N/A (Acceso por código de sede)* | Supervisa máquinas de su edificio, reporta averías guiadas (< 2 min), anexa fotos/comentarios y reabre incidencias en garantía de 48h. |
| **📊 2. Coordinador de Operaciones** | `coordinacion@vendguard.internal` | `Password123!` | Panel central de triaje, supervisión de alertas de SLA 24/7 (> 60m sin asignar), asignación a técnicos de ruta y descarte lógico (*Soft Delete*). |
| **📱 3. Técnico de Ruta de Campo** | `jordi.ruta@vendguard.internal` | `Password123!` | Interfaz vertical *mobile-first* optimizada para smartphone, inicio de intervención in situ, pausa por falta de repuestos y cierre técnico justificado. |

---

## 🚀 Puesta en Marcha Rápida (Local)

### Requisitos Previos
* **PHP:** Versión 8.2 o superior con extensiones `pdo_mysql`, `curl`, `mbstring` habilitadas.
* **Base de Datos:** MariaDB 10.5+ o MySQL 8.0+.
* **Navegador Web Moderno:** Chrome, Edge, Firefox o Safari con soporte de módulos ES.

### 1. Clonar el Repositorio y Configurar la Base de Datos
Crea la base de datos `vendguard_db` en tu servidor MariaDB/MySQL local e importa el esquema:

```sql
CREATE DATABASE IF NOT EXISTS `vendguard_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Ejecuta el script de esquema y carga de semillas iniciales:

```powershell
# En Windows (PowerShell) o Linux/macOS (Bash):
php -r "require 'src/autoload.php'; require 'src/Infrastructure/Database/ConnectionFactory.php'; require 'src/Infrastructure/Database/SeedRunner.php'; \$pdo = VendGuard\Infrastructure\Database\ConnectionFactory::getConnection(); \$s = new VendGuard\Infrastructure\Database\SeedRunner(\$pdo); \$s->runSqlFile('database/schema.sql'); \$s->seedAll(); echo 'Base de datos inicializada con éxito.';"
```

### 2. Iniciar el Servidor Web Local
Lanza el servidor integrado de PHP apuntando a la raíz pública:

```powershell
php -S 127.0.0.1:8000 -t public public/index.php
```

### 3. Abrir la Aplicación Web
Accede desde tu navegador a:
👉 **[http://127.0.0.1:8000/](http://127.0.0.1:8000/)**

---

## 🎨 Sistema de Diseño Visual (Docker Tokens)

La interfaz gráfica sigue estrictamente las directrices del [Manual de Diseño](docs/design.md) inspiradas en el sistema visual de **Docker**:
* **Paleta de Color:** Azul eléctrico de alto voltaje interactivo (`--color-primary: #2560ff`) sobre fondo canvas blanco/off-white (`--color-canvas: #f9fafb`), texto slate de alto contraste (`--color-slate: #2c333f`) y bordes hairline (`--color-hairline: #c8cfda`).
* **Tipografía Dual:** `DM Sans` para titulares y visualización; `Inter` para cuerpo, etiquetas, botones y formularios.
* **Bordes Conservadores ("Herramienta técnica, no juguete"):** 4px (`--radius-interactive`) en botones, inputs y chips; 8px (`--radius-card`) en tarjetas, tablas y modales.

---

## 🔌 Resumen de Endpoints de la API REST

Todos los endpoints responden bajo la envolvente estándar JSON (`{ success: true, data: ... }` o `{ success: false, error: ... }`):

| Método | Endpoint | Rol Autorizado | Descripción del Recurso |
| :--- | :--- | :--- | :--- |
| `GET` | `/` y `/api/health` | Público | Comprobación de salud operativa del servidor y versión. |
| `POST`| `/api/auth/site-login` | Público | Autenticación de responsables por código de sede (`site_code`). |
| `POST`| `/api/auth/login` | Interno | Autenticación RBAC para Coordinadores y Técnicos emitiendo Bearer token. |
| `GET` | `/api/locations/{code}/machines` | `LOCATION_MANAGER` | Catálogo de máquinas del centro con estado de avería activa. |
| `POST`| `/api/incidents` | `LOCATION_MANAGER` | Alta de avería con cálculo automático de urgencia y control de duplicados (409). |
| `POST`| `/api/incidents/{code}/comments`| `LOCATION_MANAGER` | Anexa evidencias y notas a una avería abierta sin alterar la foto original. |
| `POST`| `/api/incidents/{code}/reopen` | `LOCATION_MANAGER` | Reapertura dentro de garantía (< 48h), desasignando al técnico anterior. |
| `GET` | `/api/coordinator/incidents` | `COORDINATOR` | Listado global con filtros combinados, métricas y cálculo de SLA > 60m. |
| `PATCH`| `/api/coordinator/incidents/{id}/assign`| `COORDINATOR`| Asignación técnica a técnico de ruta (justificación obligatoria si hay cambio de urgencia). |
| `PATCH`| `/api/coordinator/incidents/{id}/cancel`| `COORDINATOR`| Cancelación lógica justificada (*Soft Delete* preservando BD). |
| `GET` | `/api/technician/my-route` | `TECHNICIAN` | Ruta móvil de tareas asignadas ordenada por criticidad. |
| `PATCH`| `/api/technician/incidents/{id}/start` | `TECHNICIAN` | Inicia intervención física in situ (pasa a `EN_CURSO` y fija `started_at`). |
| `PATCH`| `/api/technician/incidents/{id}/pause` | `TECHNICIAN` | Pausa por falta de piezas (exige descripción del repuesto requerido). |
| `POST`| `/api/technician/incidents/{id}/resolve`| `TECHNICIAN` | Resolución documentada (exige >= 20 chars en diagnóstico y >= 20 chars en solución). |
| `POST`| `/api/cron/auto-close` | Clave Cron | Cierre definitivo automático de incidencias en garantía > 48h. |

---

## 🧪 Batería de Pruebas Automatizadas y Calidad

VendGuard cuenta con una cobertura integral sin librerías externas mediante un ejecutor nativo:

```powershell
# 1. Ejecutar la batería completa (44 suites unitarias, dinámicas e integración):
php tests/run_all.php

# 2. Ejecutar el simulador del recorrido funcional E2E de los 3 perfiles:
php tests/Manual/E2EVerificationRunner.php

# 3. Ejecutar la auditoría constitucional automatizada (Artículos I al VII):
php tests/unit/ConstitutionalAuditTest.php
```

### Resultados de la Auditoría Final (T-41):
```text
======================================================================
 RESUMEN DE EJECUCIÓN GLOBAL (T-39 & T-41)
======================================================================
 Suites de pruebas PHP Unit : 18 / 18 pasadas
 Suites de pruebas JS Unit  : 7 / 7 pasadas
 Suites de Integración PHP  : 19 / 19 pasadas
 ──────────────────────────────────────────────────────────────────
 Total Suites Ejecutadas    : 44
 Total Aserciones Evaluadas : 926
 Fallos Detectados          : 0 (100% en verde)
 Borrado físico (DELETE)    : 0 ocurrencias en código de producción
 Tipado estricto            : 100% de ficheros PHP (91/91)
======================================================================
```

---

## 📂 Estructura del Repositorio

```text
gestor-incidencias-vending/
├── constitution.md               # Ley Suprema del proyecto (Rango Inviolable)
├── AGENTS.md                     # Directrices operativas de desarrollo y SDD
├── README.md                     # Esta guía maestra de entrega y operaciones
├── database/
│   └── schema.sql                # DDL MariaDB con índice atómico uq_machine_active_ticket
├── docs/
│   ├── design.md                 # Especificación de tokens visuales Docker
│   ├── manual_0_analisis_problema.md # Análisis de negocio y reglas de vending
│   ├── manual_e2e_verification.md    # Guion detallado de pruebas E2E
│   └── constitutional_audit_report.md# Dictamen formal de auditoría del MVP
├── specs/
│   ├── functional/
│   │   └── mvp_functional_spec.md    # Especificación EARS de requisitos RF y RNF
│   └── technical/
│       ├── api_contracts.md      # Contratos JSON y esquemas de petición/respuesta
│       ├── database_schema.md    # Modelo relacional y claves foráneas
│       ├── plan.md               # Plan Maestro de Ingeniería
│       └── tasks.md              # Matriz de 41 tareas (T-01 a T-41, 100% completadas)
├── src/
│   ├── Core/                     # Entidades inmutables, Enums, DTOs y Servicios de Dominio
│   ├── Application/              # Casos de uso de autenticación y lógica de negocio
│   ├── Infrastructure/           # Repositorios PDO, conexión MariaDB, uploader de imágenes
│   └── Presentation/             # Router frontal, Middlewares RBAC y Controladores REST
├── public/                       # Raíz pública web (DocumentRoot)
│   ├── index.html                # Contenedor SPA del Frontend Vue.js
│   ├── index.php                 # Front Controller de la API REST
│   └── assets/
│       ├── css/design-tokens.css # Tokens CSS de Docker (variables, botones, inputs)
│       └── js/
│           ├── app.js            # Montaje raíz Vue 3 con conmutador de perfiles
│           ├── api.js            # Cliente HTTP nativo fetch
│           ├── store.js          # Almacén reactivo de sesión y alertas
│           ├── components/       # Componentes UI (Navbar, Badges, Modales, Cards)
│           └── views/            # Vistas (LocationPortalView, CoordinatorDashboardView, TechnicianRouteView)
└── tests/
    ├── run_all.php               # Ejecutor automatizado de toda la batería de tests
    ├── Manual/E2EVerificationRunner.php # Verificador E2E automatizado de los 3 perfiles
    ├── unit/                     # Pruebas unitarias de lógica pura y contratos
    └── integration/              # Pruebas de persistencia real en MariaDB y API HTTP
```

---

## 📜 Licencia y Autoría
Proyecto desarrollado bajo la metodología **Specification-Driven Development (SDD)** conforme a la especificación funcional y técnica del MVP de VendGuard.
Septiembre 2026.
