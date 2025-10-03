<?php
require __DIR__ . '/vendor/autoload.php';


function normalizarCadena($valor): ?string
{
    if ($valor === null) {
        return null;
    }

    $texto = trim((string) $valor);

    return $texto === '' ? null : $texto;
}

function interpretarBooleano($valor): ?bool
{
    if (is_bool($valor)) {
        return $valor;
    }

    if ($valor === 1 || $valor === 0) {
        return (bool) $valor;
    }

    if (is_int($valor)) {
        return $valor === 1;
    }

    if (is_float($valor)) {
        return ((int) $valor) === 1;
    }

    if (is_numeric($valor)) {
        return ((int) $valor) === 1;
    }

    if (is_string($valor)) {
        $normalizado = strtolower(trim($valor));
        if (in_array($normalizado, ['1', 'true', 'on', 'si', 'sí'], true)) {
            return true;
        }
        if (in_array($normalizado, ['0', 'false', 'off', 'no'], true)) {
            return false;
        }
    }

    return null;
}

function columnaExiste(PDO $pdo, string $tabla, string $columna): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tabla AND COLUMN_NAME = :columna LIMIT 1'
    );
    $stmt->execute([
        'tabla' => $tabla,
        'columna' => $columna,
    ]);

    return (bool) $stmt->fetchColumn();
}

function mapearMedico(array $fila): array
{
    return [
        'id' => (int) $fila['id'],
        'primer_nombre' => $fila['primer_nombre'],
        'segundo_nombre' => $fila['segundo_nombre'],
        'apellido_paterno' => $fila['apellido_paterno'],
        'apellido_materno' => $fila['apellido_materno'],
        'cedula' => $fila['cedula'],
        'telefono' => $fila['telefono'],
        'especialidad' => $fila['especialidad'],
        'email' => $fila['email'],
        'activo' => (bool) $fila['activo'],
        'fecha_creacion' => $fila['fecha_creacion'] ?? null,
    ];
}

function mapearUsuario(array $fila): array
{
    return [
        'id' => (int) $fila['id'],
        'correo' => $fila['correo'],
        'nombre_completo' => $fila['nombre_completo'],
        'id_medico' => $fila['id_medico'] !== null ? (int) $fila['id_medico'] : null,
        'activo' => (bool) $fila['activo'],
        'fecha_creacion' => $fila['fecha_creacion'] ?? null,
    ];
}

function obtenerMedico(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM medicos WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $fila = $stmt->fetch();

    return $fila ?: null;
}

function obtenerPaciente(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM paciente WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $fila = $stmt->fetch();

    return $fila ?: null;
}

