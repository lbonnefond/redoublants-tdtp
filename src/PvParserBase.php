<?php
declare(strict_types=1);

namespace LBonnefond\RedoublantsTdTp;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

abstract class PvParserBase
{
    protected Worksheet $sheet;
    protected int $maxColNum;
    /** @var array<int,string> en-tête ligne 1 par numéro de colonne (1-indexé) */
    protected array $h1 = [];
    /** @var array<int,string> en-tête ligne 2 par numéro de colonne (1-indexé) */
    protected array $h2 = [];

    protected function load(string $file): void
    {
        $ss = IOFactory::load($file);
        $this->sheet = $ss->getSheetByName('PVout') ?? $ss->getActiveSheet();
        $this->maxColNum = $this->colLetterToNum($this->sheet->getHighestColumn());
        for ($c = 1; $c <= $this->maxColNum; $c++) {
            $this->h1[$c] = $this->norm((string)($this->cellValue($c, 1) ?? ''));
            $this->h2[$c] = $this->norm((string)($this->cellValue($c, 2) ?? ''));
        }
    }

    protected function norm(?string $v): string
    {
        return $v === null ? '' : trim($v);
    }

    /** Convertit un numéro de colonne (1, 27, 89…) en lettre ('A', 'AA', 'CK'…). */
    protected function colNumToLetter(int $num): string
    {
        $letters = '';
        while ($num > 0) {
            $mod = ($num - 1) % 26;
            $letters = chr(ord('A') + $mod) . $letters;
            $num = intdiv($num - 1, 26);
        }
        return $letters;
    }

    /** Convertit une lettre de colonne ('A', 'CK', 'DU'…) en numéro (1, 89, 125…). */
    protected function colLetterToNum(string $letter): int
    {
        $letter = strtoupper($letter);
        $num = 0;
        for ($i = 0; $i < strlen($letter); $i++) {
            $num = $num * 26 + (ord($letter[$i]) - ord('A') + 1);
        }
        return $num;
    }

    /** Lecture de la valeur d'une cellule par numéro de colonne (int) + ligne. */
    protected function cellValue(int $col, int $row)
    {
        $coord = $this->colNumToLetter($col) . $row;
        return $this->sheet->getCell($coord)->getValue();
    }

    /**
     * Liste des blocs dont l'en-tête ligne 1 commence par 'ELP'.
     * @return array<int,array{start:int,end:int,header:string}>
     */
    protected function findElpBlocks(): array
    {
        $starts = [];
        for ($c = 1; $c <= $this->maxColNum; $c++) {
            if (str_starts_with($this->h1[$c], 'ELP')) {
                $starts[] = $c;
            }
        }
        $out = [];
        $n = count($starts);
        for ($i = 0; $i < $n; $i++) {
            $start = $starts[$i];
            $end = ($i + 1 < $n) ? $starts[$i + 1] - 1 : $this->maxColNum;
            $out[] = ['start' => $start, 'end' => $end, 'header' => $this->h1[$start]];
        }
        return $out;
    }

    protected function firstElpStart(): int
    {
        $b = $this->findElpBlocks();
        return $b ? $b[0]['start'] : $this->maxColNum;
    }

    protected function colWithH2(array $block, string $label): ?int
    {
        for ($c = $block['start']; $c <= $block['end']; $c++) {
            if (strcasecmp($this->h2[$c], $label) === 0) {
                return $c;
            }
        }
        return null;
    }

    protected function colWithH2InRange(int $from, int $to, string $label): ?int
    {
        for ($c = $from; $c <= $to; $c++) {
            if (strcasecmp($this->h2[$c], $label) === 0) {
                return $c;
            }
        }
        return null;
    }

    protected function highestDataRow(): int
    {
        return $this->sheet->getHighestDataRow();
    }

    protected function valueAt(?int $col, int $row)
    {
        if ($col === null) {
            return null;
        }
        return $this->cellValue($col, $row);
    }

    protected function textAt(?int $col, int $row): string
    {
        $v = $this->valueAt($col, $row);
        return $v === null ? '' : trim((string)$v);
    }

    /** Extrait 'Semestre 3' => 3 dans un en-tête de bloc. */
    protected function semesterNumber(string $header): ?int
    {
        if (preg_match('/Semestre\s*(\d+)/i', $header, $m)) {
            return (int)$m[1];
        }
        return null;
    }

    /** Extrait le parcours depuis un en-tête de bloc semestre : 'Semestre 3 Biologie' => 'Biologie'. */
    protected function semesterParcours(string $header): ?string
    {
        if (preg_match('/Semestre\s*\d+\s+(.+?)\s*$/i', $header, $m)) {
            $p = trim($m[1]);
            if ($p !== '') {
                return $p;
            }
        }
        return null;
    }

    /**
     * Sépare un en-tête de UE du type 'ELP VI1DCUBB  Bioch. bio. mol. fond.'
     * @return array{code:?string,name:string}
     */
    protected function splitUeHeader(string $header): array
    {
        $parts = preg_split('/\s{2,}/', $header);
        $head = $parts[0] ?? '';
        $name = trim($parts[1] ?? '');
        $tokens = preg_split('/\s+/', $head);
        $code = isset($tokens[1]) ? $tokens[1] : null;
        return ['code' => $code, 'name' => $name];
    }

    /** Indice de parcours depuis le nom de fichier d'origine (APO_PVL2S3BIO.xlsx => BIO). */
    protected function guessParcoursFromFilename(string $file): ?string
    {
        $base = pathinfo($file, PATHINFO_FILENAME);
        if (preg_match('/^APO_PV\s?L\d+(?:S\d+|ANNEE)([A-Za-z0-9]+)$/i', $base, $m)) {
            return strtoupper($m[1]);
        }
        return null;
    }
}