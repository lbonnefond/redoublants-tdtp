<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use LBonnefond\RedoublantsTdTp\ApofileYearParser;
use LBonnefond\RedoublantsTdTp\ApofileSemesterParser;

session_start();

$REDOUB_STATUSES = ['AJ', 'DEF', 'DIS'];

$yearData = $_SESSION['year'] ?? null;
$semData = $_SESSION['sem'] ?? null;
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$uploadErrors = [];

if (isset($_GET['sem'])) {
    $selectedSem = (int)$_GET['sem'];
    $_SESSION['selected_sem'] = $selectedSem;
} else {
    $selectedSem = isset($_SESSION['selected_sem']) ? (int)$_SESSION['selected_sem'] : null;
}

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

function examKind(string $label): string
{
    $up = strtoupper($label);
    $tp = preg_match('/TP/i', $up) === 1;
    $td = preg_match('/\bTD\b/i', $up) === 1;
    $tags = [];
    if ($td) {
        $tags[] = 'TD';
    }
    if ($tp) {
        $tags[] = 'TP';
    }
    return implode('/', $tags);
}

function kindBadge(string $label): string
{
    $k = examKind($label);
    if ($k === '') {
        return '';
    }
    return ' <span class="badge-' . str_replace('/', '-', $k) . '">' . e($k) . '</span>';
}

// ---------- gestion des uploads (accumulables) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'clear') {
        unset($_SESSION['year'], $_SESSION['sem'], $_SESSION['selected_sem']);
        $yearData = null;
        $semData = null;
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
                $_SESSION['year'][] = [
                    'filename' => $name,
                    'warnings' => $p->warnings,
                    'data' => $p->toArray(),
                ];
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
                $_SESSION['sem'][] = [
                    'filename' => $name,
                    'warnings' => $p->warnings,
                    'data' => $p->toArray(),
                ];
                $added++;
            } catch (\Throwable $ex) {
                $uploadErrors[] = "$name : " . $ex->getMessage();
            }
        }
        $semData = $_SESSION['sem'];
        $flash = $added . " PV de semestre ajouté(s) — total : " . count($semData) . " — ; " . count($uploadErrors) . " erreur(s).";
    }
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
  .flash { background: #eef6ff; border: 1px solid #cfe3ff; padding: 8px 12px; border-radius: 6px; }
  .badge-TD { background:#e8f0fe; color:#1a4fb0; border:1px solid #c4d8fb; border-radius:10px; padding:1px 7px; font-size:11px; }
  .badge-TP { background:#fdf0e6; color:#a04e12; border:1px solid #f3d3b6; border-radius:10px; padding:1px 7px; font-size:11px; }
  .badge-TD-TP { background:#efe6fd; color:#5a2fa0; border:1px solid #d6c4fb; border-radius:10px; padding:1px 7px; font-size:11px; }
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
</style>
</head>
<body>
<h1>Redoublants TD/TP — étape de validation (Lot 1)</h1>

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

  <?php if ($selectedSem !== null): ?>
    <?php
      $re = [];
      foreach ($yearData as $f) {
        $hasYear = $f['data']['has_year_status'];
        foreach ($f['data']['students'] as $s) {
          $ss = $s['semester_status'][(string)$selectedSem] ?? '';
          if (!in_array($ss, $REDOUB_STATUSES, true)) { continue; }
          if ($hasYear && !in_array($s['year_status'], $REDOUB_STATUSES, true)) { continue; }
          $re[] = $s + ['parcours' => $f['data']['parcours']];
        }
      }
    ?>
    <div class="card">
      <b>Redoublants potentiels — Semestre <?= $selectedSem ?></b>
      <span class="ok">(statut sem ∈ {AJ, DEF, DIS}<?= $yearData[0]['data']['has_year_status'] ? ' ET statut annuel ∈ {AJ, DEF, DIS}' : '' ?>) : <b><?= count($re) ?></b> étudiant(s)</span>
      <table>
        <thead><tr><th>N°</th><th>Nom</th><th>Prénom</th><th>Parcours</th><th>Statut année</th><th>Statut sem <?= $selectedSem ?></th></tr></thead>
        <tbody>
        <?php foreach ($re as $s): ?>
          <tr>
            <td class="num"><?= $s['numero'] ?></td>
            <td><?= e($s['nom']) ?></td>
            <td><?= e($s['prenom']) ?></td>
            <td><?= e($s['parcours']) ?></td>
            <td><?= e($s['year_status']) ?></td>
            <td><?= e($s['semester_status'][(string)$selectedSem] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
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

      <div style="margin-top:10px"><b>UEs détectées (<?= count($d['ues']) ?>)</b> — les épreuves sont pré-repérées TD/TP, à confirmer en Lot 2 :</div>
      <table>
        <thead><tr><th>Code</th><th>Matière</th><th>Épreuves</th></tr></thead>
        <tbody>
        <?php foreach ($d['ues'] as $ue): ?>
          <tr>
            <td><code><?= e($ue['code']) ?></code></td>
            <td><?= e($ue['name']) ?></td>
            <td>
              <?php
                if (empty($ue['exams'])) { echo '<i>(aucune épreuve)</i>'; }
                foreach ($ue['exams'] as $ex) {
                  echo e($ex) . kindBadge($ex) . ' &nbsp;';
                }
              ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <?php
        $sample = null;
        foreach ($d['students'] as $s) { if (in_array($s['sem_status'], $REDOUB_STATUSES, true)) { $sample = $s; break; } }
        if ($sample === null && $d['students']) { $sample = $d['students'][0]; }
      ?>
      <?php if ($sample): ?>
        <div style="margin-top:10px">
          <b>Échantillon — <?= e($sample['nom']) ?> <?= e($sample['prenom']) ?> (N° <?= $sample['numero'] ?>, statut sem <?= e($sample['sem_status']) ?>)</b> :
          <table>
            <thead><tr><th>UE</th><th>Note</th><th>Mention</th><th>An/Session</th><th>Épreuves (notes)</th></tr></thead>
            <tbody>
            <?php foreach ($d['ues'] as $ue): $u = $sample['ues'][$ue['code']] ?? null; if (!$u) continue; ?>
              <tr>
                <td><?= e($ue['code']) ?></td>
                <td class="num"><?= e($u['note']) ?></td>
                <td><?= e($u['mention']) ?></td>
                <td><?= e($u['anSession']) ?></td>
                <td>
                  <?php
                  if (empty($ue['exams'])) { echo '<i>(aucune)</i>'; }
                  foreach ($ue['exams'] as $ex): $note = $u['exams'][$ex] ?? ''; ?>
                    <?= e($ex) ?> = <b><?= e($note) ?></b><?= kindBadge($ex) ?><br>
                  <?php endforeach; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
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
  });
})();
</script>
</body>
</html>