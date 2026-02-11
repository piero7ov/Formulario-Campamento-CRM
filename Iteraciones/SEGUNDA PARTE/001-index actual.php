<?php
// 1. Conexión a la base de datos
$db_name = "campamento_verano";
$c = new mysqli("localhost", "campamento_verano", "campamento_verano", $db_name);

if ($c->connect_error) {
    die("Error de conexión: " . $c->connect_error);
}

$mensaje = "";

/* =========================================================
   Helpers para leer límites de subida en PHP (upload/post)
   ========================================================= */

/**
 * Convierte valores tipo "2M", "128M", "1G" de php.ini a bytes.
 */
function iniSizeToBytes(string $val): int {
    $val = trim($val);
    if ($val === '') return 0;

    $last = strtolower($val[strlen($val) - 1]);
    $num  = (float)$val;

    switch ($last) {
        case 'g': return (int)($num * 1024 * 1024 * 1024);
        case 'm': return (int)($num * 1024 * 1024);
        case 'k': return (int)($num * 1024);
        default:  return (int)$num;
    }
}

/**
 * Devuelve el máximo de subida efectivo en bytes:
 * min(upload_max_filesize, post_max_size)
 */
function getEffectiveUploadLimitBytes(): int {
    $uploadMax = iniSizeToBytes((string)ini_get('upload_max_filesize'));
    $postMax   = iniSizeToBytes((string)ini_get('post_max_size'));

    // Si alguno viniera raro (0), devolvemos el otro si existe
    if ($uploadMax <= 0 && $postMax > 0) return $postMax;
    if ($postMax <= 0 && $uploadMax > 0) return $uploadMax;

    return min($uploadMax, $postMax);
}

/**
 * Formatea bytes a MB con 1 decimal.
 */
function bytesToMB(int $bytes): string {
    if ($bytes <= 0) return "0";
    return number_format($bytes / (1024 * 1024), 1, '.', '');
}

/* =========================================================
   Helpers para usar tamaños de tipos SQL en inputs
   ========================================================= */

/**
 * Devuelve la longitud de un tipo VARCHAR(n) o NULL si no aplica.
 */
function getVarcharLength(string $sqlType): ?int {
    if (preg_match('/varchar\((\d+)\)/i', $sqlType, $m)) {
        return (int)$m[1];
    }
    return null;
}

/**
 * Devuelve [precision, scale] para DECIMAL(p,s) o NULL si no aplica.
 */
function getDecimalMeta(string $sqlType): ?array {
    if (preg_match('/decimal\((\d+)\s*,\s*(\d+)\)/i', $sqlType, $m)) {
        return [(int)$m[1], (int)$m[2]];
    }
    return null;
}

/**
 * Calcula el step para DECIMAL según su scale.
 * Ej: scale=2 => 0.01, scale=0 => 1
 */
function decimalStep(int $scale): string {
    if ($scale <= 0) return "1";
    return "0." . str_repeat("0", max(0, $scale - 1)) . "1";
}

/**
 * Calcula un "max" seguro tipo 9999.99 según precision/scale.
 * Ej: (10,2) => 99999999.99
 */
function decimalMaxValue(int $precision, int $scale): string {
    $intDigits = max(1, $precision - $scale);
    $intPart = str_repeat("9", $intDigits);

    if ($scale > 0) {
        $decPart = str_repeat("9", $scale);
        return $intPart . "." . $decPart;
    }
    return $intPart;
}

