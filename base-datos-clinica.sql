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

-- Tabla: consulta
CREATE TABLE consulta (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_medico INT NOT NULL,
    id_paciente INT NOT NULL,
    sintomas TEXT,
    recomendaciones TEXT,
    diagnostico TEXT,
    FOREIGN KEY (id_medico) REFERENCES medicos(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    FOREIGN KEY (id_paciente) REFERENCES paciente(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);
