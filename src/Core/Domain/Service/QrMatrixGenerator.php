<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Service;

use InvalidArgumentException;

/**
 * QrMatrixGenerator
 * 
 * Generador matemático nativo de matrices QR (ISO/IEC 18004) en PHP puro.
 * Soporta codificación Byte (8-bit), corrección de errores Reed-Solomon en GF(256),
 * versiones 1 a 10 (con auto-selección según longitud de datos), evaluación
 * de las 8 máscaras estándar y generación de matriz booleana 2D con zona de silencio.
 * 
 * Dogma Vanilla: Cero dependencias de Composer, cero extensiones de terceros.
 * Cumple con RNF-01, RNF-04 y los Artículos I y IV de la Constitución de VendGuard.
 */
class QrMatrixGenerator
{
    /** Niveles de corrección de error soportados */
    public const ECC_L = 'L'; // ~7% recuperación
    public const ECC_M = 'M'; // ~15% recuperación (Estándar VendGuard)
    public const ECC_Q = 'Q'; // ~25% recuperación
    public const ECC_H = 'H'; // ~30% recuperación

    /**
     * Capacidad de datos en modo BYTE (número de bytes de datos útiles)
     * para niveles L, M, Q, H por versión (1 a 10).
     */
    private const CAPACITY_TABLE = [
        // Versión => [L, M, Q, H]
        1  => [19, 16, 13, 9],
        2  => [34, 28, 22, 16],
        3  => [55, 44, 34, 26],
        4  => [80, 64, 48, 36],
        5  => [108, 86, 62, 46],
        6  => [136, 108, 76, 60],
        7  => [156, 124, 88, 66],
        8  => [194, 154, 110, 86],
        9  => [232, 182, 132, 100],
        10 => [274, 216, 154, 122],
    ];

    /**
     * Parámetros de bloques de corrección de errores por versión y nivel:
     * [totalCodewords, ecCodewordsPerBlock, group1Blocks, group1DataCodewords, group2Blocks, group2DataCodewords]
     */
    private const ECC_BLOCK_PARAMS = [
        // v1
        1 => [
            'L' => [26, 7, 1, 19, 0, 0],
            'M' => [26, 10, 1, 16, 0, 0],
            'Q' => [26, 13, 1, 13, 0, 0],
            'H' => [26, 17, 1, 9, 0, 0],
        ],
        // v2
        2 => [
            'L' => [44, 10, 1, 34, 0, 0],
            'M' => [44, 16, 1, 28, 0, 0],
            'Q' => [44, 22, 1, 22, 0, 0],
            'H' => [44, 28, 1, 16, 0, 0],
        ],
        // v3
        3 => [
            'L' => [70, 15, 1, 55, 0, 0],
            'M' => [70, 26, 1, 44, 0, 0],
            'Q' => [70, 18, 2, 17, 0, 0],
            'H' => [70, 22, 2, 13, 0, 0],
        ],
        // v4
        4 => [
            'L' => [100, 20, 1, 80, 0, 0],
            'M' => [100, 18, 2, 32, 0, 0],
            'Q' => [100, 26, 2, 24, 0, 0],
            'H' => [100, 16, 4, 9, 0, 0],
        ],
        // v5
        5 => [
            'L' => [134, 26, 1, 108, 0, 0],
            'M' => [134, 24, 2, 43, 0, 0],
            'Q' => [134, 18, 2, 15, 2, 16],
            'H' => [134, 22, 2, 11, 2, 12],
        ],
        // v6
        6 => [
            'L' => [172, 18, 2, 68, 0, 0],
            'M' => [172, 16, 4, 27, 0, 0],
            'Q' => [172, 24, 4, 19, 0, 0],
            'H' => [172, 28, 4, 15, 0, 0],
        ],
        // v7
        7 => [
            'L' => [196, 20, 2, 78, 0, 0],
            'M' => [196, 18, 4, 31, 0, 0],
            'Q' => [196, 18, 2, 14, 4, 15],
            'H' => [196, 26, 4, 13, 1, 14],
        ],
        // v8
        8 => [
            'L' => [242, 24, 2, 97, 0, 0],
            'M' => [242, 22, 2, 38, 2, 39],
            'Q' => [242, 22, 4, 18, 2, 19],
            'H' => [242, 26, 4, 14, 2, 15],
        ],
        // v9
        9 => [
            'L' => [292, 30, 2, 116, 0, 0],
            'M' => [292, 22, 3, 36, 2, 37],
            'Q' => [292, 20, 4, 16, 4, 17],
            'H' => [292, 24, 4, 12, 4, 13],
        ],
        // v10
        10 => [
            'L' => [346, 18, 2, 68, 2, 69],
            'M' => [346, 26, 4, 43, 1, 44],
            'Q' => [346, 24, 6, 19, 2, 20],
            'H' => [346, 28, 6, 15, 2, 16],
        ],
    ];

