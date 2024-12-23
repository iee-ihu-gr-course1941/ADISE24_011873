<?php

session_start(); // Start or resume the session
require_once 'internal/db_connection.php';

// Function to initialize the game
function initializeGame($player1Name, $player2Name) {
    try {

        $board = array_fill(0, 11, array_fill(0, 11, 0));
        // Call the function to create the game and get the IDs
        $gameData = createGameWithPlayers($player1Name, $player2Name);

        if ($gameData) {
            // Store player names and board in session for later
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

function makeMove($gameId, $playerId, $pieceId, $startX, $startY) {
    $corners = [[0, 0], [0, 10], [10, 0], [10, 10]];
    $isFirstMove = false;

    try {
        $conn = getDatabaseConnection();

        // Check if the board is empty (first move)
        $stmt = $conn->prepare("SELECT COUNT(*) AS cell_count FROM BoardState WHERE game_id = ?");
        $stmt->bind_param("i", $gameId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if ($row['cell_count'] == 0) {
            $isFirstMove = true;
        }

        // Fetch piece details
        $stmt = $conn->prepare("SELECT shape, sizeX, sizeY FROM Pieces WHERE ID = ?");
        $stmt->bind_param("i", $pieceId);
        $stmt->execute();
        $result = $stmt->get_result();
        $piece = $result->fetch_assoc();

        if (!$piece) {
            throw new Exception("Piece not found.");
        }

        $shape = $piece['shape'];
        $sizeX = $piece['sizeX'];
        $sizeY = $piece['sizeY'];

        // Validate the move
        if ($isFirstMove) {
            // First move: Validate that the piece touches one of the corners
            $touchesCorner = false;
            foreach ($corners as $corner) {
                if ($startX <= $corner[0] && $startY <= $corner[1] &&
                        ($startX + $sizeX - 1) >= $corner[0] &&
                        ($startY + $sizeY - 1) >= $corner[1]) {
                    $touchesCorner = true;
                    break;
                }
            }

            if (!$touchesCorner) {
                throw new Exception("The first move must touch one of the board's corners.");
            }
        } else {
            // Subsequent move: Add adjacency and overlap checks here
        }

        // Call the stored procedure to store the move
        $stmt = $conn->prepare("CALL PlacePiece(?, ?, ?, ?, ?)");
        $stmt->bind_param("iiiii", $gameId, $playerId, $pieceId, $startX, $startY);
        $stmt->execute();

        // Reconstruct the board
        echo "<h3>Updated Board</h3>";
        $board = reconstructBoard($gameId);
        printBoard($board);

        // Determine the next player
        $nextPlayerId = getNextPlayer($gameId, $playerId);

        // Fetch the next player's name
        $stmt = $conn->prepare("SELECT Name FROM Players WHERE ID = ?");
        $stmt->bind_param("i", $nextPlayerId);
        $stmt->execute();
        $result = $stmt->get_result();
        $nextPlayer = $result->fetch_assoc();

        echo "<h3>Next Player: " . htmlspecialchars($nextPlayer['Name']) . "</h3>";

        // Fetch the next player's available pieces
        $availablePieces = getAvailablePieces($nextPlayerId, $gameId);

        // Show the next player's pieces
        echo "<h3>Available Pieces</h3>";
        printAvailablePieces($availablePieces);
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
    } finally {
        $stmt->close();
        $conn->close();
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
    echo "<table style='border-collapse: collapse;'>";
    foreach ($board as $row) {
        echo "<tr>";
        foreach ($row as $cell) {
            // Determine the color based on the cell value
            $color = "white"; // Default empty cell color
            if ($cell === 'P1') {
                $color = "red"; // Player 1's pieces in red
            } elseif ($cell === 'P2') {
                $color = "blue"; // Player 2's pieces in blue
            }

            // Render the cell
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

        // Convert shape to an HTML table
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
