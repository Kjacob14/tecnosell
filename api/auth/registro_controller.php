<?php
/**
 * registro_controller.php
 *
 * Crea un nuevo usuario con rol de cliente.
 * Encripta la contraseña usando BCRYPT.
 */

require_once __DIR__ . '/../config/conexion.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Reglas de contraseña segura: mínimo 8 caracteres, una mayúscula, un
 * número y un carácter especial (ni letra, ni número, ni espacio).
 *
 * Devuelve los requisitos que NO se cumplen, con su texto; el arreglo
 * queda vacío si la contraseña es válida. Debe mantenerse igual que
 * REQUISITOS_PASSWORD en assets/js/registro.js: el navegador solo
 * muestra el avance, quien decide es el servidor.
 *
 * @param string $password
 * @return array<string, string>
 */
function requisitosFaltantes(string $password): array
{
    $faltantes = [];

    if (mb_strlen($password, 'UTF-8') < 8) {
        $faltantes['longitud'] = 'al menos 8 caracteres';
    }

    if (!preg_match('/\p{Lu}/u', $password)) {
        $faltantes['mayuscula'] = 'una letra mayúscula';
    }

    if (!preg_match('/\d/', $password)) {
        $faltantes['numero'] = 'un número';
    }

    if (!preg_match('/[^\p{L}\p{N}\s]/u', $password)) {
        $faltantes['especial'] = 'un carácter especial';
    }

    return $faltantes;
}

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

    // Un valor que no sea texto (por ejemplo un arreglo) rompería trim()
    // y password_hash(); se rechaza antes con un 400.
    foreach (['nombre', 'email', 'password'] as $campo) {
        if (isset($datos[$campo]) && !is_string($datos[$campo])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Formato de datos no válido.']);
            exit;
        }
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

    // Seguridad de la contraseña. Se valida antes de consultar la base de
    // datos y antes de encriptar. BCRYPT solo usa los primeros 72 bytes,
    // así que una contraseña más larga se rechaza en lugar de recortarse
    // en silencio.
    if (!mb_check_encoding($password, 'UTF-8')) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'La contraseña contiene caracteres no válidos.']);
        exit;
    }

    if (strlen($password) > 72) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'La contraseña no puede superar los 72 caracteres.']);
        exit;
    }

    $faltantes = requisitosFaltantes($password);

    if (!empty($faltantes)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'La contraseña no cumple los requisitos de seguridad. Falta: '
                . implode(', ', array_values($faltantes)) . '.',
            'requisitos_faltantes' => array_keys($faltantes)
        ]);
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