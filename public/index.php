<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use LBonnefond\RedoublantsTdTp\ApofileYearParser;
use LBonnefond\RedoublantsTdTp\ApofileSemesterParser;
use LBonnefond\RedoublantsTdTp\MatterMatcher;
use LBonnefond\RedoublantsTdTp\ExcelExporter;
use LBonnefond\RedoublantsTdTp\IaListParser;

session_start();

$REDOUB_STATUSES = ['AJ', 'DEF', 'DIS'];
$ACQUIRED_SEM = ['ADM', 'ACQ'];

$yearData = $_SESSION['year'] ?? null;
$semData = $_SESSION['sem'] ?? null;
$iaData = $_SESSION['ia'] ?? null;
$courseConfig = $_SESSION['course_config'] ?? null;
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$uploadErrors = [];

if (isset($_GET['sem'])) {
    $selectedSem = (int)$_GET['sem'];
    $_SESSION['selected_sem'] = $selectedSem;
} else {
    $selectedSem = isset($_SESSION['selected_sem']) ? (int)$_SESSION['selected_sem'] : null;
}

// ---------- helpers ----------
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function dist(array $vals): array
{
    $d = [];
    foreach ($vals as $v) {
        $k = ($v === null || $v === '') ? '(vide)' : (string)$v;
        $d[$k] = ($d[$k] ?? 0) + 1;
    }
    ksort($d);
    return $d;
}

function distHtml(array $vals): string
{
    $d = dist($vals);
    $parts = [];
    foreach ($d as $k => $n) {
        $parts[] = e($k) . ' <b>' . $n . '</b>';
    }
    return implode(' &nbsp;·&nbsp; ', $parts);
}

function examTags(string $label): array
{
    $up = strtoupper($label);
    $tags = [];
    if (preg_match('/\bTD\b/i', $up) === 1) {
        $tags[] = 'TD';
    }
    if (preg_match('/TP/i', $up) === 1) {
        $tags[] = 'TP';
    }
    return $tags;
}

function examShortLabel(string $label): string
{
    if (preg_match('/^EPR\s+\S+\s{2,}(.+)$/s', $label, $m)) {
        return $m[1];
    }
    if (preg_match('/^EPR\s+\S+\s+(.+)$/s', $label, $m)) {
        return $m[1];
    }
    return $label;
}

function courseDefaults(array $course): array
{
    $exams = [];
    $hasTdtP = false;
    foreach ($course['exams'] as $label) {
        $keep = count(examTags($label)) > 0;
        $exams[$label] = $keep;
        if ($keep) {
            $hasTdtP = true;
        }
    }
    return [
        'acronym' => '',
        'threshold' => null,
        'display' => $hasTdtP,
        'exams' => $exams,
    ];
}

// Sélection des potentiels redoublants : statut annuel ∈ {AJ,DEF,DIS} (repli : statut du semestre).
function selectPotential(array $yearData, int $selectedSem, array $reDoube, array $acquired): array
{
    $list = [];
    foreach ($yearData as $f) {
        $hasYear = $f['data']['has_year_status'];
        foreach ($f['data']['students'] as $s) {
            $ss = $s['semester_status'][(string)$selectedSem] ?? '';
            $isPotential = $hasYear
                ? in_array($s['year_status'], $reDoube, true)
                : in_array($ss, $reDoube, true);
            if (!$isPotential) {
                continue;
            }
            $list[] = [
                'numero' => $s['numero'],
                'nom' => $s['nom'],
                'prenom' => $s['prenom'],
                'parcours' => $f['data']['parcours'],
                'year_status' => $s['year_status'],
                'sem_status' => $ss,
                'acq' => in_array($ss, $acquired, true),
            ];
        }
    }
    return $list;
}

