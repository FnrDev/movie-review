<?php
session_start();
require_once 'config.php';

$dbc = get_db_connection();

// Get search parameters
$query      = trim($_GET['q'] ?? '');
$dateFrom   = trim($_GET['date_from'] ?? '');
$dateTo     = trim($_GET['date_to'] ?? '');
$creatorId  = (int) ($_GET['creator'] ?? 0);
$genreId    = (int) ($_GET['genre'] ?? 0);
$sortBy     = $_GET['sort'] ?? 'newest';
$page       = max(1, (int) ($_GET['page'] ?? 1));
$perPage    = 10;
$offset     = ($page - 1) * $perPage;

$allowedSorts = ['newest', 'oldest', 'rating', 'views', 'title'];
if (!in_array($sortBy, $allowedSorts)) $sortBy = 'newest';

// Genres for the navigation menu + genre filter dropdown
$navGenres = fetch_genres($dbc);
$genreName = '';
foreach ($navGenres as $gn) {
    if ((int)$gn['genre_id'] === $genreId) { $genreName = $gn['genre_name']; break; }
}

// Fetch creators for dropdown
$creatorRes  = mysqli_query($dbc, "
    SELECT DISTINCT u.user_id, u.username
    FROM dbProj_users u
    JOIN dbProj_movies m ON m.creator_id = u.user_id
    WHERE m.is_published = 1
    ORDER BY u.username
");
$creators = [];
while ($c = mysqli_fetch_assoc($creatorRes)) {
    $creators[] = $c;
}

// Build search query dynamically
$conditions = ['m.is_published = 1'];
$params     = [];
$types      = '';

if ($query !== '') {
    $conditions[] = "(MATCH(m.title, m.short_description) AGAINST(? IN BOOLEAN MODE) OR m.title LIKE ?)";
    $likeQuery    = '%' . $query . '%';
    $params[]     = $query . '*';
    $params[]     = $likeQuery;
    $types       .= 'ss';
}

if ($dateFrom !== '') {
    $conditions[] = "DATE(m.created_at) >= ?";
    $params[]     = $dateFrom;
    $types       .= 's';
}
if ($dateTo !== '') {
    $conditions[] = "DATE(m.created_at) <= ?";
    $params[]     = $dateTo;
    $types       .= 's';
}

if ($creatorId > 0) {
    $conditions[] = "m.creator_id = ?";
    $params[]     = $creatorId;
    $types       .= 'i';
}

if ($genreId > 0) {
    $conditions[] = "m.genre_id = ?";
    $params[]     = $genreId;
    $types       .= 'i';
}

$whereClause = implode(' AND ', $conditions);

$orderClause = match($sortBy) {
    'oldest'  => 'm.created_at ASC',
    'rating'  => 'avg_rating DESC, total_ratings DESC',
    'views'   => 'm.view_count DESC',
    'title'   => 'm.title ASC',
    default   => 'm.created_at DESC',
};

// Count total results
$countSql  = "
    SELECT COUNT(DISTINCT m.movie_id) AS total
    FROM dbProj_movies m
    JOIN dbProj_genres g ON m.genre_id = g.genre_id
    JOIN dbProj_users u ON m.creator_id = u.user_id
    LEFT JOIN dbProj_ratings r ON m.movie_id = r.movie_id
    WHERE $whereClause
";
$countStmt = mysqli_prepare($dbc, $countSql);
if (!empty($params)) {
    mysqli_stmt_bind_param($countStmt, $types, ...$params);
}
mysqli_stmt_execute($countStmt);
$countRes  = mysqli_stmt_get_result($countStmt);
$totalRows = (int) mysqli_fetch_assoc($countRes)['total'];
$totalPages = max(1, (int) ceil($totalRows / $perPage));
mysqli_stmt_close($countStmt);

// Fetch results
$sql = "
    SELECT m.movie_id, m.title, m.short_description, m.poster_image,
           m.release_year, m.view_count, m.created_at,
           g.genre_name,
           u.username AS creator_name, u.user_id AS creator_id,
           COALESCE(ROUND(AVG(r.rating_value), 1), 0) AS avg_rating,
           COUNT(DISTINCT r.rating_id) AS total_ratings
    FROM dbProj_movies m
    JOIN dbProj_genres g ON m.genre_id = g.genre_id
    JOIN dbProj_users u ON m.creator_id = u.user_id
    LEFT JOIN dbProj_ratings r ON m.movie_id = r.movie_id
    WHERE $whereClause
    GROUP BY m.movie_id
    ORDER BY $orderClause
    LIMIT ? OFFSET ?
";

$searchParams = $params;
$searchTypes  = $types . 'ii';
$searchParams[] = $perPage;
$searchParams[] = $offset;

$stmt = mysqli_prepare($dbc, $sql);
mysqli_stmt_bind_param($stmt, $searchTypes, ...$searchParams);
mysqli_stmt_execute($stmt);
$res     = mysqli_stmt_get_result($stmt);
$movies  = [];
while ($row = mysqli_fetch_assoc($res)) {
    $movies[] = $row;
}
mysqli_stmt_close($stmt);

function buildPageUrl(int $p): string {
    $params         = $_GET;
    $params['page'] = $p;
    return '?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search &middot; Movie Review</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Hero search banner */
        .search-hero {
            position: relative;
            background:
                radial-gradient(circle at 15% 30%, rgba(229,9,20,0.20), transparent 45%),
                linear-gradient(135deg, #1c1c1c 0%, #141414 60%);
            border-bottom: 1px solid #2a2a2a;
            padding: 3.5rem 0 3rem;
            overflow: hidden;
            margin-bottom: 2rem;
        }
        .search-hero::after {
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
        .search-hero .container { position: relative; z-index: 1; }
        .search-hero h2 {
            font-size: 2.2rem;
            font-weight: 800;
            margin-bottom: 1.5rem;
        }
        /* Search bar + button joined as ONE unit */
        .search-bar {
            display: flex;
            max-width: 680px;
            background: rgba(15,15,15,0.9);
            border: 1px solid #3a3a3a;
            border-radius: 14px;
            overflow: hidden;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .search-bar:focus-within {
            border-color: #e50914;
            box-shadow: 0 0 0 4px rgba(229,9,20,0.15);
        }
        .search-bar input {
            flex: 1;
            background: transparent;
            color: #f5f5f5;
            border: none;
            padding: 1.1rem 1.4rem;
            font: inherit;
            font-size: 1.05rem;
        }
        .search-bar input:focus { outline: none; }
        .search-bar input::placeholder { color: #777; }
        .btn-search {
            background: #e50914;
            color: #fff;
            border: none;
            padding: 0 2.2rem;
            margin: 0.4rem;
            border-radius: 10px;
            font: inherit;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            white-space: nowrap;
            transition: background-color 0.2s, transform 0.1s;
        }
        .btn-search:hover { background: #c40811; }
        .btn-search:active { transform: scale(0.97); }

        /* Polished filters */
        .filters-row {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: flex-end;
            margin-bottom: 1.5rem;
            background: linear-gradient(180deg, #1e1e1e, #181818);
            border: 1px solid #2e2e2e;
            border-radius: 16px;
            padding: 1.4rem 1.6rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
        }
        .filter-group label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #9a9a9a;
            font-weight: 700;
        }
        .filter-group select {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-color: #0f0f0f;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23e50914' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.85rem center;
            color: #f5f5f5;
            border: 1px solid #383838;
            border-radius: 10px;
            padding: 0.7rem 2.4rem 0.7rem 1rem;
            font: inherit;
            font-size: 0.92rem;
            min-width: 175px;
            height: 46px;
            cursor: pointer;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .filter-group select:hover { border-color: #555; }
        .filter-group select:focus {
            outline: none;
            border-color: #e50914;
            box-shadow: 0 0 0 3px rgba(229,9,20,0.15);
        }
        .filter-group input[type="date"] {
            background-color: #0f0f0f;
            color: #f5f5f5;
            border: 1px solid #383838;
            border-radius: 10px;
            padding: 0.7rem 1rem;
            font: inherit;
            font-size: 0.92rem;
            min-width: 175px;
            height: 46px;
            cursor: pointer;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .filter-group input[type="date"]:hover { border-color: #555; }
        .filter-group input[type="date"]:focus {
            outline: none;
            border-color: #e50914;
            box-shadow: 0 0 0 3px rgba(229,9,20,0.15);
        }
        .filter-group input[type="date"]::-webkit-calendar-picker-indicator {
            filter: invert(28%) sepia(96%) saturate(5000%) hue-rotate(353deg) brightness(90%);
            cursor: pointer;
        }
        .btn-filter {
            background: #e50914;
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 0 1.5rem;
            height: 46px;
            font: inherit;
            font-size: 0.92rem;
            font-weight: 700;
            cursor: pointer;
            transition: background-color 0.2s, transform 0.1s;
            align-self: flex-end;
        }
        .btn-filter:hover { background: #c40811; }
        .btn-filter:active { transform: scale(0.97); }
        .btn-clear {
            background: transparent;
            color: #b3b3b3;
            border: 1px solid #3a3a3a;
            border-radius: 10px;
            padding: 0 1.5rem;
            height: 46px;
            display: inline-flex;
            align-items: center;
            font: inherit;
            font-size: 0.92rem;
            cursor: pointer;
            text-decoration: none;
            align-self: flex-end;
            transition: color 0.2s, border-color 0.2s;
        }
        .btn-clear:hover { color: #f5f5f5; border-color: #666; }

        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            margin-bottom: 1.25rem;
        }
        .results-header h3 { font-size: 1.2rem; font-weight: 700; }
        .results-count { color: #888; font-size: 0.88rem; }
        .search-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }
        .no-results {
            text-align: center;
            padding: 3.5rem;
            color: #b3b3b3;
            background: #1b1b1b;
            border-radius: 14px;
            border: 1px dashed #333;
        }
        .no-results p { margin-bottom: 0.5rem; }

        /* Pagination */
        .pagination {
            display: flex;
            gap: 0.4rem;
            justify-content: center;
            flex-wrap: wrap;
            margin: 2rem 0;
        }
        .pagination a,
        .pagination span {
            display: inline-block;
            padding: 0.5rem 0.95rem;
            border-radius: 8px;
            font-size: 0.9rem;
            text-decoration: none;
            border: 1px solid #2f2f2f;
            color: #f5f5f5;
            transition: background-color 0.15s, border-color 0.15s;
        }
        .pagination a:hover { background: #242424; border-color: #555; }
        .pagination span.current {
            background: #e50914;
            border-color: #e50914;
            color: #fff;
            font-weight: 700;
        }
        .pagination span.dots { border-color: transparent; color: #666; }

        .active-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 0.45rem;
            margin-bottom: 1.25rem;
        }
        .filter-tag {
            background: rgba(229,9,20,0.12);
            border: 1px solid rgba(229,9,20,0.3);
            color: #f87171;
            border-radius: 999px;
            padding: 0.25rem 0.75rem;
            font-size: 0.78rem;
        }
    </style>
</head>
<body>
    <header>
        <nav class="container">
            <h1 class="logo"><a href="index.php" style="color:inherit;text-decoration:none;">Movie Review</a></h1>
            <ul class="nav-links">
                <li><a href="index.php">Home</a></li>
                <li><a href="search.php" style="color:#e50914;">Search</a></li>
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

    <!-- Search Hero -->
    <div class="search-hero">
        <div class="container">
            <h2>Search Movies</h2>
            <form method="get" id="searchForm">
                <div class="search-bar">
                    <input type="text" name="q" id="searchInput"
                           value="<?= htmlspecialchars($query) ?>"
                           placeholder="Search by title or description..."
                           autocomplete="off">
                    <?php if ($dateFrom): ?><input type="hidden" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>"><?php endif; ?>
                    <?php if ($dateTo):   ?><input type="hidden" name="date_to"   value="<?= htmlspecialchars($dateTo) ?>"><?php endif; ?>
                    <?php if ($creatorId): ?><input type="hidden" name="creator"  value="<?= $creatorId ?>"><?php endif; ?>
                    <?php if ($genreId):   ?><input type="hidden" name="genre"    value="<?= $genreId ?>"><?php endif; ?>
                    <?php if ($sortBy !== 'newest'): ?><input type="hidden" name="sort" value="<?= htmlspecialchars($sortBy) ?>"><?php endif; ?>
                    <button type="submit" class="btn-search">Search</button>
                </div>
            </form>
        </div>
    </div>

    <main class="container">
        <!-- Filters -->
        <form method="get">
            <?php if ($query): ?><input type="hidden" name="q" value="<?= htmlspecialchars($query) ?>"><?php endif; ?>
            <div class="filters-row">
                <div class="filter-group">
                    <label>Sort by</label>
                    <select name="sort">
                        <option value="newest"  <?= $sortBy === 'newest'  ? 'selected' : '' ?>>Newest first</option>
                        <option value="oldest"  <?= $sortBy === 'oldest'  ? 'selected' : '' ?>>Oldest first</option>
                        <option value="rating"  <?= $sortBy === 'rating'  ? 'selected' : '' ?>>Top rated</option>
                        <option value="views"   <?= $sortBy === 'views'   ? 'selected' : '' ?>>Most viewed</option>
                        <option value="title"   <?= $sortBy === 'title'   ? 'selected' : '' ?>>Title A&ndash;Z</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Genre</label>
                    <select name="genre">
                        <option value="">All genres</option>
                        <?php foreach ($navGenres as $gn): ?>
                            <option value="<?= (int)$gn['genre_id'] ?>"
                                <?= $genreId === (int)$gn['genre_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($gn['genre_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Creator</label>
                    <select name="creator">
                        <option value="">All creators</option>
                        <?php foreach ($creators as $c): ?>
                            <option value="<?= (int)$c['user_id'] ?>"
                                <?= $creatorId === (int)$c['user_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['username']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Date from</label>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
                </div>

                <div class="filter-group">
                    <label>Date to</label>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
                </div>

                <button type="submit" class="btn-filter">Apply Filters</button>
                <a href="search.php" class="btn-clear">Clear</a>
            </div>
        </form>

        <!-- Active filter tags -->
        <?php
        $hasFilters = $query || $dateFrom || $dateTo || $creatorId || $genreId || $sortBy !== 'newest';
        if ($hasFilters): ?>
            <div class="active-filters">
                <?php if ($query): ?>
                    <span class="filter-tag">Search: "<?= htmlspecialchars($query) ?>"</span>
                <?php endif; ?>
                <?php if ($genreId && $genreName !== ''): ?>
                    <span class="filter-tag">Genre: <?= htmlspecialchars($genreName) ?></span>
                <?php endif; ?>
                <?php if ($dateFrom): ?>
                    <span class="filter-tag">From: <?= htmlspecialchars($dateFrom) ?></span>
                <?php endif; ?>
                <?php if ($dateTo): ?>
                    <span class="filter-tag">To: <?= htmlspecialchars($dateTo) ?></span>
                <?php endif; ?>
                <?php if ($creatorId): ?>
                    <?php
                    $cName = '';
                    foreach ($creators as $c) {
                        if ((int)$c['user_id'] === $creatorId) { $cName = $c['username']; break; }
                    }
                    ?>
                    <span class="filter-tag">Creator: <?= htmlspecialchars($cName) ?></span>
                <?php endif; ?>
                <?php if ($sortBy !== 'newest'): ?>
                    <span class="filter-tag">Sort: <?= htmlspecialchars($sortBy) ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Results header -->
        <div class="results-header">
            <h3>
                <?php if ($query): ?>
                    Results for "<?= htmlspecialchars($query) ?>"
                <?php elseif ($genreId && $genreName !== ''): ?>
                    <?= htmlspecialchars($genreName) ?> Movies
                <?php else: ?>
                    All Movies
                <?php endif; ?>
            </h3>
            <span class="results-count"><?= $totalRows ?> movie<?= $totalRows !== 1 ? 's' : '' ?> found</span>
        </div>

        <!-- Results grid -->
        <?php if (empty($movies)): ?>
            <div class="no-results">
                <p>No movies found matching your search.</p>
                <p style="font-size:0.85rem;">Try different keywords or clear the filters.</p>
            </div>
        <?php else: ?>
            <div class="movie-grid search-grid">
                <?php foreach ($movies as $movie): ?>
                    <article class="movie-card" onclick="window.location='movie.php?id=<?= (int)$movie['movie_id'] ?>'">
                        <div class="poster">
                            <?php if (!empty($movie['poster_image'])): ?>
                                <img src="<?= htmlspecialchars($movie['poster_image']) ?>"
                                     alt="<?= htmlspecialchars($movie['title']) ?>"
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
                                &middot; by
                                <a href="?creator=<?= (int)$movie['creator_id'] ?>"
                                   style="color:#e50914;text-decoration:none;">
                                    <?= htmlspecialchars($movie['creator_name']) ?>
                                </a>
                            </p>
                            <p class="movie-desc"><?= htmlspecialchars($movie['short_description']) ?></p>
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
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="<?= buildPageUrl($page - 1) ?>">&larr; Prev</a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php if ($i === $page): ?>
                            <span class="current"><?= $i ?></span>
                        <?php elseif ($i === 1 || $i === $totalPages || abs($i - $page) <= 2): ?>
                            <a href="<?= buildPageUrl($i) ?>"><?= $i ?></a>
                        <?php elseif (abs($i - $page) === 3): ?>
                            <span class="dots">&hellip;</span>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="<?= buildPageUrl($page + 1) ?>">Next &rarr;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
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