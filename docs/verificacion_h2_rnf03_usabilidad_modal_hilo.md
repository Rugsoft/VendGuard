# Verificación H-2 · RNF-03 (Ergonomía móvil y de escritorio) del hilo de comentarios

**Módulo:** 10 · Hilo de comentarios bidireccional (T-COM-09 a T-COM-12)
**Requisito verificado:** `RNF-03 (Ergonomía Móvil y Escritorio)` de [spec.md](../specs/10-incident-comments/spec.md)
**Hallazgo que cierra:** H-2 (ausencia de verificación de viewport móvil/escritorio)
**Fecha:** 07/10/2026 · **Rama:** `incident-comments`

---

## 1. Criterio y alcance

> *"La interfaz del modal DEBE ser completamente responsiva (mobile-first), diseñada para un uso cómodo con una sola mano en los smartphones de los técnicos de ruta y adaptada a resoluciones de escritorio para la sede y coordinación."*

El cierre de H-2 exige **evidencia observada en un navegador real**, no una inferencia a partir del marcado.
Se instrumentaron cuatro escenarios de medición por geometría (`getBoundingClientRect`) más captura de pantalla:
móvil 390×844 (smartphone de una mano), móvil 390×500 (altura útil reducida por teclado virtual),
escritorio 1440×900 en el canal de **Coordinación** y escritorio 1440×900 en el canal de **Responsable de Sede**.
Los tres canales usan la misma instancia de `IncidentCommentThreadModal`, de modo que la medición cubre los tres puntos de acceso de RF-01.1.

---

## 2. Método y entorno

| Elemento | Detalle |
|---|---|
| Aplicación | Servidor de desarrollo del proyecto (`router.php`) en `http://127.0.0.1:8000` |
| Navegador | Chromium del panel de previsualización (viewport emulado, DPR 1) |
| Medición | `preview_evaluate` sobre el DOM vivo: contenedor modal, cabecera, cuerpo con scroll, pie, botonera y zona de pulgar |
| Capturas | `preview_screenshot` en cada viewport (véase §7) |
| Escenario | Incidencia `INC-2026-LLN5` (`VEND-0101 · Sanden Vendo G-Drink`, Sede Hospital del Mar - Edificio Central) con **7 mensajes**: 4 públicos (Sede y Técnico) y 3 notas internas (Coordinación y Técnico) |
| Datos | Creados por la API real con los tres perfiles; **borrados al cerrar** la verificación (la batería restauró las semillas canónicas: 13 incidencias, 0 comentarios) |

Zona de pulgar (alcance cómodo con el pulgar en una mano): franja inferior de la pantalla y cuadrante derecho.

---

## 3. Móvil · 390×844 (escenario principal)

| Magnitud | Valor medido |
|---|---|
| Modal (caja) | 342 × 725,8 px, x=24, y=59,1; **completamente dentro del viewport** |
| Contenedor de fondo | `position: fixed`, 390 × 844, centrado (`align-items: center`) |
| Elementos que desbordan la caja del modal | **0** |
| Cabecera (tras la corrección) | 340 × 102,4 px, dos líneas, sin solapes |
| Cuerpo con scroll | `clientHeight` 424 px sobre `scrollHeight` 877 px; **posición = fondo** (auto-scroll RF-01.2) |
| Mensajes renderizados | 6 de 6 en la primera página + 1 tras publicar |
| Pie de composición | 340 × 230,5 px, íntegro en pantalla |
| **Enviar** | 90 × 36 px, x=259–349, y=731,9–767,9 → **76,1 px sobre el borde inferior**, centro al **77,9 % del ancho** (zona de pulgar) |
| Adjuntar foto / Cerrar / Enviar | misma franja inferior (y=731,9–767,9), separación ≥ 8 px |
| Selector de privacidad | «Nota Interna de Taller» preseleccionada, radios con etiqueta de 246 × 16,8 px y 203 × 16,8 px |
| Contador | «1000 caracteres restantes» → «926» al escribir 74 caracteres |

> **Nota posterior (H-4).** Al incorporar el botón «Hacer foto» ([verificacion_h4_rf04_1_captura_camara.md](verificacion_h4_rf04_1_captura_camara.md))
> el compositor pasó de 230,5 px a 276,5 px de alto y la barra envuelve en dos filas: el grupo de fotos arriba
> (122,1 px al borde inferior) y las acciones abajo. La medición de la acción principal se repitió sobre el cambio:
> **Enviar** sigue a 76,1 px del borde inferior y 41,0 px del derecho (centro al 77,9 % del ancho), y la tarjeta
> mantiene 0 desbordes. Las cifras de las tablas de arriba corresponden al estado verificado en su momento.

