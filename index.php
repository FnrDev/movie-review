<?php
session_start();
require_once 'config.php';
$dbc = get_db_connection();

// Genres for the navigation menu
$navGenres = fetch_genres($dbc);

//Pagination
$perPage     = 8;
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$offset      = ($currentPage - 1) * $perPage;

// Total published movies (for page count)
$countRes  = mysqli_query($dbc, "SELECT COUNT(*) FROM dbProj_movies WHERE is_published = 1");
$totalRows = (int)mysqli_fetch_row($countRes)[0];
$totalPages = (int)ceil($totalRows / $perPage);
$currentPage = min($currentPage, max(1, $totalPages)); 

$stmt = mysqli_prepare($dbc, "
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
    LIMIT ? OFFSET ?
");
mysqli_stmt_bind_param($stmt, 'ii', $perPage, $offset);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
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
        /* System notice (e.g. content removed by admin) */
        .system-notice {
            background: rgba(229,9,20,0.12);
            border: 1px solid rgba(229,9,20,0.35);
            color: #fca5a5;
            padding: 0.8rem 1.1rem;
            border-radius: 8px;
            margin-top: 1.5rem;
            margin-bottom: 1.25rem;
            font-size: 0.9rem;
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

        <?php if (($_GET['notice'] ?? '') === 'unavailable'): ?>
            <div class="system-notice">
                This content is no longer available. It may have been removed by an administrator.
            </div>
        <?php endif; ?>

        <?php if (!empty($navGenres)): ?>
            <nav class="genre-bar" aria-label="Browse movies by genre">
                <a href="search.php" class="genre-pill">All</a>
                <?php foreach ($navGenres as $gn): ?>
                    <a href="search.php?genre=<?= (int)$gn['genre_id'] ?>" class="genre-pill"><?= htmlspecialchars($gn['genre_name']) ?></a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>

        <section class="movies">
            <div class="section-head">
                <h3>Latest Movies</h3>
                <span class="count"><?= $totalRows ?> title<?= $totalRows !== 1 ? 's' : '' ?></span>
            </div>

            <?php if (mysqli_num_rows($result) === 0): ?>
                <p class="empty">No movies published yet.</p>
            <?php else: ?>
                <div class="movie-grid">
                    <?php while ($movie = mysqli_fetch_assoc($result)): ?>
                        <article class="movie-card" onclick="window.location='movie.php?id=<?= (int)$movie['movie_id'] ?>'">
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

            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($currentPage > 1): ?>
                        <a href="?page=<?= $currentPage - 1 ?>">&larr; Prev</a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php if ($i === $currentPage): ?>
                            <span class="current"><?= $i ?></span>
                        <?php elseif ($i === 1 || $i === $totalPages || abs($i - $currentPage) <= 2): ?>
                            <a href="?page=<?= $i ?>"><?= $i ?></a>
                        <?php elseif (abs($i - $currentPage) === 3): ?>
                            <span class="dots">&hellip;</span>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($currentPage < $totalPages): ?>
                        <a href="?page=<?= $currentPage + 1 ?>">Next &rarr;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <footer>
        <div class="container">
            <p>&copy; <?= date('Y') ?> Movie Review</p>
        </div>
    </footer>
    
    <!-- jQuery Library -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script>
        $(document).ready(function() {
            // Fade in movie cards on page load
            $('.movie-card').hide().each(function(index) {
                $(this).delay(index * 50).fadeIn(400);
            });
            
            // Smooth hover effect for movie cards
            $('.movie-card').hover(
                function() {
                    $(this).stop().animate({
                        transform: 'translateY(-5px)'
                    }, 200);
                },
                function() {
                    $(this).stop().animate({
                        transform: 'translateY(0)'
                    }, 200);
                }
            );
            
            // Smooth scroll for pagination
            $('.pagination a').on('click', function(e) {
                $('html, body').animate({
                    scrollTop: $('.movies').offset().top - 100
                }, 500);
            });
        });
    </script>
</body>
</html>
<?php
mysqli_free_result($result);
mysqli_stmt_close($stmt);
mysqli_close($dbc);
?>