function obtenerUsuario(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT id, correo, nombre_completo, id_medico, activo, fecha_creacion FROM usuario WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $fila = $stmt->fetch();

    return $fila ?: null;
}

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
    $data = $request->getParsedBody();
    if (!is_array($data)) {
        $data = [];
    }

    $medicoId = 0;
    foreach (['id_medico', 'medicoId'] as $key) {
        if ($medicoId <= 0 && isset($data[$key])) {
            $medicoId = (int) $data[$key];
        }
    }

    $pacienteId = 0;
    foreach (['id_paciente', 'pacienteId'] as $key) {
        if ($pacienteId <= 0 && isset($data[$key])) {
            $pacienteId = (int) $data[$key];
        }
    }

    $sintomas = '';
    foreach (['sintomas', 'motivo'] as $key) {
        if ($sintomas === '' && isset($data[$key])) {
            $sintomas = trim((string) $data[$key]);
        }
    }

    $recomendaciones = '';
    foreach (['recomendaciones', 'notas'] as $key) {
        if ($recomendaciones === '' && isset($data[$key])) {
            $recomendaciones = trim((string) $data[$key]);
        }
    }

    $diagnostico = isset($data['diagnostico']) ? trim((string) $data['diagnostico']) : '';

    $errores = [];

    if ($medicoId <= 0) {
        $errores['id_medico'] = 'El médico es obligatorio.';
    }

    if ($pacienteId <= 0) {
        $errores['id_paciente'] = 'El paciente es obligatorio.';
    }

    if ($sintomas === '') {
        $errores['sintomas'] = 'Los síntomas son obligatorios.';
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

$app->get('/historial-consultas', function ($request, $response) {
    $params = $request->getQueryParams();

    $medicoId = 0;
    foreach (['medicoId', 'id_medico'] as $key) {
        if ($medicoId <= 0 && isset($params[$key])) {
            $medicoId = (int) $params[$key];
        }
    }

    $pacienteId = 0;
    foreach (['pacienteId', 'id_paciente'] as $key) {
        if ($pacienteId <= 0 && isset($params[$key])) {
            $pacienteId = (int) $params[$key];
        }
    }

    $fechaInicio = normalizarCadena($params['fechaInicio'] ?? ($params['fecha_desde'] ?? null));
    $fechaFin = normalizarCadena($params['fechaFin'] ?? ($params['fecha_hasta'] ?? null));

    $errores = [];

    foreach (['fechaInicio' => $fechaInicio, 'fechaFin' => $fechaFin] as $nombre => $valor) {
        if ($valor !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            $errores[$nombre] = 'El formato de fecha debe ser YYYY-MM-DD.';
        }
    }

    if ($errores) {
        $payload = [
            'estado' => false,
            'mensaje' => 'Parámetros inválidos.',
            'errores' => $errores,
        ];

        return $response->withJson($payload, 400);
    }

    /** @var PDO $pdo */
    $pdo = $this->get('db');

    $fechaColumnaDisponible = columnaExiste($pdo, 'consulta', 'fecha_creacion');
    if (($fechaInicio !== null || $fechaFin !== null) && !$fechaColumnaDisponible) {
        $payload = [
            'estado' => false,
            'mensaje' => 'El filtrado por fechas no está disponible en la tabla consulta.',
        ];

        return $response->withJson($payload, 400);
    }

    $columnas = [
        'c.id',
        'c.id_medico',
        'c.id_paciente',
        'c.sintomas',
        'c.recomendaciones',
        'c.diagnostico',
        'm.primer_nombre AS medico_primer_nombre',
        'm.segundo_nombre AS medico_segundo_nombre',
        'm.apellido_paterno AS medico_apellido_paterno',
        'm.apellido_materno AS medico_apellido_materno',
        'm.telefono AS medico_telefono',
        'm.especialidad AS medico_especialidad',
        'm.email AS medico_email',
        'm.activo AS medico_activo',
        'p.primer_nombre AS paciente_primer_nombre',
        'p.segundo_nombre AS paciente_segundo_nombre',
        'p.apellido_paterno AS paciente_apellido_paterno',
        'p.apellido_materno AS paciente_apellido_materno',
        'p.telefono AS paciente_telefono',
        'p.activo AS paciente_activo',
    ];

    if ($fechaColumnaDisponible) {
        $columnas[] = 'c.fecha_creacion';
    }

    $sql = 'SELECT ' . implode(', ', $columnas) . ' FROM consulta c '
        . 'INNER JOIN medicos m ON m.id = c.id_medico '
        . 'INNER JOIN paciente p ON p.id = c.id_paciente';

    $condiciones = [];
    $parametros = [];

    if ($medicoId > 0) {
        $condiciones[] = 'c.id_medico = :medicoId';
        $parametros['medicoId'] = $medicoId;
    }

    if ($pacienteId > 0) {
        $condiciones[] = 'c.id_paciente = :pacienteId';
        $parametros['pacienteId'] = $pacienteId;
    }

    if ($fechaColumnaDisponible && $fechaInicio !== null) {
        $condiciones[] = 'DATE(c.fecha_creacion) >= :fechaInicio';
        $parametros['fechaInicio'] = $fechaInicio;
    }

    if ($fechaColumnaDisponible && $fechaFin !== null) {
        $condiciones[] = 'DATE(c.fecha_creacion) <= :fechaFin';
        $parametros['fechaFin'] = $fechaFin;
    }

    if ($condiciones) {
        $sql .= ' WHERE ' . implode(' AND ', $condiciones);
    }

    $sql .= ' ORDER BY c.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($parametros);
    $filas = $stmt->fetchAll();

    $consultas = [];

    foreach ($filas as $fila) {
        $registro = [
            'id' => (int) $fila['id'],
            'id_medico' => (int) $fila['id_medico'],
            'id_paciente' => (int) $fila['id_paciente'],
            'sintomas' => $fila['sintomas'],
            'recomendaciones' => $fila['recomendaciones'],
            'diagnostico' => $fila['diagnostico'],
            'medico' => [
                'id' => (int) $fila['id_medico'],
                'primer_nombre' => $fila['medico_primer_nombre'],
                'segundo_nombre' => $fila['medico_segundo_nombre'],
                'apellido_paterno' => $fila['medico_apellido_paterno'],
                'apellido_materno' => $fila['medico_apellido_materno'],
                'telefono' => $fila['medico_telefono'],
                'especialidad' => $fila['medico_especialidad'],
                'email' => $fila['medico_email'],
                'activo' => (bool) $fila['medico_activo'],
            ],
            'paciente' => [
                'id' => (int) $fila['id_paciente'],
                'primer_nombre' => $fila['paciente_primer_nombre'],
                'segundo_nombre' => $fila['paciente_segundo_nombre'],
                'apellido_paterno' => $fila['paciente_apellido_paterno'],
                'apellido_materno' => $fila['paciente_apellido_materno'],
                'telefono' => $fila['paciente_telefono'],
                'activo' => (bool) $fila['paciente_activo'],
            ],
        ];

        if ($fechaColumnaDisponible) {
            $registro['fecha_creacion'] = $fila['fecha_creacion'];
        }

        $consultas[] = $registro;
    }

    $payload = [
        'estado' => true,
        'mensaje' => 'Historial de consultas obtenido correctamente.',
        'consultas' => $consultas,
    ];

    return $response->withJson($payload, 200);
});

$app->get('/medicos', function ($request, $response) {
    $params = $request->getQueryParams();
    $filtroActivo = null;
    if (array_key_exists('activo', $params)) {
        $valor = interpretarBooleano($params['activo']);
        if ($valor !== null) {
            $filtroActivo = $valor;
        }
    }

    /** @var PDO $pdo */
    $pdo = $this->get('db');

    $sql = 'SELECT * FROM medicos';
    $parametros = [];

    if ($filtroActivo !== null) {
        $sql .= ' WHERE activo = :activo';
        $parametros['activo'] = $filtroActivo ? 1 : 0;
    }

    $sql .= ' ORDER BY id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($parametros);
    $filas = $stmt->fetchAll();

    $medicos = array_map('mapearMedico', $filas);

    $payload = [
        'estado' => true,
        'mensaje' => 'Listado de médicos obtenido correctamente.',
        'medicos' => $medicos,
    ];

    return $response->withJson($payload, 200);
});

$app->post('/medicos', function ($request, $response) {
    $data = $request->getParsedBody();
    if (!is_array($data)) {
        $data = [];
    }

    $primerNombre = normalizarCadena($data['primer_nombre'] ?? null);
    $segundoNombre = normalizarCadena($data['segundo_nombre'] ?? null);
    $apellidoPaterno = normalizarCadena($data['apellido_paterno'] ?? null);
    $apellidoMaterno = normalizarCadena($data['apellido_materno'] ?? null);
    $cedula = normalizarCadena($data['cedula'] ?? null);
    $telefono = normalizarCadena($data['telefono'] ?? null);
    $especialidad = normalizarCadena($data['especialidad'] ?? null);
    $email = normalizarCadena($data['email'] ?? null);
    $activo = interpretarBooleano($data['activo'] ?? 1);

    $errores = [];

    if ($primerNombre === null) {
        $errores['primer_nombre'] = 'El primer nombre es obligatorio.';
    }

    if ($apellidoPaterno === null) {
        $errores['apellido_paterno'] = 'El apellido paterno es obligatorio.';
    }

    if ($cedula === null) {
        $errores['cedula'] = 'La cédula es obligatoria.';
    }

    if ($especialidad === null) {
        $errores['especialidad'] = 'La especialidad es obligatoria.';
    }

    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errores['email'] = 'El correo electrónico no es válido.';
    }

    if ($activo === null) {
        $errores['activo'] = 'El estado activo es inválido.';
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
        $stmt = $pdo->prepare('INSERT INTO medicos (primer_nombre, segundo_nombre, apellido_paterno, apellido_materno, cedula, telefono, especialidad, email, activo) VALUES (:primer_nombre, :segundo_nombre, :apellido_paterno, :apellido_materno, :cedula, :telefono, :especialidad, :email, :activo)');
        $stmt->execute([
            'primer_nombre' => $primerNombre,
            'segundo_nombre' => $segundoNombre,
            'apellido_paterno' => $apellidoPaterno,
            'apellido_materno' => $apellidoMaterno,
            'cedula' => $cedula,
            'telefono' => $telefono,
            'especialidad' => $especialidad,
            'email' => $email,
            'activo' => $activo ? 1 : 0,
        ]);

        $medico = obtenerMedico($pdo, (int) $pdo->lastInsertId());

        $payload = [
            'estado' => true,
            'mensaje' => 'Médico creado correctamente.',
            'medico' => mapearMedico($medico),
        ];

        return $response->withJson($payload, 201);
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            $erroresDuplicado = [];
            $detalle = strtolower($exception->getMessage());
            if (strpos($detalle, 'cedula') !== false) {
                $erroresDuplicado['cedula'] = 'La cédula ya está registrada.';
            }
            if (strpos($detalle, 'email') !== false) {
                $erroresDuplicado['email'] = 'El correo electrónico ya está registrado.';
            }

            if (!empty($erroresDuplicado)) {
                $payload = [
                    'estado' => false,
                    'mensaje' => 'No fue posible crear el médico.',
                    'errores' => $erroresDuplicado,
                ];

                return $response->withJson($payload, 409);
            }
        }

        $payload = [
            'estado' => false,
            'mensaje' => 'Error interno al crear el médico.',
        ];

        return $response->withJson($payload, 500);
    }
});

