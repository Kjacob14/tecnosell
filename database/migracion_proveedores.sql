-- ============================================================
-- Migración: proveedores
-- Ejecutar una sola vez en phpMyAdmin (base de datos de TecnoSell),
-- pestaña "SQL". Compatible con MariaDB de XAMPP.
-- ============================================================

CREATE TABLE IF NOT EXISTS proveedores (
    id_proveedor   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre_empresa VARCHAR(100) NOT NULL,
    contacto       VARCHAR(100) NULL,
    telefono       VARCHAR(20)  NULL,
    email          VARCHAR(100) NULL,
    direccion      VARCHAR(255) NULL,
    estado         TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id_proveedor),
    UNIQUE KEY uq_proveedores_nombre (nombre_empresa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Relación opcional: un producto puede tener un proveedor.
-- Si se elimina el proveedor, el producto se conserva (queda sin proveedor).
ALTER TABLE productos
    ADD COLUMN IF NOT EXISTS id_proveedor INT UNSIGNED NULL;

ALTER TABLE productos
    ADD CONSTRAINT fk_productos_proveedor
    FOREIGN KEY (id_proveedor) REFERENCES proveedores (id_proveedor)
    ON DELETE SET NULL ON UPDATE CASCADE;
