<?php

/**
 * login_controller.php
 *
 * Gestiona la autenticación de usuarios del sistema.
 *
 * Proyecto:
 * Sistema Integral E-Commerce y Gestión de Inventario
 *
 * Método:
 * POST
 *
 * Entrada esperada:
 * {
 *     "email": "usuario@correo.com",
 *     "password": "contraseña"
 * }
 */

require_once __DIR__ . '/../config/conexion.php';

// Todas las respuestas del controlador serán JSON.
header('Content-Type: application/json; charset=utf-8');

// Este endpoint solamente acepta solicitudes POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Método HTTP no permitido. Utilice POST.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/*
 * Configuración básica de la sesión.
 *
 * HttpOnly:
 * Evita que JavaScript pueda leer la cookie de sesión.
 *
 * SameSite=Lax:
 * Reduce determinados riesgos de solicitudes cross-site.
 */
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();

try {

    /*
     * Intentamos recibir los datos como JSON,
     * ya que el frontend utilizará fetch().
     */
    $datos = json_decode(file_get_contents('php://input'), true);

    if (!is_array($datos)) {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'El cuerpo de la solicitud no contiene un JSON válido.'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    // Obtenemos y limpiamos los datos recibidos.
    $email = trim($datos['email'] ?? '');
    $password = $datos['password'] ?? '';

    // Validación de campos obligatorios.
    if ($email === '' || $password === '') {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'El correo electrónico y la contraseña son obligatorios.'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    // Validación básica del formato del correo.
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'El correo electrónico no tiene un formato válido.'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    // Obtenemos la conexión PDO mediante nuestro Singleton.
    $pdo = Conexion::obtenerConexion();

    /*
     * Consulta preparada:
     * nunca concatenamos directamente el correo recibido
     * dentro de la consulta SQL.
     */
    $sql = "
        SELECT
            id_usuario,
            nombre,
            email,
            password,
            rol
        FROM usuarios
        WHERE email = :email
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':email' => $email
    ]);

    $usuario = $stmt->fetch();

    /*
     * Mensaje genérico para no revelar si el correo
     * existe o no en la base de datos.
     */
    if (!$usuario || !password_verify($password, $usuario['password'])) {

        http_response_code(401);

        echo json_encode([
            'success' => false,
            'message' => 'Correo electrónico o contraseña incorrectos.'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
     * Regeneramos el identificador de sesión después
     * de una autenticación correcta para evitar
     * session fixation.
     */
    session_regenerate_id(true);

    /*
     * Datos mínimos necesarios para identificar
     * al usuario durante las siguientes peticiones.
     */
    $_SESSION['id_usuario'] = (int) $usuario['id_usuario'];
    $_SESSION['nombre'] = $usuario['nombre'];
    $_SESSION['email'] = $usuario['email'];
    $_SESSION['rol'] = $usuario['rol'];

    /*
     * Nunca devolvemos la contraseña ni su hash
     * al frontend.
     */
    http_response_code(200);

    echo json_encode([
        'success' => true,
        'message' => 'Inicio de sesión exitoso.',
        'usuario' => [
            'id_usuario' => (int) $usuario['id_usuario'],
            'nombre' => $usuario['nombre'],
            'email' => $usuario['email'],
            'rol' => $usuario['rol']
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {

    /*
     * El error técnico se registra en el servidor,
     * pero no se expone al cliente.
     */
    error_log('Error en login_controller.php: ' . $e->getMessage());

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor.'
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {

    /*
     * Captura de errores generales inesperados.
     */
    error_log('Error inesperado en login_controller.php: ' . $e->getMessage());

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor.'
    ], JSON_UNESCAPED_UNICODE);
}