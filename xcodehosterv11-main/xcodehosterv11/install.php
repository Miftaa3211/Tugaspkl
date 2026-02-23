<?php
//Judul: Xcodehoster Installer (PHP Version)

function run($cmd) {
    passthru($cmd);
}

if (posix_getuid() !== 0) {
    die("ERROR: Script ini harus dijalankan sebagai root (sudo).\n");
}

echo "=================================================\n";
echo "   XCODEHOSTER - PHP INSTALLER (PUBLIC VPS)\n";
echo "=================================================\n\n";

// 1. INPUT DATA USER & CLOUDFLARE
echo "--- PENGATURAN SERVER ---\n";
$ipserver   = readline("Masukkan IP Publik VPS        : ");
$domain     = readline("Masukkan Domain (tugaspkl.my.id): ");
$pass_mysql = readline("Masukkan Password Root MySQL  : ");

echo "\n--- PENGATURAN CLOUDFLARE API ---\n";
$email_cf = readline("Masukkan Email Cloudflare     : ");
$api_key  = readline("Masukkan Global API Key       : ");
$zone_id  = readline("Masukkan Zone ID Cloudflare   : "); 

echo "\n[INFO] Memulai instalasi dalam 3 detik...\n";
sleep(3);

// 2. OTOMATISASI DNS CLOUDFLARE
echo "\n[1/6] Menghubungkan Domain ke Cloudflare API...\n";
$data_dns = [
    "type"    => "A",
    "name"    => "@",
    "content" => $ipserver,
    "ttl"     => 1,
    "proxied" => true
];

$ch = curl_init("https://api.cloudflare.com/client/v4/zones/$zone_id/dns_records");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "X-Auth-Email: $email_cf",
    "X-Auth-Key: $api_key",
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data_dns));
$response = curl_exec($ch);
$res_obj = json_decode($response);

if ($res_obj->success) {
    echo "[OK] DNS Record berhasil dibuat secara otomatis!\n";
} else {
    echo "[WARN] Gagal membuat DNS Record (mungkin IP sudah terdaftar).\n";
}
curl_close($ch);

// 3. INSTALL DEPENDENCIES (LAMP STACK)
echo "\n[2/6] Menginstall Web Server & Database...\n";
run("apt-get update");
run("apt install -y apache2 php php-curl mysql-server phpmyadmin zip unzip wget curl libapache2-mod-php");

run("a2enmod ssl cgi rewrite");

// 4. KONFIGURASI DATABASE
echo "\n[3/6] Mengkonfigurasi MySQL...\n";
shell_exec("mysql -e \"ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '$pass_mysql';\"");
shell_exec("mysql -e \"FLUSH PRIVILEGES;\"");

// 5. STRUKTUR FOLDER & PENYESUAIAN
echo "\n[4/6] Membuat Struktur Direktori...\n";
$folders = ["/home/root", "/home/pma", "/home/www", "/home/datauser", "/home/domain"];
foreach ($folders as $f) {
    if (!is_dir($f)) mkdir($f, 0755, true);
}
run("chmod -R 777 /home");

// Sed file konfigurasi
$files_to_edit = ["support/subdomain.conf", "support/domain.conf", "support/run.cgi"];
foreach ($files_to_edit as $file) {
    if (file_exists($file)) {
        shell_exec("sed -i 's/xcodehoster.com/$domain/g' $file");
    }
}

// 6. FIX PHPMYADMIN
echo "\n[5/6] Membuat Symlink phpMyAdmin...\n";
run("ln -s /usr/share/phpmyadmin /var/www/html/phpmyadmin");

echo "\n[6/6] Merestart Service...\n";
run("service apache2 restart");

echo "\n=============================================\n";
echo "   INSTALASI SISTEM SELESAI! \n";
echo "=============================================\n";
?>