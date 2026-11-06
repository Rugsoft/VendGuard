# AUDITORÍA DE LA PR #15

## Registro APPCC de mermas y levantamiento de cuarentena (Módulo 29)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)
**Pull Request:** [#15 · feat(spoilage): registro APPCC de mermas y levantamiento de cuarentena (Módulo 29)](https://github.com/Rugsoft/VendGuard/pull/15)
**Rama:** `spoilage-log` → `main`
**Commit de cabeza:** `0b85c8d430b52bd61f20ab19697fdd705ad080e8`
**Base verificada:** `main` en `9db60dd2e2c9c3f78495355004ab723078c2e5bc`
**Fecha de revisión:** 2026-11-06
**Alcance:** contraste de la rama con `main`: sintaxis, esquema PostgreSQL, persistencia, API, UI, semillas y documentación SDD.
**Resultado:** **5 hallazgos revisados**: 3 resueltos (1 alta, 1 media y 1 media-baja) y 2 abiertos (2 bajas). La PR sigue `MERGEABLE` / `CLEAN` y `main` es ancestro de la rama; no se ha fusionado y las correcciones 1, 2 y 3 están aplicadas en el árbol de trabajo local.

> Este documento recoge únicamente los hallazgos de la revisión. No modifica la especificación aprobada ni aplica correcciones sobre la PR.

---

## 1. Resumen

| # | Hallazgo | Severidad | Tipo | Estado |
| :---: | :--- | :---: | :--- | :---: |
| 1 | Las fotos de las actas sembradas no están versionadas en Git | **Alta** | Funcional / reproducibilidad | Resuelto |
| 2 | El filtro de fechas usa UTC y no el día operativo de Madrid | **Media** | Funcional | Resuelto |
| 3 | La numeración correlativa se rompe al superar 9999 | **Media-baja** | Funcional latente | Resuelto |
| 4 | Las cifras del esquema no coinciden entre los documentos SDD | **Baja** | Documental | Abierto |
| 5 | El mensaje del coordinador pierde el código del acta | **Baja** | Cosmético | Abierto |

### Verificación ejecutada

- `php tests/run_all.php`: **400 suites · 14.984 aserciones · 0 fallos**.
- `php tests/run_new_engine.php Spoilage`: **3 suites · 153 aserciones · 0 fallos**.
- `node tests/unit/SanitarySpoilageFrontendTest.mjs`: **74/74 aserciones**.
- `php -l` y `node --check` sobre los archivos modificados: sin errores.
- GitHub no tiene checks configurados para la rama; por tanto, `CLEAN` no equivale a una CI verde.

---

## 2. Hallazgos

### 2.1. Las fotos de las actas sembradas no están versionadas en Git

**Severidad:** Alta para la reproducibilidad de la PR; no afecta a las actas nuevas subidas por usuarios.
**Tipo:** Funcional / reproducibilidad.

**Evidencia**

- `database/supabase_seeds.sql:1582`, `:1602` y `:1622` siembran tres actas con estas rutas:
  - `/uploads/spoilage/ACTA-MERMA-2026-0001.jpg`
  - `/uploads/spoilage/ACTA-MERMA-2026-0002.jpg`
  - `/uploads/spoilage/ACTA-MERMA-2026-0003-empty.jpg`
- `src/Infrastructure/Database/SeedRunner.php` siembra las mismas rutas, pero no copia ni genera las imágenes.
- Los tres JPEG existen en el árbol de trabajo local y son imágenes válidas de 900 × 600, pero no están en Git:
  - `git ls-files public/uploads/spoilage` devuelve vacío.
  - `git ls-tree -r origin/spoilage-log public/uploads/spoilage` devuelve vacío.
  - `.gitignore:27` ignora `public/uploads/*`.

**Impacto**

En un clon limpio o en un despliegue que no herede los archivos ignorados de la máquina del autor, las tres actas sembradas apuntan a recursos inexistentes. El Acta Oficial A4 carga una URL que el servidor no puede servir y el navegador muestra la evidencia rota. Las suites actuales pasan porque se ejecutan sobre este árbol, donde los JPEG sí existen, pero no comprueban que las rutas sembradas estén versionadas.

**Recomendación**

Versionar los tres JPEG en una ruta de fixtures, por ejemplo `database/fixtures/spoilage/`, y apuntar allí las semillas; o añadir una excepción explícita en `.gitignore` para esos archivos. Si la política es mantener `uploads` fuera de Git, `SeedRunner` debe copiarlos desde un fixture versionado y el arranque debe fallar de forma explícita si no existen. Añadir una prueba que verifique que las tres rutas sembradas existen y que el recurso se sirve correctamente.

**Resolución aplicada (2026-11-06)**

- Las tres evidencias se copiaron a `public/assets/seed/spoilage/`, fuera de `public/uploads/`, y las referencias de `database/supabase_seeds.sql` y `SeedRunner` apuntan ahora a `/assets/seed/spoilage/...`.
- La prueba `SanitarySpoilageFrontendTest.mjs` (§7) comprueba que los tres archivos existen, que tienen cabecera JPEG y que las semillas SQL y programática usan exactamente las mismas tres rutas versionadas, sin referencias a `/uploads/spoilage`.
- Verificación: frontend 74/74.

---

### 2.2. El filtro de fechas usa UTC y no el día operativo de Madrid

**Severidad:** Media.
**Tipo:** Funcional.

**Evidencia**

- `PdoSanitarySpoilageRecordRepository.php:650-662` construye los límites como `YYYY-MM-DD 00:00:00` y los convierte con `CAST(... AS timestamptz)`.
- La sesión verificada de PostgreSQL está en **UTC**, por lo que un límite sin zona se interpreta como medianoche UTC.
- El día operativo de Madrid empieza a las `2026-03-14 23:00:00+00` para el `2026-03-15`.
- En consecuencia, un acta registrada a las **00:30 de Madrid del 15 de marzo** (`2026-03-14 23:30Z`) queda fuera del filtro; una registrada a las **00:30 de Madrid del 16 de marzo** (`2026-03-15 23:30Z`) entra en el filtro del 15.

**Impacto**

El listado de Coordinación y la exportación CSV pueden omitir o incluir actas de la primera hora del día operativo. Los KPIs globales no usan este filtro, pero la trazabilidad del libro de mermas y la exportación auditada sí quedan afectadas. Las pruebas actuales no cubren los bordes de medianoche en `Europe/Madrid`.

**Recomendación**

Construir los límites con la zona explícita:

```sql
r.created_at >= (CAST(:date_from AS timestamp) AT TIME ZONE 'Europe/Madrid')
r.created_at <  (CAST(:date_to   AS timestamp) AT TIME ZONE 'Europe/Madrid')
```

Añadir pruebas con actas a las `00:30` y `23:30` de Madrid, tanto en horario de invierno como de verano.

**Resolución aplicada (2026-11-06)**

- `findFilteredForCoordinator()` aplica `AT TIME ZONE 'Europe/Madrid'` a los dos límites, de modo que el rango se calcula sobre el día operativo y no sobre la zona de sesión UTC.
- La prueba `SanitarySpoilageServiceIntegrationTest` (§8) inserta actas a las 00:30 y 23:30 de Madrid en marzo (CET) y julio (CEST), y verifica que las de los días vecinos no se arrastran.
- El caso §7 existente se ajustó a `23:59:59.5+01:00` para que el límite superior siga midiendo el último instante del día de Madrid.
- Verificación: canal Spoilage 3 suites · 153 aserciones · 0 fallos.

---

### 2.3. La numeración correlativa se rompe al superar 9999

**Severidad:** Media-baja; defecto latente, no reproducible con el volumen actual.
**Tipo:** Funcional latente.

**Evidencia**

- `nextRecordCode()` usa `SUBSTRING(record_code FROM '([0-9]{4})$')` y `sprintf('%04d', $next)` (`PdoSanitarySpoilageRecordRepository.php:305-316`).
- `nextPreventiveOrderCode()` aplica el mismo patrón (`:982-994`).
- Con el código `...-9999`, el siguiente es `...-10000`, que ya tiene cinco dígitos.
- En la llamada siguiente, la expresión regular solo captura `0000`, calcula el siguiente como `1` y devuelve `...-0001`, que puede colisionar con un código ya existente y provocar un error de unicidad.
- `nextCertificateCode()` usa `([0-9]+)$` (`:341-352`), por lo que no duplica el correlativo, pero cambia el formato a cinco dígitos.

**Impacto**

Al alcanzar 10.000 actas u órdenes preventivas en un año, el sistema puede generar un correlativo incorrecto o fallar al intentar insertar un código duplicado. Es un caso límite, pero afecta a la numeración oficial y a la trazabilidad documental.

**Recomendación**

Usar `([0-9]+)$` y `str_pad((string)$next, 4, '0', STR_PAD_LEFT)`, o rechazar explícitamente el siguiente código si el contrato exige cuatro dígitos. Cubrir con una prueba la secuencia `9999 → 10000 → 10001` para actas, órdenes preventivas y certificados.

**Resolución aplicada (2026-11-06)**

- `nextRecordCode()` y `nextPreventiveOrderCode()` capturan ahora el sufijo completo con `([0-9]+)$`; los tres generadores formatean el número con `str_pad(..., 4, '0', STR_PAD_LEFT)`. El formato mantiene cuatro dígitos como mínimo y crece a cinco a partir de 10000, sin truncar el sufijo ni reutilizar códigos.
- `SanitarySpoilageServiceIntegrationTest` §9 inserta los códigos `9999` y `10000` y certifica las secuencias `9999 → 10000 → 10001` en actas, certificados y órdenes preventivas.
- Verificación: canal Spoilage 3 suites · 153 aserciones · 0 fallos.

---

### 2.4. Las cifras del esquema no coinciden entre los documentos SDD

**Severidad:** Baja.
**Tipo:** Documental.

**Evidencia**

- El esquema real tiene **22 columnas** y **9 restricciones `CHECK`**.
- `specs/29-haccp-spoilage-log/plan.md` habla de **21 columnas** y **8 `CHECK`**, aunque enumera nueve nombres de restricciones (`:70` y `:355`).
- `specs/technical/database_schema.md:103` indica **22 columnas pero ocho `CHECK`**.
- `specs/technical/schema_correspondence_contract.md:90` repite **ocho `CHECK`**.
- El comentario de `database/supabase_migrations/025_sanitary_spoilage_records.sql:27` y el resumen de `database/supabase_init.sql` también dicen “ocho”.
- `specs/29-haccp-spoilage-log/tasks.md:46` sí declara 22 columnas y 9 `CHECK`, y el cuerpo de la PR también usa 22/9.

**Impacto**

No hay impacto en tiempo de ejecución, pero la especificación técnica, el plan y los contratos de correspondencia no describen la misma tabla. En un proyecto SDD esto rompe la trazabilidad y puede inducir a error en futuras migraciones o auditorías.

**Recomendación**

Actualizar los conteos y el comentario de la migración para que todos los documentos declaren 22 columnas y 9 `CHECK`. Mantener `tasks.md` y el cuerpo de la PR como referencia correcta.

---

### 2.5. El mensaje del coordinador pierde el código del acta

**Severidad:** Baja.
**Tipo:** Cosmético.

**Evidencia**

- `SanitarySpoilageService.php:217-220` devuelve el resultado del levantamiento con esta forma:

```php
[
    'spoilage_record' => $record->toCoordinatorArray(),
    // ...
]
```

- `CoordinatorSanitarySpoilageTab.js:389` lee `result?.record_code`, pero el código está en `result.spoilage_record.record_code`.
- El modal `SanitarySpoilageLiftModal.js:268` y `:584` ya lee la ruta anidada correctamente, lo que confirma la forma real de la respuesta.

**Impacto**

El levantamiento se completa correctamente, pero el aviso de éxito del coordinador muestra el mensaje genérico “Acta de merma emitida y cuarentena levantada” en lugar de incluir el código oficial del acta. El código sigue visible en el listado y en el A4; el defecto es solo de feedback inmediato.

**Recomendación**

Cambiar la lectura a `result?.spoilage_record?.record_code` y añadir una aserción al test de la superficie de coordinación que compruebe que el mensaje incluye el código emitido.

---

## 3. Orden de corrección sugerido

1. **Hallazgo 1 — resuelto:** las evidencias viven ahora en `public/assets/seed/spoilage/` y las semillas apuntan a esa ruta.
2. **Hallazgo 2 — resuelto:** los límites del filtro usan `Europe/Madrid` y los bordes horarios están cubiertos por la suite de integración.
3. **Hallazgo 3 — resuelto:** la numeración conserva cuatro dígitos como mínimo y crece a cinco sin reutilizar códigos.
4. **Hallazgo 4:** alinear la documentación SDD con el esquema real.
5. **Hallazgo 5:** corregir el mensaje de éxito y su prueba.

---

**Estado final de la revisión:** PR `MERGEABLE` / `CLEAN`, sin conflictos con `main`, con tres hallazgos resueltos y dos abiertos; las correcciones 1, 2 y 3 están aplicadas en el árbol de trabajo local.
