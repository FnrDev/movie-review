<?php
session_start();
require_once 'config.php';

$dbc     = get_db_connection();
$movieId = (int) ($_GET['id'] ?? 0);

if ($movieId === 0) {
    header('Location: index.php');
    exit;
}

// Fetch movie details
$stmt = mysqli_prepare($dbc, "
    SELECT m.*, g.genre_name, u.username AS creator_name,
           COALESCE(ROUND(AVG(r.rating_value), 1), 0) AS avg_rating,
           COUNT(DISTINCT r.rating_id) AS total_ratings
    FROM dbProj_movies m
    JOIN dbProj_genres g ON m.genre_id = g.genre_id
    JOIN dbProj_users u ON m.creator_id = u.user_id
    LEFT JOIN dbProj_ratings r ON m.movie_id = r.movie_id
    WHERE m.movie_id = ? AND m.is_published = 1
    GROUP BY m.movie_id
    LIMIT 1
");
mysqli_stmt_bind_param($stmt, 'i', $movieId);
mysqli_stmt_execute($stmt);
$res   = mysqli_stmt_get_result($stmt);
$movie = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$movie) {
    header('Location: index.php');
    exit;
}

// Increment view count
mysqli_query($dbc, "UPDATE dbProj_movies SET view_count = view_count + 1 WHERE movie_id = $movieId");

// Fetch media files
$mediaStmt = mysqli_prepare($dbc, "SELECT * FROM dbProj_movie_media WHERE movie_id = ?");
mysqli_stmt_bind_param($mediaStmt, 'i', $movieId);
mysqli_stmt_execute($mediaStmt);
$mediaRes   = mysqli_stmt_get_result($mediaStmt);
$mediaFiles = [];
while ($m = mysqli_fetch_assoc($mediaRes)) {
    $mediaFiles[] = $m;
}
mysqli_stmt_close($mediaStmt);

// Get current user's rating
$userRating = 0;
if (isset($_SESSION['user'])) {
    $userId    = (int) $_SESSION['user']['user_id'];
    $rateCheck = mysqli_prepare($dbc,
        "SELECT rating_value FROM dbProj_ratings WHERE movie_id = ? AND user_id = ? LIMIT 1"
    );
    mysqli_stmt_bind_param($rateCheck, 'ii', $movieId, $userId);
    mysqli_stmt_execute($rateCheck);
    $rateRes = mysqli_stmt_get_result($rateCheck);
    if ($row = mysqli_fetch_assoc($rateRes)) {
        $userRating = (int) $row['rating_value'];
    }
    mysqli_stmt_close($rateCheck);
}

