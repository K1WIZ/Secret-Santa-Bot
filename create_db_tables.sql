--
-- Secret Santa Database Initialization Script
-- Creates the participants table, pairing history table,
-- and includes wishlist-related fields.
--

CREATE TABLE participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name  VARCHAR(100) NOT NULL,
    email      VARCHAR(255) NOT NULL,
    family_unit INT NOT NULL,

    -- Wishlist fields
    wish_item1 VARCHAR(255) NULL,
    wish_item2 VARCHAR(255) NULL,
    wish_item3 VARCHAR(255) NULL,

    -- Per-user secret key for wishlist access
    wish_key   VARCHAR(64)  NULL,

    -- Optional but recommended: enforce unique keys once generated
    UNIQUE KEY uniq_wish_key (wish_key)
);

-- Table storing pairings for each year
CREATE TABLE secret_santa_pairs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year INT NOT NULL,
    giver_id INT NOT NULL,
    receiver_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
