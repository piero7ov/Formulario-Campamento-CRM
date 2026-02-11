<?php
session_start();

/* =========================================================
   CONFIG EMAIL (tus credenciales)
   ========================================================= */
$hostname = '{imap.gmail.com:993/imap/ssl}INBOX';
$username = '';
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
   TABLAS AUXILIARES (NO tocan tu tabla de inscripciones)
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
   HELPERS
   ========================================================= */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

function findEmailColumn(array $cols){
  foreach($cols as $col){
    $lc = strtolower($col);
    if (strpos($lc, 'email') !== false || strpos($lc, 'correo') !== false) return $col;
  }
  return null;
}

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

/* ---------- SMTP (Gmail) sin librerías ---------- */
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
function smtp_cmd($fp, $cmd, $expectCode = null){
  if ($cmd !== null) fwrite($fp, $cmd . "\r\n");
  $resp = smtp_read($fp);
  if ($expectCode !== null) {
    $code = (int)substr($resp, 0, 3);
    if ($code !== (int)$expectCode) return [false, $resp];
  }
  return [true, $resp];
}
function mime_header($text){
  if (function_exists('mb_encode_mimeheader')) {
    return mb_encode_mimeheader($text, 'UTF-8', 'B', "\r\n");
  }
  return $text;
}
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

/* ---------- IMAP: sacar body texto (simple) ---------- */
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
   DETECTAR PRIMARY KEY
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
   MENSAJES PANEL
   ========================================================= */
$panel_msg = "";
$panel_err = "";

/* =========================================================
   UPDATE ESTADO
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
      if ($stmt->execute()) $panel_msg = "Estado actualizado (ID #".h($id_registro).").";
      $stmt->close();
    }
  }
}

/* =========================================================
   ENVIAR EMAIL (SMTP) + guardar log en BD
   ========================================================= */
if ($loggedIn && isset($_POST["action"]) && $_POST["action"] === "send_email") {
  $id_registro = $_POST["id_registro"] ?? "";
  $to_email    = trim($_POST["to_email"] ?? "");
  $subject     = trim($_POST["subject"] ?? "");
  $message     = trim($_POST["message"] ?? "");

  if ($to_email === "" || $subject === "" || $message === "") {
    $panel_err = "Completa destinatario, asunto y mensaje.";
  } else {
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
      $panel_msg = "Correo enviado a ".h($to_email).".";
    } else {
      $panel_err = "No se pudo enviar. Detalle SMTP: ".h($smtpErr);
    }
  }
}

/* =========================================================
   ESTADOS ACTUALES (para pintar tabla)
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
   COLUMNAS DE INSCRIPCIONES
   ========================================================= */
