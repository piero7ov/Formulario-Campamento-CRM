# Proyecto Formulario Campamento & Panel de Administración

Este proyecto es una solución completa para la gestión de inscripciones de un campamento de verano. Consta de dos partes principales:
1.  **Formulario Público (`index.php`)**: Una interfaz moderna y dinámica para que los usuarios se inscriban.
2.  **Panel de Administración (`admin.php`)**: Un sistema modular para gestionar las inscripciones, estados, tareas y comunicaciones.

## Requisitos del Sistema

*   **Servidor Web**: Apache (recomendado XAMPP/WAMP/LAMP).
*   **PHP**: Versión 7.4 o superior.
*   **Base de Datos**: MySQL / MariaDB.
*   **Extensiones PHP requeridas**:
    *   `mysqli` (Base de datos)
    *   `imap` (Lectura de correos para el CRM)
    *   `openssl` (Seguridad SMTP/IMAP)
    *   `fileinfo` (Detección de tipos de archivos)

## Instalación

1.  **Base de Datos**:
    *   Crea una base de datos llamada `campamento_verano`.
    *   Importa la estructura inicial si tienes un backup, o deja que el sistema cree las tablas auxiliares automáticamente.
    *   La tabla principal `inscripciones_campamento` debe existir (el formulario lee su estructura dinámicamente).

2.  **Configuración**:
    *   Edita el archivo `includes/config.php`.
    *   Configura las credenciales de la base de datos (`$db_name`, usuario, contraseña).
    *   Configura las credenciales de correo (SMTP/IMAP) para el envío de notificaciones y lectura de respuestas.
    *   Define el usuario y contraseña del administrador (`$ADMIN_USER`, `$ADMIN_PASS`).

## Estructura del Proyecto

### 1. Formulario Público (`index.php`)
El punto de entrada para los usuarios.
*   **Dinámico**: Genera los campos del formulario automáticamente leyendo la base de datos (`SHOW FULL COLUMNS`).
*   **Características**:
    *   Validación en cliente y servidor.
    *   **Guardado automático**: Usa `localStorage` para no perder datos si se cierra la pestaña.
    *   **Subida de archivos**: Soporta imágenes y PDFs (guardados como BLOBs en la BD).
    *   **Diseño**: Tema "Jungle" con fondo animado (`jungle-bg.js`).

### 2. Panel de Administración (`admin.php`)
El panel ha sido **refactorizado** para ser modular y escalable. `admin.php` actúa como un orquestador que carga los siguientes componentes:

#### Lógica (`includes/`)
*   `config.php`: Configuración global, conexión BD y arranque de sesión.
*   `functions.php`: Funciones de ayuda (seguridad, formateo, auditoría).
*   `auth.php`: Login y Logout.
*   `actions.php`: Procesa envíos de formularios (Guardar cambios, enviar emails, crear tareas).
*   `actions_get.php`: Maneja descargas de archivos, imágenes y exportaciones.
*   `data.php`: Prepara los datos necesarios para las vistas.
*   `smtp.php` / `imap.php`: Librerías para emails.
*   `maintenance.php`: Sistema de backups.

#### Vistas (`views/`)
*   `header.php` / `footer.php`: Estructura HTML común.
*   `login.php`: Formulario de acceso.
*   `list.php`: Tabla principal de inscripciones con filtros avanzados.
*   `view_record.php`: Ficha detallada de una inscripción (Informe, Historial, Comunicaciones).
*   `ops_crud.php`: Formularios para Crear/Editar inscripciones manualmente.
*   `ops_data.php`: Gestión global de tareas y recordatorios.
*   `ops_maint.php`: Panel de mantenimiento (backups y logs del sistema).

## Funcionalidades Clave

*   **CRM Integrado**:
    *   Estados personalizados (Nuevo, Contactado, Pagado, etc.).
    *   Gestión de tareas y recordatorios con prioridades.
    *   Historial de cambios (Auditoría completa de quién cambió qué).
*   **Gestión de Correo**:
    *   Envío de emails vía SMTP (Gmail compatible).
    *   Lectura de respuestas vía IMAP, integradas en la ficha del inscrito.
*   **Gestión de Archivos**:
    *   Visualización de miniaturas de documentos adjuntos.
    *   Descarga de archivos originales.
*   **Mantenimiento**:
    *   Generación de backups SQL de todo el sistema en un clic.
    *   Exportación de datos a CSV.

---
**Desarrollado por**: Piero Olivares (PieroDev)
