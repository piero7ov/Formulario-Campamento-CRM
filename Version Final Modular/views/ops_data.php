<?php
/**
 * views/ops_data.php
 * Gestión de datos (tareas globales, filtros).
 */

// Filtros GET simples (tareas)
$tEstado = isset($_GET["t_estado"]) ? (string)$_GET["t_estado"] : "";
$tEstado = in_array($tEstado, ["","pendiente","hecha"], true) ? $tEstado : "";

$tPinned = isset($_GET["t_pinned"]) ? (string)$_GET["t_pinned"] : "";
$tPinned = in_array($tPinned, ["","1"], true) ? $tPinned : "";

$tQ = trim((string)($_GET["t_q"] ?? ""));

$where = ["1=1"];
$types = "";
$vals  = [];

if ($tEstado !== "") { $where[] = "estado = ?"; $types .= "s"; $vals[] = $tEstado; }
if ($tPinned === "1") { $where[] = "pinned = 1"; }

if ($tQ !== "") {
  $where[] = "(titulo LIKE ? OR descripcion LIKE ? OR id_registro LIKE ?)";
  $types .= "sss";
  $like = "%".$tQ."%";
  $vals[] = $like; $vals[] = $like; $vals[] = $like;
}

$sql = "
  SELECT id, id_registro, titulo, prioridad, pinned, estado, remind_at, due_at, updated_at
  FROM crm_tareas
  WHERE ".implode(" AND ", $where)."
  ORDER BY pinned DESC, prioridad ASC,
           (remind_at IS NULL) ASC, remind_at ASC,
           (due_at IS NULL) ASC, due_at ASC,
           id DESC
  LIMIT 200
";

$tareasGlobales = [];
$st = $c->prepare($sql);
if ($st) {
  if ($types !== "") {
    $params = array_merge([$types], $vals);
    $refs = [];
    foreach($params as $i => $v){ $refs[$i] = &$params[$i]; }
    call_user_func_array([$st, "bind_param"], $refs);
  }
  $st->execute();
  $res = $st->get_result();
  while($res && ($row = $res->fetch_assoc())) $tareasGlobales[] = $row;
  $st->close();
}
?>

<div class="card">
  <div class="row-between">
    <div class="section-title">📌 Gestión de datos — Tareas / recordatorios</div>
    <span class="tiny">Global + por inscripción</span>
  </div>

  <form method="get" class="mt-12" style="display:flex; gap:10px; flex-wrap:wrap;">
    <input type="hidden" name="page" value="ops">
    <input type="hidden" name="tab" value="datos">
    <input type="hidden" name="do" value="create">

    <select class="input" name="t_estado" style="width:200px;">
      <option value="" <?= ($tEstado===""?"selected":"") ?>>Estado: todos</option>
      <option value="pendiente" <?= ($tEstado==="pendiente"?"selected":"") ?>>Pendiente</option>
      <option value="hecha" <?= ($tEstado==="hecha"?"selected":"") ?>>Hecha</option>
    </select>

    <select class="input" name="t_pinned" style="width:200px;">
      <option value="" <?= ($tPinned===""?"selected":"") ?>>Pins: todos</option>
      <option value="1" <?= ($tPinned==="1"?"selected":"") ?>>Solo fijadas</option>
    </select>

    <input class="input" type="text" name="t_q" value="<?= h($tQ) ?>" placeholder="Buscar título / id_registro..." style="width:min(420px, 100%);">

    <button class="btn-estado" type="submit">Filtrar</button>
    <a class="btn-link" href="<?= h($_SERVER["PHP_SELF"]) ?>?page=ops&tab=datos&do=create">Limpiar</a>
  </form>
</div>

