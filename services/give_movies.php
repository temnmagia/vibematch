<?php
// services/give_movies.php
// Main recommendation engine. Flow:
//   1. Get Spotify token + top genres
//   2. Build genre weights
//   3. If local DB is too thin → fetch from TMDb to stock it
//   4. Score everything in local DB → $filtered (sorted, session-deduped)
//   5. Pass $filtered to daily_vibe_service.php
//      → daily not exhausted: return today's 15 films (slice of $filtered)
//      → daily exhausted:     return all-time top picks (shuffle of $filtered)

require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../core/TMDb_api.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/spotify_service.php';
require_once __DIR__ . '/daily_vibe_service.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!$IS_LOGGED_IN) return [];

$isFirstVibe = ($_SESSION['vibe_refresh_count'] ?? 0) === 0;

// -------------------------------------------------------
// STEP 1 — Spotify token + all-time top genres
// Cached in session so we don't hit Spotify on every refresh.
// -------------------------------------------------------
$accessToken = get_valid_spotify_token($pdo, $CURRENT_USER_ID);
if (!$accessToken) return [];

if (empty($_SESSION['user_genres'])) {
    $data = get_spotify_top_items($accessToken, 'artists');
    if (isset($data['error']) || empty($data)) return [];

    $allGenres = [];
    foreach ($data as $artist) {
        foreach ($artist['genres'] as $genre) {
            $allGenres[] = $genre;
        }
    }
    $counts = array_count_values($allGenres);
    arsort($counts);
    $_SESSION['user_genres'] = array_slice(array_keys($counts), 0, 5);
}

$userGenres = $_SESSION['user_genres'] ?? [];
if (empty($userGenres)) return [];

