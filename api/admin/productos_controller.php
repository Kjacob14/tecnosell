<?php

/**
 * productos_controller.php
 *
 * CRUD de productos para el panel administrativo.
 *
 * Proyecto:
 * Sistema Integral E-Commerce y Gestión de Inventario
 *
 * Métodos:
 * GET    -> Consultar productos
 * POST   -> Crear producto
 * PUT    -> Modificar producto
 * DELETE -> Eliminar producto
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
 * Responde en JSON y termina la ejecución.
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
 * Obtiene un ID válido desde la URL.
 *
 * Ejemplo:
 * ?id=1
 */
function obtenerIdProducto(): int
{
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

    if ($id === false || $id === null || $id <= 0) {
        responderJSON(400, [
            'success' => false,
            'message' => 'El id del producto no es válido.'
        ]);
    }

    return $id;
}


/**
 * Lee un cuerpo JSON.
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
 * Valida el nombre del producto.
 *
 * productos.nombre = VARCHAR(150) NOT NULL
 */
function validarNombreProducto(mixed $nombre): string
{
    if (!is_string($nombre)) {
        responderJSON(400, [
            'success' => false,
            'message' => 'El nombre del producto debe ser texto.'
        ]);
    }

    $nombre = trim($nombre);

    if ($nombre === '') {
        responderJSON(400, [
            'success' => false,
            'message' => 'El nombre del producto es obligatorio.'
        ]);
    }

    if (mb_strlen($nombre, 'UTF-8') > 150) {
        responderJSON(400, [
            'success' => false,
            'message' => 'El nombre del producto no puede superar los 150 caracteres.'
        ]);
    }

    return $nombre;
}


/**
 * Valida la descripción.
 *
 * productos.descripcion = TEXT
 */
function validarDescripcion(mixed $descripcion): ?string
{
    if ($descripcion === null || $descripcion === '') {
        return null;
    }

    if (!is_string($descripcion)) {
        responderJSON(400, [
            'success' => false,
            'message' => 'La descripción del producto debe ser texto.'
        ]);
    }

    return trim($descripcion);
}


/**
 * Valida el precio.
 *
 * productos.precio = DECIMAL(10,2)
 */
function validarPrecio(mixed $precio): string
{
    if (is_int($precio) || is_float($precio)) {
        $precio = (string) $precio;
    }

    if (!is_string($precio)) {
        responderJSON(400, [
            'success' => false,
            'message' => 'El precio debe ser numérico.'
        ]);
    }

    $precio = trim($precio);

    /*
     * DECIMAL(10,2):
     * máximo permitido: 99999999.99
     */
    if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $precio)) {
        responderJSON(400, [
            'success' => false,
            'message' => 'El precio debe ser un número válido con hasta 2 decimales.'
        ]);
    }

    if ((float) $precio > 99999999.99) {
        responderJSON(400, [
            'success' => false,
            'message' => 'El precio supera el límite permitido.'
        ]);
    }

    return number_format((float) $precio, 2, '.', '');
}


/**
 * Valida el stock.
 *
 * productos.stock = INT NOT NULL DEFAULT 0
 */
function validarStock(mixed $stock): int
{
    if (is_string($stock)) {
        $stock = trim($stock);
    }

    if (
        filter_var(
            $stock,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]]
        ) === false
    ) {
        responderJSON(400, [
            'success' => false,
            'message' => 'El stock debe ser un número entero mayor o igual a 0.'
        ]);
    }

    return (int) $stock;
}


/**
 * Valida el estado del producto.
 *
 * La tabla utiliza BOOLEAN.
 */
function validarEstado(mixed $estado): int
{
    if (is_bool($estado)) {
        return $estado ? 1 : 0;
    }

    if ($estado === 0 || $estado === '0') {
        return 0;
    }

    if ($estado === 1 || $estado === '1') {
        return 1;
    }

    responderJSON(400, [
        'success' => false,
        'message' => 'El estado debe ser 0 o 1.'
    ]);
}


/**
 * Valida la categoría.
 *
 * La categoría puede ser NULL porque id_categoria
 * no está definida como NOT NULL.
 */
function validarIdCategoria(mixed $idCategoria): ?int
{
    if ($idCategoria === null || $idCategoria === '') {
        return null;
    }

    if (
        filter_var(
            $idCategoria,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        ) === false
    ) {
        responderJSON(400, [
            'success' => false,
            'message' => 'El id de la categoría no es válido.'
        ]);
    }

    return (int) $idCategoria;
}


