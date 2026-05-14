<?php
/**
 * CarbuFrance - API dashboard utilisateur
 *
 * GET /api/dashboard.php
 * Retourne toutes les statistiques pertinentes pour l'utilisateur connecté.
 */

require_once __DIR__ . '/dbconfig.php';

$session = requireAuth();
$pdo = getDB();
$userId = $session['user_id'];

// ─── 1. Compteurs basiques
$stmt = $pdo->prepare('SELECT COUNT(*) FROM favorites WHERE user_id = ?');
$stmt->execute([$userId]);
$favoritesCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM search_history WHERE user_id = ?');
$stmt->execute([$userId]);
$searchesCount = (int)$stmt->fetchColumn();

// ─── 2. Dernières recherches (5)
$stmt = $pdo->prepare('
    SELECT query, fuel_type, radius_km, results_count, created_at
    FROM search_history
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 5
');
$stmt->execute([$userId]);
$recentSearches = $stmt->fetchAll();

// ─── 3. Top des enseignes favorites (regroupées par marque)
$stmt = $pdo->prepare('
    SELECT brand, COUNT(*) AS n
    FROM favorites
    WHERE user_id = ? AND brand IS NOT NULL AND brand <> ""
    GROUP BY brand
    ORDER BY n DESC
    LIMIT 3
');
$stmt->execute([$userId]);
$topBrands = $stmt->fetchAll();

// ─── 4. Prix moyen des favoris (snapshot du dernier ajout, par carburant)
$stmt = $pdo->prepare('
    SELECT price_snapshot_fuel AS fuel_type,
           AVG(price_snapshot) AS avg_price,
           MIN(price_snapshot) AS min_price,
           MAX(price_snapshot) AS max_price,
           COUNT(*)            AS n
    FROM favorites
    WHERE user_id = ? AND price_snapshot IS NOT NULL
    GROUP BY price_snapshot_fuel
');
$stmt->execute([$userId]);
$favoritePriceStats = $stmt->fetchAll();

// ─── 5. Profil voiture
$stmt = $pdo->prepare('
    SELECT id, label, brand, model, fuel_type, consumption, tank_capacity
    FROM car_profiles
    WHERE user_id = ? AND is_default = 1
    LIMIT 1
');
$stmt->execute([$userId]);
$carProfile = $stmt->fetch();

// ─── 6. Économies estimées
//      Hypothèse : à chaque ajout de favori on stocke un snapshot du prix.
//      L'économie = (prix max favoris - prix min favoris) * conso pour 1 plein
//      Heuristique simple, suffisante pour donner un ordre de grandeur.
$savings = null;
if ($carProfile && !empty($favoritePriceStats)) {
    // Trouver les stats pour le carburant de la voiture
    $stat = null;
    foreach ($favoritePriceStats as $s) {
        if ($s['fuel_type'] === $carProfile['fuel_type']) { $stat = $s; break; }
    }
    if ($stat && $stat['n'] >= 2) {
        $tank = (int)($carProfile['tank_capacity'] ?? 50);
        $deltaPerLiter = (float)$stat['max_price'] - (float)$stat['min_price'];
        $savings = [
            'per_full_tank' => round($deltaPerLiter * $tank, 2),
            'per_liter'     => round($deltaPerLiter, 3),
            'tank_size'     => $tank,
            'fuel'          => $carProfile['fuel_type'],
        ];
    }
}

// ─── 7. Activité récente (5)
$stmt = $pdo->prepare('
    SELECT action, details, created_at
    FROM activity_logs
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 5
');
$stmt->execute([$userId]);
$recentActivity = $stmt->fetchAll();

jsonSuccess([
    'user' => [
        'id'         => $session['user_id'],
        'username'   => $session['username'],
        'email'      => $session['email'],
        'created_at' => $session['created_at'] ?? null,
    ],
    'counts' => [
        'favorites' => $favoritesCount,
        'searches'  => $searchesCount,
    ],
    'recent_searches'      => $recentSearches,
    'top_brands'           => $topBrands,
    'favorite_price_stats' => $favoritePriceStats,
    'car_profile'          => $carProfile ?: null,
    'savings'              => $savings,
    'recent_activity'      => $recentActivity,
]);
