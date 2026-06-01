DELIMITER $$

-- 1) Insert or update a user's rating for a movie (upsert) --------------------
DROP PROCEDURE IF EXISTS sp_rate_movie $$
CREATE PROCEDURE sp_rate_movie(
    IN p_movie INT,
    IN p_user  INT,
    IN p_value DECIMAL(2,1)
)
BEGIN
    INSERT INTO dbProj_ratings (movie_id, user_id, rating_value)
    VALUES (p_movie, p_user, p_value)
    ON DUPLICATE KEY UPDATE rating_value = p_value;
END $$

-- 2) Aggregate stats for a single movie --------------------------------------
DROP PROCEDURE IF EXISTS sp_movie_stats $$
CREATE PROCEDURE sp_movie_stats(IN p_movie INT)
BEGIN
    SELECT
        m.movie_id,
        m.title,
        m.view_count,
        COALESCE(ROUND(AVG(r.rating_value), 1), 0) AS avg_rating,
        COUNT(DISTINCT r.rating_id)  AS total_ratings,
        COUNT(DISTINCT c.comment_id) AS total_comments
    FROM dbProj_movies m
    LEFT JOIN dbProj_ratings  r ON r.movie_id = m.movie_id
    LEFT JOIN dbProj_comments c ON c.movie_id = m.movie_id
    WHERE m.movie_id = p_movie
    GROUP BY m.movie_id, m.title, m.view_count;
END $$

-- 3) Top-rated published movies ----------------------------------------------
DROP PROCEDURE IF EXISTS sp_top_movies $$
CREATE PROCEDURE sp_top_movies(IN p_limit INT)
BEGIN
    SELECT
        m.movie_id,
        m.title,
        g.genre_name,
        u.username AS creator,
        COALESCE(ROUND(AVG(r.rating_value), 1), 0) AS avg_rating,
        COUNT(r.rating_id) AS total_ratings,
        m.view_count
    FROM dbProj_movies m
    JOIN dbProj_genres g ON g.genre_id = m.genre_id
    JOIN dbProj_users  u ON u.user_id  = m.creator_id
    LEFT JOIN dbProj_ratings r ON r.movie_id = m.movie_id
    WHERE m.is_published = 1
    GROUP BY m.movie_id, m.title, g.genre_name, u.username, m.view_count
    ORDER BY avg_rating DESC, total_ratings DESC, m.view_count DESC
    LIMIT p_limit;
END $$

-- 4) Published movies in a given genre (powers the genre navigation) ----------
DROP PROCEDURE IF EXISTS sp_movies_by_genre $$
CREATE PROCEDURE sp_movies_by_genre(IN p_genre INT)
BEGIN
    SELECT
        m.movie_id,
        m.title,
        m.release_year,
        m.view_count,
        COALESCE(ROUND(AVG(r.rating_value), 1), 0) AS avg_rating
    FROM dbProj_movies m
    LEFT JOIN dbProj_ratings r ON r.movie_id = m.movie_id
    WHERE m.genre_id = p_genre AND m.is_published = 1
    GROUP BY m.movie_id, m.title, m.release_year, m.view_count
    ORDER BY m.created_at DESC;
END $$

-- 5) Full report of one creator's movies -------------------------------------
DROP PROCEDURE IF EXISTS sp_creator_report $$
CREATE PROCEDURE sp_creator_report(IN p_creator INT)
BEGIN
    SELECT
        m.movie_id,
        m.title,
        g.genre_name,
        m.is_published,
        m.view_count,
        COALESCE(ROUND(AVG(r.rating_value), 1), 0) AS avg_rating,
        COUNT(DISTINCT r.rating_id)  AS total_ratings,
        COUNT(DISTINCT c.comment_id) AS total_comments
    FROM dbProj_movies m
    JOIN dbProj_genres g ON g.genre_id = m.genre_id
    LEFT JOIN dbProj_ratings  r ON r.movie_id = m.movie_id
    LEFT JOIN dbProj_comments c ON c.movie_id = m.movie_id
    WHERE m.creator_id = p_creator
    GROUP BY m.movie_id, m.title, g.genre_name, m.is_published, m.view_count
    ORDER BY m.created_at DESC;
END $$

DELIMITER ;
