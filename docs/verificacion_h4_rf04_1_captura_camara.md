# Verificación H-4 · RF-04.1 (Captura directa con la cámara móvil) del hilo de comentarios

**Módulo:** 10 · Hilo de comentarios bidireccional (T-COM-11)
**Requisito implementado:** `RF-04.1 (EARS - Opcional)` de [spec.md](../specs/10-incident-comments/spec.md)
**Hallazgo que cierra:** H-4 (la captura con la cámara móvil no estaba declarada en la interfaz)
**Fecha:** 07/10/2026 · **Rama:** `incident-comments`

---

## 1. Hallazgo: desviación de implementación, no cambio de especificación

La especificación **ya exigía las dos vías** desde su aprobación:

> *«DONDE el usuario decida adjuntar una fotografía a su comentario, el sistema DEBE permitir seleccionar un archivo de imagen desde el dispositivo **o capturarlo con la cámara móvil**.»*

T-COM-11 implementó la primera (selector de archivos) y omitió la segunda: no existía ningún atributo `capture` en el
repositorio. Por tanto **no se ha modificado ninguna especificación aprobada**; se ha cerrado la brecha de
implementación contra un requisito vigente (Art. V de la Constitución y disciplina SDD intactas).

---

## 2. Solución implementada

| Elemento | Implementación |
|---|---|
| Input de captura | `#incident-comment-camera-input` (`ref="photoCaptureInput"`), `type="file"`, **`capture="environment"`** (cámara trasera: la útil para fotografiar el frontal de la máquina), mismo `accept="image/jpeg,image/png,image/webp"` y mismo `:disabled="isSubmitting"` |
| Acción visible | Botón **«Hacer foto»** (`data-testid="incident-comment-camera-btn"`, `aria-label` explícito) que abre el input de captura |
| Vía de archivo existente | Botón **«Adjuntar foto»** conservado con su selector `#incident-comment-photo-input`; el icono de cámara pasa a la acción que realmente la abre y el selector adopta un icono de imagen |
| Tubería única | Ambos inputs entran por `handlePhotoSelect()`: validación MIME + límite de 5 MB (RF-04.2), miniatura con `URL.createObjectURL` y revocación de la URL anterior (una sola evidencia por mensaje) |
| Alcance | Sólo en el formulario editable del compositor; ausente de la rama de solo lectura del sellado (RF-05.3) |

**Por qué dos acciones y no un atributo suelto:** `capture` fuerza la apertura de la cámara y **elimina la
posibilidad de elegir una foto ya tomada**; RF-04.1 pide explícitamente ambas vías, así que coexisten sin perder
ninguna capacidad.

---

## 3. Pruebas reactivas (fase roja → verde)

| Suite | Grupo nuevo | Resultado |
|---|---|---|
| [IncidentCommentThreadModalFormTest.mjs](../tests/unit/IncidentCommentThreadModalFormTest.mjs) | **Grupo 6 · Captura Directa con la Cámara Móvil** (13 aserciones) | **49/49** (antes 36), exit 0 |
| [IncidentCommentThreadModalScaffoldTest.mjs](../tests/unit/IncidentCommentThreadModalScaffoldTest.mjs) | **8.5** anclaje del grupo de acciones | **49/49**, exit 0 |

Cobertura del grupo 6: existencia y tipo del input de cámara, `capture="environment"`, paridad de `accept` con el
selector, presencia de las dos acciones («Hacer foto» / «Adjuntar foto»), que el botón abre **su** input y no el
ajeno, una sola tubería (`handlePhotoSelect`), deshabilitado de ambos inputs y botones durante la subida (RF-04.5),
etiqueta accesible, pertenencia al formulario editable y no a la rama sellada, envoltura de la barra en pantallas
estrechas, y la reactividad de la tubería compartida con `File` reales: la captura sustituye a la foto anterior
revocando la miniatura previa, y una captura de 6 MB se rechaza con el mensaje de 5 MB.

Ejecución en rojo previa a la implementación: 10 de las 13 aserciones fallaban (las 3 reactivas ya pasaban por la
tubería compartida existente, y actúan como guardas de regresión de esa semántica).

---

## 4. Defecto colateral detectado y corregido en la misma verificación

Añadir un tercer y cuarto control al compositor hizo **envolver** la barra de acciones en móvil. Con
`justify-content: space-between`, una fila con un solo elemento se alinea al **principio**, así que la acción
principal se desplazó al centro-izquierda:

| Estado | «Enviar» medido en 390×844 | Distancia al borde derecho |
|---|---|---|
| Antes de la corrección | x = 117,8 – 207,8 (centro: 40,9 % del ancho) | 182,2 px → fuera del alcance cómodo del pulgar |
| **Después** (`margin-left: auto` en el grupo de acciones) | x = **259,0 – 349,0** (centro: **77,9 %**) | **41,0 px**; 76,1 px al borde inferior |

El grupo de fotos queda en la fila superior (122,1 px al borde inferior) y las acciones en la inferior-derecha. La
regresión está fijada por la aserción 8.5 del suite de andamiaje.

---

## 5. Verificación en el navegador (DOM vivo)

| Comprobación | Resultado |
|---|---|
| Input de cámara en el DOM renderizado, con `capture="environment"` y `accept` compartido | ✔ (input oculto, `display: none`) |
| El botón «Hacer foto» dispara el input de captura y no el de archivos | ✔ sonda de `click`: 1 disparo sobre el input de cámara |
| Un `File` JPEG entregado por el input de cámara produce miniatura `blob:` y nombre «image.jpg», sin error de formulario y con el envío aún bloqueado sin texto (RF-03.1) | ✔ |
| 390 × 844: 0 elementos desbordan la tarjeta; modal íntegro en pantalla | ✔ |
| 1440 × 900: la barra vuelve a **una sola fila** (fotos a la izquierda, Cerrar/Enviar a la derecha), grupo de acciones a 0 px del borde del formulario, 0 desbordes | ✔ |

---

## 6. Limitaciones honestas

1. **Sin capturas de pantalla en esta sesión:** el panel de previsualización dejó de componer fotogramas
   (`document.visibilityState === 'hidden'`) y `preview_screenshot` falló de forma consistente. La constancia de
   esta verificación son las **mediciones de geometría sobre el DOM vivo** (§4–§5) y las suites automatizadas.
2. **No se ha abierto una cámara física:** desde el navegador de escritorio sólo puede verificarse el contrato HTML
   (`capture="environment"`) y el cableado. Se recomienda una comprobación manual en un móvil real (Android e iOS)
   antes de declarar el módulo cerrado al 100 % en dispositivos.
3. Las alturas de control de 36 px del sistema de diseño frente a la recomendación ergonómica de 44 px siguen
   registradas en el informe de H-2 (§9); ambos botones de fotografía cumplen los tokens de RNF-04.

---

## 7. Regresión completa

`php tests/run_all.php` → **207/207 suites**, **7.874 aserciones**, **0 fallos**, semillas canónicas restauradas
(13 incidencias, 0 comentarios) y sin residuos en `public/uploads/`.

**Veredicto: H-4 cerrado.** RF-04.1 queda cubierto en sus dos vías (dispositivo y cámara) con 14 aserciones nuevas,
y el defecto de anclaje del botón de envío que la verificación destapó queda corregido y protegido.
