<?php

/**
 * logout_controller.php
 *
 * Gestiona el cierre de sesión del usuario.
 *
 * Proyecto:
 * Sistema Integral E-Commerce y Gestión de Inventario
 *
 * Método:
 * POST
 */

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

// Iniciar la sesión actual.
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();

try {

    /*
     * Eliminar todas las variables almacenadas
     * en la sesión actual.
     */
    $_SESSION = [];

    /*
     * Eliminar la cookie de sesión del navegador.
     *
     * Solamente se intenta eliminar si las cookies
     * están habilitadas y existe un identificador
     * de sesión activo.
     */
    if (ini_get('session.use_cookies')) {

        $parametrosCookie = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $parametrosCookie['path'],
            $parametrosCookie['domain'],
            $parametrosCookie['secure'],
            $parametrosCookie['httponly']
        );
    }

    /*
     * Destruir completamente la sesión en el servidor.
     */
    session_destroy();

    http_response_code(200);

    echo json_encode([
        'success' => true,
        'message' => 'Sesión cerrada correctamente.'
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {

    /*
     * Registrar el error técnico en el servidor
     * sin exponer información interna al cliente.
     */
    error_log('Error en logout_controller.php: ' . $e->getMessage());

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'No fue posible cerrar la sesión.'
    ], JSON_UNESCAPED_UNICODE);
}