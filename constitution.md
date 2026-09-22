# CONSTITUCIÓN DEL PROYECTO · VENDGUARD
**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Estatus:** Ley Suprema del Repositorio (Rango Inviolable)  
**Autoridad de Enmienda:** Exclusiva del Product Owner (Usuario)  
**Fecha de Ratificación:** Septiembre 2026  

---

## Preámbulo

Esta Constitución establece los principios fundamentales, éticos, arquitectónicos y operativos que rigen el diseño, desarrollo y mantenimiento del sistema **VendGuard**. Sus preceptos tienen rango supremo sobre cualquier directriz operativa (`AGENTS.md`), especificación técnica (`specs/`) o instrucción temporal de desarrollo. 

Ningún agente de inteligencia artificial, colaborador ni desarrollador podrá suspender, eludir ni contradecir los artículos aquí consagrados.

---

## Artículo I · Supremacía de la Especificación (Dogma SDD)

1. **La Especificación es la Única Fuente de Verdad:** Ninguna línea de código de producción (`src/`) podrá ser creada ni modificada sin que exista previamente una especificación formal aprobada en `specs/`.
2. **Rechazo a la Deuda Técnica por Prisas:** La rapidez nunca justificará atajos técnicos, funciones sin documentar ni código carente de pruebas. Todo código debe nacer tipado, probado y documentado.
3. **Inadmisibilidad de Código Simulado en Rutas Reales:** Queda terminantemente prohibido incorporar "mocks", stubs o comportamientos ficticios en los flujos principales de producción para aparentar que una funcionalidad está terminada.

---

## Artículo II · Principio de Precaución y Seguridad Alimentaria

1. **Prioridad Sanitaria Absoluta:** En las máquinas dispensadoras de alimentos perecederos (sándwiches, ensaladas, lácteos), la detección de fallos térmicos o rotura de cadena de frío ostenta la máxima prioridad del sistema (**Criticidad Innegociable**).
2. **Prevalencia de la Salud sobre la Facturación:** La protección de la salud del consumidor final prevalece ante cualquier avería comercial, pérdida de recaudación monetaria o inconveniente logístico.

---

## Artículo III · Inviolabilidad de los Datos y Trazabilidad Histórica

1. **Prohibición del Borrado Físico (*Hard Delete*):** Queda terminantemente prohibido ejecutar sentencias de eliminación destructiva (`DELETE FROM`) sobre entidades maestras y operativas (máquinas, usuarios, incidencias, intervenciones técnicas y evidencias).
2. **Trazabilidad por Estados y Borrado Lógico (*Soft Delete*):** Cualquier anulación, baja o descarte se gestionará exclusivamente mediante cambios de estado lógicos (`status = 'cancelled'`, `is_active = false`, etc.).
3. **Auditabilidad Completa:** El sistema debe conservar el historial inmutable de qué sucedió, quién intervino, qué pieza se reparó y cuándo se produjo cada cambio de estado.

---

## Artículo IV · Minimalismo Tecnológico y Cero Bloatware

1. **Backend en PHP Puro y Elegante:** El backend se construirá exclusivamente en **PHP 8+ moderno puro (POO)**, con tipado estricto obligatorio (`declare(strict_types=1);`), arquitectura desacoplada y acceso a datos mediante **PDO**.
2. **Frontend en Vue.js Estándar:** La capa cliente se implementará en **Vue.js 3 estándar (Composition API)** con diseño *mobile-first*, limpio y responsivo.
3. **Aversión a Dependencias Externas:** Queda prohibida la instalación de librerías, dependencias de terceros o paquetes no autorizados explícitamente. Toda solución debe construirse aprovechando las capacidades nativas del lenguaje salvo autorización formal previa.

---

## Artículo V · Integridad Inviolable de las Reglas de Negocio

Ninguna implementación podrá flexibilizar ni omitir las siguientes reglas operativas:

1. **Cierre Obligatoriamente Justificado:** Es imposible dar por "Resuelta" o "Cerrada" una avería sin registrar de forma obligatoria el diagnóstico real del fallo y la solución técnica ejecutada.
2. **Detección Preventiva de Duplicados:** El sistema debe comprobar y advertir activamente de avisos abiertos preexistentes sobre una misma máquina para impedir duplicidades de ruta técnica.
3. **Asignación Única:** Una incidencia solo puede tener un único técnico asignado responsable simultáneo.
4. **Privacidad y Segregación de Datos:** Los usuarios informadores (responsables de ubicación) jamás tendrán acceso a notas internas de taller, teléfonos personales de los técnicos ni costes económicos de los repuestos.
5. **Seguridad en la Carga de Archivos:** Las imágenes adjuntas nunca superarán los 5 MB y su formato estará restringido a tipos gráficos seguros (`.jpg`, `.jpeg`, `.png`, `.webp`), verificando su cabecera real en el servidor.
6. **Ventana de Reapertura:** La reapertura de incidencias queda limitada formalmente a las 48 horas posteriores a su resolución.

---

## Artículo VI · Delimitación Sagrada del Alcance (Anti-Feature Creep)

1. **Foco Exclusivo en el MVP (Fase 1):** Queda prohibido escribir código o diseñar esquemas para funcionalidades reservadas a fases posteriores (telemetría telemática IoT/MDB, gestión de inventario de furgonetas o devoluciones monetarias complejas) mientras el MVP no esté 100% operativo y formalmente aprobado.
2. **Respeto a la Planificación:** Cualquier nueva idea o sugerencia no contemplada en el análisis original del Manual 0 debe canalizarse hacia el backlog de fases futuras, sin entorpecer la finalización de la primera versión funcional.

---

## Artículo VII · Cláusula de Enmienda Constitucional

1. **Inmutabilidad para los Agentes:** Los agentes de inteligencia artificial y herramientas automatizadas tienen **estrictamente prohibido editar o modificar este documento por iniciativa propia**.
2. **Exclusividad del Product Owner:** Solo el Product Owner (usuario) podrá dictar enmiendas o modificaciones a esta Constitución mediante instrucción formal expresa.
3. **Veto Automático:** Cualquier propuesta o código generado que viole cualquiera de los artículos de esta Constitución queda declarado **nulo de pleno derecho** y deberá ser descartado de inmediato.
