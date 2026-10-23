<?php
declare(strict_types=1);

namespace LBonnefond\RedoublantsTdTp;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExcelExporter
{
    /** Statuts de semestre considérés « acquis » (l'étudiant ne suit plus le semestre). */
    private const ACQUIRED_SEM = ['ADM', 'ACQ'];

    // ============ LOT 3 : export des potentiels redoublants ============
    public function export(array $redoublants, array $effective, array $courses, array $semData, int $semester): string
    {
        $studentData = $this->buildStudentIndex($semData);
        $matterCodes = $this->buildMatterCodeMap($courses, $effective);
        $shownMatters = array_filter($effective, fn($ev) => !empty($ev['display']));

        $spreadsheet = new Spreadsheet();
        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle('Redoublants');

        $headerCells = [
            ['N°', 1],
            ['Nom', 2],
            ['Prénom', 3],
            ['Parcours', 4],
            ['Semestre', 5],
        ];
        $layout = [];
        $col = 6;
        foreach ($shownMatters as $mIdx => $matter) {
            $header = $matter['acronym'] !== '' ? $matter['acronym'] : $matter['name'];
            $mentionCol = $col;
            $headerCells[] = [$header, $col];
            $col++;
            $examCols = [];
            foreach ($matter['exams_selected'] as $label => $sel) {
                if (!$sel) {
                    continue;
                }
                $examCols[$label] = $col;
                $headerCells[] = [$this->shortLabel($label), $col];
                $col++;
            }
            $layout[$mIdx] = ['mention' => $mentionCol, 'exams' => $examCols];
        }
        $lastCol = $col - 1;

        foreach ($headerCells as [$label, $c]) {
            $coord = $this->coord($c, 1);
            $ws->setCellValue($coord, $label);
            $ws->getStyle($coord)->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFD9E1F2']],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            ]);
        }

        $row = 2;
        $stats = [];
        foreach ($shownMatters as $mIdx => $matter) {
            $stats[$mIdx] = ['total' => 0, 'dispensed' => 0];
        }

        foreach ($redoublants as $student) {
            $parcours = $student['parcours'];
            $stuData = $studentData[$parcours][$student['numero']] ?? null;
            $semStatus = $student['sem_status'] ?? '';

            $ws->setCellValue($this->coord(1, $row), $student['numero']);
            $ws->setCellValue($this->coord(2, $row), $student['nom']);
            $ws->setCellValue($this->coord(3, $row), $student['prenom']);
            $ws->setCellValue($this->coord(4, $row), $parcours);

            $acquired = in_array($semStatus, self::ACQUIRED_SEM, true);
            $semCoord = $this->coord(5, $row);
            if ($acquired) {
                $ws->setCellValue($semCoord, 'ACQ');
                $ws->getStyle($semCoord)->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFC6EFCE']],
                ]);
            } else {
                $ws->setCellValue($semCoord, $semStatus);
            }

            if (!$acquired) {
                foreach ($shownMatters as $mIdx => $matter) {
                    $lay = $layout[$mIdx];
                    $codes = $matterCodes[$mIdx][$parcours] ?? [];
                    if (empty($codes)) {
                        continue;
                    }
                    $mention = '';
                    $notes = [];
                    if ($stuData !== null) {
                        foreach ($codes as $code) {
                            $ue = $stuData[$code] ?? null;
                            if ($ue === null) {
                                continue;
                            }
                            $mention = $ue['mention'];
                            $notes = $ue['exams'];
                            break;
                        }
                    }
                    $mCoord = $this->coord($lay['mention'], $row);
                    $ws->setCellValue($mCoord, $mention);
                    if ($mention === 'ACQ') {
                        $ws->getStyle($mCoord)->applyFromArray([
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFC6EFCE']],
                        ]);
                    }
                    $dispNote = false;
                    foreach ($lay['exams'] as $label => $cIdx) {
                        $nCoord = $this->coord($cIdx, $row);
                        $num = $this->parseNote($notes[$label] ?? '');
                        $ws->setCellValue($nCoord, $num !== null ? $num : '—');
                        if ($num !== null && $matter['threshold'] !== null && $num >= $matter['threshold']) {
                            $ws->getStyle($nCoord)->applyFromArray([
                                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFF2CC']],
                            ]);
                            $dispNote = true;
                        }
                    }
                    $stats[$mIdx]['total']++;
                    if ($mention === 'ACQ' || $dispNote) {
                        $stats[$mIdx]['dispensed']++;
                    }
                }
            }

