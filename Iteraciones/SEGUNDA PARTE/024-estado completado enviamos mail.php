<?php
/**
 * admin.php — Panel CRM Campamento
 * ----------------------------------------------------------
 * Qué hace (implementado):
 *  - Login admin (mismo archivo) + logout
 *  - Listado de inscripciones (tabla principal)
 *      - Ordenar por columnas (click cabeceras)
 *      - Filtros por columna (texto) + filtro “con/sin doc” en BLOB
 *      - Miniaturas de BLOB en listado (imagen/pdf) + modal ampliación
 *      - Badge de emails no leídos por contacto (IMAP UNSEEN)
 *      - Acciones por fila: ver informe / editar / eliminar (con confirmación)
 *      - Estado CRM por fila (guardar estado) + “pill” de estado con color
 *      - Envío de confirmación por email al pasar a “Completado” (SMTP)
 *
 *  - Vista informe por inscripción (detalle)
 *      - Tabla clave/valor con datos
 *      - BLOB: miniatura + descarga
 *      - Enviar email manual (SMTP) + log en BD (sent)
 *      - Comunicaciones:
 *          - Enviados desde panel (BD)
 *          - Recibidos relacionados (IMAP: INBOX / All Mail fallback)
 *      - Tareas por inscripción:
 *          - Crear / pin / marcar hecha / eliminar
 *      - Historial:
 *          - Registro de acciones (tareas y confirmación/comms)
 *
 *  - Operaciones (page=ops)
 *      - CRUD:
 *          - Create (INSERT dinámico desde metadata)
 *          - Edit (UPDATE dinámico)
 *          - Delete (borra principal + auxiliares en transacción)
 *          - Soporte BLOB en create/edit: subir / reemplazar / eliminar (si nullable)
 *          - Soporte ENUM (select) y tinyint(1) (checkbox)
 *      - Gestión de datos:
 *          - Tareas globales o por inscripción
 *          - Filtros simples (estado / pinned / búsqueda)
 *      - Mantenimiento:
 *          - Backup rápido (.sql) de tabla principal + tablas CRM auxiliares
 *          - Descarga de backups desde panel
 *          - Log de mantenimiento en BD (crm_mantenimiento_log)
 *
 * Tablas auxiliares CRM (auto-creación si no existen):
 *  - crm_estados_inscripciones  (estado + color por id_registro)
 *  - crm_comunicaciones         (log de emails enviados desde panel)
 *  - crm_tareas                 (tareas/recordatorios)
 *  - crm_historial_cambios      (auditoría)
 *  - crm_mantenimiento_log      (log backups)
 *
 * Requisitos:
 *  - PHP con mysqli
 *  - Extensión IMAP habilitada para leer correos
 *  - OpenSSL (para STARTTLS SMTP)
 *  - La tabla principal: campamento_verano.inscripciones_campamento
 */

session_start();


/* =========================================================
   0) RUTEO SIMPLE (qué pantalla mostrar)
   ---------------------------------------------------------
   - page=list  : listado principal
   - page=ops   : operaciones (CRUD, etc.)
========================================================= */
$page   = isset($_GET["page"]) ? (string)$_GET["page"] : "list";
$page   = in_array($page, ["list","ops"], true) ? $page : "list";

$opsTab    = isset($_GET["tab"]) ? (string)$_GET["tab"] : "crud";      // crud | datos | mantenimiento
$opsTab    = in_array($opsTab, ["crud","datos","mantenimiento"], true) ? $opsTab : "crud";

$opsAction = isset($_GET["do"]) ? (string)$_GET["do"] : "create";      // create | edit
$opsAction = in_array($opsAction, ["create","edit"], true) ? $opsAction : "create";

/* =========================================================
   1) CONFIG EMAIL (Gmail IMAP + SMTP)
   ---------------------------------------------------------
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
   2) CONFIG BD
========================================================= */
$db_name    = "campamento_verano";
$table_name = "inscripciones_campamento";

$c = new mysqli("localhost", "campamento_verano", "campamento_verano", $db_name);
if ($c->connect_error) {
  die("Error de conexión: " . $c->connect_error);
}
$c->set_charset("utf8mb4");

/* =========================================================
   2.1) MANTENIMIENTO v1: carpeta backups (solo server-side)
   ---------------------------------------------------------
   - No toca CSS/JS. Solo crea carpeta y genera .sql bajo demanda.
========================================================= */
$BACKUP_DIR = __DIR__ . DIRECTORY_SEPARATOR . "backups_crm";
if (!is_dir($BACKUP_DIR)) {
  @mkdir($BACKUP_DIR, 0775, true);
}

/* =========================================================
   3) LOGIN ADMIN (mismo archivo)
========================================================= */
$ADMIN_USER = "";
$ADMIN_PASS = "";

