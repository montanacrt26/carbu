<?php
/**
 * CarbuFrance - API historique des recherches utilisateur
 *
 * POST /api/search_history.php   - Enregistre une nouvelle recherche
 *      body: { query, latitude, longitude, fuel_type, radius_km, results_count }
 *
 * GET  /api/search_history.php   - Liste les 20 dernières recherches
 *
 * DELETE /api/search_history.php - Vide l'historique
 */

require_once __DIR__ . '/dbconfig.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'POST':
        recordSearch();
        break;
    case 'GET':
        listSearches();
        break;
    case 'DELETE':
        clearSearches();
        break;
    default:
        jsonError('Méthode non supportée', 405);
}

function recordSearch() {
    $session = requireAuth();
    $input = getRequestBodyJson();

    $query        = trim($input['query'] ?? '');
    $latitude     = isset($input['latitude'])  && is_numeric($input['latitude'])  ? (float)$input['latitude']  : null;
    $longitude    = isset($input['longitude']) && is_numeric($input['longitude']) ? (float)$input['longitude'] : null;
    $fuelType     = trim($input['fuel_type'] ?? '');
    $radiusKm     = isset($input['radius_km']) ? (int)$input['radius_km'] : null;
    $resultsCount = isset($input['results_count']) ? (int)$input['results_count'] : 0;

    if (strlen($query) > 255) $query = substr($query, 0, 255);

    $pdo = getDB();
    $stmt = $pdo->prepare('
        INSERT INTO search_history (user_id, query, latitude, longitude, fuel_type, radius_km, results_count)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$session['user_id'], $query, $latitude, $longitude, $fuelType, $radiusKm, $resultsCount]);

    jsonSuccess(['recorded' => true]);
}

function listSearches() {
    $session = requireAuth();
    $pdo = getDB();

    $stmt = $pdo->prepare('
        SELECT id, query, latitude, longitude, fuel_type, radius_km, results_count, created_at
        FROM search_history
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 20
    ');
    $stmt->execute([$session['user_id']]);
    jsonSuccess(['searches' => $stmt->fetchAll()]);
}

function clearSearches() {
    $session = requireAuth();
    $pdo = getDB();
    $stmt = $pdo->prepare('DELETE FROM search_history WHERE user_id = ?');
    $stmt->execute([$session['user_id']]);
    logActivity($session['user_id'], 'search_history_clear', 'Historique des recherches vidé');
    jsonSuccess(['cleared' => true]);
}