// ---------- gestion des actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'clear') {
        unset($_SESSION['year'], $_SESSION['sem'], $_SESSION['selected_sem'], $_SESSION['course_config'], $_SESSION['ia']);
        $yearData = null;
        $semData = null;
        $courseConfig = null;
        $iaData = null;
        $selectedSem = null;
        $flash = 'Session vidée.';
    }

    if ($action === 'year') {
        if (!isset($_SESSION['year'])) {
            $_SESSION['year'] = [];
        }
        $files = $_FILES['year_files'] ?? [];
        $count = is_array($files['name'] ?? null) ? count($files['name']) : 1;
        $added = 0;
        for ($i = 0; $i < $count; $i++) {
            $name = $files['name'][$i] ?? '';
            if (trim($name) === '') {
                continue;
            }
            $tmp = $files['tmp_name'][$i] ?? '';
            $err = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if ($err !== UPLOAD_ERR_OK) {
                $uploadErrors[] = "$name : erreur d'upload (code $err).";
                continue;
            }
            try {
                $p = new ApofileYearParser($tmp, $name);
                $_SESSION['year'][] = ['filename' => $name, 'warnings' => $p->warnings, 'data' => $p->toArray()];
                $added++;
            } catch (\Throwable $ex) {
                $uploadErrors[] = "$name : " . $ex->getMessage();
            }
        }
        $yearData = $_SESSION['year'];
        $flash = $added . " PV d'année ajouté(s) — total : " . count($yearData) . " — ; " . count($uploadErrors) . " erreur(s).";
    }

    if ($action === 'sem') {
        if (!isset($_SESSION['sem'])) {
            $_SESSION['sem'] = [];
        }
        $files = $_FILES['sem_files'] ?? [];
        $count = is_array($files['name'] ?? null) ? count($files['name']) : 1;
        $added = 0;
        for ($i = 0; $i < $count; $i++) {
            $name = $files['name'][$i] ?? '';
            if (trim($name) === '') {
                continue;
            }
            $tmp = $files['tmp_name'][$i] ?? '';
            $err = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if ($err !== UPLOAD_ERR_OK) {
                $uploadErrors[] = "$name : erreur d'upload (code $err).";
                continue;
            }
            try {
                $p = new ApofileSemesterParser($tmp, $name);
                $_SESSION['sem'][] = ['filename' => $name, 'warnings' => $p->warnings, 'data' => $p->toArray()];
                $added++;
            } catch (\Throwable $ex) {
                $uploadErrors[] = "$name : " . $ex->getMessage();
            }
        }
        $yearData = $_SESSION['year'] ?? null;
        $semData = $_SESSION['sem'];
        unset($_SESSION['course_config']);
        $courseConfig = null;
        $flash = $added . " PV de semestre ajouté(s) — total : " . count($semData) . " — ; " . count($uploadErrors) . " erreur(s).";
    }

    if ($action === 'ia') {
        if (!isset($_SESSION['ia'])) {
            $_SESSION['ia'] = ['students' => [], 'warnings' => []];
        }
        $files = $_FILES['ia_files'] ?? [];
        $count = is_array($files['name'] ?? null) ? count($files['name']) : 1;
        $added = 0;
        for ($i = 0; $i < $count; $i++) {
            $name = $files['name'][$i] ?? '';
            if (trim($name) === '') {
                continue;
            }
            $tmp = $files['tmp_name'][$i] ?? '';
            $err = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if ($err !== UPLOAD_ERR_OK) {
                $uploadErrors[] = "$name : erreur d'upload (code $err).";
                continue;
            }
            try {
                $p = new IaListParser($tmp);
                $students = $p->toArray()['students'];
                $existing = [];
                foreach ($_SESSION['ia']['students'] as $s) {
                    $existing[$s['id']] = true;
                }
                foreach ($students as $s) {
                    if (!isset($existing[$s['id']])) {
                        $_SESSION['ia']['students'][] = $s;
                        $existing[$s['id']] = true;
                    }
                }
                foreach ($p->warnings as $w) {
                    $_SESSION['ia']['warnings'][] = $name . ' : ' . $w;
                }
                $added++;
            } catch (\Throwable $ex) {
                $uploadErrors[] = "$name : " . $ex->getMessage();
            }
        }
        $iaData = $_SESSION['ia'];
        $flash = $added . " fichier IA ajouté(s) — total : " . count($iaData['students']) . " inscrit(s) — ; " . count($uploadErrors) . " erreur(s).";
    }

    if ($action === 'config') {
        if ($semData) {
            $courses = (new MatterMatcher())->match($semData);
            $config = [];
            $nShown = 0;
            foreach ($courses as $i => $course) {
                $acronym = trim((string)($_POST['acronym'][$i] ?? ''));
                $rawTh = (string)($_POST['threshold'][$i] ?? '');
                $threshold = (trim($rawTh) === '' || !is_numeric($rawTh)) ? null : (float)$rawTh;
                $display = isset($_POST['display'][$i]);
                $exams = [];
                foreach ($course['exams'] as $j => $label) {
                    $exams[$label] = isset($_POST['exam'][$i][$j]);
                }
                $config[$i] = [
                    'key' => $course['key'],
                    'name' => $course['name'],
                    'acronym' => $acronym,
                    'threshold' => $threshold,
                    'display' => $display,
                    'exams' => $exams,
                ];
                if ($display) {
                    $nShown++;
                }
            }
            $_SESSION['course_config'] = $config;
            $courseConfig = $config;
            $flash = "Configuration enregistrée : " . count($config) . " matières, dont " . $nShown . " affichées dans l'export.";
        }
    }

    if ($action === 'export') {
        if ($yearData && $semData && $selectedSem !== null && $courseConfig) {
            $redoublants = selectPotential($yearData, $selectedSem, $REDOUB_STATUSES, $ACQUIRED_SEM);
            $matcher = new MatterMatcher();
            $courses = $matcher->match($semData);
            $effective = $matcher->mergeByAcronym($courses, $courseConfig);
            $xlsx = (new ExcelExporter())->export($redoublants, $effective, $courses, $semData, $selectedSem);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="redoublants_tdtp_S' . $selectedSem . '.xlsx"');
            header('Content-Length: ' . strlen($xlsx));
            echo $xlsx;
            exit;
        }
    }

    if ($action === 'export-ia') {
        if ($yearData && $semData && $selectedSem !== null && $courseConfig && $iaData) {
            $redoublants = selectPotential($yearData, $selectedSem, $REDOUB_STATUSES, $ACQUIRED_SEM);
            $matcher = new MatterMatcher();
            $courses = $matcher->match($semData);
            $effective = $matcher->mergeByAcronym($courses, $courseConfig);
            $xlsx = (new ExcelExporter())->exportIa($iaData['students'], $redoublants, $effective, $courses, $semData);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="redoublants_inscrits_S' . $selectedSem . '.xlsx"');
            header('Content-Length: ' . strlen($xlsx));
            echo $xlsx;
            exit;
        }
    }
}

