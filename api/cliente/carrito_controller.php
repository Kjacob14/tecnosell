<?php
/**
 * carrito_controller.php
 *
 * Gestión del carrito de compras vinculado a la sesión del usuario.
 * Persistencia directa en base de datos. Precios calculados en vivo.
 */

require_once __DIR__ . '/../config/conexion.php';

header('Content-Type: application/json; charset=utf-8');

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

// 1. REGLA CRÍTICA: Autenticación obligatoria
if (!isset($_SESSION['id_usuario'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Debe iniciar sesión para usar el carrito.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$idUsuario = (int) $_SESSION['id_usuario'];
$metodo = $_SERVER['REQUEST_METHOD'];

function obtenerDatosJSON() {
    $contenido = file_get_contents('php://input');
    $datos = json_decode($contenido, true);
    if (!is_array($datos)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'JSON inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $datos;
}

try {
    $pdo = Conexion::obtenerConexion();

    /* =====================================================
     * GET - OBTENER CARRITO Y CALCULAR SUBTOTAL EN VIVO
     * ===================================================== */
    if ($metodo === 'GET') {
        $sql = "
            SELECT 
                c.id_carrito, 
                c.id_producto, 
                c.cantidad, 
                p.nombre, 
                p.precio, 
                p.stock AS stock_disponible, 
                p.imagen,
                p.estado
            FROM carrito c
            JOIN productos p ON c.id_producto = p.id_producto
            WHERE c.id_usuario = :id_usuario
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id_usuario' => $idUsuario]);
        $items = $stmt->fetchAll();

        $subtotal = 0;
        $totalArticulos = 0;
        $carrito = [];

        foreach ($items as $item) {
            $cantidad = (int) $item['cantidad'];
            $precio = (float) $item['precio'];
            
            $subtotal += ($precio * $cantidad);
            $totalArticulos += $cantidad;

            $carrito[] = [
                'id_carrito' => (int) $item['id_carrito'],
                'id_producto' => (int) $item['id_producto'],
                'nombre' => $item['nombre'],
                'precio' => number_format($precio, 2, '.', ''),
                'cantidad' => $cantidad,
                'stock_disponible' => (int) $item['stock_disponible'],
                'imagen' => $item['imagen'],
                'activo' => (bool) $item['estado'] // Bandera para que el JS sepa si el admin lo desactivó
            ];
        }

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'total_articulos' => $totalArticulos,
            'subtotal' => number_format($subtotal, 2, '.', ''),
            'carrito' => $carrito
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* =====================================================
     * POST - AGREGAR O INCREMENTAR CANTIDAD
     * ===================================================== */
    if ($metodo === 'POST') {
        $datos = obtenerDatosJSON();
        $idProducto = filter_var($datos['id_producto'] ?? 0, FILTER_VALIDATE_INT);
        $cantidadNueva = filter_var($datos['cantidad'] ?? 0, FILTER_VALIDATE_INT);

        if (!$idProducto || $cantidadNueva <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Producto o cantidad inválida.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Validar que el producto exista, esté activo y tenga stock
        $stmtProd = $pdo->prepare("SELECT stock, estado FROM productos WHERE id_producto = :id");
        $stmtProd->execute([':id' => $idProducto]);
        $producto = $stmtProd->fetch();

        if (!$producto || $producto['estado'] == 0 || $producto['stock'] <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'El producto no está disponible.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stockDisponible = (int) $producto['stock'];

        // Revisar carrito actual
        $stmtCart = $pdo->prepare("SELECT cantidad FROM carrito WHERE id_usuario = :id_u AND id_producto = :id_p");
        $stmtCart->execute([':id_u' => $idUsuario, ':id_p' => $idProducto]);
        $itemCarrito = $stmtCart->fetch();

        if ($itemCarrito) {
            $cantidadActual = (int) $itemCarrito['cantidad'];
            $cantidadProyectada = $cantidadActual + $cantidadNueva;

            if ($cantidadProyectada > $stockDisponible) {
                $restante = $stockDisponible - $cantidadActual;
                http_response_code(400);
                echo json_encode([
                    'success' => false, 
                    'message' => "Ya tiene {$cantidadActual} en el carrito; solo puede agregar {$restante} más."
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $stmtUpdate = $pdo->prepare("UPDATE carrito SET cantidad = :cant WHERE id_usuario = :id_u AND id_producto = :id_p");
            $stmtUpdate->execute([':cant' => $cantidadProyectada, ':id_u' => $idUsuario, ':id_p' => $idProducto]);
        } else {
            if ($cantidadNueva > $stockDisponible) {
                http_response_code(400);
                echo json_encode([
                    'success' => false, 
                    'message' => "Stock insuficiente. Solo quedan {$stockDisponible} unidades disponibles."
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $stmtInsert = $pdo->prepare("INSERT INTO carrito (id_usuario, id_producto, cantidad) VALUES (:id_u, :id_p, :cant)");
            $stmtInsert->execute([':id_u' => $idUsuario, ':id_p' => $idProducto, ':cant' => $cantidadNueva]);
        }

        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Producto agregado al carrito.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* =====================================================
     * PUT - FIJAR CANTIDAD ABSOLUTA
     * ===================================================== */
    if ($metodo === 'PUT') {
        $datos = obtenerDatosJSON();
        $idProducto = filter_var($datos['id_producto'] ?? 0, FILTER_VALIDATE_INT);
        $cantidadFija = filter_var($datos['cantidad'] ?? 0, FILTER_VALIDATE_INT);

        if (!$idProducto || $cantidadFija <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Producto o cantidad inválida.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmtProd = $pdo->prepare("SELECT stock, estado FROM productos WHERE id_producto = :id");
        $stmtProd->execute([':id' => $idProducto]);
        $producto = $stmtProd->fetch();

        if (!$producto || $producto['estado'] == 0 || $producto['stock'] <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'El producto no está disponible.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stockDisponible = (int) $producto['stock'];

        if ($cantidadFija > $stockDisponible) {
            http_response_code(400);
            echo json_encode([
                'success' => false, 
                'message' => "Stock insuficiente. Solo quedan {$stockDisponible} unidades disponibles."
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmtUpdate = $pdo->prepare("UPDATE carrito SET cantidad = :cant WHERE id_usuario = :id_u AND id_producto = :id_p");
        $stmtUpdate->execute([':cant' => $cantidadFija, ':id_u' => $idUsuario, ':id_p' => $idProducto]);

        if ($stmtUpdate->rowCount() === 0) {
            // Si retorna 0 es porque no existía o la cantidad ya era la misma. Hacemos un UPSERT para curarnos en salud.
            $stmtInsert = $pdo->prepare("INSERT IGNORE INTO carrito (id_usuario, id_producto, cantidad) VALUES (:id_u, :id_p, :cant)");
            $stmtInsert->execute([':id_u' => $idUsuario, ':id_p' => $idProducto, ':cant' => $cantidadFija]);
        }

        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Cantidad actualizada correctamente.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* =====================================================
     * DELETE - ELIMINAR DEL CARRITO
     * ===================================================== */
    if ($metodo === 'DELETE') {
        $idProducto = filter_input(INPUT_GET, 'id_producto', FILTER_VALIDATE_INT);

        if ($idProducto) {
            $stmt = $pdo->prepare("DELETE FROM carrito WHERE id_usuario = :id_u AND id_producto = :id_p");
            $stmt->execute([':id_u' => $idUsuario, ':id_p' => $idProducto]);
        }

        // Se retorna 200 siempre (Idempotencia)
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Producto eliminado del carrito.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método HTTP no permitido.'], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log('Error PDO en carrito_controller.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error interno del servidor.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Error en carrito_controller.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error interno del servidor.'], JSON_UNESCAPED_UNICODE);
}
?>