<div class="card">
  <div class="row-between">
    <div class="section-title">➕ Crear tarea (global o ligada a una inscripción)</div>
    <span class="tiny">Si pones ID registro, se verá también en el informe</span>
  </div>

  <form method="post" class="task-form mt-12">
    <input type="hidden" name="action" value="task_create">
    <input type="hidden" name="return" value="<?= h($_SERVER["REQUEST_URI"]) ?>">

    <div class="grid-form">
      <div class="control">
        <label>ID inscripción (opcional)</label>
        <input class="input" type="text" name="id_registro" placeholder="Ej: 12">
      </div>

      <div class="control">
        <label>Prioridad</label>
        <select class="input" name="prioridad">
          <option value="1">Alta</option>
          <option value="2" selected>Media</option>
          <option value="3">Baja</option>
        </select>
      </div>

      <div class="control">
        <label>Título</label>
        <input class="input" type="text" name="titulo" required>
      </div>

      <div class="control">
        <label>Recordatorio</label>
        <input class="input" type="datetime-local" name="remind_at">
      </div>
    </div>

    <div class="control">
      <label>Detalles</label>
      <textarea name="descripcion" placeholder="Información adicional..."></textarea>
    </div>

    <label class="checkline" style="margin-top:10px;">
      <input type="checkbox" name="pinned" value="1">
      <span class="tiny">📌 Fijar (pin) arriba</span>
    </label>

    <div class="form-actions">
      <button class="btn-estado" type="submit">Crear</button>
    </div>
  </form>
</div>

<div class="card">
  <div class="section-title">Listado de tareas (<?= count($tareasGlobales) ?>)</div>

  <?php if (!$tareasGlobales): ?>
    <div class="tiny mt-12">— No hay tareas con ese filtro —</div>
  <?php else: ?>
    <div class="task-list mt-12">
      <?php foreach($tareasGlobales as $t): ?>
        <?php
          $prio = (int)$t["prioridad"];
          $prioTxt = ($prio === 1) ? "Alta" : (($prio === 3) ? "Baja" : "Media");
          $pillClass = ($prio === 1) ? "prio prio--high" : (($prio === 3) ? "prio prio--low" : "prio prio--mid");
          $pin = ((int)$t["pinned"] === 1);
          $isDone = ($t["estado"] === "hecha");
        ?>
        <div class="task-item <?= $isDone ? "task-item--done" : "" ?>">
          <div class="task-top">
            <div class="task-title">
              <?= $pin ? "📌" : "•" ?>
              <?= h($t["titulo"]) ?>
              <span class="<?= h($pillClass) ?>"><?= h($prioTxt) ?></span>
              <?php if (!empty($t["id_registro"])): ?>
                <a class="btn-link" href="<?= h($_SERVER["PHP_SELF"]) ?>?view=<?= h($t["id_registro"]) ?>#tasks">Ver inscripción #<?= h($t["id_registro"]) ?></a>
              <?php endif; ?>
            </div>

            <div class="row-actions">
              <form method="post" style="display:inline;">
                <input type="hidden" name="action" value="task_pin">
                <input type="hidden" name="task_id" value="<?= h($t["id"]) ?>">
                <input type="hidden" name="return" value="<?= h($_SERVER["REQUEST_URI"]) ?>">
                <button class="btn-link" type="submit"><?= $pin ? "Desfijar" : "Fijar" ?></button>
              </form>

              <form method="post" style="display:inline;">
                <input type="hidden" name="action" value="task_toggle_done">
                <input type="hidden" name="task_id" value="<?= h($t["id"]) ?>">
                <input type="hidden" name="return" value="<?= h($_SERVER["REQUEST_URI"]) ?>">
                <button class="btn-link" type="submit"><?= $isDone ? "Reabrir" : "Hecha" ?></button>
              </form>

              <form method="post" class="js-delete-form" style="display:inline;"
                    data-confirm="¿Eliminar esta tarea?">
                <input type="hidden" name="action" value="task_delete">
                <input type="hidden" name="task_id" value="<?= h($t["id"]) ?>">
                <input type="hidden" name="return" value="<?= h($_SERVER["REQUEST_URI"]) ?>">
                <button class="btn-link btn-danger" type="submit">Eliminar</button>
              </form>
            </div>
          </div>

          <div class="tiny" style="margin-top:6px;">
            <?php if (!empty($t["remind_at"])): ?>⏰ <?= h($t["remind_at"]) ?><?php endif; ?>
            <?php if (!empty($t["due_at"])): ?> · 📅 <?= h($t["due_at"]) ?><?php endif; ?>
            · Estado: <strong><?= h($t["estado"]) ?></strong>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