$courses = null;
if ($semData) {
    $courses = (new MatterMatcher())->match($semData);
}

$re = null;
if ($yearData && $selectedSem !== null) {
    $re = selectPotential($yearData, $selectedSem, $REDOUB_STATUSES, $ACQUIRED_SEM);
}
$nFollow = $re ? count(array_filter($re, fn($s) => !$s['acq'])) : 0;

// Croisement IA x potentiels redoublants
$enrolledRedoublants = null;
$notEnrolled = null;
if ($re !== null && $iaData) {
    $iaIds = [];
    foreach ($iaData['students'] as $ia) {
        $iaIds[$ia['id']] = true;
    }
    $enrolledRedoublants = 0;
    foreach ($re as $r) {
        if (isset($iaIds[$r['numero']])) {
            $enrolledRedoublants++;
        }
    }
    $notEnrolled = count($re) - $enrolledRedoublants;
}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Redoublants TD/TP — validation</title>
<style>
  body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; margin: 24px; color:#1b1b1b; }
  h1 { font-size: 22px; }
  h2 { font-size: 17px; margin-top: 28px; border-bottom: 1px solid #ddd; padding-bottom: 4px; }
  .card { border: 1px solid #e2e2e2; border-radius: 8px; padding: 14px 16px; margin: 12px 0; background:#fbfbfa; }
  table { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 13px; }
  th, td { border: 1px solid #e0e0e0; padding: 5px 8px; text-align: left; vertical-align: top; }
  th { background: #f4f4f2; }
  .warn { color: #b00; font-size: 13px; }
  .ok { color: #1a7a1a; font-size: 13px; }
  .muted { color:#888; font-size:12px; }
  .flash { background: #eef6ff; border: 1px solid #cfe3ff; padding: 8px 12px; border-radius: 6px; }
  .badge-shared { background:#e9f7ec; color:#1a7a1a; border:1px solid #bfe6c8; border-radius:10px; padding:1px 7px; font-size:11px; }
  .cell-acq { background:#c6efce; font-weight:600; }
  label { font-size: 13px; }
  button { background:#1a4fb0; color:#fff; border:0; border-radius:6px; padding:8px 14px; font-size:13px; cursor:pointer; }
  button.ghost { background:#e6e6e4; color:#333; }
  button.small { padding:2px 8px; font-size:12px; }
  .num { text-align:right; font-variant-numeric: tabular-nums; }
  .dropzone { border:2px dashed #b8c6dd; border-radius:8px; padding:18px; background:#f6f9fd; text-align:center; margin:8px 0; }
  .dropzone.over { border-color:#1a4fb0; background:#eaf1fb; }
  .dropzone p { margin:0 0 10px; font-size:13px; }
  .filelist { list-style:none; padding:0; margin:8px 0; }
  .filelist li { border:1px solid #e0e0e0; border-radius:6px; background:#fff; padding:5px 10px; margin:4px 0; font-size:13px; display:flex; justify-content:space-between; align-items:center; }
  .filelist li.empty { color:#999; border-style:dashed; background:transparent; }
  table.cfg { width:100%; border-collapse:collapse; font-size:12.5px; margin:10px 0; }
  table.cfg th, table.cfg td { border:1px solid #e3e3e0; padding:3px 6px; vertical-align:middle; text-align:left; }
  table.cfg thead th { background:#f0f0ee; position:sticky; top:0; z-index:2; }
  table.cfg td.center { text-align:center; }
  table.cfg tr.shown { background:#f6faff; }
  table.cfg .matter { font-weight:600; }
  table.cfg .sub { display:block; color:#999; font-size:11px; margin-top:1px; }
  table.cfg input[type=text] { width:92px; font-size:12.5px; padding:2px 4px; }
  table.cfg input[type=number] { width:56px; font-size:12.5px; padding:2px 4px; }
  table.cfg input[type=checkbox] { width:14px; height:14px; margin:0; vertical-align:middle; }
  .exam-pills { display:flex; flex-wrap:wrap; gap:4px 8px; }
  .exam-pill { display:inline-flex; align-items:center; gap:4px; border:1px solid #ececec; border-radius:10px; padding:1px 6px; background:#fff; }
  .exam-pill .lbl { font-size:11.5px; color:#333; }
  table.sortable th { cursor: pointer; user-select: none; }
  table.sortable th:hover { background: #e8e8e5; }
  table.sortable th .sort-icon { font-size: 10px; margin-left: 3px; color: #666; }
  .redoublants-wrap { overflow-x: auto; margin-top: 6px; }
  .redoublants-wrap.collapsed table tbody { display: none; }
  .collapse-btn {
    display: inline-flex; align-items: center; gap: 4px;
    background: #eef3fa; color: #1a4fb0; border: 1px solid #c4d8fb;
    border-radius: 6px; padding: 3px 10px; font-size: 12.5px;
    cursor: pointer; margin-left: 10px;
  }
  .collapse-btn:hover { background: #e0e9f5; }
</style>
</head>
<body>
<h1>Redoublants TD/TP — validation (Lot 1 à 4)</h1>

<?php if ($flash): ?><div class="flash"><?= e($flash) ?></div><?php endif; ?>
<?php if (!empty($uploadErrors)): ?>
  <div class="card"><div class="warn">Erreurs d'upload :</div>
    <ul><?php foreach ($uploadErrors as $er): ?><li class="warn"><?= e($er) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<!-- ================= ÉTAPE 1 : PV ANNÉE ================= -->
<h2>Étape 1 — PV de l'année (tous parcours, accumulables)</h2>
<div class="card">
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="action" value="year">
    <div class="dropzone" id="dz-year">
      <p>Glisse-dépose ici les PV d'année (un par parcours), ou clique pour les choisir. Tu peux en ajouter en plusieurs fois avant de soumettre.</p>
      <input type="file" name="year_files[]" id="fi-year" multiple accept=".xlsx,.xls">
    </div>
    <ul class="filelist" id="fl-year"></ul>
    <button type="submit" id="sb-year">Ajouter les PV d'année</button>
  </form>
</div>

<?php if ($yearData): ?>
  <?php foreach ($yearData as $f): $d = $f['data']; ?>
    <div class="card">
      <b><?= e($f['filename']) ?></b> — parcours : <b><?= e($d['parcours']) ?></b>
      <?php if (!$d['has_year_status']): ?> <span class="warn">(statut annuel absent)</span><?php endif; ?>
      <div style="margin-top:6px">
        Étudiants : <b><?= count($d['students']) ?></b> &nbsp;·&nbsp;
        Statut annuel : <?= distHtml(array_column($d['students'], 'year_status')) ?>
      </div>
      <?php if (!empty($f['warnings'])): ?>
        <div class="warn"><?= e(implode(' | ', $f['warnings'])) ?></div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <div class="card">
    <b>Semestres détectés</b> — choisissez le semestre à travailler :<br>
    <?php
      $allSem = [];
      foreach ($yearData as $f) { $allSem = array_merge($allSem, $f['data']['sem_numbers']); }
      $allSem = array_values(array_unique($allSem)); sort($allSem);
    ?>
    <?php foreach ($allSem as $sn): ?>
      <a href="?sem=<?= $sn ?>"><button type="button" class="<?= $selectedSem === (int)$sn ? '' : 'ghost' ?>">Semestre <?= $sn ?></button></a>
    <?php endforeach; ?>
  </div>

  <?php if ($re !== null): ?>
    <div class="card">
      <b>Potentiels redoublants — Semestre <?= $selectedSem ?></b>
      <span class="ok"><?= count($re) ?> étudiant(s) (statut annuel ∈ {AJ, DEF, DIS}) — dont <b><?= $nFollow ?></b> suivent ce semestre, les <?= count($re) - $nFollow ?> autres l'ont acquis (ACQ)</span>
      <button type="button" class="collapse-btn" id="collapse-redoublants" data-count="<?= count($re) ?>">▲ Réduire</button>

      <div class="redoublants-wrap" id="redoublants-wrap">
        <table class="sortable" id="tbl-redoublants">
          <thead>
            <tr>
              <th>N°</th>
              <th>Nom</th>
              <th>Prénom</th>
              <th>Parcours</th>
              <th>Semestre</th>
              <th>Statut année</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($re as $s): ?>
            <tr>
              <td class="num"><?= $s['numero'] ?></td>
              <td><?= e($s['nom']) ?></td>
              <td><?= e($s['prenom']) ?></td>
              <td><?= e($s['parcours']) ?></td>
              <td<?= $s['acq'] ? ' class="cell-acq"' : '' ?>><?= $s['acq'] ? 'ACQ' : e($s['sem_status']) ?></td>
              <td><?= e($s['year_status']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<!-- ================= ÉTAPE 2 : PV SEMESTRE ================= -->
<h2>Étape 2 — PV du semestre choisi (tous parcours, accumulables)</h2>
<div class="card">
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="action" value="sem">
    <div class="dropzone" id="dz-sem">
      <p>Glisse-dépose ici les PV du semestre (un par parcours), ou clique pour les choisir. Tu peux en ajouter en plusieurs fois avant de soumettre.</p>
      <input type="file" name="sem_files[]" id="fi-sem" multiple accept=".xlsx,.xls">
    </div>
    <ul class="filelist" id="fl-sem"></ul>
    <button type="submit" id="sb-sem">Ajouter les PV de semestre</button>
  </form>
</div>

<?php if ($semData): ?>
  <?php foreach ($semData as $f): $d = $f['data']; ?>
    <div class="card">
      <b><?= e($f['filename']) ?></b> — parcours : <b><?= e($d['parcours']) ?></b>
      <?php if ($d['semester_number'] !== null): ?> — Semestre <b><?= $d['semester_number'] ?></b><?php endif; ?>
      <?php if ($selectedSem !== null && $d['semester_number'] !== null && $d['semester_number'] !== $selectedSem): ?>
        <span class="warn"> (attention : ce PV est le semestre <?= $d['semester_number'] ?>, pas le <?= $selectedSem ?> sélectionné)</span>
      <?php endif; ?>
      <div style="margin-top:6px">
        Étudiants : <b><?= count($d['students']) ?></b> &nbsp;·&nbsp;
        Statut semestre : <?= distHtml(array_column($d['students'], 'sem_status')) ?>
      </div>
      <?php if (!empty($f['warnings'])): ?>
        <div class="warn"><?= e(implode(' | ', $f['warnings'])) ?></div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <!-- ============ ÉTAPE 3 : CONFIG MATIÈRES ============ -->
  <?php if ($courses): ?>
  <h2>Étape 3 — Configuration des matières (<?= count($courses) ?> groupes détectés)</h2>
  <div class="card">
    <p class="muted">Une ligne par groupe de matière (regroupés entre parcours). Coche <b>Affichée ?</b> pour inclure la matière dans le tableau exporté (avec la mention ACQ/NACQ/DEF/DIS, ACQ en surbrillance).
    Pour chaque épreuve, coche si sa <b>note doit être conservée</b> : elle apparaîtra alors dans une colonne, surlignée si elle dépasse le <b>seuil /20</b>.
    Les cases épreuves sont pré-cochées selon le libellé (ex. « TP/TD ») : ajuste.</p>
    <p class="muted"><b>Astuce — fusion :</b> donne le <b>même acronyme</b> à deux groupes pour les traiter comme une seule matière. Un acronyme = une matière.</p>

    <form method="post">
      <input type="hidden" name="action" value="config">
      <table class="cfg">
        <thead>
          <tr>
            <th style="width:110px">Acronyme</th>
            <th>Matière</th>
            <th style="width:70px" class="center">Affichée ?</th>
            <th style="width:70px">Seuil/20</th>
            <th>Épreuves → note à conserver</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($courses as $i => $course): $cfg = $courseConfig[$i] ?? courseDefaults($course); $isShown = !empty($cfg['display']); ?>
          <tr class="<?= $isShown ? 'shown' : '' ?>">
            <td><input type="text" name="acronym[<?= $i ?>]" value="<?= e($cfg['acronym']) ?>" maxlength="12"></td>
            <td>
              <span class="matter"><?= e($course['name']) ?></span>
              <span class="sub">
                <?= e(implode(', ', $course['codes'])) ?>
                <?php if ($course['shared']): ?> · <span class="badge-shared">partagée : <?= e(implode(', ', $course['parcours'])) ?></span>
                <?php else: ?> · spécifique : <?= e(implode(', ', $course['parcours'])) ?><?php endif; ?>
              </span>
            </td>
            <td class="center"><input type="checkbox" name="display[<?= $i ?>]" <?= $isShown ? 'checked' : '' ?>></td>
            <td><input type="number" step="0.25" min="0" max="20" name="threshold[<?= $i ?>]" value="<?= $cfg['threshold'] === null ? '' : e((string)$cfg['threshold']) ?>"></td>
            <td>
              <?php if (!empty($course['exams'])): ?>
                <div class="exam-pills">
                <?php foreach ($course['exams'] as $j => $label): $keep = !empty($cfg['exams'][$label]); ?>
                  <span class="exam-pill">
                    <input type="checkbox" name="exam[<?= $i ?>][<?= $j ?>]" <?= $keep ? 'checked' : '' ?>>
                    <span class="lbl"><?= e(examShortLabel($label)) ?></span>
                  </span>
                <?php endforeach; ?>
                </div>
              <?php else: ?>
                <span class="muted">(aucune épreuve — mention seule)</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div style="margin-top:10px"><button type="submit">Enregistrer la configuration</button></div>
    </form>
  </div>

  <?php if ($courseConfig): ?>
  <?php
    $effective = (new MatterMatcher())->mergeByAcronym($courses, $courseConfig);
    $nShown = 0;
  ?>
  <div class="card">
    <b>Récap — matières affichées dans l'export</b>
    <table>
      <thead><tr><th>Acronyme</th><th>Matière</th><th>Affichée</th><th>Seuil</th><th>Notes conservées</th><th>Statut</th></tr></thead>
      <tbody>
      <?php
        $nOk = 0;
        $anyMerged = false;
        foreach ($effective as $ev) {
          if (empty($ev['display'])) {
            continue;
          }
          $nShown++;
          $kept = [];
          foreach ($ev['exams_selected'] as $label => $sel) {
            if ($sel) {
              $kept[] = examShortLabel($label);
            }
          }
          $anyKept = count($kept) > 0;
          $issues = [];
          if ($anyKept && $ev['threshold'] === null) {
              $issues[] = 'seuil manquant';
          }
          if (empty($issues)) {
            $nOk++;
          }
          if ($ev['merged'] > 1) {
            $anyMerged = true;
          }
      ?>
        <tr>
          <td><b><?= e($ev['acronym'] !== '' ? $ev['acronym'] : '—') ?></b></td>
          <td><?= e($ev['name']) ?><?php if ($ev['merged'] > 1): ?> <span class="badge-shared">fusion de <?= $ev['merged'] ?> groupes</span><?php endif; ?></td>
          <td>oui</td>
          <td class="num"><?= $ev['threshold'] === null ? '—' : e((string)$ev['threshold']) ?></td>
          <td class="muted"><?= $anyKept ? e(implode(', ', $kept)) : '— (mention uniquement)' ?></td>
          <td><?= $issues ? '<span class="warn">' . e(implode(' ; ', $issues)) . '</span>' : '<span class="ok">OK</span>' ?></td>
        </tr>
      <?php } ?>
      </tbody>
    </table>
    <div class="ok"><?= $nOk ?> matière(s) affichée(s) sans problème.<?= $anyMerged ? ' Groupes fusionnés par acronyme.' : '' ?></div>

    <div style="margin-top:14px">
      <?php if ($nShown > 0 && $yearData && $selectedSem !== null): ?>
        <form method="post" id="form-export">
          <input type="hidden" name="action" value="export">
          <button type="submit">⬇ Exporter l'Excel des redoublants potentiels (Semestre <?= $selectedSem ?>)</button>
        </form>
      <?php else: ?>
        <span class="muted">Aucune matière sélectionnée pour l'export.</span>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>

<!-- ================= ÉTAPE 4 : IA + EXPORT INSCRITS ================= -->
<h2>Étape 4 — Inscriptions (IA) de la nouvelle année + export des redoublants inscrits</h2>
<div class="card">
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="action" value="ia">
    <div class="dropzone" id="dz-ia">
      <p>Glisse-dépose ici le fichier des inscriptions administratives (IA) de la nouvelle année, ou clique pour le choisir.</p>
      <input type="file" name="ia_files[]" id="fi-ia" multiple accept=".xlsx,.xls">
    </div>
    <ul class="filelist" id="fl-ia"></ul>
    <button type="submit" id="sb-ia">Ajouter le fichier IA</button>
  </form>
</div>

<?php if ($iaData): ?>
  <div class="card">
    <b>Liste d'inscriptions (IA)</b> — <?= count($iaData['students']) ?> étudiant(s) inscrit(s) la nouvelle année
    <?php if (!empty($iaData['warnings'])): ?>
      <div class="warn"><?= e(implode(' | ', $iaData['warnings'])) ?></div>
    <?php endif; ?>
    <?php if ($re !== null): ?>
      <div style="margin-top:8px">
        <b>Croisement avec les potentiels redoublants :</b>
        <span class="ok"><?= $enrolledRedoublants ?> redoublant(s) effectivement inscrit(s)</span>
        &nbsp;·&nbsp; <span class="warn"><?= $notEnrolled ?> potentiel(s) redoublant(s) non réinscrit(s) → exclus de l'export</span>
      </div>
      <?php if ($courseConfig && $selectedSem !== null): ?>
        <div style="margin-top:14px">
          <form method="post">
            <input type="hidden" name="action" value="export-ia">
            <button type="submit">⬇ Exporter l'Excel des inscriptions (redoublants inscrits, Semestre <?= $selectedSem ?>)</button>
          </form>
        </div>
      <?php else: ?>
        <div class="muted">Renseigne la config des matières (Étape 3) et choisis un semestre (Étape 1) pour activer l'export.</div>
      <?php endif; ?>
    <?php else: ?>
      <div class="muted">Charge d'abord les PV d'année et choisis un semestre (Étape 1) pour croiser avec les redoublants.</div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="card">
  <form method="post"><input type="hidden" name="action" value="clear">
    <button class="ghost" type="submit">Réinitialiser (vider la session)</button>
  </form>
</div>

<script>
(function(){
  function setupDropzone(zoneSel, inputSel, listSel, btnSel){
    var zone = document.querySelector(zoneSel);
    var input = document.querySelector(inputSel);
    var list = document.querySelector(listSel);
    var btn = document.querySelector(btnSel);
    if(!zone || !input || !list) return;
    var files = [];
    function key(f){ return f.name + '|' + f.size + '|' + f.lastModified; }
    function addFiles(incoming){
      incoming.forEach(function(f){
        if(!/\.(xlsx|xls)$/i.test(f.name)) return;
        if(files.some(function(x){ return key(x) === key(f); })) return;
        files.push(f);
      });
      render();
    }
    function render(){
      list.innerHTML='';
      if(!files.length){ list.innerHTML='<li class="empty">Aucun fichier en attente</li>'; return; }
      files.forEach(function(f, idx){
        var li = document.createElement('li');
        var span = document.createElement('span');
        span.textContent = f.name;
        var b = document.createElement('button');
        b.type='button'; b.className='ghost small'; b.textContent='✕';
        b.onclick = function(){ files.splice(idx,1); render(); };
        li.appendChild(span); li.appendChild(b);
        list.appendChild(li);
      });
    }
    zone.addEventListener('dragover', function(e){ e.preventDefault(); zone.classList.add('over'); });
    zone.addEventListener('dragleave', function(){ zone.classList.remove('over'); });
    zone.addEventListener('drop', function(e){
      e.preventDefault(); zone.classList.remove('over');
      if(e.dataTransfer && e.dataTransfer.files){ addFiles(Array.from(e.dataTransfer.files)); }
    });
    input.addEventListener('change', function(){
      addFiles(Array.from(input.files));
      input.value='';
    });
    if(btn){
      btn.addEventListener('click', function(e){
        if(files.length === 0){
          e.preventDefault();
          alert('Ajoute au moins un fichier (glisser-déposer ou sélection) avant de soumettre.');
          return;
        }
        var dt = new DataTransfer();
        files.forEach(function(f){ dt.items.add(f); });
        input.files = dt.files;
      });
    }
    render();
  }

  document.addEventListener('DOMContentLoaded', function(){
    setupDropzone('#dz-year', '#fi-year', '#fl-year', '#sb-year');
    setupDropzone('#dz-sem', '#fi-sem', '#fl-sem', '#sb-sem');
    setupDropzone('#dz-ia', '#fi-ia', '#fl-ia', '#sb-ia');

    var tbl = document.querySelector('#tbl-redoublants');
    if (tbl) {
      var headers = tbl.querySelectorAll('thead th');
      var tbody = tbl.querySelector('tbody');
      headers.forEach(function(th, colIdx) {
        th.addEventListener('click', function() {
          var rows = Array.from(tbody.querySelectorAll('tr'));
          var dir = (th.dataset.dir === 'asc') ? 'desc' : 'asc';
          headers.forEach(function(h) {
            delete h.dataset.dir;
            var oldIcon = h.querySelector('.sort-icon');
            if (oldIcon) oldIcon.remove();
          });
          th.dataset.dir = dir;
          var icon = document.createElement('span');
          icon.className = 'sort-icon';
          icon.textContent = (dir === 'asc') ? '\u25B2' : '\u25BC';
          th.appendChild(icon);
          rows.sort(function(a, b) {
            var aVal = a.cells[colIdx].textContent.trim();
            var bVal = b.cells[colIdx].textContent.trim();
            if (aVal !== '' && bVal !== '' && !isNaN(aVal) && !isNaN(bVal)) {
              return dir === 'asc' ? (aVal - bVal) : (bVal - aVal);
            }
            return dir === 'asc' ? aVal.localeCompare(bVal, 'fr') : bVal.localeCompare(aVal, 'fr');
          });
          rows.forEach(function(r) { tbody.appendChild(r); });
        });
      });
    }

    var cBtn = document.querySelector('#collapse-redoublants');
    var cWrap = document.querySelector('#redoublants-wrap');
    if (cBtn && cWrap) {
      cBtn.addEventListener('click', function() {
        cWrap.classList.toggle('collapsed');
        var collapsed = cWrap.classList.contains('collapsed');
        var count = cBtn.dataset.count || '?';
        cBtn.textContent = collapsed ? '\u25BC D\u00e9plier (' + count + ' \u00e9tudiants)' : '\u25B0 R\u00e9duire';
      });
    }
  });
})();
</script>
</body>
</html>