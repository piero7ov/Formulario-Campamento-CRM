<?php
/**
 * includes/config.example.php
 * Archivo de configuración de ejemplo.
 * IMPORTANTE: Renombrar este archivo a config.php. 
 */

session_start();

// Validar zona horaria si es necesario
date_default_timezone_set('Europe/Madrid'); // Ajusta según tu zona

/* =========================================================
   0) RUTEO SIMPLE
   ========================================================= */
$page   = isset($_GET["page"]) ? (string)$_GET["page"] : "list";
$page   = in_array($page, ["list","ops"], true) ? $page : "list";

$opsTab    = isset($_GET["tab"]) ? (string)$_GET["tab"] : "crud";
$opsTab    = in_array($opsTab, ["crud","datos","mantenimiento"], true) ? $opsTab : "crud";

$opsAction = isset($_GET["do"]) ? (string)$_GET["do"] : "create";
$opsAction = in_array($opsAction, ["create","edit"], true) ? $opsAction : "create";

/* =========================================================
   1) CREDENCIALES
   ========================================================= */
// Admin
$ADMIN_USER = "admin";
$ADMIN_PASS = "admin";

// Email (Gmail + SMTP)
$hostname = '{imap.gmail.com:993/imap/ssl}INBOX';
$username = 'TU_CORREO@gmail.com';
$password = 'TU_CONTRASEÑA_DE_APLICACION';


$smtpHost = 'smtp.gmail.com';
$smtpPort = 587; // STARTTLS
$smtpUser = $username;
$smtpPass = $password;

$fromEmail = $username;
$fromName  = 'Panel Campamento';

/* =========================================================
   2) CONEXIÓN BD
   ========================================================= */
$db_name    = "campamento_verano";
$table_name = "inscripciones_campamento";

$c = new mysqli("localhost", "campamento_verano", "campamento_verano", $db_name);
if ($c->connect_error) {
  die("Error de conexión: " . $c->connect_error);
}
$c->set_charset("utf8mb4");

/* =========================================================
   3) DIRECTORIOS
   ========================================================= */
$BACKUP_DIR = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "backups_crm";
if (!is_dir($BACKUP_DIR)) {
  @mkdir($BACKUP_DIR, 0775, true);
}

/* =========================================================
   4) ESTADOS CRM
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
   5) TABLAS AUXILIARES (CREATE IF NOT EXISTS)
   ========================================================= */
// crm_estados_inscripciones
$c->query("
  CREATE TABLE IF NOT EXISTS crm_estados_inscripciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_registro VARCHAR(255) NOT NULL UNIQUE,
    estado VARCHAR(50) NOT NULL,
    color  VARCHAR(20) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

// crm_comunicaciones
$c->query("
  CREATE TABLE IF NOT EXISTS crm_comunicaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_registro VARCHAR(255) NOT NULL,
    email_usuario VARCHAR(255) NOT NULL,
    direccion VARCHAR(20) NOT NULL,
    asunto VARCHAR(255) NULL,
    cuerpo MEDIUMTEXT NULL,
    meta MEDIUMTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (id_registro),
    INDEX (email_usuario),
    INDEX (direccion)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

// crm_tareas
$c->query("
  CREATE TABLE IF NOT EXISTS crm_tareas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_registro VARCHAR(255) NULL,
    titulo VARCHAR(200) NOT NULL,
    descripcion MEDIUMTEXT NULL,
    prioridad TINYINT NOT NULL DEFAULT 2,
    pinned TINYINT(1) NOT NULL DEFAULT 0,
    estado ENUM('pendiente','hecha') NOT NULL DEFAULT 'pendiente',
    remind_at DATETIME NULL,
    due_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (id_registro),
    INDEX (estado),
    INDEX (pinned),
    INDEX (prioridad),
    INDEX (remind_at),
    INDEX (due_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

// crm_historial_cambios
$c->query("
  CREATE TABLE IF NOT EXISTS crm_historial_cambios (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    entidad VARCHAR(50) NOT NULL,
    entidad_id VARCHAR(255) NULL,
    accion VARCHAR(20) NOT NULL,
    resumen VARCHAR(255) NULL,
    detalle MEDIUMTEXT NULL,
    admin_user VARCHAR(80) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (entidad),
    INDEX (entidad_id),
    INDEX (accion),
    INDEX (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

// crm_mantenimiento_log
$c->query("
  CREATE TABLE IF NOT EXISTS crm_mantenimiento_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    accion VARCHAR(50) NOT NULL,
    archivo VARCHAR(255) NULL,
    bytes BIGINT NULL,
    estado VARCHAR(20) NOT NULL,
    detalle MEDIUMTEXT NULL,
    admin_user VARCHAR(80) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (accion),
    INDEX (estado),
    INDEX (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
