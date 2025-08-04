<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once 'config.php';

$predictionError = $predictionSuccess = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['predict_mood'])) {
    $age = intval($_POST['age']);
    $screenTime = floatval($_POST['screen_time']);
    $sleepHours = floatval($_POST['sleep_hours']);
    $studyHours = floatval($_POST['study_hours']);
    $physicalActivity = intval($_POST['physical_activity']);
    $mentalClarity = intval($_POST['mental_clarity']);

    if ($age < 13 || $age > 25) {
        $predictionError = 'Age must be between 13 and 25.';
    } elseif ($screenTime < 0 || $screenTime > 24) {
        $predictionError = 'Screen time must be between 0 and 24 hours.';
    } elseif ($sleepHours < 0 || $sleepHours > 16) {
        $predictionError = 'Sleep hours must be between 0 and 16.';
    } elseif ($studyHours < 0 || $studyHours > 16) {
        $predictionError = 'Study hours must be between 0 and 16.';
    } elseif ($physicalActivity < 0 || $physicalActivity > 100) {
        $predictionError = 'Physical activity must be between 0 and 100 minutes.';
    } elseif ($mentalClarity < 1 || $mentalClarity > 10) {
        $predictionError = 'Mental clarity score must be between 1 and 10.';
    } else {
        $apiData = [
            'Age' => $age,
            'Hours_of_Screen_Time' => $screenTime,
            'Hours_of_Sleep' => $sleepHours,
            'Daily_Study_Hours' => $studyHours,
            'Physical_Activity' => $physicalActivity,
            'Mental_Clarity_Score' => $mentalClarity
        ];

        $apiUrl = 'http://localhost:5000/predict';
        $apiOptions = [
            'http' => [
                'header' => "Content-type: application/json\r\n",
                'method' => 'POST',
                'content' => json_encode($apiData)
            ]
        ];

        $context = stream_context_create($apiOptions);
        $result = @file_get_contents($apiUrl, false, $context);

        if ($result !== false) {
            $apiResponse = json_decode($result, true);
            if (isset($apiResponse['predicted_label'])) {
                $predictedMood = $apiResponse['predicted_label'];

                try {
                    $stmt = $pdo->prepare("INSERT INTO prediction_history (user_id, age, screen_time, sleep_hours, study_hours, physical_activity, mental_clarity_score, mood) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$_SESSION['user_id'], $age, $screenTime, $sleepHours, $studyHours, $physicalActivity, $mentalClarity, $predictedMood]);
                    $predictionSuccess = "Prediction completed! Your predicted mood is: " . $predictedMood;
                } catch (PDOException $e) {
                    $predictionError = 'Failed to save prediction to history.';
                    error_log("Failed to save prediction: " . $e->getMessage());
                }
            } else {
                $predictionError = 'Invalid response from prediction API.';
            }
        } else {
            $predictionError = 'Unable to connect to prediction service. Please try again later.';
        }
    }
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
    <title>Youth Mental Health - User Dashboard</title>
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
            <section id="user-info-section"
                class="card lg:col-span-3 flex justify-between items-center bg-gradient-to-r from-blue-600 via-purple-600 to-indigo-600 text-white border-0">
                <div class="flex items-center space-x-4">
                    <div class="w-16 h-16 bg-white/20 rounded-full flex items-center justify-center backdrop-blur-sm">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                        </svg>
                    </div>
                    <div>
                        <h1 class="text-2xl md:text-3xl font-bold">Welcome back!</h1>
                        <p class="text-blue-100 text-sm md:text-base">
                            <?php echo htmlspecialchars($_SESSION['username']); ?>
                        </p>
                    </div>
                </div>
                <a href="/"
                    class="flex items-center space-x-2 bg-white/20 hover:bg-white/30 px-4 py-2 rounded-lg transition-all duration-200 backdrop-blur-sm border border-white/20">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                    </svg>
                    <span class="text-sm font-medium">Home</span>
                </a>
            </section>



            <section id="prediction-section" class="card lg:col-span-2">
                <h2 class="text-2xl font-bold text-blue-900 mb-6">🧠 Mental Health Prediction</h2>
                <p class="text-slate-600 mb-6">Enter your lifestyle information to get an AI-powered mental health
                    assessment.</p>

                <?php if ($predictionError): ?>
                    <div class="mb-4 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg">
                        <?php echo htmlspecialchars($predictionError); ?>
                    </div>
                <?php endif; ?>

                <div class="bg-blue-50 rounded-lg p-6">
                    <form method="POST" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <div>
                            <label for="age" class="block text-sm font-semibold text-gray-700 mb-2">Age</label>
                            <input type="number" id="age" name="age" min="13" max="25" step="1" required
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                placeholder="e.g., 18"
                                value="<?php echo isset($_POST['age']) ? htmlspecialchars($_POST['age']) : ''; ?>">
                            <p class="text-xs text-gray-500 mt-1">Age in years (13-25)</p>
                        </div>

                        <div>
                            <label for="screen_time" class="block text-sm font-semibold text-gray-700 mb-2">Screen Time
                                (hours/day)</label>
                            <input type="number" id="screen_time" name="screen_time" min="0" max="24" step="0.1"
                                required
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                placeholder="e.g., 5.5"
                                value="<?php echo isset($_POST['screen_time']) ? htmlspecialchars($_POST['screen_time']) : ''; ?>">
                            <p class="text-xs text-gray-500 mt-1">Hours per day (0-24)</p>
                        </div>

                        <div>
                            <label for="sleep_hours" class="block text-sm font-semibold text-gray-700 mb-2">Sleep
                                Hours</label>
                            <input type="number" id="sleep_hours" name="sleep_hours" min="0" max="16" step="0.1"
                                required
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                placeholder="e.g., 7.5"
                                value="<?php echo isset($_POST['sleep_hours']) ? htmlspecialchars($_POST['sleep_hours']) : ''; ?>">
                            <p class="text-xs text-gray-500 mt-1">Hours per night (0-16)</p>
                        </div>

                        <div>
                            <label for="study_hours" class="block text-sm font-semibold text-gray-700 mb-2">Study
                                Hours</label>
                            <input type="number" id="study_hours" name="study_hours" min="0" max="16" step="0.1"
                                required
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                placeholder="e.g., 4.0"
                                value="<?php echo isset($_POST['study_hours']) ? htmlspecialchars($_POST['study_hours']) : ''; ?>">
                            <p class="text-xs text-gray-500 mt-1">Hours per day (0-16)</p>
                        </div>

                        <div>
                            <label for="physical_activity"
                                class="block text-sm font-semibold text-gray-700 mb-2">Physical Activity</label>
                            <input type="number" id="physical_activity" name="physical_activity" min="0" max="100"
                                step="1" required
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                placeholder="e.g., 30"
                                value="<?php echo isset($_POST['physical_activity']) ? htmlspecialchars($_POST['physical_activity']) : ''; ?>">
                            <p class="text-xs text-gray-500 mt-1">Minutes per week (0-100)</p>
                        </div>

                        <div>
                            <label for="mental_clarity" class="block text-sm font-semibold text-gray-700 mb-2">Mental
                                Clarity Score</label>
                            <input type="number" id="mental_clarity" name="mental_clarity" min="1" max="10" step="1"
                                required
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                placeholder="e.g., 7"
                                value="<?php echo isset($_POST['mental_clarity']) ? htmlspecialchars($_POST['mental_clarity']) : ''; ?>">
                            <p class="text-xs text-gray-500 mt-1">Scale 1-10 (1=Poor, 10=Excellent)</p>
                        </div>

                        <div class="md:col-span-2 lg:col-span-3">
                            <div class="flex flex-col sm:flex-row gap-4">
                                <button type="submit" name="predict_mood"
                                    class="flex-1 px-6 py-3 bg-gradient-to-r from-blue-600 to-purple-600 text-white rounded-lg hover:from-blue-700 hover:to-purple-700 font-semibold shadow-lg">
                                    🔮 Predict Mental Health
                                </button>
                                <button type="button" onclick="fillExampleData()"
                                    class="px-6 py-3 bg-green-500 text-white rounded-lg hover:bg-green-600 transition-colors duration-200 font-semibold">
                                    📝 Fill Example
                                </button>
                                <button type="button" onclick="clearForm()"
                                    class="px-6 py-3 bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition-colors duration-200 font-semibold">
                                    🗑️ Clear Form
                                </button>
                            </div>
                        </div>
                    </form>

                    <!-- Prediction Results -->
                    <?php if ($predictionSuccess): ?>
                        <div id="prediction-results" class="mt-6">
                            <div class="bg-white rounded-lg p-6 border-l-4 border-blue-500">
                                <h3 class="text-lg font-bold text-gray-800 mb-3">🎯 Prediction Results</h3>
                                <div id="prediction-content" class="space-y-3">
                                    <?php
                                    preg_match('/predicted mood is: (.+)$/', $predictionSuccess, $matches);
                                    $predictedMood = isset($matches[1]) ? trim($matches[1]) : 'Unknown';

                                    $moodMappings = [
                                        'Happy' => ['label' => 'Happy/Positive Mood', 'color' => 'green', 'emoji' => '😊', 'description' => 'The model predicts a positive mood state. Current lifestyle factors appear supportive.'],
                                        'Neutral' => ['label' => 'Neutral Mood', 'color' => 'yellow', 'emoji' => '😐', 'description' => 'The model predicts a balanced mood state. Maintaining current habits may be beneficial.'],
                                        'Stressed' => ['label' => 'Stressed/Low Mood', 'color' => 'red', 'emoji' => '😢', 'description' => 'The model predicts a stressed state. Consider lifestyle adjustments and stress management.']
                                    ];

                                    $moodInfo = isset($moodMappings[$predictedMood]) ? $moodMappings[$predictedMood] : ['label' => $predictedMood, 'color' => 'gray', 'emoji' => '❓', 'description' => 'Prediction result unclear.'];
                                    ?>

                                    <div class="flex items-center space-x-4 mb-4">
                                        <div class="text-4xl"><?php echo $moodInfo['emoji']; ?></div>
                                        <div>
                                            <h4 class="text-xl font-bold text-<?php echo $moodInfo['color']; ?>-600">
                                                <?php echo $moodInfo['label']; ?>
                                            </h4>
                                            <p class="text-gray-600"><?php echo $moodInfo['description']; ?></p>
                                        </div>
                                    </div>

                                    <div class="bg-blue-50 rounded-lg p-4">
                                        <h5 class="font-semibold text-blue-800 mb-2">📋 Input Summary:</h5>
                                        <div class="grid grid-cols-2 gap-2 text-sm text-blue-700">
                                            <div><strong>Age:</strong> <?php echo htmlspecialchars($_POST['age'] ?? ''); ?>
                                                years</div>
                                            <div><strong>Screen Time:</strong>
                                                <?php echo htmlspecialchars($_POST['screen_time'] ?? ''); ?>h/day</div>
                                            <div><strong>Sleep:</strong>
                                                <?php echo htmlspecialchars($_POST['sleep_hours'] ?? ''); ?>h/night</div>
                                            <div><strong>Study:</strong>
                                                <?php echo htmlspecialchars($_POST['study_hours'] ?? ''); ?>h/day</div>
                                            <div><strong>Physical Activity:</strong>
                                                <?php echo htmlspecialchars($_POST['physical_activity'] ?? ''); ?> min/week
                                            </div>
                                            <div><strong>Mental Clarity:</strong>
                                                <?php echo htmlspecialchars($_POST['mental_clarity'] ?? ''); ?>/10</div>
                                        </div>
                                    </div>

                                    <div class="mt-4 text-xs text-gray-500">
                                        ⚠️ This is an AI prediction for educational purposes only. For serious mental health
                                        concerns, please consult a healthcare professional.
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Loading State (hidden by default, can be shown via JavaScript if needed) -->
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

            <section id="user-actions-section" class="card lg:col-span-1 border-0 shadow-xl">
                <div class="flex items-center mb-6">
                    <div
                        class="w-12 h-12 bg-gradient-to-r from-blue-500 to-indigo-600 rounded-xl flex items-center justify-center mr-4 shadow-lg">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z">
                            </path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        </svg>
                    </div>
                    <h1
                        class="text-2xl md:text-3xl font-bold bg-gradient-to-r from-blue-900 via-indigo-800 to-purple-800 bg-clip-text text-transparent">
                        Account Settings</h1>
                </div>

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


            <section id="project-overview-section" class="card mb-8 lg:col-span-3">
                <h1 class="text-2xl md:text-3xl font-bold text-blue-900 mb-6">📊 Prediction History</h1>
                <p class="text-slate-600 mb-4">Your previous mental health predictions and lifestyle data.</p>

                <?php
                try {
                    $stmt = $pdo->prepare("SELECT * FROM prediction_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
                    $stmt->execute([$_SESSION['user_id']]);
                    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    $history = [];
                    error_log("Failed to fetch history: " . $e->getMessage());
                }
                ?>

                <?php if (!empty($history)): ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full bg-white text-gray-800 rounded-lg overflow-hidden shadow-sm">
                            <thead class="bg-gradient-to-r from-blue-50 to-indigo-50">
                                <tr>
                                    <th class="py-3 px-4 text-left font-semibold text-blue-900">Date</th>
                                    <th class="py-3 px-4 text-left font-semibold text-blue-900">Age</th>
                                    <th class="py-3 px-4 text-left font-semibold text-blue-900">Screen Time</th>
                                    <th class="py-3 px-4 text-left font-semibold text-blue-900">Sleep Time</th>
                                    <th class="py-3 px-4 text-left font-semibold text-blue-900">Study Time</th>
                                    <th class="py-3 px-4 text-left font-semibold text-blue-900">Activity</th>
                                    <th class="py-3 px-4 text-left font-semibold text-blue-900">Clarity</th>
                                    <th class="py-3 px-4 text-left font-semibold text-blue-900">Mood</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($history as $record): ?>
                                    <tr class="border-b border-gray-100 hover:bg-blue-25 transition-colors duration-150">
                                        <td class="py-3 px-4 text-sm">
                                            <?php echo date('M j, Y', strtotime($record['created_at'])); ?>
                                            <br><span
                                                class="text-xs text-gray-500"><?php echo date('H:i', strtotime($record['created_at'])); ?></span>
                                        </td>
                                        <td class="py-3 px-4"><?php echo htmlspecialchars($record['age']); ?></td>
                                        <td class="py-3 px-4"><?php echo htmlspecialchars($record['screen_time']); ?> h</td>
                                        <td class="py-3 px-4"><?php echo htmlspecialchars($record['sleep_hours']); ?> h</td>
                                        <td class="py-3 px-4"><?php echo htmlspecialchars($record['study_hours']); ?> h</td>
                                        <td class="py-3 px-4"><?php echo htmlspecialchars($record['physical_activity']); ?></td>
                                        <td class="py-3 px-4">
                                            <?php echo htmlspecialchars($record['mental_clarity_score']); ?>/10
                                        </td>
                                        <td class="py-3 px-4">
                                            <span class="px-3 py-1 rounded-full text-sm font-medium
                                                <?php
                                                $mood = strtolower($record['mood']);
                                                if (strpos($mood, 'happy') !== false || strpos($mood, 'positive') !== false) {
                                                    echo 'bg-green-100 text-green-800 border border-green-200';
                                                } elseif (strpos($mood, 'neutral') !== false) {
                                                    echo 'bg-yellow-100 text-yellow-800 border border-yellow-200';
                                                } else {
                                                    echo 'bg-red-100 text-red-800 border border-red-200';
                                                }
                                                ?>">
                                                <?php echo htmlspecialchars($record['mood']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-12">
                        <div class="text-gray-400 text-6xl mb-4">📊</div>
                        <h3 class="text-lg font-medium text-gray-600 mb-2">No prediction history yet</h3>
                        <p class="text-gray-500">Make your first prediction above to see your results here!</p>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
</body>
<script>
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

    function fillExampleData() {
        document.getElementById('age').value = '18';
        document.getElementById('screen_time').value = '5.0';
        document.getElementById('sleep_hours').value = '7.5';
        document.getElementById('study_hours').value = '4.0';
        document.getElementById('physical_activity').value = '30';
        document.getElementById('mental_clarity').value = '7';
        const resultsDiv = document.getElementById('prediction-results');
        if (resultsDiv) {
            resultsDiv.style.display = 'none';
        }
    }

    function clearForm() {
        document.getElementById('age').value = '';
        document.getElementById('screen_time').value = '';
        document.getElementById('sleep_hours').value = '';
        document.getElementById('study_hours').value = '';
        document.getElementById('physical_activity').value = '';
        document.getElementById('mental_clarity').value = '';
        const resultsDiv = document.getElementById('prediction-results');
        if (resultsDiv) {
            resultsDiv.style.display = 'none';
        }
        const loadingDiv = document.getElementById('prediction-loading');
        if (loadingDiv) {
            loadingDiv.classList.add('hidden');
        }
    }
</script>

</html>