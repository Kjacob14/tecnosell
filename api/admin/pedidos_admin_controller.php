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

/**
 * Agrupa las columnas dir_* del pedido en un arreglo "direccion" (null si el
 * pedido no tiene dirección) y da formato a subtotal e iva (null si no hay desglose).
 */
function formatearPedidoAdmin(array $pedido): array
{
    $direccion = null;

    if ($pedido['id_direccion'] !== null && $pedido['dir_alias'] !== null) {
        $direccion = [
            'alias' => $pedido['dir_alias'],
            'destinatario' => $pedido['dir_destinatario'],
            'telefono' => $pedido['dir_telefono'],
            'calle' => $pedido['dir_calle'],
            'num_exterior' => $pedido['dir_num_exterior'],
            'num_interior' => $pedido['dir_num_interior'],
            'colonia' => $pedido['dir_colonia'],
            'ciudad' => $pedido['dir_ciudad'],
            'estado' => $pedido['dir_estado'],
            'codigo_postal' => $pedido['dir_codigo_postal'],
            'referencias' => $pedido['dir_referencias']
        ];
    }

    foreach (array_keys($pedido) as $columna) {
        if (str_starts_with($columna, 'dir_')) {
            unset($pedido[$columna]);
        }
    }

    unset($pedido['id_direccion']);

    $pedido['subtotal'] = $pedido['subtotal'] !== null ? number_format((float) $pedido['subtotal'], 2, '.', '') : null;
    $pedido['iva'] = $pedido['iva'] !== null ? number_format((float) $pedido['iva'], 2, '.', '') : null;
    $pedido['direccion'] = $direccion;

    return $pedido;
}

require_once __DIR__ . '/../config/conexion.php';
$conexion = Conexion::obtenerConexion();
$metodo = $_SERVER['REQUEST_METHOD'];

try {
    switch ($metodo) {
        case 'GET':
            if (isset($_GET['id'])) {
                // Consultar un pedido específico y su historial congelado
                $id_pedido = filter_var($_GET['id'], FILTER_VALIDATE_INT);
                if (!$id_pedido) throw new Exception("ID de pedido inválido.");

                $stmt = $conexion->prepare("
                    SELECT p.id_pedido, p.total, p.estado_pedido, p.fecha_pedido, p.subtotal, p.iva, p.id_direccion, de.alias AS dir_alias, de.destinatario AS dir_destinatario, de.telefono AS dir_telefono, de.calle AS dir_calle, de.num_exterior AS dir_num_exterior, de.num_interior AS dir_num_interior, de.colonia AS dir_colonia, de.ciudad AS dir_ciudad, de.estado AS dir_estado, de.codigo_postal AS dir_codigo_postal, de.referencias AS dir_referencias, u.nombre, u.email 
                    FROM pedidos p 
                    JOIN usuarios u ON p.id_usuario = u.id_usuario 
                    LEFT JOIN direcciones_entrega de ON p.id_direccion = de.id_direccion 
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
                $pedido = formatearPedidoAdmin($pedido);

                http_response_code(200);
                echo json_encode(['success' => true, 'pedido' => $pedido]);
            } else {
                // Consultar el listado general de pedidos
                $stmt = $conexion->prepare("
                    SELECT p.id_pedido, p.total, p.estado_pedido, p.fecha_pedido, p.subtotal, p.iva, p.id_direccion, de.alias AS dir_alias, de.destinatario AS dir_destinatario, de.telefono AS dir_telefono, de.calle AS dir_calle, de.num_exterior AS dir_num_exterior, de.num_interior AS dir_num_interior, de.colonia AS dir_colonia, de.ciudad AS dir_ciudad, de.estado AS dir_estado, de.codigo_postal AS dir_codigo_postal, de.referencias AS dir_referencias, u.nombre 
                    FROM pedidos p 
                    JOIN usuarios u ON p.id_usuario = u.id_usuario 
                    LEFT JOIN direcciones_entrega de ON p.id_direccion = de.id_direccion 
                    ORDER BY p.fecha_pedido DESC
                ");
                $stmt->execute();
                $pedidos = array_map('formatearPedidoAdmin', $stmt->fetchAll(PDO::FETCH_ASSOC));
                
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