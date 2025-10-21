SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(120) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('ADMIN','APPROVER_L1','APPROVER_L2','USER','DRIVER') NOT NULL DEFAULT 'USER',
  line_user_id VARCHAR(64) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS vehicles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  plate_no VARCHAR(32) NOT NULL,
  brand_model VARCHAR(120) NOT NULL,
  fuel_rate_per_km DECIMAL(10,2) DEFAULT NULL,
  gps_device_id VARCHAR(64) DEFAULT NULL,
  active TINYINT(1) DEFAULT 1
);

CREATE TABLE IF NOT EXISTS bookings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  vehicle_id INT NOT NULL,
  purpose VARCHAR(255) NOT NULL,
  start_datetime DATETIME NOT NULL,
  end_datetime DATETIME NOT NULL,
  destination VARCHAR(255) DEFAULT NULL,
  status ENUM('PENDING','APPROVED_L1','APPROVED','REJECTED','CANCELLED') DEFAULT 'PENDING',
  approval_stage TINYINT NOT NULL DEFAULT 0,
  approval_required TINYINT NOT NULL DEFAULT 2,
  approver_l1_id INT DEFAULT NULL,
  approver_l1_at DATETIME DEFAULT NULL,
  approver_l2_id INT DEFAULT NULL,
  approver_l2_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX(user_id), INDEX(vehicle_id),
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id)
);

CREATE TABLE IF NOT EXISTS trips (
  id INT AUTO_INCREMENT PRIMARY KEY,
  booking_id INT NOT NULL,
  vehicle_id INT NOT NULL,
  driver_id INT DEFAULT NULL,
  start_odometer INT DEFAULT NULL,
  end_odometer INT DEFAULT NULL,
  actual_start DATETIME NOT NULL,
  actual_end DATETIME DEFAULT NULL,
  total_km DECIMAL(10,2) DEFAULT NULL,
  gps_total_km DECIMAL(10,2) DEFAULT NULL,
  distance_source ENUM('ODOMETER','GPS') DEFAULT 'ODOMETER',
  fuel_cost DECIMAL(10,2) DEFAULT NULL,
  FOREIGN KEY (booking_id) REFERENCES bookings(id),
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id)
);

CREATE TABLE IF NOT EXISTS gps_events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  vehicle_id INT NOT NULL,
  lat DECIMAL(10,6) NOT NULL,
  lng DECIMAL(10,6) NOT NULL,
  speed DECIMAL(10,2) DEFAULT NULL,
  event_time DATETIME NOT NULL,
  raw JSON DEFAULT NULL,
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
  INDEX(vehicle_id), INDEX(event_time)
);

CREATE TABLE IF NOT EXISTS alerts (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  vehicle_id INT NOT NULL,
  type ENUM('GEOFENCE','UNBOOKED_MOVE') NOT NULL,
  message VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Seed users (password = admin123; bcrypt hash below)
INSERT INTO users (name,email,password_hash,role) VALUES
('Administrator','admin@example.com','$2b$10$.azsWrWLks8FT8vH/bBSPuFBLEuo2TWd0kkHZq0prae9TK6sbCvu6','ADMIN')
ON DUPLICATE KEY UPDATE name=VALUES(name);
INSERT INTO users (name,email,password_hash,role) VALUES
('Approver Level 1','approver1@example.com','$2b$10$.azsWrWLks8FT8vH/bBSPuFBLEuo2TWd0kkHZq0prae9TK6sbCvu6','APPROVER_L1')
ON DUPLICATE KEY UPDATE name=VALUES(name);
INSERT INTO users (name,email,password_hash,role) VALUES
('Approver Level 2','approver2@example.com','$2b$10$.azsWrWLks8FT8vH/bBSPuFBLEuo2TWd0kkHZq0prae9TK6sbCvu6','APPROVER_L2')
ON DUPLICATE KEY UPDATE name=VALUES(name);
