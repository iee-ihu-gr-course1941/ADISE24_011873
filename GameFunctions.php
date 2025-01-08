<?php

session_start(); // Start or resume the session
require_once 'internal/db_connection.php';

function initializeEmptyBoard() {
    return array_fill(0, 11, array_fill(0, 11, '_'));
}

// Function to initialize the game
function setupGame() {
    try {
        $conn = getDatabaseConnection();

        $player1Id = $_SESSION['player1Id'];
        $player2Id = $_SESSION['player2Id'];
        $player1Name = $_SESSION['player1Name'];
        $player2Name = $_SESSION['player2Name'];

        $gameId = $_SESSION['gameId'];

        $currentTurn = $_SESSION['currentTurn'];

        echo "Game is ready! Here's the initial setup:<br>";

        echo "<h3>Initial Board</h3>";
        $board = reconstructBoard($_SESSION['gameId']); // Fetch the current board state!
        printBoard($board);

        $currentPlayerName = ($currentTurn === $_SESSION['player1Id']) ? $_SESSION['player1Name'] : $_SESSION['player2Name'];
        echo "<h3>It's $currentPlayerName's Turn!</h3>";

        // Fetch and display Player 1's available pieces
        $stmt = $conn->prepare("CALL GetAvailablePieces(?, ?)");
        if (!$stmt) {
            throw new Exception("Failed to prepare statement for available pieces: " . $conn->error);
        }

        $stmt->bind_param("ii", $currentTurn, $gameId);
        if (!$stmt->execute()) {
            throw new Exception("Failed to fetch available pieces: " . $stmt->error);
        }

        $result = $stmt->get_result();
        $availablePieces = [];
        while ($row = $result->fetch_assoc()) {
            $availablePieces[] = $row;
        }

        clearResults($conn);

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

// Fetch the current turn from the database
        $stmt = $conn->prepare("SELECT current_turn FROM Games WHERE ID = ?");
        if (!$stmt) {
            throw new Exception("Failed to prepare statement: " . $conn->error);
        }
        $stmt->bind_param("i", $gameId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            throw new Exception("Game not found with ID: " . $gameId);
        }

        $gameData = $result->fetch_assoc();
        $_SESSION['currentTurn'] = $gameData['current_turn']; // Update the session with the current turn
// Validate the token
        if (!isset($_SESSION['token']) || $_SESSION['token'] !== $token) {
            echo json_encode([
                "success" => false,
                "message" => "Invalid token. Please log in again."
            ]);
            return;
        }

        // Validate the player ID
        if ($_SESSION['playerId'] !== $playerId) {
            echo json_encode([
                "success" => false,
                "message" => "It's not your turn. Please wait for the correct player to play."
            ]);
            return;
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

        // Determine the next player's turn
        $nextTurn = ($_SESSION['currentTurn'] === $_SESSION['player1Id']) ? $_SESSION['player2Id'] : $_SESSION['player1Id'];

        // Update the current turn in the database
        $stmt = $conn->prepare("UPDATE Games SET current_turn = ? WHERE ID = ?");
        if (!$stmt) {
            throw new Exception("Failed to prepare statement: " . $conn->error);
        }
        $stmt->bind_param("ii", $nextTurn, $gameId);
        if (!$stmt->execute()) {
            throw new Exception("Failed to update current turn: " . $stmt->error);
        }

        // Update the session with the new turn
        $_SESSION['currentTurn'] = $nextTurn;

        echo "Move completed! It's now Player " . ($_SESSION['currentTurn'] === $_SESSION['player1Id'] ? "1's" : "2's") . " turn.";

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
// Make sure currentTurn is set and if it's not then use player1's turn
    if (!isset($_SESSION['currentTurn'])) {
        $_SESSION['currentTurn'] = $_SESSION['player1Id'];
    }

// Determine player's color based on session data
    $playerColor = ($_SESSION['currentTurn'] === $_SESSION['player1Id']) ? 'red' : 'blue';

    $playerColor = ($_SESSION['currentTurn'] === $_SESSION['player1Id']) ? 'red' : 'blue';
    echo "<div style='display: flex; flex-wrap: wrap; gap: 20px;'>"; // Flexbox for layout
    foreach ($pieces as $piece) {
        echo "<div style='margin: 10px;'>"; // Container for each piece
        echo "<p>Piece ID: {$piece['ID']} (Size: {$piece['sizeX']}x{$piece['sizeY']})</p>";

        // Render the piece shape
        $shape = $piece['shape'];
        $sizeX = $piece['sizeX'];
        $sizeY = $piece['sizeY'];

        echo "<table style='border-collapse: collapse;'>";
        for ($row = 0; $row < $sizeY; $row++) {
            echo "<tr>";
            for ($col = 0; $col < $sizeX; $col++) {
                $charIndex = ($row * $sizeX) + $col;
                $cell = ($charIndex < strlen($shape)) ? $shape[$charIndex] : '.';
                $color = ($cell === 'X') ? $playerColor : 'white'; // Use player's color for X
                echo "<td style='width: 30px; height: 30px; background: $color;'></td>";
            }
            echo "</tr>";
        }
        echo "</table>";

        echo "</div>"; // Close piece container
    }
    echo "</div>"; // Close flexbox container
}

function clearResults($conn) {
    try {
        while ($conn->more_results() && $conn->next_result()) {
            $result = $conn->use_result();
            if ($result instanceof mysqli_result) {
                $result->free();
            }
        }
    } catch (Exception $e) {
        echo "Error clearing results: " . $e->getMessage();
    }
}

function resumeGame($gameToken) {
    try {
        $conn = getDatabaseConnection();

        // Fetch game data using the token
        $stmt = $conn->prepare("CALL GetGameByToken(?, @gameID, @player1ID, @player2ID, @currentTurn)");
        if (!$stmt) {
            throw new Exception("Failed to prepare statement: " . $conn->error);
        }
        $stmt->bind_param("s", $gameToken);
        if (!$stmt->execute()) {
            throw new Exception("Failed to execute statement: " . $stmt->error);
        }

        // Retrieve output parameters
        $result = $conn->query("SELECT @gameID AS game_id, @player1ID AS player1_id, @player2ID AS player2_id, @currentTurn AS current_turn");
        if (!$result) {
            throw new Exception("Failed to fetch game data: " . $conn->error);
        }

        $gameData = $result->fetch_assoc();
        $gameId = $gameData['game_id'];
        $player1Id = $gameData['player1_id'];
        $player2Id = $gameData['player2_id'];
        $currentTurn = $gameData['current_turn'];

        if (!$gameId || !$player1Id || !$player2Id) {
            throw new Exception("Invalid game token or game data is incomplete.");
        }

        // Restore session data
        $_SESSION['gameId'] = $gameId;
        $_SESSION['gameToken'] = $gameToken;
        $_SESSION['player1Id'] = $player1Id;
        $_SESSION['player2Id'] = $player2Id;
        $_SESSION['currentTurn'] = $currentTurn;

        // Fetch and display the board state
        $board = reconstructBoard($gameId);
        echo "<h3>Resumed Board</h3>";
        printBoard($board);

        // Fetch and display available pieces for the current player
        $stmt = $conn->prepare("CALL GetAvailablePieces(?, ?)");
        $stmt->bind_param("ii", $currentTurn, $gameId);
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

        echo "<h3>Current Turn: Player " . ($currentTurn === $player1Id ? "1" : "2") . "</h3>";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
    } finally {
        if (isset($conn)) {
            $conn->close();
        }
    }
}

?>
