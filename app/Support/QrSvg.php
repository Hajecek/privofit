<?php

declare(strict_types=1);

namespace App\Support;

/**
 * QR Model 2, byte mode, ECC-M. No third-party autoload.
 */
final class QrSvg
{
    /** @var list<int> */
    private array $exp = [];

    /** @var array<int, int> */
    private array $log = [];

    public static function render(string $payload, bool $xmlHeader = false): string
    {
        $matrix = (new self())->matrix($payload);
        $size = count($matrix);
        $modules = '';
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($matrix[$y][$x]) {
                    $modules .= '<rect x="' . $x . '" y="' . $y . '" width="1" height="1"/>';
                }
            }
        }
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size . '"'
            . ' width="220" height="220" shape-rendering="crispEdges" role="img" aria-label="QR kód">'
            . '<rect width="' . $size . '" height="' . $size . '" fill="#ffffff"/>'
            . '<g fill="#000000">' . $modules . '</g></svg>';
        return $xmlHeader ? '<?xml version="1.0" encoding="UTF-8"?>' . $svg : $svg;
    }

    public static function inline(string $payload): string
    {
        return self::render($payload, false);
    }

    public static function dataUri(string $payload): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::inline($payload));
    }

    public static function png(string $payload, int $scale = 12): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new \RuntimeException('PNG QR není k dispozici.');
        }
        $matrix = (new self())->matrix($payload);
        $size = count($matrix);
        $px = $size * $scale;
        $img = imagecreatetruecolor($px, $px);
        if ($img === false) {
            throw new \RuntimeException('PNG QR se nepodařilo vytvořit.');
        }
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefill($img, 0, 0, $white === false ? 0 : $white);
        $ink = $black === false ? 0 : $black;
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($matrix[$y][$x]) {
                    imagefilledrectangle(
                        $img,
                        $x * $scale,
                        $y * $scale,
                        ($x + 1) * $scale - 1,
                        ($y + 1) * $scale - 1,
                        $ink
                    );
                }
            }
        }
        ob_start();
        imagepng($img);
        imagedestroy($img);
        return (string) ob_get_clean();
    }

    /** @return list<list<bool>> */
    private function matrix(string $data): array
    {
        $this->initGalois();
        $bytes = array_values(unpack('C*', $data) ?: []);
        $version = $this->pickVersion(count($bytes));
        $codewords = $this->encode($bytes, $version);
        $size = 21 + 4 * ($version - 1);
        $reserved = [];
        $grid = $this->emptyGrid($size);
        $this->drawFinders($grid, $reserved, $size);
        $this->drawAlignments($grid, $reserved, $version);
        $this->drawTiming($grid, $reserved, $size);
        $this->drawDarkModule($grid, $reserved, $version);
        if ($version >= 7) {
            $this->drawVersion($grid, $reserved, $version, $size);
        }
        $this->drawFormatPlaceholder($reserved, $size);
        $this->placeData($grid, $reserved, $codewords, $size);
        $best = $this->bestMask($grid, $reserved, $size);
        $this->drawFormat($best['grid'], $best['mask'], $size);
        return $this->addQuietZone($best['grid'], 4);
    }

    /**
     * ECC-M: [eccPerBlock, [[g1Blocks, g1Data], [g2Blocks, g2Data]]]
     *
     * @return array{0:int,1:array{0:array{0:int,1:int},1:array{0:int,1:int}}}
     */
    private function rsSpec(int $version): array
    {
        $table = [
            1 => [10, [[1, 16], [0, 0]]],
            2 => [16, [[1, 28], [0, 0]]],
            3 => [26, [[1, 44], [0, 0]]],
            4 => [18, [[2, 32], [0, 0]]],
            5 => [24, [[2, 43], [0, 0]]],
            6 => [16, [[4, 27], [0, 0]]],
            7 => [18, [[4, 31], [0, 0]]],
            8 => [22, [[2, 38], [2, 39]]],
            9 => [22, [[3, 36], [2, 37]]],
            10 => [26, [[4, 43], [1, 44]]],
            11 => [30, [[1, 50], [4, 51]]],
            12 => [22, [[6, 36], [2, 37]]],
            13 => [22, [[8, 37], [1, 38]]],
            14 => [24, [[4, 40], [5, 41]]],
            15 => [24, [[5, 41], [5, 42]]],
        ];
        if (!isset($table[$version])) {
            throw new \RuntimeException('QR verze není podporovaná.');
        }
        return $table[$version];
    }

    private function dataCapacity(int $version): int
    {
        [, $groups] = $this->rsSpec($version);
        return $groups[0][0] * $groups[0][1] + $groups[1][0] * $groups[1][1];
    }

    private function pickVersion(int $byteCount): int
    {
        for ($v = 1; $v <= 15; $v++) {
            $countBits = $v <= 9 ? 8 : 16;
            $bits = 4 + $countBits + $byteCount * 8;
            if ((int) ceil($bits / 8) <= $this->dataCapacity($v)) {
                return $v;
            }
        }
        throw new \RuntimeException('Data pro QR kód jsou příliš dlouhá.');
    }

    /** @param list<int> $bytes */
    private function encode(array $bytes, int $version): array
    {
        $countBits = $version <= 9 ? 8 : 16;
        $bits = '0100' . str_pad(decbin(count($bytes)), $countBits, '0', STR_PAD_LEFT);
        foreach ($bytes as $b) {
            $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        }
        $capacity = $this->dataCapacity($version) * 8;
        $remain = $capacity - strlen($bits);
        $bits .= substr('0000', 0, min(4, $remain));
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - strlen($bits) % 8);
        }
        $pad = true;
        while (strlen($bits) < $capacity) {
            $bits .= $pad ? '11101100' : '00010001';
            $pad = !$pad;
        }
        $data = [];
        for ($i = 0; $i < strlen($bits); $i += 8) {
            $data[] = bindec(substr($bits, $i, 8));
        }
        return $this->interleave($data, $version);
    }

    /** @param list<int> $data */
    private function interleave(array $data, int $version): array
    {
        [$eccLen, $groups] = $this->rsSpec($version);
        $blocks = [];
        $offset = 0;
        $maxData = 0;
        foreach ($groups as [$count, $dataLen]) {
            for ($i = 0; $i < $count; $i++) {
                $block = array_slice($data, $offset, $dataLen);
                $offset += $dataLen;
                $blocks[] = ['d' => $block, 'e' => $this->rs($block, $eccLen)];
                $maxData = max($maxData, $dataLen);
            }
        }
        $out = [];
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($blocks as $block) {
                if (isset($block['d'][$i])) {
                    $out[] = $block['d'][$i];
                }
            }
        }
        for ($i = 0; $i < $eccLen; $i++) {
            foreach ($blocks as $block) {
                $out[] = $block['e'][$i];
            }
        }
        return $out;
    }

    /** @param list<int> $data */
    private function rs(array $data, int $ecLen): array
    {
        $gen = [1];
        for ($i = 0; $i < $ecLen; $i++) {
            $next = array_fill(0, count($gen) + 1, 0);
            $factor = $this->exp[$i];
            for ($j = 0; $j < count($gen); $j++) {
                $next[$j] ^= $gen[$j];
                $next[$j + 1] ^= $this->mul($gen[$j], $factor);
            }
            $gen = $next;
        }
        $ecc = array_fill(0, $ecLen, 0);
        foreach ($data as $b) {
            $factor = $b ^ $ecc[0];
            array_shift($ecc);
            $ecc[] = 0;
            if ($factor === 0) {
                continue;
            }
            for ($i = 0; $i < $ecLen; $i++) {
                $ecc[$i] ^= $this->mul($gen[$i + 1], $factor);
            }
        }
        return $ecc;
    }

    private function initGalois(): void
    {
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $this->exp[$i] = $x;
            $this->log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11d;
            }
        }
        $this->exp[255] = $this->exp[0];
    }

    private function mul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        return $this->exp[($this->log[$a] + $this->log[$b]) % 255];
    }

    /** @return list<list<int>> */
    private function emptyGrid(int $size): array
    {
        return array_fill(0, $size, array_fill(0, $size, 0));
    }

    /** @param list<list<int>> $grid */
    private function drawFinders(array &$grid, array &$reserved, int $size): void
    {
        foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$y, $x]) {
            for ($dy = -1; $dy <= 7; $dy++) {
                for ($dx = -1; $dx <= 7; $dx++) {
                    $yy = $y + $dy;
                    $xx = $x + $dx;
                    if ($yy < 0 || $xx < 0 || $yy >= $size || $xx >= $size) {
                        continue;
                    }
                    $on = $dx === -1 || $dx === 7 || $dy === -1 || $dy === 7
                        ? false
                        : ($dx === 0 || $dx === 6 || $dy === 0 || $dy === 6 || ($dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4));
                    $grid[$yy][$xx] = $on ? 1 : 0;
                    $reserved[$yy][$xx] = true;
                }
            }
        }
    }

    /** @param list<list<int>> $grid */
    private function drawAlignments(array &$grid, array &$reserved, int $version): void
    {
        $pos = [
            1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
            6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46],
            10 => [6, 28, 50], 11 => [6, 30, 54], 12 => [6, 32, 58],
            13 => [6, 34, 62], 14 => [6, 26, 46, 66], 15 => [6, 26, 48, 70],
        ][$version];
        foreach ($pos as $y) {
            foreach ($pos as $x) {
                if (!empty($reserved[$y][$x])) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $on = $dx === -2 || $dx === 2 || $dy === -2 || $dy === 2 || ($dx === 0 && $dy === 0);
                        $grid[$y + $dy][$x + $dx] = $on ? 1 : 0;
                        $reserved[$y + $dy][$x + $dx] = true;
                    }
                }
            }
        }
    }

    /** @param list<list<int>> $grid */
    private function drawTiming(array &$grid, array &$reserved, int $size): void
    {
        for ($i = 8; $i < $size - 8; $i++) {
            $bit = $i % 2 === 0 ? 1 : 0;
            if (empty($reserved[6][$i])) {
                $grid[6][$i] = $bit;
                $reserved[6][$i] = true;
            }
            if (empty($reserved[$i][6])) {
                $grid[$i][6] = $bit;
                $reserved[$i][6] = true;
            }
        }
    }

    /** @param list<list<int>> $grid */
    private function drawDarkModule(array &$grid, array &$reserved, int $version): void
    {
        $y = 4 * $version + 9;
        $grid[$y][8] = 1;
        $reserved[$y][8] = true;
    }

    /** @param list<list<int>> $grid */
    private function drawVersion(array &$grid, array &$reserved, int $version, int $size): void
    {
        $bits = [
            7 => 0x07C94, 8 => 0x085BC, 9 => 0x09A99, 10 => 0x0A4D3,
            11 => 0x0BBF6, 12 => 0x0C762, 13 => 0x0D847, 14 => 0x0E60D, 15 => 0x0F928,
        ][$version];
        for ($i = 0; $i < 18; $i++) {
            $bit = ($bits >> $i) & 1;
            $a = intdiv($i, 3);
            $b = ($i % 3) + ($size - 11);
            $grid[$a][$b] = $bit;
            $grid[$b][$a] = $bit;
            $reserved[$a][$b] = true;
            $reserved[$b][$a] = true;
        }
    }

    private function drawFormatPlaceholder(array &$reserved, int $size): void
    {
        for ($i = 0; $i < 9; $i++) {
            $reserved[8][$i] = true;
            $reserved[$i][8] = true;
        }
        for ($i = 0; $i < 8; $i++) {
            $reserved[8][$size - 1 - $i] = true;
            $reserved[$size - 1 - $i][8] = true;
        }
    }

    /** @param list<list<int>> $grid @param list<int> $codewords */
    private function placeData(array &$grid, array $reserved, array $codewords, int $size): void
    {
        $bits = '';
        foreach ($codewords as $cw) {
            $bits .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
        }
        $version = intdiv($size - 17, 4);
        $remain = [0, 0, 7, 7, 7, 7, 7, 0, 0, 0, 0, 0, 0, 0, 3, 3][$version] ?? 0;
        $bits .= str_repeat('0', $remain);
        $idx = 0;
        $len = strlen($bits);
        $up = true;
        for ($x = $size - 1; $x > 0; $x -= 2) {
            if ($x === 6) {
                $x--;
            }
            $ys = $up ? range($size - 1, 0) : range(0, $size - 1);
            foreach ($ys as $y) {
                foreach ([$x, $x - 1] as $xx) {
                    if (!empty($reserved[$y][$xx])) {
                        continue;
                    }
                    $grid[$y][$xx] = ($idx < $len && $bits[$idx] === '1') ? 1 : 0;
                    $idx++;
                }
            }
            $up = !$up;
        }
    }

    /** @param list<list<int>> $grid @return array{grid:list<list<int>>,mask:int} */
    private function bestMask(array $grid, array $reserved, int $size): array
    {
        $best = null;
        $bestScore = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = $grid;
            for ($y = 0; $y < $size; $y++) {
                for ($x = 0; $x < $size; $x++) {
                    if (empty($reserved[$y][$x]) && $this->maskBit($mask, $x, $y)) {
                        $candidate[$y][$x] ^= 1;
                    }
                }
            }
            $score = $this->score($candidate, $size);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $candidate;
                $bestMask = $mask;
            }
        }
        return ['grid' => $best ?? $grid, 'mask' => $bestMask ?? 0];
    }

    private function maskBit(int $mask, int $x, int $y): bool
    {
        return match ($mask) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
            5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
            6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
            default => (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0,
        };
    }

    /** @param list<list<int>> $grid */
    private function score(array $grid, int $size): int
    {
        $score = 0;
        for ($y = 0; $y < $size; $y++) {
            $run = 1;
            for ($x = 1; $x < $size; $x++) {
                if ($grid[$y][$x] === $grid[$y][$x - 1]) {
                    $run++;
                } else {
                    if ($run >= 5) {
                        $score += $run - 2;
                    }
                    $run = 1;
                }
            }
            if ($run >= 5) {
                $score += $run - 2;
            }
        }
        for ($x = 0; $x < $size; $x++) {
            $run = 1;
            for ($y = 1; $y < $size; $y++) {
                if ($grid[$y][$x] === $grid[$y - 1][$x]) {
                    $run++;
                } else {
                    if ($run >= 5) {
                        $score += $run - 2;
                    }
                    $run = 1;
                }
            }
            if ($run >= 5) {
                $score += $run - 2;
            }
        }
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                if ($grid[$y][$x] === $grid[$y][$x + 1] && $grid[$y][$x] === $grid[$y + 1][$x] && $grid[$y][$x] === $grid[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }
        $pattern = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0];
        $pattern2 = [0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1];
        for ($y = 0; $y < $size; $y++) {
            $row = $grid[$y];
            $col = array_column($grid, $y);
            $score += $this->finderPenalty($row, $pattern, $pattern2) + $this->finderPenalty($col, $pattern, $pattern2);
        }
        $dark = 0;
        foreach ($grid as $row) {
            $dark += array_sum($row);
        }
        $percent = (int) ((abs(100 * $dark / ($size * $size) - 50) / 5));
        return $score + $percent * 10;
    }

    /** @param list<int> $line */
    private function finderPenalty(array $line, array $a, array $b): int
    {
        $score = 0;
        $n = count($line);
        for ($i = 0; $i <= $n - 11; $i++) {
            $slice = array_slice($line, $i, 11);
            if ($slice === $a || $slice === $b) {
                $score += 40;
            }
        }
        return $score;
    }

    /** @param list<list<int>> $grid */
    private function drawFormat(array &$grid, int $mask, int $size): void
    {
        $bits = [
            0b101010000010010,
            0b101000100100101,
            0b101111001111100,
            0b101101101001011,
            0b100010111111001,
            0b100000011001110,
            0b100111110010111,
            0b100101010100000,
        ][$mask];
        for ($i = 0; $i < 15; $i++) {
            $bit = ($bits >> $i) & 1;
            if ($i < 6) {
                $grid[$i][8] = $bit;
            } elseif ($i < 8) {
                $grid[$i + 1][8] = $bit;
            } else {
                $grid[$size - 15 + $i][8] = $bit;
            }
            if ($i < 8) {
                $grid[8][$size - 1 - $i] = $bit;
            } elseif ($i === 8) {
                $grid[8][7] = $bit;
            } else {
                $grid[8][14 - $i] = $bit;
            }
        }
        $grid[$size - 8][8] = 1;
    }

    /** @param list<list<int>> $grid @return list<list<bool>> */
    private function addQuietZone(array $grid, int $quiet): array
    {
        $size = count($grid);
        $out = [];
        for ($y = 0; $y < $size + 2 * $quiet; $y++) {
            $row = [];
            for ($x = 0; $x < $size + 2 * $quiet; $x++) {
                $yy = $y - $quiet;
                $xx = $x - $quiet;
                $row[] = $yy >= 0 && $xx >= 0 && $yy < $size && $xx < $size && $grid[$yy][$xx] === 1;
            }
            $out[] = $row;
        }
        return $out;
    }
}
