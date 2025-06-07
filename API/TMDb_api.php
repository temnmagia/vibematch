<?php
// tmdb_api.php

$tmdb_api_key = '52e3411fbaed0449e366f0843aad1d2b';
$tmdb_api_base = 'https://api.themoviedb.org/3';

function tmdb_api_request(string $endpoint, array $params = []) {
    global $tmdb_api_key, $tmdb_api_base;

    $params['api_key'] = $tmdb_api_key;
    $url = $tmdb_api_base . $endpoint . '?' . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new Exception("cURL error: $error");
    }

    return json_decode($response, true);
}

function tmdb_search_movies(string $query, int $page = 1) {
    return tmdb_api_request('/search/movie', [
        'query' => $query,
        'page' => $page,
        'include_adult' => false,
        'language' => 'uk-UA',
    ]);
}

function tmdb_get_movies_by_genre(int $genreId, int $page = 1) {
    return tmdb_api_request('/discover/movie', [
        'with_genres' => $genreId,
        'page' => $page,
        'language' => 'uk-UA',
        'sort_by' => 'popularity.desc',
        'include_adult' => false,
    ]);
}

function tmdb_get_movies_by_mood(string $mood, int $page = 1) {
    // Тут просто пошук за ключовим словом, що описує настрій
    return tmdb_search_movies($mood, $page);
}

function tmdb_get_genres() {
    return tmdb_api_request('/genre/movie/list', [
        'language' => 'uk-UA',
    ]);
}

function tmdb_get_movie_details(int $movieId) {
    return tmdb_api_request("/movie/$movieId", [
        'language' => 'uk-UA',
    ]);
}

function tmdb_get_poster_url(string $posterPath, string $size = 'w500') {
    if (!$posterPath) return null;
    return "https://image.tmdb.org/t/p/$size$posterPath";
}
function tmdb_get_imdb_id(int $movieId): ?string {
    $details = tmdb_get_movie_details($movieId);
    return $details['imdb_id'] ?? null;
}
