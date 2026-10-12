<?php

/**
 * categorias_controller.php
 *
 * CRUD de categorías para el panel administrativo.
 *
 * Proyecto:
 * Sistema Integral E-Commerce y Gestión de Inventario
 *
 * Métodos:
 * GET    -> Consultar categorías
 * POST   -> Crear categoría
 * PUT    -> Modificar categoría
 * DELETE -> Eliminar categoría
 *
 * Acceso:
 * Únicamente usuarios autenticados con rol "admin".
 */

require_once __DIR__ . '/../config/conexion.php';

header('Content-Type: application/json; charset=utf-8');


/* =========================================================
 * FUNCIONES AUXILIARES
 * ========================================================= */

/**
 * Envía una respuesta JSON y termina la ejecución.
 *
 * @param int   $codigoHTTP
 * @param array $respuesta
 * @return never
 */
function responderJSON(int $codigoHTTP, array $respuesta): never
{
    http_response_code($codigoHTTP);

    echo json_encode(
        $respuesta,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}


/**
 * Obtiene el cuerpo JSON de la petición.
 *
 * @return array
 */
function obtenerDatosJSON(): array
{
    $contenido = file_get_contents('php://input');

    if ($contenido === false || trim($contenido) === '') {
        responderJSON(400, [
            'success' => false,
            'message' => 'El cuerpo de la solicitud está vacío.'
        ]);
    }

    $datos = json_decode($contenido, true);

    if (!is_array($datos)) {
        responderJSON(400, [
            'success' => false,
            'message' => 'El cuerpo de la solicitud no contiene un JSON válido.'
        ]);
    }

    return $datos;
}


/**
 * Obtiene y valida un ID de categoría.
 *
 * @return int
 */
function obtenerIdCategoria(): int
{
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

    if ($id === false || $id === null || $id <= 0) {
        responderJSON(400, [
            'success' => false,
            'message' => 'El id de la categoría no es válido.'
        ]);
    }

    return $id;
}


/**
 * Valida el nombre de una categoría.
 *
 * La tabla categorias.nombre_categoria es VARCHAR(50) NOT NULL.
 *
 * @param mixed $nombre
 * @return string
 */
function validarNombreCategoria(mixed $nombre): string
{
    if (!is_string($nombre)) {
        responderJSON(400, [
            'success' => false,
            'message' => 'El nombre de la categoría debe ser texto.'
        ]);
    }

    $nombre = trim($nombre);

    if ($nombre === '') {
        responderJSON(400, [
            'success' => false,
            'message' => 'El nombre de la categoría es obligatorio.'
        ]);
    }

    if (mb_strlen($nombre, 'UTF-8') > 50) {
        responderJSON(400, [
            'success' => false,
            'message' => 'El nombre de la categoría no puede superar los 50 caracteres.'
        ]);
    }

    return $nombre;
}


/* =========================================================
 * SESIÓN Y CONTROL DE ACCESO
 * ========================================================= */

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();

/*
 * Todas las operaciones de este controlador son
 * exclusivamente administrativas.
 */
if (
    !isset($_SESSION['id_usuario']) ||
    !isset($_SESSION['rol']) ||
    $_SESSION['rol'] !== 'admin'
) {
    responderJSON(403, [
        'success' => false,
        'message' => 'Acceso denegado. Se requieren privilegios de administrador.'
    ]);
}


/* =========================================================
 * PROCESAMIENTO DEL CONTROLADOR
 * ========================================================= */

try {

    $pdo = Conexion::obtenerConexion();
    $metodo = $_SERVER['REQUEST_METHOD'];


    /* =====================================================
     * GET - CONSULTAR CATEGORÍAS
     * ===================================================== */

    if ($metodo === 'GET') {

        /*
         * Si se proporciona ?id=,
         * devolvemos una categoría específica.
         */
        if (isset($_GET['id'])) {

            $idCategoria = obtenerIdCategoria();

            $sql = "
                SELECT
                    id_categoria,
                    nombre_categoria
                FROM categorias
                WHERE id_categoria = :id_categoria
                LIMIT 1
            ";

            $stmt = $pdo->prepare($sql);

            $stmt->execute([
                ':id_categoria' => $idCategoria
            ]);

            $categoria = $stmt->fetch();

            if (!$categoria) {
                responderJSON(400, [
                    'success' => false,
                    'message' => 'La categoría solicitada no existe.'
                ]);
            }

            responderJSON(200, [
                'success' => true,
                'message' => 'Categoría obtenida correctamente.',
                'categoria' => [
                    'id_categoria' => (int) $categoria['id_categoria'],
                    'nombre_categoria' => $categoria['nombre_categoria']
                ]
            ]);
        }


        /*
         * Si no se proporciona ID,
         * devolvemos todas las categorías.
         */
        $sql = "
            SELECT
                id_categoria,
                nombre_categoria
            FROM categorias
            ORDER BY nombre_categoria ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();

        $categorias = $stmt->fetchAll();

        $resultado = [];

        foreach ($categorias as $categoria) {
            $resultado[] = [
                'id_categoria' => (int) $categoria['id_categoria'],
                'nombre_categoria' => $categoria['nombre_categoria']
            ];
        }

        responderJSON(200, [
            'success' => true,
            'message' => 'Categorías obtenidas correctamente.',
            'total' => count($resultado),
            'categorias' => $resultado
        ]);
    }


    /* =====================================================
     * POST - CREAR CATEGORÍA
     * ===================================================== */

    if ($metodo === 'POST') {

        $datos = obtenerDatosJSON();

        $nombreCategoria = validarNombreCategoria(
            $datos['nombre_categoria'] ?? null
        );


        /*
         * Comprobamos primero si ya existe una categoría
         * con el mismo nombre.
         *
         * Esto evita duplicados lógicos aunque actualmente
         * el esquema no tenga UNIQUE sobre nombre_categoria.
         */
        $sqlExiste = "
            SELECT id_categoria
            FROM categorias
            WHERE nombre_categoria = :nombre_categoria
            LIMIT 1
        ";

        $stmtExiste = $pdo->prepare($sqlExiste);

        $stmtExiste->execute([
            ':nombre_categoria' => $nombreCategoria
        ]);

        if ($stmtExiste->fetch()) {
            responderJSON(400, [
                'success' => false,
                'message' => 'Ya existe una categoría con ese nombre.'
            ]);
        }


        /*
         * Insertamos utilizando Prepared Statement.
         */
        $sql = "
            INSERT INTO categorias (nombre_categoria)
            VALUES (:nombre_categoria)
        ";

        $stmt = $pdo->prepare($sql);

        $stmt->execute([
            ':nombre_categoria' => $nombreCategoria
        ]);

        $idNuevo = (int) $pdo->lastInsertId();

        responderJSON(200, [
            'success' => true,
            'message' => 'Categoría creada correctamente.',
            'categoria' => [
                'id_categoria' => $idNuevo,
                'nombre_categoria' => $nombreCategoria
            ]
        ]);
    }


    /* =====================================================
     * PUT - MODIFICAR CATEGORÍA
     * ===================================================== */

    if ($metodo === 'PUT') {

        $idCategoria = obtenerIdCategoria();

        $datos = obtenerDatosJSON();

        $nombreCategoria = validarNombreCategoria(
            $datos['nombre_categoria'] ?? null
        );


        /*
         * Verificamos que la categoría exista.
         */
        $sqlExiste = "
            SELECT id_categoria
            FROM categorias
            WHERE id_categoria = :id_categoria
            LIMIT 1
        ";

        $stmtExiste = $pdo->prepare($sqlExiste);

        $stmtExiste->execute([
            ':id_categoria' => $idCategoria
        ]);

        if (!$stmtExiste->fetch()) {
            responderJSON(400, [
                'success' => false,
                'message' => 'La categoría que intenta modificar no existe.'
            ]);
        }


        /*
         * Evitamos que dos categorías tengan el mismo nombre.
         *
         * Se excluye la categoría que actualmente estamos
         * modificando.
         */
        $sqlDuplicada = "
            SELECT id_categoria
            FROM categorias
            WHERE nombre_categoria = :nombre_categoria
              AND id_categoria <> :id_categoria
            LIMIT 1
        ";

        $stmtDuplicada = $pdo->prepare($sqlDuplicada);

        $stmtDuplicada->execute([
            ':nombre_categoria' => $nombreCategoria,
            ':id_categoria' => $idCategoria
        ]);

        if ($stmtDuplicada->fetch()) {
            responderJSON(400, [
                'success' => false,
                'message' => 'Ya existe otra categoría con ese nombre.'
            ]);
        }


        /*
         * Actualización mediante Prepared Statement.
         */
        $sql = "
            UPDATE categorias
            SET nombre_categoria = :nombre_categoria
            WHERE id_categoria = :id_categoria
        ";

        $stmt = $pdo->prepare($sql);

        $stmt->execute([
            ':nombre_categoria' => $nombreCategoria,
            ':id_categoria' => $idCategoria
        ]);

        responderJSON(200, [
            'success' => true,
            'message' => 'Categoría actualizada correctamente.',
            'categoria' => [
                'id_categoria' => $idCategoria,
                'nombre_categoria' => $nombreCategoria
            ]
        ]);
    }


    /* =====================================================
     * DELETE - ELIMINAR CATEGORÍA
     * ===================================================== */

    if ($metodo === 'DELETE') {

        $idCategoria = obtenerIdCategoria();


        /*
         * Verificamos que la categoría exista.
         */
        $sqlExiste = "
            SELECT id_categoria
            FROM categorias
            WHERE id_categoria = :id_categoria
            LIMIT 1
        ";

        $stmtExiste = $pdo->prepare($sqlExiste);

        $stmtExiste->execute([
            ':id_categoria' => $idCategoria
        ]);

        if (!$stmtExiste->fetch()) {
            responderJSON(400, [
                'success' => false,
                'message' => 'La categoría que intenta eliminar no existe.'
            ]);
        }


        /*
         * La FK productos.id_categoria utiliza
         * ON DELETE SET NULL, por lo que eliminar
         * la categoría no elimina los productos.
         */
        $sql = "
            DELETE FROM categorias
            WHERE id_categoria = :id_categoria
        ";

        $stmt = $pdo->prepare($sql);

        $stmt->execute([
            ':id_categoria' => $idCategoria
        ]);

        responderJSON(200, [
            'success' => true,
            'message' => 'Categoría eliminada correctamente.',
            'id_categoria' => $idCategoria
        ]);
    }


    /* =====================================================
     * MÉTODO NO SOPORTADO
     * ===================================================== */

    responderJSON(400, [
        'success' => false,
        'message' => 'Método HTTP no soportado por este controlador.'
    ]);


} catch (PDOException $e) {

    /*
     * El detalle técnico queda únicamente en el log
     * del servidor.
     */
    error_log(
        'Error PDO en categorias_controller.php: '
        . $e->getMessage()
    );

    responderJSON(500, [
        'success' => false,
        'message' => 'Error interno del servidor.'
    ]);


} catch (Throwable $e) {

    /*
     * Captura de errores inesperados.
     */
    error_log(
        'Error inesperado en categorias_controller.php: '
        . $e->getMessage()
    );

    responderJSON(500, [
        'success' => false,
        'message' => 'Error interno del servidor.'
    ]);
}