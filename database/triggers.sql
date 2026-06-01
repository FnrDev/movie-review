-- Audit table the AFTER-INSERT triggers write into ---------------------------
CREATE TABLE IF NOT EXISTS dbProj_activity_log (
    log_id      INT AUTO_INCREMENT PRIMARY KEY,
    action_type VARCHAR(40)  NOT NULL,
    description VARCHAR(255) NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_action (action_type),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$

-- 1) Validate a rating is within 0–5 before it is stored ----------------------
DROP TRIGGER IF EXISTS trg_ratings_before_insert $$
CREATE TRIGGER trg_ratings_before_insert
BEFORE INSERT ON dbProj_ratings
FOR EACH ROW
BEGIN
    IF NEW.rating_value < 0 OR NEW.rating_value > 5 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Rating must be between 0 and 5.';
    END IF;
END $$

DROP TRIGGER IF EXISTS trg_ratings_before_update $$
CREATE TRIGGER trg_ratings_before_update
BEFORE UPDATE ON dbProj_ratings
FOR EACH ROW
BEGIN
    IF NEW.rating_value < 0 OR NEW.rating_value > 5 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Rating must be between 0 and 5.';
    END IF;
END $$

-- 2) Log new movies ----------------------------------------------------------
DROP TRIGGER IF EXISTS trg_movies_after_insert $$
CREATE TRIGGER trg_movies_after_insert
AFTER INSERT ON dbProj_movies
FOR EACH ROW
BEGIN
    INSERT INTO dbProj_activity_log (action_type, description)
    VALUES ('movie_added',
            CONCAT('Movie #', NEW.movie_id, ' "', NEW.title, '" was added.'));
END $$

-- 3) Log new ratings ---------------------------------------------------------
DROP TRIGGER IF EXISTS trg_ratings_after_insert $$
CREATE TRIGGER trg_ratings_after_insert
AFTER INSERT ON dbProj_ratings
FOR EACH ROW
BEGIN
    INSERT INTO dbProj_activity_log (action_type, description)
    VALUES ('rating_added',
            CONCAT('User #', NEW.user_id, ' rated movie #', NEW.movie_id,
                   ' (', NEW.rating_value, '/5).'));
END $$

-- 4) Log new comments --------------------------------------------------------
DROP TRIGGER IF EXISTS trg_comments_after_insert $$
CREATE TRIGGER trg_comments_after_insert
AFTER INSERT ON dbProj_comments
FOR EACH ROW
BEGIN
    INSERT INTO dbProj_activity_log (action_type, description)
    VALUES ('comment_added',
            CONCAT('User #', NEW.user_id, ' commented on movie #', NEW.movie_id, '.'));
END $$

DELIMITER ;
