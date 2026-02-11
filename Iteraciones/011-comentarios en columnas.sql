ALTER TABLE inscripciones_campamento
    MODIFY nombre VARCHAR(100) NOT NULL 
        COMMENT 'Primer y segundo nombre del menor según DNI/Pasaporte',
    MODIFY apellidos VARCHAR(150) NOT NULL 
        COMMENT 'Apellidos completos del menor',
    MODIFY email VARCHAR(150) NOT NULL 
        COMMENT 'Correo electrónico de contacto (usado para notificaciones y recibos)',
    MODIFY telefono VARCHAR(20) 
        COMMENT 'Número telefónico principal de contacto del campista o tutor';

ALTER TABLE inscripciones_campamento
    MODIFY sesion VARCHAR(200) NOT NULL 
        COMMENT 'Nombre del periodo o semana seleccionada (ej: Julio - Semana 1)',
    MODIFY fecha_inscripcion DATE NOT NULL 
        COMMENT 'Fecha en la que se registró la solicitud en el sistema',
    MODIFY edad INT 
        COMMENT 'Edad del campista al momento de iniciar la actividad',
    MODIFY precio DECIMAL(10,2) 
        COMMENT 'Importe total de la matrícula en euros (€)',
    MODIFY pagado BOOLEAN DEFAULT 0 
        COMMENT 'Estado del pago: 0 = Pendiente, 1 = Completado';

ALTER TABLE inscripciones_campamento
    MODIFY turno ENUM('mañana','tarde','todo_el_dia') 
        COMMENT 'Franja horaria elegida: solo mañanas, tardes o jornada completa',
    MODIFY camiseta ENUM('XS','S','M','L','XL') 
        COMMENT 'Talla de la camiseta oficial del campamento para el uniforme',
    MODIFY permiso_fotos BOOLEAN DEFAULT 0 
        COMMENT 'Autorización del tutor para el uso de imagen en redes sociales (0=No, 1=Sí)';

ALTER TABLE inscripciones_campamento
    MODIFY alergias TEXT 
        COMMENT 'Detalle de alergias, intolerancias alimentarias o medicación necesaria',
    MODIFY contacto_emergencia VARCHAR(150) 
        COMMENT 'Nombre completo de la persona a avisar en caso de accidente',
    MODIFY telefono_emergencia VARCHAR(20) 
        COMMENT 'Teléfono de urgencias (distinto al de contacto si es posible)';

ALTER TABLE inscripciones_campamento
    MODIFY documento BLOB 
        COMMENT 'Archivo binario del resguardo o ficha médica (PDF/Imagen)';

SHOW FULL COLUMNS FROM inscripciones_campamento;