<?php

require_once 'GameFunctions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get player names from POST request
    $player1Name = $_POST['player1'] ?? null;
    $player2Name = $_POST['player2'] ?? null;

    if ($player1Name && $player2Name) {
        // Call the function
        initializeGame($player1Name, $player2Name);
    } else {
        echo "Error: Both player1 and player2 are required.";
    }
} else {
    echo "Invalid request method. Please use POST.";
}
?>