    /** Patrones de alineación por versión (coordenadas centrales) */
    private const ALIGNMENT_PATTERN_POS = [
        1 => [],
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
        7 => [6, 22, 38],
        8 => [6, 24, 42],
        9 => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    /** Formatos codificados (BCH 15,5) para los 32 pares [Nivel, Máscara] */
    private const FORMAT_INFO = [
        'M' => [
            0 => 0x5412,
            1 => 0x5125,
            2 => 0x5e7c,
            3 => 0x5b4b,
            4 => 0x45f9,
            5 => 0x40ce,
            6 => 0x4f97,
            7 => 0x4aa0,
        ],
        'L' => [
            0 => 0x77c4,
            1 => 0x72f3,
            2 => 0x7daa,
            3 => 0x789d,
            4 => 0x662f,
            5 => 0x6318,
            6 => 0x6c41,
            7 => 0x6976,
        ],
        'H' => [
            0 => 0x1689,
            1 => 0x13be,
            2 => 0x1ce7,
            3 => 0x19d0,
            4 => 0x0762,
            5 => 0x0255,
            6 => 0x0d0c,
            7 => 0x083b,
        ],
        'Q' => [
            0 => 0x355f,
            1 => 0x3068,
            2 => 0x3f31,
            3 => 0x3a06,
            4 => 0x24b4,
            5 => 0x2183,
            6 => 0x2eda,
            7 => 0x2bed,
        ],
    ];

    /** Tablas precalculadas de Galois Field GF(256) para polinomio primitivo 0x11d */
    private static ?array $gfExp = null;
    private static ?array $gfLog = null;

    /**
     * Genera la matriz booleana 2D de un código QR.
     *
     * @param string $text Texto o URL a codificar en el código QR.
     * @param string $eccLevel Nivel de corrección de error ('L', 'M', 'Q', 'H'). Por defecto 'M'.
     * @param int $quietZone Módulos de margen blanco de seguridad (por defecto 4 según ISO/IEC 18004).
     * @return array<int, array<int, bool>> Matriz booleana 2D (true = módulo oscuro / negro, false = módulo claro / blanco).
     * @throws InvalidArgumentException Si el texto está vacío o excede la capacidad máxima disponible.
     */
    public static function generate(
        string $text,
        string $eccLevel = self::ECC_M,
        int $quietZone = 4
    ): array {
        if ($text === '') {
            throw new InvalidArgumentException('El texto a codificar en el código QR no puede estar vacío.');
        }

        $eccLevel = strtoupper(trim($eccLevel));
        if (!isset(self::FORMAT_INFO[$eccLevel])) {
            throw new InvalidArgumentException("Nivel de corrección de errores no válido: '{$eccLevel}'.");
        }

        if ($quietZone < 0) {
            throw new InvalidArgumentException('La zona de silencio (quiet zone) no puede ser negativa.');
        }

        self::initGaloisField();

        // 1. Determinar versión adecuada según la longitud de datos
        $textLen = strlen($text);
        $version = self::selectVersion($textLen, $eccLevel);
        $size = 17 + 4 * $version;

        // 2. Codificar flujo de bits de datos
        $dataCodewords = self::encodeData($text, $version, $eccLevel);

        // 3. Generar bloques de datos con corrección de error Reed-Solomon y entrelazar
        $finalCodewords = self::createBlocksAndInterleave($dataCodewords, $version, $eccLevel);

        // 4. Crear matriz base con patrones fijos (buscadores, alineación, sincronización)
        [$baseMatrix, $isFunctionModule] = self::createBaseMatrix($version, $size);

        // 5. Colocar codewords en la matriz (sin máscara)
        $rawMatrix = self::placeCodewords($baseMatrix, $isFunctionModule, $finalCodewords, $size);

        // 6. Evaluar las 8 máscaras y seleccionar la que tenga menor penalización
        $bestMask = self::selectBestMask($rawMatrix, $isFunctionModule, $size);

        // 7. Aplicar la mejor máscara
        $maskedMatrix = self::applyMask($rawMatrix, $isFunctionModule, $bestMask, $size);

        // 8. Escribir la información de formato (BCH) con el nivel ECC y la máscara elegida
        $finalMatrix = self::writeFormatInfo($maskedMatrix, $eccLevel, $bestMask, $size);

        // 9. Añadir quiet zone (zona de silencio) perimetral y garantizar valores booleanos
        return self::addQuietZone($finalMatrix, $quietZone);
    }

    /**
     * Determina la menor versión (1 a 10) que soporta la longitud en bytes indicada.
     */
    private static function selectVersion(int $byteLength, string $eccLevel): int
    {
        $levelIndex = match ($eccLevel) {
            self::ECC_L => 0,
            self::ECC_M => 1,
            self::ECC_Q => 2,
            self::ECC_H => 3,
            default => 1,
        };

        foreach (self::CAPACITY_TABLE as $ver => $capacities) {
            if ($byteLength <= $capacities[$levelIndex]) {
                return $ver;
            }
        }

        throw new InvalidArgumentException("La longitud del texto ({$byteLength} bytes) excede la capacidad máxima soportada.");
    }

    /**
     * Codifica el texto en modo Byte (8-bit) con cabecera de modo, contador y relleno.
     *
     * @return int[] Codewords de datos.
     */
    private static function encodeData(string $text, int $version, string $eccLevel): array
    {
        $bitBuffer = '';

        // Indicador de modo: 0100 (Byte mode)
        $bitBuffer .= '0100';

        // Contador de caracteres (8 bits para versiones 1-9, 16 bits para versión 10+)
        $charCountBits = ($version < 10) ? 8 : 16;
        $bitBuffer .= str_pad(decbin(strlen($text)), $charCountBits, '0', STR_PAD_LEFT);

        // Codificación de bytes
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $bitBuffer .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);
        }