// -------------------------------------------------------
// STEP 2 — Genre map + weight calculation
//
// TMDb genre IDs reference:
//   28=Action  12=Adventure  35=Comedy    18=Drama     27=Horror
//   878=Sci-Fi 9648=Mystery  53=Thriller  10749=Romance 14=Fantasy
//   36=History 80=Crime      10402=Music  99=Documentary
//   10752=War  37=Western    10751=Family
//
// Rules:
//   - 2-3 TMDb IDs per Spotify genre max (more dilutes weights)
//   - Match the MOOD of the music, not the literal genre name
//   - Top Spotify genre gets weight 5, decreasing to 1
// -------------------------------------------------------
$genreMap = [
    // Pop / Dance / Ethereal
    'pop'                  => [35, 10402],
    'dance pop'            => [35, 10402],
    'synthpop'             => [10402, 14],
    'retro pop'            => [35, 10402],
    'experimental pop'     => [99, 9648],
    'art pop'              => [10402, 99],
    'hyperpop'             => [878, 35],
    'bubblegum bass'       => [878, 35],
    'idol pop'             => [35, 10402],
    'k-pop'                => [35, 10402],
    'j-pop'                => [35, 10402],
    'latin pop'            => [10749, 35],
    'country pop'          => [10749, 37],
    'dream pop'            => [14, 10749], // Fantasy, Romance
    'noise pop'            => [18, 9648],  // Drama, Mystery

    // Hip-hop / Rap / Cloud
    'hip-hop'              => [80, 53],
    'trap'                 => [53, 80],
    'mainstream rap'       => [53, 80],
    'lo-fi hip-hop'        => [18, 10749],
    'chillhop'             => [18, 10749],
    'cloud rap'            => [14, 9648],
    'experimental hip hop' => [99, 9648],
    'noise rap'            => [27, 9648],
    'industrial hip-hop'   => [27, 53],
    'drain'                => [14, 9648],  // Fantasy, Mystery
    'swedish hip hop'      => [80, 53],    // Crime, Thriller
    'emo rap'              => [18, 10749], // Drama, Romance
    'sad rap'              => [18, 10749], // Drama, Romance
    'glitchcore'           => [878, 9648], // Sci-Fi, Mystery

    // Phonk & Aggressive Electronic
    'phonk'                => [80, 28],    // Crime, Action
    'drift phonk'          => [28, 53],    // Action, Thriller
    'brazilian phonk'      => [28, 10402], // Action, Music

    // R&B / Soul
    'r&b'                  => [10749, 18],
    'neo-soul'             => [10749, 18],
    'soul jazz'            => [10749, 18],
    'dark r&b'             => [10749, 9648],

    // Rock / Shoegaze / Goth
    'rock'                 => [28, 53],
    'pop rock'             => [35, 12],
    'indie rock'           => [18, 9648],
    'alternative rock'     => [53, 9648],
    'post-grunge'          => [9648, 18],
    'emo'                  => [18, 10749],
    'hard rock'            => [28, 80],
    'classic rock'         => [36, 18],
    'punk rock'            => [80, 28],
    'post-punk'            => [9648, 80],
    'blues rock'           => [36, 18],
    'southern rock'        => [37, 80],
    'shoegaze'             => [18, 9648],  // Drama, Mystery (Мрійливий, меланхолійний вайб)
    'gothic rock'          => [27, 18],    // Horror, Drama (Театральність, темрява)
    'new wave'             => [878, 35],   // Sci-Fi, Comedy (Стиль 80-х)
    'post-rock'            => [18, 878],   // Drama, Sci-Fi

    // Metal
    'metal'                => [27, 53],
    'nu metal'             => [53, 27],

    // Electronic / Dance / Dark Synth
    'edm'                  => [878, 10402],
    'house'                => [10402, 35],
    'techno'               => [878, 9648],
    'trance'               => [878, 14],
    'synthwave'            => [878, 14],
    'retrowave'            => [878, 14],
    'vaporwave'            => [10402, 878],
    'electro funk'         => [878, 10402],
    'tropical house'       => [10751, 10402],
    'witch house'          => [27, 9648], 
    'electroclash'         => [878, 80],   // Sci-Fi, Crime (Брудна, кіберпанкова електроніка)
    'darkwave'             => [27, 878],   // Horror, Sci-Fi
    'coldwave'             => [9648, 18],  // Mystery, Drama
    'industrial'           => [27, 53],    // Horror, Thriller
    'ebm'                  => [878, 27],   // Sci-Fi, Horror
    'dark clubbing'        => [27, 80],    // Horror, Crime
    'breakcore'            => [878, 53],   // Sci-Fi, Thriller
    'deconstructed club'   => [9648, 878], // Mystery, Sci-Fi

    // Experimental / Avant-garde
    'glitch'               => [99, 9648],
    'glitch-hop'           => [9648, 18],
    'idm'                  => [9648, 99],
    'plunderphonics'       => [9648, 99],
    'musique concrète'     => [99, 18],
    'drone'                => [9648, 27],
    'minimal'              => [99, 18],
    'electroacoustic'      => [99, 10402],
    'free improv'          => [99, 18],
    'avant-garde jazz'     => [99, 9648],

    // Jazz
    'jazz'                 => [10402, 18],
    'smooth jazz'          => [10749, 18],

    // Folk / Dark / Ambient
    'neofolk'              => [99, 18],
    'dark folk'            => [9648, 27],
    'pagan folk'           => [14, 36],
    'nordic folk'          => [36, 10752],
    'drone folk'           => [99, 9648],
    'apocalyptic folk'     => [10752, 9648],
    'dark ambient'         => [27, 9648],
    'ritual ambient'       => [27, 99],
    'dungeon synth'        => [9648, 14],
    'fantasy ambient'      => [14, 99],
    'martial industrial'   => [10752, 53],
    'ethereal wave'        => [10749, 18],

    // Other
    'reggaeton'            => [35, 10751],
    'country'              => [37, 36],
    'funk'                 => [10402, 35],
    'disco'                => [10402, 35],
    'italo disco'          => [10402, 35],

    'rage rap'             => [28, 53],    // Action, Thriller (Максимально агресивний, качовий вайб)
    'tekno'                => [878, 53],   // Sci-Fi, Thriller (Жорстка, похмура електронна довбня)
    'japanese vgm'         => [16, 14],    // Animation, Fantasy (Аніме, фентезі, епік — класика відеоігор)
    'soundtrack'           => [18, 12],    // Drama, Adventure (Кінематографічний розмах)

    // Broad catch-alls Spotify sometimes returns
    'experimental'         => [99, 9648],
    'alternative'          => [18, 53],
    'indie'                => [18, 9648],
    'electronic'           => [878, 10402],
    'ambient'              => [9648, 18],
    'acoustic'             => [18, 10749],
    'classical'            => [10402, 18],
    'folk'                 => [18, 36],
    'blues'                => [18, 36],
    'gospel'               => [10402, 18],
    'reggae'               => [35, 10751],
];

