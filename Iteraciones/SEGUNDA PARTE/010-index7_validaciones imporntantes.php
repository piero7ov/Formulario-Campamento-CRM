<?php
session_start();

// ============================================================
// 1) CONEXIÓN A LA BASE DE DATOS
// ============================================================
$db_name = "campamento_verano";
$c = new mysqli("localhost", "campamento_verano", "campamento_verano", $db_name);

if ($c->connect_error) {
    die("Error de conexión: " . $c->connect_error);
}
$c->set_charset("utf8mb4");

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

/**
 * Limpia datos "pendientes" en sesión (si existieran en el futuro).
 */
function limpiarPendiente(): void {
    unset($_SESSION['pending_post'], $_SESSION['pending_files']);
}

/**
 * VALIDACIÓN EXTRA: teléfono "razonable"
 * - Permite +, espacios, (), guiones
 * - Exige 7 a 15 dígitos reales
 */
function telefonoValido(?string $telefono): bool {
    $t = trim((string)$telefono);
    if ($t === '') return true; // (si no fuera obligatorio, no falla)

    // Solo caracteres típicos
    if (!preg_match('/^[0-9+\-\s()]+$/', $t)) return false;

    // Contar dígitos reales
    $digits = preg_replace('/\D+/', '', $t);
    $len = strlen($digits);

    return ($len >= 7 && $len <= 15);
}

/**
 * VALIDACIÓN EXTRA: fecha Y-m-d válida
 */
function fechaYmdValida(string $fecha): bool {
    $fecha = trim($fecha);
    if ($fecha === '') return true;
    $dt = DateTime::createFromFormat('Y-m-d', $fecha);
    return ($dt && $dt->format('Y-m-d') === $fecha);
}

