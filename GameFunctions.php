<?php

session_start(); // Start or resume a session
// Include the database connection
require_once 'internal/db_connection.php';

// Function to create a 7x7 board and assign player names
function initializeGame($player1, $player2) {
    // Create a 7x7 board
    $board = array();
    for ($i = 0; $i < 7; $i++) {
        $row = array();
        for ($j = 0; $j < 7; $j++) {
            $row[] = 0; // Initialize each cell to 0
        }
        $board[] = $row;
    }

    // Store player names in session
    $_SESSION['player1'] = $player1;
    $_SESSION['player2'] = $player2;

    // Insert the DB records for the game by calling the stored procedure
    $result = createGameWithPlayers($player1, $player2);
    echo $result;

    return $board;
}

function createGameWithPlayers($player1Name, $player2Name) {
    try {
        // Get database connection
        $conn = getDatabaseConnection();

        // Prepare the SQL statement to call the procedure
        $stmt = $conn->prepare("CALL CreateGameWithPlayers(?, ?)");
        $stmt->bind_param("ss", $player1Name, $player2Name);
        $stmt->execute();

        echo "Game created successfully with players '$player1Name' and '$player2Name'!";
    } catch (PDOException $e) {
        echo "Error: " . $e->getMessage();
    } finally {
        // Close the connection
        if (isset($conn)) {
            $conn = null;
        }
    }
}

?>