$tmdbGenreWeights = [];
$triggerGenresMap = [];
$currentWeight    = 5;

foreach ($userGenres as $g) {
    $gLower = mb_strtolower(trim($g));

    if (isset($genreMap[$gLower])) {
        $ids = $genreMap[$gLower];
    } else {
        // Fuzzy keyword fallback so unrecognised genres never silently score zero
        $ids = null;
        if      (str_contains($gLower, 'hop')  || str_contains($gLower, 'rap'))          $ids = [53, 80];
        elseif  (str_contains($gLower, 'pop'))                                            $ids = [35, 10402];
        elseif  (str_contains($gLower, 'rock') || str_contains($gLower, 'punk'))         $ids = [28, 53];
        elseif  (str_contains($gLower, 'metal')|| str_contains($gLower, 'core'))         $ids = [27, 53];
        elseif  (str_contains($gLower, 'jazz') || str_contains($gLower, 'soul'))         $ids = [10402, 18];
        elseif  (str_contains($gLower, 'folk') || str_contains($gLower, 'acoustic'))     $ids = [18, 36];
        elseif  (str_contains($gLower, 'ambient')|| str_contains($gLower, 'drone'))      $ids = [9648, 18];
        elseif  (str_contains($gLower, 'electro')|| str_contains($gLower, 'synth')
              || str_contains($gLower, 'wave'))                                           $ids = [878, 10402];
        elseif  (str_contains($gLower, 'experimental')|| str_contains($gLower, 'avant')) $ids = [99, 9648];
        elseif  (str_contains($gLower, 'indie')|| str_contains($gLower, 'alternative'))  $ids = [18, 9648];

        if ($ids === null) {
            error_log("give_movies: no mapping for genre '$g' — add it to \$genreMap");
        }
    }

    if ($ids) {
        foreach ($ids as $tmdbId) {
            $tmdbGenreWeights[$tmdbId] = ($tmdbGenreWeights[$tmdbId] ?? 0) + $currentWeight;
            $triggerGenresMap[$tmdbId] = $g;
        }
    }

    $currentWeight--;
    if ($currentWeight <= 0) break;
}

if (empty($tmdbGenreWeights)) return [];

// -------------------------------------------------------
// STEP 3 — Stock the local DB from TMDb if needed
//
// We count how many movies in the DB match our genre weights
// AND haven't been shown this session. If that pool is < 15,
// we fetch more pages from TMDb.
//
// TMDb queries are per-genre (one ID at a time) to avoid AND
// logic that kills results for niche genre combos.
// -------------------------------------------------------
$alreadyShown = $_SESSION['shown_movie_ids'] ?? [];

// Cap session list so it doesn't bloat forever
if (count($alreadyShown) > 200) {
    $alreadyShown = array_slice($alreadyShown, -100);
    $_SESSION['shown_movie_ids'] = $alreadyShown;
}

$allMovies = $pdo->query("SELECT * FROM movies ORDER BY popularity DESC, id DESC")->fetchAll();

// Count unseen movies that match our genre weights
$unseenGoodCount = 0;
foreach ($allMovies as $m) {
    if (in_array($m['id'], $alreadyShown)) continue;
    $mGenres = array_map('intval', explode(',', $m['genre'] ?? ''));
    foreach ($mGenres as $mg) {
        if (isset($tmdbGenreWeights[$mg])) { $unseenGoodCount++; break; }
    }
}

