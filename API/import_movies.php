<?php
session_start();
require_once 'db_connect.php';  // $pdo
require_once 'TMDb_api.php';    // Функції для TMDb

// Отримуємо масив жанрів Spotify — через GET, POST або сесію
if (isset($_GET['genres'])) {
    // Передаєш як рядок "pop,rock,hip-hop"
    $userGenres = explode(',', $_GET['genres']);
} elseif (isset($_SESSION['user_genres'])) {
    $userGenres = $_SESSION['user_genres'];
} else {
    die("Жанри користувача не передані.");
}

// Мапа Spotify жанрів => TMDb genre IDs
$genreMap = [
    'pop' => 35,           // Comedy (приклад)
    'rock' => 28,          // Action (приклад)
    'hip-hop' => 28,       // Action (приклад)
    'experimental hip hop' => 53, // Thriller (приклад)
    'hyperpop' => 878,   // Science Fiction (приклад)
    'cloud rap' => 18,         // Drama (приклад)
    'jazz' => 10402,       // Music (приклад)
    // ... додай всі потрібні відповідності
];

// Конвертуємо Spotify жанри у TMDb IDs, уникаючи дублювань
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

// Функція для збереження фільму, якщо його ще немає в БД
function saveMovieIfNotExists($pdo, $movie) {
    $stmt = $pdo->prepare("SELECT id FROM movies WHERE title = ?");
    $stmt->execute([$movie['title']]);
    if ($stmt->fetch()) return;

    // Зберігаємо TMDb жанри як рядок через кому (id жанрів)
    if (isset($movie['genre_ids']) && is_array($movie['genre_ids'])) {
        $genre_ids_str = implode(',', $movie['genre_ids']);
    } else {
        $genre_ids_str = '';
    }

    $image_url = isset($movie['poster_path']) && $movie['poster_path'] ? tmdb_get_poster_url($movie['poster_path']) : null;
    $imdb_url = null;

    $stmt = $pdo->prepare("INSERT INTO movies (title, genre, description, image_url, imdb_url) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $movie['title'],
        $genre_ids_str,
        $movie['overview'] ?? '',
        $image_url,
        $imdb_url
    ]);
}

// Імпортуємо фільми з TMDb за кожним жанром (2 сторінки максимум)
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

// Тепер виводимо фільми, жанри яких перетинаються з нашими TMDb жанрами

// Готуємо SQL LIKE умови для пошуку жанрів у полі genre (там рядок genre_ids через кому)
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

usort($movies, function($a, $b) use ($userGenres) {
    $a_genres = isset($a['genre_ids']) && is_array($a['genre_ids']) ? $a['genre_ids'] : [];
    $b_genres = isset($b['genre_ids']) && is_array($b['genre_ids']) ? $b['genre_ids'] : [];

    $a_matches = count(array_intersect($a_genres, $userGenres));
    $b_matches = count(array_intersect($b_genres, $userGenres));

    return $b_matches <=> $a_matches;  // сортуємо по спаданню збігів
});

// Вивід результату
echo "<h2>Рекомендовані фільми за вашими музичними жанрами:</h2>";
if (!$movies) {
    echo "<p>Фільми не знайдено.</p>";
} else {
    foreach ($movies as $movie) {
        echo "<h3>" . htmlspecialchars($movie['title']) . "</h3>";
        if (!empty($movie['image_url'])) {
        echo '<img src="' . htmlspecialchars($movie['image_url']) . '" alt="' . htmlspecialchars($movie['title']) . '" style="max-width:200px; height:auto;">';
        }
        echo "<p>" . htmlspecialchars($movie['description']) . "</p>";
        echo "<hr>";
    }
}
?>
