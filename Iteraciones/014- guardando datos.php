<?php
// 1. Conexión a la base de datos
$db_name = "campamento_verano";
$c = new mysqli("localhost", "campamento_verano", "campamento_verano", $db_name);

if ($c->connect_error) {
    die("Error de conexión: " . $c->connect_error);
}

$mensaje = "";

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
            // Lógica de Switch: si no existe en POST es 0 (porque usamos un input hidden o simplemente no llega el checkbox)
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
                continue; // Si no hay archivo, no lo incluimos en la consulta (o enviamos null)
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
        }
    }
}

/**
 * Función para formatear los nombres técnicos a etiquetas humanas
 */
function formatearLabel($texto) {
    $texto = str_replace('_', ' ', $texto);
    $texto = ucfirst($texto);
    $reemplazos = [
        'Sesion' => 'Sesión del campamento',
        'Documento' => 'Adjuntar documento (DNI/Ficha)',
        'Email' => 'Correo electrónico',
        'Telefono' => 'Teléfono de contacto',
        'Permiso fotos' => '¿Autoriza el uso de fotos?'
    ];
    return $reemplazos[$texto] ?? $texto;
}
?>

<!doctype html>
<html lang="es">
<head>
    <title>Registro Campamento</title>
    <meta charset="utf-8">
    <style>
        /* Estilos originales del usuario */
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
        .control_formulario{ margin-top: 18px; }
        .control_formulario label{ display: block; margin: 0 0 6px; font-size: 0.95rem; font-weight: 600; color: #1f2937; }
        .comentario-sql { margin: 6px 0 0; font-size: 0.85rem; color: #6b7280; font-style: italic; }
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

            <?php if ($mensaje): ?>
                <div class="alert"><?= htmlspecialchars($mensaje) ?></div>
            <?php endif; ?>

            <?php
            // Consultar columnas para generar el formulario
            $r = $c->query("SHOW FULL COLUMNS FROM inscripciones_campamento;");

            while($f = $r->fetch_assoc()){
                // Exclusión de Primary Key o autogenerados
                if($f['Key'] == "PRI" || str_contains($f['Extra'], "DEFAULT_GENERATED")) continue;

                $labelHumano = formatearLabel($f['Field']);
                $comentario = !empty($f['Comment']) ? '<p class="comentario-sql">'.$f['Comment'].'</p>' : '';

                // CASO TINYINT (Switch)
                if (str_contains($f['Type'], "tinyint")) {
                    echo '<div class="control_formulario control_formulario--switch">';
                    echo '<label for="'.$f['Field'].'">'.$labelHumano.'</label>';
                    echo '<input class="switch" type="checkbox" name="'.$f['Field'].'" id="'.$f['Field'].'" value="1">';
                    if($comentario) echo '<div class="switch-comentario">'.$comentario.'</div>';
                    echo '</div>';
                } 
                else {
                    echo '<div class="control_formulario"><label for="'.$f['Field'].'">'.$labelHumano.'</label>';

                    if (str_contains($f['Type'], "varchar")) {
                        echo '<input type="text" name="'.$f['Field'].'" id="'.$f['Field'].'">';
                    } else if($f['Type'] == "date") {
                        echo '<input type="date" name="'.$f['Field'].'" id="'.$f['Field'].'">';
                    } else if(str_contains($f['Type'], "int")) {
                        echo '<input type="number" name="'.$f['Field'].'" id="'.$f['Field'].'">';
                    } else if (str_contains($f['Type'], "decimal")) {
                        echo '<input type="number" name="'.$f['Field'].'" id="'.$f['Field'].'" step="0.01">';
                    } else if (str_contains($f['Type'], "enum")) {
                        echo '<select name="'.$f['Field'].'" id="'.$f['Field'].'">';
                        echo '<option value="" selected disabled>Seleccione una opción...</option>';
                        preg_match_all("/'([^']+)'/", $f['Type'], $m);
                        foreach($m[1] as $op) {
                            echo '<option value="'.$op.'">'.ucfirst($op).'</option>';
                        }
                        echo '</select>';
                    } else if (str_contains($f['Type'], "text")) {
                        echo '<textarea name="'.$f['Field'].'" id="'.$f['Field'].'"></textarea>';
                    } else if (str_contains($f['Type'], "blob")) {
                        echo '<input type="file" name="'.$f['Field'].'" id="'.$f['Field'].'">';
                    } else {
                        echo '<input type="text" name="'.$f['Field'].'" id="'.$f['Field'].'">';
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