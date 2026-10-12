<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

// 1. REGLA CRÍTICA: Control de acceso y privilegios
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
    http_response_code(403);
    echo json_encode([
        'success' => false, 
        'message' => 'Acceso denegado. Se requieren privilegios de administrador.'
    ]);
    exit;
}

require_once '../config/conexion.php';
$conexion = Conexion::getInstancia()->getConexion();
$metodo = $_SERVER['REQUEST_METHOD'];

try {
    switch ($metodo) {
        case 'GET':
            if (isset($_GET['id'])) {
                // Consultar un pedido específico y su historial congelado
                $id_pedido = filter_var($_GET['id'], FILTER_VALIDATE_INT);
                if (!$id_pedido) throw new Exception("ID de pedido inválido.");

                $stmt = $conexion->prepare("
                    SELECT p.id_pedido, p.total, p.estado_pedido, p.fecha_pedido, u.nombre, u.email 
                    FROM pedidos p 
                    JOIN usuarios u ON p.id_usuario = u.id_usuario 
                    WHERE p.id_pedido = :id
                ");
                $stmt->bindParam(':id', $id_pedido, PDO::PARAM_INT);
                $stmt->execute();
                $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$pedido) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Pedido no encontrado.']);
                    exit;
                }

                // Obtener detalles del pedido (precio_compra estático)
                $stmtDetalles = $conexion->prepare("
                    SELECT d.id_detalle, d.cantidad, d.precio_compra, pr.nombre as nombre_producto 
                    FROM detalles_pedido d 
                    JOIN productos pr ON d.id_producto = pr.id_producto 
                    WHERE d.id_pedido = :id
                ");
                $stmtDetalles->bindParam(':id', $id_pedido, PDO::PARAM_INT);
                $stmtDetalles->execute();
                $pedido['detalles'] = $stmtDetalles->fetchAll(PDO::FETCH_ASSOC);

                http_response_code(200);
                echo json_encode(['success' => true, 'pedido' => $pedido]);
            } else {
                // Consultar el listado general de pedidos
                $stmt = $conexion->prepare("
                    SELECT p.id_pedido, p.total, p.estado_pedido, p.fecha_pedido, u.nombre 
                    FROM pedidos p 
                    JOIN usuarios u ON p.id_usuario = u.id_usuario 
                    ORDER BY p.fecha_pedido DESC
                ");
                $stmt->execute();
                $pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                http_response_code(200);
                echo json_encode(['success' => true, 'pedidos' => $pedidos]);
            }
            break;

        case 'PUT':
            // Actualizar exclusivamente el estado_pedido
            $input = json_decode(file_get_contents("php://input"), true);
            
            if (!isset($_GET['id']) || !isset($input['estado_pedido'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Faltan datos requeridos (ID o estado_pedido).']);
                exit;
            }

            $id_pedido = filter_var($_GET['id'], FILTER_VALIDATE_INT);
            $estado = $input['estado_pedido'];
            $estados_validos = ['pendiente', 'pagado', 'enviado']; // Regla del esquema SQL

            if (!$id_pedido || !in_array($estado, $estados_validos)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false, 
                    'message' => 'Datos inválidos. El estado debe ser pendiente, pagado o enviado.'
                ]);
                exit;
            }

            $stmt = $conexion->prepare("UPDATE pedidos SET estado_pedido = :estado WHERE id_pedido = :id");
            $stmt->bindParam(':estado', $estado, PDO::PARAM_STR);
            $stmt->bindParam(':id', $id_pedido, PDO::PARAM_INT);
            
            if ($stmt->execute()) {
                if ($stmt->rowCount() > 0) {
                    http_response_code(200);
                    echo json_encode(['success' => true, 'message' => 'Estado del pedido actualizado correctamente.']);
                } else {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'No se encontró el pedido o el estado ya era el mismo.']);
                }
            } else {
                throw new Exception("Error al actualizar el estado del pedido.");
            }
            break;

        default:
            http_response_code(405); // Método no permitido
            echo json_encode(['success' => false, 'message' => 'Método HTTP no permitido.']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error del servidor: ' . $e->getMessage()]);
}
?>