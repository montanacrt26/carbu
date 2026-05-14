<?php
/**
 * CarbuFrance - API des favoris (v2)
 *
 * Endpoints:
 *   GET    /api/favorites.php                            - Liste des favoris
 *   POST   /api/favorites.php                            - Ajouter un favori
 *   PATCH  /api/favorites.php?id=<station>               - Modifier (surnom, notes)
 *   POST   /api/favorites.php?action=reorder             - Réordonner (drag & drop)
 *   DELETE /api/favorites.php?id=<station>               - Supprimer
 *   GET    /api/favorites.php?action=check&id=<station>  - Vérifier si favori
 */

require_once __DIR__ . '/dbconfig.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($method) {
    case 'GET':
        if ($action === 'check') {
            checkFavorite();
        } else {
            listFavorites();
        }
        break;
    case 'POST':
        if ($action === 'reorder') {
            reorderFavorites();
        } else {
            addFavorite();
        }
        break;
    case 'PATCH':
    case 'PUT':
        updateFavorite();
        break;
    case 'DELETE':
        removeFavorite();
        break;
    default:
        jsonError('Méthode non supportée', 405);
}

// ─────────────────────────────────────────────────────────────────────────────
// Handlers
// ─────────────────────────────────────────────────────────────────────────────

function listFavorites() {
    $session = requireAuth();
    $pdo = getDB();

    $stmt = $pdo->prepare('
        SELECT station_id, station_name, station_address, station_city,
               nickname, notes, sort_position,
               postal_code, latitude, longitude, brand,
               price_snapshot, price_snapshot_fuel, price_snapshot_at,
               created_at
        FROM favorites
        WHERE user_id = ?
        ORDER BY sort_position ASC, created_at DESC
    ');
    $stmt->execute([$session['user_id']]);
    $favorites = $stmt->fetchAll();

    $ids = array_column($favorites, 'station_id');

    jsonSuccess([
        'favorites' => $favorites,
        'ids'       => $ids,
        'count'     => count($favorites),
    ]);
}

function checkFavorite() {
    $session = getCurrentSession();
    $stationId = $_GET['id'] ?? '';

    if (!$session) {
        jsonSuccess(['isFavorite' => false, 'loggedIn' => false]);
    }
    if (!$stationId) {
        jsonError('ID de station requis');
    }

    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT id FROM favorites WHERE user_id = ? AND station_id = ?');
    $stmt->execute([$session['user_id'], $stationId]);

    jsonSuccess([
        'isFavorite' => (bool)$stmt->fetch(),
        'loggedIn'   => true,
    ]);
}

function addFavorite() {
    $session = requireAuth();
    $input = getRequestBodyJson();

    $stationId      = trim($input['station_id'] ?? '');
    $stationName    = trim($input['station_name'] ?? '');
    $stationAddress = trim($input['station_address'] ?? '');
    $stationCity    = trim($input['station_city'] ?? '');
    $postalCode     = trim($input['postal_code'] ?? '');
    $latitude       = isset($input['latitude'])  && is_numeric($input['latitude'])  ? (float)$input['latitude']  : null;
    $longitude      = isset($input['longitude']) && is_numeric($input['longitude']) ? (float)$input['longitude'] : null;
    $brand          = trim($input['brand'] ?? '');
    $priceSnapshot  = isset($input['price_snapshot']) && is_numeric($input['price_snapshot']) ? (float)$input['price_snapshot'] : null;
    $priceFuel      = trim($input['price_snapshot_fuel'] ?? '');

    if (!$stationId) {
        jsonError('ID de station requis');
    }

    $pdo = getDB();

    // Vérifier si déjà en favori
    $stmt = $pdo->prepare('SELECT id FROM favorites WHERE user_id = ? AND station_id = ?');
    $stmt->execute([$session['user_id'], $stationId]);
    if ($stmt->fetch()) {
        jsonSuccess(['added' => false], 'Station déjà en favoris');
    }

    // Trouver la prochaine position
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_position), -1) + 1 FROM favorites WHERE user_id = ?');
    $stmt->execute([$session['user_id']]);
    $nextPos = (int)$stmt->fetchColumn();

    // Ajouter avec toutes les infos disponibles
    $stmt = $pdo->prepare('
        INSERT INTO favorites
            (user_id, station_id, station_name, station_address, station_city,
             postal_code, latitude, longitude, brand,
             price_snapshot, price_snapshot_fuel, price_snapshot_at,
             sort_position)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
    ');
    $stmt->execute([
        $session['user_id'], $stationId, $stationName, $stationAddress, $stationCity,
        $postalCode, $latitude, $longitude, $brand,
        $priceSnapshot, $priceFuel ?: null,
        $nextPos,
    ]);

    logActivity($session['user_id'], 'favorite_add', "Station $stationId ajoutée aux favoris");

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM favorites WHERE user_id = ?');
    $stmt->execute([$session['user_id']]);
    $count = (int)$stmt->fetchColumn();

    jsonSuccess([
        'added' => true,
        'count' => $count,
    ], 'Station ajoutée aux favoris');
}

