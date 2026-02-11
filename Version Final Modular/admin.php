<?php
/**
 * admin.php — ORCHESTRATOR
 * Punto de entrada del panel de administración modularizado.
 *
 * 1) Carga configuración y librerías.
 * 2) gestiona Auth (Login/Logout).
 * 3) Procesa acciones GET que devuelven ficheros (img, csv, backups).
 * 4) Procesa acciones POST (CRUD, estados, emails).
 * 5) Prepara datos (Data Fetching).
 * 6) Renderiza Vistas (Header -> Page -> Footer).
 */

require_once "includes/config.php";        // DB, Constantes, Rutas
require_once "includes/functions.php";     // Helpers generales, auditoría
require_once "includes/smtp.php";          // Envío de correos
require_once "includes/imap.php";          // Lectura de correos
require_once "includes/maintenance.php";   // Backups

require_once "includes/auth.php";          // Login logic (POST handle) y Logout

// Si hay peticiones GET de archivos (imágenes, backups, export), se procesan y EXIT.
require_once "includes/actions_get.php";

// Si hay peticiones POST (acciones de negocio), se procesan.
require_once "includes/actions.php";

// Preparación de datos para la vista (Selects, filtros, logs).
require_once "includes/data.php";

/* =========================================================
   RENDERIZADO DE VISTAS
   ========================================================= */
require_once "views/header.php";

if (!$loggedIn) {
  require_once "views/login.php";

} else {

  // Ruteo de páginas
  if ($page === "list") {
    // ¿Vista de detalle o listado?
    if (isset($_GET["view"]) && $_GET["view"] !== "") {
      require_once "views/view_record.php";
    } else {
      require_once "views/list.php";
    }

  } elseif ($page === "ops") {
    // Pestañas de operaciones
    switch ($opsTab) {
      case "crud":
        require_once "views/ops_crud.php";
        break;
      case "datos":
        require_once "views/ops_data.php";
        break;
      case "mantenimiento":
        require_once "views/ops_maint.php";
        break;
      default:
        echo "<div class='alert-error'>Pestaña desconocida.</div>";
        break;
    }

  } else {
    echo "<div class='alert-error'>Página desconocida.</div>";
  }

}

require_once "views/footer.php";
