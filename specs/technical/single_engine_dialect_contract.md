# CONTRATO DEL DIALECTO DEL MOTOR ÚNICO

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)
**Documento:** `specs/technical/single_engine_dialect_contract.md`
**Módulo:** `13-single-engine-supabase` · **Tarea de origen:** T-13-06 (invierte el inventario de T-HYBRID-25)
**Requisitos:** EARS-13.4, EARS-13.10 (**[`spec.md`](../13-single-engine-supabase/spec.md)**)
**Normativa:** [`constitution.md`](../../constitution.md) Art. I.3 · [`AGENTS.md`](../../AGENTS.md) §4 y §5
**Contrato hermano:** [`testing_cleanup_contract.md`](testing_cleanup_contract.md) §10 (el dialecto del SQL de la batería)
**Guarda:** [`tests/unit/SqlDialectPortabilityTest.php`](../../tests/unit/SqlDialectPortabilityTest.php) (97 aserciones)
**Censo:** [`tests/Support/DialectCensus.php`](../../tests/Support/DialectCensus.php)

---

## 1. La regla

Con un solo motor, el dialecto del motor retirado **no se tolera en ninguna parte**: se declara, o se
repara. Y no basta con que no esté: lo que el sistema escribe tiene que estar **en la grafía que el
motor único entiende**.

1. **Prohibición.** Ninguna de las cuatro superficies rastreadas —`src`, `database`, `bin` y `tests`—
   contiene dialecto del motor retirado salvo en los sitios **declarados** en la guarda.
2. **Exigencia.** Las construcciones que el sistema realiza —upsert, lectura de JSON, aritmética
   temporal, introspección del catálogo y ventana de purga del arnés— están escritas en la forma del
   motor único, y esa forma está presente y medida.

La declaración es **exacta y viva**: dice **qué** es cada aparición, **cuántos literales** tiene y
**quién la cierra**; una cifra que deja de ser cierta, un literal nuevo, un literal que desaparece sin
retirar su entrada, o una deuda cuyo dueño ya está cerrado en el plan de tareas, ponen la guarda en
rojo. Una excepción con el plazo cumplido es una excepción sin pagar.

---

## 2. Qué se mide, y cómo

La unidad es **el literal de cadena**, no la línea: se cuentan los literales **tokenizados**
(`token_get_all`) que contienen la familia —de modo que el total por familia puede sumar más que el
número de literales del fichero, porque un literal puede declarar dos familias— y comentarios y
docblocks quedan fuera: la documentación que cita sintaxis del motor retirado no es dialecto.

Cuatro propiedades del censo están declaradas a propósito, porque explican cifras que si no parecerían
erróneas:

| Propiedad | Por qué |
|---|---|
| Los identificadores entre acentos graves y el operador JSON sólo cuentan dentro de un literal que parece SQL | Si no, cualquier mensaje o plantilla con acentos graves —o el `->` de una flecha de un texto— se leería como dialecto |
| Las cadenas con interpolación se cuentan **por fragmentos**, que es como las ve el intérprete | Un `"... WHERE a = '{$v}' AND b = 1"` llega al censo como varios literales; una cita heredada puede quedar partida entre dos fragmentos |
| Las comillas escapadas se resuelven antes de comparar (`\'` → `'`) | El censo mide SQL, no su fuente: sin resolverlas, el operador `->>`, el intervalo con literal y la ventana de purga pasarían inadvertidos |
| El catálogo se mide **sensible a mayúsculas** en su grafía heredada (`information_schema.COLUMNS`) | `information_schema.columns` en minúsculas es una vista que el motor único **sí** tiene, y contarla sería un falso positivo |

Las familias del dialecto heredado (10) y las del motor único (8) viven en un solo punto,
[`DialectCensus`](../../tests/Support/DialectCensus.php), para que **el censo que la guarda congela y la
medición del porte sean el mismo *rule set***: dos cifras de la misma clase tienen que ser comparables.
Ese fichero y el adaptador —[`SqlDialectAdapter`](../../src/Infrastructure/Dialect/SqlDialectAdapter.php)—
quedan fuera del rastro porque **citan el dialecto por definición**, y la guarda comprueba que ninguno
de los dos ejecuta SQL: una exclusión que no se comprueba es un escondite.

---

## 3. La declaración: qué naturaleza tiene cada aparición

| Naturaleza | Qué es | Dueño | Regla que la guarda exige |
|---|---|---|---|
| **deuda** | SQL real del sistema o de la batería que todavía está en el dialecto retirado | Una tarea **abierta** del módulo | Existe en `tasks.md` y está **abierta**: si se cierra y el dialecto sigue ahí, la guarda se pone en rojo |
| **contrato** | Una guarda que **compara** contra el artefacto heredado, que aún existe | La tarea que **retira ese artefacto** | Igual que la deuda: dueño existente y abierto |
| **guarda** | Un fichero que **nombra** el dialecto para prohibirlo o para certificar el punto único de traducción | — (no hay tarea que la cierre: prohibir no se termina) | Sin dueño atribuido |
| **fuera de alcance** | Fuera del alcance declarado del adaptador (el respaldo del mapa territorial sobre SQLite), no del sistema | — | Sin dueño; y **no vale en la batería** (§7.4 de la guarda) |
| **drenado** | Un fichero ya portado, declarado **a cero** | La tarea que lo portó (histórico) | Que siga a cero y que cite el descenso que declaró su deuda |

Además, un fichero que **mezcle las dos grafías de una misma construcción** —lo propio de una suite a
medio portar— tiene que declararlo (`'mixed' => true` con su motivo). Un híbrido sin declarar es un
fichero midiendo el dialecto en lugar de la conducta.

---

## 4. Qué certifica la guarda

