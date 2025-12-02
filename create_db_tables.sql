CREATE TABLE participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name  VARCHAR(100) NOT NULL,
    email      VARCHAR(255) NOT NULL,
    family_unit INT NOT NULL
);

CREATE TABLE secret_santa_pairs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year INT NOT NULL,
    giver_id INT NOT NULL,
    receiver_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_giver FOREIGN KEY (giver_id) REFERENCES participants(id),
    CONSTRAINT fk_receiver FOREIGN KEY (receiver_id) REFERENCES participants(id)
);
