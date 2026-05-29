<?php
session_start();
require_once 'config.php';

// Already logged in
if (isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbc = get_db_connection();

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // Server-side validation
    if (empty($email) || empty($password)) {
        $error = 'Please fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } else {
        $stmt = mysqli_prepare($dbc,
            "SELECT user_id, username, email, password_hash, role, is_active
             FROM dbProj_users WHERE email = ? LIMIT 1"
        );
        mysqli_stmt_bind_param($stmt, 's', $email);
        mysqli_stmt_execute($stmt);
        $res  = mysqli_stmt_get_result($stmt);
        $user = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $error = 'Incorrect email or password.';
        } elseif (!$user['is_active']) {
            $error = 'Your account has been deactivated. Contact an administrator.';
        } else {
            // Start session
            $_SESSION['user'] = [
                'user_id'  => $user['user_id'],
                'username' => $user['username'],
                'email'    => $user['email'],
                'role'     => $user['role'],
            ];

            // Redirect by role
            if ($user['role'] === 'admin') {
                header('Location: admin/index.php');
            } elseif ($user['role'] === 'creator') {
                header('Location: creator/index.php');
            } else {
                header('Location: index.php');
            }
            exit;
        }
    }

    mysqli_close($dbc);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login &middot; Movie Review</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .auth-wrapper {
            min-height: calc(100vh - 73px - 57px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }
        .auth-card {
            background-color: #1f1f1f;
            border: 1px solid #2a2a2a;
            border-radius: 12px;
            padding: 2.5rem 2rem;
            width: 100%;
            max-width: 420px;
        }
        .auth-card h2 {
            font-size: 1.6rem;
            margin-bottom: 0.25rem;
        }
        .auth-card .subtitle {
            color: #b3b3b3;
            font-size: 0.9rem;
            margin-bottom: 1.75rem;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
            margin-bottom: 1rem;
        }
        .form-group label {
            font-size: 0.85rem;
            color: #b3b3b3;
        }
        .form-group input {
            background-color: #141414;
            color: #f5f5f5;
            border: 1px solid #333;
            border-radius: 7px;
            padding: 0.65rem 0.85rem;
            font: inherit;
            font-size: 0.95rem;
            transition: border-color 0.2s;
        }
        .form-group input:focus {
            outline: none;
            border-color: #e50914;
        }
        .form-group input.invalid {
            border-color: #e50914;
        }
        .field-error {
            font-size: 0.78rem;
            color: #fca5a5;
            min-height: 1em;
        }
        .btn-full {
            width: 100%;
            background-color: #e50914;
            color: #fff;
            border: none;
            border-radius: 7px;
            padding: 0.7rem;
            font: inherit;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            margin-top: 0.5rem;
            transition: background-color 0.2s;
        }
        .btn-full:hover { background-color: #c40811; }
        .alert-error {
            background: rgba(239,68,68,0.12);
            border: 1px solid rgba(239,68,68,0.35);
            color: #fca5a5;
            border-radius: 7px;
            padding: 0.7rem 1rem;
            font-size: 0.88rem;
            margin-bottom: 1.25rem;
        }
        .auth-footer {
            text-align: center;
            margin-top: 1.5rem;
            font-size: 0.88rem;
            color: #b3b3b3;
        }
        .auth-footer a {
            color: #e50914;
            text-decoration: none;
        }
        .auth-footer a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <header>
        <nav class="container">
            <h1 class="logo"><a href="index.php" style="color:inherit;text-decoration:none;">Movie Review</a></h1>
            <ul class="nav-links">
                <li><a href="index.php">Home</a></li>
                <li><a href="signup.php">Sign Up</a></li>
            </ul>
        </nav>
    </header>

    <div class="auth-wrapper">
        <div class="auth-card">
            <h2>Welcome back</h2>
            <p class="subtitle">Sign in to your account</p>

            <?php if ($error): ?>
                <div class="alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form id="loginForm" method="post" novalidate>
                <div class="form-group">
                    <label for="email">Email address</label>
                    <input type="email" id="email" name="email"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                           placeholder="you@example.com" autocomplete="email">
                    <span class="field-error" id="emailErr"></span>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password"
                           placeholder="••••••••" autocomplete="current-password">
                    <span class="field-error" id="passwordErr"></span>
                </div>

                <button type="submit" class="btn-full">Sign In</button>
            </form>

            <div class="auth-footer">
                Don't have an account? <a href="signup.php">Sign up</a>
            </div>
        </div>
    </div>

    <footer>
        <div class="container">
            <p>&copy; <?= date('Y') ?> Movie Review</p>
        </div>
    </footer>

    <script>
        // JS validation before form submits
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            let valid = true;

            const email    = document.getElementById('email');
            const password = document.getElementById('password');
            const emailErr = document.getElementById('emailErr');
            const passErr  = document.getElementById('passwordErr');

            emailErr.textContent = '';
            passErr.textContent  = '';
            email.classList.remove('invalid');
            password.classList.remove('invalid');

            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

            if (!email.value.trim()) {
                emailErr.textContent = 'Email is required.';
                email.classList.add('invalid');
                valid = false;
            } else if (!emailRegex.test(email.value.trim())) {
                emailErr.textContent = 'Enter a valid email address.';
                email.classList.add('invalid');
                valid = false;
            }

            if (!password.value) {
                passErr.textContent = 'Password is required.';
                password.classList.add('invalid');
                valid = false;
            }

            if (!valid) e.preventDefault();
        });
    </script>
</body>
</html>