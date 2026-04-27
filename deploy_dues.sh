#!/bin/bash
set -e

# Step 1: Create DB tables
echo "=== Creating DB tables ==="
mysql -ufootball_user -pTf_Secure2026! trust_football <<'SQLEOF'
CREATE TABLE IF NOT EXISTS team_dues_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_id INT NOT NULL,
    monthly_fee INT NOT NULL DEFAULT 30000,
    description TEXT,
    updated_by INT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY (team_id)
);

CREATE TABLE IF NOT EXISTS team_dues_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_id INT NOT NULL,
    user_id INT NOT NULL,
    `year_month` VARCHAR(7) NOT NULL,
    amount INT NOT NULL,
    `status` ENUM('paid','unpaid','partial','exempt') DEFAULT 'unpaid',
    paid_at DATETIME,
    note TEXT,
    recorded_by INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (team_id, user_id, `year_month`)
);
SQLEOF
echo "DB tables created."

# Step 2: Apply PHP patches
echo "=== Applying PHP patches ==="
cd /var/www/html
sudo php /tmp/patch_dues.php
echo "=== PHP patches applied ==="

# Step 3: Syntax check
echo "=== Syntax check ==="
php -l /var/www/html/app.php
echo "=== DONE ==="
