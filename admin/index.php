<?php
// TODO: re-enable admin role check
// session_start();
// if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? '') !== 'admin') {
//     header('Location: ../index.php');
//     exit;
// }

require_once '../config.php';
$dbc = get_db_connection();

$report = $_GET['r'] ?? 'popular';
if (!in_array($report, ['popular', 'creator'], true)) {
    $report = 'popular';
}

// ===== Report 1: Most popular movies within a date range =====
$startDate = $_GET['start'] ?? date('Y-m-01', strtotime('-3 months'));
$endDate = $_GET['end'] ?? date('Y-m-d');
$popularRows = [];
if ($report === 'popular') {
    $stmt = mysqli_prepare($dbc, "
        SELECT m.movie_id, m.title, m.poster_image, m.release_year, m.view_count,
               g.genre_name, u.username AS creator_name,
               COUNT(r.rating_id) AS rating_count,
               COALESCE(ROUND(AVG(r.rating_value), 1), 0) AS avg_rating
        FROM dbProj_movies m
        JOIN dbProj_genres g ON m.genre_id = g.genre_id
        JOIN dbProj_users u ON m.creator_id = u.user_id
        LEFT JOIN dbProj_ratings r ON m.movie_id = r.movie_id
            AND r.created_at BETWEEN ? AND ?
        WHERE m.is_published = 1
        GROUP BY m.movie_id
        ORDER BY rating_count DESC, m.view_count DESC
        LIMIT 50
    ");
    $endParam = $endDate . ' 23:59:59';
    mysqli_stmt_bind_param($stmt, 'ss', $startDate, $endParam);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $popularRows[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

// ===== Report 2: Movies created by a specific creator =====
$creators = [];
$selectedCreator = isset($_GET['creator']) && $_GET['creator'] !== '' ? (int) $_GET['creator'] : 0;
$creatorRows = [];
$selectedCreatorName = '';
if ($report === 'creator') {
    $cRes = mysqli_query($dbc, "
        SELECT u.user_id, u.username, COUNT(m.movie_id) AS movie_count
        FROM dbProj_users u
        LEFT JOIN dbProj_movies m ON m.creator_id = u.user_id
        GROUP BY u.user_id
        HAVING movie_count > 0
        ORDER BY u.username
    ");
    if ($cRes) {
        while ($row = mysqli_fetch_assoc($cRes)) {
            $creators[] = $row;
            if ($row['user_id'] == $selectedCreator) {
                $selectedCreatorName = $row['username'];
            }
        }
    }

    if ($selectedCreator > 0) {
        $stmt = mysqli_prepare($dbc, "
            SELECT m.movie_id, m.title, m.poster_image, m.release_year, m.view_count,
                   m.is_published, m.created_at, g.genre_name,
                   COUNT(r.rating_id) AS rating_count,
                   COALESCE(ROUND(AVG(r.rating_value), 1), 0) AS avg_rating
            FROM dbProj_movies m
            JOIN dbProj_genres g ON m.genre_id = g.genre_id
            LEFT JOIN dbProj_ratings r ON m.movie_id = r.movie_id
            WHERE m.creator_id = ?
            GROUP BY m.movie_id
            ORDER BY m.created_at DESC
        ");
        mysqli_stmt_bind_param($stmt, 'i', $selectedCreator);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $creatorRows[] = $row;
            }
        }
        mysqli_stmt_close($stmt);
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin &middot; Movie Review</title>
    <link rel="stylesheet" href="../style.css">
    <style>
        .admin-layout {
            display: flex;
            min-height: calc(100vh - 73px);
        }
        .admin-sidebar {
            width: 240px;
            background-color: #1f1f1f;
            border-right: 1px solid #333;
            padding: 1.5rem 0;
            flex-shrink: 0;
        }
        .admin-sidebar h2 {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #888;
            padding: 0 1.5rem;
            margin-bottom: 0.75rem;
        }
        .admin-nav {
            list-style: none;
            display: flex;
            flex-direction: column;
        }
        .admin-nav a {
            display: block;
            padding: 0.75rem 1.5rem;
            color: #f5f5f5;
            text-decoration: none;
            border-left: 3px solid transparent;
            transition: background-color 0.15s, border-color 0.15s, color 0.15s;
        }
        .admin-nav a:hover {
            background-color: #2a2a2a;
            color: #e50914;
        }
        .admin-nav a.active {
            background-color: #2a2a2a;
            border-left-color: #e50914;
            color: #e50914;
        }
        .admin-content {
            flex: 1;
            padding: 2rem;
            min-width: 0;
        }
        .admin-content h1 {
            font-size: 1.6rem;
            margin-bottom: 0.25rem;
        }
        .admin-content .subtitle {
            color: #b3b3b3;
            margin-bottom: 1.5rem;
        }
        .admin-tabs {
            display: flex;
            gap: 0.25rem;
            border-bottom: 1px solid #333;
            margin-bottom: 1.5rem;
        }
        .admin-tabs a {
            color: #b3b3b3;
            text-decoration: none;
            padding: 0.6rem 1rem;
            border-bottom: 2px solid transparent;
            font-size: 0.9rem;
        }
        .admin-tabs a:hover {
            color: #f5f5f5;
        }
        .admin-tabs a.active {
            color: #e50914;
            border-bottom-color: #e50914;
        }
        .admin-card {
            background-color: #1f1f1f;
            border: 1px solid #2a2a2a;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }
        .admin-form {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: end;
        }
        .admin-form .field {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }
        .admin-form label {
            font-size: 0.8rem;
            color: #b3b3b3;
        }
        .admin-form input,
        .admin-form select {
            background-color: #141414;
            color: #f5f5f5;
            border: 1px solid #333;
            border-radius: 6px;
            padding: 0.5rem 0.65rem;
            font: inherit;
            min-width: 200px;
        }
        .admin-form input:focus,
        .admin-form select:focus {
            outline: none;
            border-color: #e50914;
        }
        .btn {
            background-color: #e50914;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 0.55rem 1rem;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }
        .btn:hover { background-color: #c40811; }
        .btn.secondary {
            background-color: transparent;
            border: 1px solid #444;
            color: #f5f5f5;
        }
        .btn.secondary:hover { border-color: #888; }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.88rem;
        }
        .data-table th,
        .data-table td {
            padding: 0.65rem 0.75rem;
            text-align: left;
            border-bottom: 1px solid #2a2a2a;
        }
        .data-table th {
            color: #b3b3b3;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.72rem;
            letter-spacing: 0.05em;
        }
        .data-table tbody tr:hover { background-color: #181818; }
        .data-table .num { text-align: right; font-variant-numeric: tabular-nums; }
        .badge {
            display: inline-block;
            font-size: 0.7rem;
            padding: 0.15rem 0.5rem;
            border-radius: 999px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .badge.ok { background: rgba(34,197,94,0.18); color: #4ade80; }
        .badge.off { background: rgba(148,163,184,0.18); color: #94a3b8; }
        .alert {
            padding: 0.75rem 1rem;
            border-radius: 6px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }
        .alert.ok { background: rgba(34,197,94,0.12); border: 1px solid rgba(34,197,94,0.35); color: #86efac; }
        .alert.err { background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.35); color: #fca5a5; }
        .muted { color: #888; font-size: 0.85rem; }
        .status-row {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <header>
        <nav class="container">
            <h1 class="logo">Movie Review &middot; Admin</h1>
            <ul class="nav-links">
                <li><a href="../index.php">Back to site</a></li>
            </ul>
        </nav>
    </header>

    <div class="admin-layout">
        <aside class="admin-sidebar">
            <h2>Admin</h2>
            <ul class="admin-nav">
                <li><a href="#" onclick="alert('coming soon'); return false;">User Management</a></li>
                <li><a href="#" onclick="alert('coming soon'); return false;">Movie Management</a></li>
                <li><a href="index.php" class="active">Reports</a></li>
            </ul>
        </aside>

        <main class="admin-content">
            <h1>Reports</h1>
            <p class="subtitle">Reports and database tools.</p>

            <div class="admin-tabs">
                <a href="?r=popular" class="<?= $report === 'popular' ? 'active' : '' ?>">Popular by Date Range</a>
                <a href="?r=creator" class="<?= $report === 'creator' ? 'active' : '' ?>">Movies by Creator</a>
            </div>

            <?php if ($report === 'popular'): ?>
                <div class="admin-card">
                    <form method="get" class="admin-form">
                        <input type="hidden" name="r" value="popular">
                        <div class="field">
                            <label for="start">Start date</label>
                            <input type="date" id="start" name="start" value="<?= htmlspecialchars($startDate) ?>" required>
                        </div>
                        <div class="field">
                            <label for="end">End date</label>
                            <input type="date" id="end" name="end" value="<?= htmlspecialchars($endDate) ?>" required>
                        </div>
                        <button type="submit" class="btn">Run report</button>
                    </form>
                </div>

                <div class="admin-card">
                    <p class="muted" style="margin-bottom:0.75rem;">
                        Popularity is ranked by ratings received between
                        <strong><?= htmlspecialchars($startDate) ?></strong> and
                        <strong><?= htmlspecialchars($endDate) ?></strong>.
                    </p>
                    <?php if (empty($popularRows)): ?>
                        <p class="muted">No data for the selected range.</p>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Title</th>
                                    <th>Genre</th>
                                    <th>Creator</th>
                                    <th>Year</th>
                                    <th class="num">Ratings in range</th>
                                    <th class="num">Avg rating</th>
                                    <th class="num">Total views</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($popularRows as $i => $row): ?>
                                    <tr>
                                        <td class="num"><?= $i + 1 ?></td>
                                        <td><?= htmlspecialchars($row['title']) ?></td>
                                        <td><?= htmlspecialchars($row['genre_name']) ?></td>
                                        <td><?= htmlspecialchars($row['creator_name']) ?></td>
                                        <td><?= htmlspecialchars($row['release_year'] ?? '—') ?></td>
                                        <td class="num"><?= (int) $row['rating_count'] ?></td>
                                        <td class="num">
                                            <?= $row['rating_count'] > 0 ? number_format((float) $row['avg_rating'], 1) : '—' ?>
                                        </td>
                                        <td class="num"><?= (int) $row['view_count'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <div class="admin-card">
                    <form method="get" class="admin-form">
                        <input type="hidden" name="r" value="creator">
                        <div class="field">
                            <label for="creator">Creator</label>
                            <select id="creator" name="creator" required>
                                <option value="">— select creator —</option>
                                <?php foreach ($creators as $c): ?>
                                    <option value="<?= (int) $c['user_id'] ?>" <?= $selectedCreator === (int) $c['user_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['username']) ?> (<?= (int) $c['movie_count'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn">Run report</button>
                    </form>
                </div>

                <?php if ($selectedCreator > 0): ?>
                    <div class="admin-card">
                        <p class="muted" style="margin-bottom:0.75rem;">
                            Movies created by <strong><?= htmlspecialchars($selectedCreatorName) ?></strong>.
                        </p>
                        <?php if (empty($creatorRows)): ?>
                            <p class="muted">This creator has no movies.</p>
                        <?php else: ?>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Title</th>
                                        <th>Genre</th>
                                        <th>Year</th>
                                        <th>Status</th>
                                        <th class="num">Ratings</th>
                                        <th class="num">Avg rating</th>
                                        <th class="num">Views</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($creatorRows as $row): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($row['title']) ?></td>
                                            <td><?= htmlspecialchars($row['genre_name']) ?></td>
                                            <td><?= htmlspecialchars($row['release_year'] ?? '—') ?></td>
                                            <td>
                                                <span class="badge <?= $row['is_published'] ? 'ok' : 'off' ?>">
                                                    <?= $row['is_published'] ? 'Published' : 'Draft' ?>
                                                </span>
                                            </td>
                                            <td class="num"><?= (int) $row['rating_count'] ?></td>
                                            <td class="num">
                                                <?= $row['rating_count'] > 0 ? number_format((float) $row['avg_rating'], 1) : '—' ?>
                                            </td>
                                            <td class="num"><?= (int) $row['view_count'] ?></td>
                                            <td><?= htmlspecialchars(substr((string) $row['created_at'], 0, 10)) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </main>
    </div>

    <footer>
        <div class="container">
            <p>&copy; <?= date('Y') ?> Movie Review</p>
        </div>
    </footer>
</body>
</html>
<?php mysqli_close($dbc); ?>
