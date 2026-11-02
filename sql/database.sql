-- Script de creación de Base de Datos para CodeLedger
CREATE DATABASE IF NOT EXISTS codeledger_db;
USE codeledger_db;

CREATE TABLE Usuario (
  id_usuario INT AUTO_INCREMENT PRIMARY KEY,
  nombre_completo VARCHAR(255) NOT NULL,
  correo_electronico VARCHAR(255) NOT NULL UNIQUE,
  contrasena_hash VARCHAR(255) NOT NULL,
  rol ENUM('CFO', 'PM', 'DEV') NOT NULL,
  estado_operativo BOOLEAN DEFAULT TRUE
);

CREATE TABLE FichaSalarial (
  id_ficha INT AUTO_INCREMENT PRIMARY KEY,
  id_usuario INT NOT NULL,
  modalidad_contratacion ENUM('Interno', 'Contractor', 'Agencia') NOT NULL,
  salario_base DECIMAL(18,4) NOT NULL,
  prestaciones_sociales DECIMAL(18,4) DEFAULT 0.0000,
  impuestos_patronales DECIMAL(18,4) DEFAULT 0.0000,
  horas_laborales_mes DECIMAL(18,4) NOT NULL,
  FOREIGN KEY (id_usuario) REFERENCES Usuario(id_usuario) ON DELETE CASCADE
);

CREATE TABLE Proyecto (
  id_proyecto INT AUTO_INCREMENT PRIMARY KEY,
  codigo_unico VARCHAR(50) NOT NULL UNIQUE,
  nombre_proyecto VARCHAR(255) NOT NULL,
  modelo_contratacion ENUM('Time & Materials', 'Fixed Price', 'Retainer') NOT NULL,
  bac_presupuesto_inicial DECIMAL(18,4) NOT NULL,
  estado ENUM('En Investigacion', 'En Desarrollo', 'En Produccion') NOT NULL,
  moneda_contrato VARCHAR(10) DEFAULT 'USD'
);

CREATE TABLE Hito (
  id_hito INT AUTO_INCREMENT PRIMARY KEY,
  id_proyecto INT NOT NULL,
  nombre_hito VARCHAR(255) NOT NULL,
  fase_contable ENUM('Investigacion', 'Desarrollo') NOT NULL,
  presupuesto_opex DECIMAL(18,4) NOT NULL,
  estado ENUM('Pendiente', 'Entregado') DEFAULT 'Pendiente',
  FOREIGN KEY (id_proyecto) REFERENCES Proyecto(id_proyecto) ON DELETE CASCADE
);

CREATE TABLE Tarea (
  id_tarea INT AUTO_INCREMENT PRIMARY KEY,
  id_hito INT NOT NULL,
  issue_id_github VARCHAR(100),
  titulo VARCHAR(255) NOT NULL,
  peso_horas_estimadas DECIMAL(18,4) DEFAULT 0.0000,
  estado_tarea ENUM('Open', 'Closed') DEFAULT 'Open',
  categoria_contable ENUM('Billable', 'Operativa', 'I+D') NOT NULL,
  FOREIGN KEY (id_hito) REFERENCES Hito(id_hito) ON DELETE CASCADE
);

CREATE TABLE RegistroTiempo (
  id_registro INT AUTO_INCREMENT PRIMARY KEY,
  id_tarea INT NOT NULL,
  id_usuario INT NOT NULL,
  horas_incurridas DECIMAL(18,4) NOT NULL,
  estado_aprobacion ENUM('Pendiente', 'Aprobado', 'Facturado') DEFAULT 'Pendiente',
  fully_loaded_rate_hist DECIMAL(18,4),
  billable_rate_hist DECIMAL(18,4),
  FOREIGN KEY (id_tarea) REFERENCES Tarea(id_tarea) ON DELETE CASCADE,
  FOREIGN KEY (id_usuario) REFERENCES Usuario(id_usuario) ON DELETE CASCADE
);

CREATE TABLE ConsumoFinOps (
  id_consumo INT AUTO_INCREMENT PRIMARY KEY,
  id_hito INT NOT NULL,
  proveedor_cloud VARCHAR(100) NOT NULL,
  servicio VARCHAR(150) NOT NULL,
  monto_cargado DECIMAL(18,4) NOT NULL,
  fecha_consumo TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_hito) REFERENCES Hito(id_hito) ON DELETE CASCADE
);

CREATE TABLE ComprobanteFacturacion (
  id_comprobante INT AUTO_INCREMENT PRIMARY KEY,
  id_proyecto INT NOT NULL,
  numero_correlativo VARCHAR(50) NOT NULL UNIQUE,
  tipo_comprobante ENUM('Factura', 'Nota de Credito') NOT NULL,
  monto_total DECIMAL(18,4) NOT NULL,
  estado ENUM('Pre-factura', 'Emitida', 'Cobrada', 'Anulada') DEFAULT 'Pre-factura',
  FOREIGN KEY (id_proyecto) REFERENCES Proyecto(id_proyecto) ON DELETE CASCADE
);

-- usuarios de prueba (password: 123456 hasheado para pruebas)
INSERT INTO Usuario (nombre_completo, correo_electronico, contrasena_hash, rol) VALUES 
('Director CFO', 'cfo@codeledger.com', '$2y$10$e.wV4K8L9rK9b9Z1g3k5O.lG3pD.q1T1n0P1x.F1T1n0P1x.F1T1n', 'CFO'),
('Project Manager', 'pm@codeledger.com', '$2y$10$e.wV4K8L9rK9b9Z1g3k5O.lG3pD.q1T1n0P1x.F1T1n0P1x.F1T1n', 'PM'),
('Desarrollador Senior', 'dev@codeledger.com', '$2y$10$e.wV4K8L9rK9b9Z1g3k5O.lG3pD.q1T1n0P1x.F1T1n0P1x.F1T1n', 'DEV');
