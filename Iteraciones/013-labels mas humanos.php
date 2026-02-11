<!doctype html>
<html lang="es">
  <head>
    <title>Formulario de Inscripción</title>
    <meta charset="utf-8">
    <style>
      /* ===== Reset y Base ===== */
      *{ box-sizing: border-box; }
      body{
        margin: 0;
        font-family: system-ui, -apple-system, sans-serif;
        background: #f5f7fb;
        color: #111827;
        display: grid;
        place-items: center;
        min-height: 100vh;
        padding: 24px;
      }

      form{ width: min(520px, 100%); }

      fieldset{
        border: 1px solid #e5e7eb;
        background: #ffffff;
        border-radius: 16px;
        padding: 22px;
        box-shadow: 0 10px 25px rgba(0,0,0,.07);
      }

      legend{
        font-weight: 700;
        padding: 0 10px;
        color: #0f172a;
        font-size: 1.2rem;
      }

      .control_formulario{ margin-top: 18px; }

      /* Labels más humanos */
      .control_formulario label{
        display: block;
        margin: 0 0 6px;
        font-size: 0.95rem;
        font-weight: 600;
        color: #1f2937;
      }

      /* Estilo para el comentario SQL */
      .comentario-sql {
        margin: 6px 0 0;
        font-size: 0.85rem;
        color: #6b7280;
        line-height: 1.4;
        font-style: italic;
      }

      .control_formulario input[type="text"],
      .control_formulario input[type="date"],
      .control_formulario input[type="number"],
      .control_formulario input[type="file"],
      .control_formulario select,
      .control_formulario textarea{
        width: 100%;
        padding: 12px 14px;
        border: 1px solid #d1d5db;
        border-radius: 12px;
        outline: none;
        background: #fff;
        transition: all .2s;
      }

      .control_formulario textarea{ min-height: 100px; resize: vertical; }

      .control_formulario input:focus,
      .control_formulario select:focus,
      .control_formulario textarea:focus{
        border-color: #2563eb;
        box-shadow: 0 0 0 4px rgba(37, 99, 235, .15);
      }

      input[type="submit"]{
        margin-top: 24px;
        width: 100%;
        padding: 14px;
        border: 0;
        border-radius: 12px;
        font-weight: 700;
        cursor: pointer;
        background: linear-gradient(135deg, #0ea5e9, #1e3a8a);
        color: white;
        box-shadow: 0 10px 18px rgba(14,165,233,.25);
        transition: transform .1s;
      }

      input[type="submit"]:active{ transform: scale(0.98); }

      /* SWITCH ESTILOS */
      .control_formulario--switch{
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
      }
      .control_formulario--switch label { flex: 1; margin: 0; }

      .control_formulario input.switch{
        appearance: none;
        width: 52px;
        height: 28px;
        border-radius: 999px;
        background: #e5e7eb;
        position: relative;
        cursor: pointer;
        transition: background .3s;
      }

      .control_formulario input.switch::after{
        content: "";
        position: absolute;
        top: 3px;
        left: 4px;
        width: 22px;
        height: 22px;
        border-radius: 50%;
        background: white;
        transition: left .3s;
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
      }

      .control_formulario input.switch:checked{ background: #22c55e; }
      .control_formulario input.switch:checked::after{ left: 26px; }

      .switch-comentario { width: 100%; }
    </style>
  </head>

  <body>
    <form action="" method="POST" enctype="multipart/form-data">
      <fieldset>
        <legend>Registro Campamento de Verano</legend>

        <?php
          /**
           * Función para humanizar los nombres de las columnas
           */
          function formatearLabel($texto) {
              // Reemplazar guiones bajos por espacios
              $texto = str_replace('_', ' ', $texto);
              // Poner la primera letra en mayúscula
              $texto = ucfirst($texto);
              // Opcional: Reemplazos específicos para que suene mejor
              $reemplazos = [
                  'Sesion' => 'Sesión del campamento',
                  'Documento' => 'Adjuntar documento (DNI/Ficha)',
                  'Email' => 'Correo electrónico',
                  'Telefono' => 'Teléfono de contacto',
                  'Permiso fotos' => '¿Autoriza el uso de fotos?'
              ];
              return $reemplazos[$texto] ?? $texto;
          }

          $c = new mysqli("localhost","campamento_verano","campamento_verano","campamento_verano");
          $r = $c->query("SHOW FULL COLUMNS FROM inscripciones_campamento;");

          while($f = $r->fetch_assoc()){
            if($f['Key'] != "PRI" || $f['Extra'] == "DEFAULT_GENERATED"){
              
              $labelHumano = formatearLabel($f['Field']);
              $comentario = !empty($f['Comment']) ? '<p class="comentario-sql">'.$f['Comment'].'</p>' : '';

              /* ==========================================================
                  ✅ TINTYINT (SWITCH)
                 ========================================================== */
              if (str_contains($f['Type'], "tinyint")) {
                echo '<div class="control_formulario control_formulario--switch">';
                echo '<label for="'.$f['Field'].'">'.$labelHumano.'</label>';
                echo '<input type="hidden" name="'.$f['Field'].'" value="0">';
                echo '<input class="switch" type="checkbox" name="'.$f['Field'].'" id="'.$f['Field'].'" value="1">';
                if($comentario) echo '<div class="switch-comentario">'.$comentario.'</div>';
                echo '</div>';
                continue;
              }

              /* ==========================================================
                  ✅ RESTO DE TIPOS
                 ========================================================== */
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
                if(isset($m[1])){
                  foreach($m[1] as $op){
                    // También humanizamos las opciones del ENUM (ej: todo_el_dia -> Todo el dia)
                    $opHumana = ucfirst(str_replace('_', ' ', $op));
                    echo '<option value="'.$op.'">'.$opHumana.'</option>';
                  }
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