<?php
/**
 * views/ops_maint.php
 * Mantenimiento (Backups y logs).
 */

// Listar backups
$backupFiles = [];
if (is_dir($BACKUP_DIR)) {
  $backupFiles = glob(rtrim($BACKUP_DIR, "/\\") . DIRECTORY_SEPARATOR . "crm_backup_*.sql");
  if ($backupFiles === false) $backupFiles = [];
  usort($backupFiles, function($a,$b){ return filemtime($b) <=> filemtime($a); });
}

// Logs mantenimiento (últimos 30)
$mntLogs = [];
$qr = $c->query("
  SELECT accion, archivo, bytes, estado, detalle, admin_user, created_at
  FROM crm_mantenimiento_log
  ORDER BY created_at DESC
  LIMIT 30
");
while($qr && ($row = $qr->fetch_assoc())) $mntLogs[] = $row;
?>

<div class="card">
  <div class="row-between">
    <div class="section-title">Mantenimiento — Backup rápido (v1)</div>
    <span class="tiny">Genera .sql (tabla principal + CRM aux) y lo registra en log</span>
  </div>

  <form method="post" class="mt-12">
    <input type="hidden" name="action" value="maintenance_backup">
    <button class="btn-estado" type="submit">Crear backup ahora</button>
  </form>
  
  <form method="get" class="mt-12">
    <input type="hidden" name="page" value="ops">
    <input type="hidden" name="tab" value="mantenimiento">
    <input type="hidden" name="do" value="create">
    <input type="hidden" name="export" value="sql">
    <button class="btn-estado" type="submit">Exportar SQL (descargar)</button>
  </form>

  <div class="help">
    Carpeta: <strong><?= h($BACKUP_DIR) ?></strong>
  </div>
</div>

<div class="card">
  <div class="row-between">
    <div class="section-title">Backups disponibles (<?= count($backupFiles) ?>)</div>
    <span class="tiny">Descarga directa (.sql)</span>
  </div>

  <?php if (!$backupFiles): ?>
    <div class="tiny mt-12">— Aún no hay backups —</div>
  <?php else: ?>
    <div class="mt-12">
      <?php foreach($backupFiles as $p): ?>
        <?php
          $fname = basename($p);
          $when = date("Y-m-d H:i:s", (int)filemtime($p));
          $size = bytesToHuman((int)filesize($p));
        ?>
        <details>
          <summary><?= h($when) ?> — <?= h($fname) ?> <span class="tiny">(<?= h($size) ?>)</span></summary>
          <div class="mt-12">
            <a class="btn-link" href="<?= h($_SERVER["PHP_SELF"]) ?>?backup=1&file=<?= h($fname) ?>">Descargar</a>
          </div>
        </details>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="row-between">
    <div class="section-title">Log de mantenimiento (últimos <?= count($mntLogs) ?>)</div>
    <span class="tiny">BD: crm_mantenimiento_log</span>
  </div>

  <?php if (!$mntLogs): ?>
    <div class="tiny mt-12">— Sin logs todavía —</div>
  <?php else: ?>
    <div class="history-list mt-12">
      <?php foreach($mntLogs as $lg): ?>
        <?php
          $estado = (string)$lg["estado"];
          $tag = ($estado === "ok") ? "✅ OK" : "⚠️ ERROR";
        ?>
        <details>
          <summary>
            <?= h($lg["created_at"]) ?> — <?= h($tag) ?> — <?= h($lg["accion"]) ?>
            <?= !empty($lg["archivo"]) ? ('<span class="tiny"> · '.h($lg["archivo"]).'</span>') : "" ?>
            <?= !empty($lg["admin_user"]) ? ('<span class="tiny"> · '.h($lg["admin_user"]).'</span>') : "" ?>
          </summary>
          <pre><?=
            h(
              "Archivo: ".((string)($lg["archivo"] ?? "—"))."\n".
              "Tamaño: ".(isset($lg["bytes"]) ? bytesToHuman((int)$lg["bytes"]) : "—")."\n".
              "Detalle: ".((string)($lg["detalle"] ?? "—"))
            )
          ?></pre>
        </details>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
