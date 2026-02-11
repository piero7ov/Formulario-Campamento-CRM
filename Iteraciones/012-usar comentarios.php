<!doctype html>
<html lang="es">
  <head>
    <title>Formulario</title>
    <meta charset="utf-8">
    <style>
      /* ===== Reset básico ===== */
      *{ box-sizing: border-box; }

      body{
        margin: 0;
        font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
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

      .control_formulario{ margin-top: 14px; }

      .control_formulario label{
        display: block;
        margin: 0 0 6px;
        font-size: 0.95rem;
        font-weight: 600;
        color: #374151;
      }

      /* Estilo para el comentario SQL */
      .control_formulario .comentario-sql {
        margin: 6px 0 0;
        font-size: 0.8rem;
        color: #6b7280;
        line-height: 1.4;
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
        transition: border-color .2s, box-shadow .2s;
      }

      .control_formulario textarea{ min-height: 110px; resize: vertical; }

      .control_formulario input:focus,
      .control_formulario select:focus,
      .control_formulario textarea:focus{
        border-color: #2563eb;
        box-shadow: 0 0 0 4px rgba(37, 99, 235, .15);
      }

      input[type="submit"]{
        margin-top: 18px;
        width: 100%;
        padding: 12px 14px;
        border: 0;
        border-radius: 12px;
        font-weight: 700;
        cursor: pointer;
        background: linear-gradient(135deg, #0ea5e9, #1e3a8a);
        color: white;
        box-shadow: 0 10px 18px rgba(14,165,233,.25);
        transition: transform .08s ease, filter .2s ease;
      }

      /* SWITCH ESTILOS */
      .control_formulario--switch{
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap; /* Para que el comentario baje si no cabe */
      }

      .control_formulario--switch label { flex: 1; }

      .control_formulario input.switch{
        appearance: none;
        -webkit-appearance: none;
        width: 52px;
        height: 30px;
        border-radius: 999px;
        border: 1px solid #d1d5db;
        background: #e5e7eb;
        position: relative;
        cursor: pointer;
        padding: 0;
      }

      .control_formulario input.switch::after{
        content: "";
        position: absolute;
        top: 50%;
        left: 4px;
        width: 22px;
        height: 22px;
        border-radius: 999px;
        background: #ffffff;
        transform: translateY(-50%);
        transition: left .2s ease;
      }

      .control_formulario input.switch:checked{ background: #22c55e; border-color: #16a34a; }
      .control_formulario input.switch:checked::after{ left: 26px; }

      /* Ajuste para comentario en switch */
      .switch-comentario { width: 100%; }
    </style>
  </head>

  <body>
    <form action="" method="POST" enctype="multipart/form-data">
      <fieldset>
        <legend>Formulario de recogida de datos</legend>

        <?php
          $c = new mysqli("localhost","campamento_verano","campamento_verano","campamento_verano");
          // CAMBIO CLAVE: "SHOW FULL COLUMNS" para traer los comentarios
          $r = $c->query("SHOW FULL COLUMNS FROM inscripciones_campamento;");

          while($f = $r->fetch_assoc()){
            if($f['Key'] != "PRI" || $f['Extra'] == "DEFAULT_GENERATED"){
              
              // Guardamos el comentario en una variable
              $comentario = !empty($f['Comment']) ? '<p class="comentario-sql">'.$f['Comment'].'</p>' : '';

              /* ==========================================================
                  ✅ CASO tinyint (SWITCH)
                 ========================================================== */
              if (str_contains($f['Type'], "int") && str_contains($f['Type'], "tinyint")) {

                echo '<div class="control_formulario control_formulario--switch">';
                echo '<label for="'.$f['Field'].'">'.$f['Field'].'</label>';
                echo '<input type="hidden" name="'.$f['Field'].'" value="0">';
                echo '<input class="switch" type="checkbox" name="'.$f['Field'].'" id="'.$f['Field'].'" value="1">';
                // Agregamos comentario abajo ocupando el ancho completo
                if($comentario) echo '<div class="switch-comentario">'.$comentario.'</div>';
                echo '</div>';
                continue;
              }

              /* ==========================================================
                  ✅ Resto de tipos
                 ========================================================== */
              echo '<div class="control_formulario"><label for="'.$f['Field'].'">'.$f['Field'].'</label>';

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
                echo '<option value="" selected disabled>Selecciona una opción</option>';
                preg_match_all("/'([^']+)'/", $f['Type'], $m);
                if(isset($m[1])){
                  foreach($m[1] as $op){
                    echo '<option value="'.$op.'">'.$op.'</option>';
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

              // Imprimimos el comentario debajo del input
              echo $comentario;
              echo '</div>';
            }
          }
        ?>

        <input type="submit" value="Enviar">
      </fieldset>
    </form>
  </body>
</html>

