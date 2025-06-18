<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once 'db_connect.php';
require_once 'TMDb_api.php';

// 1. Отримуємо жанри з кешу або динамічно зі Spotify
if (!isset($_SESSION['user_genres']) || empty($_SESSION['user_genres'])) {
    $access_token = $_SESSION['spotify_token']['access_token'] ?? null;
    if (!$access_token) return [];

    function getUserTopArtists($token, $limit = 20) {
        $url = "https://api.spotify.com/v1/me/top/artists?limit=$limit";
        $opts = ["http" => ["header" => "Authorization: Bearer $token"]];
        $context = stream_context_create($opts);
        $response = file_get_contents($url, false, $context);
        return json_decode($response, true);
    }

    $topArtistsData = getUserTopArtists($access_token);
    if (empty($topArtistsData['items'])) return [];

    $allGenres = [];
    foreach ($topArtistsData['items'] as $artist) {
        foreach ($artist['genres'] as $genre) {
            $allGenres[] = $genre;
        }
    }

    $genreCounts = array_count_values($allGenres);
    arsort($genreCounts);
    $_SESSION['user_genres'] = array_slice(array_keys($genreCounts), 0, 5);
}

$userGenres = $_SESSION['user_genres'] ?? [];
if (empty($userGenres)) return [];

// 2. Genre map (встав сюди повну мапу)
$genreMap = [
    // Поп
    'pop'                  => [35, 10402],    // Comedy, Music
    'dance pop'            => [35, 10402],
    'synthpop'             => [10402, 14],    // Music, Fantasy

    // Хіп-хоп
    'hip-hop'              => [80, 53],       // Crime, Thriller
    'trap'                 => [53, 80],       // Thriller, Crime
    'mainstream rap'       => [53, 80],
    'r&b'                  => [10749, 18],    // Romance, Drama
    'experimental hip hop' => [99, 9648, 18, 10402], // Documentary, Mystery, Drama, Music
    'noise rap'            => [27, 9648],     // Horror, Mystery
    'industrial hip-hop'   => [27, 53],       // Horror, Thriller
    'lo-fi hip-hop'        => [18, 10749],    // Drama, Romance
    'chillhop'             => [18, 10749],
    'cloud rap'            => [14, 9648],     // Fantasy, Mystery

    // Рок
    'rock'                 => [28, 35],       // Action, Comedy
    'pop rock'             => [35, 12],       // Comedy, Adventure
    'indie rock'           => [18, 35],       // Drama, Comedy
    'alternative rock'     => [53, 9648],     // Thriller, Mystery
    'post-grunge'          => [9648, 18],     // Mystery, Drama
    'emo'                  => [18, 10749],    // Drama, Romance
    'hard rock'            => [28, 80],       // Action, Crime
    'metal'                => [27, 53],       // Horror, Thriller
    'nu metal'             => [53, 27],       // Thriller, Horror
    'classic rock'         => [36, 18],       // History, Drama
    'punk rock'            => [80, 28],       // Crime, Action
    'post-punk'            => [9648, 80],     // Mystery, Crime

    // Електронна музика
    'edm'                  => [878, 10402],   // Science Fiction, Music
    'house'                => [10402, 35],    // Music, Comedy
    'techno'               => [878, 9648],    // Science Fiction, Mystery
    'trance'               => [878, 14],      // Science Fiction, Fantasy
    'glitch'               => [99, 9648],     // Documentary, Mystery
    'glitch-hop'           => [9648, 18],     // Mystery, Drama
    'idm'                  => [9648, 99],     // Mystery, Documentary
    'vaporwave'            => [10402, 878],   // Music, Science Fiction
    'synthwave'            => [878, 14],      // Science Fiction, Fantasy
    'retrowave'            => [878, 14],      // Science Fiction, Fantasy

    // Джаз
    'jazz'                 => [10402, 18],    // Music, Drama
    'avant-garde jazz'     => [99, 9648],     // Documentary, Mystery
    'free improv'          => [99, 18],       // Documentary, Drama
    'soul jazz'            => [10749, 18],    // Romance, Drama
    'smooth jazz'          => [10749, 18],

    // Неофолк і суміжні жанри
    'neofolk'              => [99, 18],       // Documentary, Drama
    'apocalyptic folk'     => [10752, 9648],  // War, Mystery
    'martial industrial'   => [10752, 53],    // War, Thriller
    'dark ambient'         => [27, 9648],     // Horror, Mystery
    'ritual ambient'       => [27, 99],       // Horror, Documentary
    'drone folk'           => [99, 9648],     // Documentary, Mystery
    'pagan folk'           => [14, 36],       // Fantasy, History
    'nordic folk'          => [36, 10752],    // History, War
    'dungeon synth'        => [9648, 14],     // Mystery, Fantasy
    'fantasy ambient'      => [14, 99],       // Fantasy, Documentary
    'dark folk'            => [9648, 27],     // Mystery, Horror
    'ethereal wave'        => [10749, 18],    // Romance, Drama

    // Світова музика
    'reggaeton'            => [35, 10751],    // Comedy, Family
    'latin pop'            => [10749, 35],    // Romance, Comedy
    'tropical house'       => [10751, 10402], // Family, Music
    'country'              => [37, 36],       // Western, History
    'country pop'          => [10749, 37],    // Romance, Western
    'k-pop'                => [35, 10402],    // Comedy, Music
    'j-pop'                => [35, 10402],
    'idol pop'             => [35, 10402],
 // Арт і експериментал 
    'experimental pop'     => [99, 9648],     // Documentary, Mystery
    'art pop'              => [10402, 99],    // Music, Documentary
    'plunderphonics'       => [9648, 99],     // Mystery, Documentary
    'musique concrète'     => [99, 18],       // Documentary, Drama
    'drone'                => [9648, 27],     // Mystery, Horror
    'minimal'              => [99, 18],       // Documentary, Drama
    'electroacoustic'      => [99, 10402],    // Documentary, Music

    // Funk / Disco
    'funk'                 => [10402, 35],    // Music, Comedy
    'neo-soul'             => [10749, 18],    // Romance, Drama
    'retro pop'            => [35, 10402],    // Comedy, Music
    'disco'                => [10402, 35],    // Music, Comedy
    'italo disco'          => [10402, 35],    // Music, Comedy
    'electro funk'         => [878, 10402],   // Sci-Fi, Music

    // Блюз/Саутерн
    'blues rock'           => [36, 18],       // History, Drama
    'southern rock'        => [37, 80],       // Western, Crime
];

