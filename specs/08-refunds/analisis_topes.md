# Análisis de coherencia de topes — Módulo 08 (Refunds)

**Estado:** análisis abierto, sin efecto normativo. No modifica ninguna regla aprobada.
**Origen:** revisión solicitada tras endurecer el visto bueno (`approved_amount <= claimed_amount`).
**Evidencia:** `php scratch/probe_techos.php`, que mide contra los endpoints HTTP reales y deja la base de datos intacta.

Este documento existe para responder a una pregunta estrecha: **los topes que declara el módulo 08 se contradicen entre sí, o se contradicen con lo que el código hace?**

La respuesta corta: dos de ellos se sostienen, uno está bien, y dos están mal. Ninguna de las incoherencias detectadas permite hoy sacar más dinero del debido, pero dos de ellas mienten en la especificación y una falsea el rastro de la conciliación de efectivo.

---

## 1. Los topes y dónde viven

| Constante | Valor | Significado | Fichero |
| :--- | :--- | :--- | :--- |
| `MAX_CLAIMED_AMOUNT` | 50,00 € | Tope por reclamación | `RefundRequest` |
| `COORDINATOR_APPROVAL_THRESHOLD` | 10,00 € | Por encima, exige visto bueno | `RefundRequest` |
| `RECEPTION_DELIVERY_LIMIT` | 10,00 € | Por encima, no se permite depósito en conserjería | `RefundRequest` |
| `DISCREPANCY_TOLERANCE` | 20 % | Brecha reclamada/recuperada que fuerza visto bueno | `RefundRequest` |
| `MONEY_DECIMALS` | 2 | Posiciones decimales de todo importe | `RefundManagementService` |

---

## 2. Lo que SÍ es coherente

### 2.1 El tope de 50 € y el nuevo tope del visto bueno

**Coherente.** El visto bueno solo puede **bajar** del importe reclamado, y el importe reclamado ya está acotado a 50,00 € en la apertura. Por tanto la cadena es monótona decreciente y el techo efectivo del sistema es exactamente 50,00 € por reclamación:

```
50,00 € (tope de apertura)  >=  claimed_amount  >=  approved_amount  ==  paid_amount
```

Esto además cierra por arriba el único indicador que antes podía crecer sin control. Antes de la corrección, `approved_amount` solo comprobaba el bloque de 50,00 €, de modo que el máximo real de un pago era 50,00 € pero **el mínimo** podía ser una centésima con la misma autorización. Ahora la regla es simétrica en su cota superior y no lo es en la inferior, que es exactamente lo que la especificación pedía.

### 2.2 Los dos umbrales de 10,00 €

**Coherentes y sin solapamiento.** Comparten valor pero su semántica es distinta y complementaria: `COORDINATOR_APPROVAL_THRESHOLD` gobierna **quién autoriza**, `RECEPTION_DELIVERY_LIMIT` gobierna **dónde se guarda el efectivo**. Ambos comparan con `>` estrictamente, que coincide con el enunciado de RF-REF-03 («mientras el importe supere los 10,00 €») y de RF-REF-05 («si el importe recuperado supera los 10,00 €»).

Composición verificada: un expediente de 12 € con compensación presencial exige visto bueno **y** obliga a custodiar el efectivo en caja central. No existe ninguna ruta por la que un importe aprobado por encima de 10 € acabe en una conserjería que por regla no puede custodiarlo, porque la custodia se decide en la inspección, **antes** del visto bueno, y se decide sobre el importe **recuperado** y no sobre el aprobado.

### 2.3 La exposición agregada por avería

**Coherente.** El límite de 50 € es por reclamación, y una máquina puede atender a muchos reclamantes. La pregunta es si eso permite multiplicar el pago sin control. No: la liquidación sin visto bueno exige que el efectivo recuperado cubra la **suma** de todas las reclamaciones de la incidencia (`$shortfall = $recovered < $claimedTotal`). Para que N consumidores EACH paguen su claim sin firma de un coordinador, la máquina tiene que contener realmente esa cantidad de dinero. La ruta alternativa —escalado a Coordinación— exige una firma humana **por expediente**.

Por tanto: no existe un techo agregado, pero tampoco un multiplicador sin firma. Es una decisión de negocio legítima; si algún día se quiere un tope agregado por máquina o por sede, es una regla nueva y no una contradicción de las actuales.

---

## 3. Contradicción 1 — La tolerancia del 20 % no existe

**Estado: la especificación promete una banda que el código no implementa.**

RF-REF-03, *EARS Estado*:

> Mientras el importe supere los **10,00 €** o el importe recuperado por el técnico difiera en más de un 20 % del importe reclamado, el sistema DEBE impedir la liquidación directa y exigir la aprobación formal […]

