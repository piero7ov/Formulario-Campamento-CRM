<?php
/**
 * includes/data.php
 * Prepara los datos para las vistas (selects, metadatos, carga de registros).
 */

$panel_msg = flash_get("flash_ok");
$panel_err = flash_get("flash_err");

// Mensajes de forms CREATE/EDIT que fallaron y no hicieron redirect
if (isset($createErrors) && $createErrors) {
  if ($panel_err) $panel_err .= " | " . implode(" | ", $createErrors);
  else $panel_err = implode(" | ", $createErrors);
}
if (isset($editErrors) && $editErrors) {
  if ($panel_err) $panel_err .= " | " . implode(" | ", $editErrors);
  else $panel_err = implode(" | ", $editErrors);
}


/* =========================================================
   METADATOS DE COLUMNAS (Siempre necesarios logueado)
   ========================================================= */
$cols = [];
$emailCol = null;
$blobCols = [];
$sortableCols = [];
$colsMeta = [];
$autoIncCols = [];
$primaryKeyColumn = null;
$primaryKeyIsAuto = false;

// Límites subida
$uploadMax  = iniSizeToBytes((string)ini_get('upload_max_filesize'));
$postMax    = iniSizeToBytes((string)ini_get('post_max_size'));
$limiteReal = ($uploadMax > 0 && $postMax > 0) ? min($uploadMax, $postMax) : max($uploadMax, $postMax);
$limiteTexto = bytesToHuman((int)$limiteReal);

