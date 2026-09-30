<?php
function stageUploadedXml($field, $expectedRoot, $targetName, $expectedKind = '') {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Не удалось загрузить один из XML-файлов');
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($_FILES[$field]['tmp_name'], 'SimpleXMLElement', LIBXML_NONET);
    if ($xml === false || $xml->getName() !== $expectedRoot) {
        throw new RuntimeException("Файл $field имеет неверный формат");
    }
    if ($expectedKind !== '' && (string)$xml['kind'] !== $expectedKind) {
        throw new RuntimeException("Файл $field имеет неверное назначение");
    }

    $uploadDir = __DIR__ . '/uploads';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        throw new RuntimeException('Не удалось создать папку uploads');
    }

    $target = $uploadDir . DIRECTORY_SEPARATOR . $targetName;
    $staged = $target . '.new.' . bin2hex(random_bytes(4));
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $staged)) {
        throw new RuntimeException("Не удалось сохранить файл $field");
    }

    return ['staged' => $staged, 'target' => $target, 'name' => $targetName, 'variant_id' => (string)$xml['variant_id'], 'xml' => $xml];
}

function validateBundle($tasks, $key, $answers) {
    $taskMap = [];
    foreach ($tasks->task as $task) {
        $id = (string)$task->id;
        $number = (int)$task->number;
        if ($id === '' || isset($taskMap[$id])) {
            throw new RuntimeException('tasks.xml содержит пустые или повторяющиеся task_id');
        }
        $taskMap[$id] = $number;
    }

    $keyIds = [];
    foreach ($key->answer as $answer) {
        $id = (string)$answer['task_id'];
        if (!isset($taskMap[$id]) || isset($keyIds[$id]) || (int)$answer['number'] !== $taskMap[$id]) {
            throw new RuntimeException('answer_key.xml не соответствует списку заданий');
        }
        $keyIds[$id] = true;
    }
    if (count($keyIds) !== count($taskMap)) {
        throw new RuntimeException('answer_key.xml содержит не все ответы');
    }

    $studentIds = [];
    foreach ($answers->answer as $answer) {
        $id = (string)$answer['task_id'];
        if (!isset($taskMap[$id]) || isset($studentIds[$id]) || (int)$answer['number'] !== $taskMap[$id]) {
            throw new RuntimeException('answers.xml содержит неизвестные или повторяющиеся задания');
        }
        $studentIds[$id] = true;
    }
}

function installStagedFiles(array $files) {
    $backups = [];
    $installed = [];
    try {
        foreach ($files as $file) {
            if (is_file($file['target'])) {
                $backup = $file['target'] . '.bak.' . bin2hex(random_bytes(4));
                if (!rename($file['target'], $backup)) {
                    throw new RuntimeException('Не удалось подготовить замену загруженных файлов');
                }
                $backups[$file['target']] = $backup;
            }
        }
        foreach ($files as $file) {
            if (!rename($file['staged'], $file['target'])) {
                throw new RuntimeException('Не удалось установить загруженные файлы');
            }
            $installed[] = $file['target'];
        }
        foreach ($backups as $backup) {
            @unlink($backup);
        }
    } catch (Throwable $error) {
        foreach ($installed as $target) {
            @unlink($target);
        }
        foreach ($backups as $target => $backup) {
            @rename($backup, $target);
        }
        foreach ($files as $file) {
            @unlink($file['staged']);
        }
        throw $error;
    }
}

function uploadedPath($queryName) {
    $name = isset($_GET[$queryName]) ? basename($_GET[$queryName]) : '';
    $expectedNames = [
        'tasks' => 'tasks.xml',
        'key' => 'answer_key.xml',
        'answers' => 'answers.xml',
    ];
    $expectedName = $expectedNames[$queryName] ?? '';
    if ($name !== $expectedName) {
        return '';
    }

    $path = __DIR__ . '/uploads/' . $name;
    return is_file($path) ? $path : '';
}

