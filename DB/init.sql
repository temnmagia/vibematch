CREATE DATABASE IF NOT EXISTS vibematch;
USE vibematch;

-- Користувачі
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100),
  email VARCHAR(100),
  spotify_token TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Фільми
CREATE TABLE movies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  genre VARCHAR(100),
  mood VARCHAR(50),
  description TEXT,
  image_url TEXT,
  spotify_tags TEXT, -- для зберігання тегів типу жанрів або артистів
  imdb_url TEXT
);

-- Історія користувача
CREATE TABLE user_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT,
  movie_id INT,
  watched BOOLEAN DEFAULT FALSE,
  rating INT CHECK (rating BETWEEN 1 AND 10),
  watched_at TIMESTAMP NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (movie_id) REFERENCES movies(id) ON DELETE CASCADE
);

ALTER TABLE movies ADD tmdb_id INT;

CREATE TABLE favorites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    movie_id INT NOT NULL,
    UNIQUE (user_id, movie_id)
);
CREATE TABLE votes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    movie_id INT NOT NULL,
    vote TINYINT CHECK (vote IN (-1, 1)),
    UNIQUE (user_id, movie_id)
);