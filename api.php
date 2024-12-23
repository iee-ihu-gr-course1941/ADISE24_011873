<?php
require_once 'GameFunctions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if 'method' is provided
    if (!isset($_POST['method'])) {
        echo "Error: 'method' parameter is required.";
        exit;
    }

    $method = $_POST['method'];

    try {
        switch ($method) {
            case 'initializeGame':
                if (isset($_POST['player1']) && isset($_POST['player2'])) {
                    $player1 = $_POST['player1'];
                    $player2 = $_POST['player2'];
                    initializeGame($player1, $player2);
                } else {
                    echo "Error: Both 'player1' and 'player2' parameters are required.";
                }
                break;

            case 'makeMove':
                if (isset($_POST['game_id']) && isset($_POST['player_id']) &&
                    isset($_POST['piece_id']) && isset($_POST['startX']) && isset($_POST['startY'])) {

                    $gameId = $_POST['game_id'];
                    $playerId = $_POST['player_id'];
                    $pieceId = $_POST['piece_id'];
                    $startX = $_POST['startX'];
                    $startY = $_POST['startY'];

                    makeMove($gameId, $playerId, $pieceId, $startX, $startY);
                } else {
                    echo "Error: Missing parameters. Required: 'game_id', 'player_id', 'piece_id', 'startX', 'startY'.";
                }
                break;

            default:
                echo "Error: Unknown method '$method'.";
                break;
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
    }
} else {
    echo "Error: Invalid request method. Please use POST.";
}