if ($unseenGoodCount < 15) {
    arsort($tmdbGenreWeights);
    $topGenreIds  = array_slice(array_keys($tmdbGenreWeights), 0, 3);

    if (!isset($_SESSION['tmdb_current_page'])) $_SESSION['tmdb_current_page'] = 1;
    $startPage    = $_SESSION['tmdb_current_page'];
    $pagesToFetch = ($startPage === 1) ? 3 : 2;

    $seenTmdbIds = [];
    $allResults  = [];

    // Query each genre separately → OR behaviour, not AND
    foreach ($topGenreIds as $singleGenreId) {
        for ($page = $startPage; $page < $startPage + $pagesToFetch; $page++) {
            $response = tmdb_get_movies_by_genres([$singleGenreId], $page);
            $results  = $response['results'] ?? [];
            if (empty($results)) break;
            foreach ($results as $movie) {
                if (isset($seenTmdbIds[$movie['id']])) continue;
                $seenTmdbIds[$movie['id']] = true;
                $allResults[] = $movie;
            }
        }
    }

    $_SESSION['tmdb_current_page'] = $startPage + $pagesToFetch;
    if ($_SESSION['tmdb_current_page'] > 50) $_SESSION['tmdb_current_page'] = 1;

    foreach ($allResults as $movie) {
        $stmt = $pdo->prepare("SELECT id FROM movies WHERE tmdb_id = ?");
        $stmt->execute([$movie['id']]);
        if ($stmt->fetchColumn()) continue;

        $genreStr   = isset($movie['genre_ids']) ? implode(',', $movie['genre_ids']) : '';
        $posterPath = $movie['poster_path'] ?? '';
        $imageUrl   = !empty($posterPath) ? tmdb_get_poster_url($posterPath) : null;
        $popularity = (float)($movie['popularity'] ?? 0);
        $descUk     = trim($movie['overview'] ?? '');

        // One English detail call per movie — only to get description_en
        $enDetails = tmdb_api_request("/movie/{$movie['id']}", ['language' => 'en-US']);
        $descEn    = trim($enDetails['overview'] ?? '');

        // If TMDb returned English as the Ukrainian text (no translation exists),
        // store empty string so the UI can fall back gracefully
        if ($descUk === $descEn) $descUk = '';

        $title = !empty($movie['title'])
            ? $movie['title']
            : ($enDetails['title'] ?? 'Без назви');

        try {
            $pdo->prepare(
                "INSERT INTO movies
                    (title, genre, description, description_en, image_url, imdb_url, tmdb_id, popularity)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([$title, $genreStr, $descUk, $descEn, $imageUrl, null, $movie['id'], $popularity]);
        } catch (PDOException $e) {
            continue;
        }
    }

    // Reload after inserts
    $allMovies = $pdo->query("SELECT * FROM movies ORDER BY popularity DESC, id DESC")->fetchAll();
}

// -------------------------------------------------------
// STEP 4 — Score every movie in local DB
//
// $filtered is sorted by raw_score (genre relevance + popularity).
// This single scored list is reused by both the daily vibe path
// and the all-time fallback — no double queries, no double scoring.
// -------------------------------------------------------
$maxPossibleScore = array_sum($tmdbGenreWeights) ?: 1;
$now              = time();
$filtered         = [];

foreach ($allMovies as $movie) {
    if (in_array($movie['id'], $alreadyShown)) continue;

    $movieGenres    = array_map('intval', explode(',', $movie['genre'] ?? ''));
    $movieScore     = 0;
    $primaryTrigger = 'Музичний мікс';

    foreach ($movieGenres as $mg) {
        if (isset($tmdbGenreWeights[$mg])) {
            $movieScore     += $tmdbGenreWeights[$mg];
            $primaryTrigger  = $triggerGenresMap[$mg];
        }
    }

    if ($movieScore <= 0) continue;

    $matchPct    = round(($movieScore / $maxPossibleScore) * 100);
    $popBonus    = round(min((float)($movie['popularity'] ?? 0), 200) / 200 * 20);

    $addedAt     = strtotime($movie['created_at'] ?? '');
    $daysSince   = $addedAt ? (($now - $addedAt) / 86400) : 999;
    $freshBonus  = match(true) {
        $daysSince < 7  => 10,
        $daysSince < 30 => 5,
        default         => 0,
    };

    $movie['match_percent']  = min(max($matchPct + $popBonus + $freshBonus + 30, 65), 99);
    $movie['trigger_genre']  = $primaryTrigger;
    $movie['_raw_score']     = $matchPct + $popBonus; // sort key, no cosmetics
    $filtered[] = $movie;
}

usort($filtered, fn($a, $b) => $b['_raw_score'] <=> $a['_raw_score']);

// If everything has been shown this session, reset and rescore
if (empty($filtered)) {
    $_SESSION['shown_movie_ids'] = [];
    $alreadyShown = [];
    // Rescore without the shown filter
    foreach ($allMovies as $movie) {
        $movieGenres    = array_map('intval', explode(',', $movie['genre'] ?? ''));
        $movieScore     = 0;
        $primaryTrigger = 'Музичний мікс';
        foreach ($movieGenres as $mg) {
            if (isset($tmdbGenreWeights[$mg])) {
                $movieScore    += $tmdbGenreWeights[$mg];
                $primaryTrigger = $triggerGenresMap[$mg];
            }
        }
        if ($movieScore <= 0) continue;
        $matchPct   = round(($movieScore / $maxPossibleScore) * 100);
        $popBonus   = round(min((float)($movie['popularity'] ?? 0), 200) / 200 * 20);
        $movie['match_percent'] = min(max($matchPct + $popBonus + 30, 65), 99);
        $movie['trigger_genre'] = $primaryTrigger;
        $movie['_raw_score']    = $matchPct + $popBonus;
        $filtered[] = $movie;
    }
    usort($filtered, fn($a, $b) => $b['_raw_score'] <=> $a['_raw_score']);
}

// -------------------------------------------------------
// STEP 5 — Combine daily vibe + all-time fallback
//
// Daily vibe: 15 films locked for today, same every refresh.
// All-time:   top picks from $filtered that aren't already
//             in the daily batch, so no duplicates.
// Both are returned together — index.php renders them in order
// with a divider card between them so the user can scroll forever.
// -------------------------------------------------------
$dailyResult  = resolve_daily_vibe($pdo, $CURRENT_USER_ID, $accessToken, $filtered);
$dailyMovies  = $dailyResult['movies']; // up to 15, tagged is_daily=true
$dailyIds     = array_column($dailyMovies, 'id');

// All-time: everything scored that isn't in today's daily batch
$allTimePool  = array_filter($filtered, fn($m) => !in_array($m['id'], $dailyIds));
$allTimePool  = array_values($allTimePool);

// Shuffle top 40 of all-time pool for variety, take 15
$allTimeTop   = array_slice($allTimePool, 0, 40);
shuffle($allTimeTop);
$allTimeMovies = array_slice($allTimeTop, 0, 15);
foreach ($allTimeMovies as &$m) $m['is_daily'] = false;
unset($m);

// Insert a divider marker between the two sections
// index.php checks feed_item_type === 'alltime_divider' to render the separator card
$divider = ['feed_item_type' => 'alltime_divider', 'daily_genres' => $dailyResult['genres']];

// Final list: daily films first, then divider, then all-time
$top = array_merge($dailyMovies, [$divider], $allTimeMovies);

// Edge case: if daily was empty (DB still building), just show all-time
if (empty($dailyMovies)) {
    $top = $allTimeMovies;
}

$_SESSION['daily_genres']          = $dailyResult['genres'];
$_SESSION['seconds_until_midnight'] = seconds_until_midnight();
$_SESSION['show_movie_of_day']      = $isFirstVibe;
$_SESSION['last_movies']            = $top;

return $top;