$app->put('/medicos/{id}', function ($request, $response, $args) {
    $id = (int) $args['id'];
    if ($id <= 0) {
        $payload = [
            'estado' => false,
            'mensaje' => 'Identificador inválido.',
        ];

        return $response->withJson($payload, 400);
    }

    $data = $request->getParsedBody();
    if (!is_array($data)) {
        $data = [];
    }

    /** @var PDO $pdo */
    $pdo = $this->get('db');
    $medicoActual = obtenerMedico($pdo, $id);

    if (!$medicoActual) {
        $payload = [
            'estado' => false,
            'mensaje' => 'El médico indicado no existe.',
        ];

        return $response->withJson($payload, 404);
    }

    $primerNombre = array_key_exists('primer_nombre', $data) ? normalizarCadena($data['primer_nombre']) : $medicoActual['primer_nombre'];
    $segundoNombre = array_key_exists('segundo_nombre', $data) ? normalizarCadena($data['segundo_nombre']) : $medicoActual['segundo_nombre'];
    $apellidoPaterno = array_key_exists('apellido_paterno', $data) ? normalizarCadena($data['apellido_paterno']) : $medicoActual['apellido_paterno'];
    $apellidoMaterno = array_key_exists('apellido_materno', $data) ? normalizarCadena($data['apellido_materno']) : $medicoActual['apellido_materno'];
    $cedula = array_key_exists('cedula', $data) ? normalizarCadena($data['cedula']) : $medicoActual['cedula'];
    $telefono = array_key_exists('telefono', $data) ? normalizarCadena($data['telefono']) : $medicoActual['telefono'];
    $especialidad = array_key_exists('especialidad', $data) ? normalizarCadena($data['especialidad']) : $medicoActual['especialidad'];
    $email = array_key_exists('email', $data) ? normalizarCadena($data['email']) : $medicoActual['email'];
    $activo = array_key_exists('activo', $data) ? interpretarBooleano($data['activo']) : (bool) $medicoActual['activo'];

    $errores = [];

    if ($primerNombre === null) {
        $errores['primer_nombre'] = 'El primer nombre es obligatorio.';
    }

    if ($apellidoPaterno === null) {
        $errores['apellido_paterno'] = 'El apellido paterno es obligatorio.';
    }

    if ($cedula === null) {
        $errores['cedula'] = 'La cédula es obligatoria.';
    }

    if ($especialidad === null) {
        $errores['especialidad'] = 'La especialidad es obligatoria.';
    }

    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errores['email'] = 'El correo electrónico no es válido.';
    }

    if ($activo === null) {
        $errores['activo'] = 'El estado activo es inválido.';
    }

    if ($errores) {
        $payload = [
            'estado' => false,
            'mensaje' => 'Datos inválidos.',
            'errores' => $errores,
        ];

        return $response->withJson($payload, 400);
    }

    try {
        $stmt = $pdo->prepare('UPDATE medicos SET primer_nombre = :primer_nombre, segundo_nombre = :segundo_nombre, apellido_paterno = :apellido_paterno, apellido_materno = :apellido_materno, cedula = :cedula, telefono = :telefono, especialidad = :especialidad, email = :email, activo = :activo WHERE id = :id');
        $stmt->execute([
            'primer_nombre' => $primerNombre,
            'segundo_nombre' => $segundoNombre,
            'apellido_paterno' => $apellidoPaterno,
            'apellido_materno' => $apellidoMaterno,
            'cedula' => $cedula,
            'telefono' => $telefono,
            'especialidad' => $especialidad,
            'email' => $email,
            'activo' => $activo ? 1 : 0,
            'id' => $id,
        ]);

        $medicoActualizado = obtenerMedico($pdo, $id);

        $payload = [
            'estado' => true,
            'mensaje' => 'Médico actualizado correctamente.',
            'medico' => mapearMedico($medicoActualizado),
        ];

        return $response->withJson($payload, 200);
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            $erroresDuplicado = [];
            $detalle = strtolower($exception->getMessage());
            if (strpos($detalle, 'cedula') !== false) {
                $erroresDuplicado['cedula'] = 'La cédula ya está registrada.';
            }
            if (strpos($detalle, 'email') !== false) {
                $erroresDuplicado['email'] = 'El correo electrónico ya está registrado.';
            }

            if (!empty($erroresDuplicado)) {
                $payload = [
                    'estado' => false,
                    'mensaje' => 'No fue posible actualizar el médico.',
                    'errores' => $erroresDuplicado,
                ];

                return $response->withJson($payload, 409);
            }
        }

        $payload = [
            'estado' => false,
            'mensaje' => 'Error interno al actualizar el médico.',
        ];

        return $response->withJson($payload, 500);
    }
});

