-- Crear la base de datos
CREATE DATABASE IF NOT EXISTS `test-clinica` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Usar la base de datos
USE `test-clinica`;

-- Tabla: medicos
CREATE TABLE medicos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    primer_nombre VARCHAR(50),
    segundo_nombre VARCHAR(50),
    apellido_paterno VARCHAR(50),
    apellido_materno VARCHAR(50),
    cedula VARCHAR(20) UNIQUE,
    telefono VARCHAR(20),
    especialidad VARCHAR(100),
    email VARCHAR(100) UNIQUE,
    activo TINYINT(1) DEFAULT 1,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabla: paciente
CREATE TABLE paciente (
    id INT AUTO_INCREMENT PRIMARY KEY,
    primer_nombre VARCHAR(50),
    segundo_nombre VARCHAR(50),
    apellido_paterno VARCHAR(50),
    apellido_materno VARCHAR(50),
    telefono VARCHAR(20),
    activo TINYINT(1) DEFAULT 1,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabla: usuario
CREATE TABLE usuario (
    id INT AUTO_INCREMENT PRIMARY KEY,
    correo VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    nombre_completo VARCHAR(100),
    id_medico INT DEFAULT NULL,
    activo TINYINT(1) DEFAULT 1,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_medico) REFERENCES medicos(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
);

CREATE TABLE consulta (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_medico INT NOT NULL,
    id_paciente INT NOT NULL,
    fecha DATE NOT NULL,
    hora TIME NOT NULL,
    motivo VARCHAR(200) NOT NULL,
    notas TEXT,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_medico) REFERENCES medicos(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    FOREIGN KEY (id_paciente) REFERENCES paciente(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);

-- Datos iniciales para medicos
INSERT INTO medicos (
    primer_nombre,
    segundo_nombre,
    apellido_paterno,
    apellido_materno,
    cedula,
    telefono,
    especialidad,
    email,
    activo
)
VALUES
    ('Ana', 'Lucía', 'García', 'Ramírez', 'MED-001', '+52 55 1111 1111', 'Cardiología', 'ana.garcia@example.com', 1),
    ('Bruno', NULL, 'Hernández', 'Santos', 'MED-002', '+52 55 2222 2222', 'Pediatría', 'bruno.hernandez@example.com', 1),
    ('Carla', 'María', 'López', 'Martínez', 'MED-003', '+52 55 3333 3333', 'Dermatología', 'carla.lopez@example.com', 0),
    ('Diego', NULL, 'Ortiz', 'Flores', 'MED-004', '+52 55 4444 4444', 'Neurología', 'diego.ortiz@example.com', 1);

-- Datos iniciales para pacientes
INSERT INTO paciente (
    primer_nombre,
    segundo_nombre,
    apellido_paterno,
    apellido_materno,
    telefono,
    activo
)
VALUES
    ('Elena', 'Sofía', 'Pérez', 'Gómez', '+52 55 5555 5555', 1),
    ('Fernando', NULL, 'Ruiz', 'Lara', '+52 55 6666 6666', 1),
    ('Gabriela', 'Isabel', 'Torres', 'Nava', '+52 55 7777 7777', 0),
    ('Héctor', NULL, 'Jiménez', 'Vega', '+52 55 8888 8888', 1);
