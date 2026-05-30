<?php
session_start();
require_once 'config.php';
$dbc = get_db_connection();

$sql = "
    SELECT
        m.movie_id,
        m.title,
        m.short_description,
        m.poster_image,
        m.release_year,
        m.view_count,
        g.genre_name,
        u.username AS creator_name,
        COALESCE(ROUND(AVG(r.rating_value), 1), 0) AS avg_rating,
        COUNT(DISTINCT r.rating_id) AS total_ratings
    FROM dbProj_movies m
    JOIN dbProj_genres g ON m.genre_id = g.genre_id
    JOIN dbProj_users  u ON m.creator_id = u.user_id
    LEFT JOIN dbProj_ratings r ON m.movie_id = r.movie_id
    WHERE m.is_published = 1
    GROUP BY m.movie_id
    ORDER BY m.created_at DESC
";
$result = mysqli_query($dbc, $sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Movie Review</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Hero banner */
        .hero-banner {
            position: relative;
            background:
                radial-gradient(circle at 15% 30%, rgba(229,9,20,0.18), transparent 45%),
                linear-gradient(135deg, #1c1c1c 0%, #141414 60%);
            border-bottom: 1px solid #2a2a2a;
            padding: 3.5rem 0 3rem;
            overflow: hidden;
        }
        .hero-banner::after {
            content: "";
            position: absolute;
            right: -60px;
            top: -60px;
            width: 320px;
            height: 320px;
            background: radial-gradient(circle, rgba(229,9,20,0.12), transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }
        .hero-banner .container { position: relative; z-index: 1; }
        .hero-greeting {
            display: inline-block;
            font-size: 0.85rem;
            font-weight: 600;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: #e50914;
            margin-bottom: 0.75rem;
        }
        .hero-banner h1 {
            font-size: 2.6rem;
            font-weight: 800;
            line-height: 1.1;
            margin-bottom: 0.75rem;
            max-width: 620px;
        }
        .hero-banner p.tagline {
            color: #b3b3b3;
            font-size: 1.1rem;
            max-width: 520px;
        }
        @media (max-width: 600px) {
            .hero-banner h1 { font-size: 1.9rem; }
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

    <!-- Hero -->
    <section class="hero-banner">
        <div class="container">
            <?php if (isset($_SESSION['user'])): ?>
                <span class="hero-greeting">Welcome back, <?= htmlspecialchars($_SESSION['user']['username']) ?></span>
            <?php endif; ?>
            <h1>Discover &amp; review<br>your favorite movies</h1>
            <p class="tagline">Browse the latest titles, rate what you've watched, and share your thoughts with the community.</p>
        </div>
    </section>

    <main class="container">
        <section class="movies">
            <div class="section-head">
                <h3>Latest Movies</h3>
                <span class="count"><?= mysqli_num_rows($result) ?> titles</span>
            </div>

            <?php if (mysqli_num_rows($result) === 0): ?>
                <p class="empty">No movies published yet.</p>
            <?php else: ?>
                <div class="movie-grid">
                    <?php while ($movie = mysqli_fetch_assoc($result)): ?>
                        <article class="movie-card">
                            <div class="poster">
                                <?php if (!empty($movie['poster_image'])): ?>
                                    <img src="<?= htmlspecialchars($movie['poster_image']) ?>"
                                         alt="<?= htmlspecialchars($movie['title']) ?> poster"
                                         onerror="this.parentNode.classList.add('no-image'); this.remove();">
                                <?php else: ?>
                                    <span class="poster-fallback">No Image</span>
                                <?php endif; ?>
                                <span class="genre-tag"><?= htmlspecialchars($movie['genre_name']) ?></span>
                            </div>
                            <div class="movie-body">
                                <h4 class="movie-title"><?= htmlspecialchars($movie['title']) ?></h4>
                                <p class="movie-meta">
                                    <?= htmlspecialchars($movie['release_year'] ?? '&mdash;') ?>
                                    &middot; by <?= htmlspecialchars($movie['creator_name']) ?>
                                </p>
                                <p class="movie-desc">
                                    <?= htmlspecialchars($movie['short_description']) ?>
                                </p>
                                <div class="movie-stats">
                                    <span class="rating">
                                        &#9733; <?= number_format((float)$movie['avg_rating'], 1) ?>
                                        <small>(<?= (int)$movie['total_ratings'] ?>)</small>
                                    </span>
                                    <span class="views"><?= (int)$movie['view_count'] ?> views</span>
                                </div>
                                <a href="movie.php?id=<?= (int)$movie['movie_id'] ?>" class="view-more">View More</a>
                            </div>
                        </article>
                    <?php endwhile; ?>
                </div>
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
<?php
mysqli_free_result($result);
mysqli_close($dbc);
?>