$app->patch('/medicos/{id}/estado', function ($request, $response, $args) {
    $id = (int) $args['id'];
    if ($id <= 0) {
        $payload = [
            'estado' => false,
            'mensaje' => 'Identificador inválido.',
        ];

        return $response->withJson($payload, 400);
    }

    $data = $request->getParsedBody();
    if (!is_array($data)) {
        $data = [];
    }

    if (!array_key_exists('activo', $data)) {
        $payload = [
            'estado' => false,
            'mensaje' => 'Debe indicar el estado activo.',
        ];

        return $response->withJson($payload, 400);
    }

    $activo = interpretarBooleano($data['activo']);

    if ($activo === null) {
        $payload = [
            'estado' => false,
            'mensaje' => 'El estado activo es inválido.',
        ];

        return $response->withJson($payload, 400);
    }

    /** @var PDO $pdo */
    $pdo = $this->get('db');
    $medico = obtenerMedico($pdo, $id);

    if (!$medico) {
        $payload = [
            'estado' => false,
            'mensaje' => 'El médico indicado no existe.',
        ];

        return $response->withJson($payload, 404);
    }

    $stmt = $pdo->prepare('UPDATE medicos SET activo = :activo WHERE id = :id');
    $stmt->execute([
        'activo' => $activo ? 1 : 0,
        'id' => $id,
    ]);

    $medicoActualizado = obtenerMedico($pdo, $id);

    $payload = [
        'estado' => true,
        'mensaje' => $activo ? 'Médico activado correctamente.' : 'Médico desactivado correctamente.',
        'medico' => mapearMedico($medicoActualizado),
    ];

    return $response->withJson($payload, 200);
});

