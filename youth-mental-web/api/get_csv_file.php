<?php

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ADMIN') {
    http_response_code(403);
    exit('Unauthorized');
}

if (isset($_SESSION['cleaned_csv']) && file_exists($_SESSION['cleaned_csv'])) {
    header('Content-Type: text/csv');
    readfile($_SESSION['cleaned_csv']);
} else {
    http_response_code(404);
    exit('CSV file not found');
}
?>