$tmdbGenres = [];
foreach ($userGenres as $g) {
    $gLower = mb_strtolower(trim($g));
    if (isset($genreMap[$gLower])) {
        foreach ($genreMap[$gLower] as $id) {
            if (!in_array($id, $tmdbGenres)) {
                $tmdbGenres[] = $id;
            }
        }
    }
}
if (empty($tmdbGenres)) return [];

// 3. Отримуємо фільми з TMDb та додаємо в базу
$page = 1;
do {
    $response = tmdb_get_movies_by_genres($tmdbGenres, $page);
    $results = $response['results'] ?? [];
    if (empty($results)) break;

    foreach ($results as $movie) {
        $stmt = $pdo->prepare("SELECT id FROM movies WHERE tmdb_id = ?");
        $stmt->execute([$movie['id']]);
        if ($stmt->fetch()) continue;

        $genre_ids_str = isset($movie['genre_ids']) ? implode(',', $movie['genre_ids']) : '';
        $image_url = !empty($movie['poster_path']) ? tmdb_get_poster_url($movie['poster_path']) : null;
        $imdb_id = tmdb_get_imdb_id($movie['id']);
        $imdb_url = $imdb_id ? "https://www.imdb.com/title/$imdb_id" : null;

$ukDetails = tmdb_api_request("/movie/{$movie['id']}", ['language' => 'uk-UA']);
$enDetails = tmdb_api_request("/movie/{$movie['id']}", ['language' => 'en-US']);

$description_uk = $ukDetails['overview'] ?? '';
$description_en = $enDetails['overview'] ?? '';

// Додати до БД
$stmt = $pdo->prepare("INSERT INTO movies (title, genre, description, description_en, image_url, imdb_url, tmdb_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
$stmt->execute([
    $movie['title'],
    $genre_ids_str,
    $description_uk,
    $description_en,
    $image_url,
    $imdb_url,
    $movie['id']
]);
    }

    $page++;
} while ($page <= ($response['total_pages'] ?? 1) && $page <= 2);

// 4. Витягуємо фільми з бази
$stmt = $pdo->query("SELECT * FROM movies");
$allMovies = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 5. Видаляємо вже показані
$alreadyShown = $_SESSION['shown_movie_ids'] ?? [];
$allMovies = array_filter($allMovies, function($movie) use ($alreadyShown) {
    return !in_array($movie['id'], $alreadyShown);
});

// 6. Сортуємо за релевантністю
$filteredMovies = [];
foreach ($allMovies as $movie) {
    $movieGenres = array_map('intval', explode(',', $movie['genre']));
    $overlap = array_intersect($movieGenres, $tmdbGenres);
    if (count($overlap) > 0) {
        $movie['relevance'] = count($overlap);
        $filteredMovies[] = $movie;
    }
}

usort($filteredMovies, function($a, $b) {
    return $b['relevance'] <=> $a['relevance'];
});

// 7. Якщо фільми закінчились — скидаємо список
if (empty($filteredMovies)) {
    $_SESSION['shown_movie_ids'] = [];

    $stmt = $pdo->query("SELECT * FROM movies");
    $allMovies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $filteredMovies = [];

    foreach ($allMovies as $movie) {
        $movieGenres = array_map('intval', explode(',', $movie['genre']));
        $overlap = array_intersect($movieGenres, $tmdbGenres);
        if (count($overlap) > 0) {
            $movie['relevance'] = count($overlap);
            $filteredMovies[] = $movie;
        }
    }

    usort($filteredMovies, function($a, $b) {
        return $b['relevance'] <=> $a['relevance'];
    });
}

// 8. Повертаємо топ 10
$topMovies = array_slice($filteredMovies, 0, 10);
$_SESSION['last_movies'] = $topMovies;
return $topMovies;
?>
