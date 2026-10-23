# ESPECIFICACIÓN FUNCIONAL: SUPERFICIE CSAT DEL TÉCNICO (INCREMENTO 20.1)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)
**Módulo:** 20.1 · Superficie CSAT del Técnico de Campo
**Documento Canónico:** `specs/20-csat-satisfaction-survey/spec-20.1-technician-surface.md`
**Versión:** 1 · aprobada (2026-10-23)
**Estado:** ✅ **PUERTA SDD SUPERADA** — aprobada por el Product Owner el 2026-10-23 con umbral de muestra de 3 respuestas y documento único. Fase 3 abierta en la rama `csat-technician-surface`.
**Origen:** Revisión de la resolución **R6** del módulo 20 (`spec.md` §13), a petición del Product Owner
**Alineación Constitucional:** Art. III.3 (solo lectura, no altera el historial), Art. IV.2 (Vue 3), Art. V.4 (mínimo privilegio). **Art. II: no aplica** (no toca cadena de frío, SLA ni cuarentenas).

> **Nota de andamiaje:** este incremento vive dentro de la carpeta del módulo 20 y usa un único documento (spec + tareas) en lugar de `spec.md` + `plan.md` + `tasks.md`. Justificación: el incremento no introduce capa nueva de dominio ni de persistencia, así que un `plan.md` sería una lista de archivos ya enumerada en §11. Si el Product Owner prefiere la ceremonia completa, se parte en tres ficheros antes de la puerta.

---

## 1. Problema y contexto

La resolución **R6** del módulo 20 decidió: «Sin superficie; el técnico no ve nota ni comentarios». El técnico resuelve la avería y nunca sabe cómo la valoró la sede. Eso cierra el bucle hacia Coordinación pero **deja ciego al que ejecuta el servicio**: no hay señal de mejora, ni de deterioro, ni forma de detectar que una sede concreta está sistemáticamente insatisfecha con un tipo de intervención.

Este incremento abre **una superficie mínima y acotada** para el técnico: su propia agregación, sin comentarios, sin desglose por sede, sin comparación con otros técnicos.

### Por qué R6 se puede revisar (y R4 no)

R6 es una **decisión de producto**, no un candado técnico: Art. V.4 protege al informador frente a la coordinación, no limita lo que el técnico sabe de su propio desempeño. R4, en cambio, choca con Art. III.3 porque exigiría reescribir evidencia. **Este incremento no escribe nada**: es agregación de lectura.

---

## 2. Alcance

### Dentro
* Endpoint de agregación CSAT del técnico autenticado.
* DTO propio con la superficie mínima.
* Tarjeta «⭐ Mi Valoración de las Sedes» en `TechnicianMetricsView.js`.
* Pruebas de contrato, de privacidad y de UI.

### Fuera (explícito)
* **Comentarios**: el texto escrito por la sede no se expone en esta superficie (§4.2).
* **Desglose por sede**: permitiría reidentificación (§4.1).
* **Comparación con otros técnicos o ranking**: Art. V.4 y decisión de producto.
* **Exportación**: no aplica a la superficie del técnico.
* **Rectificación (R4)**: sigue fuera de alcance; este incremento no la habilita.
* **Filtro por sede o rango personalizado**: el técnico no elige sede, y el rango es el del selector existente.

---

## 3. Requisitos Funcionales

| # | Requisito |
| :--- | :--- |
| **RF-20.1.1** | El técnico autenticado puede consultar su **valoración media**, el **número de respuestas**, la **distribución 1–5** y la **tendencia mensual** del periodo seleccionado. |
| **RF-20.1.2** | La superficie respeta el **selector de periodo ya existente** en `TechnicianMetricsView` (`last_7_days`, `last_30_days`, `current_month`). No añade selector propio. |
| **RF-20.1.3** | Sin respuestas en el periodo se muestra «Aún no hay valoraciones en el periodo», **nunca un 0**. |
| **RF-20.1.4** | Por debajo del umbral de muestra (§5, D4) se muestra «Aún no hay valoraciones suficientes para mostrar una media», **sin cifra**. |
| **RF-20.1.5** | Un fallo del bloque CSAT **no oculta ni vacía** las métricas de MTTR, resueltas, en curso y primera respuesta. |
| **RF-20.1.6** | La tarjeta **nunca** muestra comentarios, sede, código de ticket ni fecha exacta de una valoración. |
| **RF-20.1.7** | La superficie es de **solo lectura**: no hay acción que envíe, corrija o borre nada. |

---

## 4. Privacidad y mínimo privilegio

### 4.1 Identidad del técnico

