<?php
// ============================================================
// 1) CONEXIÓN A LA BASE DE DATOS
// ============================================================
$db_name = "campamento_verano";
$c = new mysqli("localhost", "campamento_verano", "campamento_verano", $db_name);

if ($c->connect_error) {
    die("Error de conexión: " . $c->connect_error);
}

$mensaje = "";
$errores = [];

// ============================================================
// 2) CAMPOS OBLIGATORIOS (los que pediste)
// ============================================================
$camposObligatorios = [
    'nombre',
    'apellidos',
    'email',
    'telefono',
    'sesion',
    'fecha_inscripcion',
    'edad',
    'contacto_emergencia',
    'telefono_emergencia'
];

// ============================================================
// 3) HELPERS PARA PARSEAR TIPOS SQL Y TAMAÑOS
// ============================================================

/**
 * Convierte tamaños tipo "2M", "128M", "1G" a bytes.
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

/**
 * Pasa bytes a formato humano (MB/GB).
 */
function bytesToHuman(int $bytes): string {
    if ($bytes <= 0) return "desconocido";
    $units = ['B','KB','MB','GB','TB'];
    $i = 0;
    $v = (float)$bytes;
    while ($v >= 1024 && $i < count($units) - 1) {
        $v /= 1024;
        $i++;
    }
    return rtrim(rtrim(number_format($v, 2), '0'), '.') . ' ' . $units[$i];
}

/**
 * Extrae longitud de un tipo como varchar(150) -> 150
 * Retorna null si no aplica.
 */
function sqlLength(?string $columnType): ?int {
    if (!$columnType) return null;
    if (preg_match('/\((\d+)\)/', $columnType, $m)) {
        return (int)$m[1];
    }
    return null;
}

/**
 * Extrae precisión y escala de decimal(p,s) -> [p,s]
 * Retorna null si no es decimal.
 */
function sqlDecimalPS(?string $columnType): ?array {
    if (!$columnType) return null;
    if (preg_match('/decimal\((\d+),\s*(\d+)\)/i', $columnType, $m)) {
        return [(int)$m[1], (int)$m[2]];
    }
    return null;
}

/**
 * Determina si un campo es "tipo archivo" (blob/longblob).
 */
function isBlobType(string $sqlType): bool {
    $t = strtolower($sqlType);
    return (strpos($t, 'blob') !== false);
}

/**
 * Formatea los nombres técnicos a etiquetas humanas (front).
 */
function formatearLabel(string $texto): string {
    $texto = str_replace('_', ' ', $texto);
    $texto = ucfirst($texto);

    $reemplazos = [
        'Sesion'               => 'Sesión del campamento',
        'Email'                => 'Correo electrónico',
        'Telefono'             => 'Teléfono de contacto',
        'Permiso fotos'        => '¿Autoriza el uso de fotos?',
        'Contacto emergencia'  => 'Contacto de emergencia',
        'Telefono emergencia'  => 'Teléfono de emergencia',
        'Fecha inscripcion'    => 'Fecha de inscripción',
        'Documento'            => 'Resguardo o ficha médica (PDF/Imagen)',
    ];

    return $reemplazos[$texto] ?? $texto;
}

/**
 * Etiqueta "bonita" para errores (sin mostrar el nombre técnico).
 */
function labelBonito(string $campo): string {
    return formatearLabel($campo);
}

/**
 * Devuelve true si el campo es obligatorio según tu lista.
 */
function esObligatorio(string $campo, array $lista): bool {
    return in_array($campo, $lista, true);
}

