-- ============================================================
-- CodeLedger - Sistema Integral de Contabilidad Operativa TI
-- Esquema de base de datos (MySQL 8+)
-- ============================================================

CREATE DATABASE IF NOT EXISTS codeledger_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE codeledger_db;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS EventoAuditoria;
DROP TABLE IF EXISTS SyncLog;
DROP TABLE IF EXISTS Integracion;
DROP TABLE IF EXISTS ConsumoRetainer;
DROP TABLE IF EXISTS ContratoRetainer;
DROP TABLE IF EXISTS ActivoIntangible;
DROP TABLE IF EXISTS ReglaContable;
DROP TABLE IF EXISTS AlertaFinOps;
DROP TABLE IF EXISTS PresupuestoFinOps;
DROP TABLE IF EXISTS ConsumoFinOps;
DROP TABLE IF EXISTS ReconocimientoIngreso;
DROP TABLE IF EXISTS FacturaDetalle;
DROP TABLE IF EXISTS ComprobanteFacturacion;
DROP TABLE IF EXISTS RegistroTiempo;
DROP TABLE IF EXISTS Tarea;
DROP TABLE IF EXISTS Hito;
DROP TABLE IF EXISTS TarifaVenta;
DROP TABLE IF EXISTS Proyecto;
DROP TABLE IF EXISTS Cliente;
DROP TABLE IF EXISTS CostoAsignadoUsuario;
DROP TABLE IF EXISTS FichaSalarial;
DROP TABLE IF EXISTS Usuario;
DROP TABLE IF EXISTS Configuracion;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- Organización y personas
-- ------------------------------------------------------------
CREATE TABLE Usuario (
  id_usuario INT AUTO_INCREMENT PRIMARY KEY,
  nombre_completo VARCHAR(255) NOT NULL,
  correo_electronico VARCHAR(255) NOT NULL UNIQUE,
  contrasena_hash VARCHAR(255) NOT NULL,
  rol ENUM('CFO', 'PM', 'DEV') NOT NULL,
  nivel ENUM('Junior', 'Senior', 'Architect') NOT NULL DEFAULT 'Senior',
  estado_operativo BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE FichaSalarial (
  id_ficha INT AUTO_INCREMENT PRIMARY KEY,
  id_usuario INT NOT NULL UNIQUE,
  modalidad_contratacion ENUM('Interno', 'Contractor', 'Agencia') NOT NULL,
  salario_base DECIMAL(18,4) NOT NULL DEFAULT 0,
  prestaciones_sociales DECIMAL(18,4) NOT NULL DEFAULT 0,
  impuestos_patronales DECIMAL(18,4) NOT NULL DEFAULT 0,
  horas_laborales_mes DECIMAL(8,2) NOT NULL DEFAULT 160,
  moneda VARCHAR(3) NOT NULL DEFAULT 'USD',
  vigente_desde DATE NOT NULL,
  FOREIGN KEY (id_usuario) REFERENCES Usuario(id_usuario) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE CostoAsignadoUsuario (
  id_costo INT AUTO_INCREMENT PRIMARY KEY,
  id_usuario INT NOT NULL,
  concepto VARCHAR(150) NOT NULL,
  categoria ENUM('Licencia', 'Hardware', 'Beneficio', 'Otro') NOT NULL DEFAULT 'Licencia',
  monto_mensual DECIMAL(18,4) NOT NULL DEFAULT 0,
  vida_util_meses INT DEFAULT NULL,
  activo BOOLEAN NOT NULL DEFAULT TRUE,
  FOREIGN KEY (id_usuario) REFERENCES Usuario(id_usuario) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Clientes y proyectos
-- ------------------------------------------------------------
CREATE TABLE Cliente (
  id_cliente INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(255) NOT NULL,
  industria VARCHAR(120) DEFAULT NULL,
  moneda VARCHAR(3) NOT NULL DEFAULT 'USD',
  contacto VARCHAR(255) DEFAULT NULL,
  activo BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE Proyecto (
  id_proyecto INT AUTO_INCREMENT PRIMARY KEY,
  id_cliente INT NOT NULL,
  id_manager INT DEFAULT NULL,
  codigo_unico VARCHAR(50) NOT NULL UNIQUE,
  nombre_proyecto VARCHAR(255) NOT NULL,
  modelo_contratacion ENUM('Time & Materials', 'Fixed Price', 'Retainer') NOT NULL,
  bac_presupuesto_inicial DECIMAL(18,4) NOT NULL DEFAULT 0,
  estado ENUM('En Investigacion', 'En Desarrollo', 'En Produccion', 'Cerrado') NOT NULL DEFAULT 'En Desarrollo',
  moneda_contrato VARCHAR(3) NOT NULL DEFAULT 'USD',
  tecnologia VARCHAR(120) DEFAULT NULL,
  metodologia VARCHAR(120) DEFAULT NULL,
  fecha_inicio DATE DEFAULT NULL,
  fecha_fin_estimada DATE DEFAULT NULL,
  tarifa_fija_total DECIMAL(18,4) NOT NULL DEFAULT 0,
  horas_estimadas DECIMAL(10,2) NOT NULL DEFAULT 0,
  activo BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_cliente) REFERENCES Cliente(id_cliente),
  FOREIGN KEY (id_manager) REFERENCES Usuario(id_usuario) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE TarifaVenta (
  id_tarifa INT AUTO_INCREMENT PRIMARY KEY,
  rol ENUM('Junior', 'Senior', 'Architect') NOT NULL,
  modalidad_contratacion ENUM('Interno', 'Contractor', 'Agencia') NOT NULL,
  monto_hora DECIMAL(18,4) NOT NULL,
  moneda VARCHAR(3) NOT NULL DEFAULT 'USD',
  vigente_desde DATE NOT NULL,
  UNIQUE KEY uk_tarifa (rol, modalidad_contratacion, vigente_desde)
) ENGINE=InnoDB;

CREATE TABLE Hito (
  id_hito INT AUTO_INCREMENT PRIMARY KEY,
  id_proyecto INT NOT NULL,
  nombre_hito VARCHAR(255) NOT NULL,
  fase_contable ENUM('Investigacion', 'Desarrollo') NOT NULL,
  presupuesto_opex DECIMAL(18,4) NOT NULL DEFAULT 0,
  presupuesto_capex DECIMAL(18,4) NOT NULL DEFAULT 0,
  valor_facturable DECIMAL(18,4) NOT NULL DEFAULT 0,
  estado ENUM('Pendiente', 'En Curso', 'Entregado') NOT NULL DEFAULT 'Pendiente',
  fecha_inicio DATE DEFAULT NULL,
  fecha_entrega DATE DEFAULT NULL,
  FOREIGN KEY (id_proyecto) REFERENCES Proyecto(id_proyecto) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE Tarea (
  id_tarea INT AUTO_INCREMENT PRIMARY KEY,
  id_hito INT NOT NULL,
  issue_id_github VARCHAR(100) DEFAULT NULL,
  titulo VARCHAR(255) NOT NULL,
  historia_usuario VARCHAR(255) DEFAULT NULL,
  peso_horas_estimadas DECIMAL(10,2) NOT NULL DEFAULT 0,
  estado_tarea ENUM('Open', 'Closed') NOT NULL DEFAULT 'Open',
  categoria_contable ENUM('Billable', 'Operativa', 'I+D') NOT NULL DEFAULT 'Billable',
  fecha_creacion DATE DEFAULT NULL,
  FOREIGN KEY (id_hito) REFERENCES Hito(id_hito) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Timesheets
-- ------------------------------------------------------------
CREATE TABLE RegistroTiempo (
  id_registro INT AUTO_INCREMENT PRIMARY KEY,
  id_tarea INT NOT NULL,
  id_usuario INT NOT NULL,
  horas_incurridas DECIMAL(8,2) NOT NULL,
  estado_aprobacion ENUM('Pendiente', 'Aprobado', 'Facturado') NOT NULL DEFAULT 'Pendiente',
  fully_loaded_rate_hist DECIMAL(18,4) NOT NULL DEFAULT 0,
  billable_rate_hist DECIMAL(18,4) NOT NULL DEFAULT 0,
  fecha_registro DATE NOT NULL,
  descripcion VARCHAR(500) DEFAULT NULL,
  id_comprobante INT DEFAULT NULL,
  facturado_en DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_tarea) REFERENCES Tarea(id_tarea) ON DELETE CASCADE,
  FOREIGN KEY (id_usuario) REFERENCES Usuario(id_usuario) ON DELETE CASCADE,
  INDEX idx_reg_fecha (fecha_registro),
  INDEX idx_reg_estado (estado_aprobacion)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Facturación y reconocimiento de ingresos
-- ------------------------------------------------------------
CREATE TABLE ComprobanteFacturacion (
  id_comprobante INT AUTO_INCREMENT PRIMARY KEY,
  id_proyecto INT NOT NULL,
  id_cliente INT NOT NULL,
  numero_correlativo VARCHAR(50) NOT NULL UNIQUE,
  tipo_comprobante ENUM('Factura', 'Nota de Credito', 'Pre-factura') NOT NULL DEFAULT 'Pre-factura',
  monto_total DECIMAL(18,4) NOT NULL DEFAULT 0,
  estado ENUM('Pre-factura', 'Emitida', 'Cobrada', 'Anulada') NOT NULL DEFAULT 'Pre-factura',
  moneda VARCHAR(3) NOT NULL DEFAULT 'USD',
  tipo_cambio DECIMAL(18,6) NOT NULL DEFAULT 1,
  monto_total_usd DECIMAL(18,4) NOT NULL DEFAULT 0,
  metodo_cobro ENUM('Transferencia', 'Stripe', 'PayPal', 'Otro') DEFAULT 'Transferencia',
  fecha_emision DATE DEFAULT NULL,
  fecha_vencimiento DATE DEFAULT NULL,
  fecha_cobro DATE DEFAULT NULL,
  observaciones VARCHAR(500) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_proyecto) REFERENCES Proyecto(id_proyecto) ON DELETE CASCADE,
  FOREIGN KEY (id_cliente) REFERENCES Cliente(id_cliente),
  INDEX idx_fact_estado (estado)
) ENGINE=InnoDB;

CREATE TABLE FacturaDetalle (
  id_detalle INT AUTO_INCREMENT PRIMARY KEY,
  id_comprobante INT NOT NULL,
  concepto VARCHAR(255) NOT NULL,
  cantidad DECIMAL(10,2) NOT NULL DEFAULT 1,
  precio_unitario DECIMAL(18,4) NOT NULL DEFAULT 0,
  monto DECIMAL(18,4) NOT NULL DEFAULT 0,
  id_registro INT DEFAULT NULL,
  FOREIGN KEY (id_comprobante) REFERENCES ComprobanteFacturacion(id_comprobante) ON DELETE CASCADE,
  FOREIGN KEY (id_registro) REFERENCES RegistroTiempo(id_registro) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE ReconocimientoIngreso (
  id_reconocimiento INT AUTO_INCREMENT PRIMARY KEY,
  id_comprobante INT NOT NULL,
  monto_total DECIMAL(18,4) NOT NULL,
  monto_reconocido DECIMAL(18,4) NOT NULL DEFAULT 0,
  fecha_inicio DATE NOT NULL,
  fecha_fin DATE NOT NULL,
  metodo ENUM('Lineal', 'Hitos', 'Entrega') NOT NULL DEFAULT 'Lineal',
  estado ENUM('Diferido', 'Reconociendo', 'Reconocido') NOT NULL DEFAULT 'Diferido',
  FOREIGN KEY (id_comprobante) REFERENCES ComprobanteFacturacion(id_comprobante) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- FinOps (cloud, APIs y licencias)
-- ------------------------------------------------------------
CREATE TABLE ConsumoFinOps (
  id_consumo INT AUTO_INCREMENT PRIMARY KEY,
  id_proyecto INT NOT NULL,
  id_hito INT DEFAULT NULL,
  proveedor VARCHAR(100) NOT NULL,
  categoria ENUM('Cloud', 'API', 'Licencia', 'Otro') NOT NULL DEFAULT 'Cloud',
  servicio VARCHAR(150) NOT NULL,
  monto_cargado DECIMAL(18,4) NOT NULL,
  moneda VARCHAR(3) NOT NULL DEFAULT 'USD',
  fecha_consumo DATE NOT NULL,
  referencia_externa VARCHAR(150) DEFAULT NULL,
  etiqueta_proyecto VARCHAR(100) DEFAULT NULL,
  facturado BOOLEAN NOT NULL DEFAULT FALSE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_proyecto) REFERENCES Proyecto(id_proyecto) ON DELETE CASCADE,
  FOREIGN KEY (id_hito) REFERENCES Hito(id_hito) ON DELETE SET NULL,
  UNIQUE KEY uk_consumo_ref (referencia_externa),
  INDEX idx_consumo_fecha (fecha_consumo)
) ENGINE=InnoDB;

CREATE TABLE PresupuestoFinOps (
  id_presupuesto INT AUTO_INCREMENT PRIMARY KEY,
  id_proyecto INT NOT NULL,
  id_hito INT DEFAULT NULL,
  proveedor VARCHAR(100) DEFAULT NULL,
  servicio VARCHAR(150) DEFAULT NULL,
  presupuesto_mensual DECIMAL(18,4) NOT NULL DEFAULT 0,
  umbral_alerta DECIMAL(5,2) NOT NULL DEFAULT 80,
  activo BOOLEAN NOT NULL DEFAULT TRUE,
  FOREIGN KEY (id_proyecto) REFERENCES Proyecto(id_proyecto) ON DELETE CASCADE,
  FOREIGN KEY (id_hito) REFERENCES Hito(id_hito) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE AlertaFinOps (
  id_alerta INT AUTO_INCREMENT PRIMARY KEY,
  id_presupuesto INT DEFAULT NULL,
  id_proyecto INT NOT NULL,
  id_hito INT DEFAULT NULL,
  tipo VARCHAR(80) NOT NULL DEFAULT 'Desviación OpEx',
  mensaje VARCHAR(500) NOT NULL,
  nivel ENUM('Info', 'Advertencia', 'Crítica') NOT NULL DEFAULT 'Advertencia',
  monto_actual DECIMAL(18,4) NOT NULL DEFAULT 0,
  monto_limite DECIMAL(18,4) NOT NULL DEFAULT 0,
  leida BOOLEAN NOT NULL DEFAULT FALSE,
  fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_proyecto) REFERENCES Proyecto(id_proyecto) ON DELETE CASCADE,
  FOREIGN KEY (id_hito) REFERENCES Hito(id_hito) ON DELETE SET NULL,
  FOREIGN KEY (id_presupuesto) REFERENCES PresupuestoFinOps(id_presupuesto) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Contabilidad / IAS 38
-- ------------------------------------------------------------
CREATE TABLE ReglaContable (
  id_regla INT AUTO_INCREMENT PRIMARY KEY,
  id_proyecto INT NOT NULL,
  fase ENUM('Investigacion', 'Desarrollo') NOT NULL,
  tratamiento ENUM('OpEx', 'CapEx') NOT NULL,
  vida_util_meses INT NOT NULL DEFAULT 36,
  metodo_amortizacion ENUM('Lineal') NOT NULL DEFAULT 'Lineal',
  activo BOOLEAN NOT NULL DEFAULT TRUE,
  UNIQUE KEY uk_regla (id_proyecto, fase),
  FOREIGN KEY (id_proyecto) REFERENCES Proyecto(id_proyecto) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE ActivoIntangible (
  id_activo INT AUTO_INCREMENT PRIMARY KEY,
  id_proyecto INT NOT NULL,
  nombre VARCHAR(255) NOT NULL,
  fecha_capitalizacion DATE NOT NULL,
  monto_capitalizado DECIMAL(18,4) NOT NULL DEFAULT 0,
  vida_util_meses INT NOT NULL DEFAULT 36,
  amortizacion_mensual DECIMAL(18,4) NOT NULL DEFAULT 0,
  amortizacion_acumulada DECIMAL(18,4) NOT NULL DEFAULT 0,
  estado ENUM('Activo', 'Amortizado') NOT NULL DEFAULT 'Activo',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_activo_proyecto (id_proyecto),
  FOREIGN KEY (id_proyecto) REFERENCES Proyecto(id_proyecto) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Multimoneda, retainer e integraciones
-- ------------------------------------------------------------
CREATE TABLE TipoCambio (
  id_tipo_cambio INT AUTO_INCREMENT PRIMARY KEY,
  moneda_origen VARCHAR(3) NOT NULL,
  moneda_destino VARCHAR(3) NOT NULL DEFAULT 'USD',
  tasa DECIMAL(18,6) NOT NULL,
  fecha DATE NOT NULL,
  UNIQUE KEY uk_tipo_cambio (moneda_origen, moneda_destino, fecha)
) ENGINE=InnoDB;

CREATE TABLE ContratoRetainer (
  id_retainer INT AUTO_INCREMENT PRIMARY KEY,
  id_proyecto INT NOT NULL UNIQUE,
  horas_incluidas_mes DECIMAL(8,2) NOT NULL DEFAULT 0,
  precio_mensual DECIMAL(18,4) NOT NULL DEFAULT 0,
  rollover_maximo DECIMAL(8,2) NOT NULL DEFAULT 0,
  fecha_inicio DATE NOT NULL,
  activo BOOLEAN NOT NULL DEFAULT TRUE,
  FOREIGN KEY (id_proyecto) REFERENCES Proyecto(id_proyecto) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE ConsumoRetainer (
  id_consumo_ret INT AUTO_INCREMENT PRIMARY KEY,
  id_retainer INT NOT NULL,
  periodo CHAR(7) NOT NULL,
  horas_consumidas DECIMAL(8,2) NOT NULL DEFAULT 0,
  saldo_inicial DECIMAL(8,2) NOT NULL DEFAULT 0,
  saldo_final DECIMAL(8,2) NOT NULL DEFAULT 0,
  facturado BOOLEAN NOT NULL DEFAULT FALSE,
  UNIQUE KEY uk_retainer_periodo (id_retainer, periodo),
  FOREIGN KEY (id_retainer) REFERENCES ContratoRetainer(id_retainer) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE Integracion (
  id_integracion INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(120) NOT NULL,
  tipo ENUM('Tareas', 'Cloud', 'ERP', 'Pagos') NOT NULL,
  proveedor VARCHAR(120) NOT NULL,
  estado ENUM('Conectado', 'Pendiente', 'Error') NOT NULL DEFAULT 'Pendiente',
  configuracion TEXT DEFAULT NULL,
  ultima_sincronizacion DATETIME DEFAULT NULL,
  activo BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB;

CREATE TABLE SyncLog (
  id_log INT AUTO_INCREMENT PRIMARY KEY,
  id_integracion INT NOT NULL,
  tipo VARCHAR(80) NOT NULL,
  referencia VARCHAR(255) DEFAULT NULL,
  mensaje VARCHAR(500) DEFAULT NULL,
  registros INT NOT NULL DEFAULT 0,
  estado ENUM('OK', 'Error') NOT NULL DEFAULT 'OK',
  fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_integracion) REFERENCES Integracion(id_integracion) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE EventoAuditoria (
  id_evento INT AUTO_INCREMENT PRIMARY KEY,
  id_usuario INT DEFAULT NULL,
  accion VARCHAR(120) NOT NULL,
  entidad VARCHAR(120) DEFAULT NULL,
  id_entidad INT DEFAULT NULL,
  detalle VARCHAR(500) DEFAULT NULL,
  ip VARCHAR(64) DEFAULT NULL,
  fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_usuario) REFERENCES Usuario(id_usuario) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE Configuracion (
  clave VARCHAR(100) PRIMARY KEY,
  valor VARCHAR(500) NOT NULL,
  descripcion VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB;