$app->get('/usuarios', function ($request, $response) {
    $params = $request->getQueryParams();
    $filtroActivo = null;
    if (array_key_exists('activo', $params)) {
        $valor = interpretarBooleano($params['activo']);
        if ($valor !== null) {
            $filtroActivo = $valor;
        }
    }

    $filtroMedico = 0;
    foreach (['id_medico', 'medicoId'] as $key) {
        if ($filtroMedico <= 0 && isset($params[$key])) {
            $filtroMedico = (int) $params[$key];
        }
    }

    /** @var PDO $pdo */
    $pdo = $this->get('db');

    $sql = 'SELECT id, correo, nombre_completo, id_medico, activo, fecha_creacion FROM usuario';
    $condiciones = [];
    $parametros = [];

    if ($filtroActivo !== null) {
        $condiciones[] = 'activo = :activo';
        $parametros['activo'] = $filtroActivo ? 1 : 0;
    }

    if ($filtroMedico > 0) {
        $condiciones[] = 'id_medico = :id_medico';
        $parametros['id_medico'] = $filtroMedico;
    }

    if ($condiciones) {
        $sql .= ' WHERE ' . implode(' AND ', $condiciones);
    }

    $sql .= ' ORDER BY id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($parametros);
    $filas = $stmt->fetchAll();

    $usuarios = array_map('mapearUsuario', $filas);

    $payload = [
        'estado' => true,
        'mensaje' => 'Listado de usuarios obtenido correctamente.',
        'usuarios' => $usuarios,
    ];

    return $response->withJson($payload, 200);
});

