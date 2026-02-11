<?php
/**
 * views/ops_crud.php
 * Formulario de Crear / Editar registro (CRUD).
 */

if ($opsAction === "create"):
  // Variables venidas de actions.php o inicializadas
  // $createOld se pasa si hubo error
  if (!isset($createOld)) $createOld = [];
?>
  <div class="card">
    <div class="row-between">
      <div class="section-title">➕ Crear inscripción</div>
      <span class="tiny">Límite subida servidor: <?= h($limiteTexto) ?></span>
    </div>

    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="crud_create">

      <div class="grid-form">
        <?php foreach($cols as $col): ?>
          <?php
            if (isset($autoIncCols[$col])) continue;

            $meta = $colsMeta[$col] ?? [];
            $extra = (string)($meta["EXTRA"] ?? "");
            if (stripos($extra, "GENERATED") !== false) continue;

            $dt = strtolower((string)($meta["DATA_TYPE"] ?? ""));
            $ct = (string)($meta["COLUMN_TYPE"] ?? "");
            $comment = (string)($meta["COLUMN_COMMENT"] ?? "");
            $isNullable = strtoupper((string)($meta["IS_NULLABLE"] ?? "YES")) === "YES";
            $default = $meta["COLUMN_DEFAULT"];
            $isRequired = (!$isNullable && $default === null && !isset($blobCols[$col]));

            $old = isset($createOld[$col]) ? (string)$createOld[$col] : "";

            $inputType = "text";
            $lc = strtolower($col);
            if (strpos($lc, "email") !== false || strpos($lc, "correo") !== false) $inputType = "email";
            if (strpos($lc, "telefono") !== false) $inputType = "tel";
          ?>

          <div class="control">
            <label><?= h(labelize($col)) ?><?= $isRequired ? ' <span class="req">*</span>' : '' ?></label>

            <?php if (isset($blobCols[$col])): ?>
              <input class="input" type="file" name="b[<?= h($col) ?>]" accept="application/pdf,image/*">
              <div class="help">Adjunta PDF o imagen. Máx: <strong><?= h($limiteTexto) ?></strong>.</div>

            <?php elseif (is_boolish_col($meta)): ?>
              <label class="checkline">
                <input type="checkbox" name="c[<?= h($col) ?>]" value="1" <?= ($old === "1") ? "checked" : "" ?>>
                <span class="tiny">Activado</span>
              </label>

            <?php elseif ($dt === "enum"): ?>
              <?php $opts = parse_enum_options($ct); ?>
              <select class="input" name="c[<?= h($col) ?>]" <?= $isRequired ? "required" : "" ?>>
                <option value="" <?= ($old==="" ? "selected" : "") ?> disabled>Selecciona…</option>
                <?php foreach($opts as $op): ?>
                  <option value="<?= h($op) ?>" <?= ($old===$op ? "selected" : "") ?>><?= h(ucfirst($op)) ?></option>
                <?php endforeach; ?>
              </select>

            <?php elseif ($dt === "date"): ?>
              <input class="input" type="date" name="c[<?= h($col) ?>]" value="<?= h($old) ?>" <?= $isRequired ? "required" : "" ?>>

            <?php elseif (in_array($dt, ["int","bigint","smallint","mediumint","tinyint"], true)): ?>
              <input class="input" type="number" step="1" name="c[<?= h($col) ?>]" value="<?= h($old) ?>" <?= $isRequired ? "required" : "" ?>>

            <?php elseif (in_array($dt, ["decimal","float","double"], true)): ?>
              <input class="input" type="number" step="0.01" name="c[<?= h($col) ?>]" value="<?= h($old) ?>" <?= $isRequired ? "required" : "" ?>>

            <?php elseif (in_array($dt, ["text","mediumtext","longtext"], true)): ?>
              <textarea class="input" name="c[<?= h($col) ?>]" <?= $isRequired ? "required" : "" ?>><?= h($old) ?></textarea>

            <?php else: ?>
              <input class="input" type="<?= h($inputType) ?>" name="c[<?= h($col) ?>]" value="<?= h($old) ?>" <?= $isRequired ? "required" : "" ?>>
            <?php endif; ?>

            <?php if ($comment !== ""): ?>
              <div class="help"><?= h($comment) ?></div>
            <?php endif; ?>
          </div>

        <?php endforeach; ?>
      </div>

      <div class="form-actions">
        <button class="btn-estado" type="submit">Crear inscripción</button>
        <a class="btn-link" href="<?= h($_SERVER["PHP_SELF"]) ?>">Cancelar</a>
      </div>

      <div class="note" style="margin-top:12px;">
        Campos con <strong>*</strong> se consideran obligatorios si la columna es NOT NULL y no tiene DEFAULT.
      </div>
    </form>
  </div>