// 2. Lógica de guardado
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Recuperar metadatos de las columnas
    $meta = $c->query("
        SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_KEY, EXTRA, COLUMN_DEFAULT
        FROM information_schema.columns
        WHERE table_schema='$db_name'
          AND table_name='inscripciones_campamento'
    ");

    $columnas     = [];
    $placeholders = [];
    $valores      = [];
    $tiposBind    = "";

    while ($f = $meta->fetch_assoc()) {
        $campo  = $f['COLUMN_NAME'];
        $tipo   = $f['COLUMN_TYPE'];
        $colKey = $f['COLUMN_KEY'];
        $extra  = $f['EXTRA'];

        // Excluir Primary Key y columnas generadas/automáticas
        if ($colKey == "PRI" || str_contains($extra, "GENERATED") || $f['COLUMN_DEFAULT'] == "CURRENT_TIMESTAMP") {
            continue;
        }

        $valor = null;

        // Determinar el valor y el tipo de bind según el tipo de dato SQL
        if (str_contains($tipo, "tinyint")) {
            // Switch: si no llega en POST es 0
            $valor = isset($_POST[$campo]) ? 1 : 0;
            $tiposBind .= "i";
        }
        else if (str_contains($tipo, "int")) {
            $valor = (isset($_POST[$campo]) && $_POST[$campo] !== "") ? (int)$_POST[$campo] : null;
            $tiposBind .= "i";
        }
        else if (str_contains($tipo, "decimal") || str_contains($tipo, "float")) {
            $valor = (isset($_POST[$campo]) && $_POST[$campo] !== "") ? (float)$_POST[$campo] : null;
            $tiposBind .= "d";
        }
        else if (str_contains($tipo, "blob")) {
            if (isset($_FILES[$campo]) && $_FILES[$campo]['error'] === UPLOAD_ERR_OK) {
                $valor = file_get_contents($_FILES[$campo]['tmp_name']);
                $tiposBind .= "b"; // Tipo blob
            } else {
                continue; // Si no hay archivo, no lo incluimos
            }
        }
        else {
            // varchar, text, date, enum
            $valor = $_POST[$campo] ?? null;
            $tiposBind .= "s";
        }

        $columnas[]     = $campo;
        $placeholders[] = "?";
        $valores[]      = $valor;
    }

    if (!empty($columnas)) {
        $sql = "INSERT INTO inscripciones_campamento (" . implode(",", $columnas) . ") VALUES (" . implode(",", $placeholders) . ")";
        $stmt = $c->prepare($sql);

        if ($stmt) {
            // Preparar parámetros para bind_param
            $params = [$tiposBind];
            foreach ($valores as $key => $val) {
                $params[] = &$valores[$key];
            }

            call_user_func_array([$stmt, 'bind_param'], $params);

            if ($stmt->execute()) {
                $mensaje = "¡Inscripción realizada con éxito!";
            } else {
                $mensaje = "Error al guardar: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $mensaje = "Error al preparar la consulta.";
        }
    } else {
        $mensaje = "No hay datos para guardar.";
    }
}

/**
 * Función para formatear los nombres técnicos
 */
function formatearLabel($texto) {
    $texto = str_replace('_', ' ', $texto);
    $texto = ucfirst($texto);

    $reemplazos = [
        'Sesion' => 'Sesión del campamento',
        'Documento' => 'Resguardo o ficha médica (PDF/Imagen)',
        'Email' => 'Correo electrónico',
        'Telefono' => 'Teléfono de contacto',
        'Permiso fotos' => '¿Autoriza el uso de fotos?'
    ];

    return $reemplazos[$texto] ?? $texto;
}


// Límite real de subida para avisar al usuario (sirve para BLOB/LONGBLOB)
$uploadLimitBytes = getEffectiveUploadLimitBytes();
$uploadLimitMB    = bytesToMB($uploadLimitBytes);
?>

<!doctype html>
<html lang="es">
<head>
    <title>Registro Campamento</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        *{ box-sizing: border-box; }
        body{
            margin: 0; font-family: system-ui, sans-serif;
            background: #f5f7fb; color: #111827;
            display: grid; place-items: center; min-height: 100vh; padding: 24px;
        }
        form{ width: min(520px, 100%); }
        fieldset{
            border: 1px solid #e5e7eb; background: #ffffff;
            border-radius: 16px; padding: 22px;
            box-shadow: 0 10px 25px rgba(0,0,0,.07);
        }
        legend{ font-weight: 700; padding: 0 10px; color: #0f172a; font-size: 1.2rem; }

        .helper{
            margin-top: 10px;
            padding: 10px 12px;
            border-radius: 10px;
            background: #eef6ff;
            border: 1px solid #cfe5ff;
            color: #0b4a7a;
            font-size: 0.9rem;
            line-height: 1.35;
        }

        .control_formulario{ margin-top: 18px; }
        .control_formulario label{ display: block; margin: 0 0 6px; font-size: 0.95rem; font-weight: 600; color: #1f2937; }
        .comentario-sql { margin: 6px 0 0; font-size: 0.85rem; color: #6b7280; font-style: italic; }

        .info-upload{
            margin: 8px 0 0;
            padding: 10px 12px;
            border-radius: 10px;
            background: #f7f9ff;
            border: 1px solid #e5e7eb;
            color: #334155;
            font-size: 0.88rem;
            line-height: 1.35;
        }

        input[type="text"], input[type="date"], input[type="number"], input[type="file"], select, textarea {
            width: 100%; padding: 12px 14px; border: 1px solid #d1d5db; border-radius: 12px; outline: none;
        }
        textarea{ min-height: 100px; resize: vertical; }
        input:focus, select:focus, textarea:focus{ border-color: #2563eb; box-shadow: 0 0 0 4px rgba(37, 99, 235, .15); }

        input[type="submit"]{
            margin-top: 24px; width: 100%; padding: 14px; border: 0; border-radius: 12px;
            font-weight: 700; cursor: pointer; color: white;
            background: linear-gradient(135deg, #0ea5e9, #1e3a8a);
        }

        /* SWITCH ESTILOS */
        .control_formulario--switch{ display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        .control_formulario--switch label { flex: 1; margin: 0; }
        input.switch{
            appearance: none; width: 52px; height: 28px; border-radius: 999px;
            background: #e5e7eb; position: relative; cursor: pointer; transition: background .3s;
        }
        input.switch::after{
            content: ""; position: absolute; top: 3px; left: 4px; width: 22px; height: 22px;
            border-radius: 50%; background: white; transition: left .3s;
        }
        input.switch:checked{ background: #22c55e; }
        input.switch:checked::after{ left: 26px; }
        .switch-comentario { width: 100%; }

        .alert { padding: 12px; border-radius: 8px; margin-bottom: 20px; background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
    </style>
</head>
<body>

    <form action="" method="POST" enctype="multipart/form-data">
        <fieldset>
            <legend>Registro Campamento de Verano</legend>

            <div class="helper">
                Completa los datos y pulsa <strong>Finalizar Inscripción</strong>.<br>
                Los campos de texto tienen un límite de caracteres y los importes aceptan solo decimales válidos.
            </div>

            <?php if ($mensaje): ?>
                <div class="alert"><?= htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php
            // Consultar columnas para generar el formulario
            $r = $c->query("SHOW FULL COLUMNS FROM inscripciones_campamento;");

            while($f = $r->fetch_assoc()){
                // Exclusión de Primary Key o autogenerados
                if($f['Key'] == "PRI" || str_contains($f['Extra'], "DEFAULT_GENERATED")) continue;

                $field   = $f['Field'];
                $sqlType = strtolower($f['Type']);
                $labelHumano = formatearLabel($field);

                // Comentario SQL (escapado)
                $comentario = '';
                if (!empty($f['Comment'])) {
                    $comentario = '<p class="comentario-sql">'.htmlspecialchars($f['Comment'], ENT_QUOTES, 'UTF-8').'</p>';
                }

                // --- TINYINT (Switch) ---
                if (str_contains($sqlType, "tinyint")) {
                    echo '<div class="control_formulario control_formulario--switch">';
                    echo '<label for="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars($labelHumano, ENT_QUOTES, 'UTF-8').'</label>';
                    echo '<input class="switch" type="checkbox" name="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'" id="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'" value="1">';
                    if($comentario) echo '<div class="switch-comentario">'.$comentario.'</div>';
                    echo '</div>';
                }
                else {
                    echo '<div class="control_formulario">';
                    echo '<label for="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars($labelHumano, ENT_QUOTES, 'UTF-8').'</label>';

                    // --- VARCHAR(n) -> maxlength ---
                    if (str_contains($sqlType, "varchar")) {
                        $maxLen = getVarcharLength($sqlType);
                        $maxAttr = $maxLen ? ' maxlength="'.$maxLen.'" title="Máximo '.$maxLen.' caracteres"' : '';

                        echo '<input type="text" name="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'" id="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'"'.$maxAttr.'>';
                    }
                    // --- DATE ---
                    else if ($sqlType === "date") {
                        echo '<input type="date" name="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'" id="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'">';
                    }
                    // --- INT ---
                    else if (str_contains($sqlType, "int")) {
                        echo '<input type="number" name="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'" id="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'" step="1">';
                    }
                    // --- DECIMAL(p,s) -> step + max ---
                    else if (str_contains($sqlType, "decimal")) {
                        $metaDec = getDecimalMeta($sqlType);
                        $step = "0.01";
                        $max = "";

                        if ($metaDec) {
                            [$p, $s] = $metaDec;
                            $step = decimalStep($s);
                            $max  = decimalMaxValue($p, $s);
                        }

                        $maxAttr = $max !== "" ? ' max="'.$max.'"' : '';
                        echo '<input type="number" inputmode="decimal" name="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'" id="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'" step="'.$step.'"'.$maxAttr.'>';
                    }
                    // --- ENUM ---
                    else if (str_contains($sqlType, "enum")) {
                        echo '<select name="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'" id="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'">';
                        echo '<option value="" selected disabled>Seleccione una opción...</option>';
                        preg_match_all("/'([^']+)'/", $f['Type'], $m);
                        foreach($m[1] as $op) {
                            echo '<option value="'.htmlspecialchars($op, ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars(ucfirst($op), ENT_QUOTES, 'UTF-8').'</option>';
                        }
                        echo '</select>';
                    }
                    // --- TEXT ---
                    else if (str_contains($sqlType, "text")) {
                        echo '<textarea name="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'" id="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'"></textarea>';
                    }
                    // --- BLOB / LONGBLOB (archivo) ---
                    else if (str_contains($sqlType, "blob")) {
                        // input file
                        echo '<input type="file" name="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'" id="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'" accept=".pdf,.jpg,.jpeg,.png,.webp">';

                        // Mensaje para el usuario (claro y no técnico)
                        $tipoArchivo = str_contains($sqlType, "longblob") ? "archivo grande" : "archivo";
                        echo '<div class="info-upload">';
                        echo '<strong>Subida de '.$tipoArchivo.':</strong><br>';
                        echo '• Tamaño máximo permitido por el servidor: <strong>'.$uploadLimitMB.' MB</strong>.<br>';
                        echo '• Formatos recomendados: PDF o imagen (JPG/PNG/WebP).<br>';
                        echo '• Si el archivo supera el límite, la subida fallará automáticamente.';
                        echo '</div>';
                    }
                    // --- fallback ---
                    else {
                        echo '<input type="text" name="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'" id="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'">';
                    }

                    echo $comentario;
                    echo '</div>';
                }
            }
            ?>

            <input type="submit" value="Finalizar Inscripción">
        </fieldset>
    </form>
</body>
</html>