        // Capacidad total de datos requerida en bits
        $levelIndex = match ($eccLevel) {
            self::ECC_L => 0,
            self::ECC_M => 1,
            self::ECC_Q => 2,
            self::ECC_H => 3,
            default => 1,
        };
        $totalDataBytes = self::CAPACITY_TABLE[$version][$levelIndex];
        $totalDataBits = $totalDataBytes * 8;

        // Terminador de 4 ceros (o hasta completar capacidad)
        $remainingBits = $totalDataBits - strlen($bitBuffer);
        $terminatorLen = min(4, max(0, $remainingBits));
        $bitBuffer .= str_repeat('0', $terminatorLen);

        // Rellenar hasta múltiplo de 8 bits
        if (strlen($bitBuffer) % 8 !== 0) {
            $bitBuffer .= str_repeat('0', 8 - (strlen($bitBuffer) % 8));
        }

        // Relleno de bytes alternados (0xEC y 0x11)
        $padBytes = ['11101100', '00010001'];
        $padIdx = 0;
        while (strlen($bitBuffer) < $totalDataBits) {
            $bitBuffer .= $padBytes[$padIdx % 2];
            $padIdx++;
        }

        // Convertir flujo de bits a codewords (enteros 0-255)
        $codewords = [];
        $codewordCount = strlen($bitBuffer) / 8;
        for ($i = 0; $i < $codewordCount; $i++) {
            $byteChunk = substr($bitBuffer, $i * 8, 8);
            $codewords[] = (int)bindec($byteChunk);
        }

