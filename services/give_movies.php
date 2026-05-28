<?php
// give_movies.php — повертає масив рекомендованих фільмів з відсотком метчу
// Підключається через include з index.php

require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../core/TMDb_api.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/spotify_service.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!$IS_LOGGED_IN) return [];

// -------------------------------------------------------
// MOVIE OF THE DAY — показуємо тільки при першому завантаженні,
// не при оновленні вайбу (refresh_recommendations)
// -------------------------------------------------------
// Логіка: index.php встановлює $_SESSION['vibe_refresh_count'] = 0 при першому
// get_recommendations і збільшує на 1 при кожному refresh_recommendations.
// Тут ми просто повертаємо прапорець — index.php сам вирішує, чи рендерити блок.
$isFirstVibe = ($_SESSION['vibe_refresh_count'] ?? 0) === 0;

// Отримуємо дійсний токен
$accessToken = get_valid_spotify_token($pdo, $CURRENT_USER_ID);
if (!$accessToken) return [];

// --- Жанри з кешу або Spotify ---
if (empty($_SESSION['user_genres'])) {
    $data = get_spotify_top_items($accessToken, 'artists');
    if (isset($data['error'])) return [];
    if (empty($data)) return [];

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
// GENRE MAP: Spotify → TMDb genre IDs
//
// RECOMMENDATION: Keep this map maintained — it's the core
// of your matching quality. A few tips:
//
// 1. TMDb genre IDs that matter most:
//    28=Action, 12=Adventure, 35=Comedy, 18=Drama, 27=Horror,
//    878=Sci-Fi, 9648=Mystery, 53=Thriller, 10749=Romance,
//    14=Fantasy, 36=History, 80=Crime, 10402=Music,
//    99=Documentary, 10752=War, 37=Western, 10751=Family
//
// 2. Prefer 2–3 genre IDs per Spotify genre, not more.
//    Too many dilutes the weight calculation below.
//
// 3. Match the *mood* of the music, not just the literal genre.
//    e.g. dark ambient → Horror + Mystery (27, 9648), not Documentary.
//
// 4. If a Spotify genre isn't in this map, it silently gets ignored.
//    Check your logs — if users listen to genres that keep missing,
//    add them here.
// -------------------------------------------------------
$genreMap = [
    // Pop / Dance
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

    // Hip-hop / Rap
    'hip-hop'              => [80, 53],
    'trap'                 => [53, 80],
    'mainstream rap'       => [53, 80],
    'lo-fi hip-hop'        => [18, 10749],
    'chillhop'             => [18, 10749],
    'cloud rap'            => [14, 9648],
    'experimental hip hop' => [99, 9648],
    'noise rap'            => [27, 9648],
    'industrial hip-hop'   => [27, 53],

    // R&B / Soul
    'r&b'                  => [10749, 18],
    'neo-soul'             => [10749, 18],
    'soul jazz'            => [10749, 18],

    // Rock
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

    // Metal
    'metal'                => [27, 53],
    'nu metal'             => [53, 27],

    // Electronic / Dance
    'edm'                  => [878, 10402],
    'house'                => [10402, 35],
    'techno'               => [878, 9648],
    'trance'               => [878, 14],
    'synthwave'            => [878, 14],
    'retrowave'            => [878, 14],
    'vaporwave'            => [10402, 878],
    'electro funk'         => [878, 10402],
    'tropical house'       => [10751, 10402],

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

    // Broad catch-all labels Spotify sometimes returns
    'experimental'         => [99, 9648],   // avant-garde → Documentary + Mystery
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

// -------------------------------------------------------
// GENRE WEIGHT CALCULATION
//
// Top-ranked Spotify genre gets weight 5, second gets 4, etc.
// This means a film that matches your #1 genre scores much
// higher than one matching your #5 genre.
// -------------------------------------------------------
$tmdbGenreWeights = [];
$currentWeight    = 5;
$triggerGenresMap = [];

foreach ($userGenres as $g) {
    $gLower = mb_strtolower(trim($g));
    if (isset($genreMap[$gLower])) {
        foreach ($genreMap[$gLower] as $tmdbId) {
            $tmdbGenreWeights[$tmdbId] = ($tmdbGenreWeights[$tmdbId] ?? 0) + $currentWeight;
            $triggerGenresMap[$tmdbId] = $g;
        }
    } else {
        // Fuzzy fallback: if Spotify returns a genre we don't have mapped
        // (e.g. "vapor twitch", "escape room"), try to match on partial keywords
        // so we never silently discard a user's top genre.
        $fallbackIds = null;
        if (str_contains($gLower, 'hop') || str_contains($gLower, 'rap'))        $fallbackIds = [53, 80];
        elseif (str_contains($gLower, 'pop'))                                     $fallbackIds = [35, 10402];
        elseif (str_contains($gLower, 'rock') || str_contains($gLower, 'punk'))  $fallbackIds = [28, 53];
        elseif (str_contains($gLower, 'metal') || str_contains($gLower, 'core')) $fallbackIds = [27, 53];
        elseif (str_contains($gLower, 'jazz') || str_contains($gLower, 'soul'))  $fallbackIds = [10402, 18];
        elseif (str_contains($gLower, 'folk') || str_contains($gLower, 'acoustic')) $fallbackIds = [18, 36];
        elseif (str_contains($gLower, 'ambient') || str_contains($gLower, 'drone')) $fallbackIds = [9648, 18];
        elseif (str_contains($gLower, 'electro') || str_contains($gLower, 'synth') || str_contains($gLower, 'wave')) $fallbackIds = [878, 10402];
        elseif (str_contains($gLower, 'experimental') || str_contains($gLower, 'avant')) $fallbackIds = [99, 9648];
        elseif (str_contains($gLower, 'indie') || str_contains($gLower, 'alternative')) $fallbackIds = [18, 9648];

        if ($fallbackIds) {
            foreach ($fallbackIds as $tmdbId) {
                $tmdbGenreWeights[$tmdbId] = ($tmdbGenreWeights[$tmdbId] ?? 0) + $currentWeight;
                $triggerGenresMap[$tmdbId] = $g;
            }
            error_log("give_movies: fuzzy fallback used for unmapped genre '$g'");
        } else {
            error_log("give_movies: no mapping found for genre '$g' — add it to \$genreMap");
        }
    }
    $currentWeight--;
    if ($currentWeight <= 0) break;
}

$tmdbGenres = array_keys($tmdbGenreWeights);
if (empty($tmdbGenres)) return [];

// -------------------------------------------------------
// SMART DB CHECK — only fetch from TMDb when we're running low
// -------------------------------------------------------
$alreadyShown = $_SESSION['shown_movie_ids'] ?? [];
$allMovies    = $pdo->query("SELECT * FROM movies ORDER BY popularity DESC, id DESC")->fetchAll();

$unseenGoodMovies = 0;
foreach ($allMovies as $m) {
    if (in_array($m['id'], $alreadyShown)) continue;
    $movieGenres = array_map('intval', explode(',', $m['genre'] ?? ''));
    foreach ($movieGenres as $mg) {
        if (isset($tmdbGenreWeights[$mg])) {
            $unseenGoodMovies++;
            break;
        }
    }
}

// Fetch fresh movies from TMDb only when unseen pool is low
if ($unseenGoodMovies < 15) {

    // KEY INSIGHT: Never make per-movie detail API calls during bulk fetch.
    // The discover endpoint already returns title, overview, poster, genres,
    // and popularity for all 20 movies in a single request.
    // We use those directly — saving up to 40 extra API calls per page.
    //
    // TMDb's with_genres uses AND logic with multiple IDs (film must match ALL).
    // Instead we query each genre separately and merge — effective OR behavior.
    arsort($tmdbGenreWeights);
    $topGenreIds = array_slice(array_keys($tmdbGenreWeights), 0, 3);

    if (!isset($_SESSION['tmdb_current_page'])) {
        $_SESSION['tmdb_current_page'] = 1;
    }
    $startPage    = $_SESSION['tmdb_current_page'];
    $pagesToFetch = ($startPage === 1) ? 3 : 2;

    $seenTmdbIds = [];
    $allResults  = [];

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

        // Use discover-level data directly — no extra API calls.
        // $movie already contains: title (uk-UA), overview (uk-UA), poster_path,
        // genre_ids, popularity — everything we need to store and score.
        $genreStr   = isset($movie['genre_ids']) ? implode(',', $movie['genre_ids']) : '';
        $posterPath = $movie['poster_path'] ?? '';
        $imageUrl   = !empty($posterPath) ? tmdb_get_poster_url($posterPath) : null;
        $popularity = (float)($movie['popularity'] ?? 0);

        // The discover endpoint returns the overview in uk-UA (because we pass language=uk-UA).
        // If TMDb has no Ukrainian translation it returns English — we detect this below
        // by fetching English separately ONLY to store description_en, not to replace description.
        // We make this extra call only when the discover overview is non-empty,
        // so we always have an English fallback for movie detail pages.
        $descUk = trim($movie['overview'] ?? '');

        // Fetch English description — one call per movie, not two.
        // We skip the uk-UA detail call entirely (discover already gave us that data).
        $enDetails  = tmdb_api_request("/movie/{$movie['id']}", ['language' => 'en-US']);
        $descEn     = trim($enDetails['overview'] ?? '');

        // Detect TMDb auto-fallback: if uk overview === en overview, TMDb had no translation.
        // In that case store empty string so the UI knows to show the English version.
        if ($descUk === $descEn) $descUk = '';

        // Title: discover already returns uk-UA title. Use en fallback only if blank.
        $title = !empty($movie['title'])
            ? $movie['title']
            : ($enDetails['title'] ?? 'Без назви');

        try {
            $pdo->prepare(
                "INSERT INTO movies (title, genre, description, description_en, image_url, imdb_url, tmdb_id, popularity)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([$title, $genreStr, $descUk, $descEn, $imageUrl, null, $movie['id'], $popularity]);
        } catch (PDOException $e) {
            continue;
        }
    }

    $allMovies = $pdo->query("SELECT * FROM movies ORDER BY popularity DESC, id DESC")->fetchAll();
}

// -------------------------------------------------------
// SCORING & FILTERING
//
// Match score = genre overlap score (weighted by Spotify rank)
// Popularity boost = blended in to prefer well-known films
// Freshness boost = small bonus for recently added films
//
// Why not just sort by popularity alone?
// Because a super popular Action film is useless to someone
// whose top genre maps to Romance + Drama. Genre relevance
// must stay the primary signal; popularity is a tiebreaker.
// -------------------------------------------------------
$maxPossibleScore = array_sum($tmdbGenreWeights) ?: 1;
$now              = time();

$processMovies = function(array $moviesList) use (
    $alreadyShown,
    $tmdbGenreWeights,
    $maxPossibleScore,
    $triggerGenresMap,
    $now
): array {
    $resultList = [];

    foreach ($moviesList as $movie) {
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

        // Base genre match percentage
        $matchPercent = round(($movieScore / $maxPossibleScore) * 100);

        // Popularity boost: normalize TMDb popularity to 0–20 bonus points.
        // TMDb popularity typically ranges 0–500+ for mainstream films.
        // We cap at 200 so mega-blockbusters don't completely override niche matches.
        $popularityRaw   = (float)($movie['popularity'] ?? 0);
        $popularityBonus = round(min($popularityRaw, 200) / 200 * 20); // 0–20 pts

        // Freshness bonus: reward recently added films
        $addedAt        = strtotime($movie['created_at'] ?? '');
        $daysSinceAdded = $addedAt ? (($now - $addedAt) / 86400) : 999;
        $freshnessBonus = match(true) {
            $daysSinceAdded < 7  => 10,
            $daysSinceAdded < 30 => 5,
            default              => 0,
        };

        // Cosmetic floor/ceil: always show at least 65%, never claim 100%
        $movie['match_percent']  = min(max($matchPercent + $popularityBonus + $freshnessBonus + 30, 65), 99);
        $movie['trigger_genre']  = $primaryTrigger;
        $movie['_raw_score']     = $matchPercent + $popularityBonus; // for sorting, without cosmetics
        $resultList[] = $movie;
    }

    // Sort by raw score (genre relevance + popularity), not cosmetic percent
    usort($resultList, fn($a, $b) => $b['_raw_score'] <=> $a['_raw_score']);
    return $resultList;
};

$filtered = $processMovies($allMovies);

// Reset seen list if we've exhausted everything
if (empty($filtered)) {
    $_SESSION['shown_movie_ids'] = [];
    $filtered = $processMovies($allMovies);
}

// Shuffle top 30 for variety, then take 10
// This prevents the same top films appearing every single refresh
// while still keeping quality high (we never go below rank 30)
$top30 = array_slice($filtered, 0, 30);
shuffle($top30);
$top = array_slice($top30, 0, 10);

// Attach movie-of-the-day flag for index.php to use
// Only the first vibe generation gets it; refreshes do not
$_SESSION['show_movie_of_day'] = $isFirstVibe;

$_SESSION['last_movies'] = $top;
return $top;