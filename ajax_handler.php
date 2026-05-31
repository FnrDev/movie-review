<?php
/**
 * AJAX handler for rating and comment submissions.
 */
session_start();
require_once 'config.php';

header('Content-Type: application/json');

$action  = $_POST['action']  ?? '';
$movieId = (int)($_POST['movie_id'] ?? 0);

if ($movieId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Invalid movie.']);
    exit;
}

$dbc = get_db_connection();

//Rates
if ($action === 'rate') {
    if (!isset($_SESSION['user'])) {
        echo json_encode(['ok' => false, 'message' => 'You must be logged in to rate.']);
        exit;
    }

    $userId      = (int)$_SESSION['user']['user_id'];
    $ratingValue = (int)($_POST['rating_value'] ?? 0);

    if ($ratingValue < 1 || $ratingValue > 5) {
        echo json_encode(['ok' => false, 'message' => 'Please select a rating between 1 and 5.']);
        exit;
    }

    $stmt = mysqli_prepare($dbc, "
        INSERT INTO dbProj_ratings (movie_id, user_id, rating_value)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE rating_value = VALUES(rating_value)
    ");
    mysqli_stmt_bind_param($stmt, 'iii', $movieId, $userId, $ratingValue);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    // Return updated stats
    $res  = mysqli_query($dbc, "
        SELECT COALESCE(ROUND(AVG(rating_value), 1), 0) AS avg_rating,
               COUNT(*) AS total_ratings
        FROM dbProj_ratings WHERE movie_id = $movieId
    ");
    $stats = mysqli_fetch_assoc($res);

    echo json_encode([
        'ok'           => true,
        'message'      => 'Rating saved!',
        'avg_rating'   => number_format((float)$stats['avg_rating'], 1),
        'total_ratings'=> (int)$stats['total_ratings'],
        'user_rating'  => $ratingValue,
    ]);
    exit;
}

//Comments
if ($action === 'comment') {
    if (!isset($_SESSION['user'])) {
        echo json_encode(['ok' => false, 'message' => 'You must be logged in to comment.']);
        exit;
    }

    $userId  = (int)$_SESSION['user']['user_id'];
    $comment = trim($_POST['comment_text'] ?? '');

    if ($comment === '') {
        echo json_encode(['ok' => false, 'message' => 'Comment cannot be empty.']);
        exit;
    }
    if (strlen($comment) > 1000) {
        echo json_encode(['ok' => false, 'message' => 'Comment must be under 1000 characters.']);
        exit;
    }

    $stmt = mysqli_prepare($dbc,
        "INSERT INTO dbProj_comments (movie_id, user_id, comment_text) VALUES (?, ?, ?)"
    );
    mysqli_stmt_bind_param($stmt, 'iis', $movieId, $userId, $comment);

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        echo json_encode(['ok' => false, 'message' => 'Failed to post comment.']);
        exit;
    }

    $commentId = mysqli_insert_id($dbc);
    mysqli_stmt_close($stmt);

    // Return the new comment data so JS can prepend it without reload
    echo json_encode([
        'ok'         => true,
        'message'    => 'Comment posted!',
        'comment_id' => $commentId,
        'username'   => $_SESSION['user']['username'],
        'text'       => $comment,
        'created_at' => date('Y-m-d H:i'),
    ]);
    exit;
}

echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
mysqli_close($dbc);
