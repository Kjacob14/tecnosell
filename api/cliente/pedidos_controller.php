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

/**
 * Datos de la dirección de entrega de un pedido, o null si el pedido no
 * tiene dirección (anterior a SC-02 o dirección ya eliminada).
 */
function formatearDireccionPedido(array $fila): ?array
{
    if ($fila['id_direccion'] === null || $fila['dir_alias'] === null) {
        return null;
    }

    return [
        'alias' => $fila['dir_alias'],
        'calle' => $fila['dir_calle'],
        'num_exterior' => $fila['dir_num_exterior'],
        'colonia' => $fila['dir_colonia'],
        'ciudad' => $fila['dir_ciudad'],
        'estado' => $fila['dir_estado'],
        'codigo_postal' => $fila['dir_codigo_postal']
    ];
}

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
            SELECT p.id_pedido, p.total, p.subtotal, p.iva, p.estado_pedido, p.fecha_pedido, p.id_direccion,
                   de.alias AS dir_alias, de.calle AS dir_calle, de.num_exterior AS dir_num_exterior,
                   de.colonia AS dir_colonia, de.ciudad AS dir_ciudad, de.estado AS dir_estado,
                   de.codigo_postal AS dir_codigo_postal
            FROM pedidos p
            LEFT JOIN direcciones_entrega de ON p.id_direccion = de.id_direccion
            WHERE p.id_pedido = :id_pedido AND p.id_usuario = :id_usuario
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
                'subtotal' => $pedido['subtotal'] !== null ? number_format((float) $pedido['subtotal'], 2, '.', '') : null,
                'iva' => $pedido['iva'] !== null ? number_format((float) $pedido['iva'], 2, '.', '') : null,
                'total' => number_format((float) $pedido['total'], 2, '.', ''),
                'estado_pedido' => $pedido['estado_pedido'],
                'fecha_pedido' => $pedido['fecha_pedido'],
                'direccion' => formatearDireccionPedido($pedido),
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
            p.subtotal,
            p.iva,
            p.estado_pedido,
            p.fecha_pedido,
            p.id_direccion,
            de.alias AS dir_alias,
            de.calle AS dir_calle,
            de.num_exterior AS dir_num_exterior,
            de.colonia AS dir_colonia,
            de.ciudad AS dir_ciudad,
            de.estado AS dir_estado,
            de.codigo_postal AS dir_codigo_postal,
            (
                SELECT COALESCE(SUM(d.cantidad), 0)
                FROM detalles_pedido d
                WHERE d.id_pedido = p.id_pedido
            ) AS total_articulos
        FROM pedidos p
        LEFT JOIN direcciones_entrega de ON p.id_direccion = de.id_direccion
        WHERE p.id_usuario = :id_usuario
        ORDER BY p.fecha_pedido DESC, p.id_pedido DESC
    ");
    $stmt->execute([':id_usuario' => $idUsuario]);

    $pedidos = [];

    foreach ($stmt->fetchAll() as $fila) {
        $pedidos[] = [
            'id_pedido' => (int) $fila['id_pedido'],
            'subtotal' => $fila['subtotal'] !== null ? number_format((float) $fila['subtotal'], 2, '.', '') : null,
            'iva' => $fila['iva'] !== null ? number_format((float) $fila['iva'], 2, '.', '') : null,
            'total' => number_format((float) $fila['total'], 2, '.', ''),
            'estado_pedido' => $fila['estado_pedido'],
            'fecha_pedido' => $fila['fecha_pedido'],
            'direccion' => formatearDireccionPedido($fila),
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
