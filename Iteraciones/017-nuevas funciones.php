<?php
session_start();

/* =========================================================
   1) Conexión BD
   ========================================================= */
$db_name    = "campamento_verano";
$table_name = "inscripciones_campamento";

$c = new mysqli("localhost", "campamento_verano", "campamento_verano", $db_name);
if ($c->connect_error) {
  die("Error de conexión: " . $c->connect_error);
}

/* =========================================================
   2) Login fijo
   ========================================================= */
$ADMIN_USER = "";
$ADMIN_PASS = "";

/* =========================================================
   3) Tabla auxiliar CRM + updated_at
   ========================================================= */
$c->query("
  CREATE TABLE IF NOT EXISTS crm_estados_inscripciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_registro VARCHAR(255) NOT NULL UNIQUE,
    estado VARCHAR(50) NOT NULL,
    color  VARCHAR(20) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

/* =========================================================
   4) Estados CRM + colores
   ========================================================= */
$estadosCRM = [
  "Nuevo"             => "#0ea5e9",
  "Contactado"        => "#14b8a6",
  "En seguimiento"    => "#f59e0b",
  "Pendiente de pago" => "#fb923c",
  "Pagado"            => "#22c55e",
  "Cancelado"         => "#ef4444",
  "Completado"        => "#6366f1",
];

/* =========================================================
   5) Helper
   ========================================================= */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

/* =========================================================
   6) Detectar PRIMARY KEY
   ========================================================= */
$primaryKeyColumn = null;

$pkResult = $c->query("
  SELECT COLUMN_NAME
  FROM information_schema.columns
  WHERE table_schema = '".$c->real_escape_string($db_name)."'
    AND table_name   = '".$c->real_escape_string($table_name)."'
    AND COLUMN_KEY   = 'PRI'
  LIMIT 1
");
if ($pkResult && $pkResult->num_rows > 0) {
  $primaryKeyColumn = $pkResult->fetch_assoc()["COLUMN_NAME"];
}

/* =========================================================
   7) Logout
   ========================================================= */
if (isset($_GET["logout"])) {
  session_destroy();
  header("Location: " . $_SERVER["PHP_SELF"]);
  exit;
}

/* =========================================================
   8) Login
   ========================================================= */
$login_error = "";

if (isset($_POST["action"]) && $_POST["action"] === "login") {
  $usuario  = $_POST["usuario"]  ?? "";
  $password = $_POST["password"] ?? "";

  if ($usuario === $ADMIN_USER && $password === $ADMIN_PASS) {
    $_SESSION["admin_logged"] = true;
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
  } else {
    $login_error = "Usuario o contraseña incorrectos.";
  }
}

$loggedIn = !empty($_SESSION["admin_logged"]);

/* =========================================================
   9) Actualizar estado CRM
   ========================================================= */
$panel_msg = "";

if ($loggedIn && isset($_POST["action"]) && $_POST["action"] === "update_estado") {
  $id_registro = $_POST["id_registro"] ?? null;
  $estado      = $_POST["estado"]      ?? null;

  if ($id_registro !== null && isset($estadosCRM[$estado])) {
    $color = $estadosCRM[$estado];

    $stmt = $c->prepare("
      INSERT INTO crm_estados_inscripciones (id_registro, estado, color)
      VALUES (?, ?, ?)
      ON DUPLICATE KEY UPDATE
        estado = VALUES(estado),
        color  = VALUES(color)
    ");
    if ($stmt) {
      $stmt->bind_param("sss", $id_registro, $estado, $color);
      if ($stmt->execute()) {
        $panel_msg = "Estado actualizado correctamente (ID #".h($id_registro).").";
      }
      $stmt->close();
    }
  }
}

/* =========================================================
   10) Estados actuales para pintar tabla
   ========================================================= */
$estadosActuales = [];
if ($loggedIn) {
  $resEstados = $c->query("SELECT id_registro, estado, color FROM crm_estados_inscripciones");
  if ($resEstados) {
    while ($row = $resEstados->fetch_assoc()) {
      $estadosActuales[$row["id_registro"]] = $row;
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Panel de administración</title>

  <style>
    /* ================== Base ================== */
    *{ box-sizing:border-box; }

    :root{
      --bg:#f5f7fb;
      --card:#ffffff;
      --text:#083136;
      --muted:#6b7280;
      --border:#e5e7eb;

      --sky: rgba(173,216,230,.28);
      --grad: linear-gradient(180deg, rgba(95,191,192,.28), rgba(173,216,230,.25));

      --shadow: 0 10px 25px rgba(0,0,0,.07);
      --radius: 16px;
      --navW: 260px;

      --ctrl-bg: rgba(255,255,255,.75);
      --ctrl-border: rgba(95,191,192,.45);
      --ctrl-text: #063b40;
    }

    body{
      margin:0;
      font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
      background: var(--bg);
      color: var(--text);
      min-height: 100vh;
    }

    /* ================== Login ================== */
    .login-wrapper{
      min-height: 100vh;
      display:grid;
      place-items:center;
      padding:24px;
    }
    .login-card{
      width:min(520px, 100%);
      background:#fff;
      border:1px solid var(--border);
      border-radius: var(--radius);
      padding:22px;
      box-shadow: var(--shadow);
    }
    .logo{ display:flex; justify-content:center; margin-bottom:12px; }
    .logo img{ width:min(320px, 100%); height:auto; object-fit:contain; }

    .login-card h2{
      margin: 6px 0 16px;
      text-align:center;
      font-size: 1.15rem;
      font-weight: 900;
      color:#0f172a;
    }
    .control{ margin-top: 14px; }
    label{ display:block; margin:0 0 6px; font-weight:800; color:#1f2937; }

    input[type="text"], input[type="password"]{
      width:100%;
      padding:12px 14px;
      border:1px solid #d1d5db;
      border-radius:12px;
      outline:none;
    }
    input:focus{
      border-color:#2563eb;
      box-shadow: 0 0 0 4px rgba(37,99,235,.15);
    }

    .btn{
      margin-top:18px;
      width:100%;
      padding:14px;
      border:0;
      border-radius:12px;
      font-weight: 900;
      cursor:pointer;
      color:#0f172a;
      background: var(--grad);
    }

    .alert-error{
      margin-bottom:10px;
      padding:12px 14px;
      border-radius:12px;
      background:#fee2e2;
      border:1px solid #fecaca;
      color:#991b1b;
      font-weight: 800;
    }

    /* ================== Layout admin ================== */
    nav{
      width: var(--navW);
      padding: 18px;

      position: fixed;
      left:0; top:0;
      height:100vh;
      overflow:auto;

      background: var(--grad);
      border-right: 1px solid var(--border);
      box-shadow: 0 6px 18px rgba(0,0,0,.05);

      display:flex;
      flex-direction:column;
      gap:10px;
    }

    nav h2{
      margin:0;
      font-size: 18px;
      font-weight: 900;
      color:#0f172a;
      display:flex;
      gap:10px;
      align-items:center;
    }
    nav h2::before{
      content:"";
      width:10px; height:10px;
      border-radius:999px;
      background:#5fbfc0;
      flex: 0 0 auto;
    }

    .nav-sub{
      margin:0;
      font-size: 13px;
      font-weight: 800;
      color:#0f172a;
      opacity:.85;
    }

    .nav-btn{
      width:100%;
      padding:12px 14px;
      border: 1px solid rgba(0,0,0,.08);
      background:#fff;
      color:#0f172a;
      font-weight: 900;
      border-radius: 12px;
      cursor:pointer;
      text-decoration:none;
      text-align:center;
    }

    /* Empuja el logout al fondo */
    .logout-link{
      margin-top:auto;
    }

    main{
      margin-left: var(--navW);
      padding: 24px;
      min-height: 100vh;
    }

    h3{
      margin:0 0 14px;
      font-size: 18px;
      font-weight: 900;
      color:#0f172a;
      display:flex;
      align-items:center;
      gap:10px;
    }
    h3::before{
      content:"";
      width:12px; height:12px;
      border-radius:4px;
      background:#9fd7ea;
      box-shadow: 0 0 0 4px rgba(159,215,234,.35);
      flex: 0 0 auto;
    }

    .alert-ok{
      margin: 0 0 14px;
      padding: 12px 14px;
      border-radius: 12px;
      border: 1px solid rgba(95,191,192,.35);
      background: rgba(95,191,192,.12);
      color: #063b40;
      font-weight: 900;
    }

    /* ================== Tabla (scroll interno) ================== */
    .table-wrap{
      width:100%;
      max-height: calc(100vh - 190px);
      overflow: auto;
      scrollbar-gutter: stable;

      border-radius: var(--radius);
      box-shadow: var(--shadow);
      background: var(--card);
      border: 1px solid var(--border);
    }

    table{
      width: max-content;
      min-width: 100%;
      border-collapse: separate;
      border-spacing: 0;
    }

    thead th{
      position: sticky;
      top: 0;
      z-index: 2;

      text-align:left;
      padding: 8px 10px;
      font-size: 11px;
      font-weight: 900;
      color: #063b40;

      background: var(--grad);
      border-bottom: 1px solid rgba(0,0,0,.06);

      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 190px;
    }

    tbody td{
      padding: 8px 10px;
      font-size: 12px;
      color:#0f172a;

      border-top: 1px solid var(--border);
      vertical-align: top;

      max-width: 240px;
      overflow-wrap: anywhere;
      word-break: break-word;
    }

    tbody td + td,
    thead th + th{
      border-left: 1px solid var(--border);
    }

    tbody tr:nth-child(even) td{ background: var(--sky); }
    tbody tr:hover td{ background: rgba(95,191,192,.12); }

    th.col-estado, td.col-estado{ min-width: 150px; }
    th.col-acciones, td.col-acciones{ min-width: 240px; vertical-align: middle; }

    .estado-pill{
      display:inline-flex;
      align-items:center;
      padding: 6px 10px;
      border-radius: 999px;
      font-weight: 900;
      font-size: 11px;
      border: 1px solid rgba(0,0,0,.08);
      background: rgba(255,255,255,.65);
      white-space: nowrap;
    }
    .estado-pill--vacio{
      color: var(--muted);
      border-style: dashed;
      background: transparent;
    }

    /* ================== Acciones (compacto) ================== */
    .form-estado{
      display:flex;
      gap:6px;
      align-items:center;
      flex-wrap: nowrap;
    }

    .form-estado select{
      width: 150px;
      min-width: 150px;
      padding: 6px 8px;
      border-radius: 10px;
      border: 1px solid var(--ctrl-border);
      background: var(--ctrl-bg);
      color: #0f172a;
      font-weight: 800;
      font-size: 11px;
      line-height: 1.2;
      outline:none;
      box-shadow: 0 1px 4px rgba(0,0,0,.05);
    }
    .form-estado select:focus{
      border-color: rgba(58,169,170,.65);
      box-shadow: 0 0 0 4px rgba(95,191,192,.16);
    }

    .btn-estado{
      padding: 6px 10px;
      border-radius: 10px;
      border: 1px solid var(--ctrl-border);
      background: var(--ctrl-bg);
      color: var(--ctrl-text);
      font-weight: 900;
      font-size: 11px;
      cursor: pointer;
      white-space: nowrap;
      margin: 0;
      box-shadow: 0 1px 4px rgba(0,0,0,.05);
    }
    .btn-estado:hover{
      background: rgba(255,255,255,.88);
      border-color: rgba(58,169,170,.60);
    }
    .btn-estado:active{
      transform: translateY(1px);
      box-shadow: none;
    }
    .btn-estado:focus-visible{
      outline: none;
      box-shadow: 0 0 0 4px rgba(95,191,192,.16);
    }

    @media (max-width: 720px){
      :root{ --navW: 220px; }
      main{ padding: 16px; }
      .table-wrap{ max-height: calc(100vh - 170px); }
    }
  </style>
</head>

<body>
<?php if (!$loggedIn): ?>

  <div class="login-wrapper">
    <form method="post" class="login-card">
      <div class="logo">
        <img src="https://piero7ov.github.io/pierodev-assets/brand/pierodev/logos/logocompleto.png" alt="PieroDev logo">
      </div>

      <h2>Acceso al panel</h2>

      <?php if ($login_error !== ""): ?>
        <div class="alert-error"><?= h($login_error) ?></div>
      <?php endif; ?>

      <div class="control">
        <label for="usuario">Usuario</label>
        <input type="text" name="usuario" id="usuario" autocomplete="username" required>
      </div>

      <div class="control">
        <label for="password">Contraseña</label>
        <input type="password" name="password" id="password" autocomplete="current-password" required>
      </div>

      <input type="hidden" name="action" value="login">
      <input class="btn" type="submit" value="Entrar">
    </form>
  </div>

<?php else: ?>

  <nav>
    <h2>Panel de control</h2>
    <div class="nav-sub">Sesión: <?= h($ADMIN_USER) ?></div>

    <button class="nav-btn" type="button">Enlace 1</button>
    <button class="nav-btn" type="button">Enlace 2</button>
    <button class="nav-btn" type="button">Enlace 3</button>

    <a class="nav-btn logout-link" href="?logout=1">Cerrar sesión</a>
  </nav>

  <main>
    <h3>Listado de inscripciones del campamento</h3>

    <?php if ($panel_msg): ?>
      <div class="alert-ok"><?= h($panel_msg) ?></div>
    <?php endif; ?>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <?php
              $cols = [];
              $r = $c->query("
                SELECT COLUMN_NAME
                FROM information_schema.columns
                WHERE table_schema='".$c->real_escape_string($db_name)."'
                  AND table_name='".$c->real_escape_string($table_name)."'
                ORDER BY ORDINAL_POSITION
              ");

              while($f = $r->fetch_assoc()){
                $colName = $f["COLUMN_NAME"];
                $cols[] = $colName;
                echo '<th title="'.h($colName).'">'.h($colName).'</th>';
              }

              echo '<th class="col-estado" title="Estado CRM">Estado CRM</th>';
              echo '<th class="col-acciones" title="Acciones">Acciones</th>';
            ?>
          </tr>
        </thead>

        <tbody>
          <?php
            $orderBy = $primaryKeyColumn ? $primaryKeyColumn : $cols[0];
            $r = $c->query("SELECT * FROM `".$table_name."` ORDER BY `".$orderBy."` DESC");

            while($f = $r->fetch_assoc()){
              $idRegistro = null;
              if ($primaryKeyColumn !== null && isset($f[$primaryKeyColumn])) {
                $idRegistro = (string)$f[$primaryKeyColumn];
              }

              echo "<tr>";

              foreach($cols as $col){
                $val = $f[$col] ?? "";
                if ($val === null || $val === "") {
                  echo '<td><span style="color:#6b7280;font-weight:800;">—</span></td>';
                } else {
                  echo "<td title='".h($val)."'>".h($val)."</td>";
                }
              }

              echo '<td class="col-estado">';
              if ($idRegistro !== null && isset($estadosActuales[$idRegistro])) {
                $estadoRow = $estadosActuales[$idRegistro];
                $estadoTxt = h($estadoRow["estado"]);
                $colorTxt  = h($estadoRow["color"]);
                echo '<span class="estado-pill" style="background:'.$colorTxt.'20; border-color:'.$colorTxt.'; color:'.$colorTxt.';">'.$estadoTxt.'</span>';
              } else {
                echo '<span class="estado-pill estado-pill--vacio">Sin estado</span>';
              }
              echo '</td>';

              echo '<td class="col-acciones">';
              if ($idRegistro !== null) {
                echo '<form method="post" class="form-estado">';
                echo '<input type="hidden" name="action" value="update_estado">';
                echo '<input type="hidden" name="id_registro" value="'.h($idRegistro).'">';

                echo '<select name="estado">';
                foreach($estadosCRM as $nombreEstado => $colorEstado){
                  $selected = (
                    isset($estadosActuales[$idRegistro]) &&
                    $estadosActuales[$idRegistro]["estado"] === $nombreEstado
                  ) ? " selected" : "";
                  echo '<option value="'.h($nombreEstado).'"'.$selected.'>'.h($nombreEstado).'</option>';
                }
                echo '</select>';

                echo '<button type="submit" class="btn-estado">Guardar</button>';
                echo '</form>';
              } else {
                echo '<span style="color:#6b7280;font-weight:800;">—</span>';
              }
              echo '</td>';

              echo "</tr>";
            }
          ?>
        </tbody>
      </table>
    </div>
  </main>

<?php endif; ?>
</body>
</html>
