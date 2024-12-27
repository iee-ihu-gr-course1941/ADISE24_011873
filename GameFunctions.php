<?php

session_start(); // Start or resume the session
require_once 'internal/db_connection.php';

function initializeEmptyBoard() {
    return array_fill(0, 11, array_fill(0, 11, '_'));
}



// Function to initialize the game
function initializeGame($player1, $player2) {
    try {
        $conn = getDatabaseConnection();

        // Call the stored procedure to create the game and players
        $stmt = $conn->prepare("CALL CreateGameWithPlayers(?, ?)");
        $stmt->bind_param("ss", $player1, $player2);
        if (!$stmt->execute()) {
            throw new Exception("Failed to initialize the game: " . $stmt->error);
        }

        // Consume the result set from CreateGameWithPlayers
        $result = $stmt->get_result();
        $gameData = $result->fetch_assoc();
        $gameId = $gameData['game_id'];
        $player1Id = $gameData['player1_id'];
        $player2Id = $gameData['player2_id'];

        // Close the result set and statement
        $result->free();
        $stmt->close();

        echo "Game initialized successfully!<br>";

        // Display the empty board
        $board = initializeEmptyBoard();
        echo "<h3>Initial Board</h3>";
        printBoard($board);

        // Show the first player's turn
        echo "<h3>Player 1's Turn: $player1</h3>";

        // Fetch and display Player 1's available pieces
        $stmt = $conn->prepare("CALL GetAvailablePieces(?, ?)");
        if (!$stmt) {
            throw new Exception("Failed to prepare statement for available pieces: " . $conn->error);
        }

        $stmt->bind_param("ii", $player1Id, $gameId);
        if (!$stmt->execute()) {
            throw new Exception("Failed to fetch available pieces: " . $stmt->error);
        }

        $result = $stmt->get_result();
        $availablePieces = [];
        while ($row = $result->fetch_assoc()) {
            $availablePieces[] = $row;
        }

        echo "<h3>Available Pieces</h3>";
        printAvailablePieces($availablePieces);

        // Close the result set and statement
        $result->free();
        $stmt->close();

    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
    } finally {
        if (isset($conn)) {
            $conn->close();
        }
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

function makeMove($gameId, $playerId, $pieceId, $startX, $startY) {
    try {
        $conn = getDatabaseConnection();

        if ($conn === null) {
            throw new Exception("Database connection is null in makeMove.");
        }

        // Call the stored procedure to place the piece
        $stmt = $conn->prepare("CALL PlacePiece(?, ?, ?, ?, ?)");
        if (!$stmt) {
            throw new Exception("Statement preparation failed: " . $conn->error);
        }

        $stmt->bind_param("iiiii", $gameId, $playerId, $pieceId, $startX, $startY);
        if (!$stmt->execute()) {
            throw new Exception("Execution failed: " . $stmt->error);
        }
        echo "Move placed successfully.<br>";

        // Mark the piece as used in the PlayerPieces table
        $stmt = $conn->prepare("UPDATE PlayerPieces SET used = TRUE WHERE game_id = ? AND player_id = ? AND piece_id = ?");
        if (!$stmt) {
            throw new Exception("Statement preparation failed: " . $conn->error);
        }
        $stmt->bind_param("iii", $gameId, $playerId, $pieceId);
        if (!$stmt->execute()) {
            throw new Exception("Failed to mark piece as used: " . $stmt->error);
        }


// Reconstruct and display the updated board
        $board = reconstructBoard($gameId);
        echo "<h3>Updated Board</h3>";
        printBoard($board);

        // Determine the next player's ID
        $nextPlayerId = getNextPlayer($gameId, $playerId);

        // Fetch the next player's name
        $stmt = $conn->prepare("SELECT Name FROM Players WHERE ID = ?");
        if (!$stmt) {
            throw new Exception("Statement preparation failed: " . $conn->error);
        }

        $stmt->bind_param("i", $nextPlayerId);
        $stmt->execute();
        $result = $stmt->get_result();
        $nextPlayer = $result->fetch_assoc();

        echo "<h3>Next Player: " . htmlspecialchars($nextPlayer['Name']) . "</h3>";

        // Fetch the next player's available pieces using the stored procedure
        $stmt = $conn->prepare("CALL GetAvailablePieces(?, ?)");
        if (!$stmt) {
            throw new Exception("Statement preparation failed: " . $conn->error);
        }

        $stmt->bind_param("ii", $nextPlayerId, $gameId);
        $stmt->execute();
        $result = $stmt->get_result();

        // Collect available pieces
        $availablePieces = [];
        while ($row = $result->fetch_assoc()) {
            $availablePieces[] = $row;
        }

        // Display the next player's available pieces
        echo "<h3>Available Pieces</h3>";
        printAvailablePieces($availablePieces);
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
    } finally {
        if (isset($conn)) {
            $conn->close();
        }
    }
}

function reconstructBoard($gameId) {
    $conn = getDatabaseConnection();

    // Fetch all occupied cells for the given game
    $stmt = $conn->prepare("SELECT posX, posY, player_id FROM BoardState WHERE game_id = ?");
    $stmt->bind_param("i", $gameId);
    $stmt->execute();
    $result = $stmt->get_result();

    $board = array_fill(0, 11, array_fill(0, 11, '_'));

    // Populate the board with player IDs
    while ($row = $result->fetch_assoc()) {
        $board[$row['posY']][$row['posX']] = 'P' . $row['player_id'];
    }

    $stmt->close();
    $conn->close();

    return $board;
}

function printBoard($board) {
    echo "<h3>Debug: Board Structure</h3>";

    // Debug output of the board structure
    foreach ($board as $row) {
        echo implode("", $row) . "<br>";
    }

    // Render the board visually
    echo "<table style='border-collapse: collapse;'>";
    foreach ($board as $row) {
        echo "<tr>";
        foreach ($row as $cell) {
            $color = "white"; // Default empty cell color
            if ($cell === 'P1') {
                $color = "red"; // Player 1's pieces in red
            } elseif ($cell === 'P2') {
                $color = "blue"; // Player 2's pieces in blue
            }

            echo "<td style='width: 20px; height: 20px; background: $color; border: 1px solid #ccc; text-align: center;'>$cell</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
}


function getNextPlayer($gameId, $currentPlayerId) {
    // Fetch player IDs from the Games table
    $conn = getDatabaseConnection();
    $stmt = $conn->prepare("SELECT player1, player2 FROM Games WHERE ID = ?");
    $stmt->bind_param("i", $gameId);
    $stmt->execute();
    $result = $stmt->get_result();
    $game = $result->fetch_assoc();

    $stmt->close();
    $conn->close();

    if ($game['player1'] == $currentPlayerId) {
        return $game['player2'];
    }
    return $game['player1'];
}

function getAvailablePieces($playerId, $gameId) {
    $conn = getDatabaseConnection();

    $stmt = $conn->prepare("
        SELECT p.ID, p.sizeX, p.sizeY, p.shape 
        FROM PlayerPieces pp
        JOIN Pieces p ON pp.piece_id = p.ID
        WHERE pp.player_id = ? AND pp.game_id = ? AND pp.used = FALSE
    ");
    $stmt->bind_param("ii", $playerId, $gameId);
    $stmt->execute();
    $result = $stmt->get_result();

    $pieces = [];
    while ($row = $result->fetch_assoc()) {
        $pieces[] = $row;
    }

    $stmt->close();
    $conn->close();

    return $pieces;
}

function printAvailablePieces($pieces) {
    echo "<div style='display: flex; flex-wrap: wrap; gap: 20px;'>";
    foreach ($pieces as $piece) {
        echo "<div style='margin: 10px;'>";
        echo "<p>Piece ID: {$piece['ID']} (Size: {$piece['sizeX']}x{$piece['sizeY']})</p>";

        // Convert the shape into an HTML table
        $rows = explode("\n", $piece['shape']);
        echo "<table style='border-collapse: collapse;'>";
        foreach ($rows as $row) {
            echo "<tr>";
            foreach (str_split($row) as $cell) {
                $color = ($cell === 'X') ? 'black' : 'white';
                echo "<td style='width: 20px; height: 20px; background: $color;'></td>";
            }
            echo "</tr>";
        }
        echo "</table>";

        echo "</div>";
    }
    echo "</div>";
}

?>
