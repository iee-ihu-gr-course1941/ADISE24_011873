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

    // Call the stored procedure to start the game
    //gameStart($player1, $player2);

    return $board;
}

// Function to call the gameStart stored procedure
function gameStart($player1, $player2) {
    global $pdo; // Use the PDO connection from db_connection.php
    try {
        $stmt = $pdo->prepare("CALL gameStart(:player1, :player2)");
        $stmt->bindParam(':player1', $player1, PDO::PARAM_STR);
        $stmt->bindParam(':player2', $player2, PDO::PARAM_STR);
        $stmt->execute();
    } catch (PDOException $e) {
        die(json_encode(["error" => "Database error: " . $e->getMessage()]));
    }
}

// Handle incoming requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'initializeGame':
            $player1 = $_POST['player1'] ?? '';
            $player2 = $_POST['player2'] ?? '';

            if (empty($player1) || empty($player2)) {
                echo json_encode(["error" => "Player names are required"]);
            } else {
                $board = initializeGame($player1, $player2);
                echo json_encode([
                    "message" => "Game initialized",
                    "board" => $board,
                    "players" => [
                        "player1" => $_SESSION['player1'],
                        "player2" => $_SESSION['player2']
                    ]
                ]);
            }
            break;

        default:
            echo json_encode(["error" => "Unknown action"]);
    }
} else {
    http_response_code(405);
    echo json_encode(["error" => "Invalid request method. Use POST."]);
}
?>