/* =========================================================
   4) TABLAS AUXILIARES CRM (NO tocan tu tabla principal)
   ---------------------------------------------------------
   - crm_estados_inscripciones: estado y color por id_registro
   - crm_comunicaciones: log de enviados desde panel (sent)
   - crm_tareas: tareas/recordatorios
   - crm_historial_cambios: auditoría
   - ✅ crm_mantenimiento_log: logs de backup
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
    direccion VARCHAR(20) NOT NULL,  -- 'sent'
    asunto VARCHAR(255) NULL,
    cuerpo MEDIUMTEXT NULL,
    meta MEDIUMTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (id_registro),
    INDEX (email_usuario),
    INDEX (direccion)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

/* ---------- tareas/recordatorios ---------- */
$c->query("
  CREATE TABLE IF NOT EXISTS crm_tareas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_registro VARCHAR(255) NULL,
    titulo VARCHAR(200) NOT NULL,
    descripcion MEDIUMTEXT NULL,

    prioridad TINYINT NOT NULL DEFAULT 2,      -- 1=Alta, 2=Media, 3=Baja
    pinned TINYINT(1) NOT NULL DEFAULT 0,      -- 1=pin arriba
    estado ENUM('pendiente','hecha') NOT NULL DEFAULT 'pendiente',

    remind_at DATETIME NULL,
    due_at DATETIME NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX (id_registro),
    INDEX (estado),
    INDEX (pinned),
    INDEX (prioridad),
    INDEX (remind_at),
    INDEX (due_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

/* ---------- base historial ---------- */
$c->query("
  CREATE TABLE IF NOT EXISTS crm_historial_cambios (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    entidad VARCHAR(50) NOT NULL,
    entidad_id VARCHAR(255) NULL,
    accion VARCHAR(20) NOT NULL,
    resumen VARCHAR(255) NULL,
    detalle MEDIUMTEXT NULL,
    admin_user VARCHAR(80) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX (entidad),
    INDEX (entidad_id),
    INDEX (accion),
    INDEX (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

/* ---------- mantenimiento log ---------- */
$c->query("
  CREATE TABLE IF NOT EXISTS crm_mantenimiento_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    accion VARCHAR(50) NOT NULL,          -- 'backup'
    archivo VARCHAR(255) NULL,            -- nombre archivo .sql
    bytes BIGINT NULL,
    estado VARCHAR(20) NOT NULL,          -- 'ok' | 'error'
    detalle MEDIUMTEXT NULL,
    admin_user VARCHAR(80) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX (accion),
    INDEX (estado),
    INDEX (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

/* =========================================================
   5) ESTADOS CRM + COLORES
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
   6) HELPERS (Funciones utilitarias)
========================================================= */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

/**
 * labelize()
 * Convierte nombre_columna a "Nombre columna"
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
 * build_inscripcion_resumen()
 * Construye un resumen en texto plano de la inscripción
 * - NO incluye BLOBs
 * - omite campos vacíos
 */
function build_inscripcion_resumen(array $row, array $cols, array $blobCols){
  $lines = [];
  foreach($cols as $col){
    if (isset($blobCols[$col])) continue; // NO blobs
    $v = $row[$col] ?? null;
    if ($v === null) continue;
    $v = trim((string)$v);
    if ($v === "") continue;

    $lines[] = labelize($col) . ": " . $v;
  }

  if (!$lines) return "(Sin datos para resumir)";
  return implode("\n", $lines);
}

/**
 * Flash messages (mensajes que sobreviven a 1 redirect)
 */
function flash_set($key, $val){ $_SESSION[$key] = (string)$val; }
function flash_get($key){
  if (!isset($_SESSION[$key])) return "";
  $v = (string)$_SESSION[$key];
  unset($_SESSION[$key]);
  return $v;
}

/**
 * Encuentra una columna que "parezca" email
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
 * decodeMime()
 * Decodifica strings MIME (asuntos de email típicos)
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
 * Extrae emails del header IMAP
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

/**
 * Convierte datetime-local (YYYY-MM-DDTHH:MM) a MySQL DATETIME (YYYY-MM-DD HH:MM:SS)
 */
function dt_local_to_mysql($v){
  $v = trim((string)$v);
  if ($v === "") return null;
  $v = str_replace("T", " ", $v);
  if (preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}$/', $v)) $v .= ":00";
  if (!preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}$/', $v)) return null;
  return $v;
}

/**
 * Auditoría simple en BD
 */
function audit_add(mysqli $c, $entidad, $entidad_id, $accion, $resumen = "", $detalleArr = null, $adminUser = null){
  $det = null;
  if (is_array($detalleArr) || is_object($detalleArr)) {
    $det = json_encode($detalleArr, JSON_UNESCAPED_UNICODE);
  } elseif (is_string($detalleArr) && $detalleArr !== "") {
    $det = $detalleArr;
  }

  $stmt = $c->prepare("
    INSERT INTO crm_historial_cambios (entidad, entidad_id, accion, resumen, detalle, admin_user)
    VALUES (?, ?, ?, ?, ?, ?)
  ");
  if (!$stmt) return;

  $entidad    = (string)$entidad;
  $entidad_id = ($entidad_id === null) ? null : (string)$entidad_id;
  $accion     = (string)$accion;
  $resumen    = (string)$resumen;
  $adminUser  = ($adminUser === null) ? null : (string)$adminUser;

  $stmt->bind_param("ssssss", $entidad, $entidad_id, $accion, $resumen, $det, $adminUser);
  $stmt->execute();
  $stmt->close();
}

/**
 * Mantenimiento log
 */
function maintenance_log_add(mysqli $c, $accion, $archivo, $bytes, $estado, $detalle = "", $adminUser = null){
  $stmt = $c->prepare("
    INSERT INTO crm_mantenimiento_log (accion, archivo, bytes, estado, detalle, admin_user)
    VALUES (?, ?, ?, ?, ?, ?)
  ");
  if (!$stmt) return;

  $accion = (string)$accion;
  $archivo = ($archivo === null) ? null : (string)$archivo;
  $estado = (string)$estado;
  $detalle = ($detalle === null) ? null : (string)$detalle;
  $adminUser = ($adminUser === null) ? null : (string)$adminUser;
  $bytes = ($bytes === null) ? null : (int)$bytes;

  $stmt->bind_param("ssisss", $accion, $archivo, $bytes, $estado, $detalle, $adminUser);
  $stmt->execute();
  $stmt->close();
}

/**
 * Return seguro
 */
function safe_return_url($fallbackSelf){
  $return = trim((string)($_POST["return"] ?? ""));
  if ($return === "") return $fallbackSelf;

  $u = parse_url($return);
  if (is_array($u) && !isset($u["scheme"]) && !isset($u["host"])) {
    $path = (string)($u["path"] ?? "");
    if ($path === "" || basename($path) === basename($fallbackSelf)) {
      return $return;
    }
  }
  return $fallbackSelf;
}

/* ---------------- SMTP sin librerías (STARTTLS) ---------------- */

/**
 * smtp_read()
 * Lee la respuesta del servidor SMTP hasta que termina el bloque.
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
 * smtp_cmd()
 * Envía un comando y valida código de respuesta
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
 * mime_header()
 * Para headers con UTF-8 (asunto, nombre, etc.)
 */
function mime_header($text){
  if (function_exists('mb_encode_mimeheader')) {
    return mb_encode_mimeheader($text, 'UTF-8', 'B', "\r\n");
  }
  return $text;
}

/**
 * smtp_send_gmail()
 * Envío SMTP manual (STARTTLS + AUTH LOGIN) compatible con Gmail
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

  // Seguridad SMTP: líneas que empiezan con "." se duplican
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
 * imap_get_text_body()
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
   7) HELPERS EXTRA: BLOB + SELECT projection + tamaños upload
========================================================= */

/**
 * is_blob_datatype()
 * Detecta tipos blob de MySQL
 */
function is_blob_datatype($dt){
  $dt = strtolower((string)$dt);
  return in_array($dt, ['blob','mediumblob','longblob','tinyblob'], true);
}

/**
 * detect_mime()
 * Detecta el MIME de un binario por cabecera
 */
function detect_mime($bin){
  if (!is_string($bin) || $bin === '') return 'application/octet-stream';
  if (substr($bin, 0, 4) === "%PDF") return "application/pdf";
  if (substr($bin, 0, 3) === "\xFF\xD8\xFF") return 'image/jpeg';
  if (substr($bin, 0, 8) === "\x89PNG\x0D\x0A\x1A\x0A") return 'image/png';
  return 'application/octet-stream';
}

/**
 * build_select_projection()
 * En vez de traer blobs, trae OCTET_LENGTH(blob) como __len_col
 * y el resto normal.
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

/**
 * iniSizeToBytes() + bytesToHuman()
 * Para mostrar límite real de subida (min(upload_max, post_max))
 */
function iniSizeToBytes(string $value): int {
  $value = trim($value);
  if ($value === '') return 0;

  $last = strtolower($value[strlen($value) - 1]);
  $num  = (int)$value;

  switch ($last) {
    case 'g': return $num * 1024 * 1024 * 1024;
    case 'm': return $num * 1024 * 1024;
    case 'k': return $num * 1024;
    default:  return (int)$value;
  }
}
function bytesToHuman(int $bytes): string {
  if ($bytes <= 0) return "desconocido";
  $units = ['B','KB','MB','GB','TB'];
  $i = 0;
  $v = (float)$bytes;
  while ($v >= 1024 && $i < count($units) - 1) { $v /= 1024; $i++; }
  return rtrim(rtrim(number_format($v, 2), '0'), '.') . ' ' . $units[$i];
}

/**
 * is_boolish_col()
 * Detecta tinyint(1) para pintarlo como checkbox
 */
function is_boolish_col(array $meta){
  $dt = strtolower((string)($meta["DATA_TYPE"] ?? ""));
  $ct = strtolower((string)($meta["COLUMN_TYPE"] ?? ""));
  return ($dt === "tinyint" && preg_match('/\(\s*1\s*\)/', $ct));
}

/**
 * parse_enum_options()
 * Extrae las opciones de un ENUM('a','b',...)
 */
function parse_enum_options($columnType){
  $opts = [];
  if (!is_string($columnType)) return $opts;
  if (stripos($columnType, "enum(") !== 0) return $opts;

  if (preg_match_all("/'((?:\\\\'|[^'])*)'/", $columnType, $m)) {
    foreach ($m[1] as $raw) $opts[] = str_replace("\\'", "'", $raw);
  }
  return $opts;
}

/* =========================================================
   7.5) MANTENIMIENTO: Backup rápido (.sql)
========================================================= */

/**
 * maintenance_table_exists()
 */
function maintenance_table_exists(mysqli $c, string $db, string $table): bool {
  $stmt = $c->prepare("
    SELECT 1
    FROM information_schema.tables
    WHERE table_schema = ? AND table_name = ?
    LIMIT 1
  ");
  if (!$stmt) return false;
  $stmt->bind_param("ss", $db, $table);
  $stmt->execute();
  $res = $stmt->get_result();
  $ok = ($res && $res->num_rows > 0);
  $stmt->close();
  return $ok;
}

/**
 * maintenance_col_types()
 * Devuelve un mapa col => data_type
 */
function maintenance_col_types(mysqli $c, string $db, string $table): array {
  $map = [];
  $stmt = $c->prepare("
    SELECT COLUMN_NAME, DATA_TYPE
    FROM information_schema.columns
    WHERE table_schema = ? AND table_name = ?
    ORDER BY ORDINAL_POSITION
  ");
  if (!$stmt) return $map;
  $stmt->bind_param("ss", $db, $table);
  $stmt->execute();
  $res = $stmt->get_result();
  while($res && ($r = $res->fetch_assoc())){
    $map[(string)$r["COLUMN_NAME"]] = strtolower((string)$r["DATA_TYPE"]);
  }
  $stmt->close();
  return $map;
}

/**
 * maintenance_is_numeric_type()
 */
function maintenance_is_numeric_type(string $dt): bool {
  static $num = [
    "int","bigint","smallint","mediumint","tinyint",
    "decimal","float","double","bit"
  ];
  return in_array($dt, $num, true);
}

/**
 * maintenance_dump_table()
 */
function maintenance_dump_table(mysqli $c, string $db, string $table, $fp, array &$stats, string &$err): bool {
  // SHOW CREATE TABLE
  $q = $c->query("SHOW CREATE TABLE `$table`");
  if (!$q) { $err = "SHOW CREATE TABLE falló para $table: ".$c->error; return false; }
  $row = $q->fetch_assoc();
  $q->free();

  $createSql = $row["Create Table"] ?? null;
  if (!$createSql) { $err = "No se pudo obtener CREATE TABLE de $table."; return false; }

  fwrite($fp, "\n-- --------------------------------------------------------\n");
  fwrite($fp, "-- Table: `$table`\n");
  fwrite($fp, "-- --------------------------------------------------------\n");
  fwrite($fp, "DROP TABLE IF EXISTS `$table`;\n");
  fwrite($fp, $createSql . ";\n\n");

  // Tipos de columnas
  $typesMap = maintenance_col_types($c, $db, $table);

  // Dump data
  $res = $c->query("SELECT * FROM `$table`", MYSQLI_USE_RESULT);
  if (!$res) { $err = "SELECT * falló para $table: ".$c->error; return false; }

  $rowCount = 0;

  while ($r = $res->fetch_assoc()) {
    $cols = array_keys($r);
    $vals = [];

    foreach ($cols as $col) {
      $v = $r[$col];

      if ($v === null) {
        $vals[] = "NULL";
        continue;
      }

      $dt = $typesMap[$col] ?? "text";

      // BLOBs
      if (in_array($dt, ["blob","mediumblob","longblob","tinyblob"], true)) {
        if ($v === "") {
          $vals[] = "''";
        } else {
          $vals[] = "0x" . bin2hex($v);
        }
        continue;
      }

      // Números
      if (maintenance_is_numeric_type($dt)) {
        $sv = trim((string)$v);
        if ($sv === "") $vals[] = "NULL";
        else $vals[] = $sv;
        continue;
      }

      // Texto/fechas/etc.
      $vals[] = "'" . $c->real_escape_string((string)$v) . "'";
    }

    $colList = "`" . implode("`,`", $cols) . "`";
    $valList = implode(",", $vals);

    fwrite($fp, "INSERT INTO `$table` ($colList) VALUES ($valList);\n");
    $rowCount++;
  }

  $res->free();

  $stats[$table] = $rowCount;
  return true;
}

/**
 * maintenance_make_backup()
 * Genera un .sql con un set de tablas (rápido, sin dependencias).
 */
function maintenance_make_backup(mysqli $c, string $db, array $tables, string $dir, string &$outFile, int &$outBytes, string &$outErr): bool {
  $outErr = "";
  $outFile = "";
  $outBytes = 0;

  if (!is_dir($dir) || !is_writable($dir)) {
    $outErr = "La carpeta de backups no existe o no tiene permisos de escritura: ".$dir;
    return false;
  }

  $stamp = date("Ymd_His");
  $filename = "crm_backup_" . $stamp . ".sql";
  $path = rtrim($dir, "/\\") . DIRECTORY_SEPARATOR . $filename;

  $fp = @fopen($path, "wb");
  if (!$fp) {
    $outErr = "No se pudo crear el archivo de backup en: ".$path;
    return false;
  }

  $stats = [];

  fwrite($fp, "-- CRM Backup (v1)\n");
  fwrite($fp, "-- DB: $db\n");
  fwrite($fp, "-- Date: ".date("Y-m-d H:i:s")."\n");
  fwrite($fp, "SET NAMES utf8mb4;\n");
  fwrite($fp, "SET FOREIGN_KEY_CHECKS=0;\n\n");

  foreach ($tables as $t) {
    $t = (string)$t;
    if ($t === "") continue;
    if (!maintenance_table_exists($c, $db, $t)) continue;

    $err = "";
    $ok = maintenance_dump_table($c, $db, $t, $fp, $stats, $err);
    if (!$ok) {
      fclose($fp);
      @unlink($path);
      $outErr = $err;
      return false;
    }
  }

  fwrite($fp, "\nSET FOREIGN_KEY_CHECKS=1;\n");
  fwrite($fp, "\n-- Stats:\n");
  foreach($stats as $t => $n){
    fwrite($fp, "--   $t: $n row(s)\n");
  }

  fclose($fp);

  $outFile = $filename;
  $outBytes = (int)@filesize($path);

  return true;
}

/* =========================================================
   8) PRIMARY KEY (para identificar filas + endpoints)
========================================================= */
$primaryKeyColumn = null;
$primaryKeyIsAuto = false;

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

/* =========================================================
   9) LOGOUT
========================================================= */
if (isset($_GET["logout"])) {
  session_destroy();
  header("Location: " . $_SERVER["PHP_SELF"]);
  exit;
}

/* =========================================================
   10) LOGIN
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
   11) MENSAJES (flash + normales)
========================================================= */
$panel_msg = flash_get("flash_ok");
$panel_err = flash_get("flash_err");

/* =========================================================
   12) COLUMNAS DE INSCRIPCIONES (meta) + detectar email + blobs
========================================================= */
$cols        = [];
$emailCol    = null;
$blobCols    = [];  // ["documento"=>true]
$sortableCols= [];  // columnas NO blob (para ordenar/filtrar)
$colsMeta    = [];  // metadatos por columna
$autoIncCols = [];  // columnas auto_increment (normalmente id)

// límites de subida
$uploadMax  = iniSizeToBytes((string)ini_get('upload_max_filesize'));
$postMax    = iniSizeToBytes((string)ini_get('post_max_size'));
$limiteReal = ($uploadMax > 0 && $postMax > 0) ? min($uploadMax, $postMax) : max($uploadMax, $postMax);
$limiteTexto = bytesToHuman((int)$limiteReal);

if ($loggedIn) {
  $r = $c->query("
    SELECT
      COLUMN_NAME, DATA_TYPE, COLUMN_TYPE,
      IS_NULLABLE, COLUMN_DEFAULT, EXTRA,
      COLUMN_COMMENT,
      CHARACTER_MAXIMUM_LENGTH,
      NUMERIC_PRECISION, NUMERIC_SCALE
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
   12.5) CRUD EDIT: cargar fila para editar (sin blobs)
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
   13) SORTING SIMPLE (1 columna) - click cabeceras
========================================================= */
$sortCol = $loggedIn ? (string)($_GET["sort"] ?? "") : "";
$sortDir = $loggedIn ? strtolower((string)($_GET["dir"] ?? "asc")) : "asc";

if (!isset($sortableCols[$sortCol])) $sortCol = "";
$sortDir = ($sortDir === "desc") ? "desc" : "asc";

/* =========================================================
   14) FILTROS MULTI-CRITERIO
========================================================= */
$filtersText = ($loggedIn && isset($_GET["f"]) && is_array($_GET["f"])) ? $_GET["f"] : [];
$filtersBlob = ($loggedIn && isset($_GET["fb"]) && is_array($_GET["fb"])) ? $_GET["fb"] : [];

foreach($filtersText as $k => $v){
  if (!is_string($k)) { unset($filtersText[$k]); continue; }
  $filtersText[$k] = trim((string)$v);
}
foreach($filtersBlob as $k => $v){
  if (!is_string($k)) { unset($filtersBlob[$k]); continue; }
  $filtersBlob[$k] = trim((string)$v);
}

/* =========================================================
   14.5) EXPORTACIÓN
   ---------------------------------------------------------
   - export=csv : exporta el listado (con filtros + orden actual)
   - export=sql : genera un .sql (igual que backup) y lo descarga directo
   - log en crm_mantenimiento_log
========================================================= */
if ($loggedIn && isset($_GET["export"])) {

  $export = (string)$_GET["export"];

  /* -----------------------------
     A) EXPORT CSV (listado)
     ----------------------------- */
  if ($export === "csv") {

    @set_time_limit(0);

    // Reutiliza tu misma lógica de listado (projection + where + order)
    $projection = build_select_projection($cols, $blobCols);

    $where = ["1=1"];
    $bindTypes = "";
    $bindVals  = [];

    foreach($filtersText as $col => $val){
      if (!is_string($col)) continue;
      $val = trim((string)$val);
      if ($val === "") continue;
      if (!isset($sortableCols[$col])) continue;

      $where[] = "CAST(`$col` AS CHAR) LIKE ?";
      $bindTypes .= "s";
      $bindVals[] = "%".$val."%";
    }

    foreach($filtersBlob as $col => $flag){
      if (!is_string($col)) continue;
      $flag = trim((string)$flag);
      if ($flag === "") continue;
      if (!isset($blobCols[$col])) continue;

      if ($flag === "1") {
        $where[] = "OCTET_LENGTH(`$col`) > 0";
      } elseif ($flag === "0") {
        $where[] = "(`$col` IS NULL OR OCTET_LENGTH(`$col`) = 0)";
      }
    }

    $sql = "SELECT $projection FROM `$table_name` WHERE ".implode(" AND ", $where);

    // ORDER BY (igual que el listado)
    if ($sortCol !== "") {
      $sql .= " ORDER BY `$sortCol` ".strtoupper($sortDir);
    } else {
      $orderBy = $primaryKeyColumn ? $primaryKeyColumn : ($cols[0] ?? null);
      if ($orderBy) $sql .= " ORDER BY `$orderBy` DESC";
    }

    // Log (intento de export)
    maintenance_log_add(
      $c,
      "export_csv",
      null,
      null,
      "ok",
      "Export CSV con filtros/orden. sort={$sortCol} dir={$sortDir}",
      $ADMIN_USER
    );

    $filename = "inscripciones_export_" . date("Ymd_His") . ".csv";

    header("Content-Type: text/csv; charset=UTF-8");
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header("Cache-Control: private, max-age=0");

    // BOM para Excel
    echo "\xEF\xBB\xBF";

    $fp = fopen("php://output", "w");

    // Cabeceras CSV: columnas normales y blobs como has_col
    $header = [];
    foreach($cols as $col){
      if (isset($blobCols[$col])) $header[] = "has_" . $col;
      else $header[] = $col;
    }
    fputcsv($fp, $header);

    $stmt = $c->prepare($sql);
    if (!$stmt) {
      // Log error real
      maintenance_log_add($c, "export_csv", null, null, "error", "Prepare failed: ".$c->error, $ADMIN_USER);
      fclose($fp);
      exit;
    }

    if ($bindTypes !== "") {
      $params = array_merge([$bindTypes], $bindVals);
      $refs = [];
      foreach($params as $i => $v){ $refs[$i] = &$params[$i]; }
      call_user_func_array([$stmt, "bind_param"], $refs);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    while($res && ($row = $res->fetch_assoc())){
      $line = [];
      foreach($cols as $col){
        if (isset($blobCols[$col])) {
          $lenKey = "__len_$col";
          $hasDoc = (isset($row[$lenKey]) && (int)$row[$lenKey] > 0) ? 1 : 0;
          $line[] = $hasDoc;
        } else {
          $line[] = $row[$col] ?? "";
        }
      }
      fputcsv($fp, $line);
    }

    $stmt->close();
    fclose($fp);
    exit;
  }

  /* -----------------------------
     B) EXPORT SQL (descarga directa)
     - reutiliza tu maintenance_make_backup()
     - deja el archivo también en backups_crm
     ----------------------------- */
  if ($export === "sql") {

    @set_time_limit(0);

    $tablesToBackup = [
      $table_name,
      "crm_estados_inscripciones",
      "crm_comunicaciones",
      "crm_tareas",
      "crm_historial_cambios",
      "crm_mantenimiento_log",
    ];

    $outFile  = "";
    $outBytes = 0;
    $outErr   = "";

    $ok = maintenance_make_backup($c, $db_name, $tablesToBackup, $BACKUP_DIR, $outFile, $outBytes, $outErr);

    if ($ok) {
      maintenance_log_add($c, "export_sql", $outFile, $outBytes, "ok", "Export SQL directo", $ADMIN_USER);

      $path = rtrim($BACKUP_DIR, "/\\") . DIRECTORY_SEPARATOR . $outFile;

      header("Content-Type: application/sql; charset=UTF-8");
      header('Content-Disposition: attachment; filename="'.$outFile.'"');
      header("Content-Length: " . (string)filesize($path));
      header("Cache-Control: private, max-age=0, must-revalidate");

      readfile($path);
      exit;

    } else {
      maintenance_log_add($c, "export_sql", null, null, "error", $outErr, $ADMIN_USER);
      flash_set("flash_err", "No se pudo exportar SQL: ".$outErr);
      header("Location: ".$_SERVER["PHP_SELF"]."?page=ops&tab=mantenimiento&do=create");
      exit;
    }
  }

  // Si llega un export desconocido
  flash_set("flash_err", "Export desconocido.");
  header("Location: ".$_SERVER["PHP_SELF"]);
  exit;
}

/* =========================================================
   15) ENDPOINT ARCHIVO (BLOB -> ver/descargar)
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
  $mime = detect_mime($bin);

  header("Content-Type: ".$mime);
  header("Cache-Control: private, max-age=86400");

  if (isset($_GET["download"]) && $_GET["download"] == "1") {
    $ext = "bin";
    if ($mime === "image/png") $ext = "png";
    else if ($mime === "image/jpeg") $ext = "jpg";
    else if ($mime === "application/pdf") $ext = "pdf";

    $safe = preg_replace('/[^a-z0-9_\-]/i', '_', $col);
    header('Content-Disposition: attachment; filename="'.$safe.'_'.$id.'.'.$ext.'"');
  }

  echo $bin;
  exit;
}

/* =========================================================
   15.B) ENDPOINT BACKUP (descarga .sql) — Mantenimiento
========================================================= */
if ($loggedIn && isset($_GET["backup"]) && $_GET["backup"] == "1") {
  $file = (string)($_GET["file"] ?? "");
  $file = basename($file);

  // Solo permite .sql con nombre esperado
  if ($file === "" || !preg_match('/^crm_backup_\d{8}_\d{6}\.sql$/', $file)) {
    http_response_code(400);
    exit;
  }

  $path = rtrim($BACKUP_DIR, "/\\") . DIRECTORY_SEPARATOR . $file;
  if (!is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit;
  }

  header("Content-Type: application/sql; charset=UTF-8");
  header('Content-Disposition: attachment; filename="'.$file.'"');
  header("Content-Length: " . (string)filesize($path));
  header("Cache-Control: private, max-age=0, must-revalidate");
  readfile($path);
  exit;
}

/* =========================================================
   16) UPDATE ESTADO CRM (+ confirmación al "Completado")
========================================================= */
if ($loggedIn && isset($_POST["action"]) && $_POST["action"] === "update_estado") {

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

          if (!$primaryKeyColumn) {
            flash_set("flash_err", "No se detectó PK, no se puede armar resumen para confirmación.");
            header("Location: ".$returnUrl);
            exit;
          }

          if (!$emailCol) {
            flash_set("flash_err", "No se detectó columna de email (email/correo). No se pudo enviar confirmación.");
            header("Location: ".$returnUrl);
            exit;
          }

          // Traer fila (sin blobs) para resumen
          $projection = build_select_projection($cols, $blobCols);
          $rowData = null;

          $stRow = $c->prepare("SELECT $projection FROM `$table_name` WHERE `$primaryKeyColumn`=? LIMIT 1");
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

          $to_email = trim((string)($rowData[$emailCol] ?? ""));
          if ($to_email === "") {
            flash_set("flash_err", "La inscripción no tiene email. No se pudo enviar confirmación.");
            header("Location: ".$returnUrl);
            exit;
          }

          // Resumen sin BLOBs
          $resumen = build_inscripcion_resumen($rowData, $cols, $blobCols);

          // Email (texto plano)
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

            // Log en comunicaciones (sent)
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

            // Auditoría (historial)
            audit_add($c, "inscripcion", (string)$id_registro, "confirm", "Email confirmación enviado", [
              "to" => $to_email,
              "subject" => $subject,
              "type" => "confirmacion_completado"
            ], $ADMIN_USER);

            // Mensaje OK (sobrescribe / complementa)
            flash_set("flash_ok", "Estado actualizado (ID #".$id_registro.") + confirmación enviada a ".$to_email.".");

          } else {

            // Auditoría error
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

/* =========================================================
   17) ENVIAR EMAIL (SMTP) + log en BD (sent)
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
   17.B) TAREAS / RECORDATORIOS (CRUD simple) + auditoría
========================================================= */
if ($loggedIn && isset($_POST["action"])) {

  // -------- Crear tarea
  if ($_POST["action"] === "task_create") {

    $id_registro = trim((string)($_POST["id_registro"] ?? ""));
    if ($id_registro === "") $id_registro = null; // permite tarea global

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
        "prioridad" => $prioridad,
        "pinned" => $pinned,
        "remind_at" => $remind_at,
        "due_at" => $due_at
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

    $idRegistro = null;
    $titulo = "";
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

/* =========================================================
   17.C) MANTENIMIENTO: Acción backup rápido + log
========================================================= */
if ($loggedIn && isset($_POST["action"]) && $_POST["action"] === "maintenance_backup") {

  // Tablas a incluir (rápido y relevante para tu CRM)
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

/* =========================================================
   18) ESTADOS ACTUALES (para pintar la tabla)
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
   19) EMAILS NO LEÍDOS (UNSEEN) POR CONTACTO (IMAP)
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
   20) VISTA INFORME + comunicaciones + tareas + historial
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

  // enviados panel
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

  // recibidos IMAP
  if (!$emailCol) {
    $imapWarning = "No se detectó una columna de email (email/correo).";
  } else if (!$viewRow || empty($viewRow[$emailCol])) {
    $imapWarning = "Esta inscripción no tiene email para buscar mensajes.";
  } else if (!function_exists("imap_open")) {
    $imapWarning = "La extensión IMAP no está habilitada en PHP.";
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

  // tareas del registro
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

  // historial del registro (base)
  $stmt = $c->prepare("
    SELECT entidad, accion, resumen, detalle, admin_user, created_at
    FROM crm_historial_cambios
    WHERE entidad_id = ?
    ORDER BY created_at DESC
    LIMIT 40
  ");
  if ($stmt) {
    $stmt->bind_param("s", $viewId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) $historyLogs[] = $row;
    $stmt->close();
  }
}

if ($loggedIn && isset($_GET["refresh"]) && $_GET["refresh"] == "1") {
  if ($panel_msg === "" && $panel_err === "") $panel_msg = "Comunicaciones actualizadas.";
}

/* =========================================================
   21) OPERACIONES > CRUD > CREATE (INSERT)
========================================================= */
$createOld    = [];
$createErrors = [];

if ($loggedIn
    && $page === "ops"
    && $opsTab === "crud"
    && $opsAction === "create"
    && isset($_POST["action"]) && $_POST["action"] === "crud_create"
) {
  $createOld = (isset($_POST["c"]) && is_array($_POST["c"])) ? $_POST["c"] : [];

  $insertCols = [];
  foreach ($cols as $col) {
    if (isset($autoIncCols[$col])) continue;
    $extra = (string)($colsMeta[$col]["EXTRA"] ?? "");
    if (stripos($extra, "GENERATED") !== false) continue;
    $insertCols[] = $col;
  }

  $sqlCols = [];
  $sqlQs   = [];
  $types   = "";
  $values  = [];
  $blobParamIdx = [];

  foreach ($insertCols as $col) {

    $meta = $colsMeta[$col] ?? [];
    $dt   = strtolower((string)($meta["DATA_TYPE"] ?? ""));
    $ct   = (string)($meta["COLUMN_TYPE"] ?? "");
    $isNullable = strtoupper((string)($meta["IS_NULLABLE"] ?? "YES")) === "YES";
    $default    = $meta["COLUMN_DEFAULT"];

    // ---------------- BLOB: se sube por $_FILES ----------------
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
          $sqlCols[] = "`$col`";
          $sqlQs[]   = "?";
          $types    .= "s";
          $values[]  = null;
        } else {
          $createErrors[] = "El campo '".labelize($col)."' es obligatorio (archivo).";
        }
      } else {
        $sqlCols[] = "`$col`";
        $sqlQs[]   = "?";
        $types    .= "b";
        $values[]  = $bin;
        $blobParamIdx[] = count($values) - 1;
      }

      continue;
    }

    // ---------------- tinyint(1): checkbox ----------------
    if (is_boolish_col($meta)) {
      $raw = $createOld[$col] ?? "0";
      $sqlCols[] = "`$col`";
      $sqlQs[]   = "?";
      $types    .= "i";
      $values[]  = (int)$raw;
      continue;
    }

    // ---------------- ENUM ----------------
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

    // ---------------- Campos normales ----------------
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

        // bind_param requiere referencias
        $bindParams = [];
        $bindParams[] = $types;
        for ($i=0; $i<count($values); $i++){
          $bindParams[] = &$values[$i];
        }
        call_user_func_array([$stmt, "bind_param"], $bindParams);

        // blobs: send_long_data para binarios
        foreach($blobParamIdx as $idx){
          $stmt->send_long_data($idx, $values[$idx]);
        }

        $ok = $stmt->execute();
        $err = $stmt->error;
        $stmt->close();

        if (!$ok) {
          $createErrors[] = "No se pudo crear la inscripción: ".$err;
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

  if ($createErrors) $panel_err = implode(" | ", $createErrors);
}

/* =========================================================
   21.B) OPERACIONES > CRUD > UPDATE (EDITAR)
========================================================= */
$editOld    = [];
$editErrors = [];

if ($loggedIn
    && $page === "ops"
    && $opsTab === "crud"
    && isset($_POST["action"]) && $_POST["action"] === "crud_update"
) {
  if (!$primaryKeyColumn) {
    $editErrors[] = "No hay clave primaria detectada; no se puede editar.";
  }

  $id = (string)($_POST["id"] ?? "");
  if ($id === "") $editErrors[] = "Falta el ID a editar.";

  $editOld = (isset($_POST["c"]) && is_array($_POST["c"])) ? $_POST["c"] : [];
  $rm      = (isset($_POST["rm"]) && is_array($_POST["rm"])) ? $_POST["rm"] : [];

  $updateCols = [];
  foreach ($cols as $col) {
    if ($col === $primaryKeyColumn) continue;
    if (isset($autoIncCols[$col])) continue;
    $extra = (string)($colsMeta[$col]["EXTRA"] ?? "");
    if (stripos($extra, "GENERATED") !== false) continue;
    $updateCols[] = $col;
  }

  $setParts = [];
  $types    = "";
  $values   = [];
  $blobParamIdx = [];

  foreach ($updateCols as $col) {

    $meta = $colsMeta[$col] ?? [];
    $dt   = strtolower((string)($meta["DATA_TYPE"] ?? ""));
    $ct   = (string)($meta["COLUMN_TYPE"] ?? "");
    $isNullable = strtoupper((string)($meta["IS_NULLABLE"] ?? "YES")) === "YES";

    // ---------------- BLOB: reemplazar / eliminar ----------------
    if (isset($blobCols[$col])) {

      // Si se marcó "Eliminar documento"
      $wantRemove = isset($rm[$col]) && (string)$rm[$col] === "1";
      if ($wantRemove) {
        if ($isNullable) {
          $setParts[] = "`$col` = NULL";
        } else {
          $editErrors[] = "No puedes eliminar '".labelize($col)."' porque es NOT NULL.";
        }
        continue;
      }

      // Si no suben archivo, se mantiene
      $err = $_FILES["b"]["error"][$col] ?? UPLOAD_ERR_NO_FILE;
      $tmp = $_FILES["b"]["tmp_name"][$col] ?? null;

      if ($err === UPLOAD_ERR_NO_FILE) {
        continue; // mantiene el blob
      }

      if ($err === UPLOAD_ERR_OK && $tmp && is_uploaded_file($tmp)) {
        $bin = file_get_contents($tmp);
        if ($bin === false) $bin = null;

        if ($bin === null) {
          $editErrors[] = "No se pudo leer el archivo de '".labelize($col)."'.";
        } else {
          $setParts[] = "`$col` = ?";
          $types .= "b";
          $values[] = $bin;
          $blobParamIdx[] = count($values) - 1;
        }
      } else {
        $editErrors[] = "Error subiendo archivo en: ".labelize($col);
      }

      continue;
    }

    // ---------------- tinyint(1): checkbox ----------------
    if (is_boolish_col($meta)) {
      $raw = $editOld[$col] ?? "0";
      $setParts[] = "`$col` = ?";
      $types .= "i";
      $values[] = (int)$raw;
      continue;
    }

    // ---------------- ENUM ----------------
    if ($dt === "enum") {
      $val  = isset($editOld[$col]) ? (string)$editOld[$col] : "";
      $opts = parse_enum_options($ct);

      if ($val === "") {
        if ($isNullable) {
          $setParts[] = "`$col` = NULL";
        } else {
          $editErrors[] = "El campo '".labelize($col)."' es obligatorio.";
        }
      } else {
        if (!in_array($val, $opts, true)) {
          $editErrors[] = "Valor inválido en '".labelize($col)."' (ENUM).";
        } else {
          $setParts[] = "`$col` = ?";
          $types .= "s";
          $values[] = $val;
        }
      }
      continue;
    }

    // ---------------- Campos normales ----------------
    $val = isset($editOld[$col]) ? trim((string)$editOld[$col]) : "";

    if ($val === "") {
      if ($isNullable) {
        $setParts[] = "`$col` = NULL";
      } else {
        $editErrors[] = "El campo '".labelize($col)."' es obligatorio.";
      }
      continue;
    }

    if (in_array($dt, ["int","bigint","smallint","mediumint","tinyint"], true)) {
      $setParts[] = "`$col` = ?";
      $types .= "i";
      $values[] = (int)$val;
      continue;
    }

    if (in_array($dt, ["decimal","float","double"], true)) {
      $setParts[] = "`$col` = ?";
      $types .= "d";
      $values[] = (float)$val;
      continue;
    }

    $setParts[] = "`$col` = ?";
    $types .= "s";
    $values[] = $val;
  }

  if (!$editErrors) {
    if (!$setParts) {
      $editErrors[] = "No hay cambios para guardar.";
    } else {

      $sql = "UPDATE `$table_name` SET ".implode(", ", $setParts)." WHERE `$primaryKeyColumn` = ? LIMIT 1";
      $stmt = $c->prepare($sql);

      if (!$stmt) {
        $editErrors[] = "Error preparando UPDATE: ".$c->error;
      } else {

        // al final se bindea el ID
        $types .= "s";
        $values[] = $id;

        $bindParams = [];
        $bindParams[] = $types;
        for ($i=0; $i<count($values); $i++){
          $bindParams[] = &$values[$i];
        }

        call_user_func_array([$stmt, "bind_param"], $bindParams);

        foreach ($blobParamIdx as $idx) {
          $stmt->send_long_data($idx, $values[$idx]);
        }

        $ok = $stmt->execute();
        $err = $stmt->error;
        $stmt->close();

        if (!$ok) {
          $editErrors[] = "No se pudo actualizar: ".$err;
        } else {
          flash_set("flash_ok", "Inscripción actualizada correctamente (ID #".$id.").");
          header("Location: ".$_SERVER["PHP_SELF"]."?view=".urlencode($id));
          exit;
        }
      }
    }
  }

  if ($editErrors) $panel_err = implode(" | ", $editErrors);
}

/* =========================================================
   21.C) OPERACIONES > CRUD > DELETE (ELIMINAR)
========================================================= */
if ($loggedIn && isset($_POST["action"]) && $_POST["action"] === "crud_delete") {

  // Sin PK no podemos borrar una fila concreta
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

  // return seguro
  $return = trim((string)($_POST["return"] ?? ""));
  $safeReturn = $_SERVER["PHP_SELF"];
  if ($return !== "") {
    $u = parse_url($return);
    if (is_array($u) && !isset($u["scheme"]) && !isset($u["host"])) {
      $path = (string)($u["path"] ?? "");
      if ($path === "" || basename($path) === basename($_SERVER["PHP_SELF"])) {
        $safeReturn = $return;
      }
    }
  }

  $ok = true;
  $affectedMain = 0;

  try {
    $c->begin_transaction();

    // 1) Borrar historial de comunicaciones (tabla auxiliar)
    $stmt = $c->prepare("DELETE FROM crm_comunicaciones WHERE id_registro = ?");
    if (!$stmt) { $ok = false; }
    else {
      $stmt->bind_param("s", $id);
      $ok = $stmt->execute();
      $stmt->close();
    }

    // 2) Borrar estado CRM (tabla auxiliar)
    if ($ok) {
      $stmt = $c->prepare("DELETE FROM crm_estados_inscripciones WHERE id_registro = ?");
      if (!$stmt) { $ok = false; }
      else {
        $stmt->bind_param("s", $id);
        $ok = $stmt->execute();
        $stmt->close();
      }
    }

    // 2.5) Borrar tareas asociadas (tabla auxiliar)
    if ($ok) {
      $stmt = $c->prepare("DELETE FROM crm_tareas WHERE id_registro = ?");
      if (!$stmt) { $ok = false; }
      else {
        $stmt->bind_param("s", $id);
        $ok = $stmt->execute();
        $stmt->close();
      }
    }

    // 3) Borrar registro principal
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

    // Commit/Rollback según resultado
    if ($ok && $affectedMain > 0) {
      $c->commit();
      flash_set("flash_ok", "Inscripción eliminada correctamente (ID #".$id.").");
    } else {
      $c->rollback();
      if (!$ok) {
        flash_set("flash_err", "No se pudo eliminar (error BD).");
      } else {
        flash_set("flash_err", "No se eliminó: registro no encontrado (ID #".$id.").");
      }
    }
  } catch (Throwable $e) {
    @($c->rollback());
    flash_set("flash_err", "No se pudo eliminar: ".$e->getMessage());
  }

  header("Location: " . $safeReturn);
  exit;
}

/* =========================================================
   22) ESTILADO/CLASES ACTIVAS NAV (para UI)
========================================================= */
function nav_active($cond){
  return $cond ? " is-active" : "";
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Panel de administración</title>

  <link rel="stylesheet" href="admin_021.css">
  <script src="admin_017.js" defer></script>
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

    <a class="nav-btn<?= nav_active($page==="list") ?>" href="<?= h($_SERVER["PHP_SELF"]) ?>">Listado</a>

    <a class="nav-btn<?= nav_active($page==="ops" && $opsTab==="crud") ?>"
       href="<?= h($_SERVER["PHP_SELF"]) ?>?page=ops&tab=crud&do=create">Operaciones</a>

    <a class="nav-btn<?= nav_active($page==="ops" && $opsTab==="datos") ?>"
       href="<?= h($_SERVER["PHP_SELF"]) ?>?page=ops&tab=datos&do=create">Gestión de datos</a>

    <a class="nav-btn<?= nav_active($page==="ops" && $opsTab==="mantenimiento") ?>"
       href="<?= h($_SERVER["PHP_SELF"]) ?>?page=ops&tab=mantenimiento&do=create">Mantenimiento</a>

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

    <?php else: ?>

      <?php if ($page === "ops"): ?>

        <h3>Operaciones</h3>

        <div class="ops-tabs">
          <a class="btn-link<?= nav_active($opsTab==="crud") ?>"
             href="<?= h($_SERVER["PHP_SELF"]) ?>?page=ops&tab=crud&do=create">CRUD</a>

          <a class="btn-link<?= nav_active($opsTab==="datos") ?>"
             href="<?= h($_SERVER["PHP_SELF"]) ?>?page=ops&tab=datos&do=create">Gestión de datos</a>

          <a class="btn-link<?= nav_active($opsTab==="mantenimiento") ?>"
             href="<?= h($_SERVER["PHP_SELF"]) ?>?page=ops&tab=mantenimiento&do=create">Mantenimiento</a>
        </div>

       <?php if ($opsTab === "crud" && $opsAction === "create"): ?>
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

        <?php elseif ($opsTab === "crud" && $opsAction === "edit"): ?>

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

        <?php else: ?>

         <?php if ($opsTab === "datos"): ?>

            <?php
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

          <?php elseif ($opsTab === "mantenimiento"): ?>

            <?php
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

          <?php else: ?>

            <div class="card">
              <div class="section-title">En construcción</div>
              <p class="tiny">Esta sección la completamos cuando terminemos el CRUD paso a paso.</p>
            </div>

          <?php endif; ?>

        <?php endif; ?>

      <?php else: ?>

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
                    // Evita que se cuele "view" por si acaso
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

                    // ELIMINAR con confirmación (JS) + return al mismo listado con filtros/orden
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

      <?php endif; ?>

    <?php endif; ?>

  </main>

  <!-- Modal para ampliar miniaturas -->
  <div id="imgModal" aria-hidden="true">
    <div class="modal-box">
      <button type="button" class="modal-close" data-close>✕</button>
      <img src="" alt="Documento">
    </div>
  </div>

<?php endif; ?>
</body>
</html>
