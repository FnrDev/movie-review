<?php
session_start();
require_once 'config.php';

$dbc = get_db_connection();

// Get search parameters
$query      = trim($_GET['q'] ?? '');
$dateFrom   = trim($_GET['date_from'] ?? '');
$dateTo     = trim($_GET['date_to'] ?? '');
$creatorId  = (int) ($_GET['creator'] ?? 0);
$sortBy     = $_GET['sort'] ?? 'newest';
$page       = max(1, (int) ($_GET['page'] ?? 1));
$perPage    = 10;
$offset     = ($page - 1) * $perPage;

$allowedSorts = ['newest', 'oldest', 'rating', 'views', 'title'];
if (!in_array($sortBy, $allowedSorts)) $sortBy = 'newest';

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
                radial-gradient(circle at 15% 30%, rgba(229,9,20,0.18), transparent 45%),
                linear-gradient(135deg, #1c1c1c 0%, #141414 60%);
            border-bottom: 1px solid #2a2a2a;
            padding: 3rem 0 2.75rem;
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
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 1.25rem;
        }
        .search-bar {
            display: flex;
            gap: 0.6rem;
            max-width: 640px;
        }
        .search-bar input {
            flex: 1;
            background: rgba(20,20,20,0.85);
            color: #f5f5f5;
            border: 1px solid #444;
            border-radius: 10px;
            padding: 0.85rem 1.1rem;
            font: inherit;
            font-size: 1rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .search-bar input:focus {
            outline: none;
            border-color: #e50914;
            box-shadow: 0 0 0 3px rgba(229,9,20,0.15);
        }
        .btn-search {
            background: #e50914;
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 0.85rem 1.8rem;
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
            background: #1b1b1b;
            border: 1px solid #2a2a2a;
            border-radius: 14px;
            padding: 1.25rem 1.5rem;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }
        .filter-group label {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #888;
            font-weight: 600;
        }
        .filter-group select,
        .filter-group input[type="date"] {
            background: #141414;
            color: #f5f5f5;
            border: 1px solid #333;
            border-radius: 9px;
            padding: 0.6rem 0.85rem;
            font: inherit;
            font-size: 0.9rem;
            min-width: 165px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .filter-group select:focus,
        .filter-group input[type="date"]:focus {
            outline: none;
            border-color: #e50914;
            box-shadow: 0 0 0 3px rgba(229,9,20,0.12);
        }
        .btn-filter {
            background: #e50914;
            color: #fff;
            border: none;
            border-radius: 9px;
            padding: 0.6rem 1.3rem;
            font: inherit;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
            align-self: flex-end;
        }
        .btn-filter:hover { background: #c40811; }
        .btn-clear {
            background: transparent;
            color: #b3b3b3;
            border: 1px solid #3a3a3a;
            border-radius: 9px;
            padding: 0.6rem 1.3rem;
            font: inherit;
            font-size: 0.9rem;
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
        $hasFilters = $query || $dateFrom || $dateTo || $creatorId || $sortBy !== 'newest';
        if ($hasFilters): ?>
            <div class="active-filters">
                <?php if ($query): ?>
                    <span class="filter-tag">Search: "<?= htmlspecialchars($query) ?>"</span>
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
                    <article class="movie-card">
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