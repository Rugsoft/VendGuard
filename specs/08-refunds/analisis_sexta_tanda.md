# Sexta tanda adversarial: conciliación del efectivo, rastro de auditoría y proyecciones por rol

**Fecha:** 2026-10-02
**Ámbito:** Módulo 08 (Refunds and Unclaimed Cash Management), con dos escapes fuera del módulo
**Estado:** cerrada — los cinco hallazgos están implementados, probados y documentados

---

## 1. Por qué esta tanda y no otra

Las cinco tandas anteriores atacaron el ciclo de vida del expediente: abrir, dictaminar, aprobar,
liquidar, entregar y el freno de fuerza bruta sobre el PIN. Todas pasaban por el mismo cuello de
botella: `refund_requests`.

Esta tanda cambia de objetivo. En lugar de romper el ciclo, se ataca **todo lo que el módulo dice
sobre el dinero una vez que el ciclo ha terminado**:

1. **La conciliación del efectivo**: ¿pueden los mismos euros aparecer dos veces en los libros?
2. **El rastro de auditoría**: ¿lo que se exporta es lo que dice exportar?
3. **Las proyecciones por rol**: ¿qué ve cada rol cuando algo falla?

Los tres son propiedades del *sistema de libros*, no del expediente. Y esa es la razón por la que
esta tanda encontró cosas que las anteriores no veían: un expediente puede estar impecable y,
 aun así, el dinero que la empresa cree tener en caja no ser el dinero que tiene.

**Método:** los cinco hallazgos se midieron contra endpoints reales con `scratch/wave6.php`, no por
lectura de código. Cada cifra de este documento procede de una respuesta HTTP y de una consulta SQL
sobre MariaDB con datos sembrados, no de una suposición sobre lo que el código pretendía hacer.

---

## 2. Los cinco hallazgos

### 🔴 H-1 · `X-Site-Code` y `POST /api/auth/site-login` son la misma puerta sin credencial

**Medición.** El hallazgo ya estaba catalogado como `S-4` en la auditoría de arquitectura, con
severidad 🟡 y el razonamiento de que la cabecera era *una alternativa* al token firmado, es decir,
un atajomolesto pero no un agujero. Ese razonamiento era falso:

```php
// AuthService::loginSite()
$token = $this->generateSiteToken($location);   // la credencial ES el site_code
```

No hay contraseña. La credencial que se presenta y la que se devuelve es el mismo `site_code`. Y ese
código está impreso en la etiqueta QR del propio edificio, a la vista de cualquiera que acuda a la
máquina.

Con el token obtenido así, medido sobre los endpoints reales:

| Petición | Resultado |
| :--- | :--- |
| `GET /api/location/refunds` | **200** — expedientes de la sede con sus importes |
| `GET /api/location/sanitary-certificates` | **200** — certificados sanitarios |
| `POST /api/incidents` | **201** — fila real creada en `incidents` |

La escalada de 🟡 a 🔴 no es una cuestión de opinión: es que la última fila **escribe**.
Retirar `X-Site-Code` no cerraba nada, porque `site-login` entrega el mismo acceso por la puerta de
al lado.

**Por qué no se arregla en esta tanda.** El factor de autenticación del responsable de ubicación *es*
el código de sede por diseño de **RF-01**: el flujo público del QR es el único punto de contacto y
no puede exigir un secreto que el usuario no tiene. Cerrar esto exige una decisión de producto —
emitir un secreto por sede en el alta, o *pairing* en la primera visita — y ambas son cambios de
contrato, no arreglos de código. El coste de hacerlo mal (dejar al responsable de ubicación fuera de
su propio panel) es mayor que el riesgo que mitiga, y esa decisión no es del agente.

**Lo que sí se hace:** documentarlo con la escalada a escritura, para que la decisión sea consciente
cuando llegue. Está en §5 de este documento y en la auditoría de arquitectura.

### 🔴 H-2 · El mismo euro conciliado dos veces

