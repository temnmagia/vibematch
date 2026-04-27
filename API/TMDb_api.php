<?php
require_once __DIR__ . '/config.php';

function tmdb_api_request(string $endpoint, array $params = []): array {
    $params['api_key'] = TMDB_API_KEY;
    $url = TMDB_BASE_URL . $endpoint . '?' . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response = curl_exec($ch);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("TMDb cURL error: $error");
        return [];
    }

    return json_decode($response, true) ?? [];
}

function tmdb_get_movie_details(int $movieId): array {
    $uk = tmdb_api_request("/movie/$movieId", ['language' => 'uk-UA']);
    if (!empty($uk['overview'])) return $uk;

    $en = tmdb_api_request("/movie/$movieId", ['language' => 'en-US']);
    $uk['overview'] = $en['overview'] ?? 'No description available.';
    return $uk;
}

function tmdb_get_movies_by_genres(array $genreIds, int $page = 1): array {
    return tmdb_api_request('/discover/movie', [
        'with_genres'   => implode(',', $genreIds),
        'page'          => $page,
        'language'      => 'uk-UA',
        'sort_by'       => 'popularity.desc',
        'include_adult' => false,
    ]);
}

function tmdb_get_poster_url(string $posterPath, string $size = 'w500'): string {
    return "https://image.tmdb.org/t/p/$size$posterPath";
}

function tmdb_get_imdb_id(int $movieId): ?string {
    $details = tmdb_get_movie_details($movieId);
    return $details['imdb_id'] ?? null;
}

function tmdb_get_movie_trailers(int $movieId): array {
    return tmdb_api_request("/movie/$movieId/videos", ['language' => 'en-US']);
}

function tmdb_search_movies(string $query, int $page = 1): array {
    return tmdb_api_request('/search/movie', [
        'query'          => $query,
        'page'           => $page,
        'include_adult'  => false,
        'language'       => 'uk-UA',
    ]);
}
