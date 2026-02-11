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

      .control_formulario input[type="text"]{
        width: 100%;
        padding: 12px 14px;
        border: 1px solid #d1d5db;
        border-radius: 12px;
        outline: none;
        background: #fff;
        transition: border-color .2s, box-shadow .2s, transform .05s;
      }

      .control_formulario input[type="text"]:focus{
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
    </style>
  </head>

  <body>
    <form>
      <fieldset>
        <legend>Formulario de recogida de datos</legend>
<?php
  $c = new mysqli("localhost","campamento_verano","campamento_verano","campamento_verano");
  $r = $c->query("SHOW COLUMNS FROM inscripciones_campamento;");

  while($f = $r->fetch_assoc()){
    // Omite el id (PRI + auto_increment)
    if($f['Key'] != "PRI" || $f['Extra'] == "DEFAULT_GENERATED"){

      echo '<div class="control_formulario"><label for="'.$f['Field'].'">Introduce '.$f['Field'].'</label>';

      if (str_contains($f['Type'], "varchar")) {

        echo '<input type="text" name="'.$f['Field'].'" id="'.$f['Field'].'">';

      } else if($f['Type'] == "date") {

        echo '<input type="date" name="'.$f['Field'].'" id="'.$f['Field'].'">';

      } else if(str_contains($f['Type'], "int")) {

        // boolean en MySQL suele venir como tinyint(1)
        if (str_contains($f['Type'], "tinyint")) {
          echo '<input type="checkbox" name="'.$f['Field'].'" id="'.$f['Field'].'" value="1">';
        } else {
          echo '<input type="number" name="'.$f['Field'].'" id="'.$f['Field'].'">';
        }

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
