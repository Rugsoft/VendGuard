# Verificación de usabilidad móvil a 360 px · Modal de pausa (T-PAUSE-27)

**Módulo:** `11-pending-info-sla-pause`
**Requisito verificado:** RNF-03 (ergonomía móvil para técnicos: operable con una mano en terminales de 360 px, objetivos táctiles ≥ 44 px y contador reactivo de caracteres)
**Fecha:** 2026-10-10
**Artefacto medido:** [`PendingInfoPauseModal.js`](../public/assets/js/components/PendingInfoPauseModal.js)
**Evidencia de regresión:** grupo 3 ampliado de [`PendingInfoPauseModalTest.mjs`](../tests/unit/PendingInfoPauseModalTest.mjs) (7 aserciones nuevas de contrato de layout)

---

## 1. Por qué esta verificación existe

La auditoría de trazabilidad del módulo 11 detectó que RNF-03 se sostenía sobre dos pruebas reales (objetivos de 44 px y contador reactivo) pero **la anchura de 360 px que la propia especificación nombra no estaba comprobada en ninguna parte**. Se cierra el hueco en dos capas:

1. **Contrato de layout asertado** en la suite ESM, para que una regresión de estilo —un ancho fijo, un `min-width` o una tipografía diminuta— rompa la batería.
2. **Medición real en navegador** sobre el componente montado, porque una aserción sobre el texto de la plantilla no prueba cómo queda pintado.

---

## 2. Método

* Página de sonda local (no versionada, vive en `scratch/`, ignorada por Git) que monta el **componente real** con Vue 3 vendoreado en `public/assets/js/vendor/vue.esm-browser.prod.js` y carga los **tokens de diseño de producción** (`public/assets/css/design-tokens.css`), más un fixture de incidencia en cuarentena.
* Servidor de desarrollo del proyecto (`php -S 127.0.0.1:8899 -t . router.php`) y navegador con viewport forzado a **360 × 640 px** (terminal vertical más estrecho del parque declarado).
* Medición con `getBoundingClientRect()` sobre el diálogo y sobre cada control interactivo, comprobación de desbordamiento horizontal del documento y ejecución real del flujo completo: seleccionar causa, redactar el motivo y comprobar que el botón de confirmación se habilita.
* Consola del navegador sin mensajes ni errores tras la interacción.

---

## 3. Medición obtenida (viewport 360 × 640)

| Elemento | Ancho | Alto | Comprobación |
| :--- | ---: | ---: | :--- |
| Diálogo del modal | **328 px** | 576 px | Exactamente `360 − 2 × 16 px` de relleno del backdrop; `max-width: 540px` no llega a actuar nunca |
| Margen izquierdo del diálogo | 16 px | — | Centrado sin desbordar |
| Botón de cerrar | 44 px | **44 px** | Objetivo táctil mínimo cumplido |
| Botones de causa (×4) | 269 px | **52 px** | Por encima del mínimo, en una sola columna |
| Área de texto del motivo | 269 px | 86 px | `box-sizing: border-box`; el relleno no desborda |
| Botón «Cancelar» | 90 px | **44 px** | Objetivo táctil mínimo cumplido |
| Botón «Confirmar Pausa» | 141 px | **44 px** | Objetivo táctil mínimo cumplido |

* **Desbordamiento horizontal:** ninguno (`document.scrollWidth = 360` = ancho de viewport).
* **Desplazamiento interno:** activo (`max-height: 90vh` + `overflow-y: auto`), de modo que el formulario completo cabe sin recortar el pie de acciones.
* **Altura mínima de cualquier control interactivo:** 44 px.
* **Tipografía mínima:** 12 px.
* **Colores de producción resueltos:** cabecera ámbar `rgb(254, 248, 231)` (`--color-warning-bg`) con texto `rgb(146, 64, 14)` (`--color-warning-text`).

## 4. Operabilidad real del flujo (una sola mano)

| Paso | Resultado medido |
| :--- | :--- |
| 1. Seleccionar «Edificio cerrado / Sin acceso a instalaciones» | Causa marcada; el botón de confirmación sigue deshabilitado (faltan los 20 caracteres) |
| 2. Redactar el motivo | 53 caracteres escritos en el área de 269 px; contador reactivo publica «✓ 53 caracteres» |
| 3. Enviar | Botón «Confirmar Pausa» habilitado, con 44 px de alto y 141 px de ancho |

---

## 5. Límites declarados

* La sonda se ejecuta contra el **componente montado**, no contra el flujo autenticado completo del técnico: no se ha recorrido la ruta real con sesión y base de datos a 360 px (esa capa la cubre `PendingInfoTechnicianEndpointsTest` o sus aserciones de contrato, no la ergonomía).
* La medición es de **navegador de escritorio con viewport forzado**, no de un terminal físico: no se ha medido respuesta táctil ni rendimiento de pintado en hardware real.
* La página de sonda no se versiona (vive en `scratch/`, ignorado por Git) por ser un instrumento de comprobación, no un artefacto del producto; la protección permanente contra regresiones es el grupo 3 de la suite ESM.