        return $codewords;
    }

    /**
     * Divide los codewords en bloques, calcula la corrección Reed-Solomon para cada uno y entrelaza.
     *
     * @param int[] $dataCodewords
     * @return int[] Codewords entrelazados finales listos para mapear en la matriz.
     */
    private static function createBlocksAndInterleave(array $dataCodewords, int $version, string $eccLevel): array
    {
        $params = self::ECC_BLOCK_PARAMS[$version][$eccLevel];
        $ecPerBlock = $params[1];
        $g1Blocks = $params[2];
        $g1DataCount = $params[3];
        $g2Blocks = $params[4];
        $g2DataCount = $params[5];

        $dataBlocks = [];
        $ecBlocks = [];

        $offset = 0;
        // Bloques del grupo 1
        for ($i = 0; $i < $g1Blocks; $i++) {
            $block = array_slice($dataCodewords, $offset, $g1DataCount);
            $offset += $g1DataCount;
            $dataBlocks[] = $block;
            $ecBlocks[] = self::calculateReedSolomon($block, $ecPerBlock);
        }

        // Bloques del grupo 2
        for ($i = 0; $i < $g2Blocks; $i++) {
            $block = array_slice($dataCodewords, $offset, $g2DataCount);
            $offset += $g2DataCount;
            $dataBlocks[] = $block;
            $ecBlocks[] = self::calculateReedSolomon($block, $ecPerBlock);
        }

        // Entrelazado de bloques de datos
        $result = [];
        $maxDataLen = max($g1DataCount, $g2DataCount);
        for ($i = 0; $i < $maxDataLen; $i++) {
            foreach ($dataBlocks as $b) {
                if (isset($b[$i])) {
                    $result[] = $b[$i];
                }
            }
        }

        // Entrelazado de bloques de corrección de error
        for ($i = 0; $i < $ecPerBlock; $i++) {
            foreach ($ecBlocks as $ec) {
                if (isset($ec[$i])) {
                    $result[] = $ec[$i];
                }
            }
        }

        return $result;
    }

    /**
     * Calcula los codewords de corrección de error Reed-Solomon para un bloque.
     *
     * @param int[] $data Codewords de datos.
     * @param int $ecCount Cantidad de codewords de corrección requeridos.
     * @return int[] Codewords de corrección calculados.
     */
    private static function calculateReedSolomon(array $data, int $ecCount): array
    {
        $generator = self::buildGeneratorPolynomial($ecCount);

        // Inicializar división de polinomios
        $poly = array_merge($data, array_fill(0, $ecCount, 0));
        $dataLen = count($data);

        for ($i = 0; $i < $dataLen; $i++) {
            $coef = $poly[$i];
            if ($coef !== 0) {
                $coefLog = self::$gfLog[$coef];
                $genLen = count($generator);
                for ($j = 0; $j < $genLen; $j++) {
                    $term = self::$gfExp[($coefLog + $generator[$j]) % 255];
                    $poly[$i + $j] ^= $term;
                }
            }
        }

        return array_slice($poly, $dataLen, $ecCount);
    }

    /**
     * Construye el polinomio generador para el número dado de codewords de error.
     *
     * @return int[]
     */
    private static function buildGeneratorPolynomial(int $ecCount): array
    {
        $gen = [0]; // g(x) = (x - alpha^0) = x + 1 => representado en logs
        for ($i = 1; $i < $ecCount; $i++) {
            $next = [];
            $len = count($gen);
            $next[0] = $gen[0];
            for ($j = 1; $j < $len; $j++) {
                $term1 = self::$gfExp[($gen[$j - 1] + $i) % 255];
                $term2 = self::$gfExp[$gen[$j]];
                $next[$j] = self::$gfLog[$term1 ^ $term2];
            }
            $next[$len] = ($gen[$len - 1] + $i) % 255;
            $gen = $next;
        }

        return $gen;
    }

    /**
     * Inicializa las tablas de exponentes y logaritmos en GF(256).
     */
    private static function initGaloisField(): void
    {
        if (self::$gfExp !== null) {
            return;
        }

        self::$gfExp = array_fill(0, 512, 0);
        self::$gfLog = array_fill(0, 256, 0);

        $val = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$gfExp[$i] = $val;
            self::$gfExp[$i + 255] = $val;
            self::$gfLog[$val] = $i;
            $val <<= 1;
            if ($val >= 256) {
                $val ^= 0x11d; // Polinomio primitivo estándar QR: x^8 + x^4 + x^3 + x^2 + 1
            }
        }
    }

    /**
     * Crea la matriz base con patrones fijos (buscadores, alineación, sincronización).
     *
     * @return array{0: array<int, array<int, int>>, 1: array<int, array<int, bool>>}
     */
    private static function createBaseMatrix(int $version, int $size): array
    {
        $matrix = array_fill(0, $size, array_fill(0, $size, 0));
        $isFunction = array_fill(0, $size, array_fill(0, $size, false));

        // 1. Patrones de búsqueda en esquinas (Finder Patterns) de 7x7 + separadores de 8x8
        self::placeFinderPattern($matrix, $isFunction, 0, 0, $size);
        self::placeFinderPattern($matrix, $isFunction, $size - 7, 0, $size);
        self::placeFinderPattern($matrix, $isFunction, 0, $size - 7, $size);

        // 2. Patrones de alineación para versión 2+
        $alignPos = self::ALIGNMENT_PATTERN_POS[$version];
        $alignCount = count($alignPos);
        for ($r = 0; $r < $alignCount; $r++) {
            for ($c = 0; $c < $alignCount; $c++) {
                $row = $alignPos[$r];
                $col = $alignPos[$c];
                // No solapar con patrones de búsqueda en esquinas
                if (
                    ($row === 6 && $col === 6) ||
                    ($row === 6 && $col === $size - 7) ||
                    ($row === $size - 7 && $col === 6)
                ) {
                    continue;
                }
                self::placeAlignmentPattern($matrix, $isFunction, $row, $col);
            }
        }

        // 3. Patrones de sincronización (Timing Patterns) en fila 6 y columna 6
        for ($i = 8; $i < $size - 8; $i++) {
            $val = ($i % 2 === 0) ? 1 : 0;
            if (!$isFunction[6][$i]) {
                $matrix[6][$i] = $val;
                $isFunction[6][$i] = true;
            }
            if (!$isFunction[$i][6]) {
                $matrix[$i][6] = $val;
                $isFunction[$i][6] = true;
            }
        }

        // 4. Módulo oscuro fijo en (4*version + 9, 8)
        $darkRow = 4 * $version + 9;
        $matrix[$darkRow][8] = 1;
        $isFunction[$darkRow][8] = true;

        // 5. Reservar áreas de información de formato
        for ($i = 0; $i < 9; $i++) {
            $isFunction[8][$i] = true;
            $isFunction[$i][8] = true;
        }
        for ($i = $size - 8; $i < $size; $i++) {
            $isFunction[8][$i] = true;
            $isFunction[$i][8] = true;
        }

        return [$matrix, $isFunction];
    }

    /**
     * Coloca un patrón de búsqueda de 7x7 y sus separadores de seguridad en la matriz.
     */
    private static function placeFinderPattern(
        array &$matrix,
        array &$isFunction,
        int $startRow,
        int $startCol,
        int $size
    ): void {
        for ($r = -1; $r <= 7; $r++) {
            for ($c = -1; $c <= 7; $c++) {
                $currR = $startRow + $r;
                $currC = $startCol + $c;
                if ($currR >= 0 && $currR < $size && $currC >= 0 && $currC < $size) {
                    $isFunction[$currR][$currC] = true;
                    if ($r >= 0 && $r <= 6 && $c >= 0 && $c <= 6) {
                        // Marco exterior 7x7 o núcleo interior 3x3
                        $isDark = ($r === 0 || $r === 6 || $c === 0 || $c === 6 || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4));
                        $matrix[$currR][$currC] = $isDark ? 1 : 0;
                    } else {
                        // Separador blanco
                        $matrix[$currR][$currC] = 0;
                    }
                }
            }
        }
    }

    /**
     * Coloca un patrón de alineación concéntrico de 5x5 centrado en ($centerRow, $centerCol).
     */
    private static function placeAlignmentPattern(
        array &$matrix,
        array &$isFunction,
        int $centerRow,
        int $centerCol
    ): void {
        for ($r = -2; $r <= 2; $r++) {
            for ($c = -2; $c <= 2; $c++) {
                $currR = $centerRow + $r;
                $currC = $centerCol + $c;
                $isFunction[$currR][$currC] = true;
                $isDark = (abs($r) === 2 || abs($c) === 2 || ($r === 0 && $c === 0));
                $matrix[$currR][$currC] = $isDark ? 1 : 0;
            }
        }
    }

    /**
     * Mapea los codewords entrelazados en los módulos disponibles recorriendo en zigzag ascendente/descendente.
     */
    private static function placeCodewords(
        array $baseMatrix,
        array $isFunction,
        array $codewords,
        int $size
    ): array {
        $matrix = $baseMatrix;

        // Convertir todos los codewords en un stream continuo de bits
        $bitStream = '';
        foreach ($codewords as $byte) {
            $bitStream .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }

        $bitIdx = 0;
        $totalBits = strlen($bitStream);

        // Recorrido de derecha a izquierda en columnas de 2 en 2
        $col = $size - 1;
        $upward = true;

        while ($col > 0) {
            // La columna de sincronización (columna 6) se salta
            if ($col === 6) {
                $col--;
            }

            $rows = $upward ? range($size - 1, 0) : range(0, $size - 1);
            foreach ($rows as $row) {
                for ($c = 0; $c < 2; $c++) {
                    $currCol = $col - $c;
                    if (!$isFunction[$row][$currCol]) {
                        if ($bitIdx < $totalBits) {
                            $matrix[$row][$currCol] = ($bitStream[$bitIdx] === '1') ? 1 : 0;
                            $bitIdx++;
                        } else {
                            $matrix[$row][$currCol] = 0; // Remainder bits
                        }
                    }
                }
            }

            $upward = !$upward;
            $col -= 2;
        }

        return $matrix;
    }

    /**
     * Evalúa las 8 máscaras estándar y retorna el índice de la que tiene menor penalización.
     */
    private static function selectBestMask(array $matrix, array $isFunction, int $size): int
    {
        $bestMask = 0;
        $lowestPenalty = PHP_INT_MAX;

        for ($mask = 0; $mask < 8; $mask++) {
            $testMatrix = self::applyMask($matrix, $isFunction, $mask, $size);
            $penalty = self::calculatePenalty($testMatrix, $size);
            if ($penalty < $lowestPenalty) {
                $lowestPenalty = $penalty;
                $bestMask = $mask;
            }
        }

        return $bestMask;
    }

    /**
     * Aplica la fórmula de máscara especificada a los módulos de datos disponibles.
     */
    private static function applyMask(array $matrix, array $isFunction, int $mask, int $size): array
    {
        $result = $matrix;
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if (!$isFunction[$r][$c]) {
                    $invert = match ($mask) {
                        0 => (($r + $c) % 2 === 0),
                        1 => ($r % 2 === 0),
                        2 => ($c % 3 === 0),
                        3 => (($r + $c) % 3 === 0),
                        4 => (((int)floor($r / 2) + (int)floor($c / 3)) % 2 === 0),
                        5 => ((($r * $c) % 2 + ($r * $c) % 3) === 0),
                        6 => (((($r * $c) % 2 + ($r * $c) % 3) % 2) === 0),
                        7 => (((($r + $c) % 2 + ($r * $c) % 3) % 2) === 0),
                        default => false,
                    };
                    if ($invert) {
                        $result[$r][$c] = $result[$r][$c] === 1 ? 0 : 1;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Escribe los 15 bits de información de formato (BCH 15,5) alrededor de los patrones de búsqueda.
     */
    private static function writeFormatInfo(array $matrix, string $eccLevel, int $mask, int $size): array
    {
        $formatBits = self::FORMAT_INFO[$eccLevel][$mask];
        $bits = str_pad(decbin($formatBits), 15, '0', STR_PAD_LEFT);

        // Copia 1: Alrededor del buscador superior izquierdo
        // bits 0 a 5 en (8, 0) a (8, 5)
        $matrix[8][0] = (int)$bits[0];
        $matrix[8][1] = (int)$bits[1];
        $matrix[8][2] = (int)$bits[2];
        $matrix[8][3] = (int)$bits[3];
        $matrix[8][4] = (int)$bits[4];
        $matrix[8][5] = (int)$bits[5];
        // bit 6 en (8, 7)
        $matrix[8][7] = (int)$bits[6];
        // bit 7 en (8, 8)
        $matrix[8][8] = (int)$bits[7];
        // bit 8 en (7, 8)
        $matrix[7][8] = (int)$bits[8];
        // bits 9 a 14 en (5, 8) a (0, 8)
        $matrix[5][8] = (int)$bits[9];
        $matrix[4][8] = (int)$bits[10];
        $matrix[3][8] = (int)$bits[11];
        $matrix[2][8] = (int)$bits[12];
        $matrix[1][8] = (int)$bits[13];
        $matrix[0][8] = (int)$bits[14];

        // Copia 2: Distribuida en buscadores inferior izquierdo y superior derecho
        // bits 0 a 6 en (size - 1, 8) descendiendo a (size - 7, 8)
        for ($i = 0; $i < 7; $i++) {
            $matrix[$size - 1 - $i][8] = (int)$bits[$i];
        }
        // bits 7 a 14 en (8, size - 8) ascendiendo a (8, size - 1)
        for ($i = 0; $i < 8; $i++) {
            $matrix[8][$size - 8 + $i] = (int)$bits[7 + $i];
        }

        return $matrix;
    }

    /**
     * Calcula la penalización según las 4 reglas ISO/IEC 18004.
     */
    private static function calculatePenalty(array $matrix, int $size): int
    {
        $penalty = 0;

        // Regla 1: 5 o más módulos consecutivos del mismo color en filas y columnas
        for ($r = 0; $r < $size; $r++) {
            $runColor = $matrix[$r][0];
            $runLen = 1;
            for ($c = 1; $c < $size; $c++) {
                if ($matrix[$r][$c] === $runColor) {
                    $runLen++;
                } else {
                    if ($runLen >= 5) {
                        $penalty += 3 + ($runLen - 5);
                    }
                    $runColor = $matrix[$r][$c];
                    $runLen = 1;
                }
            }
            if ($runLen >= 5) {
                $penalty += 3 + ($runLen - 5);
            }
        }

        for ($c = 0; $c < $size; $c++) {
            $runColor = $matrix[0][$c];
            $runLen = 1;
            for ($r = 1; $r < $size; $r++) {
                if ($matrix[$r][$c] === $runColor) {
                    $runLen++;
                } else {
                    if ($runLen >= 5) {
                        $penalty += 3 + ($runLen - 5);
                    }
                    $runColor = $matrix[$r][$c];
                    $runLen = 1;
                }
            }
            if ($runLen >= 5) {
                $penalty += 3 + ($runLen - 5);
            }
        }

        // Regla 2: Bloques de 2x2 del mismo color
        for ($r = 0; $r < $size - 1; $r++) {
            for ($c = 0; $c < $size - 1; $c++) {
                $val = $matrix[$r][$c];
                if (
                    $matrix[$r + 1][$c] === $val &&
                    $matrix[$r][$c + 1] === $val &&
                    $matrix[$r + 1][$c + 1] === $val
                ) {
                    $penalty += 3;
                }
            }
        }

        // Regla 3: Patrones 1:1:3:1:1 (oscuro:claro:oscuro:claro:oscuro) con 4 claros a un lado
        $p1 = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0];
        $p2 = [0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1];
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c <= $size - 11; $c++) {
                $sub = array_slice($matrix[$r], $c, 11);
                if ($sub === $p1 || $sub === $p2) {
                    $penalty += 40;
                }
            }
        }

        // Regla 4: Proporción de módulos oscuros frente al 50%
        $darkCount = 0;
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($matrix[$r][$c] === 1) {
                    $darkCount++;
                }
            }
        }
        $totalModules = $size * $size;
        $percentDark = ($darkCount * 100) / $totalModules;
        $prevMultipleOf5 = (int)(floor($percentDark / 5) * 5);
        $nextMultipleOf5 = $prevMultipleOf5 + 5;
        $diff = min(abs($prevMultipleOf5 - 50), abs($nextMultipleOf5 - 50));
        $penalty += (int)($diff / 5) * 10;

        return $penalty;
    }

    /**
     * Rodea la matriz resultante con una zona de silencio (Quiet Zone) de módulos en blanco.
     *
     * @param array<int, array<int, int>> $matrix
     * @return array<int, array<int, bool>>
     */
    private static function addQuietZone(array $matrix, int $quietZone): array
    {
        $size = count($matrix);
        $newSize = $size + 2 * $quietZone;

        $output = [];
        // Filas superiores de quiet zone
        for ($r = 0; $r < $quietZone; $r++) {
            $output[] = array_fill(0, $newSize, false);
        }

        // Filas intermedias con márgenes izquierdo y derecho
        for ($r = 0; $r < $size; $r++) {
            $row = array_fill(0, $quietZone, false);
            for ($c = 0; $c < $size; $c++) {
                $row[] = ($matrix[$r][$c] === 1);
            }
            for ($c = 0; $c < $quietZone; $c++) {
                $row[] = false;
            }
            $output[] = $row;
        }

        // Filas inferiores de quiet zone
        for ($r = 0; $r < $quietZone; $r++) {
            $output[] = array_fill(0, $newSize, false);
        }

        return $output;
    }
}
