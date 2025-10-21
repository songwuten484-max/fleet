-- Optional: speed up availability checks
CREATE INDEX IF NOT EXISTS idx_bookings_vehicle_time ON bookings (vehicle_id, start_datetime, end_datetime, status);

-- Optional (advanced): trigger to block overlapping bookings for the same vehicle (MySQL 5.7+/8.0)
DELIMITER //
CREATE TRIGGER bookings_no_overlap BEFORE INSERT ON bookings
FOR EACH ROW
BEGIN
  IF EXISTS (
    SELECT 1 FROM bookings b
    WHERE b.vehicle_id = NEW.vehicle_id
      AND b.status IN ('PENDING','APPROVED_L1','APPROVED')
      AND NOT (b.end_datetime <= NEW.start_datetime OR b.start_datetime >= NEW.end_datetime)
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Overlapping booking for this vehicle';
  END IF;
END//
DELIMITER ;
