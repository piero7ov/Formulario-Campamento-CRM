<?php
/**
 * includes/actions_get.php
 * Procesa acciones GET que retornan archivos o binarios y terminan la ejecución.
 * (Imágenes, descargas de backups, exportaciones CSV/SQL).
 */

if (!$loggedIn) return;

/* =========================================================
   15) ENDPOINT ARCHIVO (BLOB -> ver/descargar)
   ========================================================= */
if (isset($_GET["img"]) && $_GET["img"] == "1") {
  $id  = (string)($_GET["id"] ?? "");
  $col = (string)($_GET["col"] ?? "");
  $dl  = (isset($_GET["download"]) && $_GET["download"] == "1");

  if ($id === "" || $col === "") die("Faltan parámetros.");

  // Verificar que la columna es blob
  $isBlob = false;
  $r = $c->query("SELECT DATA_TYPE FROM information_schema.columns WHERE table_schema='".$c->real_escape_string($db_name)."' AND table_name='".$c->real_escape_string($table_name)."' AND COLUMN_NAME='".$c->real_escape_string($col)."'");
  if ($r && ($f = $r->fetch_assoc())) {
     if (is_blob_datatype($f["DATA_TYPE"])) $isBlob = true;
  }
  if (!$isBlob) die("Columna no válida o no es binaria.");

  // Obtener PK
  $pkCol = null;
  $r = $c->query("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema='".$c->real_escape_string($db_name)."' AND table_name='".$c->real_escape_string($table_name)."' AND COLUMN_KEY='PRI' LIMIT 1");
  if ($r && ($f=$r->fetch_assoc())) $pkCol = $f["COLUMN_NAME"];
  if (!$pkCol) die("No hay PK definida.");

  $stmt = $c->prepare("SELECT `$col` FROM `$table_name` WHERE `$pkCol` = ? LIMIT 1");
  if ($stmt) {
    $stmt->bind_param("s", $id);
    $stmt->execute();
    $stmt->bind_result($binData);
    if ($stmt->fetch()) {
      if ($binData) {
        $mime = detect_mime($binData);
        header("Content-Type: $mime");
        header("Content-Length: " . strlen($binData));
        if ($dl) {
          $ext = ($mime==="application/pdf") ? "pdf" : ( ($mime==="image/png") ? "png" : "jpg" );
          header("Content-Disposition: attachment; filename=\"doc_{$col}_{$id}.{$ext}\"");
        }
        echo $binData;
      } else {
        http_response_code(404);
        echo "Vacio";
      }
    } else {
      http_response_code(404);
      echo "No encontrado";
    }
    $stmt->close();
  }
  exit;
}

/* =========================================================
   15.B) ENDPOINT BACKUP (descarga .sql)
   ========================================================= */
if (isset($_GET["backup"]) && $_GET["backup"] == "1") {
  $file = basename((string)$_GET["file"]);
  if ($file === "") die("No file");
  $path = rtrim($BACKUP_DIR, "/\\") . DIRECTORY_SEPARATOR . $file;

  if (file_exists($path)) {
    header("Content-Description: File Transfer");
    header("Content-Type: application/octet-stream");
    header("Content-Disposition: attachment; filename=\"$file\"");
    header("Expires: 0");
    header("Cache-Control: must-revalidate");
    header("Pragma: public");
    header("Content-Length: " . filesize($path));
    readfile($path);
    exit;
  }
  die("Archivo no encontrado.");
}

/* =========================================================
   14.5) EXPORTACIÓN (CSV / SQL)
   ========================================================= */
