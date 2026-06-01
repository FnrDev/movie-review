<?php
session_start();
require_once '../config.php';

// Only admins can access
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$dbc    = get_db_connection();
$action = $_GET['action'] ?? 'dashboard';
$msg    = '';

// Handle POST actions 

// Toggle user active/inactive
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_user'])) {
    $uid      = (int) $_POST['user_id'];
    $newState = (int) $_POST['new_state'];
    // Prevent admin from deactivating themselves
    if ($uid !== (int) $_SESSION['user']['user_id']) {
        $stmt = mysqli_prepare($dbc, "UPDATE dbProj_users SET is_active = ? WHERE user_id = ?");
        mysqli_stmt_bind_param($stmt, 'ii', $newState, $uid);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    header('Location: index.php?action=users&msg=' . ($newState ? 'activated' : 'deactivated'));
    exit;
}

// Change user role
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_role'])) {
    $uid     = (int) $_POST['user_id'];
    $newRole = $_POST['new_role'] ?? '';
    if (in_array($newRole, ['visitor', 'creator', 'admin'], true) && $uid !== (int) $_SESSION['user']['user_id']) {
        $stmt = mysqli_prepare($dbc, "UPDATE dbProj_users SET role = ? WHERE user_id = ?");
        mysqli_stmt_bind_param($stmt, 'si', $newRole, $uid);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    header('Location: index.php?action=users&msg=role_updated');
    exit;
}

// Delete user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $uid = (int) $_POST['user_id'];
    if ($uid !== (int) $_SESSION['user']['user_id']) {
        $stmt = mysqli_prepare($dbc, "DELETE FROM dbProj_users WHERE user_id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $uid);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    header('Location: index.php?action=users&msg=user_deleted');
    exit;
}

// Delete movie (admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_movie'])) {
    $mid = (int) $_POST['movie_id'];
    $stmt = mysqli_prepare($dbc, "DELETE FROM dbProj_movies WHERE movie_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $mid);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    header('Location: index.php?action=movies&msg=movie_deleted');
    exit;
}

// Toggle movie publish (admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_movie'])) {
    $mid      = (int) $_POST['movie_id'];
    $newState = (int) $_POST['new_state'];
    $stmt = mysqli_prepare($dbc, "UPDATE dbProj_movies SET is_published = ? WHERE movie_id = ?");
    mysqli_stmt_bind_param($stmt, 'ii', $newState, $mid);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    header('Location: index.php?action=movies&msg=movie_updated');
    exit;
}

// Delete comment (admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_comment'])) {
    $cid = (int) $_POST['comment_id'];
    $stmt = mysqli_prepare($dbc, "DELETE FROM dbProj_comments WHERE comment_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $cid);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    header('Location: index.php?action=comments&msg=comment_deleted');
    exit;
}

$msg = $_GET['msg'] ?? '';

// ── Fetch data based on action ─────────────────────────────────────────────

// Dashboard stats
$stats = [];
if ($action === 'dashboard') {
    $stats['users']    = mysqli_fetch_assoc(mysqli_query($dbc, "SELECT COUNT(*) AS n FROM dbProj_users"))['n'];
    $stats['movies']   = mysqli_fetch_assoc(mysqli_query($dbc, "SELECT COUNT(*) AS n FROM dbProj_movies"))['n'];
    $stats['published']= mysqli_fetch_assoc(mysqli_query($dbc, "SELECT COUNT(*) AS n FROM dbProj_movies WHERE is_published=1"))['n'];
    $stats['comments'] = mysqli_fetch_assoc(mysqli_query($dbc, "SELECT COUNT(*) AS n FROM dbProj_comments"))['n'];
    $stats['ratings']  = mysqli_fetch_assoc(mysqli_query($dbc, "SELECT COUNT(*) AS n FROM dbProj_ratings"))['n'];
    $stats['views']    = mysqli_fetch_assoc(mysqli_query($dbc, "SELECT COALESCE(SUM(view_count),0) AS n FROM dbProj_movies"))['n'];
}

