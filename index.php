<?php
require_once 'config.php';
$dbc = get_db_connection();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Movie Review</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header>
        <nav class="container">
            <h1 class="logo">Movie Review</h1>
            <ul class="nav-links">
                <li><a href="index.php">Home</a></li>
                <li><a href="#">Movies</a></li>
                <li><a href="#">Reviews</a></li>
                <li><a href="#">About</a></li>
            </ul>
        </nav>
    </header>

    <main class="container">
        <section class="hero">
            <h2>Welcome to Movie Review</h2>
            <p>Discover and review your favorite movies.</p>
        </section>

        <section class="status">
            <h3>Database Status</h3>
            <?php if (mysqli_ping($dbc)): ?>
                <p class="success">Connected to database: <strong>db202202672</strong></p>
                <p>Server info: <?= htmlspecialchars(mysqli_get_server_info($dbc)) ?></p>
            <?php else: ?>
                <p class="error">Database connection failed.</p>
            <?php endif; ?>
        </section>
    </main>

    <footer>
        <div class="container">
            <p>&copy; <?= date('Y') ?> Movie Review</p>
        </div>
    </footer>
</body>
</html>
<?php mysqli_close($dbc); ?>
