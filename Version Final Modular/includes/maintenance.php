<?php
/**
 * includes/maintenance.php
 * Funciones de backup (dump MySQL a .sql).
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

function maintenance_is_numeric_type(string $dt): bool {
  static $num = [
    "int","bigint","smallint","mediumint","tinyint",
    "decimal","float","double","bit"
  ];
  return in_array($dt, $num, true);
}

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
