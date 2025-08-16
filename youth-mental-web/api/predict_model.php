<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ADMIN') {
        throw new Exception('Unauthorized access');
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        throw new Exception('No input data provided');
    }

    $required_fields = ['Age', 'Hours_of_Screen_Time', 'Hours_of_Sleep', 'Daily_Study_Hours', 'Physical_Activity', 'Mental_Clarity_Score'];
    foreach ($required_fields as $field) {
        if (!isset($input[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }

    foreach ($required_fields as $field) {
        if (!is_numeric($input[$field])) {
            throw new Exception("$field must be a numeric value");
        }
    }

    if ($input['Age'] < 13 || $input['Age'] > 25) {
        throw new Exception("Age must be between 13 and 25 years");
    }
    if ($input['Hours_of_Screen_Time'] < 0 || $input['Hours_of_Screen_Time'] > 24) {
        throw new Exception("Screen time must be between 0 and 24 hours");
    }
    if ($input['Hours_of_Sleep'] < 0 || $input['Hours_of_Sleep'] > 16) {
        throw new Exception("Sleep hours must be between 0 and 16 hours");
    }
    if ($input['Daily_Study_Hours'] < 0 || $input['Daily_Study_Hours'] > 16) {
        throw new Exception("Study hours must be between 0 and 16 hours");
    }
    if ($input['Physical_Activity'] < 0 || $input['Physical_Activity'] > 100) {
        throw new Exception("Physical activity must be between 0 and 100 minutes per week");
    }
    if ($input['Mental_Clarity_Score'] < 1 || $input['Mental_Clarity_Score'] > 10) {
        throw new Exception("Mental clarity score must be between 1 and 10");
    }

    $prediction_data = [
        'Age' => (float) $input['Age'],
        'Hours_of_Screen_Time' => (float) $input['Hours_of_Screen_Time'],
        'Hours_of_Sleep' => (float) $input['Hours_of_Sleep'],
        'Daily_Study_Hours' => (float) $input['Daily_Study_Hours'],
        'Physical_Activity' => (float) $input['Physical_Activity'],
        'Mental_Clarity_Score' => (float) $input['Mental_Clarity_Score']
    ];

    error_log("Prediction request data: " . json_encode($prediction_data));

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
        CURLOPT_URL => 'http://localhost:5000/predict',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($prediction_data),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json'
        ]
    ]);

    error_log("Sending prediction request to Python API");
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    error_log("Python API prediction response - HTTP Code: $httpCode, Error: " . ($error ?: 'none'));

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

    error_log("Prediction completed successfully");

    echo $response;

} catch (Exception $e) {
    error_log("Prediction error: " . $e->getMessage());
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