<?php elseif ($opsAction === "edit"): ?>
  <?php if (!isset($editOld)) $editOld = []; ?>

  <div class="card">
    <div class="row-between">
      <div class="section-title">✏️ Editar inscripción <?= ($editId ? "(ID #".h($editId).")" : "") ?></div>
      <span class="tiny">Límite subida servidor: <?= h($limiteTexto) ?></span>
    </div>

    <?php if (!$primaryKeyColumn): ?>
      <div class="alert-error">No se detectó clave primaria (PK). No se puede editar.</div>

    <?php elseif ($editId === "" || !$editRow): ?>
      <div class="alert-error">No se encontró el registro a editar.</div>
      <a class="btn-link" href="<?= h($_SERVER["PHP_SELF"]) ?>">Volver al listado</a>

    <?php else: ?>

      <!-- Acciones rápidas en edit -->
      <div class="row-actions mt-12">
        <a class="btn-link" href="<?= h($_SERVER["PHP_SELF"]) ?>?view=<?= h($editId) ?>">Ver informe</a>
        <a class="btn-link" href="<?= h($_SERVER["PHP_SELF"]) ?>">Volver al listado</a>

        <form method="post" class="js-delete-form" style="display:inline;"
              data-confirm="¿Eliminar la inscripción ID #<?= h($editId) ?>? Esta acción no se puede deshacer.">
          <input type="hidden" name="action" value="crud_delete">
          <input type="hidden" name="id" value="<?= h($editId) ?>">
          <input type="hidden" name="return" value="<?= h($_SERVER["PHP_SELF"]) ?>">
          <button type="submit" class="btn-link btn-danger">Eliminar</button>
        </form>
      </div>

      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="crud_update">
        <input type="hidden" name="id" value="<?= h($editId) ?>">

        <div class="grid-form">
          <?php foreach($cols as $col): ?>
            <?php
              if ($col === $primaryKeyColumn) continue;
              if (isset($autoIncCols[$col])) continue;

              $meta = $colsMeta[$col] ?? [];
              $extra = (string)($meta["EXTRA"] ?? "");
              if (stripos($extra, "GENERATED") !== false) continue;

              $dt = strtolower((string)($meta["DATA_TYPE"] ?? ""));
              $ct = (string)($meta["COLUMN_TYPE"] ?? "");
              $comment = (string)($meta["COLUMN_COMMENT"] ?? "");
              $isNullable = strtoupper((string)($meta["IS_NULLABLE"] ?? "YES")) === "YES";

              // Valor actual: si venimos de error ($editOld), usar eso. Si no, de BD ($editRow).
              $cur = isset($editOld[$col]) ? (string)$editOld[$col] : (string)($editRow[$col] ?? "");

              $inputType = "text";
              $lc = strtolower($col);
              if (strpos($lc, "email") !== false || strpos($lc, "correo") !== false) $inputType = "email";
              if (strpos($lc, "telefono") !== false) $inputType = "tel";
            ?>

            <div class="control">
              <label><?= h(labelize($col)) ?></label>

              <?php if (isset($blobCols[$col])): ?>
                <?php
                  $lenKey = "__len_$col";
                  $hasDoc = isset($editRow[$lenKey]) && (int)$editRow[$lenKey] > 0;
                  $src = $_SERVER["PHP_SELF"]."?img=1&id=".urlencode($editId)."&col=".urlencode($col);
                ?>

                <?php if ($hasDoc): ?>
                  <div class="doc-row">
                    <img class="thumb-lg js-thumb" src="<?= h($src) ?>" data-full="<?= h($src) ?>" alt="<?= h(labelize($col)) ?>">
                    <a class="btn-link" href="<?= h($src) ?>&download=1">Descargar</a>

                    <?php if ($isNullable): ?>
                      <label class="rm-line">
                        <input type="checkbox" name="rm[<?= h($col) ?>]" value="1">
                        <span class="tiny">Eliminar documento</span>
                      </label>
                    <?php endif; ?>
                  </div>
                <?php else: ?>
                  <div class="tiny">— Sin documento —</div>
                <?php endif; ?>

                <input class="input" type="file" name="b[<?= h($col) ?>]" accept="application/pdf,image/*">
                <div class="help">Si subes archivo, reemplaza. Si no, se mantiene.</div>

              <?php elseif (is_boolish_col($meta)): ?>
                <?php $checked = ($cur === "1") ? "checked" : ""; ?>
                <label class="checkline">
                  <input type="checkbox" name="c[<?= h($col) ?>]" value="1" <?= $checked ?>>
                  <span class="tiny">Activado</span>
                </label>

              <?php elseif ($dt === "enum"): ?>
                <?php $opts = parse_enum_options($ct); ?>
                <select class="input" name="c[<?= h($col) ?>]">
                  <option value="" <?= ($cur==="" ? "selected" : "") ?>>—</option>
                  <?php foreach($opts as $op): ?>
                    <option value="<?= h($op) ?>" <?= ($cur===$op ? "selected" : "") ?>><?= h(ucfirst($op)) ?></option>
                  <?php endforeach; ?>
                </select>

              <?php elseif ($dt === "date"): ?>
                <input class="input" type="date" name="c[<?= h($col) ?>]" value="<?= h($cur) ?>">

              <?php elseif (in_array($dt, ["int","bigint","smallint","mediumint","tinyint"], true)): ?>
                <input class="input" type="number" step="1" name="c[<?= h($col) ?>]" value="<?= h($cur) ?>">

              <?php elseif (in_array($dt, ["decimal","float","double"], true)): ?>
                <input class="input" type="number" step="0.01" name="c[<?= h($col) ?>]" value="<?= h($cur) ?>">

              <?php elseif (in_array($dt, ["text","mediumtext","longtext"], true)): ?>
                <textarea class="input" name="c[<?= h($col) ?>]"><?= h($cur) ?></textarea>

              <?php else: ?>
                <input class="input" type="<?= h($inputType) ?>" name="c[<?= h($col) ?>]" value="<?= h($cur) ?>">
              <?php endif; ?>

              <?php if ($comment !== ""): ?>
                <div class="help"><?= h($comment) ?></div>
              <?php endif; ?>

            </div>
          <?php endforeach; ?>
        </div>

        <div class="form-actions">
          <button class="btn-estado" type="submit">Guardar cambios</button>
          <a class="btn-link" href="<?= h($_SERVER["PHP_SELF"]) ?>?view=<?= h($editId) ?>">Cancelar</a>
        </div>
      </form>

    <?php endif; ?>
  </div>
<?php endif; ?>
