<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Panel de administración</title>
  <link rel="stylesheet" href="admin.css">
  <script src="admin.js" defer></script>
</head>
<body>

<?php if ($loggedIn): ?>
  <nav>
    <h2>Panel de control</h2>

    <a class="nav-btn<?= nav_active($page==="list") ?>" href="<?= h($_SERVER["PHP_SELF"]) ?>">Listado</a>

    <a class="nav-btn<?= nav_active($page==="ops" && $opsTab==="crud") ?>"
       href="<?= h($_SERVER["PHP_SELF"]) ?>?page=ops&tab=crud&do=create">Operaciones</a>

    <a class="nav-btn<?= nav_active($page==="ops" && $opsTab==="datos") ?>"
       href="<?= h($_SERVER["PHP_SELF"]) ?>?page=ops&tab=datos&do=create">Gestión de datos</a>

    <a class="nav-btn<?= nav_active($page==="ops" && $opsTab==="mantenimiento") ?>"
       href="<?= h($_SERVER["PHP_SELF"]) ?>?page=ops&tab=mantenimiento&do=create">Mantenimiento</a>

    <a class="nav-btn logout-link" href="?logout=1">Cerrar sesión</a>
  </nav>
<?php endif; ?>

<main>
  <?php if ($panel_msg): ?>
    <div class="alert-ok"><?= h($panel_msg) ?></div>
  <?php endif; ?>
  <?php if ($panel_err): ?>
    <div class="alert-error"><?= h($panel_err) ?></div>
  <?php endif; ?>