function updateFavorite() {
    $session = requireAuth();

    $stationId = $_GET['id'] ?? '';
    $input = getRequestBodyJson();
    if (!$stationId) {
        $stationId = trim($input['station_id'] ?? '');
    }
    if (!$stationId) {
        jsonError('ID de station requis');
    }

    $pdo = getDB();
    $sets = [];
    $params = [];

    if (array_key_exists('nickname', $input)) {
        $nick = $input['nickname'];
        if ($nick !== null) $nick = trim((string)$nick);
        if ($nick === '') $nick = null;
        if ($nick !== null && strlen($nick) > 100) $nick = substr($nick, 0, 100);
        $sets[] = 'nickname = ?';
        $params[] = $nick;
    }
    if (array_key_exists('notes', $input)) {
        $notes = $input['notes'];
        if ($notes !== null) $notes = trim((string)$notes);
        if ($notes === '') $notes = null;
        if ($notes !== null && strlen($notes) > 2000) $notes = substr($notes, 0, 2000);
        $sets[] = 'notes = ?';
        $params[] = $notes;
    }

    if (!$sets) {
        jsonError('Aucun champ à modifier');
    }

    $params[] = $session['user_id'];
    $params[] = $stationId;

    $sql = 'UPDATE favorites SET ' . implode(', ', $sets) . ' WHERE user_id = ? AND station_id = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $changed = $stmt->rowCount() > 0;

    if ($changed) {
        logActivity($session['user_id'], 'favorite_update', "Favori $stationId modifié");
    }

    jsonSuccess(['updated' => $changed], $changed ? 'Favori mis à jour' : 'Aucun changement');
}

function reorderFavorites() {
    $session = requireAuth();
    $input = getRequestBodyJson();
    $order = $input['order'] ?? null;

    if (!is_array($order) || !$order) {
        jsonError('Liste d\'IDs requise (param "order")');
    }

    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE favorites SET sort_position = ? WHERE user_id = ? AND station_id = ?');
        foreach ($order as $position => $stationId) {
            $stmt->execute([(int)$position, $session['user_id'], (string)$stationId]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Erreur lors du réordonnancement', 500);
    }

    jsonSuccess(['reordered' => true]);
}

function removeFavorite() {
    $session = requireAuth();

    $stationId = $_GET['id'] ?? '';
    if (!$stationId) {
        $input = getRequestBodyJson();
        $stationId = $input['station_id'] ?? '';
    }
    if (!$stationId) {
        jsonError('ID de station requis');
    }

    $pdo = getDB();
    $stmt = $pdo->prepare('DELETE FROM favorites WHERE user_id = ? AND station_id = ?');
    $stmt->execute([$session['user_id'], $stationId]);
    $removed = $stmt->rowCount() > 0;

    if ($removed) {
        logActivity($session['user_id'], 'favorite_remove', "Station $stationId retirée des favoris");
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM favorites WHERE user_id = ?');
    $stmt->execute([$session['user_id']]);
    $count = (int)$stmt->fetchColumn();

    jsonSuccess([
        'removed' => $removed,
        'count'   => $count,
    ], $removed ? 'Station retirée des favoris' : 'Station non trouvée dans les favoris');
}
