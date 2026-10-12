<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

/**
 * LockWaitBudgetInterface
 *
 * Puerto de la **cota de espera de bloqueo** de una sesión de base de datos
 * (T-PAUSE-40, RF-03.5.1, RF-03.5.2, Constitución Art. II).
 *
 * Problema que resuelve: un proceso de fondo que comparte filas con consumidores
 * interactivos no puede heredar el tiempo de espera del servidor —**50 s** en
 * MariaDB—. Si lo hace, un tenedor que se alarga deja la pasada bloqueada un
 * minuto por fila y el aplazamiento, que es la señal útil, nunca llega a verse.
 * Con una cota corta el proceso falla rápido, clasifica el aplazamiento y sigue
 * con el lote: la máquina aplazada se reintenta en la pasada siguiente.
 *
 * Contrato del puerto:
 * 1. La cota vale **sólo para la operación** y se restituye al terminar, tanto si
 *    la operación termina bien como si lanza: la sesión no queda tocada por un
 *    fallo ajeno a ella.
 * 2. **Nunca eleva** una cota ya más estricta que la pedida: si la sesión ya
 *    espera menos, la elección del operador se respeta.
 * 3. Sólo se toca la **sesión**, jamás el valor del servidor: una pasada de fondo
 *    no puede cambiar cómo esperan los demás procesos.
 * 4. Una cota imposible se rechaza **antes** de tocar la sesión (Fail-Fast).
 *
 * Dogma Vanilla: interfaz pura, tipado estricto, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
interface LockWaitBudgetInterface
{
    /**
     * Ejecuta la operación bajo la cota de espera de bloqueo indicada y restituye
     * la anterior al terminar.
     *
     * @param int $seconds Cota de espera de bloqueo, en segundos (al menos 1).
     * @param callable():mixed $operation Operación que debe correr bajo la cota.
     * @return mixed El resultado de la operación, tal cual.
     *
     * @throws \InvalidArgumentException Si la cota no es un valor positivo.
     */
    public function runWithLockWaitBudget(int $seconds, callable $operation): mixed;
}
