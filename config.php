<?php
function get_db_connection() {
    //change the paramters in the function below to your user id
    $dbc = mysqli_connect('localhost', 'u202202672', 'asdASD123!', 'db202202672');

    if (mysqli_connect_errno()) {
        printf("Connect failed: %s\n", mysqli_connect_error());
        die('b0ther');
    }

    mysqli_set_charset($dbc, 'utf8mb4');
    return $dbc;
}

/**
 * Detect a file's MIME type without depending on the fileinfo extension.
 * Falls back to mime_content_type/finfo when available, then to the file
 * extension. Used for upload validation so uploads don't fatal-error on
 * servers where fileinfo is disabled.
 */
function detect_file_mime($path, $originalName = '') {
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $path);
            finfo_close($finfo);
            if ($mime) return $mime;
        }
    }
    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($path);
        if ($mime) return $mime;
    }
    // Last resort: map by file extension
    $ext = strtolower(pathinfo($originalName !== '' ? $originalName : $path, PATHINFO_EXTENSION));
    $map = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'webp' => 'image/webp', 'gif' => 'image/gif',
        'mp4' => 'video/mp4', 'webm' => 'video/webm',
        'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4',
    ];
    return $map[$ext] ?? '';
}
?>
