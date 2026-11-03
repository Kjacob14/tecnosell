<?php

/**
 * proveedores_controller.php
 *
 * CRUD de proveedores para el panel administrativo.
 *
 * Métodos:
 * GET    -> Consultar proveedores (?id= para uno, ?q= para buscar)
 * POST   -> Crear proveedor
 * PUT    -> Modificar proveedor (?id=)
 * DELETE -> Eliminar proveedor (?id=); sus productos quedan sin proveedor
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

function obtenerIdProveedor(): int
{
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

    if ($id === false || $id === null || $id <= 0) {
        error400('El id del proveedor no es válido.');
    }

    return $id;
}

/**
 * Valida un campo de texto. Si es opcional y viene vacío devuelve null.
 */
function validarTexto(mixed $valor, string $etiqueta, int $maximo, bool $obligatorio): ?string
{
    if ($valor === null || (is_string($valor) && trim($valor) === '')) {
        if ($obligatorio) {
            error400("$etiqueta es obligatorio.");
        }
        return null;
    }

    if (!is_string($valor)) {
        error400("$etiqueta debe ser texto.");
    }

    $valor = trim($valor);

    if (mb_strlen($valor, 'UTF-8') > $maximo) {
        error400("$etiqueta no puede superar los $maximo caracteres.");
    }

    return $valor;
}

function validarEmail(mixed $valor): ?string
{
    $email = validarTexto($valor, 'El correo', 100, false);

    if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        error400('El correo del proveedor no tiene un formato válido.');
    }

    return $email;
}

function validarTelefono(mixed $valor): ?string
{
    $telefono = validarTexto($valor, 'El teléfono', 20, false);

    // Dígitos, espacios, guiones, paréntesis y + (ej. +52 722 123 4567).
    if ($telefono !== null && !preg_match('/^[0-9+()\-\s]{7,20}$/', $telefono)) {
        error400('El teléfono solo puede tener números, espacios, guiones, paréntesis y +, con al menos 7 caracteres.');
    }

    return $telefono;
}

function validarEstado(mixed $valor): int
{
    if ($valor === true || $valor === 1 || $valor === '1') {
        return 1;
    }

    if ($valor === false || $valor === 0 || $valor === '0') {
        return 0;
    }

    error400('El estado del proveedor debe ser 1 (activo) o 0 (inactivo).');
}

/**
 * Valida todos los campos del cuerpo. En PUT se reciben todos los campos.
 */
function validarProveedor(array $datos): array
{
    return [
        'nombre_empresa' => validarTexto($datos['nombre_empresa'] ?? null, 'El nombre de la empresa', 100, true),
        'contacto'       => validarTexto($datos['contacto'] ?? null, 'El nombre de contacto', 100, false),
        'telefono'       => validarTelefono($datos['telefono'] ?? null),
        'email'          => validarEmail($datos['email'] ?? null),
        'direccion'      => validarTexto($datos['direccion'] ?? null, 'La dirección', 255, false),
        'estado'         => array_key_exists('estado', $datos) ? validarEstado($datos['estado']) : 1,
    ];
}

