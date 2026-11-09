<?php
/**
 * direcciones_controller.php
 *
 * Direcciones de entrega del cliente que tiene la sesión iniciada.
 *
 * Métodos:
 *   GET           -> lista sus direcciones, de la más reciente a la más antigua.
 *   POST (JSON)   -> crea una dirección (máximo 5 por usuario).
 *   DELETE ?id=   -> borra una dirección propia.
 *
 * Toda consulta filtra por el id_usuario de la sesión: una persona nunca
 * ve ni modifica las direcciones de otra.
 */

require_once __DIR__ . '/../config/conexion.php';

header('Content-Type: application/json; charset=utf-8');

const MAX_DIRECCIONES = 5;

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

function obtenerDatosJSON(): array
{
    $contenido = file_get_contents('php://input');

    if ($contenido === false || trim($contenido) === '') {
        error400('El cuerpo de la solicitud está vacío.');
    }

    $datos = json_decode($contenido, true);

    if (!is_array($datos)) {
        error400('El cuerpo de la solicitud no contiene un JSON válido.');
    }

    return $datos;
}

/**
 * Valida un campo de texto. Rechaza lo que no sea string. Si es opcional
 * y viene vacío o ausente, devuelve null.
 */
function validarTextoDireccion(mixed $valor, string $etiqueta, int $maximo, bool $obligatorio): ?string
{
    if ($valor === null) {
        if ($obligatorio) {
            error400("$etiqueta es obligatorio.");
        }
        return null;
    }

    if (!is_string($valor)) {
        error400("$etiqueta debe ser texto.");
    }

    $valor = trim($valor);

    if ($valor === '') {
        if ($obligatorio) {
            error400("$etiqueta es obligatorio.");
        }
        return null;
    }

    if (mb_strlen($valor, 'UTF-8') > $maximo) {
        error400("$etiqueta no puede superar los $maximo caracteres.");
    }

    return $valor;
}

function validarDireccion(array $datos): array
{
    $telefono = validarTextoDireccion($datos['telefono'] ?? null, 'El teléfono', 20, true);

    if (!preg_match('/^[0-9+()\-\s]{7,20}$/', $telefono)) {
        error400('El teléfono solo puede tener números, espacios, guiones, paréntesis y +, con al menos 7 caracteres.');
    }

    $codigoPostal = validarTextoDireccion($datos['codigo_postal'] ?? null, 'El código postal', 5, true);

    if (!preg_match('/^\d{5}$/', $codigoPostal)) {
        error400('El código postal debe tener exactamente 5 dígitos.');
    }

    return [
        'alias'         => validarTextoDireccion($datos['alias'] ?? null, 'El alias', 50, true),
        'destinatario'  => validarTextoDireccion($datos['destinatario'] ?? null, 'El destinatario', 100, true),
        'telefono'      => $telefono,
        'calle'         => validarTextoDireccion($datos['calle'] ?? null, 'La calle', 150, true),
        'num_exterior'  => validarTextoDireccion($datos['num_exterior'] ?? null, 'El número exterior', 20, true),
        'num_interior'  => validarTextoDireccion($datos['num_interior'] ?? null, 'El número interior', 20, false),
        'colonia'       => validarTextoDireccion($datos['colonia'] ?? null, 'La colonia', 100, true),
        'ciudad'        => validarTextoDireccion($datos['ciudad'] ?? null, 'La ciudad', 100, true),
        'estado'        => validarTextoDireccion($datos['estado'] ?? null, 'El estado', 100, true),
        'codigo_postal' => $codigoPostal,
        'referencias'   => validarTextoDireccion($datos['referencias'] ?? null, 'Las referencias', 255, false),
    ];
}

function formatearDireccion(array $fila): array
{
    return [
        'id_direccion'  => (int) $fila['id_direccion'],
        'alias'         => $fila['alias'],
        'destinatario'  => $fila['destinatario'],
        'telefono'      => $fila['telefono'],
        'calle'         => $fila['calle'],
        'num_exterior'  => $fila['num_exterior'],
        'num_interior'  => $fila['num_interior'],
        'colonia'       => $fila['colonia'],
        'ciudad'        => $fila['ciudad'],
        'estado'        => $fila['estado'],
        'codigo_postal' => $fila['codigo_postal'],
        'referencias'   => $fila['referencias'],
    ];
}