La implementación real (`TechnicianRefundService::inspectBalance()`) calcula:

```php
$shortfall = $claims === [] ? false : ($recovered < $claimedTotal);
```

Es decir, **cualquier déficit escala**, por pequeño que sea. Y `hasMaterialDiscrepancy()`, que es lo que aplicaría el 20 %, solo llega a evaluarse cuando **no** hay déficit, en cuyo caso la brecha es cero o negativa y nunca supera el umbral. **`DISCREPANCY_TOLERANCE = 0.20` es código muerto.**

Medición:

| Reclamado | Recuperado | Brecha | Visto bueno | Resultado |
| :--- | :--- | :--- | :--- | :--- |
| 10,00 € | 10,00 € | 0 % | ninguno | `VERIFIED_PENDING_PAYMENT` |
| 10,00 € | 9,00 € | 10 % | — | `REQUIRES_COORDINATOR_APPROVAL` |
| 10,00 € | 8,50 € | 15 % | — | `REQUIRES_COORDINATOR_APPROVAL` |
| 10,00 € | 8,00 € | 20 % | — | `REQUIRES_COORDINATOR_APPROVAL` |
| 10,00 € | 7,90 € | 21 % | — | `REQUIRES_COORDINATOR_APPROVAL` |

La banda tolerada no existe: un céntimo de diferencia ya escala.

**Consecuencias.**

1. La especificación es incorrecta y alguien que la lea para prever carga de trabajo se equivocará. Toda reclamación con un céntimo de menos pasa por Coordinación.
2. `DISCREPANCY_TOLERANCE` induce a error: su nombre promete una regla que no se aplica.
3. Es la única incoherencia de este documento que es **más restrictiva** que la especificación. No hay riesgo de sobrepago; el riesgo es de operación y de confianza en la norma.

**Decisión pendiente de negocio.** Hay dos caminos coherentes, y son excluyentes:

- **A. Implementar la banda tal como está escrita.** Sustituir la regla de déficit por la regla de discrepancia: `ratio > 20 %` escala, y por debajo se liquida sin firma. Exige decidir **qué cifra se paga** dentro de la banda (ver §4.1), porque es ahí donde la especificación actual dice una cosa y el diseño del módulo sugiere otra.
- **B. Corregir la especificación para que diga lo que el código hace.** Se elimina la mención al 20 % y se declara que cualquier déficit exige visto bueno. Es el camino de menor riesgo y el que refleja el propósito original del comentario `RF-REF-08` en el código: «una sola pila de monedas no puede satisfacer a varios reclamantes».

La opción B es la recomendada: el 20 % nunca estuvo implementado, y activarlo ahora cambiaría el flujo de caja de un caso que hoy ya está verificado.

---

## 4. Contradicción 2 — Cada caso guarda el total recuperado de la avería

**Estado: defecto de integridad de datos, no de dinero.**

`applyVerdict()` pasa a cada expediente el **total** recuperado de la incidencia como su `recovered_amount`:

```php
$inspected = $case->withInspection($dto->finding, $recovered, …);  // $recovered es el total
```

Con dos reclamantes y la pila cuadrada al céntimo — el mejor caso posible, sin déficit — ambos expedientes guardan el total:

```
Resolución técnica con la pila cuadrada (16,00 €): HTTP 200

caso   reclamado   recuperado   estado                     visto bueno
A      10,00 €     16,00 €      REQUIRES_COORDINATOR_APPROVAL  ninguno
B       6,00 €     16,00 €      REQUIRES_COORDINATOR_APPROVAL  ninguno
```

Dos efectos medidos:

1. **El importe guardado supera lo reclamado por ese consumidor.** Un expediente de 6,00 € registra 16,00 € recuperados. Eso contradice frontalmente el invariante que sostiene `InvalidRecoveredAmountException`, cuya función es precisamente impedir que un importe recuperado supere lo reclamado.
2. **La banda de tolerancia se autoactiva por error.** `discrepancyRatio()` pasa a ser del 166 % y `hasMaterialDiscrepancy()` devuelve verdadero, así que **todo** expediente con más de un reclamante escala a Coordinación aunque la caja cuadre al céntimo. Esto explica por qué `DISCREPANCY_TOLERANCE` no es solo irrelevante, sino que en el caso multi-reclamante es lo que dispara el escalado.

**¿Sale dinero indebido?** No, y por un motivo que conviene dejar escrito: la liquidación está acotada por `approved_amount <= claimed_amount`, así que el coordinador puede firmar como máximo 6,00 € en el caso B aunque la pantalla le enseñe 16,00 € recuperados. La suma de lo pagado nunca puede exceder la suma de lo reclamado. **El defecto es de datos, de trazabilidad y de interfaz**, no de caja.

