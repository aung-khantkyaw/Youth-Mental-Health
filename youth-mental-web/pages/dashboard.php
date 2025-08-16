<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
} else if ($_SESSION['role'] !== 'ADMIN') {
    header('Location: index.php');
    exit;
}

require_once '../config/config.php';

$users = [];
$stmt = $pdo->query("SELECT id, username, email, created_at FROM users WHERE role = 'USER' ORDER BY created_at DESC");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);


$csvData = [];
$csvRowsPerPage = 10;
$csvCurrentPage = isset($_GET['csv_page']) ? max(1, intval($_GET['csv_page'])) : 1;
$csvTotalRows = 0;
$csvTotalPages = 1;
$cleanedCsvData = [];

function readCsvToArray($filepath)
{
    $data = [];
    if (file_exists($filepath)) {
        if (($handle = fopen($filepath, 'r')) !== false) {
            while (($row = fgetcsv($handle)) !== false) {
                $data[] = $row;
            }
            fclose($handle);
        }
    }
    return $data;
}

if (isset($_GET['action']) && $_GET['action'] === 'cancel_csv') {
    unset($_SESSION['uploaded_csv'], $_SESSION['cleaned_csv'], $_SESSION['csv_row_diff'], $_SESSION['csv_row_original'], $_SESSION['csv_row_cleaned']);
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $uploadDir = __DIR__ . '/../upload/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    $file = $_FILES['file'];
    $fileName = basename($file['name']);
    $fileType = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $targetFile = $uploadDir . uniqid('csv_', true) . '.csv';

    if ($fileType === 'csv') {
        if (move_uploaded_file($file['tmp_name'], $targetFile)) {
            $csvData = readCsvToArray($targetFile);

            $cleanedData = [];
            $originalRowCount = count($csvData) - 1;
            if (!empty($csvData)) {
                $cleanedData[] = $csvData[0];
                foreach (array_slice($csvData, 1) as $row) {
                    $hasEmpty = false;
                    foreach ($row as $cell) {
                        if ($cell === null || $cell === 'null' || $cell === 'NULL' || $cell === '' || $cell === '0') {
                            $hasEmpty = true;
                            break;
                        }
                    }
                    if (!$hasEmpty) {
                        $cleanedData[] = $row;
                    }
                }
            }
            $cleanedRowCount = count($cleanedData) - 1;

            $cleanedFile = $uploadDir . uniqid('csv_cleaned_', true) . '.csv';
            $fp = fopen($cleanedFile, 'w');
            foreach ($cleanedData as $row) {
                fputcsv($fp, $row);
            }
            fclose($fp);
            $csvData = readCsvToArray($cleanedFile);
            $_SESSION['uploaded_csv'] = $targetFile;
            $_SESSION['cleaned_csv'] = $cleanedFile;
            $_SESSION['csv_row_diff'] = $originalRowCount - $cleanedRowCount;
            $_SESSION['csv_row_original'] = $originalRowCount;
            $_SESSION['csv_row_cleaned'] = $cleanedRowCount;
        } else {
            $csvData = [['Error uploading file.']];
            unset($_SESSION['uploaded_csv']);
        }
    } else {
        $csvData = [['Only CSV files are allowed.']];
        unset($_SESSION['uploaded_csv']);
    }
} elseif (isset($_SESSION['cleaned_csv'])) {
    $csvData = readCsvToArray($_SESSION['cleaned_csv']);
}

$accountError = $accountSuccess = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_account'])) {
    $newUsername = trim($_POST['username']);
    $newEmail = trim($_POST['email']);
    if (empty($newUsername) || !preg_match('/^[a-zA-Z0-9_]{3,20}$/', $newUsername)) {
        $accountError = 'Invalid username. Only 3-20 characters, letters, numbers, and underscores allowed.';
    } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $accountError = 'Invalid email address.';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?');
        $stmt->execute([$newUsername, $newEmail, $_SESSION['user_id']]);
        if ($stmt->fetch()) {
            $accountError = 'Username or email already exists.';
        } else {
            $stmt = $pdo->prepare('UPDATE users SET username = ?, email = ? WHERE id = ?');
            $stmt->execute([$newUsername, $newEmail, $_SESSION['user_id']]);
            $_SESSION['username'] = $newUsername;
            $_SESSION['email'] = $newEmail;
            $accountSuccess = 'Account updated successfully!';
        }
    }
}

$passwordError = $passwordSuccess = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_password'])) {
    $currentPassword = $_POST['current_password'];
    $newPassword = $_POST['new_password'];

    $valid = true;
    if (strlen($newPassword) < 6) {
        $passwordError = 'Password must be at least 6 characters.';
        $valid = false;
    }
    if (!preg_match('/[A-Z]/', $newPassword)) {
        $passwordError = 'Password must contain at least one uppercase letter.';
        $valid = false;
    }
    if (!preg_match('/[a-z]/', $newPassword)) {
        $passwordError = 'Password must contain at least one lowercase letter.';
        $valid = false;
    }
    if (!preg_match('/[0-9]/', $newPassword)) {
        $passwordError = 'Password must contain at least one number.';
        $valid = false;
    }

    $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($currentPassword, $row['password'])) {
        $passwordError = 'Current password is incorrect.';
        $valid = false;
    }

    if ($valid && password_verify($newPassword, $row['password'])) {
        $passwordError = 'New password must be different from current password.';
        $valid = false;
    }
    if ($valid) {
        $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
        $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $_SESSION['user_id']]);
        $passwordSuccess = 'Password updated successfully!';
    }
}