* El `technician_id` sale **exclusivamente del token**, igual que en `TechnicianMetricsController::getMyMetrics` (patrón vigente): cualquier `technician_id` en la query se **sobrescribe**.
* `location_id` en la query se **descarta y se fuerza a `null`**. Sin esta regla, `?location_id=3&period=custom&from=…&to=…` aislaría una única valoración y revelaría qué sede puntuó qué. **Es un agujero de reidentificación, no una comodidad**: la regla es normativa y lleva prueba propia.
* Con `technician_id = null` («Sin técnico») no se devuelve nada: el filtro por id lo excluye por construcción.

### 4.2 Contenido expuesto

| Campo | ¿Se expone? | Motivo |
| :--- | :--- | :--- |
| `average`, `responses`, `distribution` | Sí | Señal de calidad sin identificar a nadie |
| `monthly_trend` | Sí | Evolución propia; el mes agrega varias respuestas |
| `comment` | **No** | Texto libre de la sede: vector de conflicto y de represalia |
| `by_site` | **No** | Reidentificación por sede con pocos datos |
| `by_technician` | **No** | Solo hay un técnico: no aporta y filtra nombres ajenos |
| Nombre del técnico | No se repite aquí | Ya lo pinta la vista; evitar duplicar la fuente |

---

## 5. Decisiones de diseño

| # | Decisión | Razón |
| :--- | :--- | :--- |
| **D1** | **Endpoint nuevo** `GET /api/technician/my-metrics/csat`, no extender `getMyMetrics` | Dominio de fallo separado: un fallo de CSAT no puede tumbar las métricas de SLA. Espeja `/coordinator/metrics/csat`. No cambia un contrato vivo. |
| **D2** | **DTO propio** `TechnicianCsatDto`, proyección de `CsatReportDto` | Reusar `CsatReportDto` obligaría a devolver `by_site`/`by_technician` vacíos: un contrato que miente. El DTO nuevo son ~50 líneas y no filtra nada. |
| **D3** | **Sin consultas nuevas**: se reusa `SatisfactionSurveyRepositoryInterface::aggregate()` | `MetricFilter` ya soporta `technicianId` y `buildScope()` ya filtra. Se ejecutan dos agregados que se descartan (`by_site`, `by_technician`); son `GROUP BY` sobre tabla indexada y el coste es despreciable frente a duplicar SQL y su fake en memoria. |
| **D4** | **Umbral de muestra: 3 respuestas**, aplicado a **toda media expuesta** *(aprobado en la puerta)* | Con una o dos respuestas una media es ruido y expone a quien resolvió una única avería. El umbral no es solo de la media global: **también rige la distribución y cada mes de la tendencia**. Por debajo del umbral se devuelve `average: null`, distribución a cero y `monthly_trend: []`; publicar «2026-09: 1.00 (1 respuesta)» filtraría exactamente lo que el umbral tapa. |
| **D5** | **Sin evento de auditoría** | Lectura de datos propios; el catálogo de auditoría registra mutaciones, no consultas. |
| **D6** | **Sin tokens hexadecimales nuevos** en la UI | La vista es legacy con hex incrustados; el bloque nuevo usa `var(--color-…)` y el ratchet no sube. |

---

## 6. Contrato de API

```
GET /api/technician/my-metrics/csat?period=last_30_days
Auth: técnico (token técnico)
```

**200**
```json
{
  "success": true,
  "data": {
    "summary": {
      "average": 4.33,
      "responses": 6,
      "distribution": { "1": 0, "2": 0, "3": 1, "4": 2, "5": 3 }
    },
    "monthly_trend": [
      { "month": "2026-09", "average": 4.0, "responses": 2 },
      { "month": "2026-10", "average": 4.5, "responses": 4 }
    ],
    "threshold_met": true
  }
}
```

`threshold_met: false` cuando `responses < 3`: el cliente muestra el estado de RF-20.1.4 y **oculta** `average` (se envía `null` para no dejar la cifra al alcance de las herramientas del navegador). En ese caso `distribution` viaja a cero y `monthly_trend` vacío: publicar el mes concreto filtraría la misma cifra por otra puerta (D4).

**Errores**

| Código | Situación |
| :--- | :--- |
| `401 UNAUTHORIZED` | Sin token o sin `user_id` en el contexto |
| `403 FORBIDDEN` | Token de coordinación o de sede |
| `400 INVALID_FILTER_PARAMS` | Periodo o rango inválido (mensaje de `MetricFilter`) |
| `500 INTERNAL_SERVER_ERROR` | Fallo interno; el detalle solo va al log del servidor |

---

## 7. Interfaz

Tarjeta en `TechnicianMetricsView.js`, entre «Primera Respuesta Media» y la nota de privacidad:

* **Título:** «⭐ Mi Valoración de las Sedes».
* **Ready:** cifra grande `4.33 / 5`, «N valoraciones», distribución 5→1 en barras proporcionales al total, y tendencia mensual en lista compacta.
* **Sin respuestas:** «Aún no hay valoraciones en el periodo».
* **Bajo umbral:** «Aún no hay valoraciones suficientes para mostrar una media».
* **Error:** mensaje en la propia tarjeta, con la nota de que el resto de métricas sigue disponible.
* **Carga:** indicador propio dentro de la tarjeta, sin bloquear las tarjetas de SLA.
* **Móvil primero:** una columna, sin tablas horizontales.

