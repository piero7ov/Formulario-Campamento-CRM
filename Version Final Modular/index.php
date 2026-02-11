<?php
/**************************************************************
 *  REGISTRO CAMPAMENTO — VERSIÓN FINAL
 *  - Conexión MySQL (mysqli) + UTF8
 *  - Validación extra SOLO en PHP:
 *      • Teléfonos: caracteres permitidos + 7 a 15 dígitos reales
 *      • Fecha: formato YYYY-MM-DD y NO futura
 *      • Edad: número > 0 y coherente (<= 99)
 *  - INSERT dinámico leyendo la estructura de la tabla (metadata)
 *  - Campos BLOB opcionales (si no hay archivo, no se insertan)
 *  - Modal resumen antes de enviar (sin mostrar adjuntos)
 *  - localStorage: guardado automático del formulario
 *  - Botón “Limpiar”: borra draft + resetea form + limpia URL/servidor
 * 
 *  Importante:
 *  - El fondo jungla se carga desde jungle-bg.css / jungle-bg.js
 *  - El estilo del formulario está en formulario.css
 **************************************************************/

session_start();

/* ============================================================
   1) CONEXIÓN A LA BASE DE DATOS
   ============================================================ */
$db_name = "campamento_verano";

// Conexión (host, usuario, password, db)
$c = new mysqli("localhost", "campamento_verano", "campamento_verano", $db_name);

// Si falla, cortamos ejecución con un mensaje
if ($c->connect_error) {
    die("Error de conexión: " . $c->connect_error);
}

// Aseguramos UTF8 para tildes/ñ y emojis
$c->set_charset("utf8mb4");

// Mensajes para el usuario (éxito o errores)
$mensaje = "";
$errores = [];

/* ============================================================
   2) CAMPOS OBLIGATORIOS (los que pediste)
   ============================================================ */
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

/* ============================================================
   3) HELPERS PARA PARSEAR TIPOS SQL Y TAMAÑOS
   ============================================================ */

/**
 * Convierte tamaños tipo "2M", "128M", "1G" a bytes.
 * - Se usa para mostrar un tamaño máximo real permitido por PHP.
 */
