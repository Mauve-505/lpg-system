CREATE DATABASE IF NOT EXISTS lpg_db CHARACTER SET utf8mb4;
USE lpg_db;

CREATE TABLE cylinder_sizes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  label VARCHAR(30) NOT NULL UNIQUE,
  selling_price DECIMAL(10,2) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1
);
CREATE TABLE customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  contact VARCHAR(50),
  address VARCHAR(150),
  is_active TINYINT(1) NOT NULL DEFAULT 1
);
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(100) NOT NULL,
  role ENUM('owner','staff','customer') NOT NULL DEFAULT 'staff',
  customer_id INT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id)
);
CREATE TABLE plant_deliveries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  delivery_date DATE NOT NULL,
  receipt_no VARCHAR(50) NOT NULL,
  remarks VARCHAR(200),
  received_by INT NOT NULL,
  FOREIGN KEY (received_by) REFERENCES users(id)
);
CREATE TABLE plant_delivery_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  delivery_id INT NOT NULL,
  size_id INT NOT NULL,
  full_received INT NOT NULL DEFAULT 0,
  empties_returned INT NOT NULL DEFAULT 0,
  FOREIGN KEY (delivery_id) REFERENCES plant_deliveries(id) ON DELETE CASCADE,
  FOREIGN KEY (size_id) REFERENCES cylinder_sizes(id)
);
CREATE TABLE sales (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sale_date DATE NOT NULL,
  customer_id INT NULL,
  payment_type ENUM('cash','credit') NOT NULL,
  created_by INT NOT NULL,
  FOREIGN KEY (customer_id) REFERENCES customers(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
);
CREATE TABLE sale_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sale_id INT NOT NULL,
  size_id INT NOT NULL,
  qty INT NOT NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  empties_received INT NOT NULL DEFAULT 0,
  FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
  FOREIGN KEY (size_id) REFERENCES cylinder_sizes(id)
);
CREATE TABLE payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  payment_date DATE NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  created_by INT NOT NULL,
  FOREIGN KEY (customer_id) REFERENCES customers(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
);
CREATE TABLE stock_adjustments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  adj_date DATE NOT NULL,
  size_id INT NOT NULL,
  reason VARCHAR(50) NOT NULL,
  full_change INT NOT NULL DEFAULT 0,
  empty_change INT NOT NULL DEFAULT 0,
  created_by INT NOT NULL,
  FOREIGN KEY (size_id) REFERENCES cylinder_sizes(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
);
-- Ledger: every delivery, sale and adjustment writes rows here. Stock = SUM of this table.
CREATE TABLE stock_movements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  move_date DATE NOT NULL,
  size_id INT NOT NULL,
  ref_type ENUM('delivery','sale','adjustment') NOT NULL,
  ref_id INT NOT NULL,
  full_change INT NOT NULL DEFAULT 0,
  empty_change INT NOT NULL DEFAULT 0,
  out_change INT NOT NULL DEFAULT 0,
  INDEX (size_id), INDEX (ref_type, ref_id),
  FOREIGN KEY (size_id) REFERENCES cylinder_sizes(id)
);
CREATE TABLE orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  size_id INT NOT NULL,
  qty INT NOT NULL,
  payment_type ENUM('cash','credit') NOT NULL DEFAULT 'cash',
  notes VARCHAR(200),
  status ENUM('pending','approved','delivered','cancelled') NOT NULL DEFAULT 'pending',
  order_date DATE NOT NULL,
  sale_id INT NULL,
  FOREIGN KEY (customer_id) REFERENCES customers(id),
  FOREIGN KEY (size_id) REFERENCES cylinder_sizes(id)
);

INSERT INTO cylinder_sizes (label, selling_price) VALUES ('11kg',950),('22kg',1850),('50kg',4100);