$app->post('/usuarios', function ($request, $response) {
    $data = $request->getParsedBody();
    if (!is_array($data)) {
        $data = [];
    }

    $correo = normalizarCadena($data['correo'] ?? null);
    $password = $data['password'] ?? null;
    $nombreCompleto = normalizarCadena($data['nombre_completo'] ?? null);

    $idMedico = null;
    foreach (['id_medico', 'medicoId'] as $key) {
        if (array_key_exists($key, $data)) {
            $valor = $data[$key];
            if ($valor === null || $valor === '') {
                $idMedico = null;
            } else {
                $idMedico = (int) $valor;
            }
            break;
        }
    }

    $activo = interpretarBooleano($data['activo'] ?? 1);

    $errores = [];

    if ($correo === null || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $errores['correo'] = 'El correo electrónico es obligatorio y debe ser válido.';
    }

    if ($password === null || trim((string) $password) === '') {
        $errores['password'] = 'La contraseña es obligatoria.';
    }

    if ($activo === null) {
        $errores['activo'] = 'El estado activo es inválido.';
    }

    /** @var PDO $pdo */
    $pdo = $this->get('db');

    if ($idMedico !== null && $idMedico > 0) {
        $medico = obtenerMedico($pdo, $idMedico);
        if (!$medico) {
            $errores['id_medico'] = 'El médico asociado no existe.';
        }
    } else {
        $idMedico = null;
    }

    if ($errores) {
        $payload = [
            'estado' => false,
            'mensaje' => 'Datos inválidos.',
            'errores' => $errores,
        ];

        return $response->withJson($payload, 400);
    }

    $hash = password_hash((string) $password, PASSWORD_DEFAULT);

    try {
        $stmt = $pdo->prepare('INSERT INTO usuario (correo, password, nombre_completo, id_medico, activo) VALUES (:correo, :password, :nombre_completo, :id_medico, :activo)');
        $stmt->execute([
            'correo' => $correo,
            'password' => $hash,
            'nombre_completo' => $nombreCompleto,
            'id_medico' => $idMedico,
            'activo' => $activo ? 1 : 0,
        ]);

        $usuario = obtenerUsuario($pdo, (int) $pdo->lastInsertId());

        $payload = [
            'estado' => true,
            'mensaje' => 'Usuario creado correctamente.',
            'usuario' => mapearUsuario($usuario),
        ];

        return $response->withJson($payload, 201);
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            $payload = [
                'estado' => false,
                'mensaje' => 'El correo electrónico ya está registrado.',
                'errores' => ['correo' => 'El correo electrónico ya está registrado.'],
            ];

            return $response->withJson($payload, 409);
        }

        $payload = [
            'estado' => false,
            'mensaje' => 'Error interno al crear el usuario.',
        ];

        return $response->withJson($payload, 500);
    }
});

