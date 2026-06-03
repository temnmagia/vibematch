<?php
// services/daily_vibe_service.php
// Simple daily vibe — 15 films per day, picked once, reset at midnight.
// No swipe tracking, no seen counting, no AJAX.
//
// -------------------------------------------------------
// SQL — run once:
// CREATE TABLE IF NOT EXISTS daily_vibes (
//     id         INT AUTO_INCREMENT PRIMARY KEY,
//     user_id    INT NOT NULL,
//     vibe_date  DATE NOT NULL,
//     genres     JSON NOT NULL,
//     movie_ids  JSON NOT NULL,
//     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
//     UNIQUE KEY unique_user_date (user_id, vibe_date)
// );
// -------------------------------------------------------

const DAILY_LIMIT = 15;

// -------------------------------------------------------
// resolve_daily_vibe()
// Called by give_movies.php with the already-scored $filtered list.
// - First call of the day: picks 15 IDs from $filtered, saves to DB
// - Subsequent calls today: loads same 15 from DB
// Returns the 15 movie rows (with match_percent, trigger_genre etc.)
// -------------------------------------------------------
function resolve_daily_vibe(PDO $pdo, int $userId, string $accessToken, array $filtered): array {
    $today = date('Y-m-d');

    $stmt = $pdo->prepare("SELECT * FROM daily_vibes WHERE user_id = ? AND vibe_date = ?");
    $stmt->execute([$userId, $today]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        // First visit today — pick 15 from the scored list and lock them in
        $genres   = get_today_genres($accessToken);
        $movieIds = pick_from_scored($filtered);

        $pdo->prepare(
            "INSERT INTO daily_vibes (user_id, vibe_date, genres, movie_ids)
             VALUES (?, ?, ?, ?)"
        )->execute([$userId, $today, json_encode($genres), json_encode($movieIds)]);

        $row = ['genres' => json_encode($genres), 'movie_ids' => json_encode($movieIds)];
    }

    $movieIds = json_decode($row['movie_ids'], true) ?? [];
    $genres   = json_decode($row['genres'],    true) ?? [];

    // Hydrate movie rows from $filtered (no extra DB query needed)
    $index  = array_column($filtered, null, 'id');
    $movies = [];
    foreach ($movieIds as $id) {
        if (isset($index[$id])) {
            $index[$id]['is_daily'] = true;
            $movies[] = $index[$id];
        }
    }

    return [
        'movies' => $movies,
        'genres' => $genres,
    ];
}

// -------------------------------------------------------
// pick_from_scored()
// Shuffles the top 30 of $filtered for variety,
// returns 15 IDs. Called only on first visit of the day.
// -------------------------------------------------------
function pick_from_scored(array $filtered): array {
    $top30 = array_slice($filtered, 0, 30);
    shuffle($top30);
    return array_column(array_slice($top30, 0, DAILY_LIMIT), 'id');
}

// -------------------------------------------------------
// get_today_genres()
// Grabs artist genres from tracks played TODAY on Spotify.
// Falls back to short-term top artists if nothing played yet.
// Only called when creating a new daily row.
// -------------------------------------------------------
function get_today_genres(string $accessToken): array {
    $ch = curl_init('https://api.spotify.com/v1/me/player/recently-played?limit=50');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $accessToken"],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $data = json_decode(curl_exec($ch), true) ?? [];
    curl_close($ch);

    $today     = date('Y-m-d');
    $artistIds = [];
    foreach ($data['items'] ?? [] as $item) {
        if (substr($item['played_at'] ?? '', 0, 10) !== $today) continue;
        foreach ($item['track']['artists'] ?? [] as $a) {
            if (!empty($a['id'])) $artistIds[$a['id']] = true;
        }
    }

    if (empty($artistIds)) return get_today_genres_fallback($accessToken);

    $ch2 = curl_init('https://api.spotify.com/v1/artists?ids=' . implode(',', array_keys($artistIds)));
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $accessToken"],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $artistData = json_decode(curl_exec($ch2), true) ?? [];
    curl_close($ch2);

    $allGenres = [];
    foreach ($artistData['artists'] ?? [] as $artist) {
        foreach ($artist['genres'] ?? [] as $g) $allGenres[] = $g;
    }

    if (empty($allGenres)) return get_today_genres_fallback($accessToken);

    $counts = array_count_values($allGenres);
    arsort($counts);
    return array_slice(array_keys($counts), 0, 5);
}

function get_today_genres_fallback(string $accessToken): array {
    $ch = curl_init('https://api.spotify.com/v1/me/top/artists?limit=20&time_range=short_term');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $accessToken"],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $data = json_decode(curl_exec($ch), true) ?? [];
    curl_close($ch);

    $allGenres = [];
    foreach ($data['items'] ?? [] as $artist) {
        foreach ($artist['genres'] ?? [] as $g) $allGenres[] = $g;
    }

    $counts = array_count_values($allGenres);
    arsort($counts);
    return array_slice(array_keys($counts), 0, 5);
}

// -------------------------------------------------------
// seconds_until_midnight()
// Used by index.php to show a countdown in the UI.
// -------------------------------------------------------
function seconds_until_midnight(): int {
    return max(0, mktime(0, 0, 0, (int)date('m'), (int)date('d') + 1, (int)date('Y')) - time());
}