function formatearProveedor(array $fila): array
{
    return [
        'id_proveedor'   => (int) $fila['id_proveedor'],
        'nombre_empresa' => $fila['nombre_empresa'],
        'contacto'       => $fila['contacto'],
        'telefono'       => $fila['telefono'],
        'email'          => $fila['email'],
        'direccion'      => $fila['direccion'],
        'estado'         => (int) $fila['estado'],
    ];
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

    $pdo = Conexion::obtenerConexion();
    $metodo = $_SERVER['REQUEST_METHOD'];

    $columnas = 'id_proveedor, nombre_empresa, contacto, telefono, email, direccion, estado';

    /* ---------- GET ---------- */
    if ($metodo === 'GET') {

        if (isset($_GET['id'])) {
            $id = obtenerIdProveedor();

            $stmt = $pdo->prepare("SELECT $columnas FROM proveedores WHERE id_proveedor = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $fila = $stmt->fetch();

            if (!$fila) {
                error400('El proveedor solicitado no existe.');
            }

            responderJSON(200, [
                'success' => true,
                'message' => 'Proveedor obtenido correctamente.',
                'proveedor' => formatearProveedor($fila)
            ]);
        }

        $busqueda = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';

        if (mb_strlen($busqueda, 'UTF-8') > 100) {
            error400('La búsqueda no puede superar los 100 caracteres.');
        }

        if ($busqueda !== '') {
            // Se escapan % y _ para que se busquen como texto literal.
            $patron = '%' . addcslashes($busqueda, '\\%_') . '%';

            $stmt = $pdo->prepare("
                SELECT $columnas FROM proveedores
                WHERE nombre_empresa LIKE :q1
                   OR contacto LIKE :q2
                   OR email LIKE :q3
                ORDER BY nombre_empresa ASC
            ");
            $stmt->execute([':q1' => $patron, ':q2' => $patron, ':q3' => $patron]);
        } else {
            $stmt = $pdo->prepare("SELECT $columnas FROM proveedores ORDER BY nombre_empresa ASC");
            $stmt->execute();
        }

        $proveedores = array_map('formatearProveedor', $stmt->fetchAll());

        responderJSON(200, [
            'success' => true,
            'message' => 'Proveedores obtenidos correctamente.',
            'total' => count($proveedores),
            'proveedores' => $proveedores
        ]);
    }


    /* ---------- POST ---------- */
    if ($metodo === 'POST') {

        $d = validarProveedor(obtenerDatosJSON());

        $stmt = $pdo->prepare('SELECT id_proveedor FROM proveedores WHERE nombre_empresa = :n LIMIT 1');
        $stmt->execute([':n' => $d['nombre_empresa']]);

        if ($stmt->fetch()) {
            error400('Ya existe un proveedor con ese nombre.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO proveedores (nombre_empresa, contacto, telefono, email, direccion, estado)
            VALUES (:nombre_empresa, :contacto, :telefono, :email, :direccion, :estado)
        ");
        $stmt->execute([
            ':nombre_empresa' => $d['nombre_empresa'],
            ':contacto'       => $d['contacto'],
            ':telefono'       => $d['telefono'],
            ':email'          => $d['email'],
            ':direccion'      => $d['direccion'],
            ':estado'         => $d['estado'],
        ]);

        responderJSON(200, [
            'success' => true,
            'message' => 'Proveedor creado correctamente.',
            'proveedor' => ['id_proveedor' => (int) $pdo->lastInsertId()] + $d
        ]);
    }


    /* ---------- PUT ---------- */
    if ($metodo === 'PUT') {

        $id = obtenerIdProveedor();
        $d = validarProveedor(obtenerDatosJSON());

        $stmt = $pdo->prepare('SELECT id_proveedor FROM proveedores WHERE id_proveedor = :id LIMIT 1');
        $stmt->execute([':id' => $id]);

        if (!$stmt->fetch()) {
            error400('El proveedor que intenta modificar no existe.');
        }

        $stmt = $pdo->prepare('
            SELECT id_proveedor FROM proveedores
            WHERE nombre_empresa = :n AND id_proveedor <> :id LIMIT 1
        ');
        $stmt->execute([':n' => $d['nombre_empresa'], ':id' => $id]);

        if ($stmt->fetch()) {
            error400('Ya existe otro proveedor con ese nombre.');
        }

        $stmt = $pdo->prepare("
            UPDATE proveedores SET
                nombre_empresa = :nombre_empresa,
                contacto = :contacto,
                telefono = :telefono,
                email = :email,
                direccion = :direccion,
                estado = :estado
            WHERE id_proveedor = :id
        ");
        $stmt->execute([
            ':nombre_empresa' => $d['nombre_empresa'],
            ':contacto'       => $d['contacto'],
            ':telefono'       => $d['telefono'],
            ':email'          => $d['email'],
            ':direccion'      => $d['direccion'],
            ':estado'         => $d['estado'],
            ':id'             => $id,
        ]);

        responderJSON(200, [
            'success' => true,
            'message' => 'Proveedor actualizado correctamente.',
            'proveedor' => ['id_proveedor' => $id] + $d
        ]);
    }


    /* ---------- DELETE ---------- */
    if ($metodo === 'DELETE') {

        $id = obtenerIdProveedor();

        $stmt = $pdo->prepare('SELECT id_proveedor FROM proveedores WHERE id_proveedor = :id LIMIT 1');
        $stmt->execute([':id' => $id]);

        if (!$stmt->fetch()) {
            error400('El proveedor que intenta eliminar no existe.');
        }

        // La FK productos.id_proveedor es ON DELETE SET NULL.
        $stmt = $pdo->prepare('DELETE FROM proveedores WHERE id_proveedor = :id');
        $stmt->execute([':id' => $id]);

        responderJSON(200, [
            'success' => true,
            'message' => 'Proveedor eliminado correctamente.',
            'id_proveedor' => $id
        ]);
    }

    error400('Método HTTP no soportado por este controlador.');

} catch (PDOException $e) {
    error_log('Error PDO en proveedores_controller.php: ' . $e->getMessage());
    responderJSON(500, ['success' => false, 'message' => 'Error interno del servidor.']);
} catch (Throwable $e) {
    error_log('Error inesperado en proveedores_controller.php: ' . $e->getMessage());
    responderJSON(500, ['success' => false, 'message' => 'Error interno del servidor.']);
}