function iniSizeToBytes(string $value): int {
    $value = trim($value);
    if ($value === '') return 0;

    // Último carácter puede ser k/m/g
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
 * Pasa bytes a formato humano (KB/MB/GB...).
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
 * Extrae longitud de un tipo como varchar(150) -> 150.
 * - Se usa para poner maxlength automáticamente en inputs.
 */
function sqlLength(?string $columnType): ?int {
    if (!$columnType) return null;
    if (preg_match('/\((\d+)\)/', $columnType, $m)) {
        return (int)$m[1];
    }
    return null;
}

/**
 * Extrae precisión y escala de decimal(p,s) -> [p,s].
 * - Ej: decimal(10,2) -> [10,2]
 * - Se usa para configurar step y ayuda visual.
 */
function sqlDecimalPS(?string $columnType): ?array {
    if (!$columnType) return null;
    if (preg_match('/decimal\((\d+),\s*(\d+)\)/i', $columnType, $m)) {
        return [(int)$m[1], (int)$m[2]];
    }
    return null;
}

/**
 * Detecta si un tipo SQL es un BLOB (blob, longblob, etc).
 */
function isBlobType(string $sqlType): bool {
    $t = strtolower($sqlType);
    return (strpos($t, 'blob') !== false);
}

/**
 * Convierte nombres técnicos (snake_case) a etiquetas más amigables.
 * - También aplica reemplazos específicos.
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
 * Etiqueta bonita para errores (para no mostrar el nombre técnico).
 */
function labelBonito(string $campo): string {
    return formatearLabel($campo);
}

/**
 * Verifica si un campo está en la lista de obligatorios.
 */
function esObligatorio(string $campo, array $lista): bool {
    return in_array($campo, $lista, true);
}

/**
 * Limpia datos "pendientes" en sesión.
 * - Ahora no los usamos, pero queda listo por si luego
 *   guardas datos temporales en sesión.
 */
function limpiarPendiente(): void {
    unset($_SESSION['pending_post'], $_SESSION['pending_files']);
}

/**
 * VALIDACIÓN EXTRA: teléfono “razonable”
 * - Permite: +, espacios, (), guiones
 * - Exige: 7 a 15 dígitos reales
 * - Nota: devuelve true si está vacío (por si el campo no fuera obligatorio).
 */
function telefonoValido(?string $telefono): bool {
    $t = trim((string)$telefono);
    if ($t === '') return true;

    // 1) Solo caracteres típicos
    if (!preg_match('/^[0-9+\-\s()]+$/', $t)) return false;

    // 2) Contar dígitos reales ignorando separadores
    $digits = preg_replace('/\D+/', '', $t);
    $len = strlen($digits);

    return ($len >= 7 && $len <= 15);
}

/**
 * VALIDACIÓN EXTRA: fecha Y-m-d válida (YYYY-MM-DD).
 */
function fechaYmdValida(string $fecha): bool {
    $fecha = trim($fecha);
    if ($fecha === '') return true;

    $dt = DateTime::createFromFormat('Y-m-d', $fecha);
    return ($dt && $dt->format('Y-m-d') === $fecha);
}

/* ============================================================
   3.5) LIMPIAR FORMULARIO (servidor) + marcar para borrar localStorage
   ============================================================ */
/**
 * Cuando el usuario hace click en "Limpiar":
 * - Redirigimos a ?clear=1
 * - Aquí marcamos una bandera en sesión para que el front
 *   borre el localStorage
 * - Luego volvemos a la URL limpia sin parámetros
 */
if (isset($_GET['clear']) && $_GET['clear'] == '1') {
    limpiarPendiente();

    // Bandera: "al cargar, borra el draft del localStorage"
    $_SESSION['clear_draft'] = 1;

    // Redirección para eliminar el ?clear=1 de la URL
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

/* ============================================================
   4) LÓGICA DE GUARDADO (POST)
   ============================================================ */
$insertOK = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* --------------------------------------------------------
       4.1) VALIDACIÓN (solo PHP)
       -------------------------------------------------------- */

    // 1) Requeridos: no pueden ir vacíos
    foreach ($camposObligatorios as $campoReq) {
        $v = trim((string)($_POST[$campoReq] ?? ''));
        if ($v === '') {
            $errores[] = "El campo <strong>" . htmlspecialchars(labelBonito($campoReq), ENT_QUOTES, 'UTF-8') . "</strong> es obligatorio.";
        }
    }

    // 2) Email
    if (!empty($_POST['email']) && !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        $errores[] = "El <strong>correo electrónico</strong> no tiene un formato válido.";
    }

    // 3) Edad: número > 0 y coherente
    if (isset($_POST['edad']) && trim((string)$_POST['edad']) !== '') {
        if (!ctype_digit((string)$_POST['edad']) || (int)$_POST['edad'] <= 0) {
            $errores[] = "La <strong>edad</strong> debe ser un número mayor que 0.";
        } else if ((int)$_POST['edad'] > 99) {
            $errores[] = "La <strong>edad</strong> no parece coherente. Revisa el dato.";
        }
    }

    // 4) Teléfonos
    if (!empty($_POST['telefono']) && !telefonoValido($_POST['telefono'])) {
        $errores[] = "El <strong>teléfono de contacto</strong> no parece válido (usa 7 a 15 dígitos).";
    }
    if (!empty($_POST['telefono_emergencia']) && !telefonoValido($_POST['telefono_emergencia'])) {
        $errores[] = "El <strong>teléfono de emergencia</strong> no parece válido (usa 7 a 15 dígitos).";
    }

    // 5) Fecha: formato + no futura
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

    // Si hay errores, se muestran y NO se inserta nada
    if (!empty($errores)) {
        $mensaje = implode("<br>", $errores);
    } else {

        /* --------------------------------------------------------
           4.2) INSERT DINÁMICO (según columnas reales de la tabla)
           --------------------------------------------------------
           - Leemos metadata de columns (information_schema)
           - Armamos INSERT con placeholders
           - Bind automático según tipo (i,d,s,b)
           - Para BLOB: usamos send_long_data
        -------------------------------------------------------- */

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
        $blobPositions = []; // índices de parámetros BLOB para send_long_data()

        while ($f = $meta->fetch_assoc()) {
            $campo   = $f['COLUMN_NAME'];
            $tipo    = $f['COLUMN_TYPE'] ?? '';
            $colKey  = $f['COLUMN_KEY'] ?? '';
            $extra   = $f['EXTRA'] ?? '';
            $def     = $f['COLUMN_DEFAULT'];

            // Omitimos:
            // - PK
            // - columnas generadas
            // - timestamps con CURRENT_TIMESTAMP automático
            $defUpper = is_string($def) ? strtoupper($def) : '';
            if (
                $colKey === "PRI" ||
                stripos($extra, "GENERATED") !== false ||
                ($defUpper !== '' && strpos($defUpper, "CURRENT_TIMESTAMP") !== false)
            ) {
                continue;
            }

            $valor = null;

            // tinyint -> switch/checkbox (0/1)
            if (stripos($tipo, "tinyint") !== false) {
                $valor = isset($_POST[$campo]) ? 1 : 0;
                $tiposBind .= "i";
            }
            // int
            else if (stripos($tipo, "int") !== false) {
                $valor = (isset($_POST[$campo]) && $_POST[$campo] !== "") ? (int)$_POST[$campo] : null;
                $tiposBind .= "i";
            }
            // decimal/float
            else if (stripos($tipo, "decimal") !== false || stripos($tipo, "float") !== false) {
                $valor = (isset($_POST[$campo]) && $_POST[$campo] !== "") ? (float)$_POST[$campo] : null;
                $tiposBind .= "d";
            }
            // BLOB/LONGBLOB
            else if (isBlobType($tipo)) {

                // Si adjuntan archivo OK, lo incluimos
                if (isset($_FILES[$campo]) && $_FILES[$campo]['error'] === UPLOAD_ERR_OK) {
                    $valor = file_get_contents($_FILES[$campo]['tmp_name']);

                    // 'b' = blob (luego se envía con send_long_data)
                    $tiposBind .= "b";

                    // Guardamos la posición del parámetro blob en $valores
                    $blobPositions[] = count($valores);
                } else {
                    // Si no hay archivo, NO insertamos esa columna (queda NULL o lo que tenga por defecto)
                    continue;
                }
            }
            // varchar/text/date/enum...
            else {
                $valor = $_POST[$campo] ?? null;
                $tiposBind .= "s";
            }

            // Guardamos columna, placeholder y valor
            $columnas[]     = $campo;
            $placeholders[] = "?";
            $valores[]      = $valor;
        }

        if (!empty($columnas)) {
            $sql  = "INSERT INTO inscripciones_campamento (" . implode(",", $columnas) . ") ";
            $sql .= "VALUES (" . implode(",", $placeholders) . ")";

            $stmt = $c->prepare($sql);

            if ($stmt) {
                // bind_param necesita referencias (&)
                $params = [$tiposBind];
                foreach ($valores as $k => $v) {
                    $params[] = &$valores[$k];
                }
                call_user_func_array([$stmt, 'bind_param'], $params);

                // Si hay blobs, los enviamos con send_long_data
                foreach ($blobPositions as $pos) {
                    $stmt->send_long_data($pos, $valores[$pos]);
                }

                // Ejecutar INSERT
                if ($stmt->execute()) {
                    $insertOK = true;
                    $mensaje = "¡Inscripción realizada con éxito! Te contactaremos por correo si hace falta confirmar algún dato.";

                    // Limpiar POST en server para no dejar el form “relleno”
                    $_POST = [];

                    // Bandera para que el front borre el localStorage
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

/* ============================================================
   5) INFO DE LÍMITES DE SUBIDA
   ============================================================ */
/**
 * upload_max_filesize y post_max_size pueden diferir.
 * El límite real suele ser el menor de ambos.
 */
$uploadMax  = iniSizeToBytes((string)ini_get('upload_max_filesize'));
$postMax    = iniSizeToBytes((string)ini_get('post_max_size'));

if ($uploadMax > 0 && $postMax > 0) {
    $limiteReal = min($uploadMax, $postMax);
} else {
    // Si uno es 0 o “no medible”, usamos el mayor
    $limiteReal = max($uploadMax, $postMax);
}

$limiteTexto = bytesToHuman($limiteReal);

/* ============================================================
   6) BANDERA PARA BORRAR EL DRAFT (localStorage)
   ============================================================ */
$clearDraftFlag = !empty($_SESSION['clear_draft']);
unset($_SESSION['clear_draft']); // importante: la consumimos una sola vez

?>
<!doctype html>
<html lang="es">
<head>
    <title>Registro Campamento</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">

    <!-- Fondo jungla (ya los tienes creados) -->
    <link rel="stylesheet" href="jungle-bg.css">
    <script src="jungle-bg.js" defer></script>

    <!-- CSS del formulario (archivo externo) -->
    <link rel="stylesheet" href="formulario.css">
</head>
<body>

<!-- Fondo jungla: siempre al inicio del body -->
<canvas id="jungle-bg" aria-hidden="true"></canvas>
<div class="overlay" aria-hidden="true"></div>

<div class="page bg-content">
    <!-- Logo corporativo centrado (fuera del fieldset) -->
    <div class="brand">
        <img
            src="https://piero7ov.github.io/pierodev-assets/brand/pierodev/logos/logocompleto.png"
            alt="PIERODEV"
            loading="eager"
        >
    </div>
    <a
        href="admin.php"
        class="btn-clear"
        title="Ir a administración"
        style="position:fixed; top:16px; left:16px; right:auto; z-index:10000;"
    >
        Admin
    </a>
    <form id="frmCampamento" action="" method="POST" enctype="multipart/form-data">
        <fieldset>
            <legend>Registro Campamento de Verano</legend>

            <!-- Botón Limpiar (arriba a la derecha) -->
            <a id="btnClear" class="btn-clear" href="?clear=1" title="Limpiar formulario">Limpiar</a>

            <!-- Mensaje de error / éxito -->
            <?php if (!empty($mensaje)): ?>
                <div class="alert"><?= $mensaje; ?></div>
            <?php endif; ?>

            <?php
            /**
             * Generador automático de formulario:
             * - Lee las columnas reales con SHOW FULL COLUMNS
             * - Para cada columna decide el tipo de input según el tipo SQL
             * - Muestra siempre el comentario de la columna si existe
             */
            $r = $c->query("SHOW FULL COLUMNS FROM inscripciones_campamento;");

            while($f = $r->fetch_assoc()){
                // Omitimos PK y autogenerados (por ejemplo DEFAULT_GENERATED)
                if (($f['Key'] ?? '') === "PRI" || stripos(($f['Extra'] ?? ''), "DEFAULT_GENERATED") !== false) {
                    continue;
                }

                $field = $f['Field'];       // nombre columna
                $type  = $f['Type'] ?? '';  // tipo SQL (varchar(100), int, enum, etc)
                $labelHumano = formatearLabel($field);

                // ¿Es obligatorio?
                $isReq = esObligatorio($field, $camposObligatorios);

                // Comentario SQL (se muestra siempre si existe)
                $commentRaw = $f['Comment'] ?? '';
                $comentario = '';
                if (!empty($commentRaw)) {
                    $comentario = '<p class="comentario-sql">'.htmlspecialchars($commentRaw, ENT_QUOTES, 'UTF-8').'</p>';
                }

                // Mantener valores escritos si hubo error en el POST
                $valorOld = $_POST[$field] ?? '';

                /* ------------------------------
                   TINYINT => SWITCH (checkbox)
                   ------------------------------ */
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

                // Maxlength automático si es varchar(n)
                $maxLen = null;
                if (stripos($type, "varchar") !== false) {
                    $maxLen = sqlLength($type);
                }

                /* ------------------------------
                   VARCHAR => input text/email/tel
                   ------------------------------ */
                if (stripos($type, "varchar") !== false) {

                    // Ajuste de tipo de input por nombre de campo
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
                /* ------------------------------
                   DATE => input date
                   ------------------------------ */
                else if (strtolower($type) === "date") {
                    echo '<input type="date" name="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'" id="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'"'
                       . ($isReq ? ' required' : '')
                       . ' value="'.htmlspecialchars((string)$valorOld, ENT_QUOTES, 'UTF-8').'">';
                }
                /* ------------------------------
                   DECIMAL => input number con step
                   ------------------------------ */
                else if (stripos($type, "decimal") !== false) {
                    $ps = sqlDecimalPS($type);
                    $p = $ps ? $ps[0] : 10;
                    $s = $ps ? $ps[1] : 2;

                    // step = 0.01 si s=2, etc.
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
                /* ------------------------------
                   INT => input number
                   ------------------------------ */
                else if (stripos($type, "int") !== false) {
                    echo '<input type="number" name="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'" id="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'"'
                       . ($isReq ? ' required' : '')
                       . ' value="'.htmlspecialchars((string)$valorOld, ENT_QUOTES, 'UTF-8').'">';
                }
                /* ------------------------------
                   ENUM => select
                   ------------------------------ */
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
                /* ------------------------------
                   TEXT => textarea
                   ------------------------------ */
                else if (stripos($type, "text") !== false) {
                    echo '<textarea name="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'" id="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'"'
                       . ($isReq ? ' required' : '')
                       . '>'.htmlspecialchars((string)$valorOld, ENT_QUOTES, 'UTF-8').'</textarea>';
                }
                /* ------------------------------
                   BLOB => input file
                   ------------------------------ */
                else if (isBlobType($type)) {
                    echo '<input type="file" name="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'" id="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'" accept="application/pdf,image/*">';
                    echo '<p class="help">Puedes subir PDF o imagen. Tamaño máximo permitido por el servidor: <strong>'.$limiteTexto.'</strong>.</p>';
                }
                /* ------------------------------
                   Cualquier otro tipo => input text
                   ------------------------------ */
                else {
                    echo '<input type="text" name="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'" id="'.htmlspecialchars($field, ENT_QUOTES, 'UTF-8').'"'
                       . ($isReq ? ' required' : '')
                       . ' value="'.htmlspecialchars((string)$valorOld, ENT_QUOTES, 'UTF-8').'">';
                }

                // Comentario SQL (si existe, se muestra siempre)
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
</div>

<!-- ==========================================================
     MODAL: RESUMEN PREVIO (NO muestra blobs)
     - Se abre cuando el form pasa validación HTML5
     - Permite al usuario revisar antes de enviar de verdad
========================================================== -->
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
/**
 * JS (mínimo) para:
 * 1) Guardar el formulario en localStorage mientras escribes
 * 2) Cargar ese borrador al abrir la página
 * 3) Botón Limpiar: borrar borrador + reset + pedir limpieza server
 * 4) Modal resumen antes del submit real
 */
(function(){
  const form = document.getElementById("frmCampamento");

  // Clave de localStorage (si cambias versión, cambia esta key)
  const STORAGE_KEY = "campamento_form_draft_v1";

  /* --------------------------------------------------------
     0) Si venimos de "Limpiar" o de un submit OK,
        el servidor activa $clearDraftFlag y aquí borramos el draft
     -------------------------------------------------------- */
  const mustClearDraft = <?= $clearDraftFlag ? "true" : "false" ?>;
  if (mustClearDraft) {
    try { localStorage.removeItem(STORAGE_KEY); } catch(e){}
  }

  /* --------------------------------------------------------
     1) Guardado automático del draft en localStorage
     -------------------------------------------------------- */

  // Decide si un campo se puede guardar (no guardamos files, submit, etc.)
  function isSaveableField(el){
    if (!el || !el.name) return false;
    if (el.type === "file") return false; // archivos NO se guardan en localStorage
    if (el.type === "submit" || el.type === "button") return false;
    if (el.disabled) return false;
    return true;
  }

  // Guarda en localStorage un objeto con name => value
  function saveDraft(){
    const data = {};
    const els = form.querySelectorAll("input, select, textarea");

    els.forEach(el => {
      if (!isSaveableField(el)) return;

      // Checkbox: guardamos 1/0
      if (el.type === "checkbox") {
        data[el.name] = el.checked ? 1 : 0;
      } else {
        data[el.name] = el.value;
      }
    });

    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(data)); } catch(e){}
  }

  // Carga el draft si existe
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

  // Solo cargamos el draft si NO venimos de limpiar
  if (!mustClearDraft) loadDraft();

  // Guardar con cada cambio (input y change cubren casi todo)
  form.addEventListener("input", function(e){
    if (isSaveableField(e.target)) saveDraft();
  });
  form.addEventListener("change", function(e){
    if (isSaveableField(e.target)) saveDraft();
  });

  /* --------------------------------------------------------
     2) Botón Limpiar:
        - Borra localStorage
        - Resetea formulario visualmente
        - Redirige a ?clear=1 para que el server limpie la URL y
          marque flag de “borrado” también en server
     -------------------------------------------------------- */
  const btnClear = document.getElementById("btnClear");
  if (btnClear) {
    btnClear.addEventListener("click", function(e){
      e.preventDefault();

      try { localStorage.removeItem(STORAGE_KEY); } catch(err){}
      form.reset();

      // Pedimos al servidor limpiar (y quitar el parámetro luego)
      window.location.href = "?clear=1";
    });
  }

  /* --------------------------------------------------------
     3) Modal resumen antes de enviar (sin blobs)
     -------------------------------------------------------- */
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

  // Obtiene el texto del label asociado al input/select
  function labelFromInput(el){
    const id = el.id ? el.id : null;
    if (id) {
      const lab = form.querySelector('label[for="'+CSS.escape(id)+'"]');
      if (lab) return lab.textContent.trim().replace(/\*/g,'').trim();
    }
    return (el.name || "").trim();
  }

  // Obtiene el valor “humano” a mostrar en el resumen
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

  // Construye la tabla resumen (sin inputs tipo file)
  function buildSummary(){
    summaryTable.innerHTML = "";

    const els = form.querySelectorAll("input, select, textarea");
    els.forEach(el => {
      if (el.type === "file") return; // nunca mostramos adjuntos
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

  // Interceptamos el submit:
  // - Si HTML5 validity falla, mostramos mensajes nativos del navegador
  // - Si todo OK, abrimos modal en vez de enviar directamente
  form.addEventListener("submit", function(e){
    if (!form.checkValidity()){
      form.reportValidity();
      return;
    }

    e.preventDefault();
    buildSummary();
    openModal();
  });

  // Cerrar modal y volver a editar
  btnCancelar.addEventListener("click", function(){
    closeModal();
  });

  // Confirmar:
  // - Guardamos draft (por si el server devuelve error y el usuario vuelve)
  // - Cerramos modal
  // - Enviamos el form “real” (POST al servidor)
  btnConfirmar.addEventListener("click", function(){
    saveDraft();
    closeModal();
    form.submit();
  });

  // Click fuera del modal -> cerrar
  modal.addEventListener("click", function(e){
    if (e.target === modal) closeModal();
  });

  // ESC para cerrar
  document.addEventListener("keydown", function(e){
    if (e.key === "Escape" && modal.classList.contains("is-open")) {
      closeModal();
    }
  });

})();
</script>

</body>
</html>
