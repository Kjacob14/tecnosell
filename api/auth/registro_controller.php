<?php
/**
 * registro_controller.php
 *
 * Crea un nuevo usuario con rol de cliente.
 * Encripta la contraseña usando BCRYPT.
 */

require_once __DIR__ . '/../config/conexion.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido. Use POST.']);
    exit;
}

try {
    $datos = json_decode(file_get_contents('php://input'), true);

    if (!is_array($datos)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'JSON inválido.']);
        exit;
    }

    $nombre = trim($datos['nombre'] ?? '');
    $email = trim($datos['email'] ?? '');
    $password = $datos['password'] ?? '';

    if ($nombre === '' || $email === '' || $password === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Todos los campos son obligatorios.']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Formato de correo no válido.']);
        exit;
    }

    $pdo = Conexion::obtenerConexion();

    // 1. Verificar si el correo ya existe
    $stmtVerificar = $pdo->prepare("SELECT id_usuario FROM usuarios WHERE email = :email LIMIT 1");
    $stmtVerificar->execute([':email' => $email]);
    
    if ($stmtVerificar->fetch()) {
        http_response_code(409); // Conflicto
        echo json_encode(['success' => false, 'message' => 'El correo electrónico ya está registrado.']);
        exit;
    }

    // 2. Encriptar contraseña (Cumplimiento de Seguridad)
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);

    // 3. Insertar nuevo usuario (rol 'cliente' por defecto)
    $sql = "INSERT INTO usuarios (nombre, email, password, rol) VALUES (:nombre, :email, :password, 'cliente')";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':nombre' => $nombre,
        ':email' => $email,
        ':password' => $passwordHash
    ]);

    http_response_code(200);
    echo json_encode([
        'success' => true, 
        'message' => 'Cuenta creada correctamente.'
    ]);

} catch (PDOException $e) {
    error_log('Error PDO en registro: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error interno del servidor.']);
} catch (Throwable $e) {
    error_log('Error general en registro: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error interno del servidor.']);
}
?>