<?php
// give_movies.php — повертає масив рекомендованих фільмів з відсотком метчу
// Підключається через include з index.php

// Оновлюємо шляхи, бо файли переїхали в папки core та config
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../core/TMDb_api.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/spotify_service.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!$IS_LOGGED_IN) return [];


// Отримуємо дійсний токен
$accessToken = get_valid_spotify_token($pdo, $CURRENT_USER_ID);

if (!$accessToken) return [];

// --- Жанри з кешу або Spotify ---
if (empty($_SESSION['user_genres'])) {
    $data = get_spotify_top_items($accessToken, 'artists');

    // Якщо раптом токен протух саме в цю секунду (error від нашого сервісу)
    if (isset($data['error'])) {
        // Примусове оновлення можна було б зробити, але get_valid_spotify_token вже робить запас.
        return [];
    }

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
// РОЗШИРЕНА МАПА ЖАНРІВ: Spotify → TMDb genre IDs
// -------------------------------------------------------
$genreMap = [
    'pop'                  => [35, 10402],
    'dance pop'            => [35, 10402],
    'synthpop'             => [10402, 14],
    'hip-hop'              => [80, 53],
    'trap'                 => [53, 80],
    'mainstream rap'       => [53, 80],
    'r&b'                  => [10749, 18],
    'experimental hip hop' => [99, 9648, 18, 10402],
    'noise rap'            => [27, 9648],
    'industrial hip-hop'   => [27, 53],
    'lo-fi hip-hop'        => [18, 10749],
    'chillhop'             => [18, 10749],
    'cloud rap'            => [14, 9648],
    'rock'                 => [28, 35],
    'pop rock'             => [35, 12],
    'indie rock'           => [18, 35],
    'alternative rock'     => [53, 9648],
    'post-grunge'          => [9648, 18],
    'emo'                  => [18, 10749],
    'hard rock'            => [28, 80],
    'metal'                => [27, 53],
    'nu metal'             => [53, 27],
    'classic rock'         => [36, 18],
    'punk rock'            => [80, 28],
    'post-punk'            => [9648, 80],
    'edm'                  => [878, 10402],
    'house'                => [10402, 35],
    'techno'               => [878, 9648],
    'trance'               => [878, 14],
    'glitch'               => [99, 9648],
    'glitch-hop'           => [9648, 18],
    'idm'                  => [9648, 99],
    'vaporwave'            => [10402, 878],
    'synthwave'            => [878, 14],
    'retrowave'            => [878, 14],
    'jazz'                 => [10402, 18],
    'avant-garde jazz'     => [99, 9648],
    'free improv'          => [99, 18],
    'soul jazz'            => [10749, 18],
    'smooth jazz'          => [10749, 18],
    'neofolk'              => [99, 18],
    'apocalyptic folk'     => [10752, 9648],
    'martial industrial'   => [10752, 53],
    'dark ambient'         => [27, 9648],
    'ritual ambient'       => [27, 99],
    'drone folk'           => [99, 9648],
    'pagan folk'           => [14, 36],
    'nordic folk'          => [36, 10752],
    'dungeon synth'        => [9648, 14],
    'fantasy ambient'      => [14, 99],
    'dark folk'            => [9648, 27],
    'ethereal wave'        => [10749, 18],
    'reggaeton'            => [35, 10751],
    'latin pop'            => [10749, 35],
    'tropical house'       => [10751, 10402],
    'country'              => [37, 36],
    'country pop'          => [10749, 37],
    'k-pop'                => [35, 10402],
    'j-pop'                => [35, 10402],
    'idol pop'             => [35, 10402],
    'experimental pop'     => [99, 9648],
    'art pop'              => [10402, 99],
    'plunderphonics'       => [9648, 99],
    'musique concrète'     => [99, 18],
    'drone'                => [9648, 27],
    'minimal'              => [99, 18],
    'electroacoustic'      => [99, 10402],
    'funk'                 => [10402, 35],
    'neo-soul'             => [10749, 18],
    'retro pop'            => [35, 10402],
    'disco'                => [10402, 35],
    'italo disco'          => [10402, 35],
    'electro funk'         => [878, 10402],
    'blues rock'           => [36, 18],
    'southern rock'        => [37, 80]
];

// -------------------------------------------------------
// ПОКРАЩЕНИЙ АЛГОРИТМ: Розрахунок ваги для кожного жанру TMDb
// -------------------------------------------------------
$tmdbGenreWeights = [];
$currentWeight = 5; // ТОП-1 жанр отримує вагу 5, ТОП-2 — вагу 4 і т.д.
$triggerGenresMap = []; // Запам'ятовуємо, яка саме музика викликала цей кіножанр

foreach ($userGenres as $g) {
    $gLower = mb_strtolower(trim($g));
    if (isset($genreMap[$gLower])) {
        foreach ($genreMap[$gLower] as $tmdbId) {
            if (!isset($tmdbGenreWeights[$tmdbId])) {
                $tmdbGenreWeights[$tmdbId] = 0;
            }
            $tmdbGenreWeights[$tmdbId] += $currentWeight;
            $triggerGenresMap[$tmdbId] = $g; // Для контексту в картці
        }
    }
    $currentWeight--;
    if ($currentWeight <= 0) break;
}

$tmdbGenres = array_keys($tmdbGenreWeights);
if (empty($tmdbGenres)) return [];

// --- Завантажуємо фільми з TMDb та зберігаємо в БД ---
for ($page = 1; $page <= 2; $page++) {
    $response = tmdb_get_movies_by_genres($tmdbGenres, $page);
    $results  = $response['results'] ?? [];
    if (empty($results)) break;

    foreach ($results as $movie) {
        $stmt = $pdo->prepare("SELECT id FROM movies WHERE tmdb_id = ?");
        $stmt->execute([$movie['id']]);
        if ($stmt->fetchColumn()) continue;

        $genreStr  = isset($movie['genre_ids']) ? implode(',', $movie['genre_ids']) : '';
        $imageUrl  = !empty($movie['poster_path']) ? tmdb_get_poster_url($movie['poster_path']) : null;
        $imdbId    = tmdb_get_imdb_id($movie['id']);
        $imdbUrl   = $imdbId ? "https://www.imdb.com/title/$imdbId" : null;

        $ukDetails = tmdb_api_request("/movie/{$movie['id']}", ['language' => 'uk-UA']);
        $enDetails = tmdb_api_request("/movie/{$movie['id']}", ['language' => 'en-US']);

        $pdo->prepare(
            "INSERT INTO movies (title, genre, description, description_en, image_url, imdb_url, tmdb_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $movie['title'],
            $genreStr,
            $ukDetails['overview'] ?? '',
            $enDetails['overview'] ?? '',
            $imageUrl,
            $imdbUrl,
            $movie['id'],
        ]);
    }
    if ($page >= ($response['total_pages'] ?? 1)) break;
}

