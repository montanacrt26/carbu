<?php
/**
 * CarbuFrance - Recent Searches API
 *
 * GET    /api/recent_searches.php           Liste les recherches récentes de l'utilisateur (plus récente d'abord)
 * POST   /api/recent_searches.php           Upsert (body: { postal_code, city_name, latitude, longitude })
 * DELETE /api/recent_searches.php?id=X      Supprime une entrée
 * DELETE /api/recent_searches.php?all=1     Vide toutes les entrées de l'utilisateur
 */

require_once __DIR__ . '/dbconfig.php';

// Auth requise (renvoie 401 si pas connecté)
$session = requireAuth();
$userId  = (int)$session['user_id'];

$pdo    = getDB();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        $limit = isset($_GET['limit']) ? max(1, min(20, (int)$_GET['limit'])) : 8;
        $stmt = $pdo->prepare(
            'SELECT id, postal_code, city_name, latitude, longitude, last_used_at, use_count
             FROM recent_searches
             WHERE user_id = :uid
             ORDER BY last_used_at DESC
             LIMIT ' . (int)$limit
        );
        $stmt->execute([':uid' => $userId]);
        $rows = $stmt->fetchAll();

        $items = array_map(function ($r) {
            return [
                'id'           => (int)$r['id'],
                'postal_code'  => $r['postal_code'],
                'city_name'    => $r['city_name'],
                'latitude'     => $r['latitude']  !== null ? (float)$r['latitude']  : null,
                'longitude'    => $r['longitude'] !== null ? (float)$r['longitude'] : null,
                'last_used_at' => $r['last_used_at'],
                'use_count'    => (int)$r['use_count'],
            ];
        }, $rows);

        jsonSuccess(['items' => $items, 'count' => count($items)]);
    }

    if ($method === 'POST') {
        // Lire le body JSON (depuis le cache pour éviter le double-read de php://input)
        $body = getRequestBodyJson();

        $postal = trim((string)($body['postal_code'] ?? ''));
        $city   = trim((string)($body['city_name']   ?? ''));
        $lat    = isset($body['latitude'])  && is_numeric($body['latitude'])  ? (float)$body['latitude']  : null;
        $lon    = isset($body['longitude']) && is_numeric($body['longitude']) ? (float)$body['longitude'] : null;

        if ($postal === '' && $city === '') {
            jsonError('Code postal ou ville requis.', 400);
        }

        // Tronquer pour rester dans les contraintes des colonnes
        $postal = mb_substr($postal, 0, 10);
        $city   = mb_substr($city,   0, 120);

        $stmt = $pdo->prepare(
            'INSERT INTO recent_searches (user_id, postal_code, city_name, latitude, longitude)
             VALUES (:uid, :cp, :city, :lat, :lon)
             ON DUPLICATE KEY UPDATE
               latitude     = COALESCE(VALUES(latitude),  latitude),
               longitude    = COALESCE(VALUES(longitude), longitude),
               last_used_at = CURRENT_TIMESTAMP,
               use_count    = use_count + 1'
        );
        $stmt->execute([
            ':uid'  => $userId,
            ':cp'   => $postal,
            ':city' => $city,
            ':lat'  => $lat,
            ':lon'  => $lon,
        ]);

        // Garder uniquement les 20 dernières entrées par utilisateur
        $pdo->prepare(
            'DELETE FROM recent_searches
             WHERE user_id = :uid AND id NOT IN (
                SELECT id FROM (
                    SELECT id FROM recent_searches WHERE user_id = :uid2
                    ORDER BY last_used_at DESC LIMIT 20
                ) AS keep_set
             )'
        )->execute([':uid' => $userId, ':uid2' => $userId]);

        // Log facultatif
        if (function_exists('logActivity')) {
            logActivity($userId, 'recent_search_saved', $postal . ' ' . $city);
        }

        jsonSuccess(['saved' => true]);
    }

    if ($method === 'DELETE') {
        if (!empty($_GET['all'])) {
            $pdo->prepare('DELETE FROM recent_searches WHERE user_id = :uid')
                ->execute([':uid' => $userId]);
            jsonSuccess(['cleared' => true]);
        }
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) jsonError('Identifiant invalide.', 400);
        $stmt = $pdo->prepare('DELETE FROM recent_searches WHERE id = :id AND user_id = :uid');
        $stmt->execute([':id' => $id, ':uid' => $userId]);
        jsonSuccess(['deleted' => $stmt->rowCount() > 0]);
    }

    jsonError('Méthode non autorisée.', 405);

} catch (Throwable $e) {
    error_log('[recent_searches] ' . $e->getMessage());
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        jsonError('Erreur serveur: ' . $e->getMessage(), 500);
    }
    jsonError('Erreur serveur lors du traitement des recherches récentes.', 500);
}
