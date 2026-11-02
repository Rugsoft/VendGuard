<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Service;

use InvalidArgumentException;

/**
 * ReassignmentReasonValidator — puerta única del motivo de reasignación (Módulo 26).
 *
 * El Coordinador no reasigna «porque sí»: el traspaso de una avería a otro
 * técnico deja rastro en la cadena de custodia y exige una explicación con
 * contenido real (R1, QA 1). La regla no es un mínimo de longitud bruto: diez
 * guiones, diez puntos o diez espacios intercalados ocupan lo mismo que una
 * frase y no explican nada.
 *
 * Por eso se valida sobre el texto **normalizado**: se recortan los extremos, se
 * colapsan las secuencias internas de espacios en blanco a un único espacio, y
 * sólo entonces se exigen diez caracteres efectivos (`mb_strlen` en UTF-8) y al
 * menos una letra o un dígito (`/\p{L}|\p{N}/u`). Un motivo aceptado se devuelve
 * ya normalizado, de modo que lo que se guarda es exactamente lo que se midió.
 *
 * **Independencia constitucional.** Esta puerta no conoce ni sustituye la
 * salvaguarda sanitaria de `override_justification` (≥ 20 caracteres, Art. II y
 * Art. V.1): son dos validaciones distintas, con dos mensajes distintos y dos
 * momentos distintos. Mezclarlas permitiría que un texto largo eludiera el
 * control sanitario o que un motivo de custodia breve pasara por suficiente.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias ni estado.
 * Dualismo Lingüístico: código en inglés, mensajes en castellano.
 */
final class ReassignmentReasonValidator
{
    /** Longitud efectiva mínima del motivo, medida tras normalizar. */
    public const MIN_LENGTH = 10;

    /**
     * Normaliza y valida el motivo de una reasignación de custodio.
     *
     * @param string|null $rawReason Texto tal como llega del cliente.
     * @return string Motivo normalizado (extremos recortados y espacios colapsados).
     *
     * @throws InvalidArgumentException Si el motivo es nulo, vacío, demasiado corto
     *                                  o está compuesto sólo de símbolos o espacios.
     */
    public static function normalizeAndValidate(?string $rawReason): string
    {
        $collapsed = $rawReason === null
            ? ''
            : preg_replace('/\s+/u', ' ', trim($rawReason));

        $normalized = is_string($collapsed) ? $collapsed : '';

        if (mb_strlen($normalized, 'UTF-8') < self::MIN_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'El motivo de la reasignación debe tener al menos %d caracteres reales tras colapsar espacios (R1, QA 1).',
                self::MIN_LENGTH
            ));
        }

        if (preg_match('/[\p{L}\p{N}]/u', $normalized) !== 1) {
            throw new InvalidArgumentException(
                'El motivo de la reasignación debe contener al menos una letra o un dígito: no se admite relleno de guiones, puntos ni símbolos (R1, QA 1).'
            );
        }

        return $normalized;
    }
}