**Medición.** Un solo `POST /api/technician/incidents/{id}/resolve` acepta el bloque de dictamen
**y** el bloque `unclaimed_cash_found`. Con una reclamación viva de 8,00 €:

1. El técnico dictamina `FOUND_PHYSICAL` con `recovered_amount: 8.00` → el expediente pasa a
   `VERIFIED_PENDING_PAYMENT`.
2. En la **misma llamada** declara `unclaimed_cash_found: 8.00` → se inserta una fila en
   `unclaimed_cash_findings` por 8,00 €.
3. Coordinación liquida el expediente → `PAID_DIGITAL`.

Resultado: 8,00 €conciliados contra el reclamante **y** 8,00 € abonados a caja central como
sobrante. El mismo euro, dos veces, en los libros. La conciliación del día cuadra en cada tabla
individualmente y no cuadra en la suma.

**Causa.** `registerUnclaimedCash()` no miraba las reclamaciones de la avería. El hallazgo se
documentaba como «independiente y opcional del dictamen», lo cual es cierto en un caso y falso en
otro: no es independiente cuando las monedas son las mismas.

**Arreglo.** Antes de insertar, si alguna reclamación de la avería tiene dictamen emitido, el intento
se rechaza con `422 INVALID_RECOVERED_AMOUNT`. El efectivo ya está adjudicado — para el consumidor o
para nadie— y quien lo reclame a partir de ahí es Coordinación, no el técnico de campo.

La guarda mira el **dictamen**, no sólo el estado: una reclamación que el consumidor cerró sin
inspección no consumió ningún euro, así que no genera doble asiento y no debe impedir declarar el
sobrante. Y mira la proyección **restringida** (`findRestrictedByIncident`), no la completa: cargar
el IBAN de un reclamante para decidir un hallazgo de monedas sería dejar la puerta de Art. V.4 abierta
por el hueco de una consulta.

**Efecto colateral declarado y fijado por prueba.** El `resolve` registra el dictamen *antes* que el
sobrante (es el desacoplamiento declarado en RF-REF-09: una avería no se retiene por un problema de
dinero). Por tanto, un `422` del sobrante deja el dictamen ya registrado. La prueba 11.4 lo fija
explícitamente para que no se confunda con un efecto secundario, y la 11.5 demuestra que el técnico
cierra la intervención reintentando sin el bloque de sobrante: el rechazo no deja el trabajo en un
limbo irrecuperable.

### 🟠 H-3 · El tope de 50 € era por hallazgo, y las intervenciones se multiplican

**Medición.** Por la vía **legítima** —no por abuso de la API—: avería sin reclamaciones → 50,00 € de
sobrante → la sede reabre la avería dentro de la ventana de 48 h (Art. V.6) → segunda intervención →
otros 50,00 €. **100,00 €** en la misma máquina, y nada impedía un tercero.

Lo agravaba el mensaje de error, que recomendaba «divídalo en varios registros»: la multiplicación,
escrita.

**Arreglo.** El tope pasa a ser un **agregado por máquina**, contado sobre `unclaimed_cash_findings`.
Cada máquina dispone de 50,00 € de sobrante en total, no 50,00 € por visita. El mensaje de rechazo
informa de lo que esa máquina ya tiene registrado.

El agregado se mide **en céntimos enteros**, no en euros en coma flotante. Esto no es un detalle:
la primera implementación restaba `50.00 - 49.99` y el hueco salía en `0.00999999999999801` €, así que
un hallazgo de 0,01 € que cerraba el agregado en 50,00 € **exactos** se rechazaba. Un techo que no
alcanza su propio límite no es un techo. La prueba 11.12/11.13 mide precisamente ese céntimo.

El agregado es por máquina y no global. Un tope global sería otra política —y también sería otro
fallo—, así que la prueba 11.11 verifica que una máquina agotada no bloquea a otra.

### 🟡 H-4 · La exportación de auditoría recortaba en silencio

**Medición.** `PdoAuditLogRepository::findEvents()` aplicaba `min(100, $limit)` por su cuenta. Ese
100 estaba pensado para el listado paginado y se colaba en la exportación: el endpoint anunciaba
10.000 registros y entregaba **99 de 18.442**, sin avisar en ningún sitio.

