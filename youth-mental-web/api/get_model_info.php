<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ADMIN') {
        throw new Exception('Unauthorized access');
    }

    error_log("Getting model information...");

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
        throw new Exception('Python AI API is not responding. Please ensure the model server is running.');
    }

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => 'http://localhost:5000/models',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json'
        ]
    ]);

    error_log("Sending model info request to Python API");
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    error_log("Python API model info response - $response, HTTP Code: $httpCode, Error: " . ($error ?: 'none'));

    if ($error) {
        throw new Exception('Connection error to AI API: ' . $error);
    }

    if ($httpCode !== 200) {
        error_log("API response body: " . substr($response, 0, 500));
        throw new Exception('AI API returned error. HTTP code: ' . $httpCode . '. Response: ' . substr($response, 0, 200));
    }

    $jsonResponse = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON response from AI API: ' . json_last_error_msg());
    }

    error_log("Model info retrieved successfully");

    echo $response;

} catch (Exception $e) {
    error_log("Model info error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug' => [
            'timestamp' => date('Y-m-d H:i:s'),
            'user_id' => $_SESSION['user_id'] ?? 'not set',
            'user_role' => $_SESSION['role'] ?? 'not set'
        ]
    ]);
}
?>