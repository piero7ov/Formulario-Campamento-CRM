<!doctype html>
<html lang="es">
  <head>
    <title>Formulario</title>
    <meta charset="utf-8">
    <style>
      /* ===== Reset básico ===== */
      *{
        box-sizing: border-box;
      }

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

      /* ===== Form width ===== */
      form{
        width: min(520px, 100%);
      }

      /* ===== Fieldset estilo tarjeta ===== */
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

      /* ===== Bloques control_formulario ===== */
      .control_formulario{
        margin-top: 14px; /* separa cada bloque */
      }

      .control_formulario label{
        display: block;
        margin: 0 0 6px;
        font-size: 0.95rem;
        color: #374151;
      }

      /* ✅ Para que se vean bien todos los controles */
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
        transition: border-color .2s, box-shadow .2s, transform .05s;
      }

      .control_formulario textarea{
        min-height: 110px;
        resize: vertical;
      }

      .control_formulario input:focus,
      .control_formulario select:focus,
      .control_formulario textarea:focus{
        border-color: #2563eb;
        box-shadow: 0 0 0 4px rgba(37, 99, 235, .15);
      }

      /* ===== Botón submit ===== */
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

      input[type="submit"]:hover{
        filter: brightness(1.05);
      }

      input[type="submit"]:active{
        transform: translateY(1px);
      }

      /* ==========================================================
         ✅ SWITCH (checkbox estilizado para tinyint)
         ========================================================== */
      .control_formulario--switch{
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
      }

      .control_formulario--switch label{
        margin: 0;
      }

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
        transition: background .2s ease, border-color .2s ease, box-shadow .2s ease, filter .2s ease;
        padding: 0;
      }

      /* La bolita */
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
        box-shadow: 0 6px 14px rgba(0,0,0,.15);
        transition: left .2s ease;
      }

      /* Encendido */
      .control_formulario input.switch:checked{
        background: #22c55e;
        border-color: #16a34a;
      }

      /* Mueve la bolita */
      .control_formulario input.switch:checked::after{
        left: 26px;
      }

      /* Foco accesible */
      .control_formulario input.switch:focus{
        box-shadow: 0 0 0 4px rgba(34,197,94,.18);
        border-color: #16a34a;
      }

      /* Hover sutil */
      .control_formulario input.switch:hover{
        filter: brightness(1.02);
      }
    </style>
  </head>

  <body>
    <form action="" method="POST" enctype="multipart/form-data">
      <fieldset>
        <legend>Formulario de recogida de datos</legend>

        <?php
          $c = new mysqli("localhost","campamento_verano","campamento_verano","campamento_verano");
          $r = $c->query("SHOW COLUMNS FROM inscripciones_campamento;");

          while($f = $r->fetch_assoc()){
            // Omite el id (PRI + auto_increment)
            if($f['Key'] != "PRI" || $f['Extra'] == "DEFAULT_GENERATED"){

              /* ==========================================================
                 ✅ CASO tinyint: lo renderizamos como SWITCH
                 (y hacemos continue para no duplicar cierres)
                 ========================================================== */
              if (str_contains($f['Type'], "int") && str_contains($f['Type'], "tinyint")) {

                echo '<div class="control_formulario control_formulario--switch">';
                echo '<label for="'.$f['Field'].'">Introduce '.$f['Field'].'</label>';

                // Siempre manda valor (0/1)
                echo '<input type="hidden" name="'.$f['Field'].'" value="0">';

                // Switch
                echo '<input class="switch" type="checkbox" name="'.$f['Field'].'" id="'.$f['Field'].'" value="1">';

                echo '</div>';
                continue;
              }

              /* ==========================================================
                 ✅ Resto de tipos: se queda como lo tenías
                 ========================================================== */
              echo '<div class="control_formulario"><label for="'.$f['Field'].'">Introduce '.$f['Field'].'</label>';

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

                // Subida de archivo
                echo '<input type="file" name="'.$f['Field'].'" id="'.$f['Field'].'">';

              } else {

                // fallback por si aparece otro tipo
                echo '<input type="text" name="'.$f['Field'].'" id="'.$f['Field'].'">';

              }

              echo '</div>';
            }
          }
        ?>

        <input type="submit" value="Enviar">
      </fieldset>
    </form>
  </body>
</html>
