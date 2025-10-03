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

$app->options('/{routes:.+}', function ($request, $response) {
    return $response;
});

$app->add(function ($request, $response, $next) {
    $response = $next($request, $response);

    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
});

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

$app->post('/crear-consulta', function ($request, $response) {
    $data = $request->getParsedBody() ?? [];

    $medicoId = isset($data['medicoId']) ? (int) $data['medicoId'] : 0;
    if ($medicoId <= 0 && isset($data['id_medico'])) {
        $medicoId = (int) $data['id_medico'];
    }

    $pacienteId = isset($data['pacienteId']) ? (int) $data['pacienteId'] : 0;
    if ($pacienteId <= 0 && isset($data['id_paciente'])) {
        $pacienteId = (int) $data['id_paciente'];
    }

    $motivo = isset($data['motivo']) ? trim((string) $data['motivo']) : '';
    $sintomas = isset($data['sintomas']) ? trim((string) $data['sintomas']) : '';
    if ($sintomas === '' && $motivo !== '') {
        $sintomas = $motivo;
    }

    $notas = isset($data['notas']) ? trim((string) $data['notas']) : '';
    $recomendaciones = isset($data['recomendaciones']) ? trim((string) $data['recomendaciones']) : '';
    if ($recomendaciones === '' && $notas !== '') {
        $recomendaciones = $notas;
    }

    $diagnostico = isset($data['diagnostico']) ? trim((string) $data['diagnostico']) : '';

    $fecha = isset($data['fecha']) ? trim((string) $data['fecha']) : '';
    $hora = isset($data['hora']) ? trim((string) $data['hora']) : '';
    if ($diagnostico === '' && ($fecha !== '' || $hora !== '')) {
        $partes = [];
        if ($fecha !== '') {
            $partes[] = 'Fecha: ' . $fecha;
        }
        if ($hora !== '') {
            $partes[] = 'Hora: ' . $hora;
        }
        $diagnostico = implode(' | ', $partes);
    }

    $errores = [];

    if ($medicoId <= 0) {
        $errores['medicoId'] = 'El médico es obligatorio.';
    }

    if ($pacienteId <= 0) {
        $errores['pacienteId'] = 'El paciente es obligatorio.';
    }

    if ($sintomas === '') {
        $errores['motivo'] = 'El motivo o los síntomas son obligatorios.';
    }

    if ($errores) {
        $payload = [
            'estado' => false,
            'mensaje' => 'Datos inválidos.',
            'errores' => $errores,
        ];

        return $response->withJson($payload, 400);
    }

    /** @var PDO $pdo */
    $pdo = $this->get('db');

    try {
        $pdo->beginTransaction();

        $stmtMedico = $pdo->prepare('SELECT id FROM medicos WHERE id = :id LIMIT 1');
        $stmtMedico->execute(['id' => $medicoId]);
        if (!$stmtMedico->fetch()) {
            $pdo->rollBack();

            $payload = [
                'estado' => false,
                'mensaje' => 'El médico seleccionado no existe.',
            ];

            return $response->withJson($payload, 404);
        }

        $stmtPaciente = $pdo->prepare('SELECT id FROM paciente WHERE id = :id LIMIT 1');
        $stmtPaciente->execute(['id' => $pacienteId]);
        if (!$stmtPaciente->fetch()) {
            $pdo->rollBack();

            $payload = [
                'estado' => false,
                'mensaje' => 'El paciente seleccionado no existe.',
            ];

            return $response->withJson($payload, 404);
        }

        $stmtInsert = $pdo->prepare(
            'INSERT INTO consulta (id_medico, id_paciente, sintomas, recomendaciones, diagnostico) VALUES (:id_medico, :id_paciente, :sintomas, :recomendaciones, :diagnostico)'
        );

        $stmtInsert->execute([
            'id_medico' => $medicoId,
            'id_paciente' => $pacienteId,
            'sintomas' => $sintomas,
            'recomendaciones' => $recomendaciones !== '' ? $recomendaciones : null,
            'diagnostico' => $diagnostico !== '' ? $diagnostico : null,
        ]);

        $pdo->commit();

        $payload = [
            'estado' => true,
            'mensaje' => 'Consulta registrada correctamente.',
            'idConsulta' => (int) $pdo->lastInsertId(),
        ];

        return $response->withJson($payload, 201);
    } catch (\Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $payload = [
            'estado' => false,
            'mensaje' => 'No fue posible registrar la consulta.',
        ];

        return $response->withJson($payload, 500);
    }
});

$app->run();
