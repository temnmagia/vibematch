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
    // Популярні та загальні жанри
    'pop'                  => 35,      // романтична комедія, молодіжна драма, музичне кіно, feel-good фільм, візуальне фентезі
    'dance pop'            => 10402,   // музичний документальний фільм, клубна музика
    'synthpop'             => 10402,   // ретро-футуро, неонова ностальгія
    
    // Хіп-хоп та суміжні жанри
    'hip-hop'              => 28,      // урбаністична драма, гангстерський бойовик, соціальний трилер, музична біографія
    'trap'                 => 28,      // гангстерський бойовик, соціальна драма
    'mainstream rap'       => 28,
    'r&b'                  => 10402,   // любовна драма, соул-історія
    'experimental hip hop' => 53,      // артхаус, сюрреалізм, авангард, інтелектуальний хіп-хоп, психоделічний трилер
    'noise rap'            => 53,      // антиутопія, сатиричний жах, психотрилер
    'industrial hip-hop'   => 53,      // індустріальний трилер, темне кіно
    'lo-fi hip-hop'        => 10402,   // slice of life, повільне артхаус-кіно, постромантична драма
    'chillhop'             => 10402,   // медитативне кіно, slow cinema
    'cloud rap'            => 18,      // урбаністичне поетичне кіно, slow cinema, камерна драма
    
    // Альтернативні та інді жанри
    'rock'                 => 28,      // молодіжне роуд-муві, драмеді, романтична драма
    'pop rock'             => 28,
    'indie rock'           => 28,      // камерна романтика, ретроспективна меланхолія
    'alternative rock'     => 28,      // психологічна драма, молодіжна меланхолія
    'post-grunge'          => 28,
    'emo'                  => 28,      // екзистенційна драма, боді горор, внутрішній монолог
    'hard rock'            => 28,      // жорстокий бойовик, антигеройське кіно
    'metal'                => 28,      // хорор, апокаліптичний трилер, темне фентезі
    'nu metal'             => 28,
    'classic rock'         => 28,      // епічний бойовик, музичне роуд-муві
    'punk rock'            => 28,      // анархічне кіно, DIY-естетика, кіно про бунт
    'post-punk'            => 28,      // нео-нуар, дистопія, психотрилер
    
    // Електронна музика, експериментальна
    'edm'                  => 878,     // rave-культура, кіберпанк, танцювальне документальне кіно
    'house'                => 878,
    'techno'               => 878,     // кіберпанк, технотрилер
    'trance'               => 878,
    'glitch'               => 878,     // кіберпанк, експериментальна наукова фантастика
    'glitch-hop'           => 878,     // кіберпанк, експериментальний технотрилер
    'idm'                  => 878,     // абстрактна наукова фантастика, мінімалістичне кіно
    'vaporwave'            => 10402,   // неонова ностальгія, естетичне ретро-футуро
    'synthwave'            => 10402,   // ретро-футуро, кіберпанк
    'retrowave'            => 10402,   // неонова ностальгія
    
    // Джаз і суміжні жанри
    'jazz'                 => 10402,   // нуар, інтелігентна драма, романтика, вечірній фільм
    'avant-garde jazz'     => 10402,   // імпровізаційне кіно, авангардний фільм
    'free improv'          => 10402,
    'soul jazz'            => 10402,
    'smooth jazz'          => 10402,
    
    // Фольк і етнічні жанри
    'neofolk'              => 99,      // містична драма, фольк-хорор, символістське артхаус-кіно
    'apocalyptic folk'     => 99,      // апокаліптичне кіно, містичний трилер
    'martial industrial'   => 99,      // воєнна драма, темне фентезі, поствоєнний артхаус
    'dark ambient'         => 99,      // медитативний жах, містичне етнографічне кіно
    'ritual ambient'       => 99,
    'drone folk'           => 99,
    'pagan folk'           => 99,      // міфологічне фентезі, етнографічне кіно, містична драма
    'nordic folk'          => 99,
    'dungeon synth'        => 99,      // темне середньовічне фентезі, готичне кіно
    'fantasy ambient'      => 99,
    'dark folk'            => 99,
    'ethereal wave'        => 99,      // релігійне кіно, філософський епос
    
    // Популярні світові стилі
    'reggaeton'            => 10402,   // романтична комедія, пригодницька драма, мелодрама
    'latin pop'            => 10402,
    'tropical house'       => 10402,
    'country'              => 37,      // роуд-муві, сімейна драма, сільське життя
    'country pop'          => 37,
    'k-pop'                => 10402,   // молодіжне фентезі, музичне шоу, фантастична романтика
    'j-pop'                => 10402,
    'idol pop'             => 10402,
    
    // Експериментальні, арт- і інтелектуальні жанри
    'experimental pop'     => 35,      // постмодерне фентезі, кіберестетика, візуальна гіперреальність
    'art pop'              => 35,      // авангардна драма, експериментальний фентезі
    'plunderphonics'       => 35,      // постінтернет-арт, відеоколаж
    'musique concrète'     => 35,
    'drone'                => 99,      // медитативне кіно, slow cinema
    'minimal'              => 99,
    'electroacoustic'      => 99,      // тактильна документалістика, екологічне арт-кіно
    
    // Інші популярні жанри
    'funk'                 => 10402,   // кримінальна комедія, соул-драма, мюзикл
    'neo-soul'             => 10402,
    'retro pop'            => 10402,
    'disco'                => 10402,   // нічна комедія, гламурне ретро-кіно
    'italo disco'          => 10402,
    'electro funk'         => 10402,
    'blues rock'           => 28,      // провінційна драма, психологічний вестерн
    'southern rock'        => 28,
    
    // Специфічні та класифікації TMDb (приклади)
    // В TMDb жанрах є обмежена кількість ID, можна поєднувати кілька ID для гнучкості
    
    // Важливо: TMDb genre IDs:
    // 28 - Action
    // 35 - Comedy
    // 37 - Western
    // 53 - Thriller
    // 99 - Documentary
    // 878 - Science Fiction
    // 10402 - Music
    
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

$stmt = $pdo->prepare("INSERT INTO movies (title, genre, description, image_url, imdb_url, tmdb_id) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->execute([
    $movie['title'],
    $genre_ids_str,
    $movie['overview'] ?? '',
    $image_url,
    $imdb_url,
    $movie['id'] // Оце TMDb ID
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