// ============================================================
// 4) LÓGICA DE GUARDADO (POST)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 4.1 Validación (solo PHP, sin JS)
    foreach ($camposObligatorios as $campoReq) {
        $v = trim((string)($_POST[$campoReq] ?? ''));
        if ($v === '') {
            $errores[] = "El campo <strong>" . htmlspecialchars(labelBonito($campoReq), ENT_QUOTES, 'UTF-8') . "</strong> es obligatorio.";
        }
    }

    // Email válido
    if (!empty($_POST['email']) && !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        $errores[] = "El <strong>correo electrónico</strong> no tiene un formato válido.";
    }

    // Edad válida (>0)
    if (isset($_POST['edad']) && trim((string)$_POST['edad']) !== '') {
        if (!ctype_digit((string)$_POST['edad']) || (int)$_POST['edad'] <= 0) {
            $errores[] = "La <strong>edad</strong> debe ser un número mayor que 0.";
        }
    }

    // Si hay errores, no intentamos insertar
    if (!empty($errores)) {
        $mensaje = implode("<br>", $errores);
    } else {

        // 4.2 Recuperar metadatos de las columnas para armar el INSERT dinámico
        $meta = $c->query("
            SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_KEY, EXTRA, COLUMN_DEFAULT
            FROM information_schema.columns
            WHERE table_schema='$db_name'
              AND table_name='inscripciones_campamento'
        ");

        $columnas      = [];
        $placeholders  = [];
        $valores       = [];
        $tiposBind     = "";
        $blobPositions = []; // posiciones (0-based) de parámetros blob para send_long_data()

        while ($f = $meta->fetch_assoc()) {
            $campo   = $f['COLUMN_NAME'];
            $tipo    = $f['COLUMN_TYPE'] ?? '';
            $colKey  = $f['COLUMN_KEY'] ?? '';
            $extra   = $f['EXTRA'] ?? '';
            $def     = $f['COLUMN_DEFAULT'];

            // Excluir PK, generados y timestamps automáticos
            $defUpper = is_string($def) ? strtoupper($def) : '';
            if (
                $colKey === "PRI" ||
                stripos($extra, "GENERATED") !== false ||
                ($defUpper !== '' && strpos($defUpper, "CURRENT_TIMESTAMP") !== false)
            ) {
                continue;
            }

            $valor = null;

            // tinyint -> checkbox switch (0/1)
            if (stripos($tipo, "tinyint") !== false) {
                $valor = isset($_POST[$campo]) ? 1 : 0;
                $tiposBind .= "i";
            }
            // int
            else if (stripos($tipo, "int") !== false) {
                $valor = (isset($_POST[$campo]) && $_POST[$campo] !== "") ? (int)$_POST[$campo] : null;
                $tiposBind .= "i";
            }
            // decimal / float
            else if (stripos($tipo, "decimal") !== false || stripos($tipo, "float") !== false) {
                $valor = (isset($_POST[$campo]) && $_POST[$campo] !== "") ? (float)$_POST[$campo] : null;
                $tiposBind .= "d";
            }
            // blob / longblob
            else if (isBlobType($tipo)) {
                if (isset($_FILES[$campo]) && $_FILES[$campo]['error'] === UPLOAD_ERR_OK) {
                    $valor = file_get_contents($_FILES[$campo]['tmp_name']);
                    // 'b' requiere send_long_data para funcionar bien con mysqli
                    $tiposBind .= "b";
                    $blobPositions[] = count($valores); // posición del parámetro blob dentro de $valores
                } else {
                    // Si no adjunta archivo, no incluimos este campo en el INSERT
                    continue;
                }
            }
            // varchar/text/date/enum...
            else {
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
                // bind_param con referencias
                $params = [$tiposBind];
                foreach ($valores as $k => $v) {
                    $params[] = &$valores[$k];
                }

                call_user_func_array([$stmt, 'bind_param'], $params);

                // Enviar blobs (si existen)
                foreach ($blobPositions as $pos) {
                    // send_long_data usa index de parámetro 0-based
                    $stmt->send_long_data($pos, $valores[$pos]);
                }

                if ($stmt->execute()) {
                    $mensaje = "¡Inscripción realizada con éxito! Te contactaremos por correo si hace falta confirmar algún dato.";
                    $_POST = []; // limpiar
                } else {
                    $mensaje = "Error al guardar: " . htmlspecialchars($stmt->error, ENT_QUOTES, 'UTF-8');
                }

                $stmt->close();
            } else {
                $mensaje = "Error al preparar la consulta.";
            }
        } else {
            $mensaje = "No se detectaron campos para guardar.";
        }
    }
}

// ============================================================
// 5) INFO DE LÍMITES DE SUBIDA (para mostrar al usuario)
// ============================================================
$uploadMax  = iniSizeToBytes((string)ini_get('upload_max_filesize'));
$postMax    = iniSizeToBytes((string)ini_get('post_max_size'));
$limiteReal = 0;

if ($uploadMax > 0 && $postMax > 0) {
    $limiteReal = min($uploadMax, $postMax);
} else {
    $limiteReal = max($uploadMax, $postMax);
}

$limiteTexto = bytesToHuman($limiteReal);

