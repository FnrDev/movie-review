<?php
session_start();
require_once '../config.php';

// Only creators can access this page
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'creator') {
    header('Location: ../login.php');
    exit;
}

$dbc       = get_db_connection();
$creatorId = (int) $_SESSION['user']['user_id'];

// Handle publish/unpublish toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_publish'])) {
    $movieId  = (int) $_POST['movie_id'];
    $newState = (int) $_POST['new_state'];
    $stmt = mysqli_prepare($dbc,
        "UPDATE dbProj_movies SET is_published = ? WHERE movie_id = ? AND creator_id = ?"
    );
    mysqli_stmt_bind_param($stmt, 'iii', $newState, $movieId, $creatorId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    header('Location: index.php?msg=' . ($newState ? 'published' : 'unpublished'));
    exit;
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_movie'])) {
    $movieId = (int) $_POST['movie_id'];
    $stmt = mysqli_prepare($dbc,
        "DELETE FROM dbProj_movies WHERE movie_id = ? AND creator_id = ?"
    );
    mysqli_stmt_bind_param($stmt, 'ii', $movieId, $creatorId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    header('Location: index.php?msg=deleted');
    exit;
}

// Fetch creator's movies
$stmt = mysqli_prepare($dbc, "
    SELECT m.movie_id, m.title, m.short_description, m.poster_image,
           m.release_year, m.is_published, m.view_count, m.created_at,
           g.genre_name,
           COUNT(DISTINCT r.rating_id) AS rating_count,
           COALESCE(ROUND(AVG(r.rating_value),1), 0) AS avg_rating
    FROM dbProj_movies m
    JOIN dbProj_genres g ON m.genre_id = g.genre_id
    LEFT JOIN dbProj_ratings r ON m.movie_id = r.movie_id
    WHERE m.creator_id = ?
    GROUP BY m.movie_id
    ORDER BY m.created_at DESC
");
mysqli_stmt_bind_param($stmt, 'i', $creatorId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$movies = [];
while ($row = mysqli_fetch_assoc($result)) {
    $movies[] = $row;
}
mysqli_stmt_close($stmt);

$msg = $_GET['msg'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Movies &middot; Movie Review</title>
    <link rel="stylesheet" href="../style.css">
    <style>
        .creator-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 2rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #333;
        }
        .creator-header h2 { font-size: 1.5rem; }
        .btn-red {
            background-color: #e50914;
            color: #fff;
            border: none;
            border-radius: 7px;
            padding: 0.6rem 1.2rem;
            font: inherit;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: background-color 0.2s;
        }
        .btn-red:hover { background-color: #c40811; }
        .btn-outline {
            background: transparent;
            color: #f5f5f5;
            border: 1px solid #444;
            border-radius: 7px;
            padding: 0.45rem 0.85rem;
            font: inherit;
            font-size: 0.82rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: border-color 0.2s;
        }
        .btn-outline:hover { border-color: #888; }
        .btn-danger {
            background: transparent;
            color: #fca5a5;
            border: 1px solid rgba(239,68,68,0.4);
            border-radius: 7px;
            padding: 0.45rem 0.85rem;
            font: inherit;
            font-size: 0.82rem;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .btn-danger:hover { background-color: rgba(239,68,68,0.1); }
        .btn-publish {
            background: transparent;
            color: #86efac;
            border: 1px solid rgba(34,197,94,0.4);
            border-radius: 7px;
            padding: 0.45rem 0.85rem;
            font: inherit;
            font-size: 0.82rem;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .btn-publish:hover { background-color: rgba(34,197,94,0.1); }
        .alert {
            padding: 0.75rem 1rem;
            border-radius: 7px;
            margin-top: 2rem;
            margin-bottom: 1.25rem;
            font-size: 0.9rem;
        }
        .alert.ok  { background: rgba(34,197,94,0.12); border: 1px solid rgba(34,197,94,0.35); color: #86efac; }
        .alert.err { background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.35); color: #fca5a5; }
        .movies-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.88rem;
        }
        .movies-table th,
        .movies-table td {
            padding: 0.75rem 0.85rem;
            text-align: left;
            border-bottom: 1px solid #2a2a2a;
            vertical-align: middle;
        }
        .movies-table th {
            color: #b3b3b3;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .movies-table tbody tr:hover { background-color: #1a1a1a; }
        .poster-thumb {
            width: 48px;
            height: 64px;
            object-fit: cover;
            border-radius: 4px;
            background: #2a2a2a;
            display: block;
        }
        .no-poster {
            width: 48px;
            height: 64px;
            background: #2a2a2a;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.65rem;
            color: #666;
        }
        .badge {
            display: inline-block;
            font-size: 0.7rem;
            padding: 0.15rem 0.5rem;
            border-radius: 999px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .badge.published { background: rgba(34,197,94,0.18); color: #4ade80; }
        .badge.draft     { background: rgba(148,163,184,0.18); color: #94a3b8; }
        .actions { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; }
        .actions form { margin: 0; }
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #b3b3b3;
            background: #1f1f1f;
            border-radius: 10px;
            border: 1px dashed #333;
        }
        .empty-state p { margin-bottom: 1rem; }
        .stats-bar {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .stat-box {
            background: #1f1f1f;
            border: 1px solid #2a2a2a;
            border-radius: 8px;
            padding: 0.85rem 1.25rem;
            flex: 1;
        }
        .stat-box .num { font-size: 1.6rem; font-weight: 700; color: #e50914; }
        .stat-box .lbl { font-size: 0.78rem; color: #888; margin-top: 0.1rem; }
    </style>
</head>
<body>
    <header>
        <nav class="container">
            <h1 class="logo"><a href="../index.php" style="color:inherit;text-decoration:none;">Movie Review</a></h1>
            <ul class="nav-links">
                <li><a href="../index.php">Home</a></li>
                <li><a href="index.php">My Movies</a></li>
                <li><a href="../logout.php">Logout</a></li>
            </ul>
        </nav>
    </header>

    <main class="container">
        <?php if ($msg === 'published'): ?>
            <div class="alert ok">Movie published successfully!</div>
        <?php elseif ($msg === 'unpublished'): ?>
            <div class="alert ok">Movie moved back to draft.</div>
        <?php elseif ($msg === 'deleted'): ?>
            <div class="alert ok">Movie deleted.</div>
        <?php elseif ($msg === 'saved'): ?>
            <div class="alert ok">Movie saved as draft.</div>
        <?php elseif ($msg === 'added'): ?>
            <div class="alert ok">Movie added successfully!</div>
        <?php elseif ($msg === 'updated'): ?>
            <div class="alert ok">Movie updated successfully!</div>
        <?php endif; ?>

        <div class="creator-header">
            <h2>My Movies</h2>
            <a href="add.php" class="btn-red">+ Add New Movie</a>
        </div>

        <!-- Stats -->
        <?php
        $total     = count($movies);
        $published = count(array_filter($movies, fn($m) => $m['is_published']));
        $drafts    = $total - $published;
        $totalViews = array_sum(array_column($movies, 'view_count'));
        ?>
        <div class="stats-bar">
            <div class="stat-box">
                <div class="num"><?= $total ?></div>
                <div class="lbl">Total Movies</div>
            </div>
            <div class="stat-box">
                <div class="num"><?= $published ?></div>
                <div class="lbl">Published</div>
            </div>
            <div class="stat-box">
                <div class="num"><?= $drafts ?></div>
                <div class="lbl">Drafts</div>
            </div>
            <div class="stat-box">
                <div class="num"><?= $totalViews ?></div>
                <div class="lbl">Total Views</div>
            </div>
        </div>

        <?php if (empty($movies)): ?>
            <div class="empty-state">
                <p>You haven't added any movies yet.</p>
                <a href="add.php" class="btn-red">Add Your First Movie</a>
            </div>
        <?php else: ?>
            <table class="movies-table">
                <thead>
                    <tr>
                        <th>Poster</th>
                        <th>Title</th>
                        <th>Genre</th>
                        <th>Year</th>
                        <th>Status</th>
                        <th>Rating</th>
                        <th>Views</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($movies as $movie): ?>
                        <tr>
                            <td>
                                <?php if (!empty($movie['poster_image'])): ?>
                                    <img src="../<?= htmlspecialchars($movie['poster_image']) ?>"
                                         class="poster-thumb"
                                         onerror="this.style.display='none'">
                                <?php else: ?>
                                    <div class="no-poster">No img</div>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= htmlspecialchars($movie['title']) ?></strong></td>
                            <td><?= htmlspecialchars($movie['genre_name']) ?></td>
                            <td><?= htmlspecialchars($movie['release_year'] ?? '—') ?></td>
                            <td>
                                <span class="badge <?= $movie['is_published'] ? 'published' : 'draft' ?>">
                                    <?= $movie['is_published'] ? 'Published' : 'Draft' ?>
                                </span>
                            </td>
                            <td>
                                <?= $movie['rating_count'] > 0
                                    ? '★ ' . number_format((float)$movie['avg_rating'], 1) . ' (' . (int)$movie['rating_count'] . ')'
                                    : '—' ?>
                            </td>
                            <td><?= (int)$movie['view_count'] ?></td>
                            <td>
                                <div class="actions">
                                    <a href="edit.php?id=<?= (int)$movie['movie_id'] ?>" class="btn-outline">Edit</a>

                                    <!-- Publish / Unpublish -->
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="movie_id" value="<?= (int)$movie['movie_id'] ?>">
                                        <input type="hidden" name="new_state" value="<?= $movie['is_published'] ? 0 : 1 ?>">
                                        <button type="submit" name="toggle_publish"
                                                class="<?= $movie['is_published'] ? 'btn-outline' : 'btn-publish' ?>">
                                            <?= $movie['is_published'] ? 'Unpublish' : 'Publish' ?>
                                        </button>
                                    </form>

                                    <!-- Delete -->
                                    <form method="post" style="display:inline;"
                                          onsubmit="return confirm('Delete this movie? This cannot be undone.')">
                                        <input type="hidden" name="movie_id" value="<?= (int)$movie['movie_id'] ?>">
                                        <button type="submit" name="delete_movie" class="btn-danger">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>

    <footer>
        <div class="container">
            <p>&copy; <?= date('Y') ?> Movie Review</p>
        </div>
    </footer>
</body>
</html>
<?php mysqli_close($dbc); ?>