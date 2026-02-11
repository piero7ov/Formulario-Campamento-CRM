<?php
session_start();

/* =========================================================
   CONFIG EMAIL (Gmail IMAP + SMTP)
========================================================= */
$hostname = '{imap.gmail.com:993/imap/ssl}INBOX';
$username = '[EMAIL_ADDRESS]';
$password = '';

$smtpHost = 'smtp.gmail.com';
$smtpPort = 587; // STARTTLS
$smtpUser = $username;
$smtpPass = $password;

$fromEmail = $username;
$fromName  = 'Panel Campamento';

/* =========================================================
   CONFIG BD
========================================================= */
$db_name    = "campamento_verano";
$table_name = "inscripciones_campamento";

$c = new mysqli("localhost", "campamento_verano", "campamento_verano", $db_name);
if ($c->connect_error) {
  die("Error de conexión: " . $c->connect_error);
}
$c->set_charset("utf8mb4");

/* =========================================================
   LOGIN ADMIN (mismo archivo)
========================================================= */
$ADMIN_USER = "";
$ADMIN_PASS = "";

/* =========================================================
   TABLAS AUXILIARES (NO tocan tu tabla inscripciones)
========================================================= */
$c->query("
  CREATE TABLE IF NOT EXISTS crm_estados_inscripciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_registro VARCHAR(255) NOT NULL UNIQUE,
    estado VARCHAR(50) NOT NULL,
    color  VARCHAR(20) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

$c->query("
  CREATE TABLE IF NOT EXISTS crm_comunicaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_registro VARCHAR(255) NOT NULL,
    email_usuario VARCHAR(255) NOT NULL,
    direccion VARCHAR(20) NOT NULL,  -- sent
    asunto VARCHAR(255) NULL,
    cuerpo MEDIUMTEXT NULL,
    meta MEDIUMTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (id_registro),
    INDEX (email_usuario),
    INDEX (direccion)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

/* =========================================================
   ESTADOS CRM + COLORES
========================================================= */
$estadosCRM = [
  "Nuevo"             => "#0ea5e9",
  "Contactado"        => "#14b8a6",
  "En seguimiento"    => "#f59e0b",
  "Pendiente de pago" => "#fb923c",
  "Pagado"            => "#22c55e",
  "Cancelado"         => "#ef4444",
  "Completado"        => "#6366f1",
];

/* =========================================================
   HELPERS (comentados)
========================================================= */

/**
 * Escape seguro para HTML.
 * Evita XSS cuando imprimes contenido de BD/IMAP.
 */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

/**
 * Convierte nombres de columna técnicos en etiquetas legibles.
 * - Quita guiones/underscores
 * - Pone espacios
 * - Pone primera letra mayúscula
 * Ej: "fecha_inscripcion" -> "Fecha inscripcion"
 */
function labelize($field){
  $t = (string)$field;
  $t = str_replace(['_', '-'], ' ', $t);
  $t = preg_replace('/\s+/', ' ', $t);
  $t = trim($t);
  if ($t === '') return '';
  $t = mb_strtolower($t, 'UTF-8');
  $t = mb_strtoupper(mb_substr($t, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($t, 1, null, 'UTF-8');
  return $t;
}

/**
 * Flash messages:
 * guardas en $_SESSION y se muestran 1 vez.
 */
function flash_set($key, $val){ $_SESSION[$key] = (string)$val; }
function flash_get($key){
  if (!isset($_SESSION[$key])) return "";
  $v = (string)$_SESSION[$key];
  unset($_SESSION[$key]);
  return $v;
}

/**
 * Detecta columna email/correo dentro de una lista de columnas.
 */
function findEmailColumn(array $cols){
  foreach($cols as $col){
    $lc = strtolower($col);
    if (strpos($lc, 'email') !== false || strpos($lc, 'mail') !== false || strpos($lc, 'correo') !== false) {
      return $col;
    }
  }
  return null;
}

/**
 * Decodifica headers MIME (asuntos con codificación).
 */
function decodeMime($str){
  if (!is_string($str) || $str === '') return '';
  if (!function_exists('imap_mime_header_decode')) return $str;

  $out = '';
  foreach (imap_mime_header_decode($str) as $part) {
    $ch = $part->charset ?? 'default';
    $tx = $part->text ?? '';
    if ($ch !== 'default' && function_exists('iconv')) {
      $out .= @iconv($ch, 'UTF-8//IGNORE', $tx);
    } else {
      $out .= $tx;
    }
  }
  return $out;
}

/**
 * Extrae emails del remitente desde header IMAP (header->from).
 * Devuelve emails normalizados en minúsculas: mailbox@host
 */
function imap_extract_from_emails($header){
  $emails = [];
  if ($header && !empty($header->from) && is_array($header->from)) {
    foreach ($header->from as $obj) {
      if (!empty($obj->mailbox) && !empty($obj->host)) {
        $emails[] = strtolower(trim($obj->mailbox . '@' . $obj->host));
      }
    }
  }
  return array_values(array_unique($emails));
}

/* ---------------- SMTP sin librerías (STARTTLS) ---------------- */

/**
 * Lee respuesta SMTP (soporta respuestas multi-línea).
 */
function smtp_read($fp){
  $data = '';
  while (!feof($fp)) {
    $line = fgets($fp, 515);
    if ($line === false) break;
    $data .= $line;
    if (preg_match('/^\d{3}\s/', $line)) break;
  }
  return $data;
}

/**
 * Envía comando SMTP y valida código esperado.
 * Devuelve [ok, resp]
 */
function smtp_cmd($fp, $cmd, $expectCode = null){
  if ($cmd !== null) fwrite($fp, $cmd . "\r\n");
  $resp = smtp_read($fp);
  if ($expectCode !== null) {
    $code = (int)substr($resp, 0, 3);
    if ($code !== (int)$expectCode) return [false, $resp];
  }
  return [true, $resp];
}

/**
 * Codifica cabeceras MIME para tildes/ñ.
 */
function mime_header($text){
  if (function_exists('mb_encode_mimeheader')) {
    return mb_encode_mimeheader($text, 'UTF-8', 'B', "\r\n");
  }
  return $text;
}

/**
 * Envío SMTP (Gmail) usando STARTTLS + AUTH LOGIN.
 * No usa librerías.
 */
function smtp_send_gmail($host, $port, $user, $pass, $fromEmail, $fromName, $to, $subject, $body, &$err){
  $err = "";

  $fp = stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 20);
  if (!$fp) { $err = "SMTP connect error: $errstr ($errno)"; return false; }
  stream_set_timeout($fp, 20);

  [$ok, $resp] = smtp_cmd($fp, null, 220);
  if(!$ok){ $err = "SMTP greeting: $resp"; fclose($fp); return false; }

  [$ok, $resp] = smtp_cmd($fp, "EHLO localhost", 250);
  if(!$ok){ $err = "SMTP EHLO: $resp"; fclose($fp); return false; }

  [$ok, $resp] = smtp_cmd($fp, "STARTTLS", 220);
  if(!$ok){ $err = "SMTP STARTTLS: $resp"; fclose($fp); return false; }

  if(!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)){
    $err = "No se pudo iniciar TLS.";
    fclose($fp);
    return false;
  }

  [$ok, $resp] = smtp_cmd($fp, "EHLO localhost", 250);
  if(!$ok){ $err = "SMTP EHLO TLS: $resp"; fclose($fp); return false; }

  [$ok, $resp] = smtp_cmd($fp, "AUTH LOGIN", 334);
  if(!$ok){ $err = "SMTP AUTH: $resp"; fclose($fp); return false; }

  [$ok, $resp] = smtp_cmd($fp, base64_encode($user), 334);
  if(!$ok){ $err = "SMTP USER: $resp"; fclose($fp); return false; }

  [$ok, $resp] = smtp_cmd($fp, base64_encode($pass), 235);
  if(!$ok){ $err = "SMTP PASS: $resp"; fclose($fp); return false; }

  [$ok, $resp] = smtp_cmd($fp, "MAIL FROM:<{$fromEmail}>", 250);
  if(!$ok){ $err = "SMTP MAIL FROM: $resp"; fclose($fp); return false; }

  [$ok, $resp] = smtp_cmd($fp, "RCPT TO:<{$to}>", 250);
  if(!$ok){ $err = "SMTP RCPT TO: $resp"; fclose($fp); return false; }

  [$ok, $resp] = smtp_cmd($fp, "DATA", 354);
  if(!$ok){ $err = "SMTP DATA: $resp"; fclose($fp); return false; }

  $sub = mime_header($subject);

  $headers  = "From: ".mime_header($fromName)." <{$fromEmail}>\r\n";
  $headers .= "To: <{$to}>\r\n";
  $headers .= "Subject: {$sub}\r\n";
  $headers .= "MIME-Version: 1.0\r\n";
  $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
  $headers .= "Content-Transfer-Encoding: 8bit\r\n";

  $lines = preg_split("/\r\n|\n|\r/", (string)$body);
  $safeBody = "";
  foreach($lines as $ln){
    if (isset($ln[0]) && $ln[0] === '.') $ln = '.' . $ln;
    $safeBody .= $ln . "\r\n";
  }

  fwrite($fp, $headers . "\r\n" . $safeBody . "\r\n.\r\n");
  $resp = smtp_read($fp);
  if((int)substr($resp,0,3) !== 250){
    $err = "SMTP DATA end: $resp";
    fclose($fp);
    return false;
  }

  smtp_cmd($fp, "QUIT", 221);
  fclose($fp);
  return true;
}

/**
 * Devuelve el cuerpo del mensaje priorizando text/plain.
 */
function imap_get_text_body($imap, $msgno){
  $structure = @imap_fetchstructure($imap, $msgno);
  if (!$structure) return "";

  if (isset($structure->parts) && is_array($structure->parts)) {
    $text = "";

    foreach ($structure->parts as $idx => $part) {
      $isTextPlain = (isset($part->type) && (int)$part->type === 0);
      $subtype = isset($part->subtype) ? strtoupper($part->subtype) : "";

      if ($isTextPlain && $subtype === "PLAIN") {
        $partNo = (string)($idx + 1);
        $body = @imap_fetchbody($imap, $msgno, $partNo);
        if ($body === false) continue;

        $enc = $part->encoding ?? 0;
        if ((int)$enc === 3) $body = base64_decode($body);
        if ((int)$enc === 4) $body = quoted_printable_decode($body);

        $text = $body;
        break;
      }
    }

    if ($text === "") {
      $body = @imap_body($imap, $msgno);
      if ($body !== false) $text = $body;
    }

    return trim((string)$text);
  }

  $body = @imap_body($imap, $msgno);
  if ($body === false) return "";
  $enc = $structure->encoding ?? 0;
  if ((int)$enc === 3) $body = base64_decode($body);
  if ((int)$enc === 4) $body = quoted_printable_decode($body);
  return trim((string)$body);
}

/* =========================================================
   HELPERS EXTRA: BLOB thumbs + SELECT projection
========================================================= */

/**
 * Detecta si un DATA_TYPE de MySQL es tipo BLOB.
 */
function is_blob_datatype($dt){
  $dt = strtolower((string)$dt);
  return in_array($dt, ['blob','mediumblob','longblob','tinyblob'], true);
}

/**
 * Detecta MIME de imagen por "magic bytes" (PNG/JPG).
 */
function detect_image_mime($bin){
  if (!is_string($bin) || $bin === '') return 'application/octet-stream';

  // JPEG: FF D8 FF
  if (substr($bin, 0, 3) === "\xFF\xD8\xFF") return 'image/jpeg';

  // PNG: 89 50 4E 47 0D 0A 1A 0A
  if (substr($bin, 0, 8) === "\x89PNG\x0D\x0A\x1A\x0A") return 'image/png';

  return 'application/octet-stream';
}

/**
 * Proyección SELECT: NO trae bytes de BLOB, solo su tamaño.
 * - Columnas normales: `col`
 * - BLOB: OCTET_LENGTH(`col`) AS `__len_col`
 */
function build_select_projection(array $cols, array $blobCols){
  $parts = [];
  foreach($cols as $col){
    if (isset($blobCols[$col])) {
      $parts[] = "OCTET_LENGTH(`$col`) AS `__len_$col`";
    } else {
      $parts[] = "`$col`";
    }
  }
  return implode(", ", $parts);
}

/* =========================================================
   DETECTAR PRIMARY KEY (para identificar cada fila)
========================================================= */
$primaryKeyColumn = null;
$pkResult = $c->query("
  SELECT COLUMN_NAME
  FROM information_schema.columns
  WHERE table_schema = '".$c->real_escape_string($db_name)."'
    AND table_name   = '".$c->real_escape_string($table_name)."'
    AND COLUMN_KEY   = 'PRI'
  LIMIT 1
");
if ($pkResult && $pkResult->num_rows > 0) {
  $primaryKeyColumn = $pkResult->fetch_assoc()["COLUMN_NAME"];
}

/* =========================================================
   LOGOUT
========================================================= */
if (isset($_GET["logout"])) {
  session_destroy();
  header("Location: " . $_SERVER["PHP_SELF"]);
  exit;
}

/* =========================================================
   LOGIN
========================================================= */
$login_error = "";
if (isset($_POST["action"]) && $_POST["action"] === "login") {
  $u = $_POST["usuario"] ?? "";
  $p = $_POST["password"] ?? "";
  if ($u === $ADMIN_USER && $p === $ADMIN_PASS) {
    $_SESSION["admin_logged"] = true;
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
  } else {
    $login_error = "Usuario o contraseña incorrectos.";
  }
}
$loggedIn = !empty($_SESSION["admin_logged"]);

/* =========================================================
   MENSAJES (flash + normales)
========================================================= */
$panel_msg = flash_get("flash_ok");
$panel_err = flash_get("flash_err");

/* =========================================================
   UPDATE ESTADO (si está logueado)
========================================================= */
if ($loggedIn && isset($_POST["action"]) && $_POST["action"] === "update_estado") {
  $id_registro = $_POST["id_registro"] ?? null;
  $estado      = $_POST["estado"]      ?? null;

  if ($id_registro !== null && isset($estadosCRM[$estado])) {
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
        $panel_msg = "Estado actualizado (ID #".h($id_registro).").";
      }
      $stmt->close();
    }
  }
}

/* =========================================================
   ENVIAR EMAIL (SMTP) + guardar log en BD (sent)
========================================================= */
if ($loggedIn && isset($_POST["action"]) && $_POST["action"] === "send_email") {
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

/* =========================================================
   ESTADOS ACTUALES (para pintar la tabla)
========================================================= */
$estadosActuales = [];
if ($loggedIn) {
  $resEstados = $c->query("SELECT id_registro, estado, color FROM crm_estados_inscripciones");
  if ($resEstados) {
    while ($row = $resEstados->fetch_assoc()) {
      $estadosActuales[$row["id_registro"]] = $row;
    }
  }
}

/* =========================================================
   COLUMNAS DE INSCRIPCIONES + detectar columna email + BLOB
========================================================= */
$cols = [];
$emailCol = null;

// columnas BLOB (para miniaturas) y columnas normales (ordenables / filtrables)
$blobCols     = []; // ej: ["documento"=>true]
$sortableCols = []; // ej: ["nombre"=>true, "fecha_inscripcion"=>true]

if ($loggedIn) {
  $r = $c->query("
    SELECT COLUMN_NAME, DATA_TYPE
    FROM information_schema.columns
    WHERE table_schema='".$c->real_escape_string($db_name)."'
      AND table_name='".$c->real_escape_string($table_name)."'
    ORDER BY ORDINAL_POSITION
  ");

  while($r && ($f = $r->fetch_assoc())){
    $col = $f["COLUMN_NAME"];
    $dt  = $f["DATA_TYPE"] ?? "";

    $cols[] = $col;

    if (is_blob_datatype($dt)) {
      $blobCols[$col] = true;
    } else {
      $sortableCols[$col] = true; // no blobs
    }
  }

  $emailCol = findEmailColumn($cols);
}

/* =========================================================
   SORTING SIMPLE (1 columna) - click cabeceras
========================================================= */
$sortCol = $loggedIn ? (string)($_GET["sort"] ?? "") : "";
$sortDir = $loggedIn ? strtolower((string)($_GET["dir"] ?? "asc")) : "asc";

if (!isset($sortableCols[$sortCol])) $sortCol = "";
$sortDir = ($sortDir === "desc") ? "desc" : "asc";

/* =========================================================
   FILTROS MULTI-CRITERIO (mini inputs por columna)
   - f[col]=texto (no BLOB)
   - fb[col]=1/0 (BLOB: con doc / sin doc)
========================================================= */
$filtersText = ($loggedIn && isset($_GET["f"]) && is_array($_GET["f"])) ? $_GET["f"] : [];
$filtersBlob = ($loggedIn && isset($_GET["fb"]) && is_array($_GET["fb"])) ? $_GET["fb"] : [];

// Normaliza strings
foreach($filtersText as $k => $v){
  if (!is_string($k)) { unset($filtersText[$k]); continue; }
  $filtersText[$k] = trim((string)$v);
}
foreach($filtersBlob as $k => $v){
  if (!is_string($k)) { unset($filtersBlob[$k]); continue; }
  $filtersBlob[$k] = trim((string)$v); // "1" / "0" / ""
}

/* =========================================================
   ENDPOINT IMAGEN (BLOB -> thumbnail/full)
   Uso: ?img=1&id=XXX&col=nombre_blob[&download=1]
========================================================= */
if ($loggedIn && isset($_GET["img"]) && $_GET["img"] == "1") {
  if (!$primaryKeyColumn) { http_response_code(400); exit; }

  $id  = (string)($_GET["id"]  ?? "");
  $col = (string)($_GET["col"] ?? "");

  if ($id === "" || $col === "" || !isset($blobCols[$col])) {
    http_response_code(400);
    exit;
  }

  $sql = "SELECT `$col` AS bin FROM `$table_name` WHERE `$primaryKeyColumn` = ? LIMIT 1";
  $stmt = $c->prepare($sql);
  if (!$stmt) { http_response_code(500); exit; }

  $stmt->bind_param("s", $id);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();

  if (!$row || !isset($row["bin"]) || $row["bin"] === null || $row["bin"] === "") {
    http_response_code(404);
    exit;
  }

  $bin  = $row["bin"];
  $mime = detect_image_mime($bin);

  header("Content-Type: ".$mime);
  header("Cache-Control: private, max-age=86400");

  if (isset($_GET["download"]) && $_GET["download"] == "1") {
    $ext = ($mime === "image/png") ? "png" : (($mime === "image/jpeg") ? "jpg" : "bin");
    $safe = preg_replace('/[^a-z0-9_\-]/i', '_', $col);
    header('Content-Disposition: attachment; filename="'.$safe.'_'.$id.'.'.$ext.'"');
  }

  echo $bin;
  exit;
}

/* =========================================================
   EMAILS NO LEÍDOS (UNSEEN) POR CONTACTO
========================================================= */
$unreadCountByEmail = [];

if ($loggedIn && $emailCol !== null && function_exists('imap_open')) {
  $imap = @imap_open($hostname, $username, $password);

  if ($imap) {
    $unseenIds = @imap_search($imap, 'UNSEEN', SE_FREE, "UTF-8");

    if (is_array($unseenIds)) {
      foreach ($unseenIds as $num) {
        $header = @imap_headerinfo($imap, $num);
        $fromEmails = imap_extract_from_emails($header);

        foreach ($fromEmails as $fromEmailAddr) {
          if ($fromEmailAddr === strtolower($username)) continue;
          if (!isset($unreadCountByEmail[$fromEmailAddr])) $unreadCountByEmail[$fromEmailAddr] = 0;
          $unreadCountByEmail[$fromEmailAddr] += 1;
        }
      }
    }

    if (function_exists('imap_errors')) imap_errors();
    if (function_exists('imap_alerts')) imap_alerts();

    @imap_close($imap);
  }
}

/* =========================================================
   VISTA INFORME + comunicaciones
========================================================= */
$viewId = ($loggedIn && isset($_GET["view"])) ? (string)$_GET["view"] : null;
$viewRow = null;

$sentLogs = [];
$receivedLogs = [];
$imapWarning = "";
$commsUpdatedAt = date("Y-m-d H:i:s");

if ($loggedIn && $viewId !== null && $viewId !== "") {

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

  $stmt = $c->prepare("
    SELECT asunto, cuerpo, created_at
    FROM crm_comunicaciones
    WHERE id_registro = ? AND direccion='sent'
    ORDER BY created_at DESC
    LIMIT 30
  ");
  if ($stmt) {
    $stmt->bind_param("s", $viewId);
    $stmt->execute();
    $res = $stmt->get_result();
    while($res && ($row = $res->fetch_assoc())){
      $sentLogs[] = $row;
    }
    $stmt->close();
  }

  if (!$emailCol) {
    $imapWarning = "No se detectó una columna de email (email/correo).";
  } else if (!$viewRow || empty($viewRow[$emailCol])) {
    $imapWarning = "Esta inscripción no tiene email para buscar mensajes.";
  } else if (!function_exists("imap_open")) {
    $imapWarning = "La extensión de correo no está habilitada en PHP (IMAP).";
  } else {
    $userEmail = trim((string)$viewRow[$emailCol]);
    $safeEmail = str_replace('"', '', $userEmail);

    $imap = @imap_open($hostname, $username, $password);

    if (!$imap) {
      $imapWarning = "No se pudo acceder al correo: " . (function_exists('imap_last_error') ? (imap_last_error() ?: "sin detalle") : "sin detalle");
    } else {

      $buscarEnMailbox = function($mailbox) use ($imap, $safeEmail){
        @imap_reopen($imap, $mailbox);

        $ids = [];

        $a = @imap_search($imap, 'FROM "'.$safeEmail.'"', SE_FREE, "UTF-8");
        if (is_array($a)) $ids = array_merge($ids, $a);

        $b = @imap_search($imap, 'TO "'.$safeEmail.'"', SE_FREE, "UTF-8");
        if (is_array($b)) $ids = array_merge($ids, $b);

        if (empty($ids)) {
          $t = @imap_search($imap, 'TEXT "'.$safeEmail.'"', SE_FREE, "UTF-8");
          if (is_array($t)) $ids = array_merge($ids, $t);
        }

        $ids = array_values(array_unique($ids));
        rsort($ids);
        return array_slice($ids, 0, 20);
      };

      $ids = $buscarEnMailbox($hostname);

      if (empty($ids)) {
        $allMail = preg_replace('~INBOX$~i', '[Gmail]/All Mail', $hostname);
        $ids = $buscarEnMailbox($allMail);

        if (empty($ids)) {
          $todos = preg_replace('~INBOX$~i', '[Gmail]/Todos', $hostname);
          $ids = $buscarEnMailbox($todos);
        }
      }

      foreach($ids as $msgno){
        $ov = @imap_fetch_overview($imap, $msgno, 0);
        $ov = ($ov && isset($ov[0])) ? $ov[0] : null;

        $subject = $ov ? decodeMime((string)($ov->subject ?? "")) : "";
        $from    = $ov ? decodeMime((string)($ov->from ?? ""))    : "";
        $date    = $ov ? (string)($ov->date ?? "")               : "";

        $body = imap_get_text_body($imap, $msgno);
        if (strlen($body) > 1200) $body = substr($body, 0, 1200) . "\n\n[...recortado...]";

        $receivedLogs[] = [
          "subject" => $subject,
          "from"    => $from,
          "date"    => $date,
          "body"    => $body,
        ];
      }

      if (function_exists('imap_errors')) imap_errors();
      if (function_exists('imap_alerts')) imap_alerts();

      @imap_close($imap);
    }
  }
}

if ($loggedIn && isset($_GET["refresh"]) && $_GET["refresh"] == "1") {
  if ($panel_msg === "" && $panel_err === "") {
    $panel_msg = "Comunicaciones actualizadas.";
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Panel de administración</title>

  <!-- CSS externo (mismo diseño que tenías embebido) -->
  <link rel="stylesheet" href="013_panel.css?v=1">
</head>

<body>
<?php if (!$loggedIn): ?>

  <div class="login-wrapper">
    <form method="post" class="login-card">
      <div class="logo">
        <img src="https://piero7ov.github.io/pierodev-assets/brand/pierodev/logos/logocompleto.png" alt="PieroDev logo">
      </div>

      <h2>Acceso al panel</h2>

      <?php if ($login_error !== ""): ?>
        <div class="alert-error"><?= h($login_error) ?></div>
      <?php endif; ?>

      <div class="control">
        <label for="usuario">Usuario</label>
        <input type="text" name="usuario" id="usuario" autocomplete="username" required>
      </div>

      <div class="control">
        <label for="password">Contraseña</label>
        <input type="password" name="password" id="password" autocomplete="current-password" required>
      </div>

      <input type="hidden" name="action" value="login">
      <input class="btn" type="submit" value="Entrar">
    </form>
  </div>

<?php else: ?>

  <nav>
    <h2>Panel de control</h2>
    <a class="nav-btn" href="<?= h($_SERVER["PHP_SELF"]) ?>">Listado</a>
    <button class="nav-btn" type="button">Enlace 1</button>
    <button class="nav-btn" type="button">Enlace 2</button>
    <button class="nav-btn" type="button">Enlace 3</button>
    <a class="nav-btn logout-link" href="?logout=1">Cerrar sesión</a>
  </nav>

  <main>

    <?php if ($panel_msg): ?>
      <div class="alert-ok"><?= h($panel_msg) ?></div>
    <?php endif; ?>
    <?php if ($panel_err): ?>
      <div class="alert-error"><?= h($panel_err) ?></div>
    <?php endif; ?>

    <?php if ($viewId !== null && $viewId !== ""): ?>

      <h3>Informe de inscripción (ID #<?= h($viewId) ?>)</h3>

      <?php if (!$viewRow): ?>
        <div class="alert-error">No se encontró la inscripción solicitada.</div>
        <a class="btn-link" href="<?= h($_SERVER["PHP_SELF"]) ?>">Volver al listado</a>

      <?php else: ?>

        <div class="card">
          <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center;">
            <div class="section-title">Datos de la inscripción</div>
            <a class="btn-link" href="<?= h($_SERVER["PHP_SELF"]) ?>">← Volver al listado</a>
          </div>

          <table class="kv">
            <?php foreach($cols as $col): ?>
              <tr>
                <td><?= h(labelize($col)) ?></td>
                <td>
                  <?php if (isset($blobCols[$col])): ?>
                    <?php
                      $lenKey = "__len_$col";
                      $hasImg = isset($viewRow[$lenKey]) && (int)$viewRow[$lenKey] > 0;
                    ?>
                    <?php if ($hasImg): ?>
                      <?php $src = $_SERVER["PHP_SELF"]."?img=1&id=".urlencode($viewId)."&col=".urlencode($col); ?>
                      <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
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

          <!-- ========= Comunicaciones ========= -->
          <div class="card" id="comms">
            <div style="display:flex; justify-content:space-between; gap:10px; align-items:center; flex-wrap:wrap;">
              <div class="section-title">
                Historial de comunicaciones
                <span class="tiny">Última actualización: <?= h($commsUpdatedAt) ?></span>
              </div>

              <a class="btn-link" href="?view=<?= h($viewId) ?>&refresh=1#comms">↻ Actualizar</a>
            </div>

            <?php if ($imapWarning): ?>
              <div class="alert-warn" style="margin-top:12px;"><?= h($imapWarning) ?></div>
            <?php endif; ?>

            <details open style="margin-top:12px;">
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

      <?php endif; ?>

    <?php else: ?>

      <h3>Listado de inscripciones del campamento</h3>

      <!-- Form de filtros (NO envuelve la tabla para evitar forms anidados) -->
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

            <!-- ===== fila de filtros por columna ===== -->
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
                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                  <button class="btn-link" type="submit" form="filterForm">Aplicar</button>
                  <a class="btn-link" href="<?= h($_SERVER["PHP_SELF"]) ?>">Limpiar</a>
                </div>
              </th>

              <th class="col-estado"></th>
            </tr>
          </thead>

          <tbody>
            <?php
              $projection = build_select_projection($cols, $blobCols);

              $where = ["1=1"];
              $bindTypes = "";
              $bindVals  = [];

              // filtros texto (columnas no BLOB)
              foreach($filtersText as $col => $val){
                if ($val === "") continue;
                if (!isset($sortableCols[$col])) continue; // solo columnas normales
                $where[] = "CAST(`$col` AS CHAR) LIKE ?";
                $bindTypes .= "s";
                $bindVals[] = "%".$val."%";
              }

              // filtros blob (con documento / sin documento)
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

              // ORDER BY (solo 1 columna)
              if ($sortCol !== "") {
                $sql .= " ORDER BY `$sortCol` ".strtoupper($sortDir);
              } else {
                $orderBy = $primaryKeyColumn ? $primaryKeyColumn : ($cols[0] ?? null);
                if ($orderBy) $sql .= " ORDER BY `$orderBy` DESC";
              }

              // Ejecuta con prepared
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
                    // BLOB: solo sabemos tamaño por __len_col
                    $lenKey = "__len_$col";
                    $hasImg = isset($f[$lenKey]) && (int)$f[$lenKey] > 0;

                    if ($hasImg && $idRegistro !== null) {
                      $src = $_SERVER["PHP_SELF"]."?img=1&id=".urlencode($idRegistro)."&col=".urlencode($col);
                      echo '<td class="td-blob">';
                      echo '<img class="thumb js-thumb" src="'.h($src).'" data-full="'.h($src).'" alt="'.h(labelize($col)).'">';
                      echo '</td>';
                    } else {
                      echo '<td><span style="color:#6b7280;font-weight:800;">—</span></td>';
                    }
                  } else {
                    $val = $f[$col] ?? "";
                    if ($val === null || $val === "") {
                      echo '<td><span style="color:#6b7280;font-weight:800;">—</span></td>';
                    } else {
                      echo "<td title='".h($val)."'>".h($val)."</td>";
                    }
                  }
                }

                echo '<td class="col-unread">';
                if ($rowEmailNorm && isset($unreadCountByEmail[$rowEmailNorm])) {
                  $n = (int)$unreadCountByEmail[$rowEmailNorm];
                  echo '<span class="badge-unread" title="Hay '.$n.' email(s) sin leer de este contacto.">'.$n.'</span>';
                } else {
                  echo '<span class="badge-none">—</span>';
                }
                echo '</td>';

                echo '<td class="col-acciones">';
                if ($idRegistro !== null) {
                  echo '<div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">';

                  echo '<form method="post" class="form-estado">';
                  echo '<input type="hidden" name="action" value="update_estado">';
                  echo '<input type="hidden" name="id_registro" value="'.h($idRegistro).'">';

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

                  echo '</div>';
                } else {
                  echo '<span style="color:#6b7280;font-weight:800;">—</span>';
                }
                echo '</td>';

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

              if (isset($stmtList) && $stmtList) $stmtList->close();
            ?>
          </tbody>
        </table>
      </div>

    <?php endif; ?>

  </main>

  <!-- Modal imagen -->
  <div id="imgModal" aria-hidden="true">
    <div class="modal-box">
      <button type="button" class="modal-close" data-close>✕</button>
      <img src="" alt="Documento">
    </div>
  </div>

  <script>
  (() => {
    // ===== Modal imágenes =====
    const modal = document.getElementById("imgModal");
    if (modal) {
      const modalImg = modal.querySelector("img");

      function openModal(src){
        modalImg.src = src;
        modal.classList.add("is-open");
        modal.setAttribute("aria-hidden", "false");
      }
      function closeModal(){
        modal.classList.remove("is-open");
        modal.setAttribute("aria-hidden", "true");
        modalImg.src = "";
      }

      // Delegación: cualquier .js-thumb abre modal
      document.addEventListener("click", (e) => {
        const t = e.target;
        if (t && t.classList && t.classList.contains("js-thumb")) {
          e.preventDefault();
          openModal(t.dataset.full || t.src);
        }
      });

      modal.addEventListener("click", (e) => {
        if (e.target === modal || (e.target && e.target.hasAttribute("data-close"))) closeModal();
      });

      document.addEventListener("keydown", (e) => {
        if (e.key === "Escape") closeModal();
      });
    }

    // ===== Sorting simple en TH (preserva filtros porque es URLSearchParams) =====
    const ths = document.querySelectorAll("th.sortable[data-sort-col]");
    if (ths.length) {
      ths.forEach(th => {
        th.addEventListener("click", () => {
          const col = th.dataset.sortCol;
          if (!col) return;

          const url = new URL(window.location.href);
          const sp = url.searchParams;

          const curCol = sp.get("sort") || "";
          const curDir = (sp.get("dir") || "asc").toLowerCase();

          let nextDir = "asc";
          if (curCol === col) nextDir = (curDir === "asc") ? "desc" : "asc";

          sp.set("sort", col);
          sp.set("dir", nextDir);

          // asegura vista listado
          sp.delete("view");
          sp.delete("refresh");
          url.hash = "";
          window.location.href = url.toString();
        });
      });
    }
  })();
  </script>

<?php endif; ?>
</body>
</html>