$csvHistoryFiles = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT user_id FROM prediction_history ORDER BY user_id");
    $csvHistoryFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $csvHistoryFiles = [];
}
$csvHistoryMinDate = null;
$csvHistoryMaxDate = null;
try {
    $stmt = $pdo->query("SELECT MIN(DATE(created_at)) as min_date, MAX(DATE(created_at)) as max_date FROM prediction_history");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $csvHistoryMinDate = $row['min_date'];
    $csvHistoryMaxDate = $row['max_date'];
} catch (Exception $e) {
    $csvHistoryMinDate = $csvHistoryMaxDate = null;
}
if (isset($_POST['change_csv_history_start']) && isset($_POST['change_csv_history_end'])) {
    $start = $_POST['change_csv_history_start'];
    $end = $_POST['change_csv_history_end'];
    $stmt = $pdo->prepare("SELECT age, screen_time, sleep_hours, study_hours, physical_activity, mental_clarity_score, mood FROM prediction_history WHERE DATE(created_at) BETWEEN ? AND ? ORDER BY created_at");
    $stmt->execute([$start, $end]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        $csvData = [];
        $csvData[] = array('Age', 'Hours_of_Screen_Time', 'Hours_of_Sleep', 'Daily_Study_Hours', 'Physical_Activity', 'Mental_Clarity_Score', 'Mood');
        foreach ($rows as $row) {
            $csvData[] = [
                $row['age'],
                $row['screen_time'],
                $row['sleep_hours'],
                $row['study_hours'],
                $row['physical_activity'],
                $row['mental_clarity_score'],
                $row['mood']
            ];
        }
        $uploadDir = __DIR__ . '/../upload/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $filename = 'history_' . date('Ymd_His') . '_' . uniqid() . '.csv';
        $filepath = $uploadDir . $filename;
        $fp = fopen($filepath, 'w');
        foreach ($csvData as $fields) {
            fputcsv($fp, $fields);
        }
        fclose($fp);
        $_SESSION['cleaned_csv'] = $filepath;
        $_SESSION['csvData'] = $csvData;
    }
}
if (isset($_SESSION['csvData'])) {
    $csvData = $_SESSION['csvData'];
    unset($_SESSION['csvData']);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Youth Mental Health - Admin Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8fafc;
            color: #334155;
            position: relative;
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at 10% 10%, rgba(59, 130, 246, 0.08) 0%, rgba(6, 182, 212, 0.05) 40%, transparent 70%);
            pointer-events: none;
            z-index: -1;
            animation: subtleGlow 10s infinite alternate;
        }

        @keyframes subtleGlow {
            from {
                transform: scale(1);
                opacity: 0.8;
            }

            to {
                transform: scale(1.05);
                opacity: 1;
            }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(50px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes shimmer {
            0% {
                background-position: -200px 0;
            }

            100% {
                background-position: calc(200px + 100%) 0;
            }
        }

        .animate-fadeInUp {
            animation: fadeInUp 0.6s ease-out forwards;
        }

        .animate-slideInRight {
            animation: slideInRight 0.8s ease-out forwards;
        }

        .shimmer-loading {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200px 100%;
            animation: shimmer 1.5s infinite;
        }

        .container {
            width: 100%;
            padding: 0 1rem;
        }

        .card {
            background-color: rgba(255, 255, 255, 0.9);
            border-radius: 1rem;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05), 0 1px 3px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(59, 130, 246, 0.15);
        }

        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            color: white;
            padding: 16px 24px;
            border-radius: 12px;
            z-index: 1000;
            transform: translateX(400px);
            transition: transform 0.3s ease, opacity 0.3s ease;
            max-width: 500px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
        }

        .toast.success {
            background: linear-gradient(135deg, #10B981, #059669);
            box-shadow: 0 10px 25px rgba(16, 185, 129, 0.3);
        }

        .toast.error {
            background: linear-gradient(135deg, #EF4444, #DC2626);
            box-shadow: 0 10px 25px rgba(239, 68, 68, 0.3);
        }

        .toast.show {
            transform: translateX(0);
        }

        .toast .toast-icon {
            font-size: 20px;
            flex-shrink: 0;
        }

        .toast .toast-close {
            margin-left: auto;
            background: none;
            border: none;
            color: white;
            font-size: 18px;
            cursor: pointer;
            padding: 0;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background-color 0.2s ease;
        }

        .toast .toast-close:hover {
            background-color: rgba(255, 255, 255, 0.2);
        }

        .tab-button {
            transition: all 0.3s ease;
        }

        .tab-button.active {
            background: linear-gradient(135deg, #1e40af, #3b82f6) !important;
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.4);
            transform: scale(1.05);
        }

        .section-content {
            animation: fadeInUp 0.5s ease-out;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Sidebar Styles */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: 280px;
            background: linear-gradient(135deg, #1e293b 0%, #334155 50%, #475569 100%);
            box-shadow: 4px 0 15px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            transition: transform 0.3s ease;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .sidebar-header {
            padding: 2rem 1.5rem 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-nav {
            padding: 1rem 0;
            overflow-x: hidden;
        }

        .nav-item {
            display: block;
            width: 100%;
            box-sizing: border-box;
            padding: 1rem 1.5rem;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            border: none;
            background: none;
            text-align: left;
            transition: all 0.3s ease;
            cursor: pointer;
            border-left: 4px solid transparent;
            position: relative;
            font-weight: 500;
            overflow-x: hidden;
        }

        .nav-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 0;
            background: linear-gradient(90deg, rgba(59, 130, 246, 0.1), transparent);
            transition: width 0.3s ease;
            z-index: -1;
        }

        .nav-item:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-left-color: #3b82f6;
            transform: translateX(5px);
        }

        .nav-item:hover::before {
            width: 100%;
        }

        .nav-item.active {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.3), rgba(96, 165, 250, 0.2));
            color: white;
            border-left-color: #60a5fa;
            box-shadow: inset 0 0 0 1px rgba(96, 165, 250, 0.3);
            transform: translateX(8px);
            font-weight: 600;
        }

        .nav-item.active::before {
            width: 100%;
            background: linear-gradient(90deg, rgba(59, 130, 246, 0.2), transparent);
        }

        .nav-item.active:hover {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.4), rgba(96, 165, 250, 0.3));
            border-left-color: #93c5fd;
            transform: translateX(10px);
        }

        .nav-item .nav-icon {
            font-size: 1.2rem;
            margin-right: 0.75rem;
            width: 1.5rem;
            display: inline-block;
        }

        .main-content {
            margin-left: 280px;
            min-height: 100vh;
            padding: 2rem;
            transition: margin-left 0.3s ease;
        }

        .mobile-menu-btn {
            display: none;
            position: fixed;
            top: 1rem;
            left: 1rem;
            z-index: 1001;
            background: #1e293b;
            color: white;
            border: none;
            padding: 0.5rem;
            border-radius: 0.5rem;
            cursor: pointer;
        }

        /* Mobile responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .mobile-menu-btn {
                display: block;
            }

            .sidebar-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.5);
                z-index: 999;
                display: none;
            }

            .sidebar-overlay.show {
                display: block;
            }
        }
    </style>
</head>

<body>
    <!-- Mobile menu button -->
    <button class="mobile-menu-btn" onclick="toggleSidebar()">
        <svg width="24" height="24" fill="currentColor" viewBox="0 0 24 24">
            <path d="M3 6h18M3 12h18M3 18h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"></path>
        </svg>
    </button>

    <!-- Sidebar overlay for mobile -->
    <div class="sidebar-overlay" onclick="closeSidebar()"></div>

    <!-- Sidebar Navigation -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h1 class="text-xl font-bold text-white mb-2">Youth Mental Health</h1>
            <p class="text-sm text-gray-300">Admin Dashboard</p>
        </div>

        <nav class="sidebar-nav">
            <button onclick="showSection('file-upload')" id="nav-file-upload" class="nav-item">
                Data & Training
            </button>
            <button onclick="showSection('model-info')" id="nav-model-info" class="nav-item">
                Model Info
            </button>
            <button onclick="showSection('model-testing')" id="nav-model-testing" class="nav-item">
                Model Testing
            </button>
            <button onclick="showSection('all-models')" id="nav-all-models" class="nav-item">
                All Models
            </button>
            <button onclick="showSection('user-management')" id="nav-user-management" class="nav-item">
                Users
            </button>
            <button onclick="showSection('account-settings')" id="nav-account-settings" class="nav-item">
                Settings
            </button>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="container mx-auto max-w-6xl">
            <main class="space-y-8">
                <!-- File Upload Section -->
                <section id="section-file-upload" class="section-content card"
                    style="display: <?php echo (!empty($csvData) || (isset($_GET['action']) && $_GET['action'] === 'create_model')) ? 'block' : 'none'; ?>;">
                    <!-- Step 1: File Upload (when no CSV data) -->
                    <?php if (empty($csvData)): ?>
                        <h2 class="text-2xl md:text-3xl font-bold text-blue-900 mb-6">Upload CSV File</h2>
                        <form action="dashboard.php" method="post" enctype="multipart/form-data">
                            <div class="mb-4">
                                <div id="drop-area"
                                    class="flex flex-col items-center justify-center w-full h-32 border-2 border-dashed border-blue-400 rounded-lg cursor-pointer bg-blue-50 hover:bg-blue-100 transition-colors duration-200 relative">
                                    <svg class="w-10 h-10 text-blue-500 mb-2" fill="none" stroke="currentColor"
                                        stroke-width="2" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M7 16v-4a4 4 0 018 0v4" />
                                        <path d="M12 12v9" />
                                        <path d="M16 19H8" />
                                    </svg>
                                    <span class="text-blue-700 font-semibold">Click to select or drag & drop CSV here</span>
                                    <input type="file" id="file" name="file" accept=".csv"
                                        class="absolute inset-0 opacity-0 cursor-pointer" required>
                                </div>
                                <div id="file-name" class="mt-2 text-blue-700 font-medium"></div>
                            </div>
                            <button type="submit"
                                class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition-colors duration-200 w-full mt-2">Upload
                                CSV File</button>
                        </form>
                        <!-- Change CSV from prediction_history by date interval (date only) -->
                        <div
                            class="mt-8 p-6 bg-gradient-to-r from-green-50 to-blue-50 border border-green-200 rounded-2xl shadow">
                            <h3 class="text-lg font-bold text-green-900 mb-2 flex items-center gap-2">
                                <svg class="w-6 h-6 text-green-500" fill="none" stroke="currentColor" stroke-width="2"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                                Load CSV from Prediction History (by Date Interval)
                            </h3>
                            <form action="dashboard.php" method="post"
                                class="flex flex-col md:flex-row md:items-end gap-4 justify-between">
                                <div class="flex flex-col md:flex-row gap-4">
                                    <div class="flex flex-col">
                                        <label for="change_csv_history_start" class="mb-1 font-medium text-green-800">Start
                                            Date</label>
                                        <input type="date" name="change_csv_history_start" id="change_csv_history_start"
                                            min="<?php echo htmlspecialchars($csvHistoryMinDate); ?>"
                                            max="<?php echo htmlspecialchars($csvHistoryMaxDate); ?>" required
                                            class="p-2 border border-green-300 rounded focus:ring-2 focus:ring-green-400 focus:border-green-400 w-[200px]">
                                    </div>
                                    <div class="flex flex-col">
                                        <label for="change_csv_history_end" class="mb-1 font-medium text-green-800">End
                                            Date</label>
                                        <input type="date" name="change_csv_history_end" id="change_csv_history_end"
                                            min="<?php echo htmlspecialchars($csvHistoryMinDate); ?>"
                                            max="<?php echo htmlspecialchars($csvHistoryMaxDate); ?>" required
                                            class="p-2 border border-green-300 rounded focus:ring-2 focus:ring-green-400 focus:border-green-400 w-[200px]">
                                    </div>
                                </div>
                                <button type="submit"
                                    class="h-12 px-6 mt-4 md:mt-0 bg-green-500 text-white font-semibold rounded-lg shadow hover:from-green-600 hover:to-blue-600 transition-all duration-200 flex items-center gap-2">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2"
                                        viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                                    </svg>
                                    Load CSV
                                </button>
                            </form>
                            <p class="text-xs text-green-700 mt-2">Select a date range to generate a CSV from all prediction
                                history records in that interval. This will save the CSV to the upload folder and allow you
                                to preview and train a model with it.</p>
                        </div>


                    <?php endif; ?>

                    <!-- Step 2: CSV Data Preview (when CSV uploaded but not training) -->
                    <?php if (!empty($csvData) && (!isset($_GET['action']) || $_GET['action'] !== 'create_model')): ?>
                        <div class="flex justify-between items-center mb-4">
                            <h1 class="text-3xl md:text-4xl font-bold text-blue-900">CSV Data Preview</h1>
                            <div class="flex gap-2">
                                <a href="dashboard.php?action=create_model"
                                    class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">Train Model</a>
                                <a href="dashboard.php?action=cancel_csv"
                                    class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700">Cancel</a>
                            </div>
                        </div>
                        <?php
                        $csvTotalRows = count($csvData) - 1;
                        $csvTotalPages = max(1, ceil($csvTotalRows / $csvRowsPerPage));
                        $csvStart = ($csvCurrentPage - 1) * $csvRowsPerPage + 1;
                        $csvEnd = min($csvStart + $csvRowsPerPage - 1, count($csvData) - 1);
                        $csvPageRows = array_slice($csvData, $csvStart, $csvEnd - $csvStart + 1);
                        ?>
                        <?php if (isset($_SESSION['csv_row_diff'])): ?>
                            <div class="mb-4 p-4 bg-yellow-100 text-yellow-800 rounded">
                                <strong>Row Difference:</strong> <?php echo $_SESSION['csv_row_diff']; ?> rows removed
                                (<?php echo $_SESSION['csv_row_original']; ?> original,
                                <?php echo $_SESSION['csv_row_cleaned']; ?>
                                after cleaning)
                            </div>
                        <?php endif; ?>
                        <table class="min-w-full bg-white text-gray-800">
                            <thead>
                                <tr>
                                    <?php foreach ($csvData[0] as $header): ?>
                                        <th class="py-2 px-4 border-b"><?php echo htmlspecialchars($header); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($csvPageRows as $row): ?>
                                    <tr>
                                        <?php foreach ($row as $cell): ?>
                                            <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($cell); ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="flex justify-between items-center mt-4">
                            <div>
                                Page <?php echo $csvCurrentPage; ?> of <?php echo $csvTotalPages; ?>
                            </div>
                            <div>
                                <?php if ($csvCurrentPage > 1): ?>
                                    <a href="?csv_page=<?php echo $csvCurrentPage - 1; ?>"
                                        class="px-3 py-1 bg-gray-700 text-white rounded hover:bg-gray-600 mr-2">Previous</a>
                                <?php endif; ?>
                                <?php if ($csvCurrentPage < $csvTotalPages): ?>
                                    <a href="?csv_page=<?php echo $csvCurrentPage + 1; ?>"
                                        class="px-3 py-1 bg-gray-700 text-white rounded hover:bg-gray-600">Next</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Step 3: Model Training (when training mode) -->
                    <?php if (isset($_GET['action']) && $_GET['action'] === 'create_model' && !empty($csvData)): ?>
                        <div class="flex justify-between items-center mb-4">
                            <h1 class="text-3xl md:text-4xl font-bold text-blue-900">Model Training</h1>
                            <div class="flex gap-2">
                                <a href="dashboard.php"
                                    class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">Back to Data
                                    Preview</a>
                                <a href="dashboard.php?action=cancel_csv"
                                    class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700">Cancel</a>
                            </div>
                        </div>
                        <h2 class="text-2xl font-bold text-blue-900 mb-4">File Information</h2>
                        <?php
                        $csvFilePath = isset($_SESSION['cleaned_csv']) ? $_SESSION['cleaned_csv'] : null;
                        $csvFileName = $csvFilePath ? basename($csvFilePath) : '';
                        $csvFileSize = $csvFilePath && file_exists($csvFilePath) ? filesize($csvFilePath) : 0;
                        $csvFileCreated = $csvFilePath && file_exists($csvFilePath) ? date('Y-m-d H:i:s', filectime($csvFilePath)) : '';
                        ?>
                        <div class="mb-6 p-4 bg-slate-100 rounded text-slate-700">
                            <div class="mb-2"><strong>File Name:</strong> <?php echo htmlspecialchars($csvFileName); ?>
                            </div>
                            <div class="mb-2"><strong>File Size:</strong>
                                <?php echo number_format($csvFileSize / 1024, 2); ?>
                                KB</div>
                            <div class="mb-2"><strong>Created At:</strong> <?php echo htmlspecialchars($csvFileCreated); ?>
                            </div>
                            <?php if (isset($_SESSION['csv_row_cleaned'])): ?>
                                <div><strong>Total Rows:</strong> <?php echo $_SESSION['csv_row_cleaned']; ?> (after cleaning)
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="mt-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                            <h3 class="text-lg font-semibold text-yellow-800 mb-2">Model Training Status</h3>
                            <p class="text-yellow-700 mb-4">Your CSV data has been processed and is ready for machine
                                learning
                                model training. Click the button below to start the training process.</p>

                            <div id="training-status" class="mb-4 hidden">
                                <div class="flex items-center mb-2">
                                    <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-yellow-600 mr-2"></div>
                                    <span class="text-yellow-700 font-medium">Training model...</span>
                                </div>
                                <div class="w-full bg-yellow-200 rounded-full h-2">
                                    <div id="progress-bar"
                                        class="bg-yellow-600 h-2 rounded-full transition-all duration-500"
                                        style="width: 0%"></div>
                                </div>
                            </div>

                            <div id="training-result" class="mb-4 hidden"></div>

                            <button id="start-training-btn" onclick="startModelTraining()"
                                class="px-6 py-3 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700 transition-colors duration-200 font-semibold">
                                Start Model Training
                            </button>
                        </div>
                    <?php endif; ?>

                </section>

                <!-- Model Info Section -->
                <section id="section-model-info" class="section-content card" style="display: none;">
                    <h2 class="text-2xl font-bold text-blue-900 mb-6">Mental Health Model Information</h2>
                    <p class="text-gray-600 mb-6">View detailed analytics and performance metrics for the trained AI
                        model.</p>

                    <div id="active-model-info" class="mb-8 overflow-hidden">
                        <div
                            class="bg-gradient-to-r from-blue-600 via-purple-600 to-indigo-600 rounded-t-xl p-6 text-white">
                            <div class="flex justify-between items-start">
                                <div class="flex items-center space-x-3">
                                    <div>
                                        <h3 class="text-xl font-bold mb-1">AI Model Dashboard</h3>
                                        <p class="text-blue-100 text-sm">Real-time model performance & analytics</p>
                                    </div>
                                </div>
                                <div class="flex space-x-2">
                                    <button onclick="loadActiveModelInfo()"
                                        class="group px-4 py-2 bg-white/20 hover:bg-white/30 rounded-lg transition-all duration-200 text-sm font-medium backdrop-blur-sm border border-white/20 hover:border-white/40 hover:scale-105">
                                        <svg class="inline w-4 h-4 mr-1 transition-transform duration-500 group-hover:rotate-180"
                                            fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15">
                                            </path>
                                        </svg>
                                        Refresh
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white rounded-b-xl border-x border-b border-gray-200 shadow-lg">
                            <div id="model-info-content" class="p-6">
                                <div class="flex items-center justify-center py-12">
                                    <div class="text-center">
                                        <div class="relative mb-6">
                                            <div
                                                class="w-16 h-16 border-4 border-blue-200 border-t-blue-600 rounded-full animate-spin mx-auto">
                                            </div>
                                            <div class="absolute inset-0 w-16 h-16 border-4 border-transparent border-r-purple-400 rounded-full animate-spin mx-auto"
                                                style="animation-direction: reverse; animation-duration: 0.8s;"></div>
                                        </div>
                                        <div class="space-y-2">
                                            <h4 class="text-lg font-medium text-gray-700 animate-pulse">Loading model
                                                analytics...</h4>
                                            <p class="text-gray-400 text-sm animate-bounce">Fetching latest performance
                                                metrics</p>
                                        </div>
                                        <div class="mt-6 space-y-3">
                                            <div class="h-4 bg-gray-200 rounded shimmer-loading"></div>
                                            <div class="h-4 bg-gray-200 rounded shimmer-loading"
                                                style="animation-delay: 0.2s;"></div>
                                            <div class="h-4 bg-gray-200 rounded shimmer-loading w-3/4 mx-auto"
                                                style="animation-delay: 0.4s;"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Model Testing Section -->
                <section id="section-model-testing" class="section-content card" style="display: none;">
                    <h2 class="text-2xl font-bold text-blue-900 mb-6">Test Mental Health Model</h2>
                    <p class="text-gray-600 mb-6">Use the trained AI model to predict mental health outcomes based on
                        lifestyle factors.</p>

                    <!-- Quick Model Status -->
                    <div id="quick-model-status"
                        class="mb-6 bg-gradient-to-r from-blue-50 to-purple-50 rounded-lg p-4 border border-blue-200">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-3">
                                <div class="w-3 h-3 bg-green-500 rounded-full animate-pulse"></div>
                                <div>
                                    <h4 class="font-semibold text-gray-800">Model Status: <span
                                            id="model-status-text">Loading...</span></h4>
                                    <p class="text-sm text-gray-600">Accuracy: <span id="model-accuracy-text">--</span>
                                    </p>
                                </div>
                            </div>
                            <button onclick="showSection('model-info')"
                                class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-200 text-sm font-medium">
                                View Details
                            </button>
                        </div>
                    </div>

                    <div class="bg-blue-50 rounded-lg p-6">
                        <form id="prediction-form" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <div>
                                <label for="age" class="block text-sm font-semibold text-gray-700 mb-2">Age</label>
                                <input type="number" id="age" name="Age" min="13" max="25" step="1" required
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                    placeholder="e.g., 18">
                                <p class="text-xs text-gray-500 mt-1">Age in years (13-25)</p>
                            </div>

                            <div>
                                <label for="screen_time" class="block text-sm font-semibold text-gray-700 mb-2">Screen
                                    Time
                                    (hours/day)</label>
                                <input type="number" id="screen_time" name="Hours_of_Screen_Time" min="0" max="24"
                                    step="0.1" required
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                    placeholder="e.g., 5.5">
                                <p class="text-xs text-gray-500 mt-1">Hours per day (0-24)</p>
                            </div>

                            <div>
                                <label for="sleep_hours" class="block text-sm font-semibold text-gray-700 mb-2">Sleep
                                    Hours</label>
                                <input type="number" id="sleep_hours" name="Hours_of_Sleep" min="0" max="16" step="0.1"
                                    required
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                    placeholder="e.g., 7.5">
                                <p class="text-xs text-gray-500 mt-1">Hours per night (0-16)</p>
                            </div>

                            <div>
                                <label for="study_hours" class="block text-sm font-semibold text-gray-700 mb-2">Study
                                    Hours</label>
                                <input type="number" id="study_hours" name="Daily_Study_Hours" min="0" max="16"
                                    step="0.1" required
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                    placeholder="e.g., 4.0">
                                <p class="text-xs text-gray-500 mt-1">Hours per day (0-16)</p>
                            </div>

                            <div>
                                <label for="physical_activity"
                                    class="block text-sm font-semibold text-gray-700 mb-2">Physical Activity</label>
                                <input type="number" id="physical_activity" name="Physical_Activity" min="0" max="100"
                                    step="1" required
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                    placeholder="e.g., 30">
                                <p class="text-xs text-gray-500 mt-1">Minutes per week (0-100)</p>
                            </div>

                            <div>
                                <label for="mental_clarity"
                                    class="block text-sm font-semibold text-gray-700 mb-2">Mental
                                    Clarity Score</label>
                                <input type="number" id="mental_clarity" name="Mental_Clarity_Score" min="1" max="10"
                                    step="1" required
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                    placeholder="e.g., 7">
                                <p class="text-xs text-gray-500 mt-1">Scale 1-10 (1=Poor, 10=Excellent)</p>
                            </div>
                        </form>

                        <div class="mt-6 flex flex-col sm:flex-row gap-4">
                            <button id="classify-btn" onclick="classifyMentalHealth()"
                                class="flex-1 px-6 py-3 bg-gradient-to-r from-blue-600 to-purple-600 text-white rounded-lg hover:from-blue-700 hover:to-purple-700 font-semibold shadow-lg">
                                Classify Mental Health
                            </button>

                            <button type="button" onclick="fillExampleData()"
                                class="px-6 py-3 bg-green-500 text-white rounded-lg hover:bg-green-600 transition-colors duration-200 font-semibold">
                                Fill Example
                            </button>

                            <button type="button" onclick="clearPredictionForm()"
                                class="px-6 py-3 bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition-colors duration-200 font-semibold">
                                Clear Form
                            </button>
                        </div>

                        <div id="prediction-results" class="mt-6 hidden">
                            <div class="bg-white rounded-lg p-6 border-l-4 border-blue-500">
                                <h3 class="text-lg font-bold text-gray-800 mb-3">Prediction Results</h3>
                                <div id="prediction-content" class="space-y-3">
                                    <!-- Results will be populated here -->
                                </div>
                            </div>
                        </div>

                        <div id="prediction-loading" class="mt-6 hidden">
                            <div class="bg-blue-100 rounded-lg p-6 text-center">
                                <div class="inline-flex items-center">
                                    <div class="animate-spin rounded-full h-6 w-6 border-b-2 border-blue-600 mr-3">
                                    </div>
                                    <span class="text-blue-700 font-medium">AI is analyzing the data...</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- All Models Section -->
                <section id="section-all-models" class="section-content card" style="display: none;">
                    <h1 class="text-2xl md:text-3xl font-bold text-blue-900 mb-6">All Models</h1>
                    <div class="overflow-x-auto rounded-2xl shadow-lg">
                        <table class="min-w-full bg-white text-gray-800 rounded-2xl overflow-hidden">
                            <thead>
                                <tr class="bg-gradient-to-r from-blue-100 via-indigo-100 to-purple-100">
                                    <th class="py-3 px-5 text-left font-semibold text-blue-900">Filename</th>
                                    <th class="py-3 px-5 text-left font-semibold text-blue-900">Train Accuracy</th>
                                    <th class="py-3 px-5 text-left font-semibold text-blue-900">Test Accuracy</th>
                                    <th class="py-3 px-5 text-left font-semibold text-blue-900">Total Rows</th>
                                    <th class="py-3 px-5 text-left font-semibold text-blue-900">Features Used</th>
                                    <th class="py-3 px-5 text-left font-semibold text-blue-900">Target</th>
                                    <th class="py-3 px-5 text-left font-semibold text-blue-900">Created At</th>
                                </tr>
                            </thead>
                            <tbody id="models-list">

                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- User Management Section -->
                <section id="section-user-management" class="section-content card" style="display: none;">
                    <h1 class="text-2xl md:text-3xl font-bold text-blue-900 mb-6">All Users</h1>
                    <div class="overflow-x-auto rounded-2xl shadow-lg">
                        <table class="min-w-full bg-white text-gray-800 rounded-2xl overflow-hidden">
                            <thead>
                                <tr class="bg-gradient-to-r from-blue-100 via-indigo-100 to-purple-100">
                                    <th class="py-3 px-5 text-left font-semibold text-blue-900">ID</th>
                                    <th class="py-3 px-5 text-left font-semibold text-blue-900">Username</th>
                                    <th class="py-3 px-5 text-left font-semibold text-blue-900">Email</th>
                                    <th class="py-3 px-5 text-left font-semibold text-blue-900">Created At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                    <tr class="border-b border-gray-100 hover:bg-blue-50 transition-colors duration-150">
                                        <td class="py-3 px-5 text-sm"><?php echo htmlspecialchars($user['id']); ?></td>
                                        <td class="py-3 px-5 text-sm font-medium text-blue-700">
                                            <?php echo htmlspecialchars($user['username']); ?>
                                        </td>
                                        <td class="py-3 px-5 text-sm"><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td class="py-3 px-5 text-xs text-gray-500">
                                            <?php echo htmlspecialchars($user['created_at']); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- Account Settings Section -->
                <section id="section-account-settings" class="section-content card border-0 shadow-xl"
                    style="display: none;">
                    <h1 class="text-2xl md:text-3xl font-bold text-blue-900 mb-6">Account Settings</h1>

                    <div id="user-actions-buttons" class="space-y-4" <?php if ((isset($_POST['update_account']) && !$accountSuccess) || (isset($_POST['update_password']) && !$passwordSuccess))
                        echo 'style="display:none;"'; ?>>

                        <button type="button" onclick="showAccountForm()"
                            class="group w-full p-4 bg-gradient-to-r from-cyan-500 to-blue-600 text-white rounded-xl hover:from-cyan-600 hover:to-blue-700 transition-all duration-300 shadow-lg hover:shadow-xl">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-3">
                                    <div
                                        class="w-10 h-10 bg-white/20 rounded-lg flex items-center justify-center backdrop-blur-sm">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z">
                                            </path>
                                        </svg>
                                    </div>
                                    <div class="text-left">
                                        <div class="font-semibold">Update Profile</div>
                                        <div class="text-sm text-cyan-100">Change username & email</div>
                                    </div>
                                </div>
                                <svg class="w-5 h-5 transform group-hover:translate-x-1 transition-transform duration-200"
                                    fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M9 5l7 7-7 7">
                                    </path>
                                </svg>
                            </div>
                        </button>

                        <button type="button" onclick="showPasswordForm()"
                            class="group w-full p-4 bg-gradient-to-r from-indigo-500 to-purple-600 text-white rounded-xl hover:from-indigo-600 hover:to-purple-700 transition-all duration-300 shadow-lg hover:shadow-xl">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-3">
                                    <div
                                        class="w-10 h-10 bg-white/20 rounded-lg flex items-center justify-center backdrop-blur-sm">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 012 2zm10-10V7a4 4 0 00-8 0v4h8z">
                                            </path>
                                        </svg>
                                    </div>
                                    <div class="text-left">
                                        <div class="font-semibold">Change Password</div>
                                        <div class="text-sm text-indigo-100">Update security credentials</div>
                                    </div>
                                </div>
                                <svg class="w-5 h-5 transform group-hover:translate-x-1 transition-transform duration-200"
                                    fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M9 5l7 7-7 7">
                                    </path>
                                </svg>
                            </div>
                        </button>

                        <a href="logout.php"
                            class="group block w-full p-4 bg-gradient-to-r from-red-500 to-pink-600 text-white rounded-xl hover:from-red-600 hover:to-pink-700 transition-all duration-300 shadow-lg hover:shadow-xl">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-3">
                                    <div
                                        class="w-10 h-10 bg-white/20 rounded-lg flex items-center justify-center backdrop-blur-sm">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1">
                                            </path>
                                        </svg>
                                    </div>
                                    <div class="text-left">
                                        <div class="font-semibold">Sign Out</div>
                                        <div class="text-sm text-red-100">End current session</div>
                                    </div>
                                </div>
                                <svg class="w-5 h-5 transform group-hover:translate-x-1 transition-transform duration-200"
                                    fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M9 5l7 7-7 7">
                                    </path>
                                </svg>
                            </div>
                        </a>
                    </div>

                    <?php if ($accountSuccess): ?>
                        <script>
                            window.addEventListener('DOMContentLoaded', function () {
                                showToast('<?php echo addslashes($accountSuccess); ?>', 'success');
                            });
                        </script>
                    <?php elseif ($accountError): ?>
                        <script>
                            window.addEventListener('DOMContentLoaded', function () {
                                showToast('<?php echo addslashes($accountError); ?>', 'error');
                            });
                        </script>
                    <?php endif; ?>

                    <?php if ($passwordSuccess): ?>
                        <script>
                            window.addEventListener('DOMContentLoaded', function () {
                                showToast('<?php echo addslashes($passwordSuccess); ?>', 'success');
                            });
                        </script>
                    <?php elseif ($passwordError): ?>
                        <script>
                            window.addEventListener('DOMContentLoaded', function () {
                                showToast('<?php echo addslashes($passwordError); ?>', 'error');
                            });
                        </script>
                    <?php endif; ?>

                    <form id="account-form" method="post"
                        class="bg-white/80 backdrop-blur-sm rounded-xl p-6 shadow-lg border border-blue-100"
                        style="display:<?php echo (isset($_POST['update_account']) && !$accountSuccess) ? 'block' : 'none'; ?>;">
                        <div class="flex items-center mb-6">
                            <div
                                class="w-10 h-10 bg-gradient-to-r from-cyan-500 to-blue-600 rounded-lg flex items-center justify-center mr-3">
                                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z">
                                    </path>
                                </svg>
                            </div>
                            <h3 class="text-xl font-bold text-gray-800">Update Profile Information</h3>
                        </div>

                        <div class="space-y-5">
                            <div class="group">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Username</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <svg class="w-5 h-5 text-gray-400 group-focus-within:text-cyan-500 transition-colors"
                                            fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z">
                                            </path>
                                        </svg>
                                    </div>
                                    <input type="text" name="username"
                                        value="<?php echo htmlspecialchars(isset($_SESSION['username']) ? $_SESSION['username'] : ''); ?>"
                                        class="w-full pl-10 pr-4 py-3 rounded-lg bg-gray-50 border border-gray-200 text-gray-700 focus:ring-2 focus:ring-cyan-500 focus:border-transparent transition-all duration-200">
                                </div>
                            </div>

                            <div class="group">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Email Address</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <svg class="w-5 h-5 text-gray-400 group-focus-within:text-cyan-500 transition-colors"
                                            fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207">
                                            </path>
                                        </svg>
                                    </div>
                                    <input type="email" name="email"
                                        value="<?php echo htmlspecialchars(isset($_SESSION['email']) ? $_SESSION['email'] : ''); ?>"
                                        class="w-full pl-10 pr-4 py-3 rounded-lg bg-gray-50 border border-gray-200 text-gray-700 focus:ring-2 focus:ring-cyan-500 focus:border-transparent transition-all duration-200">
                                </div>
                            </div>
                        </div>

                        <div class="flex gap-3 mt-6">
                            <button type="submit" name="update_account"
                                class="flex-1 px-6 py-3 bg-gradient-to-r from-cyan-500 to-blue-600 text-white rounded-lg hover:from-cyan-600 hover:to-blue-700 font-semibold shadow-lg hover:shadow-xl">
                                <div class="flex items-center justify-center space-x-2">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M5 13l4 4L19 7"></path>
                                    </svg>
                                    <span>Save Changes</span>
                                </div>
                            </button>
                            <button type="button" onclick="hideForms()"
                                class="px-6 py-3 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 font-semibold transition-colors duration-200">
                                Cancel
                            </button>
                        </div>
                    </form>

                    <form id="password-form" method="post"
                        class="bg-white/80 backdrop-blur-sm rounded-xl p-6 shadow-lg border border-blue-100"
                        style="display:<?php echo (isset($_POST['update_password']) && !$passwordSuccess) ? 'block' : 'none'; ?>;">
                        <div class="flex items-center mb-6">
                            <div
                                class="w-10 h-10 bg-gradient-to-r from-indigo-500 to-purple-600 rounded-lg flex items-center justify-center mr-3">
                                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 012 2zm10-10V7a4 4 0 00-8 0v4h8z">
                                    </path>
                                </svg>
                            </div>
                            <h3 class="text-xl font-bold text-gray-800">Change Password</h3>
                        </div>

                        <div class="space-y-5">
                            <div class="group">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Current Password</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <svg class="w-5 h-5 text-gray-400 group-focus-within:text-indigo-500 transition-colors"
                                            fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 012 2zm10-10V7a4 4 0 00-8 0v4h8z">
                                            </path>
                                        </svg>
                                    </div>
                                    <input type="password" name="current_password"
                                        class="w-full pl-10 pr-4 py-3 rounded-lg bg-gray-50 border border-gray-200 text-gray-700 focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all duration-200"
                                        placeholder="Enter current password">
                                </div>
                            </div>

                            <div class="group">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">New Password</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <svg class="w-5 h-5 text-gray-400 group-focus-within:text-indigo-500 transition-colors"
                                            fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        </svg>
                                    </div>
                                    <input type="password" name="new_password" required minlength="6"
                                        class="w-full pl-10 pr-4 py-3 rounded-lg bg-gray-50 border border-gray-200 text-gray-700 focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all duration-200"
                                        placeholder="Enter new password">
                                </div>
                                <p class="text-xs text-gray-500 mt-2">Must be at least 6 characters with uppercase,
                                    lowercase, and number</p>
                            </div>
                        </div>

                        <div class="flex gap-3 mt-6">
                            <button type="submit" name="update_password"
                                class="flex-1 px-6 py-3 bg-gradient-to-r from-indigo-500 to-purple-600 text-white rounded-lg hover:from-indigo-600 hover:to-purple-700 font-semibold shadow-lg hover:shadow-xl">
                                <div class="flex items-center justify-center space-x-2">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M5 13l4 4L19 7"></path>
                                    </svg>
                                    <span>Update Password</span>
                                </div>
                            </button>
                            <button type="button" onclick="hideForms()"
                                class="px-6 py-3 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 font-semibold transition-colors duration-200">
                                Cancel
                            </button>
                        </div>
                    </form>
                </section>
            </main>
        </div>
    </div>
</body>
<script>
    const dropArea = document.getElementById('drop-area');
    const fileInput = document.getElementById('file');
    const fileNameDiv = document.getElementById('file-name');

    dropArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropArea.classList.add('bg-blue-100');
    });
    dropArea.addEventListener('dragleave', (e) => {
        e.preventDefault();
        dropArea.classList.remove('bg-blue-100');
    });
    dropArea.addEventListener('drop', (e) => {
        e.preventDefault();
        dropArea.classList.remove('bg-blue-100');
        if (e.dataTransfer.files.length) {
            fileInput.files = e.dataTransfer.files;
            showFileName();
        }
    });

    fileInput.addEventListener('change', showFileName);

    function showFileName() {
        if (fileInput.files.length) {
            fileNameDiv.textContent = fileInput.files[0].name;
        } else {
            fileNameDiv.textContent = '';
        }
    }

    function showAccountForm() {
        document.getElementById('user-actions-buttons').style.display = 'none';
        document.getElementById('account-form').style.display = 'block';
        document.getElementById('password-form').style.display = 'none';
    }

    function showPasswordForm() {
        document.getElementById('user-actions-buttons').style.display = 'none';
        document.getElementById('account-form').style.display = 'none';
        document.getElementById('password-form').style.display = 'block';
    }

    function hideForms() {
        document.getElementById('user-actions-buttons').style.display = 'block';
        document.getElementById('account-form').style.display = 'none';
        document.getElementById('password-form').style.display = 'none';
    }

    function showToast(message, type = 'success') {
        const existingToasts = document.querySelectorAll('.toast');
        existingToasts.forEach(toast => toast.remove());

        const icon = type === 'success'
            ? '<svg class="w-6 h-6 text-green-50" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>'
            : '<svg class="w-6 h-6 text-red-50" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
        const toastClass = type === 'success' ? 'toast success' : 'toast error';

        const toast = document.createElement('div');
        toast.className = toastClass;
        toast.innerHTML = `
            <span class="toast-icon">${icon}</span>
            <span class="toast-message">${message}</span>
            <button class="toast-close" onclick="hideToast(this)">&times;</button>
        `;

        document.body.appendChild(toast);

        setTimeout(() => {
            toast.classList.add('show');
        }, 100);

        setTimeout(() => {
            hideToast(toast.querySelector('.toast-close'));
        }, 5000);
    }

    function hideToast(closeButton) {
        const toast = closeButton.parentElement;
        toast.style.transform = 'translateX(400px)';
        toast.style.opacity = '0';

        setTimeout(() => {
            if (toast.parentElement) {
                toast.remove();
            }
        }, 300);
    }

    async function startModelTraining() {
        const startBtn = document.getElementById('start-training-btn');
        const trainingStatus = document.getElementById('training-status');
        const trainingResult = document.getElementById('training-result');
        const progressBar = document.getElementById('progress-bar');

        try {
            if (!<?php echo isset($_SESSION['cleaned_csv']) ? 'true' : 'false'; ?>) {
                showToast('No CSV file found. Please upload a file first.', 'error');
                return;
            }

            startBtn.disabled = true;
            startBtn.textContent = 'Training in progress...';
            trainingStatus.classList.remove('hidden');
            trainingResult.classList.add('hidden');

            let logsContainer = document.getElementById('training-logs');
            if (!logsContainer) {
                logsContainer = document.createElement('div');
                logsContainer.id = 'training-logs';
                logsContainer.className = 'mt-6 bg-gray-900 rounded-lg p-4 text-green-400 font-mono text-sm max-h-96 overflow-y-auto';
                logsContainer.style.display = 'none';
                trainingResult.parentNode.insertBefore(logsContainer, trainingResult);
            }

            logsContainer.style.display = 'block';
            logsContainer.innerHTML = '<div class="text-blue-400">Initializing training session...</div>';

            let progress = 0;
            const progressInterval = setInterval(() => {
                progress += Math.random() * 5;
                if (progress > 95) progress = 95;
                progressBar.style.width = progress + '%';
            }, 1000);

            const eventSource = new EventSource('../api/train_stream.php');

            eventSource.onmessage = function (event) {
                try {
                    const data = JSON.parse(event.data);

                    if (data.log) {
                        appendTrainingLog(data.log, data.type || 'info');
                    } else if (data.error) {
                        appendTrainingLog(`ERROR: ${data.error}`, 'error');
                        clearInterval(progressInterval);
                        eventSource.close();
                        throw new Error(data.error);
                    } else if (data.success) {
                        clearInterval(progressInterval);
                        progressBar.style.width = '100%';

                        appendTrainingLog('Training completed successfully!', 'success');

                        trainingResult.innerHTML = `
                            <div class="p-4 my-4 bg-green-100 border border-green-200 rounded-lg">
                                <h4 class="text-green-800 font-semibold mb-2">Training Completed Successfully!</h4>
                                <div class="text-green-700 text-sm space-y-1">
                                    <div><strong>Model File:</strong> ${data.model_filename}</div>
                                    <div><strong>Train Accuracy:</strong> ${(data.train_accuracy * 100).toFixed(2)}%</div>
                                    <div><strong>Test Accuracy:</strong> ${(data.test_accuracy * 100).toFixed(2)}%</div>
                                    <div><strong>Cross Validation Score:</strong> ${(data.cross_validation_score * 100).toFixed(2)}%</div>
                                </div>
                            </div>
                        `;

                        showToast(`Model trained successfully! Test Accuracy: ${(data.test_accuracy * 100).toFixed(1)}%`, 'success');
                        startBtn.style.display = 'none';

                        setTimeout(() => {
                            loadActiveModelInfo();
                            loadModelsInfo();
                        }, 1000);

                        eventSource.close();
                    }
                } catch (parseError) {
                    console.error('Error parsing SSE data:', parseError);
                    appendTrainingLog(`Parse error: ${event.data}`, 'error');
                }
            };

            eventSource.onerror = function (event) {
                console.error('SSE error:', event);
                clearInterval(progressInterval);
                appendTrainingLog('Connection error occurred', 'error');
                eventSource.close();
                throw new Error('SSE connection failed');
            };

        } catch (error) {
            console.error('Training error:', error);

            trainingResult.innerHTML = `
                <div class="p-4 bg-red-100 border border-red-200 rounded-lg">
                    <h4 class="text-red-800 font-semibold mb-2">Training Failed</h4>
                    <div class="text-red-700 text-sm">
                        <strong>Error:</strong> ${error.message}
                    </div>
                    <div class="text-red-600 text-xs mt-2">
                        Check the logs above for more details. Make sure Python API is running.
                    </div>
                </div>
            `;

            showToast('Training failed: ' + error.message, 'error');
        } finally {
            startBtn.disabled = false;
            startBtn.textContent = 'Start Model Training';
            trainingStatus.classList.add('hidden');
            trainingResult.classList.remove('hidden');
        }
    }

    function appendTrainingLog(message, type = 'info') {
        const logsContainer = document.getElementById('training-logs');
        if (!logsContainer) return;

        const timestamp = new Date().toLocaleTimeString();
        const colors = {
            'info': 'text-blue-400',
            'success': 'text-green-400',
            'error': 'text-red-400',
            'warning': 'text-yellow-400'
        };

        const logEntry = document.createElement('div');
        logEntry.className = `${colors[type] || 'text-gray-400'} mb-1`;
        logEntry.innerHTML = `<span class="text-gray-500">[${timestamp}]</span> ${message}`;

        logsContainer.appendChild(logEntry);
        logsContainer.scrollTop = logsContainer.scrollHeight;
    }

    async function classifyMentalHealth() {
        const classifyBtn = document.getElementById('classify-btn');
        const loadingDiv = document.getElementById('prediction-loading');
        const resultsDiv = document.getElementById('prediction-results');
        const contentDiv = document.getElementById('prediction-content');

        try {
            const formData = {
                Age: parseFloat(document.getElementById('age').value),
                Hours_of_Screen_Time: parseFloat(document.getElementById('screen_time').value),
                Hours_of_Sleep: parseFloat(document.getElementById('sleep_hours').value),
                Daily_Study_Hours: parseFloat(document.getElementById('study_hours').value),
                Physical_Activity: parseFloat(document.getElementById('physical_activity').value),
                Mental_Clarity_Score: parseFloat(document.getElementById('mental_clarity').value)
            };

            for (const [key, value] of Object.entries(formData)) {
                if (isNaN(value)) {
                    showToast(`Please enter a valid number for ${key.replace(/_/g, ' ')}`, 'error');
                    return;
                }
            }

            if (formData.Age < 13 || formData.Age > 25) {
                showToast('Age must be between 13 and 25 years', 'error');
                return;
            }
            if (formData.Hours_of_Screen_Time < 0 || formData.Hours_of_Screen_Time > 24) {
                showToast('Screen time must be between 0 and 24 hours', 'error');
                return;
            }
            if (formData.Hours_of_Sleep < 0 || formData.Hours_of_Sleep > 16) {
                showToast('Sleep hours must be between 0 and 16 hours', 'error');
                return;
            }
            if (formData.Daily_Study_Hours < 0 || formData.Daily_Study_Hours > 16) {
                showToast('Study hours must be between 0 and 16 hours', 'error');
                return;
            }
            if (formData.Physical_Activity < 0 || formData.Physical_Activity > 100) {
                showToast('Physical activity must be between 0 and 100 minutes per week', 'error');
                return;
            }
            if (formData.Mental_Clarity_Score < 1 || formData.Mental_Clarity_Score > 10) {
                showToast('Mental clarity score must be between 1 and 10', 'error');
                return;
            }

            classifyBtn.disabled = true;
            classifyBtn.textContent = 'Analyzing...';
            loadingDiv.classList.remove('hidden');
            resultsDiv.classList.add('hidden');

            console.log('Sending prediction request:', formData);


            const response = await fetch('../api/predict_model.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(formData),
                credentials: 'same-origin'
            });

            console.log('Response status:', response.status);
            const responseText = await response.text();
            console.log('Raw response:', responseText);

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status} - ${responseText}`);
            }

            let result;
            try {
                result = JSON.parse(responseText);
            } catch (parseError) {
                console.error('JSON parse error:', parseError);
                throw new Error(`Invalid JSON response: ${responseText.substring(0, 200)}...`);
            }

            console.log('Prediction result:', result);

            if (result.success) {
                const moodMappings = {
                    'Happy': { label: 'Happy/Positive Mood', color: 'green', description: 'The model predicts a positive mood state. Current lifestyle factors appear supportive.' },
                    'Neutral': { label: 'Neutral Mood', color: 'yellow', description: 'The model predicts a balanced mood state. Maintaining current habits may be beneficial.' },
                    'Stressed': { label: 'Stressed/Low Mood', color: 'red', description: 'The model predicts a stressed state. Consider lifestyle adjustments and stress management.' }
                };

                const predictedLabel = result.predicted_label || 'Unknown';
                const prediction = result.prediction;
                const confidence = result.confidence || result.probabilities;
                const moodInfo = moodMappings[predictedLabel] || { label: predictedLabel, color: 'gray', description: 'Prediction result unclear.' };

                contentDiv.innerHTML = `
                    <div class="flex items-center space-x-4 mb-4">
                        <div>
                            <h4 class="text-xl font-bold text-${moodInfo.color}-600">${moodInfo.label}</h4>
                            <p class="text-gray-600">${moodInfo.description}</p>
                        </div>
                    </div>
                    
                    <div class="bg-gray-50 rounded-lg p-4 mb-4">
                            <h5 class="font-semibold text-gray-700 mb-2">Confidence Levels : ${(confidence * 100).toFixed(2)}%</h5>
                    </div>
                    
                    <div class="bg-blue-50 rounded-lg p-4">
                        <h5 class="font-semibold text-blue-800 mb-2">Input Summary:</h5>
                        <div class="grid grid-cols-2 gap-2 text-sm text-blue-700">
                            <div><strong>Age:</strong> ${formData.Age} years</div>
                            <div><strong>Screen Time:</strong> ${formData.Hours_of_Screen_Time}h/day</div>
                            <div><strong>Sleep:</strong> ${formData.Hours_of_Sleep}h/night</div>
                            <div><strong>Study:</strong> ${formData.Daily_Study_Hours}h/day</div>
                            <div><strong>Physical Activity:</strong> ${formData.Physical_Activity} min/week</div>
                            <div><strong>Mental Clarity:</strong> ${formData.Mental_Clarity_Score}/10</div>
                        </div>
                    </div>
                    
                    <div class="mt-4 text-xs text-gray-500">
                        Note: This is an AI prediction for educational purposes only. For serious mental health concerns, please consult a healthcare professional.
                    </div>
                `;
            } else {
                throw new Error(result.error || 'Prediction failed');
            }

        } catch (error) {
            console.error('Prediction error:', error);
            contentDiv.innerHTML = `
                <div class="p-4 bg-red-100 border border-red-200 rounded-lg">
                    <h4 class="text-red-800 font-semibold mb-2">Prediction Failed</h4>
                    <div class="text-red-700 text-sm">
                        <strong>Error:</strong> ${error.message}
                    </div>
                    <div class="text-red-600 text-xs mt-2">
                        Please ensure the AI model has been trained and the server is running.
                    </div>
                </div>
            `;
            showToast('Prediction failed: ' + error.message, 'error');
        } finally {
            classifyBtn.disabled = false;
            classifyBtn.textContent = 'Classify Mental Health';
            loadingDiv.classList.add('hidden');
            resultsDiv.classList.remove('hidden');
        }
    }

    function clearPredictionForm() {
        document.getElementById('age').value = '';
        document.getElementById('screen_time').value = '';
        document.getElementById('sleep_hours').value = '';
        document.getElementById('study_hours').value = '';
        document.getElementById('physical_activity').value = '';
        document.getElementById('mental_clarity').value = '';
        document.getElementById('prediction-results').classList.add('hidden');
        document.getElementById('prediction-loading').classList.add('hidden');
    }

    function fillExampleData() {
        document.getElementById('age').value = '18';
        document.getElementById('screen_time').value = '5.0';
        document.getElementById('sleep_hours').value = '7.5';
        document.getElementById('study_hours').value = '4.0';
        document.getElementById('physical_activity').value = '30';
        document.getElementById('mental_clarity').value = '7';
    }

    let modelsInfoRequestId = 0;

    async function loadModelsInfo() {
        const modelsList = document.getElementById('models-list');
        if (!modelsList) return;

        const requestId = ++modelsInfoRequestId;

        modelsList.innerHTML = `
            <tr>
                <td colspan="7" class="py-6 text-center text-gray-500">Loading models...</td>
            </tr>
        `;

        try {
            const response = await fetch('../api/get_model_info.php', {
                method: 'GET',
                credentials: 'same-origin'
            });
            const responseText = await response.text();

            if (requestId !== modelsInfoRequestId) return;

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            let result;
            try {
                result = JSON.parse(responseText);
            } catch (parseError) {
                throw new Error(`Invalid JSON response`);
            }

            if (!result || result.success !== true) {
                throw new Error(result && result.error ? result.error : 'Unexpected response from server.');
            }

            const models = Array.isArray(result.models) ? result.models : [];

            if (models.length === 0) {
                modelsList.innerHTML = `
                    <tr class="text-center py-12">
                        <td colspan="7" class="text-gray-500 py-12">
                            <h4 class="text-xl font-bold text-gray-800 mb-2">No Model Found!</h4>
                        </td>
                    </tr>
                `;
                return;
            }

            modelsList.innerHTML = '';
            models.forEach(model => {
                let trainingDate = 'Unknown';
                const ts = model && model.training_info && model.training_info.timestamp;
                if (ts) {
                    if (typeof ts === 'string' && ts.includes('_')) {
                        const [datePart, timePart] = ts.split('_');
                        const year = datePart.substring(0, 4);
                        const month = datePart.substring(4, 6);
                        const day = datePart.substring(6, 8);
                        const hour = timePart.substring(0, 2);
                        const minute = timePart.substring(2, 4);
                        const second = timePart.substring(4, 6);
                        const parsedDate = new Date(year, month - 1, day, hour, minute, second);
                        trainingDate = parsedDate.toLocaleString();
                    } else {
                        trainingDate = new Date(ts).toLocaleString();
                    }
                }

                const trainAcc = Number(model.train_accuracy || 0) * 100;
                const testAcc = Number(model.test_accuracy || 0) * 100;
                const rows = model.training_info && model.training_info.data_shape ? model.training_info.data_shape[0] : '-';
                const numFeatures = model.num_features ?? '-';
                const target = model.target_column ?? '-';
                const filename = model.filename || '-';

                modelsList.innerHTML += `
                    <tr class="border-b border-gray-100 hover:bg-blue-50 transition-colors duration-150">
                        <td class="py-3 px-5 text-sm">${filename}</td>
                        <td class="py-3 px-5 text-sm">${trainAcc.toFixed(1)}%</td>
                        <td class="py-3 px-5 text-sm">${testAcc.toFixed(1)}%</td>
                        <td class="py-3 px-5 text-sm">${rows}</td>
                        <td class="py-3 px-5 text-sm">${numFeatures}</td>
                        <td class="py-3 px-5 text-sm">${target}</td>
                        <td class="py-3 px-5 text-sm">${trainingDate}</td>
                    </tr>
                `;
            });
        } catch (error) {
            if (requestId !== modelsInfoRequestId) return;
            modelsList.innerHTML = `
                <tr class="text-center py-12">
                    <td colspan="7" class="text-gray-500 py-12">
                        <h4 class="text-xl font-bold text-gray-800 mb-2">Connection Error</h4>
                        <p class="text-gray-500 mb-4 max-w-md mx-auto">${error.message}</p>
                        <button onclick="loadModelsInfo()" 
                            class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-blue-500 to-purple-600 text-white rounded-lg hover:from-blue-600 hover:to-purple-700 transition-all duration-200 font-medium shadow-lg hover:shadow-xl transform hover:-translate-y-0.5">
                            Retry Connection
                        </button>
                        <div class="mt-4 text-xs text-gray-400">
                            Make sure the Python API server is running on port 5000
                        </div>
                    </td>
                </tr>
            `;
        }
    }
    async function loadActiveModelInfo() {
        const modelInfoContent = document.getElementById('active-model-info');

        try {
            const response = await fetch('../api/get_model_info.php', {
                method: 'GET',
                credentials: 'same-origin'
            });

            const responseText = await response.text();

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            let result;
            try {
                result = JSON.parse(responseText);
            } catch (parseError) {
                console.error('Model info JSON parse error:', parseError);
                throw new Error(`Invalid JSON response: ${responseText.substring(0, 200)}...`);
            }

            if (result.success && result.active_model) {
                const modelInfo = result.active_model;

                let trainingDate = 'Unknown';
                if (modelInfo.training_info.timestamp) {
                    const timestamp = modelInfo.training_info.timestamp;
                    if (timestamp.includes('_')) {
                        const [datePart, timePart] = timestamp.split('_');
                        const year = datePart.substring(0, 4);
                        const month = datePart.substring(4, 6);
                        const day = datePart.substring(6, 8);
                        const hour = timePart.substring(0, 2);
                        const minute = timePart.substring(2, 4);
                        const second = timePart.substring(4, 6);

                        const parsedDate = new Date(year, month - 1, day, hour, minute, second);
                        trainingDate = parsedDate.toLocaleString();
                    } else {
                        trainingDate = new Date(timestamp).toLocaleString();
                    }
                }

                console.log('Model info:', modelInfo.training_info.timestamp);
                console.log('Training date:', trainingDate);

                const testAccuracy = modelInfo.train_accuracy * 100;
                let accuracyStatus = { color: 'red', text: 'Needs Improvement' };
                if (testAccuracy >= 80) accuracyStatus = { color: 'green', text: 'Excellent' };
                else if (testAccuracy >= 70) accuracyStatus = { color: 'blue', text: 'Good' };
                else if (testAccuracy >= 60) accuracyStatus = { color: 'yellow', text: 'Fair' };

                modelInfoContent.innerHTML = `
                    <!-- Summary Cards -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                        <div class="bg-sky-400 text-white rounded-xl p-4 shadow-lg">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sky-100 text-sm">Train Accuracy</p>
                                    <p class="text-2xl font-bold">${(modelInfo.train_accuracy * 100).toFixed(1)}%</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="bg-emerald-400 text-white rounded-xl p-4 shadow-lg">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-emerald-100 text-sm">Test Accuracy</p>
                                    <p class="text-2xl font-bold">${(modelInfo.test_accuracy * 100).toFixed(1)}%</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="bg-violet-400 text-white rounded-xl p-4 shadow-lg">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-violet-100 text-sm">Total Samples</p>
                                    <p class="text-2xl font-bold">${modelInfo.training_info?.data_shape?.[0]?.toLocaleString() || 'N/A'}</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="bg-orange-400 text-white rounded-xl p-4 shadow-lg">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-orange-100 text-sm">Model Status</p>
                                    <p class="text-lg font-bold">${accuracyStatus.text}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Complete Model Information Table -->
                    <div class="bg-white rounded-xl p-6 shadow-lg border border-gray-200 mb-6">
                        <h3 class="text-xl font-semibold text-gray-800 mb-4 flex items-center">
                            Complete Model Information
                        </h3>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <tbody class="divide-y divide-gray-200">
                                    <tr class="hover:bg-gray-50">
                                        <td class="py-3 px-4 font-medium text-gray-700">Model Type</td>
                                        <td class="py-3 px-4 text-gray-900">${modelInfo.model_type || 'N/A'}</td>
                                    </tr>
                                    <tr class="hover:bg-gray-50">
                                        <td class="py-3 px-4 font-medium text-gray-700">Filename</td>
                                        <td class="py-3 px-4 text-gray-900 font-mono text-xs">${modelInfo.filename || 'N/A'}</td>
                                    </tr>
                                    <tr class="hover:bg-gray-50">
                                        <td class="py-3 px-4 font-medium text-gray-700">Created Date</td>
                                        <td class="py-3 px-4 text-gray-900">${trainingDate}</td>
                                    </tr>
                                    <tr class="hover:bg-gray-50">
                                        <td class="py-3 px-4 font-medium text-gray-700">File Size</td>
                                        <td class="py-3 px-4 text-gray-900">${(modelInfo.size / 1024).toFixed(1)} KB</td>
                                    </tr>
                                    <tr class="hover:bg-gray-50">
                                        <td class="py-3 px-4 font-medium text-gray-700">Target Column</td>
                                        <td class="py-3 px-4 text-gray-900">${modelInfo.target_column || 'N/A'}</td>
                                    </tr>
                                    <tr class="hover:bg-gray-50">
                                        <td class="py-3 px-4 font-medium text-gray-700">Number of Features</td>
                                        <td class="py-3 px-4 text-gray-900">${modelInfo.num_features || 0}</td>
                                    </tr>
                                    <tr class="hover:bg-gray-50">
                                        <td class="py-3 px-4 font-medium text-gray-700">Dataset Shape</td>
                                        <td class="py-3 px-4 text-gray-900">${modelInfo.training_info?.data_shape ? `${modelInfo.training_info.data_shape[0]} rows × ${modelInfo.training_info.data_shape[1]} columns` : 'N/A'}</td>
                                    </tr>
                                    <tr class="hover:bg-gray-50">
                                        <td class="py-3 px-4 font-medium text-gray-700">Number of Classes</td>
                                        <td class="py-3 px-4 text-gray-900">${modelInfo.training_info?.classes || 'N/A'}</td>
                                    </tr>
                                    <tr class="hover:bg-gray-50">
                                        <td class="py-3 px-4 font-medium text-gray-700">Class Imbalance Ratio</td>
                                        <td class="py-3 px-4 text-gray-900">${modelInfo.training_info?.class_imbalance_ratio?.toFixed(3) || 'N/A'}</td>
                                    </tr>
                                    ${modelInfo.training_info?.var_smoothing ? `
                                    <tr class="hover:bg-gray-50">
                                        <td class="py-3 px-4 font-medium text-gray-700">Variance Smoothing</td>
                                        <td class="py-3 px-4 text-gray-900 font-mono text-xs">${modelInfo.training_info.var_smoothing}</td>
                                    </tr>
                                    ` : ''}
                                    ${modelInfo.training_info?.priors ? `
                                    <tr class="hover:bg-gray-50">
                                        <td class="py-3 px-4 font-medium text-gray-700">Priors</td>
                                        <td class="py-3 px-4 text-gray-900">${modelInfo.training_info.priors}</td>
                                    </tr>
                                    ` : ''}
                                    ${modelInfo.training_info?.original_filename ? `
                                    <tr class="hover:bg-gray-50">
                                        <td class="py-3 px-4 font-medium text-gray-700">Original Data File</td>
                                        <td class="py-3 px-4 text-gray-900">${modelInfo.training_info.original_filename}</td>
                                    </tr>
                                    ` : ''}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Feature Names Table -->
                    ${modelInfo.feature_names && modelInfo.feature_names.length > 0 ? `
                    <div class="bg-white rounded-xl p-6 shadow-lg border border-gray-200 mb-6">
                        <h3 class="text-xl font-semibold text-gray-800 mb-4 flex items-center">
                            Feature Names
                        </h3>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                            ${modelInfo.feature_names.map((feature, index) => `
                                <div class="bg-cyan-100 border border-cyan-200 rounded-lg p-3 flex items-center space-x-3 hover:shadow-md transition-shadow">
                                    <div class="bg-cyan-500 text-white rounded-full w-6 h-6 flex items-center justify-center text-xs font-bold">${index + 1}</div>
                                    <div class="font-medium text-gray-800">${feature.replace(/_/g, ' ')}</div>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                    ` : ''}

                    <!-- Cross Validation Scores -->
                    ${modelInfo.training_info?.cv_scores && modelInfo.training_info.cv_scores.length > 0 ? `
                    <div class="bg-white rounded-2xl p-8 shadow-xl border border-gray-200 mb-8">
                        <h3 class="text-2xl font-bold text-gray-800 mb-6 flex items-center gap-2">
                            Cross Validation Scores
                        </h3>
                        <div class="flex flex-col lg:flex-row gap-8 items-center">
                            <div class="bg-gray-50 rounded-2xl p-6 flex items-center justify-center shadow-md">
                                <canvas id="cvScoresChart" width="320" height="320"></canvas>
                            </div>
                            <div class="flex-1 flex flex-col gap-4">
                                <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                                    ${modelInfo.training_info.cv_scores.map((score, index) => `
                                        <div class="bg-indigo-200 border border-indigo-300 rounded-xl p-4 flex flex-col items-center shadow-sm">
                                            <div class="text-xs text-indigo-700 font-semibold mb-1">Fold ${index + 1}</div>
                                            <div class="text-2xl font-extrabold text-indigo-900">${(score * 100).toFixed(1)}%</div>
                                        </div>
                                    `).join('')}
                                </div>
                                <div class="flex flex-col md:flex-row gap-4 mt-6">
                                    <div class="flex-1 bg-gradient-to-r from-gray-50 to-gray-100 rounded-xl p-4 flex flex-col items-center shadow">
                                        <div class="text-sm text-gray-600">Mean CV Score</div>
                                        <div class="text-2xl font-bold text-gray-800">${(modelInfo.training_info.cv_scores.reduce((a, b) => a + b, 0) / modelInfo.training_info.cv_scores.length * 100).toFixed(1)}%</div>
                                    </div>
                                    <div class="flex-1 bg-gradient-to-r from-gray-50 to-gray-100 rounded-xl p-4 flex flex-col items-center shadow">
                                        <div class="text-sm text-gray-600">Standard Deviation</div>
                                        <div class="text-2xl font-bold text-gray-800">${(modelInfo.training_info.cv_std * 100).toFixed(2)}%</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    ` : ''}
                    
                    <!-- Class Distribution with Pie Chart -->
                    ${modelInfo.training_info?.class_distribution ? `
                    <div class="bg-white rounded-xl p-6 shadow-lg border border-gray-200 mb-6">
                        <h3 class="text-xl font-semibold text-gray-800 mb-4 flex items-center">
                            Class Distribution
                        </h3>
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            <!-- Pie Chart -->
                            <div class="flex items-center justify-center">
                                <div class="relative w-64 h-64">
                                    <canvas id="classDistributionChart" width="256" height="256"></canvas>
                                </div>
                            </div>
                            <!-- Class Stats -->
                            <div class="space-y-4">
                                ${Object.entries(modelInfo.training_info.class_distribution).map(([key, value]) => {
                    const moodLabels = {
                        '0': { label: 'Happy', color: 'emerald', bgColor: 'bg-emerald-200 border-emerald-300 text-emerald-800' },
                        '1': { label: 'Neutral', color: 'amber', bgColor: 'bg-amber-200 border-amber-300 text-amber-800' },
                        '2': { label: 'Stressed', color: 'rose', bgColor: 'bg-rose-200 border-rose-300 text-rose-800' }
                    };
                    const moodInfo = moodLabels[key] || { label: `Class ${key}`, color: 'gray', bgColor: 'bg-gray-200 border-gray-300 text-gray-800' };
                    const total = Object.values(modelInfo.training_info.class_distribution).reduce((sum, val) => sum + val, 0);
                    const percentage = ((value / total) * 100).toFixed(1);
                    return `
                                                    <div class="border rounded-lg p-4 shadow-md ${moodInfo.bgColor}">
                                                        <div class="flex items-center justify-between">
                                                            <div class="flex items-center space-x-3">
                                                                <div>
                                                                    <div class="font-semibold text-lg">${moodInfo.label}</div>
                                                                    <div class="text-sm opacity-75">${value.toLocaleString()} samples</div>
                                                                </div>
                                                            </div>
                                                            <div class="text-right">
                                                                <div class="text-2xl font-bold">${percentage}%</div>
                                                                <div class="text-xs opacity-75">of total</div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                `;
                }).join('')}
                            </div>
                        </div>
                    </div>
                    ` : ''}
                `;

                setTimeout(() => {
                    if (modelInfo.training_info?.class_distribution) {
                        drawClassDistributionChart(modelInfo.training_info.class_distribution);
                    }
                    if (modelInfo.training_info?.cv_scores) {
                        drawCVScoresChart(modelInfo.training_info.cv_scores);
                    }
                }, 100);
            } else if (result.success && !result.active_model) {
                modelInfoContent.innerHTML = `
                    <div class="text-center py-12">
                        <div class="w-20 h-20 bg-gradient-to-br from-blue-100 to-purple-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        </div>
                        <h4 class="text-xl font-bold text-gray-800 mb-2">No Model Found</h4>
                        <p class="text-gray-500 mb-4 max-w-md mx-auto">Start by uploading a CSV file and training your first AI model to see detailed analytics and performance metrics here.</p>
                        <div class="flex justify-center space-x-4">
                            <div class="bg-blue-50 rounded-lg px-4 py-2 text-sm text-blue-700">
                                Upload Data
                            </div>
                            <div class="bg-purple-50 rounded-lg px-4 py-2 text-sm text-purple-700">
                                Train Model
                            </div>
                            <div class="bg-green-50 rounded-lg px-4 py-2 text-sm text-green-700">
                                Make Predictions
                            </div>
                        </div>
                    </div>
                `;
            } else {
                throw new Error(result.error || 'Failed to load model information');
            }

        } catch (error) {
            modelInfoContent.innerHTML = `
                <div class="text-center py-12">
                    <h4 class="text-xl font-bold text-gray-800 mb-2">Connection Error</h4>
                    <p class="text-gray-500 mb-4 max-w-md mx-auto">${error.message}</p>
                    <button onclick="loadActiveModelInfo()" 
                        class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-blue-500 to-purple-600 text-white rounded-lg hover:from-blue-600 hover:to-purple-700 transition-all duration-200 font-medium shadow-lg hover:shadow-xl transform hover:-translate-y-0.5">
                        Retry Connection
                    </button>
                    <div class="mt-4 text-xs text-gray-400">
                        Make sure the Python API server is running on port 5000
                    </div>
                </div>
            `;
        }
    }

    async function loadQuickModelStatus() {
        const statusText = document.getElementById('model-status-text');
        const accuracyText = document.getElementById('model-accuracy-text');
        const statusIndicator = document.querySelector('#quick-model-status .w-3.h-3');

        try {
            const response = await fetch('../api/get_model_info.php', {
                method: 'GET',
                credentials: 'same-origin'
            });

            const responseText = await response.text();

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            let result;
            try {
                result = JSON.parse(responseText);
            } catch (parseError) {
                throw new Error(`Invalid JSON response`);
            }

            if (result.success && result.active_model) {
                const accuracy = (result.active_model.train_accuracy * 100).toFixed(1);
                statusText.textContent = 'Active';
                accuracyText.textContent = `${accuracy}%`;

                statusIndicator.className = 'w-3 h-3 rounded-full animate-pulse';
                if (accuracy >= 80) {
                    statusIndicator.classList.add('bg-green-500');
                } else if (accuracy >= 70) {
                    statusIndicator.classList.add('bg-blue-500');
                } else if (accuracy >= 60) {
                    statusIndicator.classList.add('bg-yellow-500');
                } else {
                    statusIndicator.classList.add('bg-red-500');
                }
            } else {
                statusText.textContent = 'No Model Available';
                accuracyText.textContent = '--';
                statusIndicator.className = 'w-3 h-3 bg-gray-400 rounded-full';
            }

        } catch (error) {
            statusText.textContent = 'Connection Error';
            accuracyText.textContent = '--';
            statusIndicator.className = 'w-3 h-3 bg-red-500 rounded-full';
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        loadActiveModelInfo();
        loadModelsInfo();
        loadQuickModelStatus();

        const csvDataExists = <?php echo !empty($csvData) ? 'true' : 'false'; ?>;
        const isTrainingMode = <?php echo (isset($_GET['action']) && $_GET['action'] === 'create_model') ? 'true' : 'false'; ?>;

        const availableSections = [
            'file-upload',
            'model-info',
            'model-testing',
            'all-models',
            'user-management',
            'account-settings'
        ];

        let firstSectionShown = false;

        if (csvDataExists || isTrainingMode) {
            showSection('file-upload');
            firstSectionShown = true;
            setTimeout(() => {
                const allNavItems = document.querySelectorAll('.nav-item');
                allNavItems.forEach(item => item.classList.remove('active'));
                const fileUploadNav = document.getElementById('nav-file-upload');
                if (fileUploadNav) {
                    fileUploadNav.classList.add('active');
                }
            }, 0);
        } else {
            for (const section of availableSections) {
                const sectionElement = document.getElementById(`section-${section}`);
                if (sectionElement && !firstSectionShown) {
                    showSection(section);
                    firstSectionShown = true;
                    break;
                }
            }
        }
        if (!firstSectionShown) {
            showSection('model-testing');
        }

        const navItems = document.querySelectorAll('.nav-item');
        navItems.forEach(item => {
            item.addEventListener('mouseenter', function () {
                if (!this.classList.contains('active')) {
                    this.style.transform = 'translateX(5px)';
                }
            });

            item.addEventListener('mouseleave', function () {
                if (!this.classList.contains('active')) {
                    this.style.transform = 'translateX(0)';
                }
            });
        });
    });

    function showSection(sectionName) {
        const allSections = document.querySelectorAll('.section-content');
        allSections.forEach(section => {
            section.style.display = 'none';
        });

        const allNavItems = document.querySelectorAll('.nav-item');
        allNavItems.forEach(item => {
            item.classList.remove('active');
        });

        const targetSection = document.getElementById(`section-${sectionName}`);
        if (targetSection) {
            targetSection.style.display = 'block';
            targetSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        if (sectionName === 'model-testing') {
            loadQuickModelStatus();
        } else if (sectionName === 'model-info') {
            loadActiveModelInfo();
        } else if (sectionName === 'all-models') {
            loadModelsInfo();
        }

        const activeNavItem = document.getElementById(`nav-${sectionName}`);
        if (activeNavItem) {
            activeNavItem.classList.add('active');

            activeNavItem.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest',
                inline: 'nearest'
            });
        }

        if (window.innerWidth <= 768) {
            closeSidebar();
        }

        const mainContent = document.querySelector('.main-content');
        if (mainContent) {
            mainContent.scrollTo({ top: 0, behavior: 'smooth' });
        }
    }

    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.querySelector('.sidebar-overlay');

        sidebar.classList.toggle('open');
        overlay.classList.toggle('show');
    }

    function closeSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.querySelector('.sidebar-overlay');

        sidebar.classList.remove('open');
        overlay.classList.remove('show');
    }

    document.addEventListener('click', function (event) {
        const sidebar = document.getElementById('sidebar');
        const menuBtn = document.querySelector('.mobile-menu-btn');

        if (window.innerWidth <= 768 &&
            !sidebar.contains(event.target) &&
            !menuBtn.contains(event.target) &&
            sidebar.classList.contains('open')) {
            closeSidebar();
        }
    });

    function drawClassDistributionChart(classDistribution) {
        const canvas = document.getElementById('classDistributionChart');
        if (!canvas) return;

        const ctx = canvas.getContext('2d');

        const moodLabels = {
            '0': { label: 'Happy', color: 'oklch(90.5% 0.093 164.15)' },
            '1': { label: 'Neutral', color: 'oklch(92.4% 0.12 95.746)' },
            '2': { label: 'Stressed', color: 'oklch(89.2% 0.058 10.001)' }
        };

        const labels = [];
        const data = [];
        const colors = [];

        Object.entries(classDistribution).forEach(([key, value]) => {
            const moodInfo = moodLabels[key] || { label: `Class ${key}`, color: '#6B7280' };
            labels.push(moodInfo.label);
            data.push(value);
            colors.push(moodInfo.color);
        });

        new Chart(ctx, {
            type: 'pie',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: colors,
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true,
                            font: {
                                size: 12
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((context.parsed / total) * 100).toFixed(1);
                                return `${context.label}: ${context.parsed.toLocaleString()} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
    }

    function drawCVScoresChart(cvScores) {
        const canvas = document.getElementById('cvScoresChart');
        if (!canvas) return;

        const ctx = canvas.getContext('2d');
        const labels = cvScores.map((_, index) => `Fold ${index + 1}`);
        const data = cvScores.map(score => (score * 100).toFixed(1));

        new Chart(ctx, {
            type: 'radar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Cross Validation Score (%)',
                    data: data,
                    backgroundColor: 'rgba(99, 102, 241, 0.2)',
                    borderColor: 'rgba(99, 102, 241, 1)',
                    pointBackgroundColor: 'rgba(99, 102, 241, 1)',
                    pointBorderColor: '#fff',
                    pointHoverBackgroundColor: '#fff',
                    pointHoverBorderColor: 'rgba(99, 102, 241, 1)',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            font: { size: 14 }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return `Score: ${context.formattedValue}%`;
                            }
                        }
                    }
                },
                scales: {
                    r: {
                        angleLines: { display: true },
                        min: 0,
                        max: 100,
                        pointLabels: {
                            font: { size: 13 }
                        },
                        ticks: {
                            callback: function (value) { return value + '%'; },
                            stepSize: 20,
                            font: { size: 12 }
                        },
                        grid: {
                            color: 'rgba(0,0,0,0.08)'
                        }
                    }
                }
            }
        });
    }
</script>

</html>