// Handle rating submission
$ratingMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_rating'])) {
    if (!isset($_SESSION['user'])) {
        $ratingMsg = 'error:You must be logged in to rate.';
    } else {
        $userId      = (int) $_SESSION['user']['user_id'];
        $ratingValue = (int) ($_POST['rating_value'] ?? 0);
        if ($ratingValue < 1 || $ratingValue > 5) {
            $ratingMsg = 'error:Please select a rating between 1 and 5.';
        } else {
            $stmt = mysqli_prepare($dbc, "
                INSERT INTO dbProj_ratings (movie_id, user_id, rating_value)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE rating_value = VALUES(rating_value)
            ");
            mysqli_stmt_bind_param($stmt, 'iii', $movieId, $userId, $ratingValue);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $userRating = $ratingValue;
            $ratingMsg  = 'ok:Rating saved!';
            $res2 = mysqli_query($dbc, "
                SELECT COALESCE(ROUND(AVG(rating_value),1),0) AS avg_rating,
                       COUNT(*) AS total_ratings
                FROM dbProj_ratings WHERE movie_id = $movieId
            ");
            $ratingStats            = mysqli_fetch_assoc($res2);
            $movie['avg_rating']    = $ratingStats['avg_rating'];
            $movie['total_ratings'] = $ratingStats['total_ratings'];
        }
    }
}

// Handle comment submission
$commentMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_comment'])) {
    if (!isset($_SESSION['user'])) {
        $commentMsg = 'error:You must be logged in to comment.';
    } else {
        $userId  = (int) $_SESSION['user']['user_id'];
        $comment = trim($_POST['comment_text'] ?? '');
        if (empty($comment)) {
            $commentMsg = 'error:Comment cannot be empty.';
        } elseif (strlen($comment) > 1000) {
            $commentMsg = 'error:Comment must be under 1000 characters.';
        } else {
            $stmt = mysqli_prepare($dbc,
                "INSERT INTO dbProj_comments (movie_id, user_id, comment_text) VALUES (?, ?, ?)"
            );
            mysqli_stmt_bind_param($stmt, 'iis', $movieId, $userId, $comment);
            if (mysqli_stmt_execute($stmt)) {
                $commentMsg = 'ok:Comment posted!';
            } else {
                $commentMsg = 'error:Failed to post comment.';
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// Fetch comments
$commentsStmt = mysqli_prepare($dbc, "
    SELECT c.comment_id, c.comment_text, c.created_at, u.username
    FROM dbProj_comments c
    JOIN dbProj_users u ON c.user_id = u.user_id
    WHERE c.movie_id = ?
    ORDER BY c.created_at DESC
");
mysqli_stmt_bind_param($commentsStmt, 'i', $movieId);
mysqli_stmt_execute($commentsStmt);
$commentsRes = mysqli_stmt_get_result($commentsStmt);
$comments    = [];
while ($c = mysqli_fetch_assoc($commentsRes)) {
    $comments[] = $c;
}
mysqli_stmt_close($commentsStmt);

// Prepare trailer embed
$embedUrl = '';
if (!empty($movie['trailer_url'])) {
    $t = $movie['trailer_url'];
    if (preg_match('/youtube\.com\/watch\?v=([a-zA-Z0-9_-]+)/', $t, $mm)) {
        $embedUrl = 'https://www.youtube.com/embed/' . $mm[1];
    } elseif (preg_match('/youtu\.be\/([a-zA-Z0-9_-]+)/', $t, $mm)) {
        $embedUrl = 'https://www.youtube.com/embed/' . $mm[1];
    } else {
        $embedUrl = $t;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($movie['title']) ?> &middot; Movie Review</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* ===== Cinematic backdrop ===== */
        .movie-backdrop {
            position: relative;
            min-height: 520px;
            display: flex;
            align-items: flex-end;
            overflow: hidden;
            border-bottom: 1px solid #2a2a2a;
        }
        .backdrop-img {
            position: absolute;
            inset: 0;
            background-size: cover;
            background-position: center 30%;
            filter: blur(2px) brightness(0.4);
            transform: scale(1.1);
        }
        .backdrop-fallback {
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at 20% 20%, rgba(229,9,20,0.25), transparent 50%),
                linear-gradient(135deg, #2a1a1a, #141414);
        }
        .backdrop-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, rgba(20,20,20,0.4) 0%, rgba(20,20,20,0.85) 70%, #141414 100%);
        }
        .backdrop-content {
            position: relative;
            z-index: 2;
            width: 100%;
            padding: 2.5rem 0;
        }
        .backdrop-layout {
            display: grid;
            grid-template-columns: 240px 1fr;
            gap: 2.5rem;
            align-items: end;
        }
        @media (max-width: 700px) {
            .backdrop-layout { grid-template-columns: 1fr; }
            .detail-poster { max-width: 200px; }
        }
        .detail-poster {
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 20px 50px rgba(0,0,0,0.6);
            border: 1px solid rgba(255,255,255,0.08);
            aspect-ratio: 2/3;
            background: linear-gradient(135deg, #2a2a2a, #1a1a1a);
        }
        .detail-poster img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .detail-poster .no-poster-lg {
            width: 100%; height: 100%;
            display: flex; align-items: center; justify-content: center;
            color: #666; font-size: 0.9rem;
        }
        .detail-title {
            font-size: 2.8rem;
            font-weight: 800;
            line-height: 1.05;
            margin-bottom: 0.85rem;
            text-shadow: 0 2px 20px rgba(0,0,0,0.5);
        }
        .detail-meta {
            display: flex; flex-wrap: wrap; gap: 0.55rem; align-items: center;
            margin-bottom: 1rem;
        }
        .meta-pill {
            background: rgba(255,255,255,0.08);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255,255,255,0.1);
            color: #e5e5e5;
            padding: 0.3rem 0.8rem;
            border-radius: 999px;
            font-size: 0.82rem;
        }
        .meta-pill.genre { background: rgba(229,9,20,0.25); border-color: rgba(229,9,20,0.4); color: #ff6b6b; font-weight: 600; }
        .rating-badge {
            display: inline-flex; align-items: center; gap: 0.4rem;
            font-size: 1.3rem; font-weight: 800; color: #ffb400;
        }
        .rating-badge small { color: #b3b3b3; font-weight: 400; font-size: 0.85rem; }

        /* ===== Body content ===== */
        .detail-body { padding: 2.5rem 0; }
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 2.5rem;
        }
        @media (max-width: 850px) { .content-grid { grid-template-columns: 1fr; } }
        .section-title {
            font-size: 1.15rem; font-weight: 700;
            margin-bottom: 1rem; padding-bottom: 0.5rem;
            border-bottom: 2px solid #e50914;
            display: inline-block;
        }
        .synopsis { color: #ddd; line-height: 1.8; font-size: 1.02rem; margin-bottom: 2.5rem; }
        .trailer-section { margin-bottom: 2.5rem; }
        .trailer-section iframe {
            width: 100%; aspect-ratio: 16/9; border: none;
            border-radius: 14px; box-shadow: 0 10px 40px rgba(0,0,0,0.5);
        }
        .media-player { width: 100%; border-radius: 12px; margin-bottom: 1rem; background: #000; box-shadow: 0 8px 30px rgba(0,0,0,0.4); }

        /* ===== Rating widget ===== */
        .side-card {
            background: linear-gradient(180deg, #1e1e1e, #181818);
            border: 1px solid #2c2c2c;
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.25);
        }
        .side-card h3 { font-size: 1.05rem; margin-bottom: 1rem; }
        .stars { display: flex; gap: 0.35rem; margin-bottom: 1rem; }
        .star {
            font-size: 2.2rem; cursor: pointer; color: #3a3a3a;
            transition: color 0.15s, transform 0.12s; user-select: none;
            line-height: 1;
        }
        .star:hover, .star.hovered, .star.selected { color: #ffb400; }
        .star:hover { transform: scale(1.2) rotate(-5deg); }
        .btn-rate, .btn-comment {
            background: #e50914; color: #fff; border: none;
            border-radius: 10px; padding: 0.65rem 1.4rem;
            font: inherit; font-weight: 700; cursor: pointer;
            transition: background-color 0.2s, transform 0.1s;
        }
        .btn-rate:hover, .btn-comment:hover { background: #c40811; }
        .btn-rate:active, .btn-comment:active { transform: scale(0.97); }
        .rating-msg, .comment-msg { font-size: 0.85rem; margin-top: 0.65rem; min-height: 1.2em; }
        .rating-msg.ok, .comment-msg.ok { color: #86efac; }
        .rating-msg.err, .comment-msg.err { color: #fca5a5; }
        .stat-row { display: flex; justify-content: space-between; padding: 0.6rem 0; border-bottom: 1px solid #2a2a2a; font-size: 0.9rem; }
        .stat-row:last-child { border-bottom: none; }
        .stat-row .label { color: #888; }
        .stat-row .value { color: #f5f5f5; font-weight: 600; }

        /* ===== Comments ===== */
        .comments-section { margin-top: 1rem; }
        .comment-form textarea {
            width: 100%; background: #0f0f0f; color: #f5f5f5;
            border: 1px solid #333; border-radius: 12px;
            padding: 0.85rem 1rem; font: inherit; font-size: 0.92rem;
            resize: vertical; min-height: 100px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .comment-form textarea:focus { outline: none; border-color: #e50914; box-shadow: 0 0 0 3px rgba(229,9,20,0.12); }
        .comment-form textarea.invalid { border-color: #e50914; }
        .comment-card {
            background: #1a1a1a; border: 1px solid #2a2a2a;
            border-radius: 12px; padding: 1.1rem 1.25rem; margin-bottom: 0.85rem;
            transition: border-color 0.2s;
        }
        .comment-card:hover { border-color: #3a3a3a; }
        .comment-header { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 0.5rem; }
        .comment-avatar {
            display: inline-flex; align-items: center; gap: 0.6rem;
        }
        .avatar-circle {
            width: 34px; height: 34px; border-radius: 50%;
            background: linear-gradient(135deg, #e50914, #7a0008);
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 0.9rem; color: #fff; text-transform: uppercase;
        }
        .comment-author { font-weight: 600; font-size: 0.92rem; color: #f5f5f5; }
        .comment-date { font-size: 0.75rem; color: #666; }
        .comment-text { color: #ccc; font-size: 0.9rem; line-height: 1.6; }
        .no-comments { color: #888; font-size: 0.92rem; padding: 1.5rem; text-align: center; background: #1a1a1a; border-radius: 12px; border: 1px dashed #333; }
        .login-prompt {
            background: #1a1a1a; border: 1px solid #2a2a2a;
            border-radius: 12px; padding: 1.1rem 1.4rem;
            color: #b3b3b3; font-size: 0.92rem; margin-bottom: 1.5rem;
        }
        .login-prompt a { color: #e50914; text-decoration: none; font-weight: 600; }
        .login-prompt a:hover { text-decoration: underline; }
        .back-link {
            display: inline-block; color: #b3b3b3; text-decoration: none;
            font-size: 0.88rem; margin: 1.25rem 0; transition: color 0.2s;
        }
        .back-link:hover { color: #fff; }
    </style>
</head>
<body>
    <header>
        <nav class="container">
            <h1 class="logo"><a href="index.php" style="color:inherit;text-decoration:none;">Movie Review</a></h1>
            <ul class="nav-links">
                <li><a href="index.php">Home</a></li>
                <li><a href="search.php">Search</a></li>
                <?php if (isset($_SESSION['user'])): ?>
                    <?php if ($_SESSION['user']['role'] === 'creator'): ?>
                        <li><a href="creator/index.php">My Movies</a></li>
                    <?php endif; ?>
                    <?php if ($_SESSION['user']['role'] === 'admin'): ?>
                        <li><a href="admin/index.php">Admin</a></li>
                    <?php endif; ?>
                    <li><a href="logout.php">Logout</a></li>
                <?php else: ?>
                    <li><a href="login.php">Login</a></li>
                    <li><a href="signup.php">Sign Up</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </header>

    <!-- Cinematic backdrop hero -->
    <section class="movie-backdrop">
        <?php if (!empty($movie['poster_image'])): ?>
            <div class="backdrop-img" style="background-image:url('<?= htmlspecialchars($movie['poster_image']) ?>');"></div>
        <?php else: ?>
            <div class="backdrop-fallback"></div>
        <?php endif; ?>
        <div class="backdrop-overlay"></div>

        <div class="backdrop-content">
            <div class="container">
                <div class="backdrop-layout">
                    <div class="detail-poster">
                        <?php if (!empty($movie['poster_image'])): ?>
                            <img src="<?= htmlspecialchars($movie['poster_image']) ?>"
                                 alt="<?= htmlspecialchars($movie['title']) ?>"
                                 onerror="this.parentNode.innerHTML='<div class=\'no-poster-lg\'>No Image</div>'">
                        <?php else: ?>
                            <div class="no-poster-lg">No Image</div>
                        <?php endif; ?>
                    </div>
                    <div class="detail-headline">
                        <h1 class="detail-title"><?= htmlspecialchars($movie['title']) ?></h1>
                        <div class="detail-meta">
                            <span class="meta-pill genre"><?= htmlspecialchars($movie['genre_name']) ?></span>
                            <?php if ($movie['release_year']): ?>
                                <span class="meta-pill"><?= (int)$movie['release_year'] ?></span>
                            <?php endif; ?>
                            <span class="meta-pill">by <?= htmlspecialchars($movie['creator_name']) ?></span>
                            <span class="meta-pill"><?= (int)$movie['view_count'] ?> views</span>
                        </div>
                        <div class="rating-badge">
                            &#9733; <?= number_format((float)$movie['avg_rating'], 1) ?>
                            <small>(<?= (int)$movie['total_ratings'] ?> ratings)</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="detail-body">
        <div class="container">
            <a href="index.php" class="back-link">&larr; Back to movies</a>

            <div class="content-grid">
                <!-- Left: synopsis + trailer + comments -->
                <div class="main-col">
                    <h2 class="section-title">Synopsis</h2>
                    <p class="synopsis"><?= nl2br(htmlspecialchars($movie['full_description'])) ?></p>

                    <?php if ($embedUrl): ?>
                        <div class="trailer-section">
                            <h2 class="section-title">Trailer</h2>
                            <iframe src="<?= htmlspecialchars($embedUrl) ?>" allowfullscreen
                                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"></iframe>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($mediaFiles as $media): ?>
                        <?php if ($media['media_type'] === 'video'): ?>
                            <video class="media-player" controls>
                                <source src="<?= htmlspecialchars($media['media_url']) ?>">
                            </video>
                        <?php elseif ($media['media_type'] === 'audio'): ?>
                            <audio class="media-player" controls>
                                <source src="<?= htmlspecialchars($media['media_url']) ?>">
                            </audio>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <!-- Comments -->
                    <div class="comments-section">
                        <h2 class="section-title">Comments (<?= count($comments) ?>)</h2>
                        <?php [$cType, $cText] = $commentMsg ? explode(':', $commentMsg, 2) : ['', '']; ?>

                        <?php if (isset($_SESSION['user'])): ?>
                            <div class="comment-form" style="margin:1rem 0 1.5rem;">
                                <form method="post" id="commentForm" novalidate>
                                    <textarea name="comment_text" id="commentText"
                                              placeholder="Share your thoughts about this movie..." maxlength="1000"></textarea>
                                    <div style="font-size:0.76rem;color:#666;margin-top:0.3rem;">Max 1000 characters</div>
                                    <?php if ($cText): ?>
                                        <p class="comment-msg <?= $cType ?>"><?= htmlspecialchars($cText) ?></p>
                                    <?php else: ?>
                                        <p class="comment-msg" id="commentMsg"></p>
                                    <?php endif; ?>
                                    <button type="submit" name="submit_comment" class="btn-comment">Post Comment</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="login-prompt" style="margin-top:1rem;">
                                <a href="login.php">Log in</a> or <a href="signup.php">sign up</a> to leave a comment.
                            </div>
                        <?php endif; ?>

                        <?php if (empty($comments)): ?>
                            <p class="no-comments">No comments yet. Be the first to share your thoughts!</p>
                        <?php else: ?>
                            <?php foreach ($comments as $comment): ?>
                                <div class="comment-card">
                                    <div class="comment-header">
                                        <span class="comment-avatar">
                                            <span class="avatar-circle"><?= htmlspecialchars(substr($comment['username'],0,1)) ?></span>
                                            <span class="comment-author"><?= htmlspecialchars($comment['username']) ?></span>
                                        </span>
                                        <span class="comment-date"><?= htmlspecialchars(substr($comment['created_at'],0,16)) ?></span>
                                    </div>
                                    <p class="comment-text"><?= nl2br(htmlspecialchars($comment['comment_text'])) ?></p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right: rating + stats sidebar -->
                <aside class="side-col">
                    <div class="side-card">
                        <h3>Rate this movie</h3>
                        <?php if (isset($_SESSION['user'])): ?>
                            <?php [$rType, $rText] = $ratingMsg ? explode(':', $ratingMsg, 2) : ['', '']; ?>
                            <form method="post" id="ratingForm">
                                <div class="stars" id="starContainer">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <span class="star <?= $i <= $userRating ? 'selected' : '' ?>" data-value="<?= $i ?>">&#9733;</span>
                                    <?php endfor; ?>
                                </div>
                                <input type="hidden" name="rating_value" id="ratingValue" value="<?= $userRating ?>">
                                <button type="submit" name="submit_rating" class="btn-rate">Submit Rating</button>
                                <?php if ($rText): ?>
                                    <p class="rating-msg <?= $rType ?>"><?= htmlspecialchars($rText) ?></p>
                                <?php else: ?>
                                    <p class="rating-msg" id="ratingMsg"><?= $userRating > 0 ? 'Your rating: ' . $userRating . ' / 5' : 'Click a star to rate' ?></p>
                                <?php endif; ?>
                            </form>
                        <?php else: ?>
                            <p style="color:#b3b3b3;font-size:0.9rem;"><a href="login.php" style="color:#e50914;">Log in</a> to rate this movie.</p>
                        <?php endif; ?>
                    </div>

                    <div class="side-card">
                        <h3>Details</h3>
                        <div class="stat-row"><span class="label">Genre</span><span class="value"><?= htmlspecialchars($movie['genre_name']) ?></span></div>
                        <?php if ($movie['release_year']): ?>
                            <div class="stat-row"><span class="label">Year</span><span class="value"><?= (int)$movie['release_year'] ?></span></div>
                        <?php endif; ?>
                        <div class="stat-row"><span class="label">Creator</span><span class="value"><?= htmlspecialchars($movie['creator_name']) ?></span></div>
                        <div class="stat-row"><span class="label">Views</span><span class="value"><?= (int)$movie['view_count'] ?></span></div>
                        <div class="stat-row"><span class="label">Avg Rating</span><span class="value">&#9733; <?= number_format((float)$movie['avg_rating'],1) ?></span></div>
                    </div>
                </aside>
            </div>
        </div>
    </div>

    <footer>
        <div class="container">
            <p>&copy; <?= date('Y') ?> Movie Review</p>
        </div>
    </footer>

    <script>
        const stars       = document.querySelectorAll('.star');
        const ratingInput = document.getElementById('ratingValue');
        const ratingMsg   = document.getElementById('ratingMsg');
        let currentRating = <?= $userRating ?>;

        stars.forEach(star => {
            star.addEventListener('mouseenter', function() {
                const val = parseInt(this.dataset.value);
                stars.forEach(s => s.classList.toggle('hovered', parseInt(s.dataset.value) <= val));
            });
            star.addEventListener('mouseleave', () => stars.forEach(s => s.classList.remove('hovered')));
            star.addEventListener('click', function() {
                currentRating = parseInt(this.dataset.value);
                if (ratingInput) ratingInput.value = currentRating;
                stars.forEach(s => s.classList.toggle('selected', parseInt(s.dataset.value) <= currentRating));
                if (ratingMsg) ratingMsg.textContent = 'Your rating: ' + currentRating + ' / 5';
            });
        });

        const commentForm = document.getElementById('commentForm');
        if (commentForm) {
            commentForm.addEventListener('submit', function(e) {
                const text = document.getElementById('commentText');
                const msg  = document.getElementById('commentMsg');
                if (msg) msg.textContent = '';
                text.classList.remove('invalid');
                if (!text.value.trim()) {
                    if (msg) { msg.textContent = 'Comment cannot be empty.'; msg.className = 'comment-msg err'; }
                    text.classList.add('invalid');
                    e.preventDefault();
                }
            });
        }
    </script>
</body>
</html>
<?php mysqli_close($dbc); ?>