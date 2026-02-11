-- A) Ver qué registros romperían el cambio
SELECT id, nombre, apellidos, email, telefono, sesion, fecha_inscripcion, edad, contacto_emergencia, telefono_emergencia
FROM inscripciones_campamento
WHERE
  nombre IS NULL OR nombre = '' OR
  apellidos IS NULL OR apellidos = '' OR
  email IS NULL OR email = '' OR
  telefono IS NULL OR telefono = '' OR
  sesion IS NULL OR sesion = '' OR
  fecha_inscripcion IS NULL OR
  edad IS NULL OR
  contacto_emergencia IS NULL OR contacto_emergencia = '' OR
  telefono_emergencia IS NULL OR telefono_emergencia = '';

-- B) Ahora sí: convertirlos en obligatorios
ALTER TABLE inscripciones_campamento
  MODIFY nombre VARCHAR(100) NOT NULL,
  MODIFY apellidos VARCHAR(150) NOT NULL,
  MODIFY email VARCHAR(150) NOT NULL,
  MODIFY telefono VARCHAR(20) NOT NULL,
  MODIFY sesion VARCHAR(200) NOT NULL,
  MODIFY fecha_inscripcion DATE NOT NULL,
  MODIFY edad INT NOT NULL,
  MODIFY contacto_emergencia VARCHAR(150) NOT NULL,
  MODIFY telefono_emergencia VARCHAR(20) NOT NULL;