Carga: `loadCsat()` se dispara en el mismo lote que `loadMyMetrics()`, con el mismo `period`, y **absorbe su propio error**.

---

## 8. Criterios de aceptación

1. `GET /api/technician/my-metrics/csat` responde solo el agregado del técnico del token.
2. Un `technician_id` distinto en la query **no** cambia el resultado.
3. Un `location_id` en la query **se ignora** (prueba con dos sedes que dan notas distintas: la respuesta es idéntica a sin filtro).
4. Coordinación y sede reciben `403`.
5. Sin respuestas: `average: null`, `responses: 0` y `threshold_met: false`.
6. Con menos de 3 respuestas no se publica la media.
7. El bloque sobrevive al fallo del endpoint CSAT sin vaciar las métricas de SLA.
8. La tarjeta no contiene `<table>` de sedes, ni `comment`, ni `ticket_code`.
9. Ratchet de tokens: el techo **no sube**.
10. La batería global sigue en verde y las cifras documentadas son coherentes.

---

## 9. Plan de pruebas

| Suite | Qué fija |
| :--- | :--- |
| `TechnicianCsatDtoTest.php` (nueva, unitaria) | Umbral en las tres granularidades, forma del payload, distribución canónica, proyección desde `CsatReportDto` |
| `TechnicianCsatApiTest.php` (nueva, integración) | Contrato, 401/403, `technician_id` del token, **`location_id` ignorado**, periodo, vacío, umbral |
| `CsatPrivacyConstitutionalTest.php` (ampliación) | Regla de privacidad §4.1 como guarda constitucional |
| `MetricCardsTest.mjs` (grupo 6) | Tarjeta, estados, carga en el mismo lote, aislamiento del fallo, ausencia de comentarios y de desglose |
| `DesignTokenDebtRatchetTest.mjs` | Sin hex nuevos |

No hay migración, ni cambio de esquema, ni semilla.

---

## 10. Tareas

| ID | Tarea | Ruta | Estado |
| :--- | :--- | :--- | :--- |
| **T-20.1-01** | `TechnicianCsatDto` (proyección de `CsatReportDto`, umbral, `threshold_met`) + pruebas unitarias | inline | ✅ `fb5d987` · 21/21 |
| **T-20.1-02** | `TechnicianMetricsController::getMyCsat()` + ruta + `TechnicianCsatApiTest` | inline | ✅ `496839a` · 39/39 |
| **T-20.1-03** | Ampliación de `CsatPrivacyConstitutionalTest` (location_id ignorado) | inline | ✅ `8ed548f` · 22/22 |
| **T-20.1-04** | `api.metrics.getMyCsat` + tarjeta en `TechnicianMetricsView` + grupo 6 en `MetricCardsTest` | inline | ✅ `376471a` · 52/52 · trinquete 2268 |
| **T-20.1-05** | Cierre: spec 20 §13 marca R6 como revisada por 20.1, cifras y batería | inline | ✅ batería 325 suites / 12.659 aserciones |

Estimación: ~480 líneas autoradas, una sola unidad cohesiva. Entra como **un PR**; no hace falta cadena.

---

## 11. Efecto sobre el módulo 20

* `spec.md` §13: la fila **R6** se anota como *«revisada por el incremento 20.1; ver `spec-20.1-technician-surface.md`»*. No se reescribe el texto original: se documenta la revisión, Art. I.
* El módulo 20 en sí **no cambia** de comportamiento: no se toca la base de datos, ni el portal de sede, ni el cuadro de coordinación.

---

## 12. Riesgos

| Riesgo | Mitigación |
| :--- | :--- |
| Presión del técnico sobre la sede a partir de una nota baja | Sin comentarios, sin sede, sin fecha exacta y con umbral de muestra |
| Reidentificación por filtro de sede | `location_id` descartado y probado |
| Un 1★ aislado destruye la percepción de un buen técnico | Umbral de 3 respuestas (D4) |
| La vista legacy acumula hex | Bloque nuevo con tokens; el ratchet no sube |
| Duplicar la verdad del periodo | Se reutiliza `MetricFilter`; no se interpreta `period` dos veces |

---

## 13. Decisiones cerradas en la puerta

1. **D4 — umbral de 3 respuestas:** aprobado, extendido a toda media expuesta (global, distribución y cada mes).
2. **Ceremonia:** aprobado el documento único (spec + tareas); no se parte en `spec` + `plan` + `tasks`.
3. **Rama:** `csat-technician-surface`, creada desde `main` con el módulo 20 ya integrado.
