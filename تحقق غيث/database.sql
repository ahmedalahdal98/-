SET NAMES utf8mb4;
SET time_zone = '+03:00';

CREATE TABLE IF NOT EXISTS employees (
  id VARCHAR(36) PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_blocked TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS colleges (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category ENUM('health','science','literary') NOT NULL,
  name VARCHAR(150) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY unique_college (category, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS verification_methods (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL UNIQUE,
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS records (
  id VARCHAR(36) PRIMARY KEY,
  student_name VARCHAR(100) NOT NULL,
  student_gender VARCHAR(10) NOT NULL,
  waed_program VARCHAR(3) NOT NULL,
  previous_batch VARCHAR(20) NOT NULL,
  phone VARCHAR(10) NOT NULL,
  university_id VARCHAR(7) NOT NULL,
  college VARCHAR(150) NOT NULL,
  verification_method VARCHAR(200) NOT NULL,
  employee_id VARCHAR(36) NOT NULL,
  employee_name VARCHAR(100) NOT NULL,
  has_warning TINYINT(1) NOT NULL DEFAULT 0,
  warning_reason VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  INDEX idx_phone (phone),
  INDEX idx_university_id (university_id),
  INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alerts (
  id VARCHAR(36) PRIMARY KEY,
  record_id VARCHAR(36) NOT NULL,
  reason VARCHAR(255) NOT NULL,
  employee_note TEXT NOT NULL,
  employee_id VARCHAR(36) NOT NULL,
  employee_name VARCHAR(100) NOT NULL,
  student_name VARCHAR(100) NOT NULL,
  student_gender VARCHAR(10) NOT NULL,
  waed_program VARCHAR(3) NOT NULL,
  previous_batch VARCHAR(20) NOT NULL,
  phone VARCHAR(10) NOT NULL,
  university_id VARCHAR(7) NOT NULL,
  college VARCHAR(150) NOT NULL,
  verification_method VARCHAR(200) NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  INDEX idx_alert_record (record_id),
  INDEX idx_alert_read (is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blocked_phones (
  phone VARCHAR(10) PRIMARY KEY,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_settings (
  setting_key VARCHAR(100) PRIMARY KEY,
  setting_value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO colleges (category, name, sort_order) VALUES
('health','كلية الطب',1),
('health','كلية طب الأسنان',2),
('health','كلية الصيدلة',3),
('health','كلية التمريض',4),
('health','كلية العلوم الطبية التطبيقية',5),
('science','كلية الحاسب',1),
('science','كلية الهندسة',2),
('science','كلية العلوم',3),
('literary','كلية الآداب',1),
('literary','كلية التربية',2),
('literary','كلية إدارة الأعمال',3),
('literary','كلية الشريعة والقانون',4);

INSERT IGNORE INTO verification_methods (name, sort_order) VALUES
('مطابقة الهوية',1),
('التحقق عبر النظام',2),
('اتصال هاتفي',3);
