<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
} else if ($_SESSION['role'] !== 'ADMIN') {
    header('Location: index.php');
    exit;
}

require_once 'config.php';

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
    $uploadDir = __DIR__ . '/upload/';
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

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Youth Mental Health - Admin Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
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
            max-width: 1200px;
            margin: 0 auto;
            padding: 1.5rem;
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
    </style>
</head>

<body>

    <div class="container">
        <main class="grid grid-cols-1 lg:grid-cols-3 gap-8">

            <?php if (empty($csvData)): ?>
                <section id="file-upload-section" class="card col-span-3">
                    <form action="dashboard.php" method="post" enctype="multipart/form-data">
                        <div class="mb-4">
                            <div id="drop-area"
                                class="flex flex-col items-center justify-center w-full h-32 border-2 border-dashed border-blue-400 rounded-lg cursor-pointer bg-blue-50 hover:bg-blue-100 transition-colors duration-200 relative">
                                <svg class="w-10 h-10 text-blue-500 mb-2" fill="none" stroke="currentColor" stroke-width="2"
                                    viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
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

                </section>
            <?php endif; ?>

            <?php if (!empty($csvData) && (!isset($_GET['action']) || $_GET['action'] !== 'create_model')): ?>
                <section id="csv-data-section" class="card col-span-3">
                    <div class="flex justify-between items-center mb-4">
                        <h1 class="text-3xl md:text-4xl font-bold text-blue-900">CSV Data</h1>
                        <div class="flex gap-2">
                            <a href="dashboard.php?action=create_model"
                                class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">Create Model</a>
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
                            (<?php echo $_SESSION['csv_row_original']; ?> original, <?php echo $_SESSION['csv_row_cleaned']; ?>
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
                </section>
            <?php endif; ?>

            <?php if (isset($_GET['action']) && $_GET['action'] === 'create_model' && !empty($csvData)): ?>
                <section id="model-data-section" class="card col-span-3">
                    <div class="flex justify-between items-center mb-4">
                        <h1 class="text-3xl md:text-4xl font-bold text-blue-900">Model Data (Current CSV File)</h1>
                        <div class="flex gap-2">
                            <a href="dashboard.php"
                                class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">Back to CSV Data</a>
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
                        <div class="mb-2"><strong>File Name:</strong> <?php echo htmlspecialchars($csvFileName); ?></div>
                        <div class="mb-2"><strong>File Size:</strong> <?php echo number_format($csvFileSize / 1024, 2); ?>
                            KB</div>
                        <div class="mb-2"><strong>Created At:</strong> <?php echo htmlspecialchars($csvFileCreated); ?>
                        </div>
                        <?php if (isset($_SESSION['csv_row_cleaned'])): ?>
                            <div><strong>Total Rows:</strong> <?php echo $_SESSION['csv_row_cleaned']; ?> (after cleaning)</div>
                        <?php endif; ?>
                    </div>

                    <div class="mt-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                        <h3 class="text-lg font-semibold text-yellow-800 mb-2">⚠️ Model Training Status</h3>
                        <p class="text-yellow-700 mb-4">Your CSV data has been processed and is ready for machine learning
                            model training. Click the button below to start the training process.</p>

                        <div id="training-status" class="mb-4 hidden">
                            <div class="flex items-center mb-2">
                                <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-yellow-600 mr-2"></div>
                                <span class="text-yellow-700 font-medium">Training model...</span>
                            </div>
                            <div class="w-full bg-yellow-200 rounded-full h-2">
                                <div id="progress-bar" class="bg-yellow-600 h-2 rounded-full transition-all duration-500"
                                    style="width: 0%"></div>
                            </div>
                        </div>

                        <div id="training-result" class="mb-4 hidden"></div>


                        <button id="start-training-btn" onclick="startModelTraining()"
                            class="px-6 py-3 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700 transition-colors duration-200 font-semibold">
                            🚀 Start Model Training
                        </button>
                    </div>

                </section>
            <?php endif; ?>

            <section id="model-testing-section" class="card col-span-3">
                <h2 class="text-2xl font-bold text-blue-900 mb-6">Test Mental Health Model</h2>
                <p class="text-gray-600 mb-6">Use the trained AI model to predict mental health outcomes based on
                    lifestyle factors.</p>

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
                            <label for="screen_time" class="block text-sm font-semibold text-gray-700 mb-2">Screen Time
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
                            <input type="number" id="study_hours" name="Daily_Study_Hours" min="0" max="16" step="0.1"
                                required
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
                            <label for="mental_clarity" class="block text-sm font-semibold text-gray-700 mb-2">Mental
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
                            <h3 class="text-lg font-bold text-gray-800 mb-3">🎯 Prediction Results</h3>
                            <div id="prediction-content" class="space-y-3">
                                <!-- Results will be populated here -->
                            </div>
                        </div>
                    </div>

                    <div id="prediction-loading" class="mt-6 hidden">
                        <div class="bg-blue-100 rounded-lg p-6 text-center">
                            <div class="inline-flex items-center">
                                <div class="animate-spin rounded-full h-6 w-6 border-b-2 border-blue-600 mr-3"></div>
                                <span class="text-blue-700 font-medium">🧠 AI is analyzing the data...</span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section id="project-overview-section" class="card lg:col-span-3">
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

            <section id="project-overview-section" class="card lg:col-span-2">
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

            <section id="user-actions-section" class="card lg:col-span-1 border-0 shadow-xl">
                <h1 class="text-2xl md:text-3xl font-bold text-blue-900 mb-6">Account Settings</h1>

                <div id="user-actions-buttons" class="space-y-4" <?php if ((isset($_POST['update_account']) && !$accountSuccess) || (isset($_POST['update_password']) && !$passwordSuccess))
                    echo 'style="display:none;"'; ?>>

                    <button type="button" onclick="showAccountForm()"
                        class="group w-full p-4 bg-gradient-to-r from-cyan-500 to-blue-600 text-white rounded-xl hover:from-cyan-600 hover:to-blue-700 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105 active:scale-95">
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
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7">
                                </path>
                            </svg>
                        </div>
                    </button>

                    <button type="button" onclick="showPasswordForm()"
                        class="group w-full p-4 bg-gradient-to-r from-indigo-500 to-purple-600 text-white rounded-xl hover:from-indigo-600 hover:to-purple-700 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105 active:scale-95">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-3">
                                <div
                                    class="w-10 h-10 bg-white/20 rounded-lg flex items-center justify-center backdrop-blur-sm">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z">
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
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7">
                                </path>
                            </svg>
                        </div>
                    </button>

                    <a href="logout.php"
                        class="group block w-full p-4 bg-gradient-to-r from-red-500 to-pink-600 text-white rounded-xl hover:from-red-600 hover:to-pink-700 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105 active:scale-95">
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
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7">
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
                            class="flex-1 px-6 py-3 bg-gradient-to-r from-cyan-500 to-blue-600 text-white rounded-lg hover:from-cyan-600 hover:to-blue-700 font-semibold shadow-lg hover:shadow-xl transform hover:scale-105 active:scale-95 transition-all duration-200">
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
                                    d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z">
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
                                            d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z">
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
                            class="flex-1 px-6 py-3 bg-gradient-to-r from-indigo-500 to-purple-600 text-white rounded-lg hover:from-indigo-600 hover:to-purple-700 font-semibold shadow-lg hover:shadow-xl transform hover:scale-105 active:scale-95 transition-all duration-200">
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

        const icon = type === 'success' ? '✅' : '❌';
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
            startBtn.disabled = true;
            startBtn.textContent = '⏳ Training in progress...';
            trainingStatus.classList.remove('hidden');
            trainingResult.classList.add('hidden');

            let progress = 0;
            const progressInterval = setInterval(() => {
                progress += Math.random() * 15;
                if (progress > 90) progress = 90;
                progressBar.style.width = progress + '%';
            }, 500);

            console.log('Starting model training...');

            const response = await fetch('train_model.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'same-origin'
            });

            console.log('Response status:', response.status);
            console.log('Response headers:', response.headers);

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

            clearInterval(progressInterval);
            progressBar.style.width = '100%';

            console.log('Training result:', result);

            if (result.success) {
                trainingResult.innerHTML = `
                    <div class="p-4 bg-green-100 border border-green-200 rounded-lg">
                        <h4 class="text-green-800 font-semibold mb-2">✅ Training Completed Successfully!</h4>
                        <div class="text-green-700 text-sm space-y-1">
                            <div><strong>Model File:</strong> ${result.model_filename}</div>
                            <div><strong>Train Accuracy:</strong> ${(result.train_accuracy * 100).toFixed(2)}%</div>
                            <div><strong>Test Accuracy:</strong> ${(result.test_accuracy * 100).toFixed(2)}%</div>
                            <div><strong>Total Rows:</strong> ${result.data_info.total_rows}</div>
                            <div><strong>Features Used:</strong> ${result.data_info.total_features}</div>
                            <div><strong>Target:</strong> ${result.data_info.target_column}</div>
                            <div><strong>Model Type:</strong> ${result.data_info.model_type}</div>
                            ${result.data_info.class_distribution ? `<div><strong>Class Distribution:</strong> ${JSON.stringify(result.data_info.class_distribution)}</div>` : ''}
                        </div>
                    </div>
                `;
                const finalAccuracy = result.test_accuracy || result.train_accuracy;
                showToast(`Model trained successfully! Test Accuracy: ${(finalAccuracy * 100).toFixed(1)}%`, 'success');

                startBtn.style.display = 'none';

                setTimeout(() => {
                    loadActiveModelInfo();
                }, 1000);
            } else {
                let debugInfo = '';
                if (result.debug) {
                    debugInfo = `
                        <div class="mt-2 text-xs">
                            <strong>Debug Info:</strong><br>
                            Session has CSV: ${result.debug.session_has_csv}<br>
                            CSV path: ${result.debug.csv_path}<br>
                            File exists: ${result.debug.file_exists}<br>
                            Timestamp: ${result.debug.timestamp}
                        </div>
                    `;
                }

                if (result.debug_info) {
                    debugInfo += `
                        <div class="mt-3 p-2 bg-yellow-50 rounded text-xs">
                            <strong>Model Analysis:</strong><br>
                            Target Column: ${result.debug_info.target_column}<br>
                            Accuracy: ${(result.debug_info.accuracy * 100).toFixed(1)}%<br>
                            Classes: ${result.debug_info.unique_classes}<br>
                            Training Samples: ${result.debug_info.training_samples}<br>
                            Features Used: ${result.debug_info.features_used}<br>
                            ${result.debug_info.better_target ? `<strong>Better Target:</strong> ${result.debug_info.better_target}<br>` : ''}
                            <strong>Suggestion:</strong> ${result.debug_info.suggestion}
                        </div>
                    `;
                }

                trainingResult.innerHTML = `
                    <div class="p-4 bg-red-100 border border-red-200 rounded-lg">
                        <h4 class="text-red-800 font-semibold mb-2">❌ Training Failed</h4>
                        <div class="text-red-700 text-sm">
                            <strong>Error:</strong> ${result.error || 'Unknown error'}
                        </div>
                        ${debugInfo}
                    </div>
                `;
                throw new Error(result.error || 'Training failed');
            }

        } catch (error) {
            console.error('Training error:', error);

            if (trainingResult.classList.contains('hidden')) {
                trainingResult.innerHTML = `
                    <div class="p-4 bg-red-100 border border-red-200 rounded-lg">
                        <h4 class="text-red-800 font-semibold mb-2">❌ Training Failed</h4>
                        <div class="text-red-700 text-sm">
                            <strong>Error:</strong> ${error.message}
                        </div>
                        <div class="text-red-600 text-xs mt-2">
                            Check browser console for more details. Make sure Python API is running.
                        </div>
                    </div>
                `;
            }

            showToast('Training failed: ' + error.message, 'error');
        } finally {
            startBtn.disabled = false;
            startBtn.textContent = '🚀 Start Model Training';
            trainingStatus.classList.add('hidden');
            trainingResult.classList.remove('hidden');
        }
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

            const response = await fetch('predict_model.php', {
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
                    'Happy': { label: 'Happy/Positive Mood', color: 'green', emoji: '😊', description: 'The model predicts a positive mood state. Current lifestyle factors appear supportive.' },
                    'Neutral': { label: 'Neutral Mood', color: 'yellow', emoji: '😐', description: 'The model predicts a balanced mood state. Maintaining current habits may be beneficial.' },
                    'Stressed': { label: 'Stressed/Low Mood', color: 'red', emoji: '😢', description: 'The model predicts a stressed state. Consider lifestyle adjustments and stress management.' }
                };

                const predictedLabel = result.predicted_label || 'Unknown';
                const prediction = result.prediction;
                const confidence = result.confidence || result.probabilities;
                const moodInfo = moodMappings[predictedLabel] || { label: predictedLabel, color: 'gray', emoji: '❓', description: 'Prediction result unclear.' };

                contentDiv.innerHTML = `
                    <div class="flex items-center space-x-4 mb-4">
                        <div class="text-4xl">${moodInfo.emoji}</div>
                        <div>
                            <h4 class="text-xl font-bold text-${moodInfo.color}-600">${moodInfo.label}</h4>
                            <p class="text-gray-600">${moodInfo.description}</p>
                        </div>
                    </div>
                    
                    <div class="bg-gray-50 rounded-lg p-4 mb-4">
                            <h5 class="font-semibold text-gray-700 mb-2">Confidence Levels : ${(confidence * 100).toFixed(2)}%</h5>
                    </div>
                    
                    <div class="bg-blue-50 rounded-lg p-4">
                        <h5 class="font-semibold text-blue-800 mb-2">📋 Input Summary:</h5>
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
                        ⚠️ This is an AI prediction for educational purposes only. For serious mental health concerns, please consult a healthcare professional.
                    </div>
                `;
            } else {
                throw new Error(result.error || 'Prediction failed');
            }

        } catch (error) {
            console.error('Prediction error:', error);
            contentDiv.innerHTML = `
                <div class="p-4 bg-red-100 border border-red-200 rounded-lg">
                    <h4 class="text-red-800 font-semibold mb-2">❌ Prediction Failed</h4>
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

    async function loadModelsInfo() {
        const modelsList = document.getElementById('models-list');

        try {

            const response = await fetch('get_model_info.php', {
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

            const models = result.models || [];

            if (result.success && models.length > 0) {

                models.forEach(model => {
                    let trainingDate = 'Unknown';
                    if (model.training_info.timestamp) {
                        const timestamp = model.training_info.timestamp;
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
                    modelsList.innerHTML += `
                        <tr class="border-b border-gray-100 hover:bg-blue-50 transition-colors duration-150">
                            <td class="py-3 px-5 text-sm">${model.filename}</td>
                            <td class="py-3 px-5 text-sm">${(model.train_accuracy * 100).toFixed(1)}%</td>
                            <td class="py-3 px-5 text-sm">${(model.test_accuracy * 100).toFixed(1)}%</td>
                            <td class="py-3 px-5 text-sm">${model.training_info.data_shape[0]}</td>
                            <td class="py-3 px-5 text-sm">${model.num_features}</td>
                            <td class="py-3 px-5 text-sm">${model.target_column}</td>
                            <td class="py-3 px-5 text-sm">${trainingDate}</td>
                        </tr>
                    `;
                });
            } else if (result.success && result.models <= 0) {
                modelsList.innerHTML += `
                <tr class="text-center py-12">
                    <td colspan="7" class="text-gray-500 py-12">
                        <h4 class="text-xl font-bold text-gray-800 mb-2">No Model Found!</h4>
                    </td>
                </tr>
                `;
            } else {
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
                // throw new Error(result.error || 'Failed to load model information');
            }
        } catch (error) {
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
        const modelInfoContent = document.getElementById('model-info-content');

        try {
            const response = await fetch('get_model_info.php', {
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
                let accuracyStatus = { color: 'red', text: 'Needs Improvement', icon: '⚠️' };
                if (testAccuracy >= 80) accuracyStatus = { color: 'green', text: 'Excellent', icon: '🎯' };
                else if (testAccuracy >= 70) accuracyStatus = { color: 'blue', text: 'Good', icon: '✅' };
                else if (testAccuracy >= 60) accuracyStatus = { color: 'yellow', text: 'Fair', icon: '🔄' };

                modelInfoContent.innerHTML = `
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                        <div class="bg-gradient-to-br from-${accuracyStatus.color}-50 to-${accuracyStatus.color}-100 rounded-xl p-4 border border-${accuracyStatus.color}-200 animate-fadeInUp hover:scale-105 transition-transform duration-300 hover:shadow-lg" style="animation-delay: 0.1s;">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-${accuracyStatus.color}-600 text-sm font-medium">Model Accuracy</span>
                                <span class="text-lg">${accuracyStatus.icon}</span>
                            </div>
                            <div class="text-2xl font-bold text-${accuracyStatus.color}-700">${testAccuracy.toFixed(1)}%</div>
                            <div class="text-xs text-${accuracyStatus.color}-600 mt-1">${accuracyStatus.text}</div>
                        </div>
                        
                        <div class="bg-gradient-to-br from-purple-50 to-purple-100 rounded-xl p-4 border border-purple-200 animate-fadeInUp hover:scale-105 transition-transform duration-300 hover:shadow-lg" style="animation-delay: 0.2s;">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-purple-600 text-sm font-medium">Training Data</span>
                                <span class="text-lg">📊</span>
                            </div>
                            <div class="text-2xl font-bold text-purple-700">${modelInfo.training_info.data_shape[0] || 'N/A'}</div>
                            <div class="text-xs text-purple-600 mt-1">samples trained</div>
                        </div>
                        
                        <div class="bg-gradient-to-br from-indigo-50 to-indigo-100 rounded-xl p-4 border border-indigo-200 animate-fadeInUp hover:scale-105 transition-transform duration-300 hover:shadow-lg" style="animation-delay: 0.3s;">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-indigo-600 text-sm font-medium">Features</span>
                                <span class="text-lg">🔍</span>
                            </div>
                            <div class="text-2xl font-bold text-indigo-700">${modelInfo.num_features}</div>
                            <div class="text-xs text-indigo-600 mt-1">input variables</div>
                        </div>
                        
                        <div class="bg-gradient-to-br from-cyan-50 to-cyan-100 rounded-xl p-4 border border-cyan-200 animate-fadeInUp hover:scale-105 transition-transform duration-300 hover:shadow-lg" style="animation-delay: 0.4s;">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-cyan-600 text-sm font-medium">Classes</span>
                                <span class="text-lg">🎯</span>
                            </div>
                            <div class="text-2xl font-bold text-cyan-700">${modelInfo.training_info.classes}</div>
                            <div class="text-xs text-cyan-600 mt-1">mood categories</div>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div class="bg-gray-50 rounded-xl p-5 border border-gray-200 animate-slideInRight hover:shadow-md transition-shadow duration-300" style="animation-delay: 0.5s;">
                            <div class="flex items-center mb-4">
                                <div class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center mr-3">
                                    <span class="text-blue-600">⚙️</span>
                                </div>
                                <h4 class="text-lg font-bold text-gray-800">Model Configuration</h4>
                            </div>
                            <div class="space-y-3 text-sm">
                                <div class="flex justify-between items-center py-2 border-b border-gray-200 last:border-b-0">
                                    <span class="font-medium text-gray-600">Algorithm</span>
                                    <span class="text-gray-800 font-semibold">${modelInfo.model_type}</span>
                                </div>
                                <div class="flex justify-between items-center py-2 border-b border-gray-200 last:border-b-0">
                                    <span class="font-medium text-gray-600">Target Variable</span>
                                    <span class="text-gray-800 font-semibold">${modelInfo.target_column}</span>
                                </div>
                                <div class="flex justify-between items-center py-2 border-b border-gray-200 last:border-b-0">
                                    <span class="font-medium text-gray-600">Training Date</span>
                                    <span class="text-gray-800 font-semibold">${trainingDate}</span>
                                </div>
                                <div class="flex justify-between items-center py-2">
                                    <span class="font-medium text-gray-600">Model File</span>
                                    <span class="text-gray-800 font-semibold text-xs truncate" title="${modelInfo.filename}">${modelInfo.filename}</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="bg-gray-50 rounded-xl p-5 border border-gray-200 animate-slideInRight hover:shadow-md transition-shadow duration-300" style="animation-delay: 0.6s;">
                            <div class="flex items-center mb-4">
                                <div class="w-8 h-8 bg-green-100 rounded-lg flex items-center justify-center mr-3">
                                    <span class="text-green-600">📈</span>
                                </div>
                                <h4 class="text-lg font-bold text-gray-800">Performance Metrics</h4>
                            </div>
                            <div class="space-y-4">
                                <!-- Training Accuracy Bar -->
                                <div>
                                    <div class="flex justify-between items-center mb-2">
                                        <span class="text-sm font-medium text-gray-600">Training Accuracy</span>
                                        <span class="text-sm font-bold text-gray-800">${(modelInfo.train_accuracy * 100).toFixed(1)}%</span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2">
                                        <div class="bg-gradient-to-r from-blue-500 to-blue-600 h-2 rounded-full transition-all duration-500" style="width: ${(modelInfo.train_accuracy * 100).toFixed(1)}%"></div>
                                    </div>
                                </div>
                                
                                <div>
                                    <div class="flex justify-between items-center mb-2">
                                        <span class="text-sm font-medium text-gray-600">Testing Accuracy</span>
                                        <span class="text-sm font-bold text-gray-800">${(modelInfo.test_accuracy * 100).toFixed(1)}%</span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2">
                                        <div class="bg-gradient-to-r from-green-500 to-green-600 h-2 rounded-full transition-all duration-500" style="width: ${(modelInfo.test_accuracy * 100).toFixed(1)}%"></div>
                                    </div>
                                </div>
                                
                                ${modelInfo.cv_accuracy ? `
                                <div>
                                    <div class="flex justify-between items-center mb-2">
                                        <span class="text-sm font-medium text-gray-600">Cross Validation</span>
                                        <span class="text-sm font-bold text-gray-800">${(modelInfo.cv_accuracy * 100).toFixed(1)}%</span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2">
                                        <div class="bg-gradient-to-r from-purple-500 to-purple-600 h-2 rounded-full transition-all duration-500" style="width: ${(modelInfo.cv_accuracy * 100).toFixed(1)}%"></div>
                                    </div>
                                </div>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                    
                    ${modelInfo.class_distribution ? `
                    <div class="mt-6 bg-gradient-to-r from-gray-50 to-gray-100 rounded-xl p-5 border border-gray-200">
                        <div class="flex items-center mb-4">
                            <div class="w-8 h-8 bg-orange-100 rounded-lg flex items-center justify-center mr-3">
                                <span class="text-orange-600">🎯</span>
                            </div>
                            <h4 class="text-lg font-bold text-gray-800">Class Distribution</h4>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            ${Object.entries(modelInfo.class_distribution).map(([key, value]) => {
                    const moodLabels = {
                        '0': { label: 'Happy', emoji: '😊', color: 'green', bgColor: 'from-green-400 to-green-500' },
                        '1': { label: 'Neutral', emoji: '😐', color: 'yellow', bgColor: 'from-yellow-400 to-yellow-500' },
                        '2': { label: 'Stressed', emoji: '😢', color: 'red', bgColor: 'from-red-400 to-red-500' },
                    };
                    const moodInfo = moodLabels[key] || { label: `Class ${key}`, emoji: '❓', color: 'gray', bgColor: 'from-gray-400 to-gray-500' };
                    const total = Object.values(modelInfo.class_distribution).reduce((sum, val) => sum + val, 0);
                    const percentage = ((value / total) * 100).toFixed(1);
                    return `
                                    <div class="bg-white rounded-lg p-4 border border-gray-200 hover:shadow-md transition-all duration-200">
                                        <div class="flex items-center justify-between mb-3">
                                            <div class="flex items-center space-x-2">
                                                <span class="text-2xl">${moodInfo.emoji}</span>
                                                <span class="font-semibold text-gray-800">${moodInfo.label}</span>
                                            </div>
                                            <div class="text-right">
                                                <div class="text-lg font-bold text-gray-800">${value}</div>
                                                <div class="text-xs text-gray-500">${percentage}%</div>
                                            </div>
                                        </div>
                                        <div class="w-full bg-gray-200 rounded-full h-2">
                                            <div class="bg-gradient-to-r ${moodInfo.bgColor} h-2 rounded-full transition-all duration-500" style="width: ${percentage}%"></div>
                                        </div>
                                    </div>
                                `;
                }).join('')}
                        </div>
                    </div>
                    ` : ''}
                    
                    ${modelInfo.feature_importance ? `
                    <div class="mt-6 bg-gradient-to-r from-gray-50 to-gray-100 rounded-xl p-5 border border-gray-200">
                        <div class="flex items-center mb-4">
                            <div class="w-8 h-8 bg-purple-100 rounded-lg flex items-center justify-center mr-3">
                                <span class="text-purple-600">🔍</span>
                            </div>
                            <h4 class="text-lg font-bold text-gray-800">Top Feature Importance</h4>
                        </div>
                        <div class="space-y-3">
                            ${Object.entries(modelInfo.feature_importance).slice(0, 5).map(([feature, importance], index) => `
                                <div class="bg-white rounded-lg p-3 border border-gray-200">
                                    <div class="flex items-center justify-between mb-2">
                                        <div class="flex items-center space-x-3">
                                            <div class="w-6 h-6 bg-purple-100 rounded-full flex items-center justify-center text-xs font-bold text-purple-600">
                                                ${index + 1}
                                            </div>
                                            <span class="font-medium text-gray-800">${feature.replace(/_/g, ' ')}</span>
                                        </div>
                                        <span class="text-sm font-bold text-purple-600">${(importance * 100).toFixed(1)}%</span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2">
                                        <div class="bg-gradient-to-r from-purple-500 to-purple-600 h-2 rounded-full transition-all duration-500" style="width: ${(importance * 100).toFixed(1)}%"></div>
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                    ` : ''}
                `;
            } else if (result.success && !result.active_model) {
                modelInfoContent.innerHTML = `
                    <div class="text-center py-12">
                        <div class="w-20 h-20 bg-gradient-to-br from-blue-100 to-purple-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <span class="text-4xl">🚀</span>
                        </div>
                        <h4 class="text-xl font-bold text-gray-800 mb-2">No Model Found</h4>
                        <p class="text-gray-500 mb-6 max-w-md mx-auto">Start by uploading a CSV file and training your first AI model to see detailed analytics and performance metrics here.</p>
                        <div class="flex justify-center space-x-4">
                            <div class="bg-blue-50 rounded-lg px-4 py-2 text-sm text-blue-700">
                                <span class="mr-1">📊</span> Upload Data
                            </div>
                            <div class="bg-purple-50 rounded-lg px-4 py-2 text-sm text-purple-700">
                                <span class="mr-1">🤖</span> Train Model
                            </div>
                            <div class="bg-green-50 rounded-lg px-4 py-2 text-sm text-green-700">
                                <span class="mr-1">🎯</span> Make Predictions
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

    document.addEventListener('DOMContentLoaded', function () {
        loadActiveModelInfo();
        loadModelsInfo();
    });
</script>

</html>