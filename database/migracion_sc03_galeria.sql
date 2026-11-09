CREATE TABLE producto_imagenes (
    id_imagen   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_producto INT NOT NULL,
    ruta_imagen VARCHAR(255) NOT NULL,
    orden       INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id_imagen),
    KEY idx_imagenes_producto (id_producto, orden),
    CONSTRAINT fk_imagenes_producto
        FOREIGN KEY (id_producto) REFERENCES productos (id_producto)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
