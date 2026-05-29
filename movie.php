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

// Get current user's rating if logged in
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
            // Insert or update rating
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

            // Refresh avg rating
            $res2 = mysqli_query($dbc, "
                SELECT COALESCE(ROUND(AVG(rating_value),1),0) AS avg_rating,
                       COUNT(*) AS total_ratings
                FROM dbProj_ratings WHERE movie_id = $movieId
            ");
            $ratingStats          = mysqli_fetch_assoc($res2);
            $movie['avg_rating']  = $ratingStats['avg_rating'];
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($movie['title']) ?> &middot; Movie Review</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .movie-detail {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 2rem;
            margin: 2rem 0;
        }
        @media (max-width: 700px) {
            .movie-detail { grid-template-columns: 1fr; }
        }
        .detail-poster {
            position: relative;
        }
        .detail-poster img {
            width: 100%;
            border-radius: 10px;
            display: block;
        }
        .no-poster-lg {
            width: 100%;
            aspect-ratio: 2/3;
            background: #2a2a2a;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #666;
            font-size: 0.9rem;
        }
        .detail-info h1 {
            font-size: 2rem;
            margin-bottom: 0.4rem;
            line-height: 1.2;
        }
        .detail-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.6rem;
            margin-bottom: 1.25rem;
            align-items: center;
        }
        .meta-tag {
            background: #2a2a2a;
            color: #b3b3b3;
            padding: 0.2rem 0.65rem;
            border-radius: 999px;
            font-size: 0.78rem;
        }
        .meta-tag.red {
            background: rgba(229,9,20,0.15);
            color: #f87171;
        }
        .avg-rating {
            font-size: 1.1rem;
            color: #ffb400;
            font-weight: 700;
        }
        .avg-rating small { color: #888; font-weight: 400; font-size: 0.85rem; }
        .detail-desc {
            color: #ddd;
            line-height: 1.7;
            margin-bottom: 1.5rem;
        }
        .section-title {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #333;
        }
        /* Star Rating */
        .star-rating-widget {
            background: #1f1f1f;
            border: 1px solid #2a2a2a;
            border-radius: 10px;
            padding: 1.25rem;
            margin-bottom: 2rem;
        }
        .star-rating-widget h3 { font-size: 1rem; margin-bottom: 0.75rem; }
        .stars {
            display: flex;
            gap: 0.3rem;
            margin-bottom: 0.75rem;
        }
        .star {
            font-size: 2rem;
            cursor: pointer;
            color: #444;
            transition: color 0.15s, transform 0.1s;
            user-select: none;
        }
        .star:hover,
        .star.hovered,
        .star.selected { color: #ffb400; }
        .star:hover { transform: scale(1.15); }
        .btn-rate {
            background: #e50914;
            color: #fff;
            border: none;
            border-radius: 7px;
            padding: 0.5rem 1.2rem;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .btn-rate:hover { background: #c40811; }
        .rating-msg {
            font-size: 0.85rem;
            margin-top: 0.5rem;
            min-height: 1.2em;
        }
        .rating-msg.ok  { color: #86efac; }
        .rating-msg.err { color: #fca5a5; }
        /* Comments */
        .comments-section { margin-top: 2rem; }
        .comment-form {
            background: #1f1f1f;
            border: 1px solid #2a2a2a;
            border-radius: 10px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }
        .comment-form textarea {
            width: 100%;
            background: #141414;
            color: #f5f5f5;
            border: 1px solid #333;
            border-radius: 7px;
            padding: 0.65rem 0.85rem;
            font: inherit;
            font-size: 0.9rem;
            resize: vertical;
            min-height: 90px;
            transition: border-color 0.2s;
        }
        .comment-form textarea:focus { outline: none; border-color: #e50914; }
        .comment-form textarea.invalid { border-color: #e50914; }
        .btn-comment {
            margin-top: 0.65rem;
            background: #e50914;
            color: #fff;
            border: none;
            border-radius: 7px;
            padding: 0.5rem 1.1rem;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .btn-comment:hover { background: #c40811; }
        .comment-msg {
            font-size: 0.85rem;
            margin-top: 0.4rem;
            min-height: 1.2em;
        }
        .comment-msg.ok  { color: #86efac; }
        .comment-msg.err { color: #fca5a5; }
        .comment-card {
            background: #1f1f1f;
            border: 1px solid #2a2a2a;
            border-radius: 8px;
            padding: 1rem 1.1rem;
            margin-bottom: 0.75rem;
        }
        .comment-header {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            margin-bottom: 0.4rem;
        }
        .comment-author { font-weight: 600; font-size: 0.9rem; color: #e50914; }
        .comment-date   { font-size: 0.75rem; color: #666; }
        .comment-text   { color: #ddd; font-size: 0.88rem; line-height: 1.6; }
        .no-comments    { color: #888; font-size: 0.9rem; padding: 1rem 0; }
        .login-prompt {
            background: #1f1f1f;
            border: 1px solid #2a2a2a;
            border-radius: 8px;
            padding: 1rem 1.25rem;
            color: #b3b3b3;
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
        }
        .login-prompt a { color: #e50914; text-decoration: none; }
        .login-prompt a:hover { text-decoration: underline; }
        .trailer-section { margin-bottom: 1.5rem; }
        .trailer-section iframe {
            width: 100%;
            aspect-ratio: 16/9;
            border: none;
            border-radius: 8px;
        }
        .media-player {
            width: 100%;
            border-radius: 8px;
            margin-bottom: 1rem;
            background: #000;
        }
        .back-link {
            display: inline-block;
            color: #b3b3b3;
            text-decoration: none;
            font-size: 0.88rem;
            margin-bottom: 1rem;
            transition: color 0.2s;
        }
        .back-link:hover { color: #f5f5f5; }
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
                    <li><a href="#">Hello, <?= htmlspecialchars($_SESSION['user']['username']) ?></a></li>
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

    <main class="container">
        <a href="index.php" class="back-link">← Back to movies</a>

        <div class="movie-detail">
            <!-- Poster -->
            <div class="detail-poster">
                <?php if (!empty($movie['poster_image'])): ?>
                    <img src="<?= htmlspecialchars($movie['poster_image']) ?>"
                         alt="<?= htmlspecialchars($movie['title']) ?> poster"
                         onerror="this.parentNode.innerHTML='<div class=\'no-poster-lg\'>No Image</div>'">
                <?php else: ?>
                    <div class="no-poster-lg">No Image</div>
                <?php endif; ?>
            </div>

            <!-- Info -->
            <div class="detail-info">
                <h1><?= htmlspecialchars($movie['title']) ?></h1>

                <div class="detail-meta">
                    <span class="meta-tag red"><?= htmlspecialchars($movie['genre_name']) ?></span>
                    <?php if ($movie['release_year']): ?>
                        <span class="meta-tag"><?= (int)$movie['release_year'] ?></span>
                    <?php endif; ?>
                    <span class="meta-tag">by <?= htmlspecialchars($movie['creator_name']) ?></span>
                    <span class="meta-tag"><?= (int)$movie['view_count'] ?> views</span>
                </div>

                <div class="avg-rating">
                    ★ <?= number_format((float)$movie['avg_rating'], 1) ?>
                    <small>(<?= (int)$movie['total_ratings'] ?> ratings)</small>
                </div>

                <br>
                <p class="detail-desc"><?= nl2br(htmlspecialchars($movie['full_description'])) ?></p>

                <!-- Trailer -->
                <?php if (!empty($movie['trailer_url'])): ?>
                    <div class="trailer-section">
                        <p class="section-title">Trailer</p>
                        <?php
                        $trailerUrl = $movie['trailer_url'];
                        // Convert YouTube watch URL to embed URL
                        if (preg_match('/youtube\.com\/watch\?v=([a-zA-Z0-9_-]+)/', $trailerUrl, $m)) {
                            $trailerUrl = 'https://www.youtube.com/embed/' . $m[1];
                        } elseif (preg_match('/youtu\.be\/([a-zA-Z0-9_-]+)/', $trailerUrl, $m)) {
                            $trailerUrl = 'https://www.youtube.com/embed/' . $m[1];
                        }
                        ?>
                        <iframe src="<?= htmlspecialchars($trailerUrl) ?>"
                                allowfullscreen
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture">
                        </iframe>
                    </div>
                <?php endif; ?>

                <!-- Media files -->
                <?php foreach ($mediaFiles as $media): ?>
                    <?php if ($media['media_type'] === 'video'): ?>
                        <video class="media-player" controls>
                            <source src="<?= htmlspecialchars($media['media_url']) ?>">
                            Your browser does not support video.
                        </video>
                    <?php elseif ($media['media_type'] === 'audio'): ?>
                        <audio class="media-player" controls>
                            <source src="<?= htmlspecialchars($media['media_url']) ?>">
                            Your browser does not support audio.
                        </audio>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Rating Widget -->
        <div class="star-rating-widget">
            <h3>Rate this movie</h3>
            <?php if (isset($_SESSION['user'])): ?>
                <?php
                [$rType, $rText] = $ratingMsg ? explode(':', $ratingMsg, 2) : ['', ''];
                ?>
                <form method="post" id="ratingForm">
                    <div class="stars" id="starContainer">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <span class="star <?= $i <= $userRating ? 'selected' : '' ?>"
                                  data-value="<?= $i ?>">★</span>
                        <?php endfor; ?>
                    </div>
                    <input type="hidden" name="rating_value" id="ratingValue" value="<?= $userRating ?>">
                    <button type="submit" name="submit_rating" class="btn-rate">Submit Rating</button>
                    <?php if ($rText): ?>
                        <p class="rating-msg <?= $rType ?>"><?= htmlspecialchars($rText) ?></p>
                    <?php else: ?>
                        <p class="rating-msg" id="ratingMsg">
                            <?= $userRating > 0 ? 'Your current rating: ' . $userRating . ' / 5' : 'Click a star to rate' ?>
                        </p>
                    <?php endif; ?>
                </form>
            <?php else: ?>
                <p style="color:#b3b3b3;font-size:0.9rem;">
                    <a href="login.php" style="color:#e50914;">Log in</a> to rate this movie.
                </p>
            <?php endif; ?>
        </div>

        <!-- Comments -->
        <div class="comments-section">
            <p class="section-title">Comments (<?= count($comments) ?>)</p>

            <?php
            [$cType, $cText] = $commentMsg ? explode(':', $commentMsg, 2) : ['', ''];
            ?>

            <?php if (isset($_SESSION['user'])): ?>
                <div class="comment-form">
                    <form method="post" id="commentForm" novalidate>
                        <textarea name="comment_text" id="commentText"
                                  placeholder="Share your thoughts about this movie..."
                                  maxlength="1000"></textarea>
                        <span style="font-size:0.76rem;color:#666;">Max 1000 characters</span>
                        <?php if ($cText): ?>
                            <p class="comment-msg <?= $cType ?>"><?= htmlspecialchars($cText) ?></p>
                        <?php else: ?>
                            <p class="comment-msg" id="commentMsg"></p>
                        <?php endif; ?>
                        <button type="submit" name="submit_comment" class="btn-comment">Post Comment</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="login-prompt">
                    <a href="login.php">Log in</a> or <a href="signup.php">sign up</a> to leave a comment.
                </div>
            <?php endif; ?>

            <?php if (empty($comments)): ?>
                <p class="no-comments">No comments yet. Be the first!</p>
            <?php else: ?>
                <?php foreach ($comments as $comment): ?>
                    <div class="comment-card">
                        <div class="comment-header">
                            <span class="comment-author"><?= htmlspecialchars($comment['username']) ?></span>
                            <span class="comment-date"><?= htmlspecialchars(substr($comment['created_at'], 0, 16)) ?></span>
                        </div>
                        <p class="comment-text"><?= nl2br(htmlspecialchars($comment['comment_text'])) ?></p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>

    <footer>
        <div class="container">
            <p>&copy; <?= date('Y') ?> Movie Review</p>
        </div>
    </footer>

    <script>
        // Star rating interaction
        const stars        = document.querySelectorAll('.star');
        const ratingInput  = document.getElementById('ratingValue');
        const ratingMsg    = document.getElementById('ratingMsg');
        let currentRating  = <?= $userRating ?>;

        stars.forEach(star => {
            star.addEventListener('mouseenter', function() {
                const val = parseInt(this.dataset.value);
                stars.forEach(s => {
                    s.classList.toggle('hovered', parseInt(s.dataset.value) <= val);
                });
            });

            star.addEventListener('mouseleave', function() {
                stars.forEach(s => s.classList.remove('hovered'));
            });

            star.addEventListener('click', function() {
                currentRating = parseInt(this.dataset.value);
                ratingInput.value = currentRating;
                stars.forEach(s => {
                    s.classList.toggle('selected', parseInt(s.dataset.value) <= currentRating);
                });
                if (ratingMsg) ratingMsg.textContent = 'Rating selected: ' + currentRating + ' / 5';
            });
        });

        // Comment JS validation
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