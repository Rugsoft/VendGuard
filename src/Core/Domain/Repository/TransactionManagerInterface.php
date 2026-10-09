<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

/**
 * TransactionManagerInterface
 *
 * Puerto de unidad de trabajo (Art. III): agrupa en una sola transacción las
 * escrituras que varios repositorios hacen sobre agregados distintos, de modo
 * que un caso de uso compuesto no deje estados a medias.
 *
 * Motivación concreta (Algoritmo 5, T-PAUSE-09): cancelar una avería por
 * inactividad de sede toca tres agregados —la incidencia pasa a `CANCELLED`,
 * la máquina queda bloqueada por falta de acceso y los reintegros económicos
 * se desvinculan de la avería—. Sin una transacción exterior, un fallo entre
 * la segunda y la tercera escritura dejaría una máquina bloqueada por una
 * avería que no se canceló, o un ticket cancelado con su máquina en verde,
 * que es exactamente lo que prohíbe el Art. V.1.
 *
 * Los repositorios derivan de este contrato sin conocerlo: cada uno abre su
 * propia transacción SOLO si no hay una en curso (`PDO::inTransaction()`), así
 * que la participación es transparente.
 */
interface TransactionManagerInterface
{
    /**
     * Ejecuta la operación dentro de una transacción y devuelve su resultado.
     *
     * Si ya hay una transacción abierta, la operación simplemente participa en
     * ella: el dueño es quien la abrió, y solo él confirma o deshace. Un fallo
     * dentro de la operación deshace la transacción propia y propaga la
     * excepción original, sin enmascararla.
     *
     * @template T
     * @param callable():T $operation Trabajo a ejecutar de forma atómica.
     * @return T Resultado devuelto por la operación.
     * @throws \Throwable La excepción lanzada por la operación, tras deshacer.
     */
    public function runInTransaction(callable $operation): mixed;
}
