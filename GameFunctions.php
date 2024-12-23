<?php

session_start(); // Start or resume the session
require_once 'internal/db_connection.php';

// Function to initialize the game
function initializeGame($player1Name, $player2Name) {
    try {
        // Create a 7x7 board (can be stored in session or database if required)
        $board = array_fill(0, 7, array_fill(0, 7, 0)); // 7x7 array filled with 0s

        // Call the function to create the game and get the IDs
        $gameData = createGameWithPlayers($player1Name, $player2Name);

        if ($gameData) {
            // Store player names and board in session
            $_SESSION['player1_name'] = $player1Name;
            $_SESSION['player2_name'] = $player2Name;
            $_SESSION['board'] = $board;

            echo "Game initialized successfully!";
        } else {
            throw new Exception("Failed to initialize the game.");
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
    }
}


// Function to create the game and insert players into the database
function createGameWithPlayers($player1Name, $player2Name) {
    $conn = null; 
    try {
        $conn = getDatabaseConnection();

        // Prepare the SQL statement to call the stored procedure
        $stmt = $conn->prepare("CALL CreateGameWithPlayers(?, ?)");
        $stmt->bind_param("ss", $player1Name, $player2Name);
        $stmt->execute();

        // Fetch the results returned by the procedure
        $result = $stmt->get_result();
        if ($result && $row = $result->fetch_assoc()) {
            $gameId = $row['game_id'];
            $player1Id = $row['player1_id'];
            $player2Id = $row['player2_id'];

            // Store the values in the session
            $_SESSION['game_id'] = $gameId;
            $_SESSION['player1_id'] = $player1Id;
            $_SESSION['player2_id'] = $player2Id;
            $_SESSION['current_turn'] = $player1Id; // Player 1 turn

            $stmt->close(); 
            return [
                'game_id' => $gameId,
                'player1_id' => $player1Id,
                'player2_id' => $player2Id
            ];
        } else {
            throw new Exception("Failed to retrieve IDs from the stored procedure.");
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
        return false;
    } finally {
        // Close the connection
        if ($conn) {
            $conn->close();
        }
    }
}

function printSessionVariables() {
    echo "<pre>";
    print_r($_SESSION);
    echo "</pre>";
}

?>
