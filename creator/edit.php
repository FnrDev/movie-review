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
$movieId   = (int) ($_GET['id'] ?? 0);
$errors    = [];

if ($movieId === 0) {
    header('Location: index.php');
    exit;
}

// Fetch movie — must belong to this creator
$stmt = mysqli_prepare($dbc, "
    SELECT m.*, g.genre_id
    FROM dbProj_movies m
    JOIN dbProj_genres g ON m.genre_id = g.genre_id
    WHERE m.movie_id = ? AND m.creator_id = ?
    LIMIT 1
");
mysqli_stmt_bind_param($stmt, 'ii', $movieId, $creatorId);
mysqli_stmt_execute($stmt);
$res   = mysqli_stmt_get_result($stmt);
$movie = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$movie) {
    header('Location: index.php');
    exit;
}

// Fetch genres
$genreRes = mysqli_query($dbc, "SELECT genre_id, genre_name FROM dbProj_genres ORDER BY genre_name");
$genres   = [];
while ($g = mysqli_fetch_assoc($genreRes)) {
    $genres[] = $g;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title            = trim($_POST['title'] ?? '');
    $shortDescription = trim($_POST['short_description'] ?? '');
    $fullDescription  = trim($_POST['full_description'] ?? '');
    $genreId          = (int) ($_POST['genre_id'] ?? 0);
    $releaseYear      = trim($_POST['release_year'] ?? '');
    $trailerUrl       = trim($_POST['trailer_url'] ?? '');
    $publish          = isset($_POST['publish']) ? 1 : 0;

    // Server-side validation
    if (empty($title))            $errors[] = 'Title is required.';
    if (strlen($title) > 200)     $errors[] = 'Title must be under 200 characters.';
    if (empty($shortDescription)) $errors[] = 'Short description is required.';
    if (empty($fullDescription))  $errors[] = 'Full description is required.';
    if ($genreId === 0)           $errors[] = 'Please select a genre.';
    if ($releaseYear !== '' && (!is_numeric($releaseYear) || $releaseYear < 1888 || $releaseYear > date('Y') + 2)) {
        $errors[] = 'Enter a valid release year.';
    }
    if ($trailerUrl !== '' && !filter_var($trailerUrl, FILTER_VALIDATE_URL)) {
        $errors[] = 'Trailer URL must be a valid URL.';
    }

    // Handle new poster upload
    $posterImage = $movie['poster_image']; // keep existing by default
    if (!empty($_FILES['poster_image']['name'])) {
        $allowed  = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $fileType = mime_content_type($_FILES['poster_image']['tmp_name']);
        $fileSize = $_FILES['poster_image']['size'];

        if (!in_array($fileType, $allowed)) {
            $errors[] = 'Poster must be a JPG, PNG, WebP, or GIF image.';
        } elseif ($fileSize > 5 * 1024 * 1024) {
            $errors[] = 'Poster image must be under 5MB.';
        } else {
            $uploadDir = '../uploads/posters/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $ext         = pathinfo($_FILES['poster_image']['name'], PATHINFO_EXTENSION);
            $filename    = 'poster_' . uniqid() . '.' . $ext;
            $destination = $uploadDir . $filename;
            if (move_uploaded_file($_FILES['poster_image']['tmp_name'], $destination)) {
                // Delete old poster if exists
                if ($movie['poster_image'] && file_exists('../' . $movie['poster_image'])) {
                    unlink('../' . $movie['poster_image']);
                }
                $posterImage = 'uploads/posters/' . $filename;
            } else {
                $errors[] = 'Failed to upload poster image.';
            }
        }
    }

    // Handle new media file upload
    if (!empty($_FILES['media_file']['name'])) {
        $allowedMedia = ['video/mp4', 'video/webm', 'audio/mpeg', 'audio/mp4', 'audio/ogg'];
        $mediaType    = mime_content_type($_FILES['media_file']['tmp_name']);
        $mediaSize    = $_FILES['media_file']['size'];

        if (!in_array($mediaType, $allowedMedia)) {
            $errors[] = 'Media file must be MP4, WebM, MP3, or OGG.';
        } elseif ($mediaSize > 100 * 1024 * 1024) {
            $errors[] = 'Media file must be under 100MB.';
        } else {
            $uploadDir = '../uploads/media/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $ext         = pathinfo($_FILES['media_file']['name'], PATHINFO_EXTENSION);
            $filename    = 'media_' . uniqid() . '.' . $ext;
            $destination = $uploadDir . $filename;
            if (move_uploaded_file($_FILES['media_file']['tmp_name'], $destination)) {
                $mType     = strpos($mediaType, 'video') !== false ? 'video' : 'audio';
                $mediaPath = 'uploads/media/' . $filename;

                // Update or insert media record
                $stmtCheck = mysqli_prepare($dbc,
                    "SELECT media_id FROM dbProj_movie_media WHERE movie_id = ? LIMIT 1"
                );
                mysqli_stmt_bind_param($stmtCheck, 'i', $movieId);
                mysqli_stmt_execute($stmtCheck);
                mysqli_stmt_store_result($stmtCheck);

                if (mysqli_stmt_num_rows($stmtCheck) > 0) {
                    mysqli_stmt_close($stmtCheck);
                    $stmtMedia = mysqli_prepare($dbc,
                        "UPDATE dbProj_movie_media SET media_type=?, media_url=? WHERE movie_id=?"
                    );
                    mysqli_stmt_bind_param($stmtMedia, 'ssi', $mType, $mediaPath, $movieId);
                } else {
                    mysqli_stmt_close($stmtCheck);
                    $stmtMedia = mysqli_prepare($dbc,
                        "INSERT INTO dbProj_movie_media (movie_id, media_type, media_url) VALUES (?,?,?)"
                    );
                    mysqli_stmt_bind_param($stmtMedia, 'iss', $movieId, $mType, $mediaPath);
                }
                mysqli_stmt_execute($stmtMedia);
                mysqli_stmt_close($stmtMedia);
            } else {
                $errors[] = 'Failed to upload media file.';
            }
        }
    }

    if (empty($errors)) {
        $releaseYearVal = $releaseYear !== '' ? (int)$releaseYear : null;

        $stmt = mysqli_prepare($dbc, "
            UPDATE dbProj_movies
            SET genre_id=?, title=?, short_description=?, full_description=?,
                poster_image=?, trailer_url=?, release_year=?, is_published=?
            WHERE movie_id=? AND creator_id=?
        ");
        mysqli_stmt_bind_param($stmt, 'isssssiiii',
            $genreId, $title, $shortDescription, $fullDescription,
            $posterImage, $trailerUrl, $releaseYearVal, $publish,
            $movieId, $creatorId
        );

        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            mysqli_close($dbc);
            header('Location: index.php?msg=updated');
            exit;
        } else {
            $errors[] = 'Database error. Please try again.';
            mysqli_stmt_close($stmt);
        }
    }

    // Refresh movie data for form repopulation
    $movie['title']             = $_POST['title'] ?? $movie['title'];
    $movie['short_description'] = $_POST['short_description'] ?? $movie['short_description'];
    $movie['full_description']  = $_POST['full_description'] ?? $movie['full_description'];
    $movie['genre_id']          = $_POST['genre_id'] ?? $movie['genre_id'];
    $movie['release_year']      = $_POST['release_year'] ?? $movie['release_year'];
    $movie['trailer_url']       = $_POST['trailer_url'] ?? $movie['trailer_url'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Movie &middot; Movie Review</title>
    <link rel="stylesheet" href="../style.css">
    <style>
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #333;
        }
        .page-header h2 { font-size: 1.5rem; }
        .form-card {
            background: #1f1f1f;
            border: 1px solid #2a2a2a;
            border-radius: 12px;
            padding: 2rem;
            max-width: 780px;
        }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
            margin-bottom: 1rem;
        }
        .form-group.full { grid-column: 1 / -1; }
        .form-group label { font-size: 0.85rem; color: #b3b3b3; }
        .form-group input,
        .form-group select,
        .form-group textarea {
            background: #141414;
            color: #f5f5f5;
            border: 1px solid #333;
            border-radius: 7px;
            padding: 0.65rem 0.85rem;
            font: inherit;
            font-size: 0.95rem;
            transition: border-color 0.2s;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus { outline: none; border-color: #e50914; }
        .form-group input.invalid,
        .form-group select.invalid,
        .form-group textarea.invalid { border-color: #e50914; }
        .form-group textarea { resize: vertical; min-height: 100px; }
        .field-hint  { font-size: 0.76rem; color: #666; }
        .field-error { font-size: 0.78rem; color: #fca5a5; min-height: 1em; }
        .section-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            color: #888;
            margin: 1.25rem 0 0.75rem;
            padding-bottom: 0.4rem;
            border-bottom: 1px solid #2a2a2a;
        }
        .alert-error {
            background: rgba(239,68,68,0.12);
            border: 1px solid rgba(239,68,68,0.35);
            color: #fca5a5;
            border-radius: 7px;
            padding: 0.75rem 1rem;
            font-size: 0.88rem;
            margin-bottom: 1.25rem;
        }
        .alert-error ul { margin: 0.4rem 0 0 1.2rem; }
        .form-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1.5rem;
            padding-top: 1.25rem;
            border-top: 1px solid #2a2a2a;
            flex-wrap: wrap;
        }
        .btn-red {
            background-color: #e50914;
            color: #fff;
            border: none;
            border-radius: 7px;
            padding: 0.65rem 1.4rem;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .btn-red:hover { background-color: #c40811; }
        .btn-outline {
            background: transparent;
            color: #f5f5f5;
            border: 1px solid #444;
            border-radius: 7px;
            padding: 0.65rem 1.2rem;
            font: inherit;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: border-color 0.2s;
        }
        .btn-outline:hover { border-color: #888; }
        .publish-toggle {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            padding: 0.75rem 1rem;
            background: #141414;
            border: 1px solid #333;
            border-radius: 7px;
            cursor: pointer;
            user-select: none;
        }
        .publish-toggle input { width: 16px; height: 16px; accent-color: #e50914; cursor: pointer; }
        .current-poster {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.75rem;
            background: #141414;
            border: 1px solid #2a2a2a;
            border-radius: 7px;
            margin-bottom: 0.5rem;
        }
        .current-poster img {
            width: 60px;
            height: 80px;
            object-fit: cover;
            border-radius: 4px;
        }
        .current-poster span { font-size: 0.82rem; color: #888; }
        .upload-preview {
            margin-top: 0.5rem;
            max-width: 120px;
            max-height: 160px;
            border-radius: 6px;
            display: none;
        }
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
        <div class="page-header">
            <h2>Edit: <?= htmlspecialchars($movie['title']) ?></h2>
            <a href="index.php" class="btn-outline">← Back</a>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert-error">
                <strong>Please fix the following:</strong>
                <ul>
                    <?php foreach ($errors as $e): ?>
                        <li><?= htmlspecialchars($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="form-card">
            <form id="editForm" method="post" enctype="multipart/form-data" novalidate>

                <p class="section-label">Basic Information</p>
                <div class="form-grid">
                    <div class="form-group full">
                        <label for="title">Movie Title *</label>
                        <input type="text" id="title" name="title"
                               value="<?= htmlspecialchars($movie['title']) ?>"
                               maxlength="200">
                        <span class="field-error" id="titleErr"></span>
                    </div>

                    <div class="form-group">
                        <label for="genre_id">Genre *</label>
                        <select id="genre_id" name="genre_id">
                            <option value="">— Select genre —</option>
                            <?php foreach ($genres as $g): ?>
                                <option value="<?= (int)$g['genre_id'] ?>"
                                    <?= $movie['genre_id'] == $g['genre_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($g['genre_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="field-error" id="genreErr"></span>
                    </div>

                    <div class="form-group">
                        <label for="release_year">Release Year</label>
                        <input type="number" id="release_year" name="release_year"
                               value="<?= htmlspecialchars($movie['release_year'] ?? '') ?>"
                               min="1888" max="<?= date('Y') + 2 ?>">
                        <span class="field-error" id="yearErr"></span>
                    </div>

                    <div class="form-group full">
                        <label for="short_description">Short Description *</label>
                        <textarea id="short_description" name="short_description"
                                  maxlength="300" style="min-height:75px;"><?= htmlspecialchars($movie['short_description']) ?></textarea>
                        <span class="field-error" id="shortDescErr"></span>
                    </div>

                    <div class="form-group full">
                        <label for="full_description">Full Description *</label>
                        <textarea id="full_description" name="full_description"
                                  style="min-height:150px;"><?= htmlspecialchars($movie['full_description']) ?></textarea>
                        <span class="field-error" id="fullDescErr"></span>
                    </div>
                </div>

                <p class="section-label">Media</p>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Poster Image</label>
                        <?php if (!empty($movie['poster_image'])): ?>
                            <div class="current-poster">
                                <img src="../<?= htmlspecialchars($movie['poster_image']) ?>"
                                     onerror="this.style.display='none'">
                                <span>Current poster — upload a new one to replace it</span>
                            </div>
                        <?php endif; ?>
                        <input type="file" id="poster_image" name="poster_image"
                               accept="image/jpeg,image/png,image/webp,image/gif">
                        <span class="field-hint">JPG, PNG, WebP or GIF — max 5MB</span>
                        <img id="posterPreview" class="upload-preview" alt="New poster preview">
                    </div>

                    <div class="form-group">
                        <label for="media_file">Replace Media File</label>
                        <input type="file" id="media_file" name="media_file"
                               accept="video/mp4,video/webm,audio/mpeg,audio/ogg">
                        <span class="field-hint">MP4, WebM, MP3 or OGG — max 100MB</span>
                    </div>

                    <div class="form-group full">
                        <label for="trailer_url">Trailer URL</label>
                        <input type="url" id="trailer_url" name="trailer_url"
                               value="<?= htmlspecialchars($movie['trailer_url'] ?? '') ?>"
                               placeholder="https://www.youtube.com/watch?v=...">
                        <span class="field-error" id="trailerErr"></span>
                    </div>
                </div>

                <p class="section-label">Publishing</p>
                <label class="publish-toggle">
                    <input type="checkbox" name="publish" id="publish"
                           <?= $movie['is_published'] ? 'checked' : '' ?>>
                    <span>Published (uncheck to move back to draft)</span>
                </label>

                <div class="form-actions">
                    <button type="submit" class="btn-red">Save Changes</button>
                    <a href="index.php" class="btn-outline">Cancel</a>
                </div>
            </form>
        </div>
    </main>

    <footer>
        <div class="container">
            <p>&copy; <?= date('Y') ?> Movie Review</p>
        </div>
    </footer>

    <script>
        // Poster preview
        document.getElementById('poster_image').addEventListener('change', function() {
            const preview = document.getElementById('posterPreview');
            if (this.files && this.files[0]) {
                const reader = new FileReader();
                reader.onload = e => {
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                };
                reader.readAsDataURL(this.files[0]);
            }
        });

        // JS Validation
        document.getElementById('editForm').addEventListener('submit', function(e) {
            let valid = true;

            const title     = document.getElementById('title');
            const genre     = document.getElementById('genre_id');
            const shortDesc = document.getElementById('short_description');
            const fullDesc  = document.getElementById('full_description');
            const year      = document.getElementById('release_year');
            const trailer   = document.getElementById('trailer_url');

            ['titleErr','genreErr','shortDescErr','fullDescErr','yearErr','trailerErr']
                .forEach(id => document.getElementById(id).textContent = '');
            [title, genre, shortDesc, fullDesc, year, trailer]
                .forEach(el => el.classList.remove('invalid'));

            if (!title.value.trim()) {
                document.getElementById('titleErr').textContent = 'Title is required.';
                title.classList.add('invalid');
                valid = false;
            }
            if (!genre.value) {
                document.getElementById('genreErr').textContent = 'Please select a genre.';
                genre.classList.add('invalid');
                valid = false;
            }
            if (!shortDesc.value.trim()) {
                document.getElementById('shortDescErr').textContent = 'Short description is required.';
                shortDesc.classList.add('invalid');
                valid = false;
            }
            if (!fullDesc.value.trim()) {
                document.getElementById('fullDescErr').textContent = 'Full description is required.';
                fullDesc.classList.add('invalid');
                valid = false;
            }
            if (year.value && (year.value < 1888 || year.value > <?= date('Y') + 2 ?>)) {
                document.getElementById('yearErr').textContent = 'Enter a valid release year.';
                year.classList.add('invalid');
                valid = false;
            }
            if (trailer.value && !trailer.value.startsWith('http')) {
                document.getElementById('trailerErr').textContent = 'Enter a valid URL starting with http.';
                trailer.classList.add('invalid');
                valid = false;
            }

            if (!valid) e.preventDefault();
        });
    </script>
</body>
</html>
<?php mysqli_close($dbc); ?>