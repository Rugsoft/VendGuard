<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Storage;

use Throwable;

/**
 * FileStorageCompensator — transaccionalidad compensatoria del almacenamiento local de
 * evidencias fotográficas (EARS-05.2, EARS-05.3, EARS-05.4, RNF-03, T-HYBRID-08).
 *
 * **El problema.** La evidencia se escribe en disco **antes** de persistir la fila que la
 * referencia (EARS-05.2: la ruta relativa viaja en la base de datos). Si la inserción falla
 * —el motor administrado agota el tiempo de espera, se pierde el enlace, o salta la
 * condición de carrera de duplicado— el fichero queda **huérfano**: nadie lo referencia,
 * nadie lo borra y el disco se llena de fotos que ningún expediente reclama. En un motor
 * remoto esa no es una rareza local, es el modo de error ordinario del enlace.
 *
 * **La compensación.** Un fichero no se «deshace» con una transacción: o se retira o se
 * queda. Este componente ejecuta la operación de base de datos que da sentido al fichero y,
 * si esa operación lanza, **retira el fichero inmediatamente** —antes de dejar viajar la
 * excepción— delegando el borrado físico en el **mismo componente que lo escribió**,
 * [`LocalFileUploader::delete()`](LocalFileUploader.php), y sobre la **ruta pública** que
 * devolvió la subida. Aquí no hay un segundo `unlink()`: la regla de dónde vive el fichero
 * y cómo se retira pertenece a quien lo escribe.
 *
 * Por eso el compensador se compone con **la misma instancia** del subidor que hizo la
 * subida (mismo directorio de destino) y no con una copia: la ruta pública que recibe es
 * exactamente la que aquel sabe resolver.
 *
 * **Contrato del llamante.** Sólo se pasa la ruta que **esta petición acaba de escribir**, o
 * la cadena vacía si la petición no escribió ninguna (sin adjunto, o ruta que llegó ya hecha
 * en el cuerpo del formulario): un fichero ajeno no se compensa nunca, porque retirarlo sería
 * destruir evidencia de otro expediente. La cadena vacía no es un error de programación, es
 * el caso declarado de «no hay nada propio que compensar».
 *
 * **Límites declarados.** (1) Si el propio borrado falla —disco no escribible, permisos— la
 * fuga queda anotada en el registro del servidor y la excepción **original** sigue su camino:
 * una segunda excepción no puede sustituir a la verdadera causa. (2) La compensación cubre la
 * ventana entre la subida y la persistencia; lo que ocurra **después** de que la fila exista
 * no la ejecuta, porque entonces el fichero ya está referenciado y retirarlo rompería el
 * expediente en lugar de limpiarlo.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas. Código en inglés, documentación en
 * castellano.
 */
final class FileStorageCompensator
{
    /** Subidor dueño del directorio: el mismo que escribió el fichero. */
    private LocalFileUploader $fileUploader;

    /**
     * @param LocalFileUploader|null $fileUploader Subidor que realizó la subida. Se comparte
     *   instancia a propósito: la ruta pública sólo es resoluble por el componente que la
     *   entregó, y con él viaja su directorio de destino.
     */
    public function __construct(?LocalFileUploader $fileUploader = null)
    {
        $this->fileUploader = $fileUploader ?? new LocalFileUploader();
    }

    /**
     * Ejecuta la operación de base de datos que da sentido al fichero recién subido y, si
     * lanza, retira el fichero huérfano antes de propagar la excepción original.
     *
     * @param string $publicPath Ruta pública devuelta por la subida de **esta** petición
     *   (`/uploads/<hash>.<ext>`), o cadena vacía si no se escribió ningún fichero propio.
     * @param callable $dbOperation Operación cuyo resultado depende de que el fichero exista:
     *   la inserción del expediente, del comentario o del aviso que guarda la ruta.
     *
     * @return mixed El valor que devuelva la operación, intacto.
     *
     * @throws Throwable La excepción original de la operación, siempre: la compensación
     *   limpia el disco pero no cambia el desenlace de la petición.
     */
    public function executeWithCompensatingFile(string $publicPath, callable $dbOperation): mixed
    {
        try {
            return $dbOperation();
        } catch (Throwable $exception) {
            $this->compensate($publicPath);

            throw $exception;
        }
    }

    /**
     * Retira el fichero huérfano delegando en el subidor, sin sustituir nunca la causa real
     * del fallo de persistencia (ver «Límites declarados» en la cabecera de la clase).
     */
    private function compensate(string $publicPath): void
    {
        if (trim($publicPath) === '') {
            return;
        }

        try {
            $retired = $this->fileUploader->delete($publicPath);
        } catch (Throwable $failure) {
            error_log(sprintf(
                '[vendguard] Compensación de archivo no ejecutada sobre %s: %s',
                $publicPath,
                $failure
            ));

            return;
        }

        error_log(sprintf(
            '[vendguard] Compensación ejecutada tras el fallo de persistencia (EARS-05.3): %s en %s',
            $retired ? 'archivo huérfano retirado' : 'no había ya archivo que retirar',
            $publicPath
        ));
    }
}