**Por qué el orden de las correcciones importa.** Antes de acotar el visto bueno, este defecto sí era un agujero: un coordinador podía firmar 16,00 € sobre una reclamación de 6,00 € porque `approved_amount` solo comprobaba el bloque de 50,00 €. La corrección de la quinta tanda no arregla este defecto, pero **neutraliza su consecuencia monetaria**. Si alguien revierte el techo del visto bueno, esta conciliación falsa vuelve a ser un agujero de pago.

**Arreglo propuesto.** Repartir el total entre los expedientes proporcionalmente a lo reclamado, y dejar el sobrante —el caso en que se recupera más de lo reclamado en conjunto— como efectivo no reclamado. Es un cambio de comportamiento que requiere especificación previa: hoy RF-REF-04 solo contempla el sobrante cuando **no hay ninguna** reclamación previa, no cuando el sobrante existe con reclamaciones abiertas.

---

## 5. Lo que se comprobó y NO es contradicción

### 5.1 La cifra que muestra la bandeja y la que exige la API

`CoordinatorRefundViewDTO::payableAmount()` cae al importe **recuperado** cuando no hay visto bueno, mientras que el servicio exige el importe **reclamado**. A primera vista parece una contradicción y hay una prueba de integración que lo deja claro — pero solo es alcanzable cuando `recovered != claimed`, y hoy **toda** discrepancia escala a Coordinación (§3), donde el importe aprobado manda y ambas cifras coinciden.

Comprobado sobre la tabla de §3: las únicas filas que llegan a `VERIFIED_PENDING_PAYMENT` tienen brecha 0 %, así que la vista y la API coinciden siempre. **Por eso el modal de pago ya no puede proposer una cifra que la API rechaza** (corregido en la quinta tanda, que pasó a prellenar con `approved_amount ?? claimed_amount`).

**Aviso prospectivo, y es el punto más importante de este documento:** en cuanto alguien implemente la banda del 20 % (opción A de §3), las dos columnas divergen de verdad. La vista enseñará el importe verificado y la API exigirá el reclamado. Si se hace ese cambio **después** de este análisis y sin tocar la vista, la divergencia reaparece con hasta un 20 % de diferencia, es decir hasta 10,00 € en un expediente de 50,00 € — y el coordinador verá una cantidad y el sistema cobrará por otra.

Por eso la opción B de §3 no es solo la más simple: es la única que no obliga a volver a tocar la proyección de Coordinación.

### 5.2 El medio céntimo

`MONEY_DECIMALS = 2` no es un tope, es una definición de qué es una cantidad de dinero. No se contradice con ningún otro umbral: `50,00`, `10,00` y el `20 %` se evalúan todos sobre importes ya redondeados a céntimos.

---

## 6. Resumen

| # | Cuestión | Veredicto |
| :--- | :--- | :--- |
| 1 | ¿50 € por reclamación vs. visto bueno acotado? | Coherente. El 50 € es ahora el techo efectivo real. |
| 2 | ¿10 € de conserjería vs. 10 € de visto bueno? | Coherente. Distinta semántica, mismo valor, misma desigualdad. |
| 3 | ¿Techo agregado por avería? | No existe, pero el pago sin firma exige caja real. Decisión de negocio, no contradicción. |
| 4 | ¿La banda de tolerancia del 20 %? | **Contradicción.** No está implementada; cualquier déficit escala. `DISCREPANCY_TOLERANCE` es código muerto. |
| 5 | ¿`recovered_amount` por expediente? | **Contradicción.** Cada caso guarda el total de la avería; supera lo reclamado y falsea la conciliación. |
| 6 | ¿Cifra mostrada vs. cifra exigible? | Coherente hoy. **Se rompería** si se activase la banda del 20 %. |
| 7 | ¿Importes con más de dos decimales? | Coherente. No es un umbral, es la definición de céntimo. |

**Ninguna de las dos contradicciones permite hoy mover más dinero del debido.** La cuarta tanda y la quinta ya acotaron las tres vías que sí lo permitían. Lo que queda es una especificación que promete una tolerancia inexistente y un rastro de conciliación que no cuadra cuando hay más de un reclamante en la misma avería.

## 7. Decisiones que corresponden al negocio

1. ¿Tolerancia del 20 % real, o corregir la especificación para que refleje el escalado por cualquier déficit? (Recomendado: corregir la especificación.)
2. Si se decide repartir el efectivo recuperado entre varios reclamantes, hace falta una regla de reparto y una regla para el sobrante con reclamaciones abiertas. Hoy ninguna de las dos existe.
3. ¿Debe existir un techo agregado por máquina o por sede? No hace falta para la seguridad del módulo, pero es la única forma de acotar el peor caso de N × 50 €.