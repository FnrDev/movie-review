<?php
session_start();
require_once 'config.php';

$dbc     = get_db_connection();
$movieId = (int) ($_GET['id'] ?? 0);

// Genres for the navigation menu
$navGenres = fetch_genres($dbc);

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
    header('Location: index.php?notice=unavailable');
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

// Rating and comment submissions are handled via AJAX (ajax_handler.php)

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
        /* Cinematic backdrop */
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

        /* Comments */
        .comments-section { margin-top: 1rem; }
        .comment-box-wrap {
            background: linear-gradient(180deg, #1d1d1d, #161616);
            border: 1px solid #2c2c2c;
            border-radius: 16px;
            padding: 1.25rem;
            margin: 1.25rem 0 2rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .comment-box-wrap:focus-within {
            border-color: #e50914;
            box-shadow: 0 0 0 3px rgba(229,9,20,0.12);
        }
        .comment-box-head {
            display: flex; align-items: center; gap: 0.65rem;
            margin-bottom: 0.85rem;
        }
        .comment-box-head .me-avatar {
            width: 38px; height: 38px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 0.95rem; color: #fff; text-transform: uppercase;
            flex-shrink: 0;
        }
        .comment-box-head .me-label { font-size: 0.88rem; color: #b3b3b3; }
        .comment-box-head .me-label strong { color: #f5f5f5; }
        .comment-form textarea {
            width: 100%; background: #0f0f0f; color: #f5f5f5;
            border: 1px solid #333; border-radius: 12px;
            padding: 0.85rem 1rem; font: inherit; font-size: 0.92rem;
            resize: vertical; min-height: 95px;
            transition: border-color 0.2s;
        }
        .comment-form textarea:focus { outline: none; border-color: #444; }
        .comment-form textarea.invalid { border-color: #e50914; }
        .comment-form-footer {
            display: flex; justify-content: space-between; align-items: center;
            margin-top: 0.75rem;
        }
        .char-counter { font-size: 0.76rem; color: #666; }
        .char-counter.warn { color: #fbbf24; }
        .char-counter.over { color: #fca5a5; }
        .comment-card {
            background: #1a1a1a; border: 1px solid #2a2a2a;
            border-radius: 14px; padding: 1.15rem 1.3rem; margin-bottom: 0.85rem;
            transition: transform 0.15s, border-color 0.2s, box-shadow 0.2s;
        }
        .comment-card:hover {
            transform: translateY(-2px);
            border-color: #3a3a3a;
            box-shadow: 0 6px 20px rgba(0,0,0,0.3);
        }
        .comment-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.65rem; }
        .comment-avatar { display: inline-flex; align-items: center; gap: 0.65rem; }
        .avatar-circle {
            width: 38px; height: 38px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 0.95rem; color: #fff; text-transform: uppercase;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
            flex-shrink: 0;
        }
        .comment-author { font-weight: 700; font-size: 0.92rem; color: #f5f5f5; }
        .comment-date { font-size: 0.75rem; color: #666; }
        .comment-text { color: #d0d0d0; font-size: 0.92rem; line-height: 1.65; padding-left: 0.15rem; }
        .no-comments {
            color: #888; font-size: 0.95rem; padding: 2.5rem 1.5rem; text-align: center;
            background: #161616; border-radius: 14px; border: 1px dashed #333;
        }
        .no-comments .big-icon { font-size: 2rem; display: block; margin-bottom: 0.5rem; opacity: 0.5; }
        .login-prompt {
            background: linear-gradient(135deg, rgba(229,9,20,0.08), #1a1a1a);
            border: 1px solid #2a2a2a;
            border-radius: 14px; padding: 1.25rem 1.5rem;
            color: #b3b3b3; font-size: 0.95rem; margin-bottom: 1.5rem;
            text-align: center;
        }
        .login-prompt a { color: #e50914; text-decoration: none; font-weight: 700; }
        .login-prompt a:hover { text-decoration: underline; }
        .back-link {
            display: inline-block; color: #b3b3b3; text-decoration: none;
            font-size: 0.88rem; margin: 1.25rem 0; transition: color 0.2s;
        }
        .back-link:hover { color: #fff; }
        /* Back link floating over the hero */
        .hero-back {
            position: absolute;
            top: 1.5rem;
            left: 0; right: 0;
            z-index: 3;
        }
        .hero-back a {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            color: #f5f5f5;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            background: rgba(0,0,0,0.4);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255,255,255,0.12);
            padding: 0.5rem 1rem;
            border-radius: 999px;
            transition: background-color 0.2s, border-color 0.2s;
        }
        .hero-back a:hover {
            background: rgba(0,0,0,0.6);
            border-color: rgba(255,255,255,0.25);
        }
    </style>
</head>
<body>
    <header>
        <nav class="container">
            <h1 class="logo"><a href="index.php" style="color:inherit;text-decoration:none;">Movie Review</a></h1>
            <ul class="nav-links">
                <li><a href="index.php">Home</a></li>
                <li><a href="search.php">Search</a></li>
                <?php if (!empty($navGenres)): ?>
                <li class="genre-dropdown">
                    <a href="search.php" class="genre-toggle">Genres</a>
                    <ul class="genre-menu">
                        <?php foreach ($navGenres as $gn): ?>
                            <li><a href="search.php?genre=<?= (int)$gn['genre_id'] ?>"><?= htmlspecialchars($gn['genre_name']) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </li>
                <?php endif; ?>
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

        <div class="hero-back">
            <div class="container">
                <a href="index.php">&larr; Back to movies</a>
            </div>
        </div>

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
                        <h2 class="section-title" id="commentsTitle">Comments (<?= count($comments) ?>)</h2>

                        <?php if (isset($_SESSION['user'])):
                            $meName  = $_SESSION['user']['username'];
                            $meColor = 'hsl(' . (crc32($meName) % 360) . ', 60%, 45%)';
                        ?>
                            <div class="comment-box-wrap">
                                <div class="comment-box-head">
                                    <span class="me-avatar" style="background:<?= $meColor ?>;"><?= htmlspecialchars(substr($meName,0,1)) ?></span>
                                    <span class="me-label">Commenting as <strong><?= htmlspecialchars($meName) ?></strong></span>
                                </div>
                                <form id="commentForm" class="comment-form" novalidate>
                                    <textarea name="comment_text" id="commentText"
                                              placeholder="Share your thoughts about this movie..." maxlength="1000"></textarea>
                                    <div class="comment-form-footer">
                                        <span class="char-counter" id="charCounter">0 / 1000</span>
                                        <button type="submit" class="btn-comment">Post Comment</button>
                                    </div>
                                    <p class="comment-msg" id="commentMsg"></p>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="login-prompt">
                                <a href="login.php">Log in</a> or <a href="signup.php">sign up</a> to join the conversation.
                            </div>
                        <?php endif; ?>

                        <?php if (empty($comments)): ?>
                            <div class="no-comments">
                                <span class="big-icon">&#128172;</span>
                                No comments yet. Be the first to share your thoughts!
                            </div>
                        <?php else: ?>
                            <?php foreach ($comments as $comment):
                                $cColor = 'hsl(' . (crc32($comment['username']) % 360) . ', 60%, 45%)';
                            ?>
                                <div class="comment-card">
                                    <div class="comment-header">
                                        <span class="comment-avatar">
                                            <span class="avatar-circle" style="background:<?= $cColor ?>;"><?= htmlspecialchars(substr($comment['username'],0,1)) ?></span>
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
                            <form id="ratingForm">
                                <div class="stars" id="starContainer">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <span class="star <?= $i <= $userRating ? 'selected' : '' ?>" data-value="<?= $i ?>">&#9733;</span>
                                    <?php endfor; ?>
                                </div>
                                <input type="hidden" id="ratingValue" value="<?= $userRating ?>">
                                <button type="submit" class="btn-rate">Submit Rating</button>
                                <p class="rating-msg" id="ratingMsg"><?= $userRating > 0 ? 'Your rating: ' . $userRating . ' / 5' : 'Click a star to rate' ?></p>
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

    <!-- jQuery Library -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    
    <script>
        const MOVIE_ID = <?= $movieId ?>;

        //Helpers
        function setMsg(el, text, isOk) {
            if (!el) return;
            el.textContent  = text;
            el.className    = 'rating-msg' === el.id || 'ratingMsg' === el.id
                ? 'rating-msg ' + (isOk ? 'ok' : 'err')
                : 'comment-msg ' + (isOk ? 'ok' : 'err');
        }

        function post(data) {
            data.append('movie_id', MOVIE_ID);
            return fetch('ajax_handler.php', { method: 'POST', body: data }).then(r => r.json());
        }

        //Rating
        const stars       = document.querySelectorAll('.star');
        const ratingInput = document.getElementById('ratingValue');
        const ratingMsg   = document.getElementById('ratingMsg');
        const ratingForm  = document.getElementById('ratingForm');
        let currentRating = <?= $userRating ?>;

        stars.forEach(star => {
            star.addEventListener('mouseenter', function () {
                const val = parseInt(this.dataset.value);
                stars.forEach(s => s.classList.toggle('hovered', parseInt(s.dataset.value) <= val));
            });
            star.addEventListener('mouseleave', () => stars.forEach(s => s.classList.remove('hovered')));
            star.addEventListener('click', function () {
                currentRating = parseInt(this.dataset.value);
                if (ratingInput) ratingInput.value = currentRating;
                stars.forEach(s => s.classList.toggle('selected', parseInt(s.dataset.value) <= currentRating));
                if (ratingMsg) ratingMsg.textContent = 'Your rating: ' + currentRating + ' / 5';
            });
        });

        if (ratingForm) {
            ratingForm.addEventListener('submit', function (e) {
                e.preventDefault();

                if (currentRating < 1) {
                    setMsg(ratingMsg, 'Please select a star first.', false);
                    return;
                }

                const btn = ratingForm.querySelector('.btn-rate');
                btn.disabled    = true;
                btn.textContent = 'Saving…';

                const fd = new FormData();
                fd.append('action', 'rate');
                fd.append('rating_value', currentRating);

                post(fd).then(res => {
                    setMsg(ratingMsg, res.message, res.ok);
                    if (res.ok) {
                        // Update the rating badge in the hero with jQuery fade animation
                        const badge = document.querySelector('.rating-badge');
                        if (badge) {
                            $(badge).fadeOut(200, function() {
                                badge.innerHTML = '&#9733; ' + res.avg_rating +
                                    ' <small>(' + res.total_ratings + ' ratings)</small>';
                                $(badge).fadeIn(300);
                            });
                        }
                        // Animate success message
                        $(ratingMsg).hide().fadeIn(400);
                    }
                }).catch(() => setMsg(ratingMsg, 'Network error. Please try again.', false))
                  .finally(() => { btn.disabled = false; btn.textContent = 'Submit Rating'; });
            });
        }

        //Comments
        const commentForm = document.getElementById('commentForm');
        if (commentForm) {
            const text    = document.getElementById('commentText');
            const counter = document.getElementById('charCounter');
            const commentMsg = document.getElementById('commentMsg');

            // Live character counter
            text.addEventListener('input', function () {
                const len = text.value.length;
                counter.textContent = len + ' / 1000';
                counter.classList.toggle('warn', len > 800 && len <= 1000);
                counter.classList.toggle('over', len > 1000);
            });

            commentForm.addEventListener('submit', function (e) {
                e.preventDefault();

                text.classList.remove('invalid');
                setMsg(commentMsg, '', true);

                if (!text.value.trim()) {
                    setMsg(commentMsg, 'Comment cannot be empty.', false);
                    text.classList.add('invalid');
                    return;
                }

                const btn = commentForm.querySelector('.btn-comment');
                btn.disabled    = true;
                btn.textContent = 'Posting…';

                const fd = new FormData();
                fd.append('action', 'comment');
                fd.append('comment_text', text.value.trim());

                post(fd).then(res => {
                    if (!res.ok) {
                        setMsg(commentMsg, res.message, false);
                        return;
                    }

                    setMsg(commentMsg, res.message, true);
                    text.value          = '';
                    counter.textContent = '0 / 1000';
                    counter.classList.remove('warn', 'over');

                    // Prepend new comment card without page reload
                    const color   = 'hsl(' + hashColor(res.username) + ', 60%, 45%)';
                    const initial = res.username.charAt(0).toUpperCase();
                    const card    = document.createElement('div');
                    card.className = 'comment-card';
                    card.innerHTML =
                        '<div class="comment-header">' +
                            '<span class="comment-avatar">' +
                                '<span class="avatar-circle" style="background:' + color + ';">' + initial + '</span>' +
                                '<span class="comment-author">' + escHtml(res.username) + '</span>' +
                            '</span>' +
                            '<span class="comment-date">' + escHtml(res.created_at) + '</span>' +
                        '</div>' +
                        '<p class="comment-text">' + escHtml(res.text).replace(/\n/g, '<br>') + '</p>';

                    // Remove "no comments" placeholder if present with fade
                    const placeholder = document.querySelector('.no-comments');
                    if (placeholder) {
                        $(placeholder).fadeOut(300, function() { placeholder.remove(); });
                    }

                    // Insert before the first existing card (newest first) with slide animation
                    const list  = document.querySelector('.comments-section');
                    const first = list.querySelector('.comment-card');
                    $(card).hide();
                    first ? list.insertBefore(card, first) : list.appendChild(card);
                    $(card).slideDown(400);

                    // Update the section counter with fade
                    const title = document.getElementById('commentsTitle');
                    if (title) {
                        const match = title.textContent.match(/\d+/);
                        const count = match ? parseInt(match[0]) + 1 : 1;
                        $(title).fadeOut(200, function() {
                            title.textContent = 'Comments (' + count + ')';
                            $(title).fadeIn(200);
                        });
                    }
                    
                    // Animate success message
                    $(commentMsg).hide().fadeIn(400);
                }).catch(() => setMsg(commentMsg, 'Network error. Please try again.', false))
                  .finally(() => { btn.disabled = false; btn.textContent = 'Post Comment'; });
            });
        }

        //Utilities
        function escHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }
        function hashColor(str) {
            let h = 0;
            for (let i = 0; i < str.length; i++) h = Math.imul(31, h) + str.charCodeAt(i) | 0;
            return ((h >>> 0) % 360);
        }
    </script>
</body>
</html>
<?php mysqli_close($dbc); ?>