* **Grupos 1 a 4** (heredados): el punto único de traducción, carácter a carácter, su rechazo en voz
  alta, su ejecución real contra el motor vivo y su conformidad con la tabla de upsert que declaran las
  semillas. Mientras la rama del motor retirado exista, el adaptador la renderiza y esto la fija: esa
  segunda forma es la aparición **declarada** con dueño, no una tolerancia. Su mitad heredada se reduce
  sola cuando muera la rama (T-13-14/T-13-16).
* **Grupo 5**: la superficie rastreada, la declaración (naturaleza, censo exacto, dueño vivo, mezcla).
* **Grupo 6**: que el **detector distingue**, contra especímenes en un fichero temporal —cada familia
  heredada se dispara, cada forma del motor único **no** dispara ninguna familia prohibida, la grafía
  minúscula del catálogo no cuenta, un comentario no cuenta, y una cita heredada dentro de un fragmento
  interpolado **se ve**—. Sin esta mitad, una guarda que no distingue no certifica nada.
* **Grupo 7**: la exigencia —las ocho construcciones del motor presentes, la ventana de purga con una
  sola forma, la superficie de ejecución sin más dialecto que el que la retirada va a retirar, la
  batería sin introducir dialecto nuevo, y el adaptador como único punto de traducción—.

---

## 5. Evidencia medida (2026-10-16, motor único PostgreSQL)

**Censo de la clase**, cuatro superficies, mismo *rule set* en las dos orillas (árbol anterior `13728a8`
y árbol entregado):

| Familia del dialecto heredado | Antes | Ahora | De lo que queda, en la guarda |
|---|---|---|---|
| Identificador entre acentos graves | 72 | **43** | 7 (sus renderizados y sus especímenes) |
| Catálogo de metadatos en su grafía heredada | 27 | **26** | 1 |
| Aritmética temporal del motor retirado | 27 | **5** | **5 → cero fuera de la guarda** |
| Introspección de columnas (`SHOW COLUMNS`/`SHOW INDEX`) | 13 | **13** | 1 (las 12 restantes, en el ejecutor de migraciones heredado) |
| Upsert heredado | 21 | **12** | 8 |
| `VALUES(columna)` heredado | 11 | **10** | 7 |
| Base del motor heredado (`DATABASE()`) | 16 | **7** | 1 |
| Extracción de JSON del motor retirado | 5 | **6** | 5 |
| Funciones de texto del motor retirado | 2 | **2** | 1 |
| Ventana de purga heredada (`SET @vendguard_purge`) | 4 | **1** | **1 → cero fuera de la guarda** |
| **Total** | **198 literales en 40 ficheros** | **125 literales en 19 ficheros** | **37 (la guarda)** |

Es decir: de los 125 literales, **88 en 18 ficheros** son dialecto declarado con dueño, y los 37
restantes son la propia guarda que lo renderiza y lo cita para probar el detector. Los dos sitios que el
censo del lote anterior no veía están ahora nombrados: la extracción de JSON del motor retirado dentro
de un **fragmento interpolado** de `CoordinatorPreventiveDetailApiTest` (dueño T-13-09) y las sondas del
estado de sesión del motor heredado en las suites de contención y de aislamiento (dueños T-13-11 y
T-13-12). Ninguno es un defecto nuevo: son sitios que ya estaban en la línea base y cuyo lote no ha
corrido todavía.

**Las cuatro degradaciones que se probaron** —la guarda muerde en las cuatro direcciones—:

| Degradación | Aserciones que se ponen en rojo |
|---|---|
| Una suite de la batería recupera un literal entre acentos graves | **5.3** (fichero sin declarar) |
| Un fichero drenado recupera dialecto | **5.4**, **5.5** y **7.3** |
| Una deuda cuyo dueño se cierra en `tasks.md` | **5.8** |
| Un fichero mezcla las dos grafías de una misma construcción | **5.10** |

**Corrida completa** (`php tests/run_all.php`): **261 suites · 8.815 aserciones · 47 rojas**, con el
**conjunto de suites rojas idéntico** al de la línea base de T-13-05 —ninguna suite verde pasa a roja y
ninguna roja se sana, porque esta tarea es de guarda y no de porte—. La guarda pasa de **84 a 97
aserciones** y las cifras documentadas siguen con sus **11 desviaciones** declaradas, que se actualizan
de una vez en T-13-13.

---

## 6. Lo que este contrato NO decide

* **El porte de las deudas declaradas.** Cada una pertenece a su lote (T-13-09 preventivo y repuestos,
  T-13-11 comentarios y pausas, T-13-12 ruta y sanitario, T-13-14 factoría y presupuesto de espera,
  T-13-15 arranque, T-13-16 retirada del DDL heredado) y se mide cuando le toca. La guarda las **nombra**
  con su cifra exacta, que es lo que permite saber si el lote avanzó.
* **La retirada de la rama heredada del adaptador** (spec §8.2, duda 3): los grupos 1 a 4 de la guarda se
  reducen solos cuando ocurra, y su censo declarado lo obliga.
* **El respaldo SQLite del mapa territorial**, declarado fuera del alcance del adaptador.
* **Las cifras del README y de los informes de cierre**, que se mueven de una vez en T-13-13.

---

## 7. Cómo se mide y se reproduce

```bash
# La guarda, con el saldo declarado y el saldo real de las cuatro superficies:
php tests/unit/SqlDialectPortabilityTest.php

# La corrida completa (la guarda va dentro, como suite unitaria):
php tests/run_all.php
```

La guarda imprime el saldo —fichero, naturaleza, dueño y censo de cada aparición declarada, más la
mezcla de grafías— de modo que la evidencia de este contrato se puede volver a leer sin depender de
ningún tool externo al repositorio.
