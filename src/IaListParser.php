<?php
declare(strict_types=1);

namespace LBonnefond\RedoublantsTdTp;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class IaListParser
{
    private Worksheet $sheet;
    private int $maxColNum;
    private int $maxRow;
    private ?int $idCol = null;
    private ?int $prenomCol = null;
    private ?int $nomCol = null;
    private ?int $emailCol = null;
    private ?int $parcoursCol = null;
    private ?int $headerRow = null;
    /** @var array<int,array<string,mixed>> */
    private array $students = [];
    /** @var string[] */
    public array $warnings = [];

    public function __construct(string $file)
    {
        $ss = IOFactory::load($file);
        $this->sheet = $ss->getSheet(0);
        $this->maxColNum = $this->colLetterToNum($this->sheet->getHighestColumn());
        $this->maxRow = $this->sheet->getHighestDataRow();
        $this->detectHeader();
        $this->readStudents();
    }

    private function cellValue(?int $col, int $row): ?string
    {
        if ($col === null) {
            return null;
        }
        $v = $this->sheet->getCell($this->colNumToLetter($col) . $row)->getValue();
        return $v === null ? null : trim((string)$v);
    }

    private function detectHeader(): void
    {
        for ($r = 1; $r <= min(15, $this->maxRow); $r++) {
            $foundId = false;
            $foundPrenom = false;
            $foundParcours = false;
            for ($c = 1; $c <= $this->maxColNum; $c++) {
                $h = strtolower(trim((string)$this->cellValue($c, $r)));
                if ($h === 'id' || $h === 'n°etu' || $h === 'numero') {
                    $this->idCol = $c;
                    $foundId = true;
                }
                if ($h === 'prénom' || $h === 'prenom') {
                    $this->prenomCol = $c;
                    $foundPrenom = true;
                }
                if (str_contains($h, 'famille') || $h === 'nom') {
                    $this->nomCol = $c;
                }
                if (str_contains($h, 'courriel') || str_contains($h, 'email') || str_contains($h, '@')) {
                    $this->emailCol = $c;
                }
                if (str_contains($h, 'parcours')) {
                    $this->parcoursCol = $c;
                    $foundParcours = true;
                }
            }
            if ($foundId && $foundPrenom && $foundParcours) {
                $this->headerRow = $r;
                return;
            }
        }
        $this->warnings[] = 'En-tête non détecté (ID/Prénom/Parcours) : aucun étudiant lu.';
    }

    private function readStudents(): void
    {
        if ($this->headerRow === null || $this->idCol === null) {
            return;
        }
        for ($r = $this->headerRow + 1; $r <= $this->maxRow; $r++) {
            $idRaw = $this->cellValue($this->idCol, $r);
            if ($idRaw === null || !is_numeric($idRaw)) {
                continue;
            }
            $id = (int)$idRaw;
            if ($id < 100000) {
                continue;
            }
            $this->students[] = [
                'id' => $id,
                'prenom' => $this->cellValue($this->prenomCol, $r) ?? '',
                'nom' => $this->cellValue($this->nomCol, $r) ?? '',
                'email' => $this->cellValue($this->emailCol, $r) ?? '',
                'parcours' => $this->cellValue($this->parcoursCol, $r) ?? '',
            ];
        }
    }

    public function toArray(): array
    {
        return ['students' => $this->students];
    }

    private function colLetterToNum(string $letter): int
    {
        $letter = strtoupper($letter);
        $num = 0;
        for ($i = 0; $i < strlen($letter); $i++) {
            $num = $num * 26 + (ord($letter[$i]) - ord('A') + 1);
        }
        return $num;
    }

    private function colNumToLetter(int $num): string
    {
        $letters = '';
        while ($num > 0) {
            $mod = ($num - 1) % 26;
            $letters = chr(ord('A') + $mod) . $letters;
            $num = intdiv($num - 1, 26);
        }
        return $letters;
    }
}