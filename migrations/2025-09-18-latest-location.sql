-- 2025-09-18 Add latest_vehicle_location table and supporting index

CREATE TABLE IF NOT EXISTS latest_vehicle_location (
  vehicle_id INT PRIMARY KEY,
  lat DOUBLE NOT NULL,
  lng DOUBLE NOT NULL,
  speed DOUBLE DEFAULT 0,
  heading DOUBLE DEFAULT NULL,
  event_time DATETIME NOT NULL,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_latest_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Optional performance index for gps_events if not present
CREATE INDEX IF NOT EXISTS idx_gps_events_vehicle_time ON gps_events(vehicle_id, event_time);
