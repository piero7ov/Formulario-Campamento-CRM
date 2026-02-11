<?php
/**
 * views/view_record.php
 * Vista de detalle de una inscripción (Informe).
 */
?>

<h3>Informe de inscripción (ID #<?= h($viewId) ?>)</h3>

<?php if (!$viewRow): ?>
  <div class="alert-error">No se encontró la inscripción solicitada.</div>
  <a class="btn-link" href="<?= h($_SERVER["PHP_SELF"]) ?>">Volver al listado</a>

<?php else: ?>

  <div class="card">
    <div class="row-between">
      <div class="section-title">Datos de la inscripción</div>

      <!-- Acciones rápidas -->
      <div class="row-actions">
        <a class="btn-link" href="<?= h($_SERVER["PHP_SELF"]) ?>">← Volver al listado</a>
        <a class="btn-link" href="?page=ops&tab=crud&do=edit&id=<?= h($viewId) ?>">Editar</a>

        <!-- Botón eliminar con confirmación JS -->
        <form method="post" class="js-delete-form" style="display:inline;"
              data-confirm="¿Eliminar la inscripción ID #<?= h($viewId) ?>? Esta acción no se puede deshacer.">
          <input type="hidden" name="action" value="crud_delete">
          <input type="hidden" name="id" value="<?= h($viewId) ?>">
          <input type="hidden" name="return" value="<?= h($_SERVER["PHP_SELF"]) ?>">
          <button type="submit" class="btn-link btn-danger">Eliminar</button>
        </form>
      </div>
    </div>

    <table class="kv">
      <?php foreach($cols as $col): ?>
        <tr>
          <td><?= h(labelize($col)) ?></td>
          <td>
            <?php if (isset($blobCols[$col])): ?>
              <?php
                $lenKey = "__len_$col";
                $hasDoc = isset($viewRow[$lenKey]) && (int)$viewRow[$lenKey] > 0;
              ?>
              <?php if ($hasDoc): ?>
                <?php $src = $_SERVER["PHP_SELF"]."?img=1&id=".urlencode($viewId)."&col=".urlencode($col); ?>
                <div class="doc-row">
                  <img class="thumb-lg js-thumb" src="<?= h($src) ?>" data-full="<?= h($src) ?>" alt="<?= h(labelize($col)) ?>">
                  <a class="btn-link" href="<?= h($src) ?>&download=1">Descargar</a>
                </div>
              <?php else: ?>
                —
              <?php endif; ?>
            <?php else: ?>
              <?= (!isset($viewRow[$col]) || $viewRow[$col] === null || $viewRow[$col] === "") ? "—" : h($viewRow[$col]) ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="grid-2">

    <div class="card">
      <div class="section-title">Enviar mensaje por email</div>

      <?php if (!$emailCol): ?>
        <div class="alert-warn">No se detectó columna de email (email/correo). No se puede enviar.</div>
      <?php else: ?>
        <?php $toEmail = trim((string)$viewRow[$emailCol]); ?>
        <?php if ($toEmail === ""): ?>
          <div class="alert-warn">Esta inscripción no tiene email.</div>
        <?php else: ?>
          <form method="post">
            <input type="hidden" name="action" value="send_email">
            <input type="hidden" name="id_registro" value="<?= h($viewId) ?>">
            <input type="hidden" name="to_email" value="<?= h($toEmail) ?>">

            <div class="control">
              <label>Para</label>
              <input class="input" type="text" value="<?= h($toEmail) ?>" readonly>
            </div>

            <div class="control">
              <label>Asunto</label>
              <input class="input" type="text" name="subject" placeholder="Ej: Información del campamento" required>
            </div>

            <div class="control">
              <label>Mensaje</label>
              <textarea name="message" placeholder="Escribe el mensaje..." required></textarea>
            </div>

            <button class="btn-estado" type="submit">Enviar</button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <div class="card" id="comms">
      <div class="row-between">
        <div class="section-title">
          Historial de comunicaciones
          <span class="tiny">Última actualización: <?= h($commsUpdatedAt) ?></span>
        </div>

        <a class="btn-link" href="?view=<?= h($viewId) ?>&refresh=1#comms">↻ Actualizar</a>
      </div>

      <?php if ($imapWarning): ?>
        <div class="alert-warn mt-12"><?= h($imapWarning) ?></div>
      <?php endif; ?>

      <details open class="mt-12">
        <summary>Mensajes enviados desde el panel (<?= count($sentLogs) ?>)</summary>
        <?php if (!$sentLogs): ?>
          <pre>— Sin envíos registrados —</pre>
        <?php else: ?>
          <?php foreach($sentLogs as $m): ?>
            <details>
              <summary><?= h($m["created_at"]) ?> — <?= h($m["asunto"] ?: "(sin asunto)") ?></summary>
              <pre><?= h($m["cuerpo"] ?: "") ?></pre>
            </details>
          <?php endforeach; ?>
        <?php endif; ?>
      </details>

      <details open>
        <summary>Mensajes recibidos (bandeja de entrada) (<?= count($receivedLogs) ?>)</summary>
        <?php if (!$receivedLogs): ?>
          <pre>— No se encontraron mensajes relacionados con ese email —</pre>
        <?php else: ?>
          <?php foreach($receivedLogs as $m): ?>
            <details>
              <summary><?= h($m["date"]) ?> — <?= h($m["subject"] ?: "(sin asunto)") ?></summary>
              <pre><strong>De:</strong> <?= h($m["from"]) ?></pre>
              <pre><?= h($m["body"]) ?></pre>
            </details>
          <?php endforeach; ?>
        <?php endif; ?>
      </details>
    </div>

  </div>

  <!-- ===================== TAREAS + HISTORIAL ===================== -->
  <div class="grid-2" style="margin-top:14px;" id="tasks">

    <div class="card">
      <div class="row-between">
        <div class="section-title">📌 Tareas / recordatorios</div>
        <span class="tiny">Pins arriba + prioridad</span>
      </div>

      <form method="post" class="task-form">
        <input type="hidden" name="action" value="task_create">
        <input type="hidden" name="id_registro" value="<?= h($viewId) ?>">
        <input type="hidden" name="return" value="<?= h($_SERVER["REQUEST_URI"]) ?>">

        <div class="grid-form">
          <div class="control">
            <label>Título</label>
            <input class="input" type="text" name="titulo" placeholder="Ej: Llamar para confirmar pago" required>
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
            <label>Recordatorio (opcional)</label>
            <input class="input" type="datetime-local" name="remind_at">
          </div>

          <div class="control">
            <label>Fecha límite (opcional)</label>
            <input class="input" type="datetime-local" name="due_at">
          </div>
        </div>

        <div class="control">
          <label>Detalles (opcional)</label>
          <textarea name="descripcion" placeholder="Información adicional..."></textarea>
        </div>

        <label class="checkline" style="margin-top:10px;">
          <input type="checkbox" name="pinned" value="1">
          <span class="tiny">📌 Fijar (pin) arriba</span>
        </label>

        <div class="form-actions">
          <button class="btn-estado" type="submit">Crear tarea</button>
          <a class="btn-link" href="#comms">Ir a comunicaciones</a>
        </div>
      </form>

      <div class="task-list" style="margin-top:12px;">
        <?php if (!$tasksByRegistro): ?>
          <div class="tiny">— Sin tareas aún —</div>
        <?php else: ?>
          <?php foreach($tasksByRegistro as $t): ?>
            <?php
              $isDone = ($t["estado"] === "hecha");
              $prio = (int)$t["prioridad"];
              $prioTxt = ($prio === 1) ? "Alta" : (($prio === 3) ? "Baja" : "Media");
              $pillClass = ($prio === 1) ? "prio prio--high" : (($prio === 3) ? "prio prio--low" : "prio prio--mid");
              $pin = ((int)$t["pinned"] === 1);
            ?>
            <div class="task-item <?= $isDone ? "task-item--done" : "" ?>">
              <div class="task-top">
                <div class="task-title">
                  <?= $pin ? "📌" : "•" ?>
                  <?= h($t["titulo"]) ?>
                  <span class="<?= h($pillClass) ?>"><?= h($prioTxt) ?></span>
                  <?php if (!empty($t["remind_at"])): ?><span class="tiny">⏰ <?= h($t["remind_at"]) ?></span><?php endif; ?>
                  <?php if (!empty($t["due_at"])): ?><span class="tiny">📅 <?= h($t["due_at"]) ?></span><?php endif; ?>
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

              <?php if (!empty($t["descripcion"])): ?>
                <div class="task-desc"><?= nl2br(h($t["descripcion"])) ?></div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="row-between">
        <div class="section-title">🧾 Historial de cambios</div>
        <span class="tiny">v3 base</span>
      </div>

      <?php if (!$historyLogs): ?>
        <div class="tiny mt-12">— Aún no hay cambios registrados —</div>
      <?php else: ?>
        <div class="history-list mt-12">
          <?php foreach($historyLogs as $hlog): ?>
            <details>
              <summary>
                <?= h($hlog["created_at"]) ?>
                — <?= h($hlog["accion"]) ?>
                <span class="tiny"> (<?= h($hlog["entidad"]) ?>)</span>
                <?= $hlog["admin_user"] ? '<span class="tiny"> — '.h($hlog["admin_user"]).'</span>' : "" ?>
              </summary>
              <?php if (!empty($hlog["resumen"])): ?>
                <div class="tiny mt-12"><strong><?= h($hlog["resumen"]) ?></strong></div>
              <?php endif; ?>
              <?php if (!empty($hlog["detalle"])): ?>
                <pre><?= h($hlog["detalle"]) ?></pre>
              <?php endif; ?>
            </details>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    </div>

  </div>

<?php endif; ?>
