CREATE TABLE direcciones_entrega (
    id_direccion  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_usuario    INT NOT NULL,
    alias         VARCHAR(50)  NOT NULL,
    destinatario  VARCHAR(100) NOT NULL,
    telefono      VARCHAR(20)  NOT NULL,
    calle         VARCHAR(150) NOT NULL,
    num_exterior  VARCHAR(20)  NOT NULL,
    num_interior  VARCHAR(20)  NULL,
    colonia       VARCHAR(100) NOT NULL,
    ciudad        VARCHAR(100) NOT NULL,
    estado        VARCHAR(100) NOT NULL,
    codigo_postal CHAR(5)      NOT NULL,
    referencias   VARCHAR(255) NULL,
    PRIMARY KEY (id_direccion),
    KEY idx_direcciones_usuario (id_usuario),
    CONSTRAINT fk_direcciones_usuario
        FOREIGN KEY (id_usuario) REFERENCES usuarios (id_usuario)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE pedidos
    ADD COLUMN id_direccion INT UNSIGNED NULL,
    ADD COLUMN subtotal DECIMAL(10,2) NULL,
    ADD COLUMN iva DECIMAL(10,2) NULL;

ALTER TABLE pedidos
    ADD CONSTRAINT fk_pedidos_direccion
        FOREIGN KEY (id_direccion) REFERENCES direcciones_entrega (id_direccion)
        ON DELETE SET NULL ON UPDATE CASCADE;
