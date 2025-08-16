<?php
require_once '../config/config.php';

$username = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    $confirmPassword = trim($_POST['confirm_password']);
    $errors = [];

    if (empty($username)) {
        $errors['username'] = 'Username is required';
    } else if (strlen($username) < 3 || strlen($username) > 20) {
        $errors['username'] = 'Username must be between 3 and 20 characters';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $errors['username'] = 'Username can only contain letters, numbers, and underscores';
    }

    if (empty($email)) {
        $errors['email'] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Email is invalid';
    }

    if (empty($password)) {
        $errors['password'] = 'Password is required';
    } elseif (strlen($password) < 6) {
        $errors['password'] = 'Password must be at least 6 characters';
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $errors['password'] = 'Password must contain at least one uppercase letter';
    } elseif (!preg_match('/[a-z]/', $password)) {
        $errors['password'] = 'Password must contain at least one lowercase letter';
    } elseif (!preg_match('/[0-9]/', $password)) {
        $errors['password'] = 'Password must contain at least one number';
    } elseif (preg_match('/\s/', $password)) {
        $errors['password'] = 'Password cannot contain spaces';
    }

    if (empty($confirmPassword)) {
        $errors['confirm_password'] = 'Please confirm your password';
    } elseif ($password !== $confirmPassword) {
        $errors['confirm_password'] = 'Passwords do not match';
    }

    if (!isset($errors['username'])) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetchColumn() > 0) {
            $errors['username'] = 'Username already exists';
        }
    }

    if (!isset($errors['email'])) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn() > 0) {
            $errors['email'] = 'Email already exists';
        }
    }

    if (empty($errors)) {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
        if ($stmt->execute([$username, $email, $hashedPassword])) {
            $success = 'Registration successful! You can now login.';

            $username = $email = $password = $confirmPassword = '';
        } else {
            $errors['general'] = 'Registration failed. Please try again.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Youth Mental Health - Register</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8fafc;
            color: #334155;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            position: relative;
            overflow: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at 70% 20%, rgba(59, 130, 246, 0.08) 0%, rgba(6, 182, 212, 0.05) 40%, transparent 70%);
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

        .register-card {
            background-color: rgba(255, 255, 255, 0.9);
            border-radius: 1rem;
            padding: 2.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05), 0 1px 3px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            border: 1px solid rgba(59, 130, 246, 0.15);
            max-width: 500px;
            width: 90%;
            text-align: center;
        }

        .form-group {
            margin-bottom: 1.25rem;
            text-align: left;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #475569;
        }

        .form-group input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid #cbd5e1;
            border-radius: 0.5rem;
            background-color: rgba(255, 255, 255, 0.8);
            color: #334155;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .form-group input:focus {
            border-color: #3B82F6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .error {
            color: #dc2626;
            font-size: 0.875rem;
            font-weight: bolder;
            margin-top: 0.5rem;
            text-align: left;
        }

        .error-input {
            border-color: #dc2626 !important;
        }

        .register-button {
            width: 100%;
            padding: 1rem 1.5rem;
            border-radius: 0.75rem;
            font-weight: 600;
            color: white;
            background: linear-gradient(to right, #06B6D4, #0891B2);
            border: none;
            cursor: pointer;
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
            box-shadow: 0 4px 10px rgba(6, 182, 212, 0.3);
        }

        .register-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(6, 182, 212, 0.4);
        }

        .register-link {
            color: #3B82F6;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s;
        }

        .register-link:hover {
            color: #1d4ed8;
        }

        .password-container {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #64748b;
            cursor: pointer;
            font-size: 0.875rem;
            padding: 0.25rem;
            transition: color 0.2s;
        }

        .password-toggle:hover {
            color: #3B82F6;
        }

        .password-toggle:focus {
            outline: none;
            color: #3B82F6;
        }
    </style>
</head>

<body>
    <div class="register-card">
        <h2 class="text-3xl font-bold text-blue-900 mb-8">Register</h2>

        <?php if (isset($success)): ?>
            <div class="mb-4 border border-green-400 bg-green-50 p-4 rounded">
                <p class="text-green-700"><?php echo htmlspecialchars($success); ?></p>
            </div>
        <?php endif; ?>

        <?php if (isset($errors['general'])): ?>
            <div class="error mb-4 border border-red-400 bg-red-50 p-4 rounded">
                <p class="text-red-700"><?php echo htmlspecialchars($errors['general']); ?></p>
            </div>
        <?php endif; ?>

        <form method="post">
            <div class="form-group">
                <label for="username">Username:</label>
                <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($username); ?>"
                    class="<?php echo isset($errors['username']) ? 'error-input' : ''; ?>">
                <?php if (isset($errors['username'])): ?>
                    <div class="error"><?php echo htmlspecialchars($errors['username']); ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="email">Email:</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>"
                    class="<?php echo isset($errors['email']) ? 'error-input' : ''; ?>">
                <?php if (isset($errors['email'])): ?>
                    <div class="error"><?php echo htmlspecialchars($errors['email']); ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="password">Password:</label>
                <div class="password-container">
                    <input type="password" id="password" name="password"
                        class="<?php echo isset($errors['password']) ? 'error-input' : ''; ?>"
                        style="padding-right: 3rem;">
                    <button type="button" class="password-toggle" onclick="togglePassword('password', this)">
                        Show
                    </button>
                </div>
                <?php if (isset($errors['password'])): ?>
                    <div class="error"><?php echo htmlspecialchars($errors['password']); ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password:</label>
                <div class="password-container">
                    <input type="password" id="confirm_password" name="confirm_password"
                        class="<?php echo isset($errors['confirm_password']) ? 'error-input' : ''; ?>"
                        style="padding-right: 3rem;">
                    <button type="button" class="password-toggle" onclick="togglePassword('confirm_password', this)">
                        Show
                    </button>
                </div>
                <?php if (isset($errors['confirm_password'])): ?>
                    <div class="error"><?php echo htmlspecialchars($errors['confirm_password']); ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group mt-6">
                <button type="submit" class="register-button">Register</button>
            </div>
        </form>
        <p class="mt-6 text-slate-600">Already have an account? <a href="login.php" class="register-link">Login here</a>
        </p>
    </div>

    <script>
        function togglePassword(inputId, button) {
            const input = document.getElementById(inputId);
            const isPassword = input.type === 'password';

            input.type = isPassword ? 'text' : 'password';

            button.textContent = isPassword ? 'Hide' : 'Show';

            button.title = isPassword ? 'Hide password' : 'Show password';
        }
    </script>
</body>

</html>