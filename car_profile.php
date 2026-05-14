<?php
/**
 * CarbuFrance - API du profil voiture
 *
 * Endpoints:
 *   GET    /api/car_profile.php          - Récupère le profil voiture par défaut
 *   POST   /api/car_profile.php          - Crée ou met à jour le profil par défaut
 *   DELETE /api/car_profile.php          - Supprime le profil par défaut
 *
 * Un utilisateur peut avoir plusieurs voitures (table car_profiles), mais
 * pour la première version on expose seulement le profil par défaut (is_default=1).
 */

require_once __DIR__ . '/dbconfig.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        getProfile();
        break;
    case 'POST':
    case 'PUT':
        saveProfile();
        break;
    case 'DELETE':
        deleteProfile();
        break;
    default:
        jsonError('Méthode non supportée', 405);
}

// ─────────────────────────────────────────────────────────────────────────────

function getProfile() {
    $session = requireAuth();
    $pdo = getDB();

    $stmt = $pdo->prepare('
        SELECT id, label, brand, model, fuel_type, consumption, tank_capacity, is_default, updated_at
        FROM car_profiles
        WHERE user_id = ? AND is_default = 1
        ORDER BY updated_at DESC
        LIMIT 1
    ');
    $stmt->execute([$session['user_id']]);
    $profile = $stmt->fetch();

    jsonSuccess([
        'profile' => $profile ?: null,
        'hasProfile' => (bool)$profile,
    ]);
}

function saveProfile() {
    $session = requireAuth();
    $input = getRequestBodyJson();

    $label        = trim($input['label'] ?? 'Ma voiture');
    $brand        = trim($input['brand'] ?? '');
    $model        = trim($input['model'] ?? '');
    $fuelType     = trim($input['fuel_type'] ?? 'gazole');
    $consumption  = (float)($input['consumption'] ?? 6.5);
    $tankCapacity = isset($input['tank_capacity']) && $input['tank_capacity'] !== '' ? (int)$input['tank_capacity'] : null;

    // Validation
    $allowedFuels = ['gazole','sp95','sp98','e10','e85','gplc'];
    if (!in_array($fuelType, $allowedFuels, true)) {
        jsonError('Type de carburant invalide');
    }
    if ($consumption <= 0 || $consumption > 50) {
        jsonError('Consommation invalide (entre 0.1 et 50 L/100km)');
    }
    if ($tankCapacity !== null && ($tankCapacity < 1 || $tankCapacity > 500)) {
        jsonError('Capacité du réservoir invalide');
    }
    if (strlen($label) > 50) $label = substr($label, 0, 50);

    $pdo = getDB();

    // Vérifier si profil existe déjà
    $stmt = $pdo->prepare('SELECT id FROM car_profiles WHERE user_id = ? AND is_default = 1 LIMIT 1');
    $stmt->execute([$session['user_id']]);
    $existing = $stmt->fetch();

    if ($existing) {
        $stmt = $pdo->prepare('
            UPDATE car_profiles
            SET label = ?, brand = ?, model = ?, fuel_type = ?, consumption = ?, tank_capacity = ?
            WHERE id = ? AND user_id = ?
        ');
        $stmt->execute([$label, $brand, $model, $fuelType, $consumption, $tankCapacity, $existing['id'], $session['user_id']]);
        $profileId = $existing['id'];
        logActivity($session['user_id'], 'car_profile_update', "Profil voiture mis à jour ($brand $model)");
    } else {
        $stmt = $pdo->prepare('
            INSERT INTO car_profiles (user_id, label, brand, model, fuel_type, consumption, tank_capacity, is_default)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1)
        ');
        $stmt->execute([$session['user_id'], $label, $brand, $model, $fuelType, $consumption, $tankCapacity]);
        $profileId = $pdo->lastInsertId();
        logActivity($session['user_id'], 'car_profile_create', "Profil voiture créé ($brand $model)");
    }

    // Renvoyer le profil à jour
    $stmt = $pdo->prepare('SELECT id, label, brand, model, fuel_type, consumption, tank_capacity, is_default, updated_at FROM car_profiles WHERE id = ?');
    $stmt->execute([$profileId]);
    jsonSuccess(['profile' => $stmt->fetch()], 'Profil enregistré');
}

function deleteProfile() {
    $session = requireAuth();
    $pdo = getDB();

    $stmt = $pdo->prepare('DELETE FROM car_profiles WHERE user_id = ? AND is_default = 1');
    $stmt->execute([$session['user_id']]);
    $removed = $stmt->rowCount() > 0;

    if ($removed) {
        logActivity($session['user_id'], 'car_profile_delete', 'Profil voiture supprimé');
    }

    jsonSuccess(['removed' => $removed], $removed ? 'Profil supprimé' : 'Aucun profil à supprimer');
}
