<?php
/**
 * includes/functions.php
 * Funciones de ayuda generales y de auditoría.
 */

/* -------------------------------------------------------------------------- */
/*                                  GENERAL                                   */
/* -------------------------------------------------------------------------- */

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

/**
 * Convierte datetime-local (YYYY-MM-DDTHH:MM) a MySQL DATETIME
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
 * Estilado/clases activas nav
 */
function nav_active($cond){
  return $cond ? " is-active" : "";
}


/* -------------------------------------------------------------------------- */
/*                           AUDITORÍA / LOGS                                 */
/* -------------------------------------------------------------------------- */

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


/* -------------------------------------------------------------------------- */
/*                           DATA / FILES HELPERS                             */
/* -------------------------------------------------------------------------- */

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

function is_blob_datatype($dt){
  $dt = strtolower((string)$dt);
  return in_array($dt, ['blob','mediumblob','longblob','tinyblob'], true);
}

function detect_mime($bin){
  if (!is_string($bin) || $bin === '') return 'application/octet-stream';
  if (substr($bin, 0, 4) === "%PDF") return "application/pdf";
  if (substr($bin, 0, 3) === "\xFF\xD8\xFF") return 'image/jpeg';
  if (substr($bin, 0, 8) === "\x89PNG\x0D\x0A\x1A\x0A") return 'image/png';
  return 'application/octet-stream';
}

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

function is_boolish_col(array $meta){
  $dt = strtolower((string)($meta["DATA_TYPE"] ?? ""));
  $ct = strtolower((string)($meta["COLUMN_TYPE"] ?? ""));
  return ($dt === "tinyint" && preg_match('/\(\s*1\s*\)/', $ct));
}

function parse_enum_options($columnType){
  $opts = [];
  if (!is_string($columnType)) return $opts;
  if (stripos($columnType, "enum(") !== 0) return $opts;

  if (preg_match_all("/'((?:\\\\'|[^'])*)'/", $columnType, $m)) {
    foreach ($m[1] as $raw) $opts[] = str_replace("\\'", "'", $raw);
  }
  return $opts;
}

function findEmailColumn(array $cols){
  foreach($cols as $col){
    $lc = strtolower($col);
    if (strpos($lc, 'email') !== false || strpos($lc, 'mail') !== false || strpos($lc, 'correo') !== false) {
      return $col;
    }
  }
  return null;
}

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
