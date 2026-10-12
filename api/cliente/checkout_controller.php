<?php
/**
 * checkout_controller.php
 *
 * Procesa la compra del usuario mediante transacciones seguras.
 * Implementa bloqueos de fila (FOR UPDATE) para prevenir ventas sin inventario real.
 */

require_once __DIR__ . '/../config/conexion.php';

header('Content-Type: application/json; charset=utf-8');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método HTTP no permitido. Utilice POST.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['id_usuario'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesión expirada o inválida. Inicie sesión nuevamente.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$idUsuario = (int) $_SESSION['id_usuario'];

try {
    $pdo = Conexion::obtenerConexion();
    
    // Iniciar transacción SQL para garantizar integridad (BEGIN)
    $pdo->beginTransaction();

    // 1. Obtener los artículos del carrito aplicando un bloqueo exclusivo de fila (FOR UPDATE).
    // El ORDER BY previene interbloqueos (deadlocks) en concurrencia masiva.
    $sqlBloqueo = "
        SELECT p.id_producto, p.nombre, p.precio, p.stock, p.estado, c.cantidad
        FROM carrito c
        JOIN productos p ON c.id_producto = p.id_producto
        WHERE c.id_usuario = :id_usuario
        ORDER BY p.id_producto ASC
        FOR UPDATE
    ";
    
    $stmt = $pdo->prepare($sqlBloqueo);
    $stmt->execute([':id_usuario' => $idUsuario]);
    $itemsCarrito = $stmt->fetchAll();

    if (empty($itemsCarrito)) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'message' => 'El carrito está vacío. Agregue productos antes de procesar el pago.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $productosAgotados = [];
    $totalPedido = 0;

    // 2. Evaluar el stock real y estado activo dentro de la transacción
    foreach ($itemsCarrito as $item) {
        $cantidadSolicitada = (int) $item['cantidad'];
        $stockReal = (int) $item['stock'];
        $estado = (int) $item['estado'];

        if ($estado === 0 || $stockReal < $cantidadSolicitada) {
            $productosAgotados[] = [
                'id_producto' => (int) $item['id_producto'],
                'nombre' => $item['nombre'],
                'cantidad_solicitada' => $cantidadSolicitada,
                'stock_disponible' => ($estado === 1) ? $stockReal : 0
            ];
        } else {
            // El precio se calcula con el valor vigente congelado en este milisegundo
            $totalPedido += ((float) $item['precio'] * $cantidadSolicitada);
        }
    }

    // 3. Abortar transacción si hay conflictos de inventario
    if (!empty($productosAgotados)) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Algunos productos ya no tienen existencias suficientes.',
            'productos_agotados' => $productosAgotados
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 4. Registrar la orden matriz (Pedidos)
    $sqlPedido = "INSERT INTO pedidos (id_usuario, total, estado_pedido) VALUES (:id_usuario, :total, 'pendiente')";
    $stmtPedido = $pdo->prepare($sqlPedido);
    $stmtPedido->execute([
        ':id_usuario' => $idUsuario,
        ':total' => $totalPedido
    ]);
    
    $idPedido = (int) $pdo->lastInsertId();

    // 5. Registrar los detalles (congelando el precio_compra) y descontar el inventario
    $sqlDetalle = "INSERT INTO detalles_pedido (id_pedido, id_producto, cantidad, precio_compra) VALUES (:id_pedido, :id_producto, :cantidad, :precio_compra)";
    $stmtDetalle = $pdo->prepare($sqlDetalle);

    $sqlDescuento = "UPDATE productos SET stock = stock - :cantidad WHERE id_producto = :id_producto";
    $stmtDescuento = $pdo->prepare($sqlDescuento);

    foreach ($itemsCarrito as $item) {
        $stmtDetalle->execute([
            ':id_pedido' => $idPedido,
            ':id_producto' => $item['id_producto'],
            ':cantidad' => $item['cantidad'],
            ':precio_compra' => $item['precio']
        ]);

        $stmtDescuento->execute([
            ':cantidad' => $item['cantidad'],
            ':id_producto' => $item['id_producto']
        ]);
    }

    // 6. Vaciar el carrito del usuario tras el pago exitoso
    $sqlVaciar = "DELETE FROM carrito WHERE id_usuario = :id_usuario";
    $stmtVaciar = $pdo->prepare($sqlVaciar);
    $stmtVaciar->execute([':id_usuario' => $idUsuario]);

    // Confirmar transacción (COMMIT)
    $pdo->commit();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Compra realizada correctamente.',
        'pedido' => [
            'id_pedido' => $idPedido,
            'total' => number_format($totalPedido, 2, '.', ''),
            'estado_pedido' => 'pendiente'
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    // Verificación estricta de la instancia antes de invocar inTransaction()
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Error PDO en checkout_controller.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error interno del servidor.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Error en checkout_controller.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error interno del servidor.'], JSON_UNESCAPED_UNICODE);
}
?>