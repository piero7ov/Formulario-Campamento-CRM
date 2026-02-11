/* =========================================
   1) Crear base de datos
   ========================================= */
CREATE DATABASE IF NOT EXISTS campamento_verano
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE campamento_verano;

/* =========================================
   2) Crear tabla
   ========================================= */
CREATE TABLE IF NOT EXISTS inscripciones_campamento (
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- Datos del campista
    nombre VARCHAR(100) NOT NULL,
    apellidos VARCHAR(150) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    telefono VARCHAR(20),

    -- Datos del campamento
    sesion VARCHAR(200) NOT NULL,                 -- Ej: "Julio - Semana 1"
    fecha_inscripcion DATE NOT NULL,              -- DATE
    edad INT,                                     -- INT
    precio DECIMAL(10,2),                         -- DECIMAL
    pagado BOOLEAN DEFAULT 0,                     -- BOOLEAN

    turno ENUM('mañana','tarde','todo_el_dia'),    -- ENUM
    camiseta ENUM('XS','S','M','L','XL'),          -- ENUM
    permiso_fotos BOOLEAN DEFAULT 0,              -- BOOLEAN

    alergias TEXT,                                -- TEXT
    contacto_emergencia VARCHAR(150),             -- VARCHAR
    telefono_emergencia VARCHAR(20),              -- VARCHAR

    documento BLOB                                -- BLOB (se deja vacío en inserts)
) ENGINE=InnoDB;

/* =========================================
   3) Insertar datos de ejemplo (sin BLOB)
   ========================================= */
INSERT INTO inscripciones_campamento
(nombre, apellidos, email, telefono, sesion, fecha_inscripcion, edad, precio, pagado, turno, camiseta, permiso_fotos, alergias, contacto_emergencia, telefono_emergencia, documento)
VALUES
('Mateo', 'Rojas', 'mateo.rojas@email.com', '999111222', 'Julio - Semana 1', '2026-06-20', 12, 450.00, 1, 'todo_el_dia', 'M', 1, 'Polen', 'Carolina Rojas', '988333444', NULL),
('Valentina', 'Gómez', 'valentina.gomez@email.com', '999222333', 'Julio - Semana 1', '2026-06-22', 11, 450.00, 0, 'mañana', 'S', 0, 'Ninguna', 'Andrea Gómez', '987444555', NULL),
('Thiago', 'Pérez', 'thiago.perez@email.com', '999333444', 'Agosto - Semana 2', '2026-07-10', 13, 480.00, 1, 'tarde', 'L', 1, 'Maní', 'Juan Pérez', '986555666', NULL);