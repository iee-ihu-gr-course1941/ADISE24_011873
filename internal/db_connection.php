<?php

function getDatabaseConnection() {
    $host = "dblabs.iee.ihu.gr";
    $user = "it011873";
    $password = "it011873";
    $dbname = "it011873";

    // Create a connection using mysqli
    $conn = new mysqli($host, $user, $password, $dbname);

    // Check the connection
    if ($conn->connect_error) {
        die("Database connection failed: " . $conn->connect_error);
    }

    return $conn;
}
?>