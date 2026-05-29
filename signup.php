<?php
session_start();
require_once 'config.php';

// Already logged in
if (isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbc = get_db_connection();

    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';
    $role     = $_POST['role'] ?? 'visitor';

    // Allowed roles for self-registration
    $allowedRoles = ['visitor', 'creator'];

    // Server-side validation
    if (empty($username) || empty($email) || empty($password) || empty($confirm)) {
        $error = 'Please fill in all fields.';
    } elseif (strlen($username) < 3 || strlen($username) > 50) {
        $error = 'Username must be between 3 and 50 characters.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $error = 'Username may only contain letters, numbers, and underscores.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (!in_array($role, $allowedRoles, true)) {
        $error = 'Invalid role selected.';
    } else {
        // Check username uniqueness
        $stmt = mysqli_prepare($dbc, "SELECT user_id FROM dbProj_users WHERE username = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 's', $username);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        if (mysqli_stmt_num_rows($stmt) > 0) {
            $error = 'That username is already taken.';
        }
        mysqli_stmt_close($stmt);

        if (!$error) {
            // Check email uniqueness
            $stmt = mysqli_prepare($dbc, "SELECT user_id FROM dbProj_users WHERE email = ? LIMIT 1");
            mysqli_stmt_bind_param($stmt, 's', $email);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);
            if (mysqli_stmt_num_rows($stmt) > 0) {
                $error = 'An account with that email already exists.';
            }
            mysqli_stmt_close($stmt);
        }

        if (!$error) {
            // Hash password and insert
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = mysqli_prepare($dbc,
                "INSERT INTO dbProj_users (username, email, password_hash, role, is_active)
                 VALUES (?, ?, ?, ?, 1)"
            );
            mysqli_stmt_bind_param($stmt, 'ssss', $username, $email, $hash, $role);

            if (mysqli_stmt_execute($stmt)) {
                $newId = mysqli_insert_id($dbc);
                mysqli_stmt_close($stmt);

                // Auto login after signup
                $_SESSION['user'] = [
                    'user_id'  => $newId,
                    'username' => $username,
                    'email'    => $email,
                    'role'     => $role,
                ];

                if ($role === 'creator') {
                    header('Location: creator/index.php');
                } else {
                    header('Location: index.php');
                }
                exit;
            } else {
                $error = 'Registration failed. Please try again.';
                mysqli_stmt_close($stmt);
            }
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
    <title>Sign Up &middot; Movie Review</title>
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
            max-width: 440px;
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
        .form-group input,
        .form-group select {
            background-color: #141414;
            color: #f5f5f5;
            border: 1px solid #333;
            border-radius: 7px;
            padding: 0.65rem 0.85rem;
            font: inherit;
            font-size: 0.95rem;
            transition: border-color 0.2s;
        }
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #e50914;
        }
        .form-group input.invalid,
        .form-group select.invalid {
            border-color: #e50914;
        }
        .field-error {
            font-size: 0.78rem;
            color: #fca5a5;
            min-height: 1em;
        }
        .password-hint {
            font-size: 0.76rem;
            color: #666;
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
        .role-options {
            display: flex;
            gap: 0.75rem;
        }
        .role-option {
            flex: 1;
            border: 1px solid #333;
            border-radius: 7px;
            padding: 0.75rem;
            cursor: pointer;
            transition: border-color 0.2s, background-color 0.2s;
            text-align: center;
        }
        .role-option:hover { border-color: #555; }
        .role-option input[type="radio"] { display: none; }
        .role-option.selected {
            border-color: #e50914;
            background-color: rgba(229,9,20,0.08);
        }
        .role-option .role-icon { font-size: 1.4rem; margin-bottom: 0.2rem; }
        .role-option .role-label { font-size: 0.82rem; font-weight: 600; }
        .role-option .role-desc { font-size: 0.72rem; color: #888; margin-top: 0.1rem; }
    </style>
</head>
<body>
    <header>
        <nav class="container">
            <h1 class="logo"><a href="index.php" style="color:inherit;text-decoration:none;">Movie Review</a></h1>
            <ul class="nav-links">
                <li><a href="index.php">Home</a></li>
                <li><a href="login.php">Sign In</a></li>
            </ul>
        </nav>
    </header>

    <div class="auth-wrapper">
        <div class="auth-card">
            <h2>Create account</h2>
            <p class="subtitle">Join the Movie Review community</p>

            <?php if ($error): ?>
                <div class="alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form id="signupForm" method="post" novalidate>

                <div class="form-group">
                    <label>I want to join as</label>
                    <div class="role-options">
                        <label class="role-option <?= ($_POST['role'] ?? 'visitor') === 'visitor' ? 'selected' : '' ?>" id="opt-visitor">
                            <input type="radio" name="role" value="visitor"
                                   <?= ($_POST['role'] ?? 'visitor') === 'visitor' ? 'checked' : '' ?>>
                            <div class="role-icon">👁️</div>
                            <div class="role-label">Viewer</div>
                            <div class="role-desc">Browse &amp; rate movies</div>
                        </label>
                        <label class="role-option <?= ($_POST['role'] ?? '') === 'creator' ? 'selected' : '' ?>" id="opt-creator">
                            <input type="radio" name="role" value="creator"
                                   <?= ($_POST['role'] ?? '') === 'creator' ? 'checked' : '' ?>>
                            <div class="role-icon">🎬</div>
                            <div class="role-label">Creator</div>
                            <div class="role-desc">Post &amp; manage movies</div>
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                           placeholder="e.g. john_doe" autocomplete="username" maxlength="50">
                    <span class="field-error" id="usernameErr"></span>
                </div>

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
                           placeholder="Min. 8 characters" autocomplete="new-password">
                    <span class="password-hint">At least 8 characters</span>
                    <span class="field-error" id="passwordErr"></span>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm password</label>
                    <input type="password" id="confirm_password" name="confirm_password"
                           placeholder="Repeat your password" autocomplete="new-password">
                    <span class="field-error" id="confirmErr"></span>
                </div>

                <button type="submit" class="btn-full">Create Account</button>
            </form>

            <div class="auth-footer">
                Already have an account? <a href="login.php">Sign in</a>
            </div>
        </div>
    </div>

    <footer>
        <div class="container">
            <p>&copy; <?= date('Y') ?> Movie Review</p>
        </div>
    </footer>

    <script>
        // Role card toggle
        document.querySelectorAll('.role-option').forEach(function(label) {
            label.addEventListener('click', function() {
                document.querySelectorAll('.role-option').forEach(l => l.classList.remove('selected'));
                this.classList.add('selected');
            });
        });

        // JS validation
        document.getElementById('signupForm').addEventListener('submit', function(e) {
            let valid = true;

            const username = document.getElementById('username');
            const email    = document.getElementById('email');
            const password = document.getElementById('password');
            const confirm  = document.getElementById('confirm_password');

            const usernameErr = document.getElementById('usernameErr');
            const emailErr    = document.getElementById('emailErr');
            const passwordErr = document.getElementById('passwordErr');
            const confirmErr  = document.getElementById('confirmErr');

            // Clear previous errors
            [usernameErr, emailErr, passwordErr, confirmErr].forEach(el => el.textContent = '');
            [username, email, password, confirm].forEach(el => el.classList.remove('invalid'));

            const emailRegex    = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            const usernameRegex = /^[a-zA-Z0-9_]+$/;

            if (!username.value.trim()) {
                usernameErr.textContent = 'Username is required.';
                username.classList.add('invalid');
                valid = false;
            } else if (username.value.trim().length < 3) {
                usernameErr.textContent = 'Username must be at least 3 characters.';
                username.classList.add('invalid');
                valid = false;
            } else if (!usernameRegex.test(username.value.trim())) {
                usernameErr.textContent = 'Only letters, numbers, and underscores allowed.';
                username.classList.add('invalid');
                valid = false;
            }

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
                passwordErr.textContent = 'Password is required.';
                password.classList.add('invalid');
                valid = false;
            } else if (password.value.length < 8) {
                passwordErr.textContent = 'Password must be at least 8 characters.';
                password.classList.add('invalid');
                valid = false;
            }

            if (!confirm.value) {
                confirmErr.textContent = 'Please confirm your password.';
                confirm.classList.add('invalid');
                valid = false;
            } else if (password.value !== confirm.value) {
                confirmErr.textContent = 'Passwords do not match.';
                confirm.classList.add('invalid');
                valid = false;
            }

            if (!valid) e.preventDefault();
        });
    </script>
</body>
</html>