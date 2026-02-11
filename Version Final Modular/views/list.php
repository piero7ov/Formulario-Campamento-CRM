<?php
/**
 * views/list.php
 * Listado principal de inscripciones.
 */
?>
<h3>Listado de inscripciones del campamento</h3>

<!-- Form "oculto" para filtros (preserva sort/dir) -->
<form id="filterForm" method="get" style="margin:0 0 10px;">
  <input type="hidden" name="sort" value="<?= h($sortCol) ?>">
  <input type="hidden" name="dir"  value="<?= h($sortDir) ?>">
</form>

<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <?php foreach($cols as $col): ?>
          <?php
            $isSortable = isset($sortableCols[$col]);
            $isSorted   = $isSortable && ($sortCol === $col);
            $arrow      = $isSortable ? ($isSorted ? (($sortDir === "asc") ? "▲" : "▼") : "↕") : "";
            $thClass    = $isSortable ? "sortable" : "";
          ?>
          <th
            class="<?= h($thClass) ?>"
            <?php if ($isSortable): ?>data-sort-col="<?= h($col) ?>"<?php endif; ?>
            title="<?= $isSortable ? "Click: ordenar" : h($col) ?>"
          >
            <span class="th-inner">
              <span class="th-label"><?= h(labelize($col)) ?></span>
              <?php if ($isSortable): ?>
                <span class="th-sort">
                  <span class="sort-arrow <?= $isSorted ? "" : "sort-arrow--idle" ?>"><?= h($arrow) ?></span>
                </span>
              <?php endif; ?>
            </span>
          </th>
        <?php endforeach; ?>

        <th class="col-unread" title="Correos no leídos en bandeja de entrada">Emails nuevos</th>
        <th class="col-acciones" title="Acciones">Acciones</th>
        <th class="col-estado" title="Estado CRM">Estado</th>
      </tr>

      <tr class="filter-row">
        <?php foreach($cols as $col): ?>
          <th>
            <?php if (isset($blobCols[$col])): ?>
              <?php $cur = $filtersBlob[$col] ?? ""; ?>
              <select class="filter-select" name="fb[<?= h($col) ?>]" form="filterForm">
                <option value=""  <?= ($cur==="") ? "selected" : "" ?>>—</option>
                <option value="1" <?= ($cur==="1") ? "selected" : "" ?>>Con doc</option>
                <option value="0" <?= ($cur==="0") ? "selected" : "" ?>>Sin doc</option>
              </select>
            <?php else: ?>
              <?php $cur = $filtersText[$col] ?? ""; ?>
              <input
                class="filter-input"
                type="text"
                name="f[<?= h($col) ?>]"
                value="<?= h($cur) ?>"
                placeholder="Filtrar…"
                form="filterForm"
              >
            <?php endif; ?>
          </th>
        <?php endforeach; ?>

        <th class="col-unread"></th>

        <th class="col-acciones">
        <div class="row-actions">
            <button class="btn-link" type="submit" form="filterForm">Aplicar</button>
            <a class="btn-link" href="<?= h($_SERVER["PHP_SELF"]) ?>">Limpiar</a>

            <?php
            // Construye URL de export CSV manteniendo GET actual (filtros/orden)
            $qs = $_GET;
            $qs["export"] = "csv";
            unset($qs["view"]);
            $exportCsvUrl = $_SERVER["PHP_SELF"] . "?" . http_build_query($qs);
            ?>
            <a class="btn-link" href="<?= h($exportCsvUrl) ?>">Exportar CSV</a>
        </div>
        </th>

        <th class="col-estado"></th>
      </tr>
    </thead>

    <tbody>
      <?php
        $projection = build_select_projection($cols, $blobCols);

        // WHERE dinámico (filtros)
        $where = ["1=1"];
        $bindTypes = "";
        $bindVals  = [];

        foreach($filtersText as $col => $val){
          if ($val === "") continue;
          if (!isset($sortableCols[$col])) continue;
          $where[] = "CAST(`$col` AS CHAR) LIKE ?";
          $bindTypes .= "s";
          $bindVals[] = "%".$val."%";
        }

        foreach($filtersBlob as $col => $flag){
          if ($flag === "") continue;
          if (!isset($blobCols[$col])) continue;

          if ($flag === "1") {
            $where[] = "OCTET_LENGTH(`$col`) > 0";
          } elseif ($flag === "0") {
            $where[] = "(`$col` IS NULL OR OCTET_LENGTH(`$col`) = 0)";
          }
        }

        $sql = "SELECT $projection FROM `$table_name` WHERE ".implode(" AND ", $where);

        // ORDER BY
        if ($sortCol !== "") {
          $sql .= " ORDER BY `$sortCol` ".strtoupper($sortDir);
        } else {
          $orderBy = $primaryKeyColumn ? $primaryKeyColumn : ($cols[0] ?? null);
          if ($orderBy) $sql .= " ORDER BY `$orderBy` DESC";
        }

        $stmtList = $c->prepare($sql);
        $r = false;

        if ($stmtList) {
          if ($bindTypes !== "") {
            $params = array_merge([$bindTypes], $bindVals);
            $refs = [];
            foreach($params as $i => $v){ $refs[$i] = &$params[$i]; }
            call_user_func_array([$stmtList, "bind_param"], $refs);
          }
          $stmtList->execute();
          $r = $stmtList->get_result();
        }

        while($r && ($f = $r->fetch_assoc())) {

          $idRegistro = null;
          if ($primaryKeyColumn !== null && isset($f[$primaryKeyColumn])) {
            $idRegistro = (string)$f[$primaryKeyColumn];
          }

          $rowEmailNorm = null;
          if ($emailCol !== null && isset($f[$emailCol]) && $f[$emailCol] !== '') {
            $rowEmailNorm = strtolower(trim((string)$f[$emailCol]));
          }

          echo "<tr>";

          foreach($cols as $col){

            if (isset($blobCols[$col])) {
              $lenKey = "__len_$col";
              $hasDoc = isset($f[$lenKey]) && (int)$f[$lenKey] > 0;

              if ($hasDoc && $idRegistro !== null) {
                $src = $_SERVER["PHP_SELF"]."?img=1&id=".urlencode($idRegistro)."&col=".urlencode($col);
                echo '<td class="td-blob">';
                echo '<img class="thumb js-thumb" src="'.h($src).'" data-full="'.h($src).'" alt="'.h(labelize($col)).'">';
                echo '</td>';
              } else {
                echo '<td><span class="muted-dash">—</span></td>';
              }
            } else {
              $val = $f[$col] ?? "";
              if ($val === null || $val === "") {
                echo '<td><span class="muted-dash">—</span></td>';
              } else {
                echo "<td title='".h($val)."'>".h($val)."</td>";
              }
            }
          }

          // Badge de emails no leídos
          echo '<td class="col-unread">';
          if ($rowEmailNorm && isset($unreadCountByEmail[$rowEmailNorm])) {
            $n = (int)$unreadCountByEmail[$rowEmailNorm];
            echo '<span class="badge-unread" title="Hay '.$n.' email(s) sin leer de este contacto.">'.$n.'</span>';
          } else {
            echo '<span class="badge-none">—</span>';
          }
          echo '</td>';

          // Acciones + Estado CRM en línea
          echo '<td class="col-acciones">';
          if ($idRegistro !== null) {
            echo '<div class="row-actions">';

            // Form para estado
            echo '<form method="post" class="form-estado">';
            echo '<input type="hidden" name="action" value="update_estado">';
            echo '<input type="hidden" name="id_registro" value="'.h($idRegistro).'">';

            echo '<input type="hidden" name="return" value="'.h($_SERVER["REQUEST_URI"]).'">';

            echo '<label class="tiny" style="display:inline-flex; align-items:center; gap:6px; margin-left:8px;">';
            echo '  <input type="checkbox" name="send_confirm" value="1">';
            echo 'Confirmación';
            echo '</label>';

            echo '<select name="estado">';
            foreach($estadosCRM as $nombreEstado => $colorEstado){
              $selected = (
                isset($estadosActuales[$idRegistro]) &&
                $estadosActuales[$idRegistro]["estado"] === $nombreEstado
              ) ? " selected" : "";
              echo '<option value="'.h($nombreEstado).'"'.$selected.'>'.h($nombreEstado).'</option>';
            }
            echo '</select>';

            echo '<button type="submit" class="btn-estado">Guardar</button>';
            echo '</form>';

            echo '<a class="btn-link" href="?view='.h($idRegistro).'#comms">Ver informe</a>';
            echo '<a class="btn-link" href="?page=ops&tab=crud&do=edit&id='.h($idRegistro).'">Editar</a>';

            // ELIMINAR con confirmación (JS)
            echo '<form method="post" class="js-delete-form" style="display:inline;" data-confirm="¿Eliminar la inscripción ID #'.h($idRegistro).'? Esta acción no se puede deshacer.">';
            echo '  <input type="hidden" name="action" value="crud_delete">';
            echo '  <input type="hidden" name="id" value="'.h($idRegistro).'">';
            echo '  <input type="hidden" name="return" value="'.h($_SERVER["REQUEST_URI"]).'">';
            echo '  <button type="submit" class="btn-link btn-danger">Eliminar</button>';
            echo '</form>';

            echo '</div>';
          } else {
            echo '<span class="muted-dash">—</span>';
          }
          echo '</td>';

          // Estado CRM pill
          echo '<td class="col-estado">';
          if ($idRegistro !== null && isset($estadosActuales[$idRegistro])) {
            $estadoRow = $estadosActuales[$idRegistro];
            $estadoTxt = h($estadoRow["estado"]);
            $colorTxt  = h($estadoRow["color"]);
            echo '<span class="estado-pill" style="background:'.$colorTxt.'20; border-color:'.$colorTxt.'; color:'.$colorTxt.';">'.$estadoTxt.'</span>';
          } else {
            echo '<span class="estado-pill estado-pill--vacio">Sin estado</span>';
          }
          echo '</td>';

          echo "</tr>";
        }

        if ($stmtList) $stmtList->close();
      ?>
    </tbody>
  </table>
</div>
