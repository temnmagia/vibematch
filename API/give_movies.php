<?php
// import_movies.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once 'db_connect.php';  // $pdo
require_once 'TMDb_api.php';


$access_token = $_SESSION['spotify_token']['access_token'] ?? null;
if (!$access_token) {
    die('Токен Spotify не знайдено.');
}

// Функція отримання топ артистів (для прикладу)
function getUserTopArtists($token, $limit = 20) {
    $url = "https://api.spotify.com/v1/me/top/artists?limit=$limit";
    $opts = [
        "http" => [
            "header" => "Authorization: Bearer $token"
        ]
    ];
    $context = stream_context_create($opts);
    $response = file_get_contents($url, false, $context);
    return json_decode($response, true);
}

// Отримуємо топ артистів
$topArtistsData = getUserTopArtists($access_token);
if (empty($topArtistsData['items'])) {
    die("Не вдалося отримати топ артистів.");
}

// Витягуємо жанри артистів
$allGenres = [];
foreach ($topArtistsData['items'] as $artist) {
    foreach ($artist['genres'] as $genre) {
        $allGenres[] = $genre;
    }
}

// Підрахунок найчастіших жанрів
$genreCounts = array_count_values($allGenres);
arsort($genreCounts);

// Вибираємо топ 5 жанрів
$topGenres = array_slice(array_keys($genreCounts), 0, 5);

$_SESSION['user_genres'] = $topGenres;


// Отримуємо жанри користувача зі сесії
if (isset($_SESSION['user_genres'])) {
    $userGenres = $_SESSION['user_genres'];
} else {
    $userGenres = [];
}

if (empty($userGenres)) {
    // Якщо немає жанрів, повертаємо пустий масив
    return [];
}

// Мапа Spotify жанрів => TMDb genre IDs
$genreMap = [
    'pop' => 35,
    'rock' => 28,
    'hip-hop' => 28,
    'experimental hip hop' => 53,
    'hyperpop' => 878,
    'cloud rap' => 18,
    'jazz' => 10402,
    // ...додай всі потрібні
];


// Конвертуємо Spotify жанри в TMDb IDs
$tmdbGenres = [];
foreach ($userGenres as $g) {
    $gLower = mb_strtolower(trim($g));
    if (isset($genreMap[$gLower]) && !in_array($genreMap[$gLower], $tmdbGenres)) {
        $tmdbGenres[] = $genreMap[$gLower];
    }
}

if (empty($tmdbGenres)) {
    die("Жоден зі Spotify жанрів не відповідає TMDb жанрам.");
}


// Функція збереження фільму (як у твоєму коді)
function saveMovieIfNotExists($pdo, $movie) {
    // Перевірка чи фільм вже є
    $stmt = $pdo->prepare("SELECT id FROM movies WHERE title = ?");
    $stmt->execute([$movie['title']]);
    if ($stmt->fetch()) return;

    // Генеруємо рядок жанрів через кому
    $genre_ids_str = isset($movie['genre_ids']) && is_array($movie['genre_ids'])
        ? implode(',', $movie['genre_ids'])
        : '';

    // Формуємо URL постера
    $image_url = isset($movie['poster_path']) && $movie['poster_path']
        ? tmdb_get_poster_url($movie['poster_path'])
        : null;

    // Отримуємо imdb_id через TMDb API (потрібно, щоб ця функція була у tmdb_api.php)
    $imdb_id = tmdb_get_imdb_id($movie['id']);
    $imdb_url = $imdb_id ? "https://www.imdb.com/title/$imdb_id" : null;

    // Вставка у базу
    $stmt = $pdo->prepare("INSERT INTO movies (title, genre, description, image_url, imdb_url) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $movie['title'],
        $genre_ids_str,
        $movie['overview'] ?? '',
        $image_url,
        $imdb_url
    ]);
}

// Імпортуємо фільми (2 сторінки максимум)
foreach ($tmdbGenres as $tmdbGenreId) {
    $page = 1;
    do {
        $response = tmdb_get_movies_by_genre((int)$tmdbGenreId, $page);
        if (empty($response['results'])) break;

        foreach ($response['results'] as $movie) {
            saveMovieIfNotExists($pdo, $movie);
        }
        $page++;
    } while ($page <= $response['total_pages'] && $page <= 2);
}

// Виводимо фільми, що мають відповідні жанри (шукаємо по genre LIKE)
$likeConditions = [];
$params = [];
foreach ($tmdbGenres as $genreId) {
    $likeConditions[] = "genre LIKE ?";
    $params[] = "%$genreId%";
}

$sql = "SELECT * FROM movies WHERE " . implode(' OR ', $likeConditions);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$movies = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Розбір genre_ids
foreach ($movies as &$movie) {
    if (!empty($movie['genre'])) {
        $movie['genre_ids'] = explode(',', $movie['genre']);
        $movie['genre_ids'] = array_map('intval', $movie['genre_ids']);
    } else {
        $movie['genre_ids'] = [];
    }
}
unset($movie);

// Сортування за збігом жанрів
usort($movies, function($a, $b) use ($tmdbGenres) {
    $a_matches = count(array_intersect($a['genre_ids'], $tmdbGenres));
    $b_matches = count(array_intersect($b['genre_ids'], $tmdbGenres));
    return $b_matches <=> $a_matches;
});
$movies = array_slice($movies, 0, 10);
return $movies;