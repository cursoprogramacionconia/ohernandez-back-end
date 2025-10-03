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
    $pacienteId = isset($data['pacienteId']) ? (int) $data['pacienteId'] : 0;
    $fecha = isset($data['fecha']) ? trim($data['fecha']) : '';
    $hora = isset($data['hora']) ? trim($data['hora']) : '';
    $motivo = isset($data['motivo']) ? trim($data['motivo']) : '';
    $notas = isset($data['notas']) ? trim($data['notas']) : '';

    $errores = [];

    if ($medicoId <= 0) {
        $errores['medicoId'] = 'El médico es obligatorio.';
    }

    if ($pacienteId <= 0) {
        $errores['pacienteId'] = 'El paciente es obligatorio.';
    }

    if ($fecha === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        $errores['fecha'] = 'La fecha debe tener el formato YYYY-MM-DD.';
    } else {
        [$year, $month, $day] = array_map('intval', explode('-', $fecha));
        if (!checkdate($month, $day, $year)) {
            $errores['fecha'] = 'La fecha proporcionada no es válida.';
        }
    }

    if ($hora === '' || !preg_match('/^\d{2}:\d{2}$/', $hora)) {
        $errores['hora'] = 'La hora debe tener el formato HH:MM.';
    } else {
        [$hour, $minute] = array_map('intval', explode(':', $hora));
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            $errores['hora'] = 'La hora proporcionada no es válida.';
        }
    }

    if ($motivo === '') {
        $errores['motivo'] = 'El motivo es obligatorio.';
    } elseif (mb_strlen($motivo) > 200) {
        $errores['motivo'] = 'El motivo no puede superar los 200 caracteres.';
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

        $stmtMedico = $pdo->prepare('SELECT id, activo FROM medicos WHERE id = :id LIMIT 1');
        $stmtMedico->execute(['id' => $medicoId]);
        $medico = $stmtMedico->fetch();

        if (!$medico) {
            $pdo->rollBack();

            $payload = [
                'estado' => false,
                'mensaje' => 'El médico seleccionado no existe.',
            ];

            return $response->withJson($payload, 404);
        }

        $stmtPaciente = $pdo->prepare('SELECT id, activo FROM paciente WHERE id = :id LIMIT 1');
        $stmtPaciente->execute(['id' => $pacienteId]);
        $paciente = $stmtPaciente->fetch();

        if (!$paciente) {
            $pdo->rollBack();

            $payload = [
                'estado' => false,
                'mensaje' => 'El paciente seleccionado no existe.',
            ];

            return $response->withJson($payload, 404);
        }

        $stmtInsert = $pdo->prepare(
            'INSERT INTO consulta (id_medico, id_paciente, fecha, hora, motivo, notas) VALUES (:id_medico, :id_paciente, :fecha, :hora, :motivo, :notas)'
        );

        $stmtInsert->execute([
            'id_medico' => $medicoId,
            'id_paciente' => $pacienteId,
            'fecha' => $fecha,
            'hora' => $hora,
            'motivo' => $motivo,
            'notas' => $notas !== '' ? $notas : null,
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