**Prueba de interacción a una mano (pulgar, sin mover la mano):** pulsación en el textarea → escritura de
*«Prueba de uso a una mano en móvil: termostato sustituido, enfría de nuevo.»* → `Enviar` se habilita (permanece deshabilitado con el campo vacío) →
**un solo toque** en el botón inferior derecho publica el mensaje: el hilo pasa a 7 mensajes con autoscroll al último,
el compositor se vacía, el contador vuelve a 1000 y la privacidad se restablece a «Nota Interna» (RF-03.4).
Al cerrar, la insignia de la parada se refresca a **«Conversación de la parada: 7 mensajes»** (RF-01.1) y el scroll del fondo queda liberado.

---

## 4. Móvil · 390×500 (altura útil reducida por teclado virtual)

| Magnitud | Valor medido |
|---|---|
| Modal (caja) | 342 × 430 px, y=35 → **sigue completo dentro del viewport** |
| Cabecera (build corregido) | 102,4 px, y=36–138,4 (dos líneas, sin solapes) |
| Pie de composición | 175 px, y=289–464 → **cabecera y pie visibles a la vez** |
| Cuerpo con scroll | 150,7 px de alto útil, con scroll propio; sin desbordes |
| **Enviar** | 259–349 × 412–448 → **52 px sobre el borde inferior** (franja del pulgar) |
| Elementos que desbordan la caja del modal | **0** |

El modal absorbe la reducción de altura cediendo espacio al cuerpo con scroll y manteniendo intactos
cabecera y compositor: la acción principal nunca queda oculta tras el teclado. La corrección de la cabecera
(§6) solo añade altura al bloque superior, que absorbe el cuerpo desplazable; la botonera está ancorada al borde
inferior del modal, de modo que la distancia de 52 px al borde se mantiene y la alcanzabilidad del pulgar no cambia.

> Nota menor (no normativa): al reducirse el viewport el hilo conserva su posición de scroll, de modo que el
> mensaje más reciente puede quedar fuera de la vista hasta que el usuario desplaza. La especificación solo exige
> autoscroll **al abrir** el hilo (RF-01.2), por lo que no se considera desviación.

---

## 5. Escritorio · 1440×900

| Magnitud | Coordinación | Responsable de Sede |
|---|---|---|
| Caja del modal | **680 × 760 px**, x=380, y=70 | **680 × 760 px**, x=380, y=70 |
| Centrado horizontal | Sí (desvío < 1 px) | Sí (desvío < 1 px) |
| Cabecera | Una sola línea de 69 px | Una sola línea de 69 px |
| Truncado de máquina/sede | Ninguno (207,9 px y 174,9 px de contenido) | Ninguno |
| Cuerpo con scroll | 487 px sobre 722 px, en el fondo | Ídem |
| Desbordes internos | 0 | 0 |
| Ancho máximo respetado | `min(680px, 100%)` ✔ | `min(680px, 100%)` ✔ |
| Contenido propio del canal | 7 mensajes: burbujas públicas (canvas) y 3 ámbar «Nota Interna de Taller (Confidencial)» | **4 mensajes públicos**, técnico enmascarado como `Servicio Técnico Oficial (Operador #02)`, **0 etiquetas internas**, sin rastro textual de «Interna/Confidencial» ni de la coordinadora, sin selector de privacidad |

Comprobación cruzada visible de RF-01.1/RF-02.1/RNF-01: la insignia de la tarjeta de máquina en el portal de Sede
marcaba **«Conversación (4)»** frente a los **7** totales del canal interno.

---

## 6. Defecto detectado y corregido durante la verificación

**Síntoma (390 px):** la cabecera imprimía el nombre de la máquina **encima** del código de ticket y parte del
texto se salía de la tarjeta.

Causa medida (estado previo):

| Elemento | Caja medida | Problema |
|---|---|---|
| `.modal-header` | 340 × 69 px, `flex-wrap: nowrap` (heredado del sistema) | Las dos columnas se comprimen en lugar de apilarse |
| Código de ticket | x=45,0 – 156,3 | Recibe encima el texto de contexto |
| Columna de contexto | ancho útil 36,9 px | Sus hijos no caben y **se desbordan por la izquierda** |
| Etiquetas máquina / sede | `max-width: 260px` **fijo** > columna disponible | La máquina se dibujaba desde **x=5,7 px**, fuera del modal (x=24) y sin elipsis real |