// Обработка загрузки файлов
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['tasksFile'], $_FILES['keyFile'], $_FILES['answersFile'])) {
        try {
            $stagedFiles = [
                stageUploadedXml('tasksFile', 'tasks', 'tasks.xml'),
                stageUploadedXml('keyFile', 'answers', 'answer_key.xml', 'key'),
                stageUploadedXml('answersFile', 'answers', 'answers.xml', 'student'),
            ];
            $variantIds = array_column($stagedFiles, 'variant_id');
            if (in_array('', $variantIds, true) || count(array_unique($variantIds)) !== 1) {
                foreach ($stagedFiles as $file) {
                    @unlink($file['staged']);
                }
                throw new RuntimeException('Выбранные XML-файлы относятся к разным вариантам');
            }
            validateBundle($stagedFiles[0]['xml'], $stagedFiles[1]['xml'], $stagedFiles[2]['xml']);
            installStagedFiles($stagedFiles);
            $tasksName = $stagedFiles[0]['name'];
            $keyName = $stagedFiles[1]['name'];
            $answersName = $stagedFiles[2]['name'];
            header('Location: ' . $_SERVER['PHP_SELF'] . '?tasks=' . urlencode($tasksName) . '&key=' . urlencode($keyName) . '&answers=' . urlencode($answersName));
            exit;
        } catch (Throwable $error) {
            foreach (glob(__DIR__ . '/uploads/*.new.*') ?: [] as $temporaryUpload) {
                @unlink($temporaryUpload);
            }
            $uploadError = $error->getMessage();
        }
    }

    // Обработка выгрузки результатов
    if (isset($_POST['exportResults'])) {
        exportResults();
        exit;
    }
}

// Функция для выгрузки результатов
function exportResults() {
    $tasksFilePath = uploadedPath('tasks');
    $keyFilePath = uploadedPath('key');
    $answersFilePath = uploadedPath('answers');

    // Загружаем данные
    list($tasksArray, $answersMap, $stats) = loadData($tasksFilePath, $keyFilePath, $answersFilePath);

    // Генерируем HTML для выгрузки
    $html = generateExportHTML($tasksArray, $answersMap, $stats, $tasksFilePath, $answersFilePath);

    // Отправляем файл для скачивания
    header('Content-Type: text/html');
    header('Content-Disposition: attachment; filename="results_' . date('Y-m-d_H-i-s') . '.html"');
    echo $html;
    exit;
}

// Функция для загрузки данных
function loadData($tasksFilePath, $keyFilePath, $answersFilePath) {
    function loadXmlFile($filename) {
        if (!file_exists($filename)) {
            return new SimpleXMLElement('<?xml version="1.0"?><empty></empty>');
        }

        $content = file_get_contents($filename);
        if (empty($content)) {
            return new SimpleXMLElement('<?xml version="1.0"?><empty></empty>');
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NONET);
        if ($xml === false) {
            return new SimpleXMLElement('<?xml version="1.0"?><empty></empty>');
        }

        return $xml;
    }

    $tasks = loadXmlFile($tasksFilePath);
    $key = loadXmlFile($keyFilePath);
    $answers = loadXmlFile($answersFilePath);

    $tasksArray = [];
    $answersMap = [];

    foreach ($tasks->task as $task) {
        $taskId = (string)$task->id;
        $tasksArray[$taskId] = [
            'number' => (int)$task->number,
            'title' => (string)$task->title,
            'answer_type' => (string)$task->answer_type,
            'correct_answer' => '',
            'table_rows' => isset($task->table_rows) ? (int)$task->table_rows : null,
            'table_columns' => isset($task->table_columns) ? (int)$task->table_columns : null
        ];
    }

    foreach ($key->answer as $answer) {
        $taskId = (string)$answer['task_id'];
        if (isset($tasksArray[$taskId])) {
            $tasksArray[$taskId]['correct_answer'] = (string)$answer->value;
        }
    }

    foreach ($answers->answer as $answer) {
        $taskID = (string)$answer['task_id'];
        $answersMap[$taskID] = [
            'number' => (int)$answer['number'],
            'value' => (string)$answer->value
        ];
    }

    uasort($tasksArray, function($a, $b) {
        return $a['number'] - $b['number'];
    });

    // Подсчет статистики
    $totalTasks = count($tasksArray);
    $answered = 0;
    $correct = 0;

    foreach ($tasksArray as $taskID => $task) {
        if (isset($answersMap[$taskID]) && !empty(trim($answersMap[$taskID]['value']))) {
            $answered++;
            if (checkAnswer($task['correct_answer'], $answersMap[$taskID]['value'], $task['answer_type'])) {
                $correct++;
            }
        }
    }

    $percentage = $totalTasks > 0 ? round(($correct / $totalTasks) * 100, 1) : 0;

    $stats = [
        'totalTasks' => $totalTasks,
        'answered' => $answered,
        'correct' => $correct,
        'percentage' => $percentage
    ];

    return [$tasksArray, $answersMap, $stats];
}