Es el fallo más barato de la lista y el más caro: un export de cumplimiento que se calla lo que no
incluye es peor que uno que falla, porque la auditoría se apoya en él. Nadie mira un CSV que ha
descargado bien.

**Arreglo.** El tope de paginación se mueve al sitio que corresponde —`AUDIT_PAGE_MAX = 100` en el
listado de `CoordinatorMetricsController`— y el repositorio obedece lo que le pidan. La exportación
conserva su límite de seguridad de 10.000 eventos, pero **prepende una línea de aviso** a su cabecera
cuando `countEvents()` dice que hay más de los que ha entregado:

```
# ATENCION: EXPORTACION PARCIAL. 10000 de 18442 eventos de auditoria exportados.
# Filtre por fecha o entidad para obtener el historico completo.
```

### 🟡 H-5 · Los 500 servían el mapa de la base de datos

**Medición.** Dieciséis handlers devolvían `$e->getMessage()` en su respuesta 500. Un
`PDOException` de MariaDB incluye la consulta SQL completa: tabla, columnas, valores comparados y a
veces la propia cláusula `WHERE`. El `Router` además devolvía en `details` la clase de excepción, el
mensaje, el fichero y la línea, sin ninguna condición de entorno.

Traducido: un `GET` mal formado que provocara un error de SQL respondía con la estructura de la base
de datos de producción, por HTTP, a un cliente sin autenticar.

**Arreglo.** El rastro se queda en `error_log()` —que es donde se investiga— y la respuesta dice sólo
que hubo un error interno. Se corrigen los diez handlers que filtraban el mensaje y los seis `details`
del `Router`.

**El detector necesitó una corrección propia.** La primera versión de la comprobación emparejaba el
bloque `catch` con un regex `\(\\?Throwable`, y **no encontraba ninguno de los diez**: `\\?` en un
literal PHP es un *backslash opcional*, no un *punto de interrogación opcional*. La aserción daba
verde por ignorancia, que es la forma más Comfortable de estar equivocado. El detector final se ancla
en el propio literal `'INTERNAL_SERVER_ERROR'` y lee lo que la respuesta arrastra a partir de ahí,
que es la pregunta real y no depende de la indentación. El control de no-vacuidad
(`scratch/probe_leak_detector.php`) ejecuta ambos contra las copias previas al arreglo: verde con
ellas, rojo con el código actual.

---

## 3. Lo que resistió el ataque

Un ataque que sólo devuelve hallazgos no dice qué está bien. Esto no cayó:

| Ataque | Resultado |
| :--- | :--- |
| Sobrante con reclamaciones vivas sin dictamen | `422 REFUND_INSPECTION_REQUIRED` |
| Técnico leyendo una incidencia que no es suya | `403` |
| Suplantar al actor enviando su `id` en el cuerpo | La auditoría registra al técnico real |
| Machine inexistente o `machine_id` manipulado | Clave foránea; la fila no se crea |
| Más de 50 € de efectivo de oficio | `422 INVALID_RECOVERED_AMOUNT` (cuarta tanda) |
| Certificados sanitarios de otra sede vía `X-Site-Code` | Aislado al `location_id` del token |

El patrón es consistente: **la lógica de dominio está donde debe estar.** El CashCustody de
`LocationRefundController` lee el `site_code` del token y no del cuerpo; `TechnicianController`
extrae el actor de los atributos del token, nunca del payload. Lo que fallaba eran las reglas
**entre** casos, no las reglas **dentro** de un caso.

---

## 4. Resumen de decisiones

