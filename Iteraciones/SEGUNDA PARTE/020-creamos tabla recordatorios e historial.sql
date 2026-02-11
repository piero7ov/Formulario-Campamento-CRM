USE campamento_verano;

-- =========================================================
-- v2) Tareas / recordatorios (por inscripción o global)
-- =========================================================
CREATE TABLE IF NOT EXISTS crm_tareas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_registro VARCHAR(255) NULL,          -- puede apuntar a una inscripción (PK), o ser NULL (tarea global)
  titulo VARCHAR(200) NOT NULL,
  descripcion MEDIUMTEXT NULL,

  prioridad TINYINT NOT NULL DEFAULT 2,    -- 1=Alta, 2=Media, 3=Baja
  pinned TINYINT(1) NOT NULL DEFAULT 0,    -- 1=pin arriba
  estado ENUM('pendiente','hecha') NOT NULL DEFAULT 'pendiente',

  remind_at DATETIME NULL,                -- cuándo te “debería” saltar
  due_at DATETIME NULL,                   -- fecha límite (opcional)

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX (id_registro),
  INDEX (estado),
  INDEX (pinned),
  INDEX (prioridad),
  INDEX (remind_at),
  INDEX (due_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- v3) Historial de cambios (auditoría desde PHP)
-- =========================================================
CREATE TABLE IF NOT EXISTS crm_historial_cambios (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  entidad VARCHAR(50) NOT NULL,         -- 'inscripcion' | 'tarea' | etc.
  entidad_id VARCHAR(255) NULL,         -- normalmente el PK de la inscripción / o id_registro
  accion VARCHAR(20) NOT NULL,          -- 'create' | 'update' | 'delete' | 'pin' | 'done'...
  resumen VARCHAR(255) NULL,            -- texto corto
  detalle MEDIUMTEXT NULL,              -- JSON (old/new o lo que quieras)
  admin_user VARCHAR(80) NULL,          -- quién lo hizo (tu login)
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX (entidad),
  INDEX (entidad_id),
  INDEX (accion),
  INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
