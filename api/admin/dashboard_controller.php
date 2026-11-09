<?php

/**
 * dashboard_controller.php
 *
 * Resumen de ventas y ganancias para el panel administrativo.
 *
 * Métodos:
 * GET -> Resumen del periodo (?dias=7|30|90|todo, por defecto 30)
 *
 * Solo cuentan los pedidos con estado "pagado" o "enviado".
 *
 * Acceso: únicamente usuarios autenticados con rol "admin".
 */

require_once __DIR__ . '/../config/conexion.php';

header('Content-Type: application/json; charset=utf-8');


function responderJSON(int $codigoHTTP, array $respuesta): never
{
    http_response_code($codigoHTTP);
    echo json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function error400(string $mensaje): never
{
    responderJSON(400, ['success' => false, 'message' => $mensaje]);
}


/* =========================================================
 * SESIÓN Y CONTROL DE ACCESO
 * ========================================================= */

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();

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

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        error400('Método HTTP no soportado por este controlador.');
    }

    $periodo = $_GET['dias'] ?? '30';

    if (!is_string($periodo) || !in_array($periodo, ['7', '30', '90', 'todo'], true)) {
        error400('El periodo debe ser 7, 30, 90 o todo.');
    }

    $pdo = Conexion::obtenerConexion();

    /*
     * Filtro de fecha: solo se agrega cuando el periodo no es "todo".
     * El número de días se pasa como parámetro, nunca concatenado.
     */
    $filtroFecha = '';
    $parametros = [];

    if ($periodo !== 'todo') {
        $filtroFecha = 'AND p.fecha_pedido >= DATE_SUB(NOW(), INTERVAL :dias DAY)';
        $parametros[':dias'] = (int) $periodo;
    }

    /* ---------- Resumen ---------- */
    $sqlResumen = "
        SELECT
            COALESCE(SUM(d.cantidad * d.precio_compra), 0) AS ingresos,
            COALESCE(SUM(d.cantidad * d.costo_compra), 0) AS costos,
            COUNT(DISTINCT p.id_pedido) AS pedidos,
            COALESCE(SUM(d.cantidad), 0) AS articulos,
            COUNT(DISTINCT CASE WHEN d.costo_compra = 0 THEN p.id_pedido END) AS pedidos_sin_costo
        FROM detalles_pedido d
        INNER JOIN pedidos p
            ON d.id_pedido = p.id_pedido
        WHERE p.estado_pedido IN ('pagado', 'enviado')
        $filtroFecha
    ";

    $stmt = $pdo->prepare($sqlResumen);
    $stmt->execute($parametros);
    $fila = $stmt->fetch();

    $ingresos = round((float) $fila['ingresos'], 2);
    $costos = round((float) $fila['costos'], 2);
    $utilidad = round($ingresos - $costos, 2);

    /* ---------- Más vendidos ---------- */
    $sqlMasVendidos = "
        SELECT
            d.id_producto,
            COALESCE(pr.nombre, 'Producto eliminado') AS nombre,
            SUM(d.cantidad) AS unidades,
            SUM(d.cantidad * d.precio_compra) AS ingresos,
            SUM(d.cantidad * d.precio_compra) - SUM(d.cantidad * d.costo_compra) AS utilidad
        FROM detalles_pedido d
        INNER JOIN pedidos p
            ON d.id_pedido = p.id_pedido
        LEFT JOIN productos pr
            ON d.id_producto = pr.id_producto
        WHERE p.estado_pedido IN ('pagado', 'enviado')
        $filtroFecha
        GROUP BY d.id_producto, pr.nombre
        ORDER BY unidades DESC, ingresos DESC
        LIMIT 5
    ";

    $stmt = $pdo->prepare($sqlMasVendidos);
    $stmt->execute($parametros);

    $masVendidos = [];

    foreach ($stmt->fetchAll() as $producto) {
        $masVendidos[] = [
            'id_producto' => $producto['id_producto'] !== null ? (int) $producto['id_producto'] : null,
            'nombre'      => $producto['nombre'],
            'unidades'    => (int) $producto['unidades'],
            'ingresos'    => number_format(round((float) $producto['ingresos'], 2), 2, '.', ''),
            'utilidad'    => number_format(round((float) $producto['utilidad'], 2), 2, '.', '')
        ];
    }

    responderJSON(200, [
        'success'     => true,
        'message'     => 'Dashboard obtenido correctamente.',
        'periodo'     => $periodo,
        'resumen'     => [
            'ingresos'          => number_format($ingresos, 2, '.', ''),
            'costos'            => number_format($costos, 2, '.', ''),
            'utilidad'          => number_format($utilidad, 2, '.', ''),
            'pedidos'           => (int) $fila['pedidos'],
            'articulos'         => (int) $fila['articulos'],
            'pedidos_sin_costo' => (int) $fila['pedidos_sin_costo']
        ],
        'mas_vendidos' => $masVendidos
    ]);

} catch (PDOException $e) {
    error_log('Error PDO en dashboard_controller.php: ' . $e->getMessage());
    responderJSON(500, ['success' => false, 'message' => 'Error interno del servidor.']);
} catch (Throwable $e) {
    error_log('Error inesperado en dashboard_controller.php: ' . $e->getMessage());
    responderJSON(500, ['success' => false, 'message' => 'Error interno del servidor.']);
}
