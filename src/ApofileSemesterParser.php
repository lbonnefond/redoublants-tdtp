<?php
declare(strict_types=1);

namespace LBonnefond\RedoublantsTdTp;

class ApofileSemesterParser extends PvParserBase
{
    private ?int $nuCol = null;
    private ?int $nomCol = null;
    private ?int $prenomCol = null;
    private ?int $semStatusCol = null;
    private ?int $semesterNumber = null;
    private string $semesterLabel = '';
    private string $parcours = '';
    /** @var array<int,array<string,mixed>> */
    private array $ues = [];
    /** @var array<int,array<string,mixed>> */
    private array $students = [];
    /** @var string[] */
    public array $warnings = [];

    public function __construct(string $file, ?string $originalName = null)
    {
        $name = $originalName ?? $file;
        $this->parcours = $this->guessParcoursFromFilename($name)
            ?? pathinfo($name, PATHINFO_FILENAME);
        $this->load($file);
        $this->detectLayout();
        $this->readStudents();
    }

    private function detectLayout(): void
    {
        $firstElp = $this->firstElpStart();
        $studentEnd = $firstElp - 1;
        $this->nuCol = $this->colWithH2InRange(1, $studentEnd, 'N°Etu');
        $this->nomCol = $this->colWithH2InRange(1, $studentEnd, 'Nom');
        $this->prenomCol = $this->colWithH2InRange(1, $studentEnd, 'Prénom');

        $skipped = [];
        $currentUeIndex = null;

        foreach ($this->findElpBlocks() as $b) {
            $header = $b['header'];
            $num = $this->semesterNumber($header);

            // --- Bloc statut du semestre ---
            if ($num !== null) {
                $this->semStatusCol = $this->colWithH2($b, 'Résultat');
                $this->semesterNumber = $num;
                $this->semesterLabel = $header;
                $currentUeIndex = null;
                continue;
            }

            // --- Exclusion : Option ---
            if (stripos($header, 'option') !== false) {
                $ue = $this->splitUeHeader($header);
                $skipped[] = ($ue['code'] ?? '?') . ' (Option)';
                continue;
            }

            // --- Test matière : Résultat + Crédits ---
            $resCol = $this->colWithH2($b, 'Résultat');
            $credCol = $this->colWithH2($b, 'Crédits');

            if ($resCol !== null && $credCol !== null) {
                $ue = $this->splitUeHeader($header);

                $noteCol = null;
                for ($cc = $b['start']; $cc <= $b['end']; $cc++) {
                    if (strcasecmp($this->h2[$cc], 'Note_Ado/20') === 0) {
                        $noteCol = $cc;
                        break;
                    }
                }
                $anSessionCol = $this->colWithH2($b, 'An/Session');

                $exams = [];
                for ($cc = $b['start'] + 1; $cc <= $b['end']; $cc++) {
                    if (str_starts_with($this->h1[$cc], 'EPR')) {
                        $exams[] = ['label' => $this->h1[$cc], 'col' => $cc];
                    }
                }

                $this->ues[] = [
                    'code' => $ue['code'],
                    'name' => $ue['name'],
                    'noteCol' => $noteCol,
                    'mentionCol' => $resCol,
                    'anSessionCol' => $anSessionCol,
                    'exams' => $exams,
                ];
                $currentUeIndex = count($this->ues) - 1;
                continue;
            }

            // --- Bloc sans Résultat/Crédits ---
            $ue = $this->splitUeHeader($header);
            // Un « bloc pédagogique » (BP1 PAM, BP2 PAM) est absorbé par la matière
            // précédente : ses épreuves EPR deviennent celles de la matière, et s'il
            // n'a qu'une seule Note_Ado/20 sans EPR, c'est une épreuve unique (ex. la
            // note de TP). Tout autre bloc isolé (ex. « Orientation BCPO ou AA ») est
            // ignoré.
            $isPedagogical = preg_match('/^BP\d/i', $ue['name'] ?? '') === 1;

            if ($currentUeIndex !== null && $isPedagogical) {
                $blockExams = [];
                $blockNoteCol = null;
                for ($cc = $b['start']; $cc <= $b['end']; $cc++) {
                    if (str_starts_with($this->h1[$cc], 'EPR')) {
                        $blockExams[] = ['label' => $this->h1[$cc], 'col' => $cc];
                    }
                    if ($blockNoteCol === null && strcasecmp($this->h2[$cc], 'Note_Ado/20') === 0) {
                        $blockNoteCol = $cc;
                    }
                }
                if (!empty($blockExams)) {
                    // BP1 : les EPR sont les vraies épreuves, la note du bloc = moyenne (ignorée)
                    foreach ($blockExams as $e) {
                        $this->ues[$currentUeIndex]['exams'][] = $e;
                    }
                } elseif ($blockNoteCol !== null) {
                    // BP2 : une seule note sans épreuve nommée → épreuve unique (ex. le TP)
                    $this->ues[$currentUeIndex]['exams'][] = [
                        'label' => ($ue['name'] !== '') ? $ue['name'] : $ue['code'],
                        'col' => $blockNoteCol,
                    ];
                }
            } else {
                $skipped[] = ($ue['code'] ?? '?') . ' (' . (($ue['name'] !== '') ? $ue['name'] : 'bloc isolé') . ')';
            }
        }

        if (!empty($skipped)) {
            $this->warnings[] = count($skipped) . ' bloc(s) ignorés : ' . implode(', ', $skipped);
        }
        if ($this->nuCol === null) {
            $this->warnings[] = 'Colonne N°Etu non détectée : aucun étudiant lu.';
        }
        if ($this->semesterNumber === null) {
            $this->warnings[] = 'Bloc statut de semestre (« Semestre X ») non détecté.';
        }
        if (count($this->ues) === 0) {
            $this->warnings[] = 'Aucune UE détectée.';
        }
    }

    private function readStudents(): void
    {
        $maxRow = $this->highestDataRow();
        $seen = [];
        for ($r = 3; $r <= $maxRow; $r++) {
            $raw = $this->valueAt($this->nuCol, $r);
            if (!is_numeric($raw)) {
                continue;
            }
            $nu = (int)$raw;
            if ($nu < 100000) {
                continue;
            }
            if (isset($seen[$nu])) {
                continue;
            }
            $seen[$nu] = true;
            $st = [
                'numero' => $nu,
                'nom' => $this->textAt($this->nomCol, $r),
                'prenom' => $this->textAt($this->prenomCol, $r),
                'sem_status' => $this->textAt($this->semStatusCol, $r),
                'ues' => [],
            ];
            foreach ($this->ues as $ue) {
                $exams = [];
                foreach ($ue['exams'] as $e) {
                    $exams[$e['label']] = $this->textAt($e['col'], $r);
                }
                $st['ues'][$ue['code']] = [
                    'note' => $this->textAt($ue['noteCol'], $r),
                    'mention' => $this->textAt($ue['mentionCol'], $r),
                    'anSession' => $this->textAt($ue['anSessionCol'], $r),
                    'exams' => $exams,
                ];
            }
            $this->students[] = $st;
        }
    }

    public function toArray(): array
    {
        $ues = [];
        foreach ($this->ues as $ue) {
            $ues[] = [
                'code' => $ue['code'],
                'name' => $ue['name'],
                'exams' => array_map(fn($e) => $e['label'], $ue['exams']),
            ];
        }
        return [
            'parcours' => $this->parcours,
            'semester_number' => $this->semesterNumber,
            'semester_label' => $this->semesterLabel,
            'ues' => $ues,
            'students' => $this->students,
        ];
    }
}