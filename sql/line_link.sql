-- Create link_tokens table if missing
CREATE TABLE IF NOT EXISTS link_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token VARCHAR(32) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  used_at DATETIME DEFAULT NULL,
  line_user_id VARCHAR(64) DEFAULT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id)
);