/**
 * Verifica que una categoría exista.
 */
function verificarCategoria(PDO $pdo, ?int $idCategoria): void
{
    if ($idCategoria === null) {
        return;
    }

    $sql = "
        SELECT id_categoria
        FROM categorias
        WHERE id_categoria = :id_categoria
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ':id_categoria' => $idCategoria
    ]);

    if (!$stmt->fetch()) {
        responderJSON(400, [
            'success' => false,
            'message' => 'La categoría indicada no existe.'
        ]);
    }
}


/**
 * Valida el id de proveedor (opcional: vacío o ausente = sin proveedor).
 */
function validarIdProveedor(mixed $idProveedor): ?int
{
    if ($idProveedor === null || $idProveedor === '' || $idProveedor === 'null') {
        return null;
    }

    $id = filter_var($idProveedor, FILTER_VALIDATE_INT);

    if ($id === false || $id <= 0) {
        responderJSON(400, [
            'success' => false,
            'message' => 'El proveedor seleccionado no es válido.'
        ]);
    }

    return $id;
}


/**
 * Comprueba que el proveedor exista (si se indicó uno).
 */
function verificarProveedor(PDO $pdo, ?int $idProveedor): void
{
    if ($idProveedor === null) {
        return;
    }

    $stmt = $pdo->prepare('SELECT id_proveedor FROM proveedores WHERE id_proveedor = :id LIMIT 1');
    $stmt->execute([':id' => $idProveedor]);

    if (!$stmt->fetch()) {
        responderJSON(400, [
            'success' => false,
            'message' => 'El proveedor seleccionado no existe.'
        ]);
    }
}


/**
 * Procesa la imagen enviada mediante multipart/form-data.
 *
 * Devuelve:
 * - nombre del archivo generado
 * - null si no se envió imagen
 */
function procesarImagen(array $archivo): ?string
{
    if (!isset($archivo['error'])) {
        return null;
    }

    if ($archivo['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($archivo['error'] !== UPLOAD_ERR_OK) {
        responderJSON(400, [
            'success' => false,
            'message' => 'Ocurrió un error al subir la imagen.'
        ]);
    }

    /*
     * Límite de 5 MB.
     */
    if ($archivo['size'] > 5 * 1024 * 1024) {
        responderJSON(400, [
            'success' => false,
            'message' => 'La imagen no puede superar los 5 MB.'
        ]);
    }

    /*
     * Validación real del MIME mediante Fileinfo.
     * No confiamos únicamente en la extensión enviada.
     */
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($archivo['tmp_name']);

    $tiposPermitidos = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    ];

    if (!isset($tiposPermitidos[$mime])) {
        responderJSON(400, [
            'success' => false,
            'message' => 'Formato de imagen no permitido. Use JPG, PNG o WEBP.'
        ]);
    }

    $directorioUploads = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads';

    if (!is_dir($directorioUploads)) {
        if (!mkdir($directorioUploads, 0755, true)) {
            responderJSON(500, [
                'success' => false,
                'message' => 'No fue posible preparar el directorio de imágenes.'
            ]);
        }
    }

    /*
     * Nombre generado por el servidor.
     * Nunca utilizamos directamente el nombre proporcionado
     * por el navegador.
     */
    $nombreArchivo =
        bin2hex(random_bytes(16))
        . '.'
        . $tiposPermitidos[$mime];

    $rutaDestino = $directorioUploads . DIRECTORY_SEPARATOR . $nombreArchivo;

    if (!move_uploaded_file($archivo['tmp_name'], $rutaDestino)) {
        responderJSON(500, [
            'success' => false,
            'message' => 'No fue posible guardar la imagen.'
        ]);
    }

    return $nombreArchivo;
}


/**
 * Elimina una imagen física del directorio uploads.
 *
 * No genera error si el archivo ya no existe.
 */
function eliminarImagen(?string $nombreArchivo): void
{
    if ($nombreArchivo === null || $nombreArchivo === '') {
        return;
    }

    /*
     * Evitamos aceptar rutas arbitrarias.
     */
    if (
        str_contains($nombreArchivo, '..') ||
        str_contains($nombreArchivo, '/') ||
        str_contains($nombreArchivo, '\\')
    ) {
        return;
    }

    $ruta = dirname(__DIR__, 2)
        . DIRECTORY_SEPARATOR
        . 'uploads'
        . DIRECTORY_SEPARATOR
        . $nombreArchivo;

    if (is_file($ruta)) {
        @unlink($ruta);
    }
}