// ============================================================
// 3.5) LIMPIAR FORMULARIO (servidor) + marcar para borrar localStorage
// ============================================================
if (isset($_GET['clear']) && $_GET['clear'] == '1') {
    limpiarPendiente();

    // Flag para que el front borre el borrador guardado
    $_SESSION['clear_draft'] = 1;

    // Volver a la URL limpia (sin ?clear=1)
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

// ============================================================
// 4) LÓGICA DE GUARDADO (POST)
// ============================================================
$insertOK = false;

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

    // Edad válida (>0) + coherente (límite razonable)
    if (isset($_POST['edad']) && trim((string)$_POST['edad']) !== '') {
        if (!ctype_digit((string)$_POST['edad']) || (int)$_POST['edad'] <= 0) {
            $errores[] = "La <strong>edad</strong> debe ser un número mayor que 0.";
        } else if ((int)$_POST['edad'] > 99) {
            $errores[] = "La <strong>edad</strong> no parece coherente. Revisa el dato.";
        }
    }

    // Teléfonos (extra)
    if (!empty($_POST['telefono']) && !telefonoValido($_POST['telefono'])) {
        $errores[] = "El <strong>teléfono de contacto</strong> no parece válido (usa 7 a 15 dígitos).";
    }
    if (!empty($_POST['telefono_emergencia']) && !telefonoValido($_POST['telefono_emergencia'])) {
        $errores[] = "El <strong>teléfono de emergencia</strong> no parece válido (usa 7 a 15 dígitos).";
    }

    // Fecha de inscripción (extra): formato + no futura
    if (!empty($_POST['fecha_inscripcion'])) {
        $fi = (string)$_POST['fecha_inscripcion'];
        if (!fechaYmdValida($fi)) {
            $errores[] = "La <strong>fecha de inscripción</strong> no tiene un formato válido.";
        } else {
            $hoy = date('Y-m-d');
            if ($fi > $hoy) {
                $errores[] = "La <strong>fecha de inscripción</strong> no puede ser futura.";
            }
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
                    $insertOK = true;
                    $mensaje = "¡Inscripción realizada con éxito! Te contactaremos por correo si hace falta confirmar algún dato.";

                    // Limpieza del POST (para que no quede relleno en el server)
                    $_POST = [];

                    // Marca para que el front borre el borrador del localStorage
                    $_SESSION['clear_draft'] = 1;
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

// ============================================================
// 6) FLAG para borrar borrador en el front (localStorage)
// ============================================================
$clearDraftFlag = !empty($_SESSION['clear_draft']);
unset($_SESSION['clear_draft']);

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
            position: relative; /* para el botón limpiar arriba a la derecha */
        }

        legend{
            font-weight: 800;
            padding: 0 10px;
            color: #0f172a;
            font-size: 1.2rem;
        }

        /* Botón limpiar (arriba-derecha, discreto) */
        .btn-clear{
            position:absolute;
            top: 16px;
            right: 16px;
            padding: 8px 10px;
            border-radius: 10px;
            border: 1px solid rgba(0,0,0,.10);
            background: #fff;
            color:#0f172a;
            font-weight: 800;
            font-size: 12px;
            text-decoration:none;
            cursor:pointer;
            box-shadow: 0 2px 10px rgba(0,0,0,.06);
        }
        .btn-clear:hover{ background:#f8fafc; }
        .btn-clear:active{ transform: translateY(1px); box-shadow:none; }

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
            width: 580px;
        }

        legend + .alert{
            margin-top: 52px;
            padding-right: 110px;
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

        /* =========================
           MODAL RESUMEN (confirmación)
        ========================== */
        .modal{
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .45);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 18px;
            z-index: 9999;
        }
        .modal.is-open{ display:flex; }

        .modal-card{
            width: min(760px, 100%);
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 20px 45px rgba(0,0,0,.18);
            overflow: hidden;
        }
        .modal-head{
            padding: 14px 16px;
            background: linear-gradient(180deg, rgba(14,165,233,.10), rgba(30,58,138,.08));
            border-bottom: 1px solid #e5e7eb;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
        }
        .modal-title{
            margin:0;
            font-weight: 900;
            color:#0f172a;
            font-size: 1rem;
        }
        .modal-body{
            padding: 14px 16px;
            max-height: min(70vh, 520px);
            overflow:auto;
        }
        .summary{
            width:100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        .summary td{
            padding: 10px 8px;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: top;
        }
        .summary td:first-child{
            width: 42%;
            font-weight: 900;
            color:#0f172a;
            white-space: nowrap;
        }
        .summary td:last-child{
            color:#0f172a;
            overflow-wrap:anywhere;
        }
        .modal-foot{
            padding: 14px 16px;
            border-top: 1px solid #e5e7eb;
            display:flex;
            gap:10px;
            justify-content:flex-end;
            flex-wrap:wrap;
            background:#fff;
        }
        .btn-modal{
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid rgba(0,0,0,.10);
            background:#fff;
            font-weight: 900;
            cursor:pointer;
        }
        .btn-modal.primary{
            border:0;
            color:#fff;
            background: linear-gradient(135deg, #0ea5e9, #1e3a8a);
        }
        .btn-modal:hover{ filter: brightness(0.98); }
        .btn-modal:active{ transform: translateY(1px); }
        .tiny{
            font-size: 12px;
            color:#475569;
            font-weight: 700;
        }
    </style>
</head>
<body>

<form id="frmCampamento" action="" method="POST" enctype="multipart/form-data">
    <fieldset>
        <legend>Registro Campamento de Verano</legend>

        <!-- Limpiar arriba a la derecha -->
        <a id="btnClear" class="btn-clear" href="?clear=1" title="Limpiar formulario">Limpiar</a>

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

            // Comentario SQL (si existe) -> SIEMPRE se muestra
            $commentRaw = $f['Comment'] ?? '';
            $comentario = '';
            if (!empty($commentRaw)) {
                $comentario = '<p class="comentario-sql">'.htmlspecialchars($commentRaw, ENT_QUOTES, 'UTF-8').'</p>';
            }

            // Valor persistente si hubo error (para no perder lo escrito)
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

            // Longitud para maxlength en varchar(n)
            $maxLen = null;
            if (stripos($type, "varchar") !== false) {
                $maxLen = sqlLength($type);
            }

            // ----------- VARCHAR -----------
            if (stripos($type, "varchar") !== false) {

                // Inputs especiales por nombre
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

                // step = 0.01 si s=2, step=0.001 si s=3...
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

            // Comentario SQL (SIEMPRE)
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

<!-- =========================
     MODAL: Resumen previo (sin blobs)
========================== -->
<div class="modal" id="modalResumen" aria-hidden="true">
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal-head">
      <h4 class="modal-title" id="modalTitle">Revisa tu inscripción</h4>
      <div class="tiny">Confirma que todo esté correcto antes de enviar.</div>
    </div>

    <div class="modal-body">
      <table class="summary" id="summaryTable"></table>
      <p class="tiny" style="margin:12px 0 0;">
        Nota: los documentos/archivos adjuntos no se muestran en este resumen.
      </p>
    </div>

    <div class="modal-foot">
      <button type="button" class="btn-modal" id="btnCancelar">Volver</button>
      <button type="button" class="btn-modal primary" id="btnConfirmar">Confirmar y enviar</button>
    </div>
  </div>
</div>

<script>
(function(){
  const form = document.getElementById("frmCampamento");
  const STORAGE_KEY = "campamento_form_draft_v1";

  // Si venimos de un "Limpiar" (servidor) o de un submit OK, borramos el draft
  const mustClearDraft = <?= $clearDraftFlag ? "true" : "false" ?>;
  if (mustClearDraft) {
    try { localStorage.removeItem(STORAGE_KEY); } catch(e){}
  }

  // ==========================
  // 1) Guardado automático (localStorage)
  // ==========================
  function isSaveableField(el){
    if (!el || !el.name) return false;
    if (el.type === "file") return false;
    if (el.type === "submit" || el.type === "button") return false;
    if (el.disabled) return false;
    return true;
  }

  function saveDraft(){
    const data = {};
    const els = form.querySelectorAll("input, select, textarea");
    els.forEach(el => {
      if (!isSaveableField(el)) return;

      if (el.type === "checkbox") {
        data[el.name] = el.checked ? 1 : 0;
      } else {
        data[el.name] = el.value;
      }
    });

    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(data)); } catch(e){}
  }

  function loadDraft(){
    let raw = null;
    try { raw = localStorage.getItem(STORAGE_KEY); } catch(e){}
    if (!raw) return;

    let data = null;
    try { data = JSON.parse(raw); } catch(e){ return; }
    if (!data || typeof data !== "object") return;

    const els = form.querySelectorAll("input, select, textarea");
    els.forEach(el => {
      if (!isSaveableField(el)) return;
      if (!(el.name in data)) return;

      if (el.type === "checkbox") {
        el.checked = String(data[el.name]) === "1";
      } else {
        el.value = data[el.name];
      }
    });
  }

  if (!mustClearDraft) loadDraft();

  form.addEventListener("input", function(e){
    if (isSaveableField(e.target)) saveDraft();
  });
  form.addEventListener("change", function(e){
    if (isSaveableField(e.target)) saveDraft();
  });

  // ==========================
  // 2) Botón Limpiar: limpia localStorage + reset + limpia servidor
  // ==========================
  const btnClear = document.getElementById("btnClear");
  if (btnClear) {
    btnClear.addEventListener("click", function(e){
      e.preventDefault();

      try { localStorage.removeItem(STORAGE_KEY); } catch(err){}
      form.reset();

      window.location.href = "?clear=1";
    });
  }

  // ==========================
  // 3) Resumen previo (modal) SIN blobs
  // ==========================
  const modal = document.getElementById("modalResumen");
  const summaryTable = document.getElementById("summaryTable");
  const btnCancelar = document.getElementById("btnCancelar");
  const btnConfirmar = document.getElementById("btnConfirmar");

  function openModal(){
    modal.classList.add("is-open");
    modal.setAttribute("aria-hidden", "false");
    btnConfirmar.focus();
  }
  function closeModal(){
    modal.classList.remove("is-open");
    modal.setAttribute("aria-hidden", "true");
  }

  function labelFromInput(el){
    const id = el.id ? el.id : null;
    if (id) {
      const lab = form.querySelector('label[for="'+CSS.escape(id)+'"]');
      if (lab) return lab.textContent.trim().replace(/\*/g,'').trim();
    }
    return (el.name || "").trim();
  }

  function valueFromInput(el){
    if (el.type === "checkbox") return el.checked ? "Sí" : "No";
    if (el.tagName === "SELECT") {
      const opt = el.options[el.selectedIndex];
      if (!el.value) return "—";
      return (opt ? opt.textContent.trim() : "") || el.value;
    }
    const v = (el.value || "").trim();
    return v === "" ? "—" : v;
  }

  function buildSummary(){
    summaryTable.innerHTML = "";

    const els = form.querySelectorAll("input, select, textarea");
    els.forEach(el => {
      if (el.type === "file") return;
      if (el.type === "submit" || el.type === "button") return;
      if (!el.name) return;

      const tr = document.createElement("tr");

      const td1 = document.createElement("td");
      td1.textContent = labelFromInput(el);

      const td2 = document.createElement("td");
      td2.textContent = valueFromInput(el);

      tr.appendChild(td1);
      tr.appendChild(td2);
      summaryTable.appendChild(tr);
    });
  }

  form.addEventListener("submit", function(e){
    if (!form.checkValidity()){
      form.reportValidity();
      return;
    }

    e.preventDefault();
    buildSummary();
    openModal();
  });

  btnCancelar.addEventListener("click", function(){
    closeModal();
  });

  btnConfirmar.addEventListener("click", function(){
    saveDraft();
    closeModal();
    form.submit();
  });

  modal.addEventListener("click", function(e){
    if (e.target === modal) closeModal();
  });

  document.addEventListener("keydown", function(e){
    if (e.key === "Escape" && modal.classList.contains("is-open")) {
      closeModal();
    }
  });

})();
</script>

</body>
</html>