// Users list
$users = [];
if ($action === 'users') {
    $res = mysqli_query($dbc, "
        SELECT u.user_id, u.username, u.email, u.role, u.is_active, u.created_at,
               COUNT(m.movie_id) AS movie_count
        FROM dbProj_users u
        LEFT JOIN dbProj_movies m ON m.creator_id = u.user_id
        GROUP BY u.user_id
        ORDER BY u.created_at DESC
    ");
    while ($row = mysqli_fetch_assoc($res)) $users[] = $row;
}

// Movies list
$movies = [];
if ($action === 'movies') {
    $res = mysqli_query($dbc, "
        SELECT m.movie_id, m.title, m.is_published, m.view_count, m.created_at,
               g.genre_name, u.username AS creator_name,
               COUNT(DISTINCT r.rating_id) AS rating_count,
               COALESCE(ROUND(AVG(r.rating_value),1),0) AS avg_rating,
               COUNT(DISTINCT c.comment_id) AS comment_count
        FROM dbProj_movies m
        JOIN dbProj_genres g ON m.genre_id = g.genre_id
        JOIN dbProj_users u ON m.creator_id = u.user_id
        LEFT JOIN dbProj_ratings r ON m.movie_id = r.movie_id
        LEFT JOIN dbProj_comments c ON m.movie_id = c.movie_id
        GROUP BY m.movie_id
        ORDER BY m.created_at DESC
    ");
    while ($row = mysqli_fetch_assoc($res)) $movies[] = $row;
}

// Comments list
$comments = [];
if ($action === 'comments') {
    $res = mysqli_query($dbc, "
        SELECT c.comment_id, c.comment_text, c.created_at,
               u.username, m.title AS movie_title, m.movie_id
        FROM dbProj_comments c
        JOIN dbProj_users u ON c.user_id = u.user_id
        JOIN dbProj_movies m ON c.movie_id = m.movie_id
        ORDER BY c.created_at DESC
    ");
    while ($row = mysqli_fetch_assoc($res)) $comments[] = $row;
}

// Reports
$popularRows  = [];
$creatorRows  = [];
$creators     = [];
$selectedCreator     = 0;
$selectedCreatorName = '';
$report    = $_GET['r'] ?? 'popular';
$startDate = $_GET['start'] ?? date('Y-m-01', strtotime('-3 months'));
$endDate   = $_GET['end']   ?? date('Y-m-d');

if ($action === 'reports') {
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
        while ($row = mysqli_fetch_assoc($res)) $popularRows[] = $row;
        mysqli_stmt_close($stmt);
    }

    if ($report === 'creator') {
        $selectedCreator = isset($_GET['creator']) && $_GET['creator'] !== '' ? (int)$_GET['creator'] : 0;
        $cRes = mysqli_query($dbc, "
            SELECT u.user_id, u.username, COUNT(m.movie_id) AS movie_count
            FROM dbProj_users u
            LEFT JOIN dbProj_movies m ON m.creator_id = u.user_id
            GROUP BY u.user_id HAVING movie_count > 0 ORDER BY u.username
        ");
        while ($row = mysqli_fetch_assoc($cRes)) {
            $creators[] = $row;
            if ($row['user_id'] == $selectedCreator) $selectedCreatorName = $row['username'];
        }
        if ($selectedCreator > 0) {
            $stmt = mysqli_prepare($dbc, "
                SELECT m.movie_id, m.title, m.release_year, m.view_count,
                       m.is_published, m.created_at, g.genre_name,
                       COUNT(r.rating_id) AS rating_count,
                       COALESCE(ROUND(AVG(r.rating_value),1),0) AS avg_rating
                FROM dbProj_movies m
                JOIN dbProj_genres g ON m.genre_id = g.genre_id
                LEFT JOIN dbProj_ratings r ON m.movie_id = r.movie_id
                WHERE m.creator_id = ?
                GROUP BY m.movie_id ORDER BY m.created_at DESC
            ");
            mysqli_stmt_bind_param($stmt, 'i', $selectedCreator);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($res)) $creatorRows[] = $row;
            mysqli_stmt_close($stmt);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel &middot; Movie Review</title>
    <link rel="stylesheet" href="../style.css">
    <style>
        .admin-layout { display: flex; min-height: calc(100vh - 73px); }
        .admin-sidebar {
            width: 220px; background: #1a1a1a;
            border-right: 1px solid #2a2a2a;
            padding: 1.5rem 0; flex-shrink: 0;
        }
        .admin-sidebar h2 {
            font-size: 0.7rem; text-transform: uppercase;
            letter-spacing: 0.1em; color: #666;
            padding: 0 1.25rem; margin-bottom: 0.5rem;
        }
        .admin-nav { list-style: none; }
        .admin-nav a {
            display: block; padding: 0.65rem 1.25rem;
            color: #b3b3b3; text-decoration: none;
            border-left: 3px solid transparent;
            font-size: 0.9rem;
            transition: background 0.15s, color 0.15s, border-color 0.15s;
        }
        .admin-nav a:hover { background: #222; color: #f5f5f5; }
        .admin-nav a.active { background: #222; border-left-color: #e50914; color: #f5f5f5; }
        .admin-content { flex: 1; padding: 2rem; min-width: 0; }
        .admin-content h1 { font-size: 1.5rem; margin-bottom: 0.25rem; }
        .subtitle { color: #888; font-size: 0.88rem; margin-bottom: 1.5rem; }
        /* Stats grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 1rem; margin-bottom: 2rem;
        }
        .stat-card {
            background: #1f1f1f; border: 1px solid #2a2a2a;
            border-radius: 10px; padding: 1.1rem 1.25rem;
        }
        .stat-card .num { font-size: 1.8rem; font-weight: 700; color: #e50914; }
        .stat-card .lbl { font-size: 0.78rem; color: #888; margin-top: 0.1rem; }
        /* Table */
        .data-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        .data-table th, .data-table td {
            padding: 0.65rem 0.75rem; text-align: left;
            border-bottom: 1px solid #2a2a2a; vertical-align: middle;
        }
        .data-table th {
            color: #888; font-size: 0.72rem;
            text-transform: uppercase; letter-spacing: 0.05em;
        }
        .data-table tbody tr:hover { background: #1a1a1a; }
        .data-table .num { text-align: right; }
        /* Badges */
        .badge {
            display: inline-block; font-size: 0.68rem;
            padding: 0.15rem 0.5rem; border-radius: 999px;
            font-weight: 600; text-transform: uppercase;
        }
        .badge.ok     { background: rgba(34,197,94,0.18); color: #4ade80; }
        .badge.off    { background: rgba(148,163,184,0.18); color: #94a3b8; }
        .badge.admin  { background: rgba(229,9,20,0.18); color: #f87171; }
        .badge.creator{ background: rgba(251,191,36,0.18); color: #fbbf24; }
        .badge.visitor{ background: rgba(148,163,184,0.18); color: #94a3b8; }
        /* Buttons */
        .btn { border: none; border-radius: 6px; padding: 0.35rem 0.75rem; font: inherit; font-size: 0.78rem; font-weight: 600; cursor: pointer; transition: background 0.15s; }
        .btn.red  { background: #e50914; color: #fff; }
        .btn.red:hover { background: #c40811; }
        .btn.gray { background: transparent; color: #f5f5f5; border: 1px solid #444; }
        .btn.gray:hover { border-color: #888; }
        .btn.danger { background: transparent; color: #fca5a5; border: 1px solid rgba(239,68,68,0.4); }
        .btn.danger:hover { background: rgba(239,68,68,0.1); }
        .btn.green { background: transparent; color: #86efac; border: 1px solid rgba(34,197,94,0.4); }
        .btn.green:hover { background: rgba(34,197,94,0.1); }
        .actions { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; }
        .actions form { margin: 0; }
        /* Alert */
        .alert { padding: 0.7rem 1rem; border-radius: 7px; margin-bottom: 1rem; font-size: 0.88rem; }
        .alert.ok  { background: rgba(34,197,94,0.12); border: 1px solid rgba(34,197,94,0.35); color: #86efac; }
        .alert.err { background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.35); color: #fca5a5; }
        /* Admin card */
        .admin-card {
            background: #1f1f1f; border: 1px solid #2a2a2a;
            border-radius: 10px; padding: 1.5rem; margin-bottom: 1rem;
        }
        /* Reports tabs */
        .admin-tabs { display: flex; gap: 0.25rem; border-bottom: 1px solid #333; margin-bottom: 1.5rem; }
        .admin-tabs a { color: #888; text-decoration: none; padding: 0.55rem 1rem; border-bottom: 2px solid transparent; font-size: 0.88rem; }
        .admin-tabs a:hover { color: #f5f5f5; }
        .admin-tabs a.active { color: #e50914; border-bottom-color: #e50914; }
        /* Form inline */
        .admin-form { display: flex; flex-wrap: wrap; gap: 0.65rem; align-items: flex-end; }
        .admin-form .field { display: flex; flex-direction: column; gap: 0.25rem; }
        .admin-form label { font-size: 0.78rem; color: #888; }
        .admin-form input, .admin-form select {
            background: #141414; color: #f5f5f5;
            border: 1px solid #333; border-radius: 6px;
            padding: 0.45rem 0.65rem; font: inherit; min-width: 180px;
        }
        .admin-form input:focus, .admin-form select:focus { outline: none; border-color: #e50914; }
        .muted { color: #888; font-size: 0.85rem; }
        .comment-preview {
            max-width: 320px; overflow: hidden;
            text-overflow: ellipsis; white-space: nowrap; color: #ddd;
        }
        select.role-select {
            background: #141414; color: #f5f5f5;
            border: 1px solid #333; border-radius: 5px;
            padding: 0.3rem 0.5rem; font: inherit; font-size: 0.78rem;
            min-width: 90px; margin-right: 0.25rem;
        }
    </style>
</head>
<body>
    <header>
        <nav class="container">
            <h1 class="logo"><a href="../index.php" style="color:inherit;text-decoration:none;">Movie Review</a></h1>
            <ul class="nav-links">
                <li><a href="../index.php">Back to site</a></li>
                <li><a href="../logout.php">Logout</a></li>
            </ul>
        </nav>
    </header>

    <div class="admin-layout">
        <aside class="admin-sidebar">
            <h2>Admin Panel</h2>
            <ul class="admin-nav">
                <li><a href="?action=dashboard" class="<?= $action==='dashboard'?'active':'' ?>">📊 Dashboard</a></li>
                <li><a href="?action=users"     class="<?= $action==='users'    ?'active':'' ?>">👥 Users</a></li>
                <li><a href="?action=movies"    class="<?= $action==='movies'   ?'active':'' ?>">🎬 Movies</a></li>
                <li><a href="?action=comments"  class="<?= $action==='comments' ?'active':'' ?>">💬 Comments</a></li>
                <li><a href="?action=reports"   class="<?= $action==='reports'  ?'active':'' ?>">📈 Reports</a></li>
            </ul>
        </aside>

        <main class="admin-content">

            <?php if ($msg): ?>
                <?php $msgs = [
                    'activated'     => 'User activated.',
                    'deactivated'   => 'User deactivated.',
                    'role_updated'  => 'User role updated.',
                    'user_deleted'  => 'User deleted.',
                    'movie_deleted' => 'Movie deleted.',
                    'movie_updated' => 'Movie updated.',
                    'comment_deleted'=> 'Comment removed.',
                ]; ?>
                <div class="alert ok"><?= htmlspecialchars($msgs[$msg] ?? $msg) ?></div>
            <?php endif; ?>

            <?php if ($action === 'dashboard'): ?>
                <h1>Dashboard</h1>
                <p class="subtitle">Overview of the platform.</p>
                <div class="stats-grid">
                    <div class="stat-card"><div class="num"><?= $stats['users'] ?></div><div class="lbl">Total Users</div></div>
                    <div class="stat-card"><div class="num"><?= $stats['movies'] ?></div><div class="lbl">Total Movies</div></div>
                    <div class="stat-card"><div class="num"><?= $stats['published'] ?></div><div class="lbl">Published</div></div>
                    <div class="stat-card"><div class="num"><?= $stats['comments'] ?></div><div class="lbl">Comments</div></div>
                    <div class="stat-card"><div class="num"><?= $stats['ratings'] ?></div><div class="lbl">Ratings</div></div>
                    <div class="stat-card"><div class="num"><?= number_format($stats['views']) ?></div><div class="lbl">Total Views</div></div>
                </div>
                <p class="muted">Use the sidebar to manage users, movies, comments, and view reports.</p>

            <?php elseif ($action === 'users'): ?>
                <h1>Users</h1>
                <p class="subtitle">Manage all registered users.</p>
                <div class="admin-card">
                    <?php if (empty($users)): ?>
                        <p class="muted">No users found.</p>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th class="num">Movies</th>
                                    <th>Joined</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $u): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                                        <td><?= htmlspecialchars($u['email']) ?></td>
                                        <td>
                                            <span class="badge <?= $u['role'] ?>">
                                                <?= htmlspecialchars($u['role']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge <?= $u['is_active'] ? 'ok' : 'off' ?>">
                                                <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
                                            </span>
                                        </td>
                                        <td class="num"><?= (int)$u['movie_count'] ?></td>
                                        <td><?= htmlspecialchars(substr($u['created_at'], 0, 10)) ?></td>
                                        <td>
                                            <?php if ($u['user_id'] != $_SESSION['user']['user_id']): ?>
                                                <div class="actions">
                                                    <!-- Change role -->
                                                    <form method="post" style="display:inline;">
                                                        <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                                                        <select name="new_role" class="role-select"
                                                                onchange="this.form.submit()">
                                                            <option value="visitor"  <?= $u['role']==='visitor' ?'selected':'' ?>>Visitor</option>
                                                            <option value="creator"  <?= $u['role']==='creator' ?'selected':'' ?>>Creator</option>
                                                            <option value="admin"    <?= $u['role']==='admin'   ?'selected':'' ?>>Admin</option>
                                                        </select>
                                                        <button type="submit" name="change_role" style="display:none;"></button>
                                                    </form>
                                                    <!-- Toggle active -->
                                                    <form method="post" style="display:inline;">
                                                        <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                                                        <input type="hidden" name="new_state" value="<?= $u['is_active'] ? 0 : 1 ?>">
                                                        <button type="submit" name="toggle_user"
                                                                class="btn <?= $u['is_active'] ? 'gray' : 'green' ?>">
                                                            <?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>
                                                        </button>
                                                    </form>
                                                    <!-- Delete -->
                                                    <form method="post" style="display:inline;"
                                                          onsubmit="return confirm('Delete this user? All their content stays.')">
                                                        <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                                                        <button type="submit" name="delete_user" class="btn danger">Delete</button>
                                                    </form>
                                                </div>
                                            <?php else: ?>
                                                <span class="muted">You</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

            <?php elseif ($action === 'movies'): ?>
                <h1>Movies</h1>
                <p class="subtitle">Manage all movies on the platform.</p>
                <div class="admin-card">
                    <?php if (empty($movies)): ?>
                        <p class="muted">No movies found.</p>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Creator</th>
                                    <th>Genre</th>
                                    <th>Status</th>
                                    <th class="num">Ratings</th>
                                    <th class="num">Views</th>
                                    <th class="num">Comments</th>
                                    <th>Added</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($movies as $movie): ?>
                                    <tr>
                                        <td>
                                            <a href="../movie.php?id=<?= (int)$movie['movie_id'] ?>"
                                               style="color:#f5f5f5;text-decoration:none;"
                                               target="_blank">
                                                <?= htmlspecialchars($movie['title']) ?>
                                            </a>
                                        </td>
                                        <td><?= htmlspecialchars($movie['creator_name']) ?></td>
                                        <td><?= htmlspecialchars($movie['genre_name']) ?></td>
                                        <td>
                                            <span class="badge <?= $movie['is_published'] ? 'ok' : 'off' ?>">
                                                <?= $movie['is_published'] ? 'Published' : 'Draft' ?>
                                            </span>
                                        </td>
                                        <td class="num">
                                            <?= $movie['rating_count'] > 0
                                                ? '★ ' . number_format((float)$movie['avg_rating'],1) . ' (' . (int)$movie['rating_count'] . ')'
                                                : '—' ?>
                                        </td>
                                        <td class="num"><?= (int)$movie['view_count'] ?></td>
                                        <td class="num"><?= (int)$movie['comment_count'] ?></td>
                                        <td><?= htmlspecialchars(substr($movie['created_at'],0,10)) ?></td>
                                        <td>
                                            <div class="actions">
                                                <form method="post" style="display:inline;">
                                                    <input type="hidden" name="movie_id" value="<?= (int)$movie['movie_id'] ?>">
                                                    <input type="hidden" name="new_state" value="<?= $movie['is_published'] ? 0 : 1 ?>">
                                                    <button type="submit" name="toggle_movie"
                                                            class="btn <?= $movie['is_published'] ? 'gray' : 'green' ?>">
                                                        <?= $movie['is_published'] ? 'Unpublish' : 'Publish' ?>
                                                    </button>
                                                </form>
                                                <form method="post" style="display:inline;"
                                                      onsubmit="return confirm('Delete this movie permanently?')">
                                                    <input type="hidden" name="movie_id" value="<?= (int)$movie['movie_id'] ?>">
                                                    <button type="submit" name="delete_movie" class="btn danger">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

            <?php elseif ($action === 'comments'): ?>
                <h1>Comments</h1>
                <p class="subtitle">Review and remove inappropriate comments.</p>
                <div class="admin-card">
                    <?php if (empty($comments)): ?>
                        <p class="muted">No comments yet.</p>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Movie</th>
                                    <th>Comment</th>
                                    <th>Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($comments as $c): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($c['username']) ?></td>
                                        <td>
                                            <a href="../movie.php?id=<?= (int)$c['movie_id'] ?>"
                                               style="color:#e50914;text-decoration:none;" target="_blank">
                                                <?= htmlspecialchars($c['movie_title']) ?>
                                            </a>
                                        </td>
                                        <td class="comment-preview"><?= htmlspecialchars($c['comment_text']) ?></td>
                                        <td><?= htmlspecialchars(substr($c['created_at'],0,16)) ?></td>
                                        <td>
                                            <form method="post" style="display:inline;" class="delete-comment-form" data-confirm="Remove this comment?">
                                                <input type="hidden" name="comment_id" value="<?= (int)$c['comment_id'] ?>">
                                                <button type="submit" name="delete_comment" class="btn danger">Remove</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

            <?php elseif ($action === 'reports'): ?>
                <h1>Reports</h1>
                <p class="subtitle">Analytics and content reports.</p>

                <div class="admin-tabs">
                    <a href="?action=reports&r=popular" class="<?= $report==='popular'?'active':'' ?>">Most Popular</a>
                    <a href="?action=reports&r=creator" class="<?= $report==='creator'?'active':'' ?>">By Creator</a>
                </div>

                <?php if ($report === 'popular'): ?>
                    <div class="admin-card">
                        <form method="get" class="admin-form">
                            <input type="hidden" name="action" value="reports">
                            <input type="hidden" name="r" value="popular">
                            <div class="field">
                                <label>Start date</label>
                                <input type="date" name="start" value="<?= htmlspecialchars($startDate) ?>">
                            </div>
                            <div class="field">
                                <label>End date</label>
                                <input type="date" name="end" value="<?= htmlspecialchars($endDate) ?>">
                            </div>
                            <button type="submit" class="btn red">Run Report</button>
                        </form>
                    </div>
                    <div class="admin-card">
                        <?php if (empty($popularRows)): ?>
                            <p class="muted">No data for this range.</p>
                        <?php else: ?>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>#</th><th>Title</th><th>Genre</th><th>Creator</th>
                                        <th class="num">Ratings</th><th class="num">Avg</th><th class="num">Views</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($popularRows as $i => $row): ?>
                                        <tr>
                                            <td><?= $i+1 ?></td>
                                            <td><?= htmlspecialchars($row['title']) ?></td>
                                            <td><?= htmlspecialchars($row['genre_name']) ?></td>
                                            <td><?= htmlspecialchars($row['creator_name']) ?></td>
                                            <td class="num"><?= (int)$row['rating_count'] ?></td>
                                            <td class="num"><?= $row['rating_count']>0 ? number_format((float)$row['avg_rating'],1) : '—' ?></td>
                                            <td class="num"><?= (int)$row['view_count'] ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>

                <?php else: ?>
                    <div class="admin-card">
                        <form method="get" class="admin-form">
                            <input type="hidden" name="action" value="reports">
                            <input type="hidden" name="r" value="creator">
                            <div class="field">
                                <label>Creator</label>
                                <select name="creator">
                                    <option value="">— select creator —</option>
                                    <?php foreach ($creators as $c): ?>
                                        <option value="<?= (int)$c['user_id'] ?>"
                                            <?= $selectedCreator===$c['user_id']?'selected':'' ?>>
                                            <?= htmlspecialchars($c['username']) ?> (<?= (int)$c['movie_count'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn red">Run Report</button>
                        </form>
                    </div>
                    <?php if ($selectedCreator > 0): ?>
                        <div class="admin-card">
                            <p class="muted" style="margin-bottom:0.75rem;">
                                Movies by <strong><?= htmlspecialchars($selectedCreatorName) ?></strong>
                            </p>
                            <?php if (empty($creatorRows)): ?>
                                <p class="muted">No movies.</p>
                            <?php else: ?>
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Title</th><th>Genre</th><th>Status</th>
                                            <th class="num">Ratings</th><th class="num">Avg</th><th class="num">Views</th><th>Created</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($creatorRows as $row): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($row['title']) ?></td>
                                                <td><?= htmlspecialchars($row['genre_name']) ?></td>
                                                <td><span class="badge <?= $row['is_published']?'ok':'off' ?>"><?= $row['is_published']?'Published':'Draft' ?></span></td>
                                                <td class="num"><?= (int)$row['rating_count'] ?></td>
                                                <td class="num"><?= $row['rating_count']>0?number_format((float)$row['avg_rating'],1):'—' ?></td>
                                                <td class="num"><?= (int)$row['view_count'] ?></td>
                                                <td><?= htmlspecialchars(substr($row['created_at'],0,10)) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

            <?php endif; ?>
        </main>
    </div>

    <footer>
        <div class="container">
            <p>&copy; <?= date('Y') ?> Movie Review</p>
        </div>
    </footer>
    
    <!-- jQuery Library -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script>
        $(document).ready(function() {
            // Fade in alerts
            $('.alert').hide().fadeIn(500);
            
            // Fade in stat cards
            $('.stat-card').hide().each(function(index) {
                $(this).delay(index * 80).fadeIn(400);
            });
            
            // Fade in table rows
            $('.data-table tbody tr').hide().each(function(index) {
                $(this).delay(index * 30).fadeIn(300);
            });
            
            // Smooth confirmation dialogs for delete comment
            $('.delete-comment-form').on('submit', function(e) {
                e.preventDefault();
                
                const form = $(this);
                const message = form.data('confirm') || 'Are you sure?';
                
                // Create custom confirm dialog with fade
                const overlay = $('<div>').css({
                    position: 'fixed', top: 0, left: 0, right: 0, bottom: 0,
                    background: 'rgba(0,0,0,0.7)', zIndex: 9999,
                    display: 'flex', alignItems: 'center', justifyContent: 'center'
                }).hide();
                
                const dialog = $('<div>').css({
                    background: '#1f1f1f', padding: '2rem', borderRadius: '12px',
                    border: '1px solid #2a2a2a', maxWidth: '400px', textAlign: 'center'
                }).html(`
                    <p style="margin-bottom: 1.5rem; font-size: 1.1rem; color: #f5f5f5;">${message}</p>
                    <button class="btn-confirm" style="background: #e50914; color: #fff; border: none; padding: 0.5rem 1.5rem; border-radius: 6px; margin-right: 0.5rem; cursor: pointer; font-weight: 600;">Confirm</button>
                    <button class="btn-cancel" style="background: transparent; color: #f5f5f5; border: 1px solid #444; padding: 0.5rem 1.5rem; border-radius: 6px; cursor: pointer; font-weight: 600;">Cancel</button>
                `);
                
                overlay.append(dialog);
                $('body').append(overlay);
                overlay.fadeIn(200);
                
                dialog.find('.btn-confirm').on('click', function() {
                    overlay.fadeOut(200, function() {
                        overlay.remove();
                        // Add hidden input to ensure delete_comment is in POST data
                        if (!form.find('input[name="delete_comment"]').length) {
                            form.append('<input type="hidden" name="delete_comment" value="1">');
                        }
                        // Unbind this jQuery handler and submit natively
                        form.off('submit');
                        form[0].submit();
                    });
                });
                
                dialog.find('.btn-cancel').on('click', function() {
                    overlay.fadeOut(200, function() { overlay.remove(); });
                });
            });
        });
    </script>
</body>
</html>
<?php mysqli_close($dbc); ?>