/* =========================================================
 * CONTROL DE SESIÓN Y AUTORIZACIÓN
 * ========================================================= */

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();

/*
 * Regla crítica del Documento Maestro:
 * toda operación administrativa requiere sesión
 * y rol admin.
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
 * PROCESAMIENTO
 * ========================================================= */

$imagenNueva = null;

try {

    $pdo = Conexion::obtenerConexion();
    $metodo = $_SERVER['REQUEST_METHOD'];


    /* =====================================================
     * GET
     * ===================================================== */

    if ($metodo === 'GET') {

        /*
         * GET ?id=1
         */
        if (isset($_GET['id'])) {

            $idProducto = obtenerIdProducto();

            $sql = "
                SELECT
                    p.id_producto,
                    p.id_categoria,
                    c.nombre_categoria,
                    p.id_proveedor,
                    pr.nombre_empresa AS nombre_proveedor,
                    p.nombre,
                    p.descripcion,
                    p.precio,
                    p.stock,
                    p.imagen,
                    p.estado
                FROM productos p
                LEFT JOIN categorias c
                    ON p.id_categoria = c.id_categoria
                LEFT JOIN proveedores pr
                    ON p.id_proveedor = pr.id_proveedor
                WHERE p.id_producto = :id_producto
                LIMIT 1
            ";

            $stmt = $pdo->prepare($sql);

            $stmt->execute([
                ':id_producto' => $idProducto
            ]);

            $producto = $stmt->fetch();

            if (!$producto) {
                responderJSON(400, [
                    'success' => false,
                    'message' => 'El producto solicitado no existe.'
                ]);
            }

            responderJSON(200, [
                'success' => true,
                'message' => 'Producto obtenido correctamente.',
                'producto' => [
                    'id_producto' => (int) $producto['id_producto'],
                    'id_categoria' => $producto['id_categoria'] !== null
                        ? (int) $producto['id_categoria']
                        : null,
                    'nombre_categoria' => $producto['nombre_categoria'],
                    'id_proveedor' => $producto['id_proveedor'] !== null ? (int) $producto['id_proveedor'] : null,
                    'nombre_proveedor' => $producto['nombre_proveedor'],
                    'nombre' => $producto['nombre'],
                    'descripcion' => $producto['descripcion'],
                    'precio' => $producto['precio'],
                    'stock' => (int) $producto['stock'],
                    'imagen' => $producto['imagen'],
                    'estado' => (int) $producto['estado']
                ]
            ]);
        }


        /*
         * GET sin ID:
         * devuelve todos los productos, incluidos
         * los desactivados, porque el administrador
         * debe poder gestionarlos.
         */
        $sql = "
            SELECT
                p.id_producto,
                p.id_categoria,
                c.nombre_categoria,
                p.id_proveedor,
                pr.nombre_empresa AS nombre_proveedor,
                p.nombre,
                p.descripcion,
                p.precio,
                p.stock,
                p.imagen,
                p.estado
            FROM productos p
            LEFT JOIN categorias c
                ON p.id_categoria = c.id_categoria
            LEFT JOIN proveedores pr
                ON p.id_proveedor = pr.id_proveedor
            ORDER BY p.id_producto DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();

        $productos = $stmt->fetchAll();

        $resultado = [];

        foreach ($productos as $producto) {
            $resultado[] = [
                'id_producto' => (int) $producto['id_producto'],
                'id_categoria' => $producto['id_categoria'] !== null
                    ? (int) $producto['id_categoria']
                    : null,
                'nombre_categoria' => $producto['nombre_categoria'],
                'id_proveedor' => $producto['id_proveedor'] !== null ? (int) $producto['id_proveedor'] : null,
                'nombre_proveedor' => $producto['nombre_proveedor'],
                'nombre' => $producto['nombre'],
                'descripcion' => $producto['descripcion'],
                'precio' => $producto['precio'],
                'stock' => (int) $producto['stock'],
                'imagen' => $producto['imagen'],
                'estado' => (int) $producto['estado']
            ];
        }

        responderJSON(200, [
            'success' => true,
            'message' => 'Productos obtenidos correctamente.',
            'total' => count($resultado),
            'productos' => $resultado
        ]);
    }


    /* =====================================================
     * POST - CREAR PRODUCTO
     * ===================================================== */

    /* =====================================================
     * POST - CREAR O ACTUALIZAR PRODUCTO (Enrutamiento Híbrido)
     * ===================================================== */
    if ($metodo === 'POST') {
        /*
         * Interceptamos el ID. Si existe, es una actualización (PUT simulado).
         * Si no existe o es nulo, es una creación (INSERT).
         */
        $idProducto = filter_input(INPUT_POST, 'id_producto', FILTER_VALIDATE_INT);

        $nombre = validarNombreProducto($_POST['nombre'] ?? null);
        $descripcion = validarDescripcion($_POST['descripcion'] ?? null);
        $precio = validarPrecio($_POST['precio'] ?? null);
        $stock = validarStock($_POST['stock'] ?? null);
        $idCategoria = validarIdCategoria($_POST['id_categoria'] ?? null);
        $estado = validarEstado($_POST['estado'] ?? 1);
        $idProveedor = validarIdProveedor($_POST['id_proveedor'] ?? null);

        verificarCategoria($pdo, $idCategoria);
        verificarProveedor($pdo, $idProveedor);

        /*
         * Procesar imagen solamente después de validar los demás datos.
         */
        if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] !== UPLOAD_ERR_NO_FILE) {
            $imagenNueva = procesarImagen($_FILES['imagen']);
        }

        if ($idProducto) {
            /* -------------------------------------------------
             * LÓGICA DE ACTUALIZACIÓN (UPDATE)
             * ------------------------------------------------- */
            $stmtExistencia = $pdo->prepare("SELECT imagen FROM productos WHERE id_producto = :id");
            $stmtExistencia->execute([':id' => $idProducto]);
            $productoActual = $stmtExistencia->fetch();

            if (!$productoActual) {
                if (isset($imagenNueva)) eliminarImagen($imagenNueva);
                responderJSON(400, [
                    'success' => false,
                    'message' => 'El producto que intenta modificar no existe.'
                ]);
            }

            // Construir consulta dinámica si hay imagen nueva
            $sqlImagen = isset($imagenNueva) ? "imagen = :imagen," : "";
            $sql = "
                UPDATE productos 
                SET 
                    id_categoria = :id_categoria,
                    id_proveedor = :id_proveedor,
                    nombre = :nombre,
                    descripcion = :descripcion,
                    precio = :precio,
                    stock = :stock,
                    {$sqlImagen}
                    estado = :estado
                WHERE id_producto = :id_producto
            ";

            $stmt = $pdo->prepare($sql);

            $stmt->bindValue(':id_categoria', $idCategoria, $idCategoria === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':id_proveedor', $idProveedor, $idProveedor === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
            $stmt->bindValue(':descripcion', $descripcion, $descripcion === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':precio', $precio, PDO::PARAM_STR);
            $stmt->bindValue(':stock', $stock, PDO::PARAM_INT);
            if (isset($imagenNueva)) {
                $stmt->bindValue(':imagen', $imagenNueva, PDO::PARAM_STR);
            }
            $stmt->bindValue(':estado', $estado, PDO::PARAM_INT);
            $stmt->bindValue(':id_producto', $idProducto, PDO::PARAM_INT);

            $stmt->execute();

            // Limpieza del disco: borrar la imagen anterior si fue reemplazada
            if (isset($imagenNueva) && $productoActual['imagen']) {
                eliminarImagen($productoActual['imagen']);
            }

            responderJSON(200, [
                'success' => true,
                'message' => 'Producto actualizado correctamente.',
                'producto' => [
                    'id_producto' => $idProducto,
                    'id_categoria' => $idCategoria,
                    'id_proveedor' => $idProveedor,
                    'nombre' => $nombre,
                    'descripcion' => $descripcion,
                    'precio' => $precio,
                    'stock' => $stock,
                    'imagen' => isset($imagenNueva) ? $imagenNueva : $productoActual['imagen'],
                    'estado' => $estado
                ]
            ]);

        } else {
            /* -------------------------------------------------
             * LÓGICA DE CREACIÓN (INSERT)
             * ------------------------------------------------- */
            $sql = "
                INSERT INTO productos (
                    id_categoria,
                    id_proveedor,
                    nombre,
                    descripcion,
                    precio,
                    stock,
                    imagen,
                    estado
                )
                VALUES (
                    :id_categoria,
                    :id_proveedor,
                    :nombre,
                    :descripcion,
                    :precio,
                    :stock,
                    :imagen,
                    :estado
                )
            ";

            $stmt = $pdo->prepare($sql);

            $stmt->bindValue(':id_categoria', $idCategoria, $idCategoria === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':id_proveedor', $idProveedor, $idProveedor === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
            $stmt->bindValue(':descripcion', $descripcion, $descripcion === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':precio', $precio, PDO::PARAM_STR);
            $stmt->bindValue(':stock', $stock, PDO::PARAM_INT);
            $stmt->bindValue(':imagen', $imagenNueva ?? null, !isset($imagenNueva) ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':estado', $estado, PDO::PARAM_INT);

            $stmt->execute();

            $idNuevo = (int) $pdo->lastInsertId();

            responderJSON(200, [
                'success' => true,
                'message' => 'Producto creado correctamente.',
                'producto' => [
                    'id_producto' => $idNuevo,
                    'id_categoria' => $idCategoria,
                    'id_proveedor' => $idProveedor,
                    'nombre' => $nombre,
                    'descripcion' => $descripcion,
                    'precio' => $precio,
                    'stock' => $stock,
                    'imagen' => $imagenNueva ?? null,
                    'estado' => $estado
                ]
            ]);
        }
    }


    /* =====================================================
     * PUT - MODIFICAR PRODUCTO
     * ===================================================== */

    if ($metodo === 'PUT') {

        $idProducto = obtenerIdProducto();

        /*
         * Para PUT utilizamos JSON.
         *
         * La sustitución física de imágenes se manejará
         * posteriormente desde el frontend con el flujo
         * de subida correspondiente.
         */
        $datos = obtenerDatosJSON();


        /*
         * Verificar existencia.
         */
        $sqlProducto = "
            SELECT
                id_producto,
                id_categoria,
                nombre,
                descripcion,
                precio,
                stock,
                imagen,
                estado
            FROM productos
            WHERE id_producto = :id_producto
            LIMIT 1
        ";

        $stmtProducto = $pdo->prepare($sqlProducto);

        $stmtProducto->execute([
            ':id_producto' => $idProducto
        ]);

        $productoActual = $stmtProducto->fetch();

        if (!$productoActual) {
            responderJSON(400, [
                'success' => false,
                'message' => 'El producto que intenta modificar no existe.'
            ]);
        }


        /*
         * PUT permite enviar únicamente los campos
         * que se desean modificar.
         */
        $nombre = array_key_exists('nombre', $datos)
            ? validarNombreProducto($datos['nombre'])
            : $productoActual['nombre'];

        $descripcion = array_key_exists('descripcion', $datos)
            ? validarDescripcion($datos['descripcion'])
            : $productoActual['descripcion'];

        $precio = array_key_exists('precio', $datos)
            ? validarPrecio($datos['precio'])
            : $productoActual['precio'];

        $stock = array_key_exists('stock', $datos)
            ? validarStock($datos['stock'])
            : (int) $productoActual['stock'];

        $idCategoria = array_key_exists('id_categoria', $datos)
            ? validarIdCategoria($datos['id_categoria'])
            : (
                $productoActual['id_categoria'] !== null
                    ? (int) $productoActual['id_categoria']
                    : null
            );

        $estado = array_key_exists('estado', $datos)
            ? validarEstado($datos['estado'])
            : (int) $productoActual['estado'];

        verificarCategoria($pdo, $idCategoria);


        /*
         * La imagen puede conservarse o sustituirse por
         * un nombre de archivo existente dentro de uploads.
         */
        $imagen = $productoActual['imagen'];

        if (array_key_exists('imagen', $datos)) {

            if ($datos['imagen'] === null || $datos['imagen'] === '') {
                $imagen = null;
            } elseif (is_string($datos['imagen'])) {

                $nombreImagen = trim($datos['imagen']);

                /*
                 * Evitar rutas arbitrarias.
                 */
                if (
                    str_contains($nombreImagen, '..') ||
                    str_contains($nombreImagen, '/') ||
                    str_contains($nombreImagen, '\\')
                ) {
                    responderJSON(400, [
                        'success' => false,
                        'message' => 'El nombre de imagen proporcionado no es válido.'
                    ]);
                }

                $rutaImagen = dirname(__DIR__, 2)
                    . DIRECTORY_SEPARATOR
                    . 'uploads'
                    . DIRECTORY_SEPARATOR
                    . $nombreImagen;

                if (!is_file($rutaImagen)) {
                    responderJSON(400, [
                        'success' => false,
                        'message' => 'La imagen indicada no existe en el directorio de uploads.'
                    ]);
                }

                $imagen = $nombreImagen;

            } else {
                responderJSON(400, [
                    'success' => false,
                    'message' => 'El valor de imagen no es válido.'
                ]);
            }
        }


        $sql = "
            UPDATE productos
            SET
                id_categoria = :id_categoria,
                nombre = :nombre,
                descripcion = :descripcion,
                precio = :precio,
                stock = :stock,
                imagen = :imagen,
                estado = :estado
            WHERE id_producto = :id_producto
        ";

        $stmt = $pdo->prepare($sql);

        $stmt->bindValue(
            ':id_categoria',
            $idCategoria,
            $idCategoria === null ? PDO::PARAM_NULL : PDO::PARAM_INT
        );

        $stmt->bindValue(
            ':nombre',
            $nombre,
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ':descripcion',
            $descripcion,
            $descripcion === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );

        $stmt->bindValue(
            ':precio',
            $precio,
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ':stock',
            $stock,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ':imagen',
            $imagen,
            $imagen === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );

        $stmt->bindValue(
            ':estado',
            $estado,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ':id_producto',
            $idProducto,
            PDO::PARAM_INT
        );

        $stmt->execute();


        responderJSON(200, [
            'success' => true,
            'message' => 'Producto actualizado correctamente.',
            'producto' => [
                'id_producto' => $idProducto,
                'id_categoria' => $idCategoria,
                'nombre' => $nombre,
                'descripcion' => $descripcion,
                'precio' => $precio,
                'stock' => $stock,
                'imagen' => $imagen,
                'estado' => $estado
            ]
        ]);
    }


    /* =====================================================
     * DELETE - ELIMINAR PRODUCTO
     * ===================================================== */

    if ($metodo === 'DELETE') {

        $idProducto = obtenerIdProducto();


        /*
         * Primero obtenemos el producto y su imagen.
         */
        $sqlProducto = "
            SELECT
                id_producto,
                imagen
            FROM productos
            WHERE id_producto = :id_producto
            LIMIT 1
        ";

        $stmtProducto = $pdo->prepare($sqlProducto);

        $stmtProducto->execute([
            ':id_producto' => $idProducto
        ]);

        $producto = $stmtProducto->fetch();

        if (!$producto) {
            responderJSON(400, [
                'success' => false,
                'message' => 'El producto que intenta eliminar no existe.'
            ]);
        }

        /*
         * El DELETE físico se mantiene porque el CRUD del
         * administrador lo exige.
         *
         * Sin embargo, detalles_pedido.id_producto utiliza
         * ON DELETE RESTRICT. Por ello, si el producto forma
         * parte de un pedido histórico, MySQL impedirá borrarlo.
         */
        $sql = "
            DELETE FROM productos
            WHERE id_producto = :id_producto
        ";

        $stmt = $pdo->prepare($sql);

        try {

            $stmt->execute([
                ':id_producto' => $idProducto
            ]);

        } catch (PDOException $e) {

            /*
             * 23000 = Integrity Constraint Violation.
             * En este caso puede deberse al ON DELETE RESTRICT
             * de detalles_pedido.
             */
            if ($e->getCode() === '23000') {

                responderJSON(400, [
                    'success' => false,
                    'message' => 'No se puede eliminar el producto porque está asociado a uno o más pedidos históricos.'
                ]);
            }

            throw $e;
        }


        /*
         * La imagen solamente se elimina del disco después
         * de que el DELETE en la base de datos tuvo éxito.
         */
        eliminarImagen($producto['imagen']);


        responderJSON(200, [
            'success' => true,
            'message' => 'Producto eliminado correctamente.',
            'id_producto' => $idProducto
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
     * Si hubo una imagen recién creada y luego falló
     * la operación SQL, intentamos eliminarla para evitar
     * archivos huérfanos.
     */
    if ($imagenNueva !== null) {
        eliminarImagen($imagenNueva);
    }

    error_log(
        'Error PDO en productos_controller.php: '
        . $e->getMessage()
    );

    responderJSON(500, [
        'success' => false,
        'message' => 'Error interno del servidor.'
    ]);


} catch (Throwable $e) {

    if ($imagenNueva !== null) {
        eliminarImagen($imagenNueva);
    }

    error_log(
        'Error inesperado en productos_controller.php: '
        . $e->getMessage()
    );

    responderJSON(500, [
        'success' => false,
        'message' => 'Error interno del servidor.'
    ]);
}