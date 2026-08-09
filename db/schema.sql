-- Travel Agency Backend MySQL schema
-- Matches the current PHP controllers in this repository.

CREATE DATABASE IF NOT EXISTS travel_agency_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE travel_agency_db;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  phone VARCHAR(30) NULL,
  role ENUM('traveler', 'agent', 'admin') NOT NULL DEFAULT 'traveler',
  is_email_verified TINYINT(1) NOT NULL DEFAULT 0,
  otp VARCHAR(6) NULL,
  otp_expires_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role),
  KEY idx_users_otp (otp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS otp_rate_limits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  identifier VARCHAR(190) NOT NULL,
  action_type VARCHAR(80) NOT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 1,
  last_attempt_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_otp_rate_identifier_action (identifier, action_type),
  KEY idx_otp_rate_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS destinations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  city VARCHAR(150) NOT NULL,
  country VARCHAR(150) NOT NULL,
  region_tag VARCHAR(100) NOT NULL DEFAULT 'General',
  thumbnail_url VARCHAR(2048) NULL,
  description TEXT NULL,
  rating DECIMAL(3,2) NOT NULL DEFAULT 4.80,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_destinations_city_country (city, country),
  KEY idx_destinations_country (country),
  KEY idx_destinations_region_tag (region_tag)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS packages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  destination_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(220) NOT NULL,
  description TEXT NOT NULL,
  base_price DECIMAL(12,2) NOT NULL,
  duration_days INT UNSIGNED NOT NULL,
  status ENUM('active', 'inactive', 'draft', 'archived') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_packages_destination_id (destination_id),
  KEY idx_packages_status (status),
  KEY idx_packages_price (base_price),
  CONSTRAINT fk_packages_destination
    FOREIGN KEY (destination_id) REFERENCES destinations (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_packages_price CHECK (base_price > 0),
  CONSTRAINT chk_packages_duration CHECK (duration_days > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS package_schedules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  package_id BIGINT UNSIGNED NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  total_seats INT UNSIGNED NOT NULL,
  available_seats INT UNSIGNED NOT NULL,
  price DECIMAL(12,2) NOT NULL,
  status ENUM('open', 'sold_out', 'closed', 'cancelled') NOT NULL DEFAULT 'open',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_package_schedules_package_id (package_id),
  KEY idx_package_schedules_status_start (status, start_date),
  CONSTRAINT fk_package_schedules_package
    FOREIGN KEY (package_id) REFERENCES packages (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT chk_package_schedules_dates CHECK (end_date >= start_date),
  CONSTRAINT chk_package_schedules_total CHECK (total_seats > 0),
  CONSTRAINT chk_package_schedules_available CHECK (available_seats <= total_seats),
  CONSTRAINT chk_package_schedules_price CHECK (price > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS package_itineraries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  package_id BIGINT UNSIGNED NOT NULL,
  day_number INT UNSIGNED NOT NULL,
  title VARCHAR(220) NOT NULL,
  description TEXT NOT NULL,
  activity_location VARCHAR(220) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_package_itineraries_day (package_id, day_number),
  CONSTRAINT fk_package_itineraries_package
    FOREIGN KEY (package_id) REFERENCES packages (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT chk_package_itineraries_day CHECK (day_number > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS package_photos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  package_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  photo_url VARCHAR(2048) NOT NULL,
  cloudinary_public_id VARCHAR(255) NULL,
  caption VARCHAR(255) NULL,
  photo_type ENUM('cover', 'marketing', 'gallery') NOT NULL DEFAULT 'gallery',
  is_approved TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_package_photos_package_type (package_id, photo_type),
  KEY idx_package_photos_approved (is_approved),
  KEY idx_package_photos_user_id (user_id),
  CONSTRAINT fk_package_photos_package
    FOREIGN KEY (package_id) REFERENCES packages (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_package_photos_user
    FOREIGN KEY (user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bookings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_reference VARCHAR(40) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  schedule_id BIGINT UNSIGNED NOT NULL,
  seats_booked INT UNSIGNED NOT NULL,
  total_amount DECIMAL(12,2) NOT NULL,
  status ENUM('pending', 'confirmed', 'cancelled', 'completed') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bookings_reference (booking_reference),
  KEY idx_bookings_user_id (user_id),
  KEY idx_bookings_schedule_id (schedule_id),
  KEY idx_bookings_status_created (status, created_at),
  CONSTRAINT fk_bookings_user
    FOREIGN KEY (user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_bookings_schedule
    FOREIGN KEY (schedule_id) REFERENCES package_schedules (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_bookings_seats CHECK (seats_booked > 0),
  CONSTRAINT chk_bookings_amount CHECK (total_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS booking_passengers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id BIGINT UNSIGNED NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  id_type ENUM('passport', 'nin', 'national_id') NOT NULL,
  id_number VARCHAR(120) NOT NULL,
  emergency_contact_name VARCHAR(150) NOT NULL,
  emergency_contact_phone VARCHAR(30) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_booking_passengers_booking_id (booking_id),
  CONSTRAINT fk_booking_passengers_booking
    FOREIGN KEY (booking_id) REFERENCES bookings (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id BIGINT UNSIGNED NOT NULL,
  transaction_ref VARCHAR(80) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  payment_method VARCHAR(50) NOT NULL DEFAULT 'card',
  status ENUM('pending', 'completed', 'failed', 'refunded') NOT NULL DEFAULT 'pending',
  paid_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payments_transaction_ref (transaction_ref),
  KEY idx_payments_booking_id (booking_id),
  KEY idx_payments_status (status),
  CONSTRAINT fk_payments_booking
    FOREIGN KEY (booking_id) REFERENCES bookings (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT chk_payments_amount CHECK (amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reviews (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  package_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  booking_id BIGINT UNSIGNED NOT NULL,
  rating TINYINT UNSIGNED NOT NULL,
  comment TEXT NOT NULL,
  is_approved TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reviews_booking_id (booking_id),
  KEY idx_reviews_package_approved (package_id, is_approved),
  KEY idx_reviews_user_id (user_id),
  CONSTRAINT fk_reviews_package
    FOREIGN KEY (package_id) REFERENCES packages (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_reviews_user
    FOREIGN KEY (user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_reviews_booking
    FOREIGN KEY (booking_id) REFERENCES bookings (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT chk_reviews_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS media_assets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id VARCHAR(255) NULL,
  asset_url VARCHAR(2048) NULL,
  secure_url VARCHAR(2048) NULL,
  resource_type VARCHAR(50) NOT NULL DEFAULT 'image',
  format VARCHAR(30) NULL,
  bytes BIGINT UNSIGNED NULL,
  width INT UNSIGNED NULL,
  height INT UNSIGNED NULL,
  folder VARCHAR(255) NULL,
  original_filename VARCHAR(255) NULL,
  uploaded_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_media_assets_uploaded_by (uploaded_by),
  KEY idx_media_assets_public_id (public_id),
  CONSTRAINT fk_media_assets_user
    FOREIGN KEY (uploaded_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_settings (
  setting_key VARCHAR(120) NOT NULL,
  setting_value TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES
  ('app_name', 'Travel Agency'),
  ('currency', 'NGN'),
  ('booking_expiry_hours', '24');
