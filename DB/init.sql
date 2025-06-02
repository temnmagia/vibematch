CREATE DATABASE moviemood;
USE moviemood;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100),
  spotify_token TEXT
);

CREATE TABLE movies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255),
  genre VARCHAR(100),
  mood VARCHAR(50),
  description TEXT,
  image_url TEXT
);

CREATE TABLE user_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT,
  movie_id INT,
  watched BOOLEAN DEFAULT FALSE,
  rating INT,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (movie_id) REFERENCES movies(id)
);