if ($loggedIn) {
  // PK
  $pkRes = $c->query("
    SELECT COLUMN_NAME, EXTRA
    FROM information_schema.columns
    WHERE table_schema = '".$c->real_escape_string($db_name)."'
      AND table_name   = '".$c->real_escape_string($table_name)."'
      AND COLUMN_KEY   = 'PRI'
    LIMIT 1
  ");
  if ($pkRes && $pkRes->num_rows > 0) {
    $row = $pkRes->fetch_assoc();
    $primaryKeyColumn = $row["COLUMN_NAME"];
    $primaryKeyIsAuto = (stripos((string)$row["EXTRA"], "auto_increment") !== false);
  }

  // Columnas
  $r = $c->query("
    SELECT
      COLUMN_NAME, DATA_TYPE, COLUMN_TYPE,
      IS_NULLABLE, COLUMN_DEFAULT, EXTRA,
      COLUMN_COMMENT
    FROM information_schema.columns
    WHERE table_schema='".$c->real_escape_string($db_name)."'
      AND table_name='".$c->real_escape_string($table_name)."'
    ORDER BY ORDINAL_POSITION
  ");

  while($r && ($f = $r->fetch_assoc())){
    $col = $f["COLUMN_NAME"];
    $dt  = $f["DATA_TYPE"] ?? "";

    $cols[] = $col;
    $colsMeta[$col] = $f;

    if (stripos((string)($f["EXTRA"] ?? ""), "auto_increment") !== false) {
      $autoIncCols[$col] = true;
    }

    if (is_blob_datatype($dt)) {
      $blobCols[$col] = true;
    } else {
      $sortableCols[$col] = true;
    }
  }

  $emailCol = findEmailColumn($cols);
}


/* =========================================================
   CARGA PARA EDIT (CRUD TAB)
   ========================================================= */
$editId  = ($loggedIn && $page === "ops" && $opsTab === "crud" && $opsAction === "edit")
  ? (string)($_GET["id"] ?? "")
  : "";
$editRow = null;

if ($loggedIn && $editId !== "" && $primaryKeyColumn) {
  $projection = build_select_projection($cols, $blobCols);
  $stmt = $c->prepare("SELECT $projection FROM `$table_name` WHERE `$primaryKeyColumn` = ? LIMIT 1");
  if ($stmt) {
    $stmt->bind_param("s", $editId);
    $stmt->execute();
    $res = $stmt->get_result();
    $editRow = $res ? $res->fetch_assoc() : null;
    $stmt->close();
  }
}

/* =========================================================
   CARGA PARA VIEW RECORD (INFORME)
   ========================================================= */
$viewId = ($loggedIn && isset($_GET["view"])) ? (string)$_GET["view"] : null;
$viewRow = null;
$sentLogs = [];
$receivedLogs = [];
$tasksByRegistro = [];
$historyLogs = [];
$imapWarning = "";
$commsUpdatedAt = date("Y-m-d H:i:s");

if ($loggedIn && $viewId !== null && $viewId !== "") {
  // Fila principal
  if ($primaryKeyColumn) {
    $projection = build_select_projection($cols, $blobCols);
    $stmt = $c->prepare("SELECT $projection FROM `$table_name` WHERE `$primaryKeyColumn` = ? LIMIT 1");
    if ($stmt) {
      $stmt->bind_param("s", $viewId);
      $stmt->execute();
      $res = $stmt->get_result();
      $viewRow = $res ? $res->fetch_assoc() : null;
      $stmt->close();
    }
  }

  // Logs enviados
  $stmt = $c->prepare("
    SELECT asunto, cuerpo, created_at
    FROM crm_comunicaciones
    WHERE id_registro = ? AND direccion='sent'
    ORDER BY created_at DESC LIMIT 30
  ");
  if ($stmt) {
    $stmt->bind_param("s", $viewId);
    $stmt->execute();
    $res = $stmt->get_result();
    while($res && ($row = $res->fetch_assoc())) $sentLogs[] = $row;
    $stmt->close();
  }

  // Tareas
  $stmt = $c->prepare("
    SELECT id, titulo, descripcion, prioridad, pinned, estado, remind_at, due_at, updated_at
    FROM crm_tareas
    WHERE id_registro = ?
    ORDER BY pinned DESC, prioridad ASC,
             (remind_at IS NULL) ASC, remind_at ASC,
             (due_at IS NULL) ASC, due_at ASC,
             id DESC
    LIMIT 50
  ");
  if ($stmt) {
    $stmt->bind_param("s", $viewId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) $tasksByRegistro[] = $row;
    $stmt->close();
  }

  // Historial
  $stmt = $c->prepare("
    SELECT entidad, accion, resumen, detalle, admin_user, created_at
    FROM crm_historial_cambios
    WHERE entidad_id = ?
    ORDER BY created_at DESC LIMIT 40
  ");
  if ($stmt) {
    $stmt->bind_param("s", $viewId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) $historyLogs[] = $row;
    $stmt->close();
  }

  // IMAP logs (solo si tenemos email)
  if (!$emailCol) $imapWarning = "No se detectó una columna de email.";
  else if (!$viewRow || empty($viewRow[$emailCol])) $imapWarning = "Esta inscripción no tiene email.";
  else if (!function_exists("imap_open")) $imapWarning = "La extensión IMAP no está habilitada.";
  else {
    $userEmail = trim((string)$viewRow[$emailCol]);
    $safeEmail = str_replace('"', '', $userEmail);
    $imap = @imap_open($hostname, $username, $password);
    if (!$imap) {
      $imapWarning = "No se pudo acceder al correo: " . (function_exists('imap_last_error') ? imap_last_error() : "error");
    } else {
      // Función interna rápida search
      $buscarEnMailbox = function($mailbox) use ($imap, $safeEmail){
        @imap_reopen($imap, $mailbox);
        $ids = [];
        $a = @imap_search($imap, 'FROM "'.$safeEmail.'"', SE_FREE, "UTF-8");
        if (is_array($a)) $ids = array_merge($ids, $a);
        $b = @imap_search($imap, 'TO "'.$safeEmail.'"', SE_FREE, "UTF-8");
        if (is_array($b)) $ids = array_merge($ids, $b);
        $ids = array_values(array_unique($ids));
        rsort($ids);
        return array_slice($ids, 0, 20);
      };
      $ids = $buscarEnMailbox($hostname);
      if (empty($ids)) {
        $allMail = preg_replace('~INBOX$~i', '[Gmail]/All Mail', $hostname);
        $ids = $buscarEnMailbox($allMail);
      }

      foreach($ids as $msgno){
        $ov = @imap_fetch_overview($imap, $msgno, 0);
        $ov = ($ov && isset($ov[0])) ? $ov[0] : null;
        $subj = $ov ? decodeMime((string)($ov->subject ?? "")) : "";
        $from = $ov ? decodeMime((string)($ov->from ?? ""))    : "";
        $date = $ov ? (string)($ov->date ?? "")               : "";
        $body = imap_get_text_body($imap, $msgno);
        if (strlen($body) > 1200) $body = substr($body, 0, 1200) . "\n\n[...recortado...]";
        $receivedLogs[] = ["subject"=>$subj, "from"=>$from, "date"=>$date, "body"=>$body];
      }
      @imap_close($imap);
    }
  }

  if (isset($_GET["refresh"]) && $_GET["refresh"] == "1" && !$panel_msg && !$panel_err) {
    $panel_msg = "Comunicaciones actualizadas.";
  }
}

/* =========================================================
   DATOS GENERALES (ESTADOS, UNREAD)
   ========================================================= */
$estadosActuales = [];
$unreadCountByEmail = [];

if ($loggedIn) {
  // Estados
  $resEstados = $c->query("SELECT id_registro, estado, color FROM crm_estados_inscripciones");
  if ($resEstados) {
    while ($row = $resEstados->fetch_assoc()) {
      $estadosActuales[$row["id_registro"]] = $row;
    }
  }

  // Unread emails global (para badge en listado)
  if ($emailCol !== null && function_exists('imap_open')) {
    $imap = @imap_open($hostname, $username, $password);
    if ($imap) {
      $unseenIds = @imap_search($imap, 'UNSEEN', SE_FREE, "UTF-8");
      if (is_array($unseenIds)) {
        foreach ($unseenIds as $num) {
          $header = @imap_headerinfo($imap, $num);
          $ex = imap_extract_from_emails($header);
          foreach ($ex as $eaddr) {
             if ($eaddr === strtolower($username)) continue;
             if (!isset($unreadCountByEmail[$eaddr])) $unreadCountByEmail[$eaddr] = 0;
             $unreadCountByEmail[$eaddr] += 1;
          }
        }
      }
      @imap_close($imap);
    }
  }
}

/* =========================================================
   SORTING & FILTERS (Listado)
   ========================================================= */
$sortCol = $loggedIn ? (string)($_GET["sort"] ?? "") : "";
$sortDir = $loggedIn ? strtolower((string)($_GET["dir"] ?? "asc")) : "asc";
if (!isset($sortableCols[$sortCol])) $sortCol = "";
$sortDir = ($sortDir === "desc") ? "desc" : "asc";

$filtersText = ($loggedIn && isset($_GET["f"]) && is_array($_GET["f"])) ? $_GET["f"] : [];
$filtersBlob = ($loggedIn && isset($_GET["fb"]) && is_array($_GET["fb"])) ? $_GET["fb"] : [];

foreach($filtersText as $k => $v){ if(is_string($k)) $filtersText[$k] = trim((string)$v); }
foreach($filtersBlob as $k => $v){ if(is_string($k)) $filtersBlob[$k] = trim((string)$v); }