/* =========================================================
 * SESIÓN
 * ========================================================= */

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

if (!isset($_SESSION['id_usuario'])) {
    responderJSON(401, [
        'success' => false,
        'message' => 'Sesión expirada o inválida. Inicie sesión nuevamente.'
    ]);
}

$idUsuario = (int) $_SESSION['id_usuario'];


/* =========================================================
 * PROCESAMIENTO
 * ========================================================= */

try {
    $pdo = Conexion::obtenerConexion();
    $metodo = $_SERVER['REQUEST_METHOD'];

    /* ---------- GET: lista de direcciones propias ---------- */
    if ($metodo === 'GET') {
        $stmt = $pdo->prepare("
            SELECT id_direccion, alias, destinatario, telefono, calle, num_exterior,
                   num_interior, colonia, ciudad, estado, codigo_postal, referencias
            FROM direcciones_entrega
            WHERE id_usuario = :id_usuario
            ORDER BY id_direccion DESC
        ");
        $stmt->execute([':id_usuario' => $idUsuario]);

        $direcciones = array_map('formatearDireccion', $stmt->fetchAll());

        responderJSON(200, [
            'success'     => true,
            'message'     => 'Direcciones obtenidas correctamente.',
            'total'       => count($direcciones),
            'direcciones' => $direcciones
        ]);
    }

    /* ---------- POST: crear dirección ---------- */
    if ($metodo === 'POST') {
        $datos = validarDireccion(obtenerDatosJSON());

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM direcciones_entrega WHERE id_usuario = :id_usuario');
        $stmt->execute([':id_usuario' => $idUsuario]);

        if ((int) $stmt->fetchColumn() >= MAX_DIRECCIONES) {
            error400('Ya tienes 5 direcciones guardadas.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO direcciones_entrega (
                id_usuario, alias, destinatario, telefono, calle, num_exterior,
                num_interior, colonia, ciudad, estado, codigo_postal, referencias
            ) VALUES (
                :id_usuario, :alias, :destinatario, :telefono, :calle, :num_exterior,
                :num_interior, :colonia, :ciudad, :estado, :codigo_postal, :referencias
            )
        ");
        $stmt->execute([
            ':id_usuario'    => $idUsuario,
            ':alias'         => $datos['alias'],
            ':destinatario'  => $datos['destinatario'],
            ':telefono'      => $datos['telefono'],
            ':calle'         => $datos['calle'],
            ':num_exterior'  => $datos['num_exterior'],
            ':num_interior'  => $datos['num_interior'],
            ':colonia'       => $datos['colonia'],
            ':ciudad'        => $datos['ciudad'],
            ':estado'        => $datos['estado'],
            ':codigo_postal' => $datos['codigo_postal'],
            ':referencias'   => $datos['referencias']
        ]);

        $datos['id_direccion'] = (int) $pdo->lastInsertId();

        responderJSON(200, [
            'success'   => true,
            'message'   => 'Dirección guardada correctamente.',
            'direccion' => formatearDireccion($datos)
        ]);
    }

    /* ---------- DELETE ?id=: borrar dirección propia ---------- */
    if ($metodo === 'DELETE') {
        $idDireccion = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

        if ($idDireccion === false || $idDireccion === null || $idDireccion <= 0) {
            error400('El id de la dirección no es válido.');
        }

        $stmt = $pdo->prepare('DELETE FROM direcciones_entrega WHERE id_direccion = :id AND id_usuario = :id_usuario');
        $stmt->execute([
            ':id'         => $idDireccion,
            ':id_usuario' => $idUsuario
        ]);

        if ($stmt->rowCount() === 0) {
            error400('La dirección no existe.');
        }

        responderJSON(200, [
            'success' => true,
            'message' => 'Dirección eliminada correctamente.'
        ]);
    }

    responderJSON(405, [
        'success' => false,
        'message' => 'Método HTTP no permitido.'
    ]);

} catch (PDOException $e) {
    error_log('Error PDO en direcciones_controller.php: ' . $e->getMessage());
    responderJSON(500, ['success' => false, 'message' => 'Error interno del servidor.']);
} catch (Throwable $e) {
    error_log('Error inesperado en direcciones_controller.php: ' . $e->getMessage());
    responderJSON(500, ['success' => false, 'message' => 'Error interno del servidor.']);
}