// --- Фільтрація та математика розрахунку відсотків (Match Score) ---
$alreadyShown = $_SESSION['shown_movie_ids'] ?? [];
$allMovies = $pdo->query("SELECT * FROM movies")->fetchAll();

$maxPossibleScore = array_sum($tmdbGenreWeights) ?: 1;

$processMovies = function($moviesList) use ($alreadyShown, $tmdbGenreWeights, $maxPossibleScore, $triggerGenresMap) {
    $resultList = [];
    foreach ($moviesList as $movie) {
        if (in_array($movie['id'], $alreadyShown)) continue;
        
        $movieGenres = array_map('intval', explode(',', $movie['genre'] ?? ''));
        $movieScore = 0;
        $primaryTrigger = 'Музичний мікс';

        foreach ($movieGenres as $mg) {
            if (isset($tmdbGenreWeights[$mg])) {
                $movieScore += $tmdbGenreWeights[$mg];
                $primaryTrigger = $triggerGenresMap[$mg]; // Беремо музичний жанр-тригер
            }
        }

        if ($movieScore > 0) {
            // Переводимо у відсотки
            $matchPercent = round(($movieScore / $maxPossibleScore) * 100);
            // Робимо буст, щоб інтерфейс виглядав привабливо (мінімум 65%, максимум 99%)
            $movie['match_percent'] = min(max($matchPercent + 30, 65), 99);
            $movie['trigger_genre'] = $primaryTrigger;
            $resultList[] = $movie;
        }
    }
    // Сортуємо: спочатку найбільший відсоток метчу
    usort($resultList, fn($a, $b) => $b['match_percent'] <=> $a['match_percent']);
    return $resultList;
};

$filtered = $processMovies($allMovies);

// Скидання, якщо всі фільми вже переглянуті
if (empty($filtered)) {
    $_SESSION['shown_movie_ids'] = [];
    $filtered = $processMovies($allMovies);
}

$top = array_slice($filtered, 0, 10);
$_SESSION['last_movies'] = $top;
return $top;