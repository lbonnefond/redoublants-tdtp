<?php
declare(strict_types=1);

namespace LBonnefond\RedoublantsTdTp;

class MatterMatcher
{
    /**
     * Regroupe les UEs de tous les PV de semestre par matière (clé de matching).
     *
     * @param array $semData  $_SESSION['sem'] : liste de PV parsés
     * @return array liste de groupes de matières, dans un ordre stable
     */
    public function match(array $semData): array
    {
        $groups = [];
        $order = [];

        foreach ($semData as $pv) {
            $parcours = $pv['data']['parcours'];
            foreach ($pv['data']['ues'] as $ue) {
                $key = $this->matchKey($ue['code'], $ue['name']);
                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'key' => $key,
                        'name' => ($ue['name'] !== '') ? $ue['name'] : $ue['code'],
                        'codes' => [],
                        'parcours' => [],
                        'exams' => [],
                        'tracks' => [],
                    ];
                    $order[] = $key;
                }
                $g = &$groups[$key];
                $g['codes'][$ue['code']] = true;
                $g['parcours'][$parcours] = true;
                $g['tracks'][] = ['parcours' => $parcours, 'code' => $ue['code']];
                foreach ($ue['exams'] as $ex) {
                    $g['exams'][$ex] = true;
                }
                if ($g['name'] === $g['key'] && $ue['name'] !== '') {
                    $g['name'] = $ue['name'];
                }
            }
            unset($g);
        }

        $result = [];
        foreach ($order as $key) {
            $g = $groups[$key];
            $g['codes'] = array_keys($g['codes']);
            $g['parcours'] = array_keys($g['parcours']);
            $g['exams'] = array_keys($g['exams']);
            $g['shared'] = count($g['parcours']) > 1;
            $result[] = $g;
        }
        return $result;
    }

    /**
     * Fusionne les groupes qui portent le MÊME acronyme (non vide) dans la config.
     * Une matière = un acronyme. Les groupes sans acronyme restent isolés.
     *
     * @param array $courses  liste des groupes de MatterMatcher::match()
     * @param array $config   $_SESSION['course_config'] indexé par position $i
     * @return array liste de « matières effectives » (fusionnées si même acronyme)
     */
    public function mergeByAcronym(array $courses, array $config): array
    {
        $result = [];
        $byAcronym = [];

        foreach ($courses as $i => $course) {
            $cfg = $config[$i] ?? null;
            $acronym = $cfg ? trim((string)$cfg['acronym']) : '';

            if ($acronym === '') {
                $result[] = $this->buildEffective($course, $cfg);
                continue;
            }

            $key = strtoupper($acronym);
            if (!isset($byAcronym[$key])) {
                $byAcronym[$key] = count($result);
                $result[] = $this->buildEffective($course, $cfg);
            } else {
                $result[$byAcronym[$key]] = $this->mergeEffective($result[$byAcronym[$key]], $course, $cfg);
            }
        }
        return $result;
    }

    /** Construit une matière effective à partir d'un groupe + sa config. */
    private function buildEffective(array $course, ?array $cfg): array
    {
        $examsSelected = [];
        if ($cfg) {
            foreach ($course['exams'] as $label) {
                $examsSelected[$label] = !empty($cfg['exams'][$label]);
            }
        }
        return [
            'name' => $course['name'],
            'acronym' => $cfg ? trim((string)$cfg['acronym']) : '',
            'codes' => $course['codes'],
            'parcours' => $course['parcours'],
            'exams' => $course['exams'],
            'shared' => $course['shared'],
            'threshold' => $cfg ? $cfg['threshold'] : null,
            'display' => $cfg ? !empty($cfg['display']) : false,
            'exams_selected' => $examsSelected,
            'merged' => 1,
        ];
    }

    /** Combine un groupe source à une matière effective existante (même acronyme). */
    private function mergeEffective(array $a, array $course, ?array $cfg): array
    {
        foreach ($course['codes'] as $c) {
            $a['codes'][$c] = true;
        }
        $a['codes'] = array_keys($a['codes']);

        foreach ($course['parcours'] as $p) {
            $a['parcours'][$p] = true;
        }
        $a['parcours'] = array_keys($a['parcours']);

        foreach ($course['exams'] as $ex) {
            if (!in_array($ex, $a['exams'], true)) {
                $a['exams'][] = $ex;
                if (!isset($a['exams_selected'][$ex])) {
                    $a['exams_selected'][$ex] = false;
                }
            }
        }

        if ($cfg) {
            foreach ($course['exams'] as $label) {
                if (!empty($cfg['exams'][$label])) {
                    $a['exams_selected'][$label] = true;
                }
            }
            $a['display'] = $a['display'] || !empty($cfg['display']);
            if ($a['threshold'] === null && $cfg['threshold'] !== null) {
                $a['threshold'] = $cfg['threshold'];
            }
        }

        if (strlen($course['name']) > strlen($a['name'])) {
            $a['name'] = $course['name'];
        }

        $a['shared'] = count($a['parcours']) > 1;
        $a['merged']++;
        return $a;
    }

    /** Clé de matching : 4 derniers caractères du code UE + 1er mot normalisé du nom. */
    public function matchKey(string $code, string $name): string
    {
        $suffix = strtoupper(substr($code, -4));
        return $suffix . '|' . $this->firstWordOfName($name);
    }

    /** 1er mot du nom, normalisé (majuscules, sans accents, sans ponctuation). */
    public function firstWordOfName(string $name): string
    {
        $name = trim($name);
        $word = '';
        if ($name !== '') {
            $parts = preg_split('/\s+/', $name);
            $word = $parts[0] ?? '';
        }
        return $this->normalize($word);
    }

    private function normalize(string $s): string
    {
        $s = mb_strtoupper($s, 'UTF-8');
        $map = [
            'À'=>'A','Â'=>'A','Ä'=>'A','Å'=>'A','Æ'=>'AE','Ç'=>'C',
            'È'=>'E','Ê'=>'E','Ë'=>'E','É'=>'E',
            'Ì'=>'I','Î'=>'I','Ï'=>'I','Í'=>'I',
            'Ò'=>'O','Ô'=>'O','Ö'=>'O','Ó'=>'O',
            'Ù'=>'U','Û'=>'U','Ü'=>'U','Ú'=>'U',
            'Ñ'=>'N','ß'=>'SS','Þ'=>'TH','Ý'=>'Y',
        ];
        $s = strtr($s, $map);
        $s = preg_replace('/[^A-Z0-9]/', '', $s);
        return $s;
    }
}