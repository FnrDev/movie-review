-- ============================================================================
-- DEPLOYMENT SCHEMA - Movie Review Application
-- ============================================================================
-- Matches the live database (db202202672) exactly.
-- SAFE: uses CREATE TABLE IF NOT EXISTS and INSERT IGNORE.
-- No DROP commands - existing data is preserved. Can be run multiple times.
--
-- NOTE: Stored procedures live in database/procedures.sql and triggers
--       (plus the dbProj_activity_log audit table they use) live in
--       database/triggers.sql. Import those two files after this one.
-- ============================================================================

CREATE DATABASE IF NOT EXISTS db202202672 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE db202202672;

-- ============================================================================
-- TABLES
-- ============================================================================

-- Users -----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dbProj_users (
    user_id        INT AUTO_INCREMENT PRIMARY KEY,
    username       VARCHAR(50)  UNIQUE NOT NULL,
    email          VARCHAR(100) UNIQUE NOT NULL,
    password_hash  VARCHAR(255) NOT NULL,
    role           ENUM('visitor','creator','admin') DEFAULT 'visitor',
    profile_image  VARCHAR(255),
    is_active      TINYINT(1) DEFAULT 1,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Genres ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dbProj_genres (
    genre_id    INT AUTO_INCREMENT PRIMARY KEY,
    genre_name  VARCHAR(50) UNIQUE NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Movies ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dbProj_movies (
    movie_id          INT AUTO_INCREMENT PRIMARY KEY,
    creator_id        INT,
    genre_id          INT,
    title             VARCHAR(200) NOT NULL,
    short_description TEXT,
    full_description  TEXT,
    poster_image      VARCHAR(255),
    trailer_url       VARCHAR(500),
    release_year      INT,
    is_published      TINYINT(1) DEFAULT 0,
    view_count        INT DEFAULT 0,
    created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (creator_id) REFERENCES dbProj_users(user_id)  ON DELETE SET NULL,
    FOREIGN KEY (genre_id)   REFERENCES dbProj_genres(genre_id) ON DELETE SET NULL,
    INDEX idx_title (title),
    INDEX idx_published (is_published),
    INDEX idx_creator (creator_id),
    INDEX idx_genre (genre_id),
    FULLTEXT idx_search (title, short_description)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ratings ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dbProj_ratings (
    rating_id     INT AUTO_INCREMENT PRIMARY KEY,
    movie_id      INT NOT NULL,
    user_id       INT NOT NULL,
    rating_value  INT,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (movie_id) REFERENCES dbProj_movies(movie_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)  REFERENCES dbProj_users(user_id)   ON DELETE CASCADE,
    UNIQUE KEY unique_user_movie (user_id, movie_id),
    INDEX idx_movie (movie_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Comments --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dbProj_comments (
    comment_id    INT AUTO_INCREMENT PRIMARY KEY,
    movie_id      INT NOT NULL,
    user_id       INT NOT NULL,
    comment_text  TEXT NOT NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (movie_id) REFERENCES dbProj_movies(movie_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)  REFERENCES dbProj_users(user_id)   ON DELETE CASCADE,
    INDEX idx_movie (movie_id),
    INDEX idx_user (user_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Movie media -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dbProj_movie_media (
    media_id     INT AUTO_INCREMENT PRIMARY KEY,
    movie_id     INT NOT NULL,
    media_url    VARCHAR(500) NOT NULL,
    media_type   ENUM('image','video','audio','document') NOT NULL,
    uploaded_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (movie_id) REFERENCES dbProj_movies(movie_id) ON DELETE CASCADE,
    INDEX idx_movie (movie_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SAMPLE DATA
-- ============================================================================

INSERT IGNORE INTO dbProj_genres (genre_name) VALUES
('Action'), ('Comedy'), ('Drama'), ('Horror'), ('Sci-Fi'),
('Romance'), ('Thriller'), ('Fantasy'), ('Animation'),
('Documentary'), ('Crime'), ('Adventure');

-- Sample users (password for all three is: test123)
INSERT IGNORE INTO dbProj_users (username, email, password_hash, role, is_active) VALUES
('admin',    'admin@moviereview.com', '$2y$12$ZHG5KRn63tNa0UNHHIwDiur7ViGhdv1/mB2/k9uCbOv4UWV4J1.8a', 'admin',   1),
('creator1', 'creator@example.com',   '$2y$12$ZHG5KRn63tNa0UNHHIwDiur7ViGhdv1/mB2/k9uCbOv4UWV4J1.8a', 'creator', 1),
('visitor1', 'visitor@example.com',   '$2y$12$ZHG5KRn63tNa0UNHHIwDiur7ViGhdv1/mB2/k9uCbOv4UWV4J1.8a', 'visitor', 1);

-- ============================================================================
-- DONE
-- Login (all): admin@moviereview.com / creator@example.com / visitor@example.com
-- Password for all: test123
-- After this file, import database/procedures.sql and database/triggers.sql.
-- ============================================================================