?>
<!doctype html>
<html lang="es">
<head>
    <title>Registro Campamento</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
        *{ box-sizing: border-box; }
        body{
            margin: 0;
            font-family: system-ui, sans-serif;
            background: #f5f7fb;
            color: #111827;
            display: grid;
            place-items: center;
            min-height: 100vh;
            padding: 24px;
        }
        form{ width: min(700px, 100%); }

        fieldset{
            border: 1px solid #e5e7eb;
            background: #ffffff;
            border-radius: 16px;
            padding: 22px;
            box-shadow: 0 10px 25px rgba(0,0,0,.07);
            position: relative; /* para el botón arriba derecha */
        }

        legend{
            font-weight: 800;
            padding: 0 10px;
            color: #0f172a;
            font-size: 1.2rem;
        }

        /* BOTÓN LIMPIAR ARRIBA DERECHA */
        .btn-limpiar-top{
            position: absolute;
            top: 16px;
            right: 16px;
            text-decoration: none;
            font-weight: 900;
            font-size: 12px;
            padding: 8px 10px;
            border-radius: 999px;
            border: 1px solid #e5e7eb;
            background: #ffffff;
            color: #0f172a;
            box-shadow: 0 6px 16px rgba(0,0,0,.06);
            cursor: pointer;
            user-select: none;
        }
        .btn-limpiar-top:hover{
            background: #f8fafc;
            border-color: #d1d5db;
        }
        .btn-limpiar-top:active{
            transform: translateY(1px);
            box-shadow: 0 2px 10px rgba(0,0,0,.06);
        }

        .control_formulario{ margin-top: 18px; }

        .control_formulario label{
            display: block;
            margin: 0 0 6px;
            font-size: 0.95rem;
            font-weight: 700;
            color: #1f2937;
        }

        .req{
            color:#ef4444;
            margin-left:6px;
            font-weight:900;
        }

        .comentario-sql{
            margin: 6px 0 0;
            font-size: 0.85rem;
            color: #6b7280;
            font-style: italic;
        }

        .help{
            margin: 6px 0 0;
            font-size: 0.85rem;
            color: #475569;
        }

        input[type="text"], input[type="date"], input[type="number"], input[type="email"], input[type="tel"],
        input[type="file"], select, textarea{
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #d1d5db;
            border-radius: 12px;
            outline: none;
            background: #fff;
        }

        textarea{ min-height: 100px; resize: vertical; }

        input:focus, select:focus, textarea:focus{
            border-color: #2563eb;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, .15);
        }

        input[type="submit"]{
            margin-top: 24px;
            width: 100%;
            padding: 14px;
            border: 0;
            border-radius: 12px;
            font-weight: 800;
            cursor: pointer;
            color: white;
            background: linear-gradient(135deg, #0ea5e9, #1e3a8a);
        }

        /* SWITCH */
        .control_formulario--switch{
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
        .control_formulario--switch label { flex: 1; margin: 0; }
        input.switch{
            appearance: none;
            width: 52px;
            height: 28px;
            border-radius: 999px;
            background: #e5e7eb;
            position: relative;
            cursor: pointer;
            transition: background .3s;
        }
        input.switch::after{
            content: "";
            position: absolute;
            top: 3px; left: 4px;
            width: 22px; height: 22px;
            border-radius: 50%;
            background: white;
            transition: left .3s;
        }
        input.switch:checked{ background: #22c55e; }
        input.switch:checked::after{ left: 26px; }
        .switch-comentario{ width: 100%; }

        .alert{
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 20px;
            background: #e0f2fe;
            color: #0369a1;
            border: 1px solid #bae6fd;
            line-height: 1.35;
        }

        .note{
            margin-top: 14px;
            padding: 10px 12px;
            border-radius: 10px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            color:#334155;
            font-size: 0.9rem;
            line-height: 1.35;
        }
    </style>
</head>
<body>

<form action="" method="POST" enctype="multipart/form-data">
    <fieldset>
        <legend>Registro Campamento de Verano</legend>

        <!-- Botón Limpiar arriba a la derecha -->
        <a class="btn-limpiar-top"
           href="<?= htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8') ?>"
           onclick="return confirm('¿Seguro que quieres limpiar el formulario? Se perderán los datos escritos.');">
           Limpiar
        </a>

        <?php if (!empty($mensaje)): ?>
            <div class="alert"><?= $mensaje; ?></div>
        <?php endif; ?>

        <?php
        // Consultar columnas para generar el formulario
        $r = $c->query("SHOW FULL COLUMNS FROM inscripciones_campamento;");

        while($f = $r->fetch_assoc()){
            // Omitir PK/autogenerados
            if (($f['Key'] ?? '') === "PRI" || stripos(($f['Extra'] ?? ''), "DEFAULT_GENERATED") !== false) {
                continue;
            }

            $field = $f['Field'];
            $type  = $f['Type'] ?? '';
            $labelHumano = formatearLabel($field);

            $isReq = esObligatorio($field, $camposObligatorios);

            // Comentario SQL (si existe)
            $commentRaw = $f['Comment'] ?? '';
            $comentario = '';
            if (!empty($commentRaw)) {
                $comentario = '<p class="comentario-sql">'.htmlspecialchars($commentRaw, ENT_QUOTES, 'UTF-8').'</p>';
            }

            // Valor persistente si hubo error
            $valorOld = $_POST[$field] ?? '';

            // ----------- TINYINT = SWITCH -----------
            if (stripos($type, "tinyint") !== false) {
                $checked = isset($_POST[$field]) ? 'checked' : '';
                echo '<div class="control_formulario control_formulario--switch">';
                echo '<label for="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'">'.$labelHumano.($isReq ? '<span class="req">*</span>' : '').'</label>';
                echo '<input class="switch" type="checkbox" name="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'" id="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'" value="1" '.$checked.'>';
                if($comentario) echo '<div class="switch-comentario">'.$comentario.'</div>';
                echo '</div>';
                continue;
            }

            echo '<div class="control_formulario">';
            echo '<label for="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'">'.$labelHumano.($isReq ? '<span class="req">*</span>' : '').'</label>';

            // Longitud varchar(n)
            $maxLen = null;
            if (stripos($type, "varchar") !== false) {
                $maxLen = sqlLength($type);
            }

            // ----------- VARCHAR -----------
            if (stripos($type, "varchar") !== false) {

                $inputType = "text";
                if (stripos($field, "email") !== false || stripos($field, "correo") !== false) {
                    $inputType = "email";
                } else if (stripos($field, "telefono") !== false) {
                    $inputType = "tel";
                }

                echo '<input type="'.$inputType.'" name="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'" id="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'"'
                   . ($isReq ? ' required' : '')
                   . ($maxLen ? ' maxlength="'.$maxLen.'"' : '')
                   . ' value="'.htmlspecialchars((string)$valorOld, ENT_QUOTES, 'UTF-8').'">';

                if ($maxLen) {
                    echo '<p class="help">Máximo: '.$maxLen.' caracteres.</p>';
                }
            }
            // ----------- DATE -----------
            else if (strtolower($type) === "date") {
                echo '<input type="date" name="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'" id="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'"'
                   . ($isReq ? ' required' : '')
                   . ' value="'.htmlspecialchars((string)$valorOld, ENT_QUOTES, 'UTF-8').'">';
            }
            // ----------- DECIMAL(p,s) -----------
            else if (stripos($type, "decimal") !== false) {
                $ps = sqlDecimalPS($type);
                $p = $ps ? $ps[0] : 10;
                $s = $ps ? $ps[1] : 2;

                $step = "1";
                if ($s > 0) {
                    $step = "0." . str_repeat("0", $s - 1) . "1";
                }

                echo '<input type="number" name="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'" id="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'" step="'.$step.'"'
                   . ($isReq ? ' required' : '')
                   . ' value="'.htmlspecialchars((string)$valorOld, ENT_QUOTES, 'UTF-8').'">';

                $enteros = max(1, $p - $s);
                echo '<p class="help">Formato: hasta '.$enteros.' dígitos enteros y '.$s.' decimales.</p>';
            }
            // ----------- INT -----------
            else if (stripos($type, "int") !== false) {
                echo '<input type="number" name="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'" id="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'"'
                   . ($isReq ? ' required' : '')
                   . ' value="'.htmlspecialchars((string)$valorOld, ENT_QUOTES, 'UTF-8').'">';
            }
            // ----------- ENUM -----------
            else if (stripos($type, "enum") !== false) {
                echo '<select name="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'" id="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'"'
                   . ($isReq ? ' required' : '')
                   . '>';
                echo '<option value="" selected disabled>Seleccione una opción...</option>';

                preg_match_all("/'([^']+)'/", $type, $m);
                foreach($m[1] as $op) {
                    $selected = ((string)$valorOld === (string)$op) ? ' selected' : '';
                    echo '<option value="'.htmlspecialchars($op, ENT_QUOTES, 'UTF-8').'"'.$selected.'>'.htmlspecialchars(ucfirst($op), ENT_QUOTES, 'UTF-8').'</option>';
                }
                echo '</select>';
            }
            // ----------- TEXT -----------
            else if (stripos($type, "text") !== false) {
                echo '<textarea name="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'" id="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'"'
                   . ($isReq ? ' required' : '')
                   . '>'.htmlspecialchars((string)$valorOld, ENT_QUOTES, 'UTF-8').'</textarea>';
            }
            // ----------- BLOB/LONGBLOB -----------
            else if (isBlobType($type)) {
                echo '<input type="file" name="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'" id="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'" accept="application/pdf,image/*">';
                echo '<p class="help">Puedes subir PDF o imagen. Tamaño máximo permitido por el servidor: <strong>'.$limiteTexto.'</strong>.</p>';
            }
            // ----------- DEFAULT -----------
            else {
                echo '<input type="text" name="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'" id="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'"'
                   . ($isReq ? ' required' : '')
                   . ' value="'.htmlspecialchars((string)$valorOld, ENT_QUOTES, 'UTF-8').'">';
            }

            echo $comentario;
            echo '</div>';
        }
        ?>

        <input type="submit" value="Finalizar Inscripción">

        <div class="note">
            Revisa que los datos estén correctos antes de enviar. Los campos marcados con <strong>*</strong> son obligatorios.
        </div>
    </fieldset>
</form>

</body>
</html>
