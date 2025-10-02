<?php
require __DIR__ . '/vendor/autoload.php';

$settings = [
    'settings' => [
        'displayErrorDetails' => true,
        'db' => [
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => getenv('DB_PORT') ?: '3306',
            'database' => getenv('DB_NAME') ?: 'test-clinica',
            'username' => getenv('DB_USER') ?: 'root',
            'password' => getenv('DB_PASS') ?: '',
            'charset' => 'utf8mb4',
        ],
    ],
];

$app = new \Slim\App($settings);

$container = $app->getContainer();

$container['db'] = function ($c) {
    $db = $c['settings']['db'];
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $db['host'],
        $db['port'],
        $db['database'],
        $db['charset']
    );

    $pdo = new PDO($dsn, $db['username'], $db['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    return $pdo;
};

$app->post('/iniciar-sesion', function ($request, $response) {
    $data = $request->getParsedBody() ?? [];
    $correo = isset($data['correo']) ? trim($data['correo']) : '';
    $password = isset($data['password']) ? (string) $data['password'] : '';

    if ($correo === '' || $password === '') {
        $payload = [
            'estado' => false,
            'mensaje' => 'Correo y contraseña son obligatorios',
        ];

        return $response->withJson($payload, 400);
    }

    /** @var PDO $pdo */
    $pdo = $this->get('db');
    $stmt = $pdo->prepare('SELECT password FROM usuario WHERE correo = :correo LIMIT 1');
    $stmt->execute(['correo' => $correo]);
    $usuario = $stmt->fetch();

    $autenticado = false;
    if ($usuario) {
        $hash = $usuario['password'];
        if (password_get_info($hash)['algo']) {
            $autenticado = password_verify($password, $hash);
        } else {
            $autenticado = hash_equals($hash, $password);
        }
    }

    if ($autenticado) {
        $payload = [
            'estado' => true,
            'mensaje' => 'Operacion exitosa',
        ];

        return $response->withJson($payload, 200);
    }

    $payload = [
        'estado' => false,
        'mensaje' => 'Usuario y/o Contraseña incorrecta',
    ];

    return $response->withJson($payload, 401);
});

$app->run();
