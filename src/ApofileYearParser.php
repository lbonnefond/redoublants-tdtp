<?php
declare(strict_types=1);

namespace LBonnefond\RedoublantsTdTp;

class ApofileYearParser extends PvParserBase
{
    private ?int $nuCol = null;
    private ?int $nomCol = null;
    private ?int $prenomCol = null;
    private ?int $yearStatusCol = null;
    /** @var array<int,int> numéro de semestre => colonne statut (Résultat du bloc) */
    private array $semStatus = [];
    private string $parcours = '';
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

        foreach ($this->findElpBlocks() as $b) {
            $num = $this->semesterNumber($b['header']);
            $resCol = $this->colWithH2($b, 'Résultat');
            if ($num !== null) {
                $this->semStatus[$num] = $resCol;
            } else {
                $this->yearStatusCol = $resCol;
            }
        }

        if ($this->nuCol === null) {
            $this->warnings[] = 'Colonne N°Etu non détectée : aucun étudiant lu.';
        }
        if ($this->yearStatusCol === null) {
            $this->warnings[] = "Statut de l'année (bloc global) non détecté : le filtre par statut annuel sera ignoré.";
        }
        if (count($this->semStatus) === 0) {
            $this->warnings[] = "Aucun bloc de semestre (« Semestre X ») détecté dans le PV d'année.";
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
                'year_status' => $this->textAt($this->yearStatusCol, $r),
                'semester_status' => [],
            ];
            foreach ($this->semStatus as $num => $col) {
                $st['semester_status'][(string)$num] = $this->textAt($col, $r);
            }
            $this->students[] = $st;
        }
    }

    public function toArray(): array
    {
        return [
            'parcours' => $this->parcours,
            'sem_numbers' => array_keys($this->semStatus),
            'has_year_status' => $this->yearStatusCol !== null,
            'students' => $this->students,
        ];
    }
}