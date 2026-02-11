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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
      *{ box-sizing: border-box; }

      :root{
        /* Base */
        --bg: #f5f7fb;
        --card: #ffffff;
        --text: #083136;
        --muted: #6b7280;
        --border: #e5e7eb;

        --sky-100: rgba(173,216,230,.28); /* LightBlue suave */
        --sky-200: rgba(173,216,230,.45);
        --sky-300: #9fd7ea;

        --sea: #5fbfc0;
        --sea-dark: #3aa9aa;

        --shadow: 0 10px 25px rgba(0,0,0,.07);
        --radius: 16px;

        --navW: 260px; /* ancho fijo del nav */
      }

      body{
        margin: 0;
        font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
        background: var(--bg);
        color: var(--text);
        min-height: 100vh;
      }

      /* ===== NAV fijo a la izquierda SIEMPRE ===== */
      nav{
        width: var(--navW);
        padding: 18px;

        position: fixed;
        left: 0;
        top: 0;
        height: 100vh;
        overflow: auto;

        background: linear-gradient(180deg, rgba(95,191,192,.28), rgba(173,216,230,.25));
        border-right: 1px solid var(--border);
        box-shadow: 0 6px 18px rgba(0,0,0,.05);
      }

      nav h2{
        margin: 0 0 14px;
        font-size: 18px;
        font-weight: 800;
        color: #0f172a;

        display: flex;
        align-items: center;
        gap: 10px;
      }

      nav h2::before{
        content: "";
        width: 10px;
        height: 10px;
        border-radius: 999px;
        background: var(--sea);
        flex: 0 0 auto;
      }

      nav button{
        width: 100%;
        display: block;
        margin-bottom: 10px;

        padding: 12px 14px;
        border: 1px solid rgba(0,0,0,.08);
        background: linear-gradient(180deg, #ffffff, #f3fbff);
        color: #0f172a;

        font-weight: 700;
        border-radius: 12px;
        cursor: pointer;

        transition: transform .12s ease, box-shadow .12s ease, background .12s ease, border-color .12s ease;
      }

      nav button:hover{
        background: linear-gradient(180deg, #ffffff, #eaf8ff);
        border-color: rgba(0,0,0,.12);
        transform: translateY(-1px);
        box-shadow: 0 10px 18px rgba(0,0,0,.08);
      }

      nav button:active{
        transform: translateY(0);
        box-shadow: none;
      }

      nav button:focus-visible{
        outline: none;
        box-shadow: 0 0 0 4px rgba(95,191,192,.22);
        border-color: rgba(58,169,170,.55);
      }

      main{
        margin-left: var(--navW);
        padding: 24px;
        min-height: 100vh;
      }

      /* ===== Título del listado ===== */
      main h3{
        margin: 0 0 14px;
        font-size: 18px;
        font-weight: 800;
        color: #0f172a;

        display: inline-flex;
        align-items: center;
        gap: 10px;
      }

      main h3::before{
        content: "";
        width: 12px;
        height: 12px;
        border-radius: 4px;
        background: var(--sky-300);
        box-shadow: 0 0 0 4px rgba(159,215,234,.35);
        flex: 0 0 auto;
      }

      .table-wrap{
        width: 100%;
        overflow-x: auto;
        padding-bottom: 6px;
      }
      table{
        width: 100%;
        min-width: 980px;

        border-collapse: separate;
        border-spacing: 0;

        background: var(--card);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        overflow: hidden;
      }

      thead th{
        position: sticky;
        top: 0;
        z-index: 1;

        text-align: left;
        padding: 12px 12px;

        font-size: 13px;
        font-weight: 800;
        color: #063b40;

        /* cabecera suave celeste */
        background: linear-gradient(180deg, rgba(95,191,192,.28), rgba(173,216,230,.25));
        border-bottom: 1px solid rgba(0,0,0,.06);
        white-space: nowrap;
      }

      tbody td{
        padding: 10px 12px;
        font-size: 13px;
        color: #0f172a;

        border-top: 1px solid var(--border);
        vertical-align: top;

        max-width: 360px;
        word-break: break-word;
      }

      /* líneas internas suaves */
      tbody td + td,
      thead th + th{
        border-left: 1px solid var(--border);
      }

      /* alternado muy sutil */
      tbody tr:nth-child(even) td{
        background: var(--sky-100);
      }

      /* hover para lectura */
      tbody tr:hover td{
        background: rgba(95,191,192,.12);
      }

      @media (max-width: 720px){
        :root{ --navW: 220px; }
        main{ padding: 16px; }
        table{ min-width: 860px; }
      }

      @media (prefers-reduced-motion: reduce){
        nav button{ transition: none; }
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
      <h3>Listado de inscripciones del campamento</h3>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <?php
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
                echo '</tr>';
              }
            ?>
          </tbody>
        </table>
      </div>
    </main>
  </body>
</html>
