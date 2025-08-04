<?php
set_time_limit(600);
ini_set('max_execution_time', 600);

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    error_log("Training request received - Session ID: " . session_id());

    if (!isset($_SESSION['cleaned_csv'])) {
        throw new Exception('No cleaned CSV file found in session');
    }

    $csvFilePath = $_SESSION['cleaned_csv'];
    error_log("CSV file path: " . $csvFilePath);

    if (!file_exists($csvFilePath)) {
        throw new Exception('CSV file does not exist at path: ' . $csvFilePath);
    }

    if (!is_readable($csvFilePath)) {
        throw new Exception('CSV file is not readable: ' . $csvFilePath);
    }

    $fileName = basename($csvFilePath);
    $fileSize = filesize($csvFilePath);
    error_log("File details - Name: $fileName, Size: $fileSize bytes");

    $healthCheck = curl_init();
    curl_setopt_array($healthCheck, [
        CURLOPT_URL => 'http://localhost:5000/health',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5
    ]);

    $healthResponse = curl_exec($healthCheck);
    $healthHttpCode = curl_getinfo($healthCheck, CURLINFO_HTTP_CODE);
    $healthError = curl_error($healthCheck);
    curl_close($healthCheck);

    if ($healthError || $healthHttpCode !== 200) {
        throw new Exception('Python API is not responding. Error: ' . ($healthError ?: 'HTTP ' . $healthHttpCode));
    }

    error_log("Python API health check passed");

    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => 'http://localhost:5000/train',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'file' => new CURLFile($csvFilePath, 'text/csv', $fileName)
        ],
        CURLOPT_TIMEOUT => 600, 
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json'
        ],
        CURLOPT_VERBOSE => false,
        CURLOPT_NOPROGRESS => true,
        CURLOPT_NOSIGNAL => true
    ]);

    error_log("Sending training request to Python API");
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    error_log("Python API response - HTTP Code: $httpCode, Error: " . ($error ?: 'none'));

    if ($error) {
        throw new Exception('cURL Error: ' . $error);
    }

    if ($httpCode !== 200) {
        error_log("API response body: " . substr($response, 0, 500));
        throw new Exception('API returned HTTP code: ' . $httpCode . '. Response: ' . substr($response, 0, 200));
    }

    $jsonResponse = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON response from API: ' . json_last_error_msg());
    }

    error_log("Training completed successfully");

    echo $response;

} catch (Exception $e) {
    error_log("Training error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug' => [
            'session_has_csv' => isset($_SESSION['cleaned_csv']),
            'csv_path' => $_SESSION['cleaned_csv'] ?? 'not set',
            'file_exists' => isset($_SESSION['cleaned_csv']) ? file_exists($_SESSION['cleaned_csv']) : false,
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);
}
?>