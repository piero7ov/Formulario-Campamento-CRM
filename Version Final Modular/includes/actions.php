<?php
/**
 * includes/actions.php
 * Procesa acciones POST (CRUD, Emails, Tareas, Mantenimiento).
 * Se ejecuta antes de generar la vista.
 */

if (!$loggedIn) return; // Solo procesar si está logueado

// 1) UPDATE ESTADO CRM (+ confirmación al "Completado")
if (isset($_POST["action"]) && $_POST["action"] === "update_estado") {

  $id_registro = $_POST["id_registro"] ?? null;
  $estado      = $_POST["estado"]      ?? null;

  // opción (checkbox)
  $sendConfirm = (isset($_POST["send_confirm"]) && (string)$_POST["send_confirm"] === "1");

  // Return seguro para volver al mismo listado (con filtros/orden)
  $fallback = $_SERVER["PHP_SELF"];
  $returnUrl = safe_return_url($fallback);

  if ($id_registro !== null && isset($estadosCRM[$estado])) {

    // estado anterior (para detectar transición)
    $prevEstado = null;
    $stPrev = $c->prepare("SELECT estado FROM crm_estados_inscripciones WHERE id_registro=? LIMIT 1");
    if ($stPrev) {
      $stPrev->bind_param("s", $id_registro);
      $stPrev->execute();
      $rPrev = $stPrev->get_result();
      if ($rPrev && ($rowPrev = $rPrev->fetch_assoc())) $prevEstado = (string)$rowPrev["estado"];
      $stPrev->close();
    }

    $color = $estadosCRM[$estado];

    $stmt = $c->prepare("
      INSERT INTO crm_estados_inscripciones (id_registro, estado, color)
      VALUES (?, ?, ?)
      ON DUPLICATE KEY UPDATE
        estado = VALUES(estado),
        color  = VALUES(color)
    ");
    if ($stmt) {
      $stmt->bind_param("sss", $id_registro, $estado, $color);

      if ($stmt->execute()) {

        // Mensaje base
        flash_set("flash_ok", "Estado actualizado (ID #".h($id_registro).").");

        // Si cambió a Completado y marcó checkbox, enviamos confirmación
        $isTransitionToCompletado = ($estado === "Completado" && $prevEstado !== "Completado");

        if ($sendConfirm && $isTransitionToCompletado) {
          // Necesitamos metadatos de columnas para buscar email y PK
          // (los cargaremos en data.php, pero aquí necesitamos acceso rápido.
          //  Para simplificar, hacemos una query puntual a information_schema o asumimos config global si estuviera disponible.
          //  Como no tenemos $primaryKeyColumn calculado aún en este punto (está en data.php),
          //  buscaremos la PK ahora mismo.)

          $pkCol = null;
          $pkRes = $c->query("
            SELECT COLUMN_NAME FROM information_schema.columns
            WHERE table_schema = '".$c->real_escape_string($db_name)."'
              AND table_name   = '".$c->real_escape_string($table_name)."'
              AND COLUMN_KEY   = 'PRI' LIMIT 1
          ");
          if($pkRes && $r=$pkRes->fetch_assoc()) $pkCol = $r["COLUMN_NAME"];

          if (!$pkCol) {
            flash_set("flash_err", "No se detectó PK, no se puede armar resumen para confirmación.");
            header("Location: ".$returnUrl);
            exit;
          }

          // Buscar columna email
          $allCols = [];
          $r = $c->query("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema='".$c->real_escape_string($db_name)."' AND table_name='".$c->real_escape_string($table_name)."'");
          while($r && $f=$r->fetch_assoc()) $allCols[] = $f["COLUMN_NAME"];
          $emCol = findEmailColumn($allCols);

          if (!$emCol) {
            flash_set("flash_err", "No se detectó columna de email. No se pudo enviar confirmación.");
            header("Location: ".$returnUrl);
            exit;
          }

          // Traer fila
          // Necesitamos blobs para el helper build_inscripcion_resumen? No, el helper los ignora si se los pasamos.
          // Detectamos blobs rápido
          $bCols = [];
          $r = $c->query("SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.columns WHERE table_schema='".$c->real_escape_string($db_name)."' AND table_name='".$c->real_escape_string($table_name)."'");
          while($r && $f=$r->fetch_assoc()) {
            if (is_blob_datatype($f["DATA_TYPE"])) $bCols[$f["COLUMN_NAME"]] = true;
          }

          $projection = build_select_projection($allCols, $bCols);
          $rowData = null;

          $stRow = $c->prepare("SELECT $projection FROM `$table_name` WHERE `$pkCol`=? LIMIT 1");
          if ($stRow) {
            $stRow->bind_param("s", $id_registro);
            $stRow->execute();
            $resRow = $stRow->get_result();
            $rowData = $resRow ? $resRow->fetch_assoc() : null;
            $stRow->close();
          }

          if (!$rowData) {
            flash_set("flash_err", "No se encontró la inscripción para enviar confirmación (ID #".$id_registro.").");
            header("Location: ".$returnUrl);
            exit;
          }

          $to_email = trim((string)($rowData[$emCol] ?? ""));
          if ($to_email === "") {
            flash_set("flash_err", "La inscripción no tiene email. No se pudo enviar confirmación.");
            header("Location: ".$returnUrl);
            exit;
          }

          // Resumen
          $resumen = build_inscripcion_resumen($rowData, $allCols, $bCols);

          // Email
          $subject = "Confirmación de inscripción — Campamento (ID #".$id_registro.")";
          $body =
            "Hola,\n\n".
            "Tu inscripción ha sido marcada como COMPLETADA.\n\n".
            "Resumen de tu inscripción:\n".
            "----------------------------------------\n".
            $resumen . "\n".
            "----------------------------------------\n\n".
            "Si necesitas cualquier cosa, responde a este correo.\n\n".
            "Saludos,\n".
            $fromName;

          $smtpErr = "";
          $okMail = smtp_send_gmail($smtpHost, $smtpPort, $smtpUser, $smtpPass, $fromEmail, $fromName, $to_email, $subject, $body, $smtpErr);

          if ($okMail) {
            // Log sent
            $meta = json_encode([
              "type"    => "confirmacion_completado",
              "trigger" => "estado",
              "to"      => $to_email,
              "from"    => $fromEmail,
              "estado"  => $estado
            ], JSON_UNESCAPED_UNICODE);

            $stIns = $c->prepare("
              INSERT INTO crm_comunicaciones (id_registro, email_usuario, direccion, asunto, cuerpo, meta)
              VALUES (?, ?, 'sent', ?, ?, ?)
            ");
            if ($stIns) {
              $stIns->bind_param("sssss", $id_registro, $to_email, $subject, $body, $meta);
              $stIns->execute();
              $stIns->close();
            }

            audit_add($c, "inscripcion", (string)$id_registro, "confirm", "Email confirmación enviado", [
              "to" => $to_email,
              "subject" => $subject,
              "type" => "confirmacion_completado"
            ], $ADMIN_USER);

            flash_set("flash_ok", "Estado actualizado (ID #".$id_registro.") + confirmación enviada a ".$to_email.".");

          } else {
            audit_add($c, "inscripcion", (string)$id_registro, "confirm_error", "Fallo envío confirmación", [
              "to" => $to_email,
              "smtp_error" => $smtpErr
            ], $ADMIN_USER);

            flash_set("flash_err", "Estado actualizado, pero no se pudo enviar confirmación: ".$smtpErr);
          }
        }

      } else {
        flash_set("flash_err", "No se pudo actualizar estado: ".$stmt->error);
      }

      $stmt->close();

    } else {
      flash_set("flash_err", "No se pudo preparar estado: ".$c->error);
    }
  }

  header("Location: ".$returnUrl);
  exit;
}

// 2) ENVIAR EMAIL (SMTP) + log en BD
if (isset($_POST["action"]) && $_POST["action"] === "send_email") {
  $id_registro = $_POST["id_registro"] ?? "";
  $to_email    = trim($_POST["to_email"] ?? "");
  $subject     = trim($_POST["subject"] ?? "");
  $message     = trim($_POST["message"] ?? "");

  if ($to_email === "" || $subject === "" || $message === "") {
    flash_set("flash_err", "Completa destinatario, asunto y mensaje.");
    header("Location: ".$_SERVER["PHP_SELF"]."?view=".urlencode($id_registro)."#comms");
    exit;
  }

  $smtpErr = "";
  $ok = smtp_send_gmail($smtpHost, $smtpPort, $smtpUser, $smtpPass, $fromEmail, $fromName, $to_email, $subject, $message, $smtpErr);

  if ($ok) {
    $meta = json_encode(["to"=>$to_email, "from"=>$fromEmail], JSON_UNESCAPED_UNICODE);

    $stmt = $c->prepare("
      INSERT INTO crm_comunicaciones (id_registro, email_usuario, direccion, asunto, cuerpo, meta)
      VALUES (?, ?, 'sent', ?, ?, ?)
    ");
    if ($stmt) {
      $stmt->bind_param("sssss", $id_registro, $to_email, $subject, $message, $meta);
      $stmt->execute();
      $stmt->close();
    }

    flash_set("flash_ok", "Correo enviado a ".$to_email.".");
    header("Location: ".$_SERVER["PHP_SELF"]."?view=".urlencode($id_registro)."&refresh=1#comms");
    exit;
  } else {
    flash_set("flash_err", "No se pudo enviar. Detalle SMTP: ".$smtpErr);
    header("Location: ".$_SERVER["PHP_SELF"]."?view=".urlencode($id_registro)."#comms");
    exit;
  }
}

// 3) TAREAS / RECORDATORIOS
if (isset($_POST["action"])) {

  // -------- Crear tarea
  if ($_POST["action"] === "task_create") {

    $id_registro = trim((string)($_POST["id_registro"] ?? ""));
    if ($id_registro === "") $id_registro = null;

    $titulo = trim((string)($_POST["titulo"] ?? ""));
    $descripcion = trim((string)($_POST["descripcion"] ?? ""));

    $prioridad = (int)($_POST["prioridad"] ?? 2);
    if ($prioridad < 1 || $prioridad > 3) $prioridad = 2;

    $pinned = isset($_POST["pinned"]) ? 1 : 0;

    $remind_at = dt_local_to_mysql($_POST["remind_at"] ?? "");
    $due_at    = dt_local_to_mysql($_POST["due_at"] ?? "");

    if ($titulo === "") {
      flash_set("flash_err", "La tarea necesita un título.");
      header("Location: ".safe_return_url($_SERVER["PHP_SELF"]));
      exit;
    }

    $stmt = $c->prepare("
      INSERT INTO crm_tareas (id_registro, titulo, descripcion, prioridad, pinned, estado, remind_at, due_at)
      VALUES (?, ?, ?, ?, ?, 'pendiente', ?, ?)
    ");
    if (!$stmt) {
      flash_set("flash_err", "No se pudo crear la tarea: ".$c->error);
      header("Location: ".safe_return_url($_SERVER["PHP_SELF"]));
      exit;
    }

    $stmt->bind_param("sssiiss", $id_registro, $titulo, $descripcion, $prioridad, $pinned, $remind_at, $due_at);

    if ($stmt->execute()) {
      $newTaskId = (string)$c->insert_id;
      audit_add($c, "tarea", $id_registro ?? $newTaskId, "create", "Tarea creada", [
        "task_id" => $newTaskId,
        "id_registro" => $id_registro,
        "titulo" => $titulo,
        "prioridad" => $prioridad
      ], $ADMIN_USER);
      flash_set("flash_ok", "Tarea creada.");
    } else {
      flash_set("flash_err", "No se pudo crear la tarea: ".$stmt->error);
    }
    $stmt->close();

    header("Location: ".safe_return_url($_SERVER["PHP_SELF"]));
    exit;
  }

  // -------- Toggle hecha/pendiente
  if ($_POST["action"] === "task_toggle_done") {
    $taskId = (int)($_POST["task_id"] ?? 0);
    if ($taskId <= 0) {
      flash_set("flash_err", "Tarea inválida.");
      header("Location: ".safe_return_url($_SERVER["PHP_SELF"]));
      exit;
    }

    $idRegistro = null; $oldEstado = null;
    $st = $c->prepare("SELECT id_registro, estado FROM crm_tareas WHERE id=? LIMIT 1");
    if ($st) {
      $st->bind_param("i", $taskId);
      $st->execute();
      $r = $st->get_result();
      if ($r && ($row = $r->fetch_assoc())) {
        $idRegistro = $row["id_registro"];
        $oldEstado  = $row["estado"];
      }
      $st->close();
    }

    $stmt = $c->prepare("UPDATE crm_tareas SET estado = IF(estado='pendiente','hecha','pendiente') WHERE id=? LIMIT 1");
    if ($stmt) {
      $stmt->bind_param("i", $taskId);
      if ($stmt->execute()) {
        audit_add($c, "tarea", $idRegistro ?? (string)$taskId, "done", "Cambio estado tarea", [
          "task_id" => $taskId,
          "old_estado" => $oldEstado
        ], $ADMIN_USER);
        flash_set("flash_ok", "Estado de tarea actualizado.");
      } else {
        flash_set("flash_err", "No se pudo actualizar: ".$stmt->error);
      }
      $stmt->close();
    }

    header("Location: ".safe_return_url($_SERVER["PHP_SELF"]));
    exit;
  }

  // -------- Pin/unpin
  if ($_POST["action"] === "task_pin") {
    $taskId = (int)($_POST["task_id"] ?? 0);
    if ($taskId <= 0) {
      flash_set("flash_err", "Tarea inválida.");
      header("Location: ".safe_return_url($_SERVER["PHP_SELF"]));
      exit;
    }

    $idRegistro = null; $oldPinned = null;
    $st = $c->prepare("SELECT id_registro, pinned FROM crm_tareas WHERE id=? LIMIT 1");
    if ($st) {
      $st->bind_param("i", $taskId);
      $st->execute();
      $r = $st->get_result();
      if ($r && ($row = $r->fetch_assoc())) {
        $idRegistro = $row["id_registro"];
        $oldPinned  = (int)$row["pinned"];
      }
      $st->close();
    }

    $stmt = $c->prepare("UPDATE crm_tareas SET pinned = IF(pinned=1,0,1) WHERE id=? LIMIT 1");
    if ($stmt) {
      $stmt->bind_param("i", $taskId);
      if ($stmt->execute()) {
        audit_add($c, "tarea", $idRegistro ?? (string)$taskId, "pin", "Pin/unpin tarea", [
          "task_id" => $taskId,
          "old_pinned" => $oldPinned
        ], $ADMIN_USER);
        flash_set("flash_ok", "Pin actualizado.");
      } else {
        flash_set("flash_err", "No se pudo actualizar: ".$stmt->error);
      }
      $stmt->close();
    }

    header("Location: ".safe_return_url($_SERVER["PHP_SELF"]));
    exit;
  }

  // -------- Eliminar tarea
  if ($_POST["action"] === "task_delete") {
    $taskId = (int)($_POST["task_id"] ?? 0);
    if ($taskId <= 0) {
      flash_set("flash_err", "Tarea inválida.");
      header("Location: ".safe_return_url($_SERVER["PHP_SELF"]));
      exit;
    }

    $idRegistro = null; $titulo = "";
    $st = $c->prepare("SELECT id_registro, titulo FROM crm_tareas WHERE id=? LIMIT 1");
    if ($st) {
      $st->bind_param("i", $taskId);
      $st->execute();
      $r = $st->get_result();
      if ($r && ($row = $r->fetch_assoc())) {
        $idRegistro = $row["id_registro"];
        $titulo = (string)($row["titulo"] ?? "");
      }
      $st->close();
    }

    $stmt = $c->prepare("DELETE FROM crm_tareas WHERE id=? LIMIT 1");
    if ($stmt) {
      $stmt->bind_param("i", $taskId);
      if ($stmt->execute()) {
        audit_add($c, "tarea", $idRegistro ?? (string)$taskId, "delete", "Tarea eliminada", [
          "task_id" => $taskId,
          "titulo" => $titulo
        ], $ADMIN_USER);
        flash_set("flash_ok", "Tarea eliminada.");
      } else {
        flash_set("flash_err", "No se pudo eliminar: ".$stmt->error);
      }
      $stmt->close();
    }

    header("Location: ".safe_return_url($_SERVER["PHP_SELF"]));
    exit;
  }
}

// 4) MANTENIMIENTO: Backup
if (isset($_POST["action"]) && $_POST["action"] === "maintenance_backup") {

  $tablesToBackup = [
    $table_name,
    "crm_estados_inscripciones",
    "crm_comunicaciones",
    "crm_tareas",
    "crm_historial_cambios",
    "crm_mantenimiento_log",
  ];

  $outFile = "";
  $outBytes = 0;
  $outErr = "";

  $ok = maintenance_make_backup($c, $db_name, $tablesToBackup, $BACKUP_DIR, $outFile, $outBytes, $outErr);

  if ($ok) {
    maintenance_log_add($c, "backup", $outFile, $outBytes, "ok", "Backup generado correctamente.", $ADMIN_USER);
    flash_set("flash_ok", "Backup creado: ".$outFile." (".bytesToHuman((int)$outBytes).").");
  } else {
    maintenance_log_add($c, "backup", null, null, "error", $outErr, $ADMIN_USER);
    flash_set("flash_err", "No se pudo crear el backup: ".$outErr);
  }

  header("Location: ".$_SERVER["PHP_SELF"]."?page=ops&tab=mantenimiento&do=create");
  exit;
}

// 5) CRUD CREATE / UPDATE / DELETE
// (Al ser muy largo y usar metadatos, se suele procesar con los metadatos cargados,
//  pero aquí hacemos una carga ad-hoc de metadatos o lo dejamos para después.
//  Para mantenerlo limpio, lo procesaremos aquí, duplicando la lógica de cargar metadatos,
//  o incluiremos un 'crud_actions.php' auxiliar.
//  Por simplicidad, lo metemos aquí, cargando metadatos mínimos necesarios)

if ($loggedIn && isset($_POST["action"]) && in_array($_POST["action"], ["crud_create", "crud_update", "crud_delete"])) {

  // Cargar info de columnas
  $cols        = [];
  $blobCols    = [];
  $autoIncCols = [];
  $colsMeta    = [];
  $primaryKeyColumn = null;
  $primaryKeyIsAuto = false;

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

  // All Cols
  $r = $c->query("
    SELECT
      COLUMN_NAME, DATA_TYPE, COLUMN_TYPE,
      IS_NULLABLE, COLUMN_DEFAULT, EXTRA
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
    if (stripos((string)($f["EXTRA"] ?? ""), "auto_increment") !== false) $autoIncCols[$col] = true;
    if (is_blob_datatype($dt)) $blobCols[$col] = true;
  }

  // --- DELETE ---
  if ($_POST["action"] === "crud_delete") {
    if (!$primaryKeyColumn) {
      flash_set("flash_err", "No hay clave primaria detectada; no se puede eliminar.");
      header("Location: " . $_SERVER["PHP_SELF"]);
      exit;
    }
    $id = (string)($_POST["id"] ?? "");
    if ($id === "") {
      flash_set("flash_err", "Falta el ID a eliminar.");
      header("Location: " . $_SERVER["PHP_SELF"]);
      exit;
    }

    $safeReturn = safe_return_url($_SERVER["PHP_SELF"]);

    $ok = true;
    $affectedMain = 0;

    try {
      $c->begin_transaction();

      // Borrar tablas auxiliares
      $tablesAux = ["crm_comunicaciones", "crm_estados_inscripciones", "crm_tareas"];
      foreach($tablesAux as $ta){
         if(!$ok) break;
         $st = $c->prepare("DELETE FROM $ta WHERE id_registro = ?");
         if(!$st) { $ok=false; break; }
         $st->bind_param("s", $id);
         $ok = $st->execute();
         $st->close();
      }

      if ($ok) {
        $stmt = $c->prepare("DELETE FROM `$table_name` WHERE `$primaryKeyColumn` = ? LIMIT 1");
        if (!$stmt) { $ok = false; }
        else {
          $stmt->bind_param("s", $id);
          $ok = $stmt->execute();
          $affectedMain = (int)$stmt->affected_rows;
          $stmt->close();
        }
      }

      if ($ok && $affectedMain > 0) {
        $c->commit();
        flash_set("flash_ok", "Inscripción eliminada correctamente (ID #".$id.").");
      } else {
        $c->rollback();
        if (!$ok) flash_set("flash_err", "No se pudo eliminar (error BD).");
        else flash_set("flash_err", "No se eliminó: registro no encontrado (ID #".$id.").");
      }
    } catch (Throwable $e) {
      @($c->rollback());
      flash_set("flash_err", "No se pudo eliminar: ".$e->getMessage());
    }

    header("Location: " . $safeReturn);
    exit;
  }

  // --- CREATE ---
  if ($_POST["action"] === "crud_create") {
    $createOld = (isset($_POST["c"]) && is_array($_POST["c"])) ? $_POST["c"] : [];
    $createErrors = [];

    $insertCols = [];
    foreach ($cols as $col) {
      if (isset($autoIncCols[$col])) continue;
      $extra = (string)($colsMeta[$col]["EXTRA"] ?? "");
      if (stripos($extra, "GENERATED") !== false) continue;
      $insertCols[] = $col;
    }

    $sqlCols = []; $sqlQs = []; $types = ""; $values = []; $blobParamIdx = [];

    foreach ($insertCols as $col) {
      $meta = $colsMeta[$col] ?? [];
      $dt   = strtolower((string)($meta["DATA_TYPE"] ?? ""));
      $ct   = (string)($meta["COLUMN_TYPE"] ?? "");
      $isNullable = strtoupper((string)($meta["IS_NULLABLE"] ?? "YES")) === "YES";
      $default    = $meta["COLUMN_DEFAULT"];

      if (isset($blobCols[$col])) {
        $err  = $_FILES["b"]["error"][$col] ?? UPLOAD_ERR_NO_FILE;
        $tmp  = $_FILES["b"]["tmp_name"][$col] ?? null;
        $bin = null;
        if ($err === UPLOAD_ERR_OK && $tmp && is_uploaded_file($tmp)) {
          $bin = file_get_contents($tmp);
          if ($bin === false) $bin = null;
        } elseif ($err !== UPLOAD_ERR_NO_FILE && $err !== UPLOAD_ERR_OK) {
          $createErrors[] = "Error subiendo archivo en: ".labelize($col);
        }

        if ($bin === null) {
          if ($default !== null) continue;
          if ($isNullable) {
            $sqlCols[] = "`$col`"; $sqlQs[] = "?"; $types .= "s"; $values[] = null;
          } else {
            $createErrors[] = "El campo '".labelize($col)."' es obligatorio (archivo).";
          }
        } else {
          $sqlCols[] = "`$col`"; $sqlQs[] = "?"; $types .= "b"; $values[] = $bin;
          $blobParamIdx[] = count($values) - 1;
        }
        continue;
      }

      if (is_boolish_col($meta)) {
        $raw = $createOld[$col] ?? "0";
        $sqlCols[] = "`$col`"; $sqlQs[] = "?"; $types .= "i"; $values[] = (int)$raw;
        continue;
      }

      if ($dt === "enum") {
        $val  = isset($createOld[$col]) ? (string)$createOld[$col] : "";
        $opts = parse_enum_options($ct);
        if ($val === "") {
          if ($default !== null) continue;
          if ($isNullable) {
            $sqlCols[]="`$col`"; $sqlQs[]="?"; $types.="s"; $values[] = null;
          } else {
            $createErrors[] = "El campo '".labelize($col)."' es obligatorio.";
          }
        } else {
          if (!in_array($val, $opts, true)) {
            $createErrors[] = "Valor inválido en '".labelize($col)."' (ENUM).";
          } else {
            $sqlCols[]="`$col`"; $sqlQs[]="?"; $types.="s"; $values[] = $val;
          }
        }
        continue;
      }

      $val = isset($createOld[$col]) ? trim((string)$createOld[$col]) : "";
      if ($val === "") {
        if ($default !== null) continue;
        if ($isNullable) {
          $sqlCols[]="`$col`"; $sqlQs[]="?"; $types.="s"; $values[] = null;
        } else {
          $createErrors[] = "El campo '".labelize($col)."' es obligatorio.";
        }
        continue;
      }

      if (in_array($dt, ["int","bigint","smallint","mediumint","tinyint"], true)) {
        $sqlCols[]="`$col`"; $sqlQs[]="?"; $types.="i"; $values[] = (int)$val;
        continue;
      }
      if (in_array($dt, ["decimal","float","double"], true)) {
        $sqlCols[]="`$col`"; $sqlQs[]="?"; $types.="d"; $values[] = (float)$val;
        continue;
      }
      if (in_array($dt, ["date","datetime","timestamp","time","year"], true)) {
        $sqlCols[]="`$col`"; $sqlQs[]="?"; $types.="s"; $values[] = $val;
        continue;
      }

      $sqlCols[]="`$col`"; $sqlQs[]="?"; $types.="s"; $values[] = $val;
    }

    if (!$createErrors) {
      if (!$sqlCols) {
        $createErrors[] = "No hay campos para insertar.";
      } else {
        $sql = "INSERT INTO `$table_name` (".implode(",", $sqlCols).") VALUES (".implode(",", $sqlQs).")";
        $stmt = $c->prepare($sql);
        if (!$stmt) {
          $createErrors[] = "Error preparando INSERT: ".$c->error;
        } else {
          $bindParams = []; $bindParams[] = $types;
          for ($i=0; $i<count($values); $i++) $bindParams[] = &$values[$i];
          call_user_func_array([$stmt, "bind_param"], $bindParams);
          foreach($blobParamIdx as $idx) $stmt->send_long_data($idx, $values[$idx]);

          $ok = $stmt->execute();
          $err = $stmt->error;
          $stmt->close();

          if (!$ok) {
            $createErrors[] = "No se pudo crear: ".$err;
          } else {
            flash_set("flash_ok", "Inscripción creada correctamente.");
            if ($primaryKeyColumn && $primaryKeyIsAuto) {
              $newId = (string)$c->insert_id;
              header("Location: ".$_SERVER["PHP_SELF"]."?view=".urlencode($newId));
            } else {
              header("Location: ".$_SERVER["PHP_SELF"]);
            }
            exit;
          }
        }
      }
    }
    // Si hay errores, se guardan en flash para mostrarlos en el form
    if ($createErrors) flash_set("flash_err", implode(" | ", $createErrors));
    // Y redirigimos de vuelta al form (que está en ops) con los datos viejos?
    // En tu diseño original, si hay error, se quedaba en la página mostrando el error.
    // Como aqui hay POST redirect, necesitamos pasar los datos viejos. O bien no redirect.
    // PARA SIMPLIFICAR: No hacemos redirect si hay error en create/update, sino que dejamos caer
    // la ejecución. PERO el diseño "includes/actions.php" implica que esto corre AL PRINCIPIO.
    // Si no hacemos redirect, la página se renderizará con el error.
    // PERO necesitamos pasar $createOld para rellenar los inputs.
    // Como actions.php se incluye en admin.php, las variables $createErrors y $createOld
    // estarán disponibles para la vista si NO hacemos exit.
    // Asi que si hay errores, NO exit.
  }

  // --- UPDATE ---
  if ($_POST["action"] === "crud_update") {
     // Similar lógica a CREATE (omitida por brevedad exhaustiva, pero necesaria).
     // Implementaremos la lógica completa de update igual que en el original.
     if (!$primaryKeyColumn) $editErrors[] = "No hay PK.";
     $id = (string)($_POST["id"] ?? "");
     if ($id === "") $editErrors[] = "Falta el ID.";

     $editOld = (isset($_POST["c"]) && is_array($_POST["c"])) ? $_POST["c"] : [];
     $rm      = (isset($_POST["rm"]) && is_array($_POST["rm"])) ? $_POST["rm"] : [];
     $editErrors = []; // reiniciar

     $updateCols = [];
     foreach ($cols as $col) {
       if ($col === $primaryKeyColumn) continue;
       if (isset($autoIncCols[$col])) continue;
       $extra = (string)($colsMeta[$col]["EXTRA"] ?? "");
       if (stripos($extra, "GENERATED") !== false) continue;
       $updateCols[] = $col;
     }

     $setParts = []; $types = ""; $values = []; $blobParamIdx = [];

     foreach ($updateCols as $col) {
        $meta = $colsMeta[$col] ?? [];
        $dt   = strtolower((string)($meta["DATA_TYPE"] ?? ""));
        $ct   = (string)($meta["COLUMN_TYPE"] ?? "");
        $isNullable = strtoupper((string)($meta["IS_NULLABLE"] ?? "YES")) === "YES";

        if (isset($blobCols[$col])) {
          $wantRemove = isset($rm[$col]) && (string)$rm[$col] === "1";
          if ($wantRemove) {
            if ($isNullable) { $setParts[] = "`$col` = NULL"; }
            else { $editErrors[] = "No puedes eliminar '".labelize($col)."' (NOT NULL)."; }
            continue;
          }
          $err = $_FILES["b"]["error"][$col] ?? UPLOAD_ERR_NO_FILE;
          $tmp = $_FILES["b"]["tmp_name"][$col] ?? null;
          if ($err === UPLOAD_ERR_NO_FILE) continue; 
          if ($err === UPLOAD_ERR_OK && $tmp && is_uploaded_file($tmp)) {
            $bin = file_get_contents($tmp);
            if ($bin === false) $bin = null;
            if ($bin === null) $editErrors[] = "Error leyendo '".labelize($col)."'.";
            else {
              $setParts[] = "`$col` = ?"; $types .= "b"; $values[] = $bin; 
              $blobParamIdx[] = count($values) - 1;
            }
          } else {
            $editErrors[] = "Error subiendo archivo '".labelize($col)."'.";
          }
          continue;
        }

        if (is_boolish_col($meta)) {
          $raw = $editOld[$col] ?? "0";
          $setParts[] = "`$col` = ?"; $types .= "i"; $values[] = (int)$raw;
          continue;
        }

        if ($dt === "enum") {
          $val  = isset($editOld[$col]) ? (string)$editOld[$col] : "";
          $opts = parse_enum_options($ct);
          if ($val === "") {
            if ($isNullable) { $setParts[] = "`$col` = NULL"; }
            else { $editErrors[] = "Campo '".labelize($col)."' obligatorio."; }
          } else {
            if (!in_array($val, $opts, true)) { $editErrors[] = "Valor inválido '".labelize($col)."' (ENUM)."; }
            else { $setParts[] = "`$col` = ?"; $types .= "s"; $values[] = $val; }
          }
          continue;
        }

        $val = isset($editOld[$col]) ? trim((string)$editOld[$col]) : "";
        if ($val === "") {
          if ($isNullable) { $setParts[] = "`$col` = NULL"; }
          else { $editErrors[] = "Campo '".labelize($col)."' obligatorio."; }
          continue;
        }

        if (in_array($dt, ["int","bigint","smallint","mediumint","tinyint"], true)) {
          $setParts[] = "`$col` = ?"; $types .= "i"; $values[] = (int)$val;
          continue;
        }
        if (in_array($dt, ["decimal","float","double"], true)) {
          $setParts[] = "`$col` = ?"; $types .= "d"; $values[] = (float)$val;
          continue;
        }
        $setParts[] = "`$col` = ?"; $types .= "s"; $values[] = $val;
     }

     if (!$editErrors) {
       if (!$setParts) { $editErrors[] = "No hay cambios."; }
       else {
         $sql = "UPDATE `$table_name` SET ".implode(", ", $setParts)." WHERE `$primaryKeyColumn` = ? LIMIT 1";
         $stmt = $c->prepare($sql);
         if (!$stmt) { $editErrors[] = "Error prepare UPDATE: ".$c->error; }
         else {
           $types .= "s"; $values[] = $id;
           $bindParams = []; $bindParams[] = $types;
           for ($i=0; $i<count($values); $i++) $bindParams[] = &$values[$i];
           call_user_func_array([$stmt, "bind_param"], $bindParams);
           foreach($blobParamIdx as $idx) $stmt->send_long_data($idx, $values[$idx]);
           
           $ok = $stmt->execute();
           $err = $stmt->error;
           $stmt->close();
           if (!$ok) { $editErrors[] = "Error update: ".$err; }
           else {
             flash_set("flash_ok", "Inscripción actualizada (ID #".$id.").");
             header("Location: ".$_SERVER["PHP_SELF"]."?view=".urlencode($id));
             exit;
           }
         }
       }
     }
     if ($editErrors) flash_set("flash_err", implode(" | ", $editErrors));
     // No exit, para que se muestre el formulario con los errores
  }
}