if (isset($_GET["export"])) {
  $mode = $_GET["export"]; // csv, sql
  $now  = date("Ymd_His");

  // Re-calculamos cols para exportar
  $cols = []; $colsMeta = []; $blobCols = [];
  $r = $c->query("SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.columns WHERE table_schema='".$c->real_escape_string($db_name)."' AND table_name='".$c->real_escape_string($table_name)."' ORDER BY ORDINAL_POSITION");
  while($r && ($f=$r->fetch_assoc())){
    $cols[] = $f["COLUMN_NAME"];
    $colsMeta[$f["COLUMN_NAME"]] = $f;
    if (is_blob_datatype($f["DATA_TYPE"])) $blobCols[$f["COLUMN_NAME"]] = true;
  }
  $pkCol = null;
  $r = $c->query("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema='".$c->real_escape_string($db_name)."' AND table_name='".$c->real_escape_string($table_name)."' AND COLUMN_KEY='PRI' LIMIT 1");
  if ($r && ($f=$r->fetch_assoc())) $pkCol = $f["COLUMN_NAME"];


  if ($mode === "csv") {
    // CSV logic (mismo listado pero dump a CSV)
    // Reconstruimos filtros
    $filtersText = (isset($_GET["f"]) && is_array($_GET["f"])) ? $_GET["f"] : [];
    $filtersBlob = (isset($_GET["fb"]) && is_array($_GET["fb"])) ? $_GET["fb"] : [];
    foreach($filtersText as $k=>$v){ if(is_string($k)) $filtersText[$k] = trim((string)$v); }
    foreach($filtersBlob as $k=>$v){ if(is_string($k)) $filtersBlob[$k] = trim((string)$v); }

    $where = ["1=1"];
    $bindTypes=""; $bindVals=[];

    foreach($filtersText as $col=>$val){
      if($val==="")continue;
      $where[] = "CAST(`$col` AS CHAR) LIKE ?";
      $bindTypes.="s"; $bindVals[]="%".$val."%";
    }
    foreach($filtersBlob as $col=>$flag){
      if($flag==="")continue;
      if($flag==="1") $where[]="OCTET_LENGTH(`$col`) > 0";
      elseif($flag==="0") $where[]="(`$col` IS NULL OR OCTET_LENGTH(`$col`) = 0)";
    }

    $sortCol = $_GET["sort"] ?? "";
    $sortDir = $_GET["dir"] ?? "asc";
    // Seguridad sort
    $validSort = false;
    foreach($cols as $cl) if($cl===$sortCol) $validSort=true;
    if(!$validSort) $sortCol = "";

    // Proyección (sin blobs pesados, solo len)
    $projection = build_select_projection($cols, $blobCols);

    $sql = "SELECT $projection FROM `$table_name` WHERE ".implode(" AND ", $where);
    if($sortCol!=="") $sql .= " ORDER BY `$sortCol` ".strtoupper($sortDir==="desc"?"DESC":"ASC");
    elseif($pkCol)    $sql .= " ORDER BY `$pkCol` DESC";

    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=export_{$now}.csv");

    $out = fopen("php://output", "w");
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8

    // Headers
    // Agregamos Estado CRM
    $csvHeaders = $cols;
    $csvHeaders[] = "Estado_CRM";
    fputcsv($out, array_map('labelize', $csvHeaders));

    // Consumo DB
    $stmt = $c->prepare($sql);
    if ($stmt) {
      if ($bindTypes!=="") {
        $params = array_merge([$bindTypes], $bindVals);
        $refs=[]; foreach($params as $i=>$v) $refs[$i]=&$params[$i];
        call_user_func_array([$stmt,"bind_param"], $refs);
      }
      $stmt->execute();
      $res = $stmt->get_result();

      // Precargar estados
      $mapEstados = [];
      $qe = $c->query("SELECT id_registro, estado FROM crm_estados_inscripciones");
      while($qe && $re=$qe->fetch_assoc()) $mapEstados[$re["id_registro"]] = $re["estado"];

      while($row = $res->fetch_assoc()){
        $line = [];
        $idReg = $pkCol ? ($row[$pkCol]??null) : null;

        foreach($cols as $col){
          if(isset($blobCols[$col])){
            $lenKey = "__len_$col";
            $len = (int)($row[$lenKey]??0);
            $line[] = ($len>0) ? "[Binario: ".bytesToHuman($len)."]" : "";
          } else {
            $line[] = $row[$col];
          }
        }
        $line[] = ($idReg && isset($mapEstados[$idReg])) ? $mapEstados[$idReg] : "";

        fputcsv($out, $line);
      }
      $stmt->close();
    }
    fclose($out);
    exit;

  } elseif ($mode === "sql") {
    // SQL Dump (solo tabla inscripciones)
    // El formato es un .sql descargable
    $outFile = ""; $outBytes = 0; $outErr = "";
    // Reutilizamos maintenance_make_backup solo para esta tabla?
    // maintenance_make_backup crea archivo en disco.
    // Podemos usar maintenance_dump_table directamente a php://output
    header("Content-Type: application/sql; charset=utf-8");
    header("Content-Disposition: attachment; filename=dump_{$table_name}_{$now}.sql");

    $out = fopen("php://output", "w");
    fwrite($out, "-- Dump tabla $table_name\n-- Date: ".date("Y-m-d H:i:s")."\n\n");
    
    $stats = [];
    $err = "";
    $ok = maintenance_dump_table($c, $db_name, $table_name, $out, $stats, $err);
    if(!$ok) fwrite($out, "\n-- Error: $err\n");

    fclose($out);
    exit;
  }
}
