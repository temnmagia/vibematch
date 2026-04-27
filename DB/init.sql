-- ============================================================
-- init.sql — Повна схема бази даних VibeMatch
-- ============================================================

CREATE DATABASE IF NOT EXISTS vibematch_2
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE vibematch_2;

-- ------------------------------------------------------------
-- Користувачі
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    name                  VARCHAR(100)        NOT NULL,
    email                 VARCHAR(100)        NOT NULL UNIQUE,
    spotify_token         TEXT,
    spotify_refresh_token VARCHAR(512),
    spotify_token_expires INT,
    created_at            TIMESTAMP           DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- Фільми (кеш TMDb)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS movies (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    tmdb_id        INT          NOT NULL UNIQUE,
    title          VARCHAR(255) NOT NULL,
    genre          VARCHAR(100),
    description    TEXT,
    description_en TEXT,
    image_url      TEXT,
    imdb_url       TEXT,
    created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- Закладки (favorites)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS favorites (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    movie_id   INT NOT NULL,           -- зберігає tmdb_id
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fav (user_id, movie_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ------------------------------------------------------------
-- Голосування (votes)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS votes (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT    NOT NULL,
    movie_id   INT    NOT NULL,        -- локальний movies.id
    vote       TINYINT NOT NULL CHECK (vote IN (-1, 1)),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vote (user_id, movie_id),
    FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
    FOREIGN KEY (movie_id) REFERENCES movies(id) ON DELETE CASCADE
);

-- ------------------------------------------------------------
-- Дружба
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS friendships (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    requester_id INT         NOT NULL,
    receiver_id  INT         NOT NULL,
    status       ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
    created_at   TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP   DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_friendship (requester_id, receiver_id),
    FOREIGN KEY (requester_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id)  REFERENCES users(id) ON DELETE CASCADE,
    CHECK (requester_id <> receiver_id)
);

-- ------------------------------------------------------------
-- Стрічка активності (для друзів)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_feed (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT         NOT NULL,
    type       ENUM('favorite','vote_up','vote_down') NOT NULL,
    movie_id   INT         NOT NULL,   -- tmdb_id
    created_at TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_time (user_id, created_at DESC)
);

-- ------------------------------------------------------------
-- Повна історія перегляду (опціонально)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_history (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT,
    movie_id   INT,
    watched    BOOLEAN   DEFAULT FALSE,
    rating     INT       CHECK (rating BETWEEN 1 AND 10),
    watched_at TIMESTAMP NULL,
    FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
    FOREIGN KEY (movie_id) REFERENCES movies(id) ON DELETE CASCADE
);
