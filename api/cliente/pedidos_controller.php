<?php
/**
 * pedidos_controller.php
 *
 * Historial de pedidos del cliente que tiene la sesión iniciada.
 * Solo lectura (GET):
 *   - GET                  -> lista de sus pedidos, del más reciente al más antiguo.
 *   - GET ?id=<id_pedido>  -> detalle de un pedido suyo con sus productos.
 *
 * Un cliente nunca puede ver pedidos de otro usuario: ambas consultas
 * filtran por el id_usuario de la sesión.
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
        'message' => 'Debe iniciar sesión para consultar sus pedidos.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Método HTTP no permitido. Utilice GET.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$idUsuario = (int) $_SESSION['id_usuario'];

try {
    $pdo = Conexion::obtenerConexion();

    /* =====================================================
     * GET ?id= - DETALLE DE UN PEDIDO PROPIO
     * ===================================================== */
    if (isset($_GET['id'])) {
        $idPedido = filter_var($_GET['id'], FILTER_VALIDATE_INT);

        if ($idPedido === false || $idPedido <= 0) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'El id del pedido no es válido.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT id_pedido, total, estado_pedido, fecha_pedido
            FROM pedidos
            WHERE id_pedido = :id_pedido AND id_usuario = :id_usuario
        ");
        $stmt->execute([
            ':id_pedido' => $idPedido,
            ':id_usuario' => $idUsuario
        ]);
        $pedido = $stmt->fetch();

        // Mismo mensaje si el pedido no existe o es de otro usuario,
        // para no revelar qué ids existen.
        if (!$pedido) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Pedido no encontrado.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmtDetalles = $pdo->prepare("
            SELECT
                d.id_producto,
                d.cantidad,
                d.precio_compra,
                pr.nombre,
                pr.imagen
            FROM detalles_pedido d
            LEFT JOIN productos pr ON d.id_producto = pr.id_producto
            WHERE d.id_pedido = :id_pedido
            ORDER BY d.id_detalle ASC
        ");
        $stmtDetalles->execute([':id_pedido' => $idPedido]);

        $detalles = [];

        foreach ($stmtDetalles->fetchAll() as $fila) {
            $cantidad = (int) $fila['cantidad'];
            $precioCompra = (float) $fila['precio_compra'];

            $detalles[] = [
                'id_producto' => (int) $fila['id_producto'],
                'nombre' => $fila['nombre'] ?? 'Producto no disponible',
                'imagen' => $fila['imagen'],
                'cantidad' => $cantidad,
                // precio_compra es el precio congelado al momento de la compra.
                'precio_compra' => number_format($precioCompra, 2, '.', ''),
                'subtotal' => number_format($precioCompra * $cantidad, 2, '.', '')
            ];
        }

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Pedido obtenido correctamente.',
            'pedido' => [
                'id_pedido' => (int) $pedido['id_pedido'],
                'total' => number_format((float) $pedido['total'], 2, '.', ''),
                'estado_pedido' => $pedido['estado_pedido'],
                'fecha_pedido' => $pedido['fecha_pedido'],
                'detalles' => $detalles
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* =====================================================
     * GET - LISTA DE PEDIDOS PROPIOS
     * ===================================================== */
    $stmt = $pdo->prepare("
        SELECT
            p.id_pedido,
            p.total,
            p.estado_pedido,
            p.fecha_pedido,
            (
                SELECT COALESCE(SUM(d.cantidad), 0)
                FROM detalles_pedido d
                WHERE d.id_pedido = p.id_pedido
            ) AS total_articulos
        FROM pedidos p
        WHERE p.id_usuario = :id_usuario
        ORDER BY p.fecha_pedido DESC, p.id_pedido DESC
    ");
    $stmt->execute([':id_usuario' => $idUsuario]);

    $pedidos = [];

    foreach ($stmt->fetchAll() as $fila) {
        $pedidos[] = [
            'id_pedido' => (int) $fila['id_pedido'],
            'total' => number_format((float) $fila['total'], 2, '.', ''),
            'estado_pedido' => $fila['estado_pedido'],
            'fecha_pedido' => $fila['fecha_pedido'],
            'total_articulos' => (int) $fila['total_articulos']
        ];
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Pedidos obtenidos correctamente.',
        'total' => count($pedidos),
        'pedidos' => $pedidos
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log('Error PDO en pedidos_controller.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor.'
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Error inesperado en pedidos_controller.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor.'
    ], JSON_UNESCAPED_UNICODE);
}
?>
