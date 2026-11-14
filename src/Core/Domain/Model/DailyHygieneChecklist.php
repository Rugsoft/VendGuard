<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use InvalidArgumentException;
use JsonSerializable;
use JsonException;

/**
 * DailyHygieneChecklist
 *
 * Checklist rápido de higiene diaria de una visita de reposición in situ
 * (Módulo 34, Tabla 47 `hygiene_checklist_json`, RF-03.1, D3, EARS 03.1).
 *
 * El checklist es **adaptativo por tipología**: no hay una lista única, hay dos.
 * En máquinas con cafetera (`HOT_DRINKS` y `COMBO`) se exigen los cuatro puntos de
 * final de carga —posos, cubo de residuos líquidos, batidores/conductos y bandeja
 * de erogación— y `cabin_spills_checked` queda como comprobación opcional. En el
 * resto (`COLD_DRINKS`, `SNACKS` y `PERISHABLE_FOOD`) solo se exigen los dos
 * puntos de cabina y entrega, y los tres indicadores de café se **normalizan a
 * `null`** aunque el cliente los envíe: una máquina sin cafetera no puede
 * declarar que vació un cajón de posos que no tiene.
 *
 * Guardar los indicadores de café en `null` en lugar de `false` es deliberado: el
 * motor distingue «no aplica» de «no se hizo», y la auditoría de Coordinación
 * necesita esa diferencia para no señalar incumplimientos imposibles.
 *
 * El objeto es inmutable y serializa en su forma canónica de cinco claves, que es
 * exactamente la que se persiste en `hygiene_checklist_json`.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, etiquetas en castellano.
 */
final class DailyHygieneChecklist implements JsonSerializable
{
    public const ITEM_COFFEE_GROUNDS_EMPTIED = 'coffee_grounds_emptied';
    public const ITEM_LIQUID_WASTE_BUCKET_CLEANED = 'liquid_waste_bucket_cleaned';
    public const ITEM_MIXERS_AND_DUCTS_RINSED = 'mixers_and_ducts_rinsed';
    public const ITEM_DISPENSING_TRAY_AND_GLASS_CLEANED = 'dispensing_tray_and_glass_cleaned';
    public const ITEM_CABIN_SPILLS_CHECKED = 'cabin_spills_checked';

    /** Indicadores que solo tienen sentido en máquinas con cafetera (D3). */
    public const COFFEE_ITEMS = [
        self::ITEM_COFFEE_GROUNDS_EMPTIED,
        self::ITEM_LIQUID_WASTE_BUCKET_CLEANED,
        self::ITEM_MIXERS_AND_DUCTS_RINSED,
    ];

    /** Los cuatro puntos obligatorios de `HOT_DRINKS` y `COMBO` (EARS 03.1). */
    public const HOT_REQUIRED_ITEMS = [
        self::ITEM_COFFEE_GROUNDS_EMPTIED,
        self::ITEM_LIQUID_WASTE_BUCKET_CLEANED,
        self::ITEM_MIXERS_AND_DUCTS_RINSED,
        self::ITEM_DISPENSING_TRAY_AND_GLASS_CLEANED,
    ];

    /** Los dos puntos obligatorios de `COLD_DRINKS`, `SNACKS` y `PERISHABLE_FOOD`. */
    public const COLD_REQUIRED_ITEMS = [
        self::ITEM_DISPENSING_TRAY_AND_GLASS_CLEANED,
        self::ITEM_CABIN_SPILLS_CHECKED,
    ];

    /** Las cinco claves canónicas del documento persistido. */
    public const ALL_ITEMS = [
        self::ITEM_COFFEE_GROUNDS_EMPTIED,
        self::ITEM_LIQUID_WASTE_BUCKET_CLEANED,
        self::ITEM_MIXERS_AND_DUCTS_RINSED,
        self::ITEM_DISPENSING_TRAY_AND_GLASS_CLEANED,
        self::ITEM_CABIN_SPILLS_CHECKED,
    ];

    /**
     * @param array<string, bool|null> $items Documento canónico ya normalizado.
     */
    private function __construct(private readonly array $items)
    {
    }

    /**
     * ¿La tipología tiene cafetera y, por tanto, exige los tres puntos de café?
     */
    public static function requiresCoffeeItems(MachineType $machineType): bool
    {
        return $machineType === MachineType::HOT_DRINKS || $machineType === MachineType::COMBO;
    }

    /**
     * Puntos que la tipología exige en `true` para dar la visita por limpia (D3).
     *
     * @return list<string>
     */
    public static function requiredItemsFor(MachineType $machineType): array
    {
        return self::requiresCoffeeItems($machineType)
            ? self::HOT_REQUIRED_ITEMS
            : self::COLD_REQUIRED_ITEMS;
    }

