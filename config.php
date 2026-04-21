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
?>