// Функция для проверки ответов
function checkAnswer($correctAnswer, $userAnswer, $answerType) {
    if (empty($userAnswer)) {
        return false;
    }

    if ($answerType === 'table') {
        $correct = array_map('trim', explode(';', $correctAnswer));
        $user = array_map('trim', explode(';', $userAnswer));

        if (count($correct) !== count($user)) {
            return false;
        }

        foreach ($correct as $index => $value) {
            if ($value !== ($user[$index] ?? '')) {
                return false;
            }
        }
        return true;
    } else {
        return trim($correctAnswer) === trim($userAnswer);
    }
}

// Функция для генерации HTML для выгрузки
function generateExportHTML($tasksArray, $answersMap, $stats, $tasksFilePath, $answersFilePath) {
    ob_start();
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Результаты тестирования - <?= date('d.m.Y H:i:s') ?></title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f5f7fa;
            color: #333;
            line-height: 1.6;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
            padding: 30px;
        }

        h1 {
            text-align: center;
            margin-bottom: 30px;
            color: #2c3e50;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
        }

        .header-info {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #3498db;
        }

        .header-info p {
            margin: 5px 0;
            font-size: 14px;
        }

        .stats {
            display: flex;
            justify-content: space-around;
            margin-bottom: 30px;
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .stat-item {
            text-align: center;
        }

        .stat-number {
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 14px;
            opacity: 0.9;
        }

        .results-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 14px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .results-table th,
        .results-table td {
            padding: 12px;
            text-align: left;
            border: 1px solid #ddd;
        }

        .results-table th {
            background-color: #2c3e50;
            color: white;
            position: sticky;
            top: 0;
            font-weight: 600;
        }

        .results-table tr:nth-child(even) {
            background-color: #f8f9fa;
        }

        .correct {
            background-color: #d4edda !important;
            color: #155724;
        }

        .incorrect {
            background-color: #f8d7da !important;
            color: #721c24;
        }

        .no-answer {
            background-color: #fff3cd !important;
            color: #856404;
        }

        .table-answer {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .score-circle {
            display: inline-block;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            text-align: center;
            line-height: 30px;
            font-weight: bold;
            font-size: 16px;
        }

        .score-correct {
            background-color: #28a745;
            color: white;
        }

        .score-incorrect {
            background-color: #dc3545;
            color: white;
        }

        .legend {
            margin-top: 30px;
            padding: 20px;
            background: #e9ecef;
            border-radius: 8px;
            text-align: center;
        }

        .legend-items {
            display: flex;
            justify-content: center;
            gap: 30px;
            margin-top: 10px;
            flex-wrap: wrap;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .legend-color {
            width: 20px;
            height: 20px;
            border-radius: 3px;
            border: 1px solid #ccc;
        }

        .footer {
            margin-top: 40px;
            text-align: center;
            padding: 20px;
            border-top: 1px solid #ddd;
            color: #6c757d;
            font-size: 12px;
        }

        @media print {
            body {
                padding: 0;
                background: white;
            }

            .container {
                box-shadow: none;
                border-radius: 0;
                padding: 15px;
            }

            .stats {
                box-shadow: none;
                break-inside: avoid;
            }

            .results-table {
                break-inside: avoid;
            }
        }

        @media (max-width: 768px) {
            .stats {
                flex-direction: column;
                gap: 15px;
            }

            .results-table {
                font-size: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📊 Результаты тестирования</h1>

        <div class="header-info">
            <p><strong>Дата генерации:</strong> <?= date('d.m.Y H:i:s') ?></p>
            <p><strong>Файл с задачами:</strong> <?= htmlspecialchars(basename($tasksFilePath)) ?></p>
            <p><strong>Файл с ответами:</strong> <?= htmlspecialchars(basename($answersFilePath)) ?></p>
        </div>

        <div class="stats">
            <div class="stat-item">
                <div class="stat-number"><?= $stats['totalTasks'] ?></div>
                <div class="stat-label">Всего заданий</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?= $stats['answered'] ?></div>
                <div class="stat-label">Выполнено</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?= $stats['correct'] ?></div>
                <div class="stat-label">Правильно</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?= $stats['percentage'] ?>%</div>
                <div class="stat-label">Результат</div>
            </div>
        </div>

        <table class="results-table">
            <thead>
                <tr>
                    <th>№</th>
                    <th>Задание</th>
                    <th>Тип ответа</th>
                    <th>Правильный ответ</th>
                    <th>Дан ответ</th>
                    <th>Результат</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tasksArray as $taskID => $task): ?>
                    <?php
                    $userAnswer = isset($answersMap[$taskID]) ? $answersMap[$taskID]['value'] : '';
                    $isAnswered = !empty(trim($userAnswer));
                    $isCorrect = $isAnswered ? checkAnswer($task['correct_answer'], $userAnswer, $task['answer_type']) : false;

                    $rowClass = '';
                    if ($isAnswered) {
                        $rowClass = $isCorrect ? 'correct' : 'incorrect';
                    } else {
                        $rowClass = 'no-answer';
                    }
                    ?>

                    <tr class="<?= $rowClass ?>">
                        <td><strong><?= $task['number'] ?></strong></td>
                        <td><?= htmlspecialchars($task['title']) ?></td>
                        <td>
                            <?= $task['answer_type'] ?>
                            <?php if ($task['answer_type'] === 'table'): ?>
                                (<?= $task['table_rows'] ?>×<?= $task['table_columns'] ?>)
                            <?php endif; ?>
                        </td>
                        <td class="table-answer">
                            <?php if ($task['answer_type'] === 'table'): ?>
                                <?php
                                $correctValues = explode(';', $task['correct_answer']);
                                echo '[' . implode('; ', $correctValues) . ']';
                                ?>
                            <?php else: ?>
                                <?= htmlspecialchars($task['correct_answer']) ?>
                            <?php endif; ?>
                        </td>
                        <td class="table-answer">
                            <?php if ($isAnswered): ?>
                                <?php if ($task['answer_type'] === 'table'): ?>
                                    <?php
                                    $userValues = explode(';', $userAnswer);
                                    echo '[' . implode('; ', $userValues) . ']';
                                    ?>
                                <?php else: ?>
                                    <?= htmlspecialchars($userAnswer) ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <em>Нет ответа</em>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isAnswered): ?>
                                <span class="score-circle <?= $isCorrect ? 'score-correct' : 'score-incorrect' ?>">
                                    <?= $isCorrect ? '✓' : '✗' ?>
                                </span>
                            <?php else: ?>
                                <span>-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="legend">
            <h3>Легенда</h3>
            <div class="legend-items">
                <div class="legend-item">
                    <div class="legend-color" style="background-color: #d4edda;"></div>
                    <span>Правильный ответ</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background-color: #f8d7da;"></div>
                    <span>Неправильный ответ</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background-color: #fff3cd;"></div>
                    <span>Нет ответа</span>
                </div>
            </div>
        </div>

        <div class="footer">
            <p>Сгенерировано автоматически • <?= date('d.m.Y H:i:s') ?></p>
            <p>Для печати используйте комбинацию Ctrl+P</p>
        </div>
    </div>
</body>
</html>
    <?php
    return ob_get_clean();
}

// Получаем пути к файлам из параметров или используем по умолчанию
$tasksFilePath = uploadedPath('tasks');
$keyFilePath = uploadedPath('key');
$answersFilePath = uploadedPath('answers');

// Загружаем данные для отображения на странице
list($tasksArray, $answersMap, $stats) = loadData($tasksFilePath, $keyFilePath, $answersFilePath);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Результаты тестирования</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f5f7fa;
            color: #333;
            line-height: 1.6;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
            padding: 30px;
        }

        h1 {
            text-align: center;
            margin-bottom: 30px;
            color: #2c3e50;
        }

        .upload-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            border: 2px dashed #dee2e6;
        }

        .upload-form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .file-input-group {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .file-input-group label {
            min-width: 120px;
            font-weight: 600;
        }

        input[type="file"] {
            flex: 1;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        button {
            background: #3498db;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: background 0.3s;
            align-self: flex-start;
        }

        button:hover {
            background: #2980b9;
        }

        .current-files {
            margin-top: 15px;
            padding: 15px;
            background: #e9ecef;
            border-radius: 4px;
            font-size: 14px;
        }

        .stats {
            display: flex;
            justify-content: space-around;
            margin-bottom: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .stat-item {
            text-align: center;
        }

        .stat-number {
            font-size: 24px;
            font-weight: bold;
            color: #3498db;
        }

        .stat-label {
            font-size: 14px;
            color: #7f8c8d;
        }

        .results-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 14px;
        }

        .results-table th,
        .results-table td {
            padding: 12px;
            text-align: left;
            border: 1px solid #ddd;
        }

        .results-table th {
            background-color: #34495e;
            color: white;
            position: sticky;
            top: 0;
        }

        .results-table tr:nth-child(even) {
            background-color: #f8f9fa;
        }

        .results-table tr:hover {
            background-color: #e9ecef;
        }

        .correct {
            background-color: #d4edda !important;
            color: #155724;
        }

        .incorrect {
            background-color: #f8d7da !important;
            color: #721c24;
        }

        .no-answer {
            background-color: #fff3cd !important;
            color: #856404;
        }

        .table-answer {
            font-family: monospace;
            font-size: 12px;
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .score-circle {
            display: inline-block;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            text-align: center;
            line-height: 30px;
            font-weight: bold;
        }

        .score-correct {
            background-color: #28a745;
            color: white;
        }

        .score-incorrect {
            background-color: #dc3545;
            color: white;
        }

        .file-info {
            background: #e3f2fd;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 10px;
            font-size: 14px;
        }

        @media (max-width: 768px) {
            .stats {
                flex-direction: column;
                gap: 15px;
            }

            .results-table {
                font-size: 12px;
            }

            .results-table th,
            .results-table td {
                padding: 8px;
            }
        }

        .export-section {
            background: #e3f2fd;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #2196f3;
        }

        .export-buttons {
            display: flex;
            gap: 15px;
            margin-top: 10px;
            flex-wrap: wrap;
        }

        .export-btn {
            background: #4caf50;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: background 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .export-btn:hover {
            background: #45a049;
        }

        .print-btn {
            background: #ff9800;
        }

        .print-btn:hover {
            background: #f57c00;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Результаты тестирования</h1>

        <!-- Форма загрузки файлов -->
        <div class="upload-section">
            <h3>Загрузить XML файлы</h3>
            <?php if (!empty($uploadError)): ?>
                <p style="color: #b00020;"><?= htmlspecialchars($uploadError) ?></p>
            <?php endif; ?>
            <form class="upload-form" method="POST" enctype="multipart/form-data">
                <div class="file-input-group">
                    <label for="tasksFile">Задачи:</label>
                    <input type="file" id="tasksFile" name="tasksFile" accept=".xml" required>
                </div>

                <div class="file-input-group">
                    <label for="keyFile">Ключ ответов:</label>
                    <input type="file" id="keyFile" name="keyFile" accept=".xml" required>
                </div>

                <div class="file-input-group">
                    <label for="answersFile">Ответы:</label>
                    <input type="file" id="answersFile" name="answersFile" accept=".xml" required>
                </div>

                <button type="submit">Загрузить и показать результаты</button>
            </form>

            <div class="current-files">
                <strong>Текущие файлы:</strong><br>
                Задачи: <?= htmlspecialchars(basename($tasksFilePath)) ?><br>
                Ключ: <?= htmlspecialchars(basename($keyFilePath)) ?><br>
                Ответы: <?= htmlspecialchars(basename($answersFilePath)) ?>
            </div>
        </div>

        <!-- Секция выгрузки результатов -->
        <div class="export-section">
            <h3>Экспорт результатов</h3>
            <p>Сохраните результаты в виде отдельного HTML-файла для печати или архивации</p>
            <div class="export-buttons">
                <form method="POST">
                    <button type="submit" name="exportResults" class="export-btn">
                        📥 Выгрузить HTML
                    </button>
                </form>
                <button onclick="window.print()" class="export-btn print-btn">
                    🖨️ Печать
                </button>
            </div>
        </div>

        <div class="file-info">
            <strong>Информация о файлах:</strong><br>
            Загружено задач: <?= $stats['totalTasks'] ?><br>
            Файл задач: <?= htmlspecialchars(basename($tasksFilePath)) ?><br>
            Ключ ответов: <?= htmlspecialchars(basename($keyFilePath)) ?><br>
            Файл ответов: <?= htmlspecialchars(basename($answersFilePath)) ?>
        </div>

        <div class="stats">
            <div class="stat-item">
                <div class="stat-number"><?= $stats['totalTasks'] ?></div>
                <div class="stat-label">Всего заданий</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?= $stats['answered'] ?></div>
                <div class="stat-label">Выполнено</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?= $stats['correct'] ?></div>
                <div class="stat-label">Правильно</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?= $stats['percentage'] ?>%</div>
                <div class="stat-label">Результат</div>
            </div>
        </div>

        <?php if ($stats['totalTasks'] > 0): ?>
            <table class="results-table">
                <thead>
                    <tr>
                        <th>№</th>
                        <th>Задание</th>
                        <th>Тип ответа</th>
                        <th>Правильный ответ</th>
                        <th>Дан ответ</th>
                        <th>Результат</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tasksArray as $taskID => $task): ?>
                        <?php
                        $userAnswer = isset($answersMap[$taskID]) ? $answersMap[$taskID]['value'] : '';
                        $isAnswered = !empty(trim($userAnswer));
                        $isCorrect = $isAnswered ? checkAnswer($task['correct_answer'], $userAnswer, $task['answer_type']) : false;

                        $rowClass = '';
                        if ($isAnswered) {
                            $rowClass = $isCorrect ? 'correct' : 'incorrect';
                        } else {
                            $rowClass = 'no-answer';
                        }
                        ?>

                        <tr class="<?= $rowClass ?>">
                            <td><?= $task['number'] ?></td>
                            <td><?= htmlspecialchars($task['title']) ?></td>
                            <td>
                                <?= $task['answer_type'] ?>
                                <?php if ($task['answer_type'] === 'table'): ?>
                                    (<?= $task['table_rows'] ?>×<?= $task['table_columns'] ?>)
                                <?php endif; ?>
                            </td>
                            <td class="table-answer" title="<?= htmlspecialchars($task['correct_answer']) ?>">
                                <?php if ($task['answer_type'] === 'table'): ?>
                                    <?php
                                    $correctValues = explode(';', $task['correct_answer']);
                                    echo '[' . implode('; ', $correctValues) . ']';
                                    ?>
                                <?php else: ?>
                                    <?= htmlspecialchars($task['correct_answer']) ?>
                                <?php endif; ?>
                            </td>
                            <td class="table-answer" title="<?= htmlspecialchars($userAnswer) ?>">
                                <?php if ($isAnswered): ?>
                                    <?php if ($task['answer_type'] === 'table'): ?>
                                        <?php
                                        $userValues = explode(';', $userAnswer);
                                        echo '[' . implode('; ', $userValues) . ']';
                                        ?>
                                    <?php else: ?>
                                        <?= htmlspecialchars($userAnswer) ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <em>Нет ответа</em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isAnswered): ?>
                                    <span class="score-circle <?= $isCorrect ? 'score-correct' : 'score-incorrect' ?>">
                                        <?= $isCorrect ? '✓' : '✗' ?>
                                    </span>
                                <?php else: ?>
                                    <span>-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div style="margin-top: 30px; text-align: center;">
                <h3>Легенда:</h3>
                <div style="display: flex; justify-content: center; gap: 20px; margin-top: 10px; flex-wrap: wrap;">
                    <div style="display: flex; align-items: center; gap: 5px;">
                        <div style="width: 20px; height: 20px; background-color: #d4edda; border: 1px solid #c3e6cb;"></div>
                        <span>Правильный ответ</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 5px;">
                        <div style="width: 20px; height: 20px; background-color: #f8d7da; border: 1px solid #f5c6cb;"></div>
                        <span>Неправильный ответ</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 5px;">
                        <div style="width: 20px; height: 20px; background-color: #fff3cd; border: 1px solid #ffeaa7;"></div>
                        <span>Нет ответа</span>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div style="text-align: center; padding: 40px; color: #6c757d;">
                <h3>Нет данных для отображения</h3>
                <p>Загрузите XML файлы с задачами и ответами</p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