    /**
     * Checklist vacío: todo `null`. Es el punto de partida del asistente móvil.
     */
    public static function emptyFor(MachineType $machineType): self
    {
        return self::fromArray([], $machineType);
    }

    /**
     * Normaliza el documento que llega del móvil a la forma canónica de la tipología.
     *
     * Los indicadores de café de una máquina sin cafetera se fuerzan a `null`
     * aunque el payload los declare (D3); una clave desconocida se rechaza en voz
     * alta para que un error de tecleo no pase por un checklist incompleto.
     *
     * @param array<string, mixed> $payload
     *
     * @throws InvalidArgumentException Si una clave no pertenece al checklist o un
     *                                  valor no es un booleano reconocible.
     */
    public static function fromArray(array $payload, MachineType $machineType): self
    {
        foreach (array_keys($payload) as $key) {
            if (!in_array($key, self::ALL_ITEMS, true)) {
                throw new InvalidArgumentException(sprintf(
                    "El checklist de higiene no admite la clave '%s'. Claves válidas: %s.",
                    (string)$key,
                    implode(', ', self::ALL_ITEMS)
                ));
            }
        }

        $coffeeApplies = self::requiresCoffeeItems($machineType);
        $items = [];

        foreach (self::ALL_ITEMS as $item) {
            if (in_array($item, self::COFFEE_ITEMS, true) && !$coffeeApplies) {
                $items[$item] = null;
                continue;
            }

            $items[$item] = self::normalizeFlag($payload[$item] ?? null, $item);
        }

        return new self($items);
    }

    /**
     * Reconstruye el checklist desde el `JSONB` del motor.
     *
     * @throws InvalidArgumentException Si el documento no es un objeto JSON válido.
     */
    public static function fromJson(string $json, MachineType $machineType): self
    {
        try {
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'El checklist de higiene persistido no es un objeto JSON válido: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        if (!is_array($decoded)) {
            throw new InvalidArgumentException('El checklist de higiene persistido debe ser un objeto JSON.');
        }

        /** @var array<string, mixed> $decoded */
        return self::fromArray($decoded, $machineType);
    }

    /**
     * ¿Están marcados en `true` todos los puntos que exige la tipología? (EARS 03.1)
     */
    public function isCompleteFor(MachineType $machineType): bool
    {
        return $this->missingItemsFor($machineType) === [];
    }

    /**
     * Puntos obligatorios que aún no están marcados en `true`, en el orden del contrato.
     *
     * @return list<string>
     */
    public function missingItemsFor(MachineType $machineType): array
    {
        $missing = [];
        foreach (self::requiredItemsFor($machineType) as $item) {
            if (($this->items[$item] ?? null) !== true) {
                $missing[] = $item;
            }
        }

        return $missing;
    }

    /**
     * Valor declarado de un punto: `true`, `false` o `null` («no aplica»).
     *
     * @throws InvalidArgumentException Si la clave no pertenece al checklist.
     */
    public function isChecked(string $item): ?bool
    {
        if (!array_key_exists($item, $this->items)) {
            throw new InvalidArgumentException(
                "El checklist de higiene no contiene la clave '{$item}'."
            );
        }

        return $this->items[$item];
    }

    /**
     * Marca un punto concreto; devuelve una copia sin mutar el checklist recibido.
     *
     * @throws InvalidArgumentException Si la clave no pertenece al checklist.
     */
    public function withChecked(string $item, bool $checked): self
    {
        if (!array_key_exists($item, $this->items)) {
            throw new InvalidArgumentException(
                "El checklist de higiene no contiene la clave '{$item}'."
            );
        }

        $items = $this->items;
        $items[$item] = $checked;

        return new self($items);
    }

    /**
     * Documento canónico de cinco claves, en el orden del contrato.
     *
     * @return array<string, bool|null>
     */
    public function toArray(): array
    {
        return $this->items;
    }

    public function toJson(): string
    {
        return json_encode($this->items, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, bool|null>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @throws InvalidArgumentException Si el valor no es un booleano reconocible.
     */
    private static function normalizeFlag(mixed $value, string $item): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 't', 'true', 'y', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'f', 'false', 'n', 'no', 'off'], true)) {
                return false;
            }
        }

        throw new InvalidArgumentException(sprintf(
            "El punto de higiene '%s' solo admite un booleano o null; se recibió %s.",
            $item,
            get_debug_type($value)
        ));
    }
}