$cols = [];
$emailCol = null;
if ($loggedIn) {
  $r = $c->query("
    SELECT COLUMN_NAME
    FROM information_schema.columns
    WHERE table_schema='".$c->real_escape_string($db_name)."'
      AND table_name='".$c->real_escape_string($table_name)."'
    ORDER BY ORDINAL_POSITION
  ");
  while($r && ($f = $r->fetch_assoc())){
    $cols[] = $f["COLUMN_NAME"];
  }
  $emailCol = findEmailColumn($cols);
}

/* =========================================================
   VISTA INFORME
   ========================================================= */
$viewId = ($loggedIn && isset($_GET["view"])) ? (string)$_GET["view"] : null;
$viewRow = null;
$sentLogs = [];
$receivedLogs = [];
$imapWarning = "";

if ($loggedIn && $viewId !== null && $viewId !== "") {

  // Cargar la fila de inscripción
  if ($primaryKeyColumn) {
    $stmt = $c->prepare("SELECT * FROM `$table_name` WHERE `$primaryKeyColumn` = ? LIMIT 1");
    if ($stmt) {
      $stmt->bind_param("s", $viewId);
      $stmt->execute();
      $res = $stmt->get_result();
      $viewRow = $res ? $res->fetch_assoc() : null;
      $stmt->close();
    }
  }

  // Enviados (desde BD)
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

  // Recibidos (IMAP) - SIN OR (evita: Unknown search criterion: OR)
  if (!$emailCol) {
    $imapWarning = "No se detectó una columna de email (email/correo).";
  } else if (!$viewRow || empty($viewRow[$emailCol])) {
    $imapWarning = "Esta inscripción no tiene email para buscar mensajes.";
  } else if (!function_exists("imap_open")) {
    $imapWarning = "La extensión IMAP no está habilitada en PHP (php-imap).";
  } else {
    $userEmail = trim((string)$viewRow[$emailCol]);
    $safeEmail = str_replace('"', '', $userEmail);

    $imap = @imap_open($hostname, $username, $password);

    if (!$imap) {
      $imapWarning = "No se pudo conectar por IMAP: " . (function_exists('imap_last_error') ? (imap_last_error() ?: "sin detalle") : "sin detalle");
    } else {

      $buscarEnMailbox = function($mailbox) use ($imap, $safeEmail){
        @imap_reopen($imap, $mailbox);

        $ids = [];

        $a = @imap_search($imap, 'FROM "'.$safeEmail.'"', SE_FREE, "UTF-8");
        if (is_array($a)) $ids = array_merge($ids, $a);

        $b = @imap_search($imap, 'TO "'.$safeEmail.'"', SE_FREE, "UTF-8");
        if (is_array($b)) $ids = array_merge($ids, $b);

        // fallback: búsqueda amplia por texto
        if (empty($ids)) {
          $t = @imap_search($imap, 'TEXT "'.$safeEmail.'"', SE_FREE, "UTF-8");
          if (is_array($t)) $ids = array_merge($ids, $t);
        }

        $ids = array_values(array_unique($ids));
        rsort($ids);
        return array_slice($ids, 0, 20);
      };

      // 1) INBOX
      $ids = $buscarEnMailbox($hostname);

      // 2) Si no hay nada, intenta All Mail / Todos (por si están archivados)
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

      // Evita notices al final del request
      if (function_exists('imap_errors')) imap_errors();
      if (function_exists('imap_alerts')) imap_alerts();

      @imap_close($imap);
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Panel de administración</title>

  <style>
    *{ box-sizing:border-box; }

    :root{
      --bg:#f5f7fb;
      --card:#ffffff;
      --text:#083136;
      --muted:#6b7280;
      --border:#e5e7eb;

      --sky: rgba(173,216,230,.28);
      --grad: linear-gradient(180deg, rgba(95,191,192,.28), rgba(173,216,230,.25));

      --shadow: 0 10px 25px rgba(0,0,0,.07);
      --radius: 16px;
      --navW: 260px;

      --ctrl-bg: rgba(255,255,255,.75);
      --ctrl-border: rgba(95,191,192,.45);
      --ctrl-text: #063b40;
    }

    body{
      margin:0;
      font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
      background: var(--bg);
      color: var(--text);
      min-height: 100vh;
    }

    /* ===== Login ===== */
    .login-wrapper{ min-height:100vh; display:grid; place-items:center; padding:24px; }
    .login-card{
      width:min(520px, 100%);
      background:#fff;
      border:1px solid var(--border);
      border-radius: var(--radius);
      padding:22px;
      box-shadow: var(--shadow);
    }
    .logo{ display:flex; justify-content:center; margin-bottom:12px; }
    .logo img{ width:min(320px, 100%); height:auto; object-fit:contain; }
    .login-card h2{
      margin: 6px 0 16px;
      text-align:center;
      font-size: 1.15rem;
      font-weight: 900;
      color:#0f172a;
    }
    .control{ margin-top: 14px; }
    label{ display:block; margin:0 0 6px; font-weight:800; color:#1f2937; }
    input[type="text"], input[type="password"], .input{
      width:100%;
      padding:12px 14px;
      border:1px solid #d1d5db;
      border-radius:12px;
      outline:none;
      font-size: 12px;
      font-family: inherit;
    }
    input:focus, .input:focus{
      border-color:#2563eb;
      box-shadow: 0 0 0 4px rgba(37,99,235,.15);
    }
    .btn{
      margin-top:18px;
      width:100%;
      padding:14px;
      border:0;
      border-radius:12px;
      font-weight: 900;
      cursor:pointer;
      color:#0f172a;
      background: var(--grad);
    }
    .alert-error{
      margin: 0 0 14px;
      padding:12px 14px;
      border-radius:12px;
      background:#fee2e2;
      border:1px solid #fecaca;
      color:#991b1b;
      font-weight: 800;
    }
    .alert-ok{
      margin: 0 0 14px;
      padding: 12px 14px;
      border-radius: 12px;
      border: 1px solid rgba(95,191,192,.35);
      background: rgba(95,191,192,.12);
      color: #063b40;
      font-weight: 900;
    }
    .alert-warn{
      margin: 0 0 14px;
      padding: 12px 14px;
      border-radius: 12px;
      border: 1px solid rgba(245,158,11,.35);
      background: rgba(245,158,11,.12);
      color: #7c4a00;
      font-weight: 900;
    }

    /* ===== Layout admin ===== */
    nav{
      width: var(--navW);
      padding: 18px;

      position: fixed;
      left:0; top:0;
      height:100vh;
      overflow:auto;

      background: var(--grad);
      border-right: 1px solid var(--border);
      box-shadow: 0 6px 18px rgba(0,0,0,.05);

      display:flex;
      flex-direction:column;
      gap:10px;
    }
    nav h2{
      margin:0;
      font-size: 18px;
      font-weight: 900;
      color:#0f172a;
      display:flex;
      gap:10px;
      align-items:center;
    }
    nav h2::before{
      content:"";
      width:10px; height:10px;
      border-radius:999px;
      background:#5fbfc0;
      flex: 0 0 auto;
    }
    .nav-btn{
      width:100%;
      padding:12px 14px;
      border: 1px solid rgba(0,0,0,.08);
      background:#fff;
      color:#0f172a;
      font-weight: 900;
      border-radius: 12px;
      cursor:pointer;
      text-decoration:none;
      text-align:center;
      display:block;
    }
    .logout-link{ margin-top:auto; }

    main{
      margin-left: var(--navW);
      padding: 24px;
      min-height: 100vh;
    }

    h3{
      margin:0 0 14px;
      font-size: 18px;
      font-weight: 900;
      color:#0f172a;
      display:flex;
      align-items:center;
      gap:10px;
    }
    h3::before{
      content:"";
      width:12px; height:12px;
      border-radius:4px;
      background:#9fd7ea;
      box-shadow: 0 0 0 4px rgba(159,215,234,.35);
      flex: 0 0 auto;
    }

    /* ===== Tabla (scroll interno) ===== */
    .table-wrap{
      width:100%;
      max-height: calc(100vh - 190px);
      overflow: auto;
      scrollbar-gutter: stable;
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      background: var(--card);
      border: 1px solid var(--border);
    }
    table{
      width: max-content;
      min-width: 100%;
      border-collapse: separate;
      border-spacing: 0;
    }
    thead th{
      position: sticky;
      top: 0;
      z-index: 2;

      text-align:left;
      padding: 8px 10px;
      font-size: 11px;
      font-weight: 900;
      color: #063b40;

      background: var(--grad);
      border-bottom: 1px solid rgba(0,0,0,.06);

      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 190px;
    }
    tbody td{
      padding: 8px 10px;
      font-size: 12px;
      color:#0f172a;

      border-top: 1px solid var(--border);
      vertical-align: top;

      max-width: 240px;
      overflow-wrap: anywhere;
      word-break: break-word;
    }
    tbody td + td,
    thead th + th{ border-left: 1px solid var(--border); }
    tbody tr:nth-child(even) td{ background: var(--sky); }
    tbody tr:hover td{ background: rgba(95,191,192,.12); }

    th.col-estado, td.col-estado{ min-width: 150px; }
    th.col-acciones, td.col-acciones{ min-width: 320px; vertical-align: middle; }

    .estado-pill{
      display:inline-flex;
      align-items:center;
      padding: 6px 10px;
      border-radius: 999px;
      font-weight: 900;
      font-size: 11px;
      border: 1px solid rgba(0,0,0,.08);
      background: rgba(255,255,255,.65);
      white-space: nowrap;
    }
    .estado-pill--vacio{
      color: var(--muted);
      border-style: dashed;
      background: transparent;
    }

    /* ===== Acciones (compacto) ===== */
    .form-estado{
      display:flex;
      gap:6px;
      align-items:center;
      flex-wrap: nowrap;
    }
    .form-estado select{
      width: 150px;
      min-width: 150px;
      padding: 6px 8px;
      border-radius: 10px;
      border: 1px solid var(--ctrl-border);
      background: var(--ctrl-bg);
      color: #0f172a;
      font-weight: 800;
      font-size: 11px;
      line-height: 1.2;
      outline:none;
      box-shadow: 0 1px 4px rgba(0,0,0,.05);
    }
    .form-estado select:focus{
      border-color: rgba(58,169,170,.65);
      box-shadow: 0 0 0 4px rgba(95,191,192,.16);
    }
    .btn-estado, .btn-link{
      padding: 6px 10px;
      border-radius: 10px;
      border: 1px solid var(--ctrl-border);
      background: var(--ctrl-bg);
      color: var(--ctrl-text);
      font-weight: 900;
      font-size: 11px;
      cursor: pointer;
      white-space: nowrap;
      margin: 0;
      box-shadow: 0 1px 4px rgba(0,0,0,.05);
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:6px;
    }
    .btn-estado:hover, .btn-link:hover{
      background: rgba(255,255,255,.88);
      border-color: rgba(58,169,170,.60);
    }
    .btn-estado:active, .btn-link:active{
      transform: translateY(1px);
      box-shadow: none;
    }

    /* ===== Informe ===== */
    .card{
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 16px;
      margin-bottom: 14px;
    }
    .grid-2{
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
    }
    .kv{
      width:100%;
      border-collapse: collapse;
      font-size: 12px;
    }
    .kv td{
      border-bottom: 1px solid var(--border);
      padding: 8px 6px;
      vertical-align: top;
    }
    .kv td:first-child{
      color:#0f172a;
      font-weight: 900;
      width: 38%;
      white-space: nowrap;
    }
    .kv td:last-child{
      color:#0f172a;
      overflow-wrap:anywhere;
    }
    textarea{
      width:100%;
      min-height: 140px;
      resize: vertical;
      padding: 10px 12px;
      border-radius: 12px;
      border: 1px solid #d1d5db;
      outline:none;
      font-family: inherit;
      font-size: 12px;
    }
    textarea:focus{
      border-color:#2563eb;
      box-shadow: 0 0 0 4px rgba(37,99,235,.15);
    }
    .section-title{
      margin:0 0 10px;
      font-weight: 1000;
      color:#0f172a;
    }
    details{
      border:1px solid var(--border);
      border-radius: 12px;
      background: rgba(255,255,255,.6);
      padding: 10px 12px;
      margin-bottom: 10px;
    }
    summary{
      cursor:pointer;
      font-weight: 900;
      color:#0f172a;
    }
    pre{
      white-space: pre-wrap;
      word-break: break-word;
      margin: 10px 0 0;
      font-size: 12px;
      color:#0f172a;
    }

    @media (max-width: 900px){
      .grid-2{ grid-template-columns: 1fr; }
    }
    @media (max-width: 720px){
      :root{ --navW: 220px; }
      main{ padding: 16px; }
      .table-wrap{ max-height: calc(100vh - 170px); }
    }
  </style>
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
                <td><?= h($col) ?></td>
                <td><?= ($viewRow[$col] === null || $viewRow[$col] === "") ? "—" : h($viewRow[$col]) ?></td>
              </tr>
            <?php endforeach; ?>
          </table>
        </div>

        <div class="grid-2">

          <!-- Enviar mensaje -->
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

          <!-- Reporte comunicaciones -->
          <div class="card">
            <div class="section-title">Reporte de comunicaciones</div>

            <?php if ($imapWarning): ?>
              <div class="alert-warn"><?= h($imapWarning) ?></div>
            <?php endif; ?>

            <details open>
              <summary>Enviados desde este panel (<?= count($sentLogs) ?>)</summary>
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
              <summary>Recibidos por IMAP (INBOX / All Mail) (<?= count($receivedLogs) ?>)</summary>
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

            <div style="margin-top:10px; font-size:12px; color:var(--muted); font-weight:800;">
              Nota: IMAP lee INBOX y, si no encuentra, intenta All Mail/Todos. Los enviados se guardan en <em>crm_comunicaciones</em>.
            </div>
          </div>

        </div>

      <?php endif; ?>

    <?php else: ?>

      <h3>Listado de inscripciones del campamento</h3>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <?php foreach($cols as $col): ?>
                <th title="<?= h($col) ?>"><?= h($col) ?></th>
              <?php endforeach; ?>
              <th class="col-estado" title="Estado CRM">Estado CRM</th>
              <th class="col-acciones" title="Acciones">Acciones</th>
            </tr>
          </thead>

          <tbody>
            <?php
              $orderBy = $primaryKeyColumn ? $primaryKeyColumn : ($cols[0] ?? null);
              $sql = "SELECT * FROM `$table_name`";
              if ($orderBy) $sql .= " ORDER BY `$orderBy` DESC";
              $r = $c->query($sql);

              while($r && ($f = $r->fetch_assoc())) {

                $idRegistro = null;
                if ($primaryKeyColumn !== null && isset($f[$primaryKeyColumn])) {
                  $idRegistro = (string)$f[$primaryKeyColumn];
                }

                echo "<tr>";

                foreach($cols as $col){
                  $val = $f[$col] ?? "";
                  if ($val === null || $val === "") {
                    echo '<td><span style="color:#6b7280;font-weight:800;">—</span></td>';
                  } else {
                    echo "<td title='".h($val)."'>".h($val)."</td>";
                  }
                }

                // Estado CRM al final
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

                // Acciones: estado + informe
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

                  echo '<a class="btn-link" href="?view='.h($idRegistro).'">Ver informe</a>';

                  echo '</div>';

                } else {
                  echo '<span style="color:#6b7280;font-weight:800;">—</span>';
                }
                echo '</td>';

                echo "</tr>";
              }
            ?>
          </tbody>
        </table>
      </div>

    <?php endif; ?>

  </main>

<?php endif; ?>
</body>
</html>
