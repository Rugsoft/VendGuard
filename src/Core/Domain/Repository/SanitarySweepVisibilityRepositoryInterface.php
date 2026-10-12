<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use DateTimeImmutable;
use VendGuard\Core\Domain\Model\SanitarySweepContention;
use VendGuard\Core\Domain\Model\SanitarySweepPass;

/**
 * SanitarySweepVisibilityRepositoryInterface — Puerto del **libro de pasadas** y
 * de los **seguimientos de contención** del barrido sanitario (T-PAUSE-41,
 * RF-07, Constitución Art. II y Art. III).
 *
 * Responsabilidad única: persistir y leer la evidencia operativa del barrido. No
 * decide nada: cuándo escala un aplazamiento lo dice
 * `SanitarySweepEscalationPolicy`, y qué cuenta cada pasada lo dice el resumen
 * del propio barrido. Este puerto sólo guarda y devuelve.
 *
 * Contratos que el adaptador debe respetar:
 * 1. **El libro se escribe al final de la pasada** (`recordPass`) y **nunca**
 *    antes de consumir el reloj: la observabilidad no puede impedir una
 *    cuarentena (Art. II). Si la escritura falla, el fallo viaja en voz alta y
 *    las cuarentenas ya confirmadas quedan firmes e idempotentes.
 * 2. **`saveContentionDetection()` es un upsert por expediente**: la clave es
 *    única por `incident_id` y se reutiliza, de modo que un episodio nuevo
 *    reescribe la fila en lugar de acumular una por aplazamiento.
 * 3. **La resolución es por IDENTIDAD, no por marca de tiempo**: la pasada cierra
 *    exactamente los seguimientos abiertos cuyo expediente **no** está en su
 *    lista de aplazados. Se descartó comparar contra el instante de arranque
 *    porque las marcas tienen resolución de segundo y dos pasadas de milisegundos
 *    pueden caer dentro del mismo: preguntar «¿a quién TOQUÉ?» es exacto, mientras
 *    que «¿qué es más antiguo que yo?» depende de una precisión que no existe.
 * 4. **Sólo lectura para la superficie**: `findOpenContentions()`,
 *    `findLatestPass()` y `findPassesSince()` no escriben nada.
 * 5. **La observación no compite con lo observado**: la tabla de seguimientos no
 *    declara clave foránea hacia `machines` a propósito. Insertar una fila hija
 *    toma un bloqueo compartido de su padre, así que un seguimiento que apuntara
 *    a la fila de la máquina quedaría sin poder escribirse justo mientras otro
 *    consumidor la retiene —la única situación que esta tabla existe para
 *    registrar— y el barrido convertiría un aplazamiento en un fallo. `machine_id`
 *    se conserva como referencia desnormalizada y la clave foránea que sí queda
 *    es la del expediente, que el tenedor de la máquina no bloquea.
 *
 * Dogma Vanilla: interfaz pura, tipado estricto, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
interface SanitarySweepVisibilityRepositoryInterface
{
    /**
     * Registra una pasada terminada y devuelve el registro con su identificador.
     *
     * @param SanitarySweepPass $pass Resumen de la pasada (con `id` nulo).
     * @return SanitarySweepPass El mismo registro, ya con `id`.
     */
    public function recordPass(SanitarySweepPass $pass): SanitarySweepPass;

    /**
     * Seguimiento vigente de un expediente, esté abierto o cerrado por una
     * pasada anterior.
     *
     * Se lee **antes** de decidir el siguiente estado porque el episodio anterior
     * explica si el aplazamiento de hoy continúa una racha o arranca una nueva
     * (RF-07.2).
     */
    public function findContentionByIncident(int $incidentId): ?SanitarySweepContention;

    /**
     * Persiste el estado resultante de una detección (upsert por expediente).
     */
    public function saveContentionDetection(SanitarySweepContention $contention): void;

    /**
     * Cierra los seguimientos abiertos que la pasada que acaba de terminar ya **no**
     * aplazó, y devuelve cuántos cerró (RF-07.5).
     *
     * La identidad de los aplazados la aporta el propio barrido —son los candidatos
     * que viajaron a `contended` en esa pasada—, de modo que el cierre no depende de
     * ninguna comparación de marcas temporales: se cierra exactamente lo que esta
     * pasada no tocó.
     *
     * @param list<int> $detectedIncidentIds Expedientes aplazados en la pasada que resuelve.
     * @param DateTimeImmutable $resolvedAt Instante del cierre.
     * @return int Seguimientos cerrados por esta pasada.
     */
    public function resolveContentionsNotDetectedIn(array $detectedIncidentIds, DateTimeImmutable $resolvedAt): int;

    /**
     * Seguimientos **abiertos**, con la presentación del expediente y de la
     * máquina ya resuelta (código, sede, planta y estados).
     *
     * Orden: escalados primero y, dentro de cada grupo, el más antiguo delante,
     * para que ninguna alerta quede por debajo de un aplazamiento reciente.
     *
     * @return list<SanitarySweepContention>
     */
    public function findOpenContentions(): array;

    /**
     * Última pasada registrada, o `null` si el barrido no ha dejado ninguna.
     */
    public function findLatestPass(): ?SanitarySweepPass;

    /**
     * Pasadas registradas desde un instante, en orden cronológico.
     *
     * @return list<SanitarySweepPass>
     */
    public function findPassesSince(DateTimeImmutable $since): array;
}
