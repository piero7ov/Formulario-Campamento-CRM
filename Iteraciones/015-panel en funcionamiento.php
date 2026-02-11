<?php
// 1. Conexión a la base de datos
$db_name = "campamento_verano";
$c = new mysqli("localhost", "campamento_verano", "campamento_verano", $db_name);

if ($c->connect_error) {
    die("Error de conexión: " . $c->connect_error);
}
?>
<!doctype html>
<html lang="es">
  <head>
    <title>Panel de administración</title>
    <meta charset="utf-8">
    <style>
      /* ===== Base ===== */
      *{ box-sizing: border-box; }

      body{
        margin: 0;
        font-family: Arial, Helvetica, sans-serif;
        background: LightBlue;
        color: #083136;
      }

      /* ===== Layout: sidebar + content ===== */
      body{
        display: flex;
        min-height: 100vh;
      }

      nav{
        width: 220px;
        background: LightSeaGreen;
        padding: 16px;
      }

      nav h2{
        margin: 0 0 14px;
        color: white;
        font-size: 18px;
        text-align: center;
        
      }

      nav button{
        width: 100%;
        display: block;
        margin-bottom: 10px;

        padding: 10px 12px;
        border: 1px solid rgba(255,255,255,.6);
        background: rgba(255,255,255,.15);
        color: white;
        font-weight: bold;
        border-radius: 10px;

        cursor: pointer;
      }

      nav button:hover{
        background: rgba(255,255,255,.25);
      }

      main{
        flex: 1;
        padding: 20px;
      }

      /* ===== Tabla clásica ===== */
      table{
        width: 100%;
        border-collapse: collapse;
        background: white;
      }

      td{
        border: 1px solid rgba(0,0,0,.2);
        padding: 8px;
        font-size: 14px;
      }

      /* primera fila como “cabecera” sin cambiar HTML */
      tr:first-child td{
        background: LightSeaGreen;
        color: white;
        font-weight: bold;
      }

      /* filas alternas */
      tr:nth-child(even) td{
        background: rgba(173,216,230,.35); /* LightBlue suave */
      }
    </style>
  </head>

  <body>
    <nav>
      <h2>Panel de control</h2>
      <button>Enlace 1</button>
      <button>Enlace 2</button>
      <button>Enlace 3</button>
    </nav>

    <main>
      <table>
        <thead>
          <tr>
            <?php
            /* COLUMNAS CON SUS COMENTARIOS */
              $r = $c->query("
                SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_KEY, COLUMN_DEFAULT, COLUMN_COMMENT
                FROM information_schema.columns
                WHERE table_schema='campamento_verano'
                  AND table_name='inscripciones_campamento'
              ");

              while($f = $r->fetch_assoc()) {
                echo '<th>'.$f['COLUMN_NAME'].'</th>';
              
              }
            ?>
          </tr>
        </thead>
        <tbody>
          <?php
            $r = $c->query("SELECT * FROM inscripciones_campamento;");

              while($f = $r->fetch_assoc()) {
              echo '<tr>';
              foreach($f as $clave=>$valor){
                echo '<td>'.$valor.'</td>';
              }
              echo  '</tr>';
              
              }
          ?>
        </tbody>
      </table>
    </main>
  </body>
</html>