| # | Hallazgo | Decisión |
| :--- | :--- | :--- |
| H-1 | Bypass de sede | **Cerrado el 2026-10-08** con decisión de PO: clave de centro emitida en el alta (solo huella, entregada en mano), `X-Site-Code` retirada y freno de intentos por sede. Enmienda en `docs/propuesta_decision_s4_acceso_sede.md` §11. |
| H-2 | Doble asiento | **Prohibido.** `422` si hay dictamen previo en la misma avería. |
| H-3 | Tope de sobrante | **Agregado por máquina**, en céntimos enteros. Contradice el «por hallazgo» implícito en RF-REF-04; la especificación se actualiza en §5. |
| H-4 | Recorte de auditoría | **Declarado, no eliminado.** El límite de seguridad de 10.000 se mantiene; el CSV avisa de lo que no incluye. |
| H-5 | Fuga en 500 | **Cerrada.** El rastro va a `error_log`. |

---

## 5. Bypass de sede: por qué se aparca y no se arregla

Conviene dejarlo escrito con su razonamiento, porque «aparcar un agujero conocido» es exactamente el
tipo de decisión que se deshace en la siguiente iteración si nadie recuerda por qué se tomó.

**Lo que hay que hacer para cerrarlo:**

1. Emitir un secreto por sede en el alta y entregarlo al responsable (rotación, pérdida, soporte).
2. O emparejar en la primera visita: el responsable accede con el código y recibe un token de
   larga duración.

**Por qué no se hace aquí:**

- Ambas son cambios de contrato de producto. RF-01 define hoy el flujo público del QR, y ninguna de
  las dos es un detalle de implementación: altera el alta de sedes, el alta de usuarios y el soporte
  al cliente.
- El modo de fallo de hacerlo mal es peor que el riesgo que se mitiga. Si el secreto se pierde y no
  hay procedimiento de rotación, el responsable de ubicación pierde el acceso a su propio panel y la
  sede se queda sin canal para reportar averías. Un atacante que ya sabe el código de la sede
  (está en la etiqueta QR, es público) obtiene datos que el responsable ya puede ver; uno que no lo
  sabe obtiene exactamente lo mismo. El valor de proteger esto depende de ocultar algo que no está
  oculto.
- Combinado con **S-3** (sin rate-limiting en el login interno), lo que sí falta es el
  *rate-limiting* por sede, que es un arreglo local y no un cambio de contrato.

**Lo que sí cambia ahora:** el alcance real del hallazgo queda documentado con su escalada a
escritura, de modo que la prioridad de la decisión de producto sea correcta. No es un descuido
técnico: es una deuda de diseño conocida, con nombre y con medidas.

**Riesgo residual:** 🔴 hasta que exista un secreto por sede.

> **Nota de cierre (2026-10-08):** el Product Owner eligió la opción A y H-1 quedó cerrado en la rama
> `incident-comments`: clave de centro emitida en el alta (solo huella bcrypt, mostrada una vez y
> entregada en mano), retirada de `X-Site-Code` como vía de autenticación y freno de cinco intentos
> por sede. Este apartado conserva su redacción original como registro de la decisión; el riesgo
> residual de la última línea ya no aplica. Evidencia en `tests/integration/SiteAccessCodeTest.php`
> y `tests/unit/SiteAccessCodeGeneratorTest.php`.

---

## 6. Trazabilidad

| Hallazgo | Guardas | Pruebas de comportamiento |
| :--- | :--- | :--- |
| H-1 | `RefundsModuleClosureTest` 7.5 (documental) · **cerrado 2026-10-08** | `SiteAccessCodeTest` (acceso, error genérico, fallo en cerrado, cabecera retirada, freno por sede y rotación con auditoría) + `SiteAccessCodeGeneratorTest` |
| H-2 | `RefundsModuleClosureTest` 7.1 | `TechnicianRefundInspectionApiTest` 11.1–11.5 |
| H-3 | `RefundsModuleClosureTest` 7.2 | `TechnicianRefundInspectionApiTest` 11.6–11.13 |
| H-4 | `ConstitutionalAuditTest` 9.7 | Misma aserción (estática) + `TechnicianRefundInspectionApiTest` para el agregado |
| H-5 | `ConstitutionalAuditTest` 9.8, `RefundsModuleClosureTest` 7.4 | Mismas aserciones; control de no-vacuidad en `scratch/probe_leak_detector.php` |