Corrección mínima (solo estilos en línea del componente, sin tocar el sistema de diseño compartido):
1. La cabecera declara `flex-wrap: wrap` con `row-gap: 8px`, de modo que en pantallas estrechas las columnas se apilan.
2. Las etiquetas de máquina y sede pasan de `max-width: 260px` a `max-width: 100%`, con lo que la elipsis de la
   propia etiqueta actúa contra su columna en vez de contra un valor fijo.

Verificación posterior a la corrección:

| Comprobación | Antes | Después |
|---|---|---|
| Elementos que desbordan la caja del modal (390×844) | 1 (máquina, x=5,7) | **0** |
| Texto solapado en la cabecera | Sí (ticket + máquina) | **No**; cabecera de 102,4 px en dos líneas (ticket + canal / máquina · sede + estado + cierre) |
| Etiquetas dentro del margen del modal | No | Sí (x=45, ancho 168,6 px con elipsis) |
| Escritorio 1440×900 | 69 px, una línea | **Sin cambios**: 69 px, una línea, sin truncado |

---

## 7. Evidencia visual

| Captura | Viewport | Contenido |
|---|---|---|
| 1 | 390 × 844 | Hilo abierto desde «Conversación de la parada (6)»: cabecera ya corregida, seis mensajes con autoscroll al último, selector de privacidad y botonera inferior (Adjuntar foto · Cerrar · **Enviar** azul a la derecha) |
| 2 | 390 × 844 | Compositor con texto del pulgar, contador «926 caracteres restantes» y **Enviar** habilitado |
| 3 | 390 × 844 | Tras el toque: séptimo mensaje ámbar «Nota Interna de Taller (Confidencial)» al fondo del hilo, compositor limpio |
| 4 | 1440 × 900 | Canal de Coordinación: modal 680 × 760 centrado, burbujas públicas y notas internas ámbar |
| 5 | 1440 × 900 | Canal de Responsable de Sede: mismo modal con **solo** los cuatro mensajes públicos |
| 6 | 390 × 500 | Altura útil de teclado virtual: cabecera de dos líneas, hilo desplazable y botonera completa a 52 px del borde inferior |

Las imágenes se tomaron en el panel de previsualización de la sesión de verificación; este documento conserva la
geometría medida (§3–§5) como constancia reproducible.

---

## 8. Guardas permanentes incorporadas

| Guarda | Ubicación |
|---|---|
| 4 aserciones nuevas (grupo 8) que fijan la cabecera envolvente, la columna encogible y la elipsis elástica, y prohíben volver a un `max-width` fijo en la cabecera | [IncidentCommentThreadModalScaffoldTest.mjs](../tests/unit/IncidentCommentThreadModalScaffoldTest.mjs) — 48 aserciones |
| Batería completa | `php tests/run_all.php` → **207/207 suites, 7.860 aserciones, 0 fallos**, semillas restauradas |

Las suites ESM verifican la plantilla como artefacto (sin DOM real); la medición de geometría seguirá siendo una
comprobación manual de navegador, ahora documentada y con trampas de regresión sobre el marcado.

---

## 9. Hallazgos informativos (fuera del alcance del modal, sin bloqueo)

1. **Desbordamiento horizontal de 94 px a 390 px en la página de fondo** (la caja del modal no desborda nada).
   Origen medido: la barra de perfil/credenciales demo (botón «Salir» hasta x=484) y la fila de pestañas de la
   vista del técnico («Repuestos (0)» hasta x=410). Afecta a las vistas, no al modal de RNF-03.
2. **Alturas de control de 36 px** (`button-primary`/`button-secondary` del sistema Docker, [design.md](design.md))
   frente a la recomendación ergonómica de 44 px (iOS) / 48 px (Android). El modal cumple RNF-04 (tokens) y el
   problema se compensa con la posición: la acción principal queda a 76 px del borde inferior y en el cuadrante
   derecho, dentro del alcance del pulgar. Se registra como recomendación, no como incumplimiento.
3. **Barra de perfiles bajo la cabecera fija**: al desplazar la página, la barra de pestañas queda cubierta por el
   encabezado `sticky` (z-index 100) y sus pestañas dejan de ser pulsables hasta volver arriba. Es un artefacto de
   la barra de demostración, no del modal.

---

## 10. Veredicto

**H-2 cerrado por evidencia de navegador.** El modal del hilo es responsivo y operable con una sola mano a 390 px
(acción principal a 76 px del borde inferior y al 77,9 % del ancho, 0 desbordes internos, cabecera sin solapes),
resiste la reducción de altura por teclado (390×500) sin ocultar el compositor, y se adapta al escritorio con
680 × 760 px centrados y una sola línea de cabecera. El defecto de cabecera que la verificación destapó queda
corregido y protegido por cuatro aserciones nuevas.
