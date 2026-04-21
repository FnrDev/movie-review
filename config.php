<?php
$host = '20.74.143.233';
$username = 'u202202672';
$password = 'asdASD123!';
$database = 'movie-review';

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}

$conn->set_charset('utf8mb4');
?>