$app->put('/usuarios/{id}', function ($request, $response, $args) {
    $id = (int) $args['id'];
    if ($id <= 0) {
        $payload = [
            'estado' => false,
            'mensaje' => 'Identificador inválido.',
        ];

        return $response->withJson($payload, 400);
    }

    $data = $request->getParsedBody();
    if (!is_array($data)) {
        $data = [];
    }

    /** @var PDO $pdo */
    $pdo = $this->get('db');
    $usuarioActual = obtenerUsuario($pdo, $id);

    if (!$usuarioActual) {
        $payload = [
            'estado' => false,
            'mensaje' => 'El usuario indicado no existe.',
        ];

        return $response->withJson($payload, 404);
    }

    $correo = array_key_exists('correo', $data) ? normalizarCadena($data['correo']) : $usuarioActual['correo'];
    $password = array_key_exists('password', $data) ? $data['password'] : null;
    $nombreCompleto = array_key_exists('nombre_completo', $data) ? normalizarCadena($data['nombre_completo']) : $usuarioActual['nombre_completo'];

    $idMedico = $usuarioActual['id_medico'];
    foreach (['id_medico', 'medicoId'] as $key) {
        if (array_key_exists($key, $data)) {
            $valor = $data[$key];
            if ($valor === null || $valor === '') {
                $idMedico = null;
            } else {
                $idMedico = (int) $valor;
            }
            break;
        }
    }

    $activo = array_key_exists('activo', $data) ? interpretarBooleano($data['activo']) : (bool) $usuarioActual['activo'];

    $errores = [];

    if ($correo === null || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $errores['correo'] = 'El correo electrónico es obligatorio y debe ser válido.';
    }

    if ($activo === null) {
        $errores['activo'] = 'El estado activo es inválido.';
    }

    /** @var PDO $pdo */
    $pdo = $this->get('db');

    if ($idMedico !== null && $idMedico > 0) {
        $medico = obtenerMedico($pdo, $idMedico);
        if (!$medico) {
            $errores['id_medico'] = 'El médico asociado no existe.';
        }
    } else {
        $idMedico = null;
    }

    if ($errores) {
        $payload = [
            'estado' => false,
            'mensaje' => 'Datos inválidos.',
            'errores' => $errores,
        ];

        return $response->withJson($payload, 400);
    }

    $campos = [
        'correo' => $correo,
        'nombre_completo' => $nombreCompleto,
        'id_medico' => $idMedico,
        'activo' => $activo ? 1 : 0,
        'id' => $id,
    ];

    $sql = 'UPDATE usuario SET correo = :correo, nombre_completo = :nombre_completo, id_medico = :id_medico, activo = :activo';

    if ($password !== null && trim((string) $password) !== '') {
        $sql .= ', password = :password';
        $campos['password'] = password_hash((string) $password, PASSWORD_DEFAULT);
    }

    $sql .= ' WHERE id = :id';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($campos);

        $usuarioActualizado = obtenerUsuario($pdo, $id);

        $payload = [
            'estado' => true,
            'mensaje' => 'Usuario actualizado correctamente.',
            'usuario' => mapearUsuario($usuarioActualizado),
        ];

        return $response->withJson($payload, 200);
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            $payload = [
                'estado' => false,
                'mensaje' => 'El correo electrónico ya está registrado.',
                'errores' => ['correo' => 'El correo electrónico ya está registrado.'],
            ];

            return $response->withJson($payload, 409);
        }

        $payload = [
            'estado' => false,
            'mensaje' => 'Error interno al actualizar el usuario.',
        ];

        return $response->withJson($payload, 500);
    }
});

$app->patch('/usuarios/{id}/estado', function ($request, $response, $args) {
    $id = (int) $args['id'];
    if ($id <= 0) {
        $payload = [
            'estado' => false,
            'mensaje' => 'Identificador inválido.',
        ];

        return $response->withJson($payload, 400);
    }

    $data = $request->getParsedBody();
    if (!is_array($data)) {
        $data = [];
    }

    if (!array_key_exists('activo', $data)) {
        $payload = [
            'estado' => false,
            'mensaje' => 'Debe indicar el estado activo.',
        ];

        return $response->withJson($payload, 400);
    }

    $activo = interpretarBooleano($data['activo']);

    if ($activo === null) {
        $payload = [
            'estado' => false,
            'mensaje' => 'El estado activo es inválido.',
        ];

        return $response->withJson($payload, 400);
    }

    /** @var PDO $pdo */
    $pdo = $this->get('db');
    $usuario = obtenerUsuario($pdo, $id);

    if (!$usuario) {
        $payload = [
            'estado' => false,
            'mensaje' => 'El usuario indicado no existe.',
        ];

        return $response->withJson($payload, 404);
    }

    $stmt = $pdo->prepare('UPDATE usuario SET activo = :activo WHERE id = :id');
    $stmt->execute([
        'activo' => $activo ? 1 : 0,
        'id' => $id,
    ]);

    $usuarioActualizado = obtenerUsuario($pdo, $id);

    $payload = [
        'estado' => true,
        'mensaje' => $activo ? 'Usuario activado correctamente.' : 'Usuario desactivado correctamente.',
        'usuario' => mapearUsuario($usuarioActualizado),
    ];

    return $response->withJson($payload, 200);
});

$app->run();