            for ($c = 1; $c <= $lastCol; $c++) {
                $this->applyThinBorder($ws, $c, $row);
            }
            $row++;
        }

        // Récap (2e feuille)
        $ws2 = $spreadsheet->createSheet();
        $ws2->setTitle('Récap');
        $rrow = 1;
        $ws2->setCellValue($this->coord(1, $rrow), 'RÉCAP — Dispensations');
        $ws2->getStyle($this->coord(1, $rrow))->applyFromArray(['font' => ['bold' => true]]);
        $rrow++;
        $capHeaders = ['Matière', 'Acronyme', 'Redoublants', 'Dispensés'];
        foreach ($capHeaders as $c => $h) {
            $coord = $this->coord($c + 1, $rrow);
            $ws2->setCellValue($coord, $h);
            $ws2->getStyle($coord)->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE2EFDA']],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            ]);
        }
        $rrow++;
        foreach ($shownMatters as $mIdx => $matter) {
            $s = $stats[$mIdx];
            $ws2->setCellValue($this->coord(1, $rrow), $matter['name']);
            $ws2->setCellValue($this->coord(2, $rrow), $matter['acronym'] !== '' ? $matter['acronym'] : '—');
            $ws2->setCellValue($this->coord(3, $rrow), $s['total']);
            $ws2->setCellValue($this->coord(4, $rrow), $s['dispensed']);
            for ($c = 1; $c <= 4; $c++) {
                $this->applyThinBorder($ws2, $c, $rrow);
            }
            $rrow++;
        }

        for ($c = 1; $c <= $lastCol; $c++) {
            $ws->getColumnDimension($this->colNumToLetter($c))->setAutoSize(true);
        }
        for ($c = 1; $c <= 4; $c++) {
            $ws2->getColumnDimension($this->colNumToLetter($c))->setAutoSize(true);
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'redoublants_') . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tmpFile);
        $content = file_get_contents($tmpFile);
        unlink($tmpFile);
        return $content;
    }

    // ============ LOT 4 : export IA (inscriptions) enrichi ============
    // Liste maîtresse = étudiants inscrits (IA). On remplit les colonnes matières
    // seulement pour ceux qui sont redoublants effectivement inscrits ; sinon vides.
    public function exportIa(array $iaStudents, array $redoublants, array $effective, array $courses, array $semData): string
    {
        $studentData = $this->buildStudentIndex($semData);
        $matterCodes = $this->buildMatterCodeMap($courses, $effective);
        $shownMatters = array_filter($effective, fn($ev) => !empty($ev['display']));

        // Index des potentiels redoublants par N°Etu
        $redoublantIndex = [];
        foreach ($redoublants as $r) {
            $redoublantIndex[$r['numero']] = $r;
        }

        $spreadsheet = new Spreadsheet();
        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle('Inscrits');

        // Colonnes fixes = celles du fichier IA, puis Semestre, puis matières
        $headerCells = [
            ['ID', 1],
            ['Prénom', 2],
            ['Nom de famille', 3],
            ['E-mail', 4],
            ['Parcours', 5],
            ['Semestre', 6],
        ];
        $layout = [];
        $col = 7;
        foreach ($shownMatters as $mIdx => $matter) {
            $header = $matter['acronym'] !== '' ? $matter['acronym'] : $matter['name'];
            $headerCells[] = [$header, $col];
            $layout[$mIdx] = ['mention' => $col, 'exams' => []];
            $col++;
            foreach ($matter['exams_selected'] as $label => $sel) {
                if (!$sel) {
                    continue;
                }
                $layout[$mIdx]['exams'][$label] = $col;
                $headerCells[] = [$this->shortLabel($label), $col];
                $col++;
            }
        }
        $lastCol = $col - 1;

        foreach ($headerCells as [$label, $c]) {
            $coord = $this->coord($c, 1);
            $ws->setCellValue($coord, $label);
            $ws->getStyle($coord)->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFD9E1F2']],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            ]);
        }

        $row = 2;
        $stats = [];
        foreach ($shownMatters as $mIdx => $matter) {
            $stats[$mIdx] = ['total' => 0, 'dispensed' => 0];
        }

        $nEnrolledRedoublants = 0;
        foreach ($iaStudents as $ia) {
            $ws->setCellValue($this->coord(1, $row), $ia['id']);
            $ws->setCellValue($this->coord(2, $row), $ia['prenom']);
            $ws->setCellValue($this->coord(3, $row), $ia['nom']);
            $ws->setCellValue($this->coord(4, $row), $ia['email']);
            $ws->setCellValue($this->coord(5, $row), $ia['parcours']);

            $r = $redoublantIndex[$ia['id']] ?? null;
            if ($r !== null) {
                $nEnrolledRedoublants++;
                $parcours = $r['parcours'];
                $stuData = $studentData[$parcours][$r['numero']] ?? null;
                $semStatus = $r['sem_status'] ?? '';
                $acquired = in_array($semStatus, self::ACQUIRED_SEM, true);

                $semCoord = $this->coord(6, $row);
                if ($acquired) {
                    $ws->setCellValue($semCoord, 'ACQ');
                    $ws->getStyle($semCoord)->applyFromArray([
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFC6EFCE']],
                    ]);
                } else {
                    $ws->setCellValue($semCoord, $semStatus);
                }

                if (!$acquired) {
                    foreach ($shownMatters as $mIdx => $matter) {
                        $lay = $layout[$mIdx];
                        $codes = $matterCodes[$mIdx][$parcours] ?? [];
                        if (empty($codes)) {
                            continue;
                        }
                        $mention = '';
                        $notes = [];
                        if ($stuData !== null) {
                            foreach ($codes as $code) {
                                $ue = $stuData[$code] ?? null;
                                if ($ue === null) {
                                    continue;
                                }
                                $mention = $ue['mention'];
                                $notes = $ue['exams'];
                                break;
                            }
                        }
                        $mCoord = $this->coord($lay['mention'], $row);
                        $ws->setCellValue($mCoord, $mention);
                        if ($mention === 'ACQ') {
                            $ws->getStyle($mCoord)->applyFromArray([
                                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFC6EFCE']],
                            ]);
                        }
                        $dispNote = false;
                        foreach ($lay['exams'] as $label => $cIdx) {
                            $nCoord = $this->coord($cIdx, $row);
                            $num = $this->parseNote($notes[$label] ?? '');
                            $ws->setCellValue($nCoord, $num !== null ? $num : '—');
                            if ($num !== null && $matter['threshold'] !== null && $num >= $matter['threshold']) {
                                $ws->getStyle($nCoord)->applyFromArray([
                                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFF2CC']],
                                ]);
                                $dispNote = true;
                            }
                        }
                        $stats[$mIdx]['total']++;
                        if ($mention === 'ACQ' || $dispNote) {
                            $stats[$mIdx]['dispensed']++;
                        }
                    }
                }
            }

            for ($c = 1; $c <= $lastCol; $c++) {
                $this->applyThinBorder($ws, $c, $row);
            }
            $row++;
        }

        // Récap (2e feuille) — ne compte que les redoublants effectivement inscrits
        $ws2 = $spreadsheet->createSheet();
        $ws2->setTitle('Récap');
        $rrow = 1;
        $ws2->setCellValue($this->coord(1, $rrow), 'RÉCAP — Dispensations (' . $nEnrolledRedoublants . ' redoublants inscrits)');
        $ws2->getStyle($this->coord(1, $rrow))->applyFromArray(['font' => ['bold' => true]]);
        $rrow++;
        $capHeaders = ['Matière', 'Acronyme', 'Redoublants', 'Dispensés'];
        foreach ($capHeaders as $c => $h) {
            $coord = $this->coord($c + 1, $rrow);
            $ws2->setCellValue($coord, $h);
            $ws2->getStyle($coord)->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE2EFDA']],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            ]);
        }
        $rrow++;
        foreach ($shownMatters as $mIdx => $matter) {
            $s = $stats[$mIdx];
            $ws2->setCellValue($this->coord(1, $rrow), $matter['name']);
            $ws2->setCellValue($this->coord(2, $rrow), $matter['acronym'] !== '' ? $matter['acronym'] : '-');
            $ws2->setCellValue($this->coord(3, $rrow), $s['total']);
            $ws2->setCellValue($this->coord(4, $rrow), $s['dispensed']);
            for ($c = 1; $c <= 4; $c++) {
                $this->applyThinBorder($ws2, $c, $rrow);
            }
            $rrow++;
        }

        for ($c = 1; $c <= $lastCol; $c++) {
            $ws->getColumnDimension($this->colNumToLetter($c))->setAutoSize(true);
        }
        for ($c = 1; $c <= 4; $c++) {
            $ws2->getColumnDimension($this->colNumToLetter($c))->setAutoSize(true);
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'redoublants_ia_') . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tmpFile);
        $content = file_get_contents($tmpFile);
        unlink($tmpFile);
        return $content;
    }

    // ============ helpers ============
    private function buildStudentIndex(array $semData): array
    {
        $index = [];
        foreach ($semData as $pv) {
            $parcours = $pv['data']['parcours'];
            foreach ($pv['data']['students'] as $s) {
                $index[$parcours][$s['numero']] = $s['ues'];
            }
        }
        return $index;
    }

    private function buildMatterCodeMap(array $courses, array $effective): array
    {
        $map = [];
        $evCodeSets = [];
        foreach ($effective as $mIdx => $ev) {
            $evCodeSets[$mIdx] = array_flip($ev['codes']);
        }
        foreach ($courses as $course) {
            foreach ($effective as $mIdx => $ev) {
                $matched = false;
                foreach ($course['codes'] as $code) {
                    if (isset($evCodeSets[$mIdx][$code])) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    continue;
                }
                if (!isset($map[$mIdx])) {
                    $map[$mIdx] = [];
                }
                foreach ($course['tracks'] as $track) {
                    $map[$mIdx][$track['parcours']][] = $track['code'];
                }
            }
        }
        return $map;
    }

    private function parseNote(?string $note): ?float
    {
        if ($note === null || $note === '' || !is_numeric($note)) {
            return null;
        }
        return (float) $note;
    }

    private function shortLabel(string $label): string
    {
        if (preg_match('/^EPR\s+\S+\s{2,}(.+)$/s', $label, $m)) {
            return $m[1];
        }
        if (preg_match('/^EPR\s+\S+\s+(.+)$/s', $label, $m)) {
            return $m[1];
        }
        return $label;
    }

    private function applyThinBorder($ws, int $col, int $row): void
    {
        $ws->getStyle($this->coord($col, $row))->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
    }

    private function coord(int $col, int $row): string
    {
        return $this->colNumToLetter($col) . $row;
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