<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ADMIN') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Cache-Control');

if (!isset($_SESSION['cleaned_csv']) || !file_exists($_SESSION['cleaned_csv'])) {
    echo "data: " . json_encode(['error' => 'No CSV file found in session']) . "\n\n";
    exit;
}

try {
    $csvFilePath = $_SESSION['cleaned_csv'];
    $apiUrl = 'http://localhost:5000/train-stream';

    $postData = [
        'file' => new CURLFile($csvFilePath, 'text/csv', 'training_data.csv')
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) {
        echo $data;
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
        return strlen($data);
    });
    curl_setopt($ch, CURLOPT_TIMEOUT, 0);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: text/event-stream',
        'Cache-Control: no-cache'
    ]);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($result === false || $httpCode !== 200 || $error) {
        echo "data: " . json_encode(['error' => "Connection failed: HTTP $httpCode - $error"]) . "\n\n";
    }

} catch (Exception $e) {
    echo "data: " . json_encode(['error' => 'Server error: ' . $e->getMessage()]) . "\n\n";
}
?>