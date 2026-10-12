<?php

/**
 * conexion.php
 *
 * Gestiona la conexión única con MySQL/MariaDB mediante PDO.
 *
 * Proyecto:
 * Sistema Integral E-Commerce y Gestión de Inventario
 *
 * Motor:
 * MySQL / MariaDB - InnoDB
 */

class Conexion
{
    /**
     * Instancia única de la conexión PDO.
     *
     * @var PDO|null
     */
    private static ?PDO $conexion = null;

    /**
     * Constructor privado para impedir
     * la creación directa de objetos.
     */
    private function __construct()
    {
    }

    /**
     * Devuelve la instancia única de PDO.
     *
     * @return PDO
     * @throws PDOException
     */
    public static function obtenerConexion(): PDO
    {
        if (self::$conexion === null) {

            // Configuración de la base de datos.
            $host = '127.0.0.1';
            $db   = 'tienda_electronica';
            $user = 'root';
            $pass = 'Ximena2005';

            // DSN con utf8mb4 para soportar caracteres especiales.
            $dsn = "mysql:host={$host};dbname={$db};charset=utf8mb4";

            try {

                self::$conexion = new PDO(
                    $dsn,
                    $user,
                    $pass,
                    [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false
                    ]
                );

            } catch (PDOException $e) {

                // Registrar el error técnico sin mostrar detalles sensibles al cliente.
                error_log('Error de conexión a la base de datos: ' . $e->getMessage());

                throw new PDOException(
                    'No fue posible establecer la conexión con la base de datos.'
                );
            }
        }

        return self::$conexion;
    }
}