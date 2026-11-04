<?php
/**
 * catalogo_controller.php
 *
 * Endpoint público de solo lectura para listar el catálogo de la tienda.
 * Solo expone productos con estado activo (1) y existencias mayores a 0.
 */

require_once __DIR__ . '/../config/conexion.php';

header('Content-Type: application/json; charset=utf-8');

// Este endpoint es público, no requiere inicializar sesión.

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Método HTTP no permitido. Utilice GET.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = Conexion::obtenerConexion();
    
    // Consulta base con JOIN para obtener el nombre de la categoría
    $sql = "
        SELECT 
            p.id_producto, 
            p.nombre, 
            p.descripcion, 
            p.precio, 
            p.stock, 
            p.imagen, 
            p.id_categoria, 
            c.nombre_categoria,
            pr.id_proveedor,
            pr.nombre_empresa AS nombre_proveedor
        FROM productos p
        LEFT JOIN categorias c ON p.id_categoria = c.id_categoria
        LEFT JOIN proveedores pr ON p.id_proveedor = pr.id_proveedor AND pr.estado = 1
        WHERE p.estado = 1 AND p.stock > 0
    ";

    $parametros = [];

    // Filtrado dinámico por id_categoria si se provee en la URL
    if (isset($_GET['id_categoria']) && $_GET['id_categoria'] !== '') {
        $idCategoria = filter_input(INPUT_GET, 'id_categoria', FILTER_VALIDATE_INT);
        
        if ($idCategoria === false || $idCategoria <= 0) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'El id de la categoría no es válido.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $sql .= " AND p.id_categoria = :id_categoria";
        $parametros[':id_categoria'] = $idCategoria;
    }

    // Filtrado por proveedor activo (?id_proveedor=). Se puede combinar con categoría y texto.
    if (isset($_GET['id_proveedor']) && $_GET['id_proveedor'] !== '') {
        $idProveedor = filter_input(INPUT_GET, 'id_proveedor', FILTER_VALIDATE_INT);

        if ($idProveedor === false || $idProveedor === null || $idProveedor <= 0) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'El id del proveedor no es válido.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $sql .= " AND pr.id_proveedor = :id_proveedor";
        $parametros[':id_proveedor'] = $idProveedor;
    }

    // Búsqueda por texto (?q=): nombre, descripción o categoría.
    // Se puede combinar con id_categoria.
    if (isset($_GET['q']) && is_string($_GET['q'])) {
        $termino = mb_substr(trim($_GET['q']), 0, 100);

        if ($termino !== '') {
            // Se escapan los comodines de LIKE (% y _) con "!" para que el
            // texto escrito por el usuario se busque de forma literal.
            $patron = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $termino) . '%';

            // Un nombre de parámetro distinto por cada LIKE: con
            // ATTR_EMULATE_PREPARES en false, PDO no permite repetirlos.
            $sql .= "
                AND (
                    p.nombre LIKE :q_nombre ESCAPE '!'
                    OR p.descripcion LIKE :q_descripcion ESCAPE '!'
                    OR c.nombre_categoria LIKE :q_categoria ESCAPE '!'
                )
            ";
            $parametros[':q_nombre'] = $patron;
            $parametros[':q_descripcion'] = $patron;
            $parametros[':q_categoria'] = $patron;
        }
    }

    $sql .= " ORDER BY p.id_producto DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($parametros);
    
    $productosBD = $stmt->fetchAll();
    $productosFormateados = [];

    foreach ($productosBD as $row) {
        $productosFormateados[] = [
            'id_producto' => (int) $row['id_producto'],
            'nombre' => $row['nombre'],
            'descripcion' => $row['descripcion'],
            // Se formatea a string con 2 decimales según contrato
            'precio' => number_format((float) $row['precio'], 2, '.', ''),
            'stock' => (int) $row['stock'],
            'imagen' => $row['imagen'],
            'id_categoria' => $row['id_categoria'] !== null ? (int) $row['id_categoria'] : null,
            'nombre_categoria' => $row['nombre_categoria'],
            'id_proveedor' => $row['id_proveedor'] !== null ? (int) $row['id_proveedor'] : null,
            'nombre_proveedor' => $row['nombre_proveedor']
        ];
    }

    if (count($productosFormateados) === 0 && (isset($_GET['id_categoria']) || isset($_GET['id_proveedor']))) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'No hay productos disponibles con ese filtro.',
            'total' => 0,
            'productos' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Catálogo obtenido correctamente.',
        'total' => count($productosFormateados),
        'productos' => $productosFormateados
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log('Error PDO en catalogo_controller.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor.'
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Error inesperado en catalogo_controller.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor.'
    ], JSON_UNESCAPED_UNICODE);
}
?>