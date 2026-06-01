-- ============================================================================
-- DEPLOYMENT SCHEMA - Movie Review Application
-- ============================================================================
-- This file is for DEPLOYMENT/GRADING purposes only
-- Creates database structure and adds sample data for testing
-- 
-- SAFE: Uses CREATE TABLE IF NOT EXISTS - won't delete existing data
-- Can be run multiple times without losing data
-- ============================================================================

-- Create database if it doesn't exist
CREATE DATABASE IF NOT EXISTS db202202672 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE db202202672;

-- ============================================================================
-- TABLE DEFINITIONS
-- ============================================================================

-- Users table
CREATE TABLE IF NOT EXISTS dbProj_users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('visitor', 'creator', 'admin') DEFAULT 'visitor',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    profile_image VARCHAR(255),
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Genres table
CREATE TABLE IF NOT EXISTS dbProj_genres (
    genre_id INT AUTO_INCREMENT PRIMARY KEY,
    genre_name VARCHAR(50) UNIQUE NOT NULL,
    description TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Movies table
CREATE TABLE IF NOT EXISTS dbProj_movies (
    movie_id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    short_description TEXT,
    full_description TEXT,
    release_year INT,
    genre_id INT,
    poster_image VARCHAR(255),
    trailer_url VARCHAR(255),
    creator_id INT,
    is_published TINYINT(1) DEFAULT 0,
    view_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (creator_id) REFERENCES dbProj_users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (genre_id) REFERENCES dbProj_genres(genre_id) ON DELETE SET NULL,
    INDEX idx_title (title),
    INDEX idx_published (is_published),
    INDEX idx_creator (creator_id),
    INDEX idx_genre (genre_id),
    FULLTEXT idx_search (title, short_description)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ratings table
CREATE TABLE IF NOT EXISTS dbProj_ratings (
    rating_id INT AUTO_INCREMENT PRIMARY KEY,
    movie_id INT NOT NULL,
    user_id INT NOT NULL,
    rating_value DECIMAL(2,1) CHECK (rating_value >= 0 AND rating_value <= 5),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (movie_id) REFERENCES dbProj_movies(movie_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES dbProj_users(user_id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_movie_rating (user_id, movie_id),
    INDEX idx_movie (movie_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Comments table
CREATE TABLE IF NOT EXISTS dbProj_comments (
    comment_id INT AUTO_INCREMENT PRIMARY KEY,
    movie_id INT NOT NULL,
    user_id INT NOT NULL,
    comment_text TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (movie_id) REFERENCES dbProj_movies(movie_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES dbProj_users(user_id) ON DELETE CASCADE,
    INDEX idx_movie (movie_id),
    INDEX idx_user (user_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Movie Media table
CREATE TABLE IF NOT EXISTS dbProj_movie_media (
    media_id INT AUTO_INCREMENT PRIMARY KEY,
    movie_id INT NOT NULL,
    media_type ENUM('video', 'audio', 'image') NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size INT,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (movie_id) REFERENCES dbProj_movies(movie_id) ON DELETE CASCADE,
    INDEX idx_movie (movie_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SAMPLE DATA
-- ============================================================================

-- Insert genres (only if they don't exist)
INSERT IGNORE INTO dbProj_genres (genre_name, description) VALUES
('Action', 'High-energy films with physical stunts and chases'),
('Comedy', 'Films designed to make audiences laugh'),
('Drama', 'Serious, plot-driven presentations'),
('Horror', 'Films designed to frighten and invoke fear'),
('Sci-Fi', 'Science fiction and futuristic themes'),
('Romance', 'Love stories and romantic relationships'),
('Thriller', 'Suspenseful and exciting films'),
('Fantasy', 'Magical and supernatural elements'),
('Animation', 'Animated films and cartoons'),
('Documentary', 'Non-fiction films about real events'),
('Crime', 'Films about criminal activities'),
('Adventure', 'Exciting journeys and quests');

-- Insert sample users (Password for all: test123)
-- Only adds users if they don't already exist
INSERT IGNORE INTO dbProj_users (username, email, password_hash, role, is_active) VALUES
('admin', 'admin@moviereview.com', '$2y$12$ZHG5KRn63tNa0UNHHIwDiur7ViGhdv1/mB2/k9uCbOv4UWV4J1.8a', 'admin', 1),
('creator1', 'creator@example.com', '$2y$12$ZHG5KRn63tNa0UNHHIwDiur7ViGhdv1/mB2/k9uCbOv4UWV4J1.8a', 'creator', 1),
('visitor1', 'visitor@example.com', '$2y$12$ZHG5KRn63tNa0UNHHIwDiur7ViGhdv1/mB2/k9uCbOv4UWV4J1.8a', 'visitor', 1);

-- ============================================================================
-- DEPLOYMENT COMPLETE
-- ============================================================================
-- Database created with:
-- - 3 sample users (admin, creator, visitor)
-- - 12 movie genres
-- - Empty movies, ratings, and comments tables (ready for testing)
--
-- Default Login Credentials:
-- Admin:   admin@moviereview.com / test123
-- Creator: creator@example.com / test123
-- Visitor: visitor@example.com / test123
-- ============================================================================
