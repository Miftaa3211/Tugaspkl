<?php
echo "==================================================\n";
echo "   INSTALLER XCODEHOSTER V11 - VERSI LENGKAP      \n";
echo "           DEVELOPED BY MAS RIZKY                 \n";
echo "==================================================\n\n";

// [1/6] Input Data (Sesuai Tantangan Mas Kur)
$domain = "tugaspkl.my.id";
$email = readline("Masukkan Email Cloudflare   : ");
$api_key = readline("Masukkan Global API Key     : ");
$zone_id = readline("Masukkan Zone ID Cloudflare : ");
$db_pass = readline("Masukkan Password Root MySQL: ");

echo "\n--- Input Sertifikat SSL (Dapatkan dari Cloudflare Origin Server) ---\n";
echo "Tempelkan isi file .pem lalu tekan Enter dan Ctrl+D (di Linux):\n";
$ssl_pem = file_get_contents('php://stdin'); 
echo "Tempelkan isi file .key lalu tekan Enter dan Ctrl+D (di Linux):\n";
$ssl_key = file_get_contents('php://stdin');

// [2/6] Memindahkan File Website
echo "[2/6] Memindahkan file ke /home/xcodehoster...\n";
shell_exec("sudo mkdir -p /home/xcodehoster");
shell_exec("sudo mkdir -p /etc/apache2/ssl");
shell_exec("sudo cp -r ./* /home/xcodehoster/");
shell_exec("sudo chmod -R 755 /home/xcodehoster");

// [3/6] Membuat Database
echo "[3/6] Membuat database xcodehoster...\n";
// Menggunakan password yang diinput user
$db_cmd = "mysql -u root -p'$db_pass' -e 'CREATE DATABASE IF NOT EXISTS xcodehoster;' 2>/dev/null";
shell_exec($db_cmd);

// [4/6] Menulis Sertifikat SSL ke System
echo "[4/6] Menanam sertifikat SSL .pem dan .key...\n";
file_put_contents("/etc/apache2/ssl/$domain.pem", $ssl_pem);
file_put_contents("/etc/apache2/ssl/$domain.key", $ssl_key);

// [5/6] Konfigurasi Apache (Otomatis Port 80 & 443)
echo "[5/6] Mengatur VirtualHost Apache...\n";
$vhost = "
<VirtualHost *:80>
    ServerName $domain
    ServerAlias www.$domain
    Redirect permanent / https://$domain/
</VirtualHost>

<VirtualHost *:443>
    ServerName $domain
    DocumentRoot /home/xcodehoster
    SSLEngine on
    SSLCertificateFile /etc/apache2/ssl/$domain.pem
    SSLCertificateKeyFile /etc/apache2/ssl/$domain.key
    <Directory /home/xcodehoster>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>";
file_put_contents("/etc/apache2/sites-enabled/$domain.conf", $vhost);

// [6/6] Selesai
echo "[6/6] Me-restart layanan...\n";
shell_exec("sudo systemctl restart apache2");

echo "\n==================================================\n";
echo "INSTALASI SELESAI! Silakan akses https://$domain\n";
echo "==================================================\n";
?>