<?php
/**
 * Xcodehoster v11 - Installer CLI (PHP)
 * Programmer : Kurniawan. kurniawanajazenfone@gmail.com. xcode.or.id.
 * X-code Media - xcode.or.id / xcode.co.id
 *
 * Cara pakai: sudo php install.php
 * (harus dijalankan dari dalam folder xcodehosterv11)
 */

// ── Pastikan dijalankan dari CLI ──────────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    die("❌ File ini hanya boleh dijalankan dari terminal.\nCara: sudo php install.php\n");
}

// ── Pastikan dijalankan sebagai root / sudo ───────────────────────────────────
if (posix_getuid() !== 0) {
    die("❌ Harus dijalankan dengan sudo.\nCara: sudo php install.php\n");
}

// ── Cek Ubuntu 24.04 ──────────────────────────────────────────────────────────
$version = trim(shell_exec("lsb_release -r | awk '{print $2}'"));
echo "Xcodehoster v11 - 6 Oktober 2025\n";
if ($version !== '24.04') {
    die("❌ Aplikasi ini tidak mendukung distro Linux Anda.\n   Install pada Ubuntu versi 24.04 (versi Anda: $version)\n");
}
echo "✅ Versi Ubuntu anda didukung oleh aplikasi ini, Ubuntu $version\n\n";

// ── Helper functions ──────────────────────────────────────────────────────────
function tanya(string $pertanyaan): string {
    echo $pertanyaan;
    return trim(fgets(STDIN));
}

function jalankan(string $cmd, bool $tampilkan = false): string {
    $output = [];
    exec($cmd . ' 2>&1', $output);
    $hasil = implode("\n", $output);
    if ($tampilkan && $hasil) echo $hasil . "\n";
    return $hasil;
}

function log_ok(string $pesan): void {
    echo "  ✅ $pesan\n";
}

function log_info(string $pesan): void {
    echo "  ⏳ $pesan\n";
}

function log_warn(string $pesan): void {
    echo "  ⚠️  $pesan\n";
}

// ── Direktori installer ───────────────────────────────────────────────────────
$installDir  = __DIR__;
$supportDir  = $installDir . '/support';
$fmDir       = $installDir . '/filemanager';
$backupDir   = $installDir . '/backup';

if (!is_dir($supportDir)) {
    die("❌ Folder 'support' tidak ditemukan.\n   Pastikan install.php ada di dalam folder xcodehosterv11.\n");
}

// ══════════════════════════════════════════════════════════════════════════════
// LANGKAH 1: Install paket-paket sistem
// ══════════════════════════════════════════════════════════════════════════════
echo "\n=== [1/14] Update & Install Paket Sistem ===\n";
log_info("Menjalankan apt-get update...");
jalankan("apt-get update -y");
log_ok("apt update selesai");

log_info("Install software-properties-common...");
jalankan("apt -y install software-properties-common");
log_ok("software-properties-common terinstall");

log_info("Install Apache2...");
jalankan("apt install -y apache2");
log_ok("Apache2 terinstall");

log_info("Install PHP...");
jalankan("apt install -y php");
log_ok("PHP terinstall");

log_info("Install MySQL Server...");
jalankan("apt install -y mysql-server");
log_ok("MySQL Server terinstall");

// ══════════════════════════════════════════════════════════════════════════════
// LANGKAH 2: Input dari pengguna
// ══════════════════════════════════════════════════════════════════════════════
echo "\n=== [2/14] Konfigurasi Server ===\n";
$ipserver = tanya("Masukkan ip publik server : ");
$passwordmysql = tanya("Masukkan password root MySQL yang akan dibuat : ");

// ── Set password root MySQL ───────────────────────────────────────────────────
echo "\n=== [3/14] Konfigurasi MySQL Root ===\n";
log_info("Mengatur password root MySQL...");
jalankan("mysql -e \"ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '$passwordmysql';\"");
log_ok("Password root MySQL berhasil diset");

// ══════════════════════════════════════════════════════════════════════════════
// LANGKAH 4: Install phpMyAdmin & tambahan
// ══════════════════════════════════════════════════════════════════════════════
echo "\n=== [4/14] Install phpMyAdmin & Paket Tambahan ===\n";
log_info("Install phpMyAdmin (ini memerlukan interaksi - ikuti petunjuk di layar)...\n");
// phpMyAdmin butuh interaksi, jalankan langsung ke terminal
system("DEBIAN_FRONTEND=noninteractive apt install -y phpmyadmin");
log_ok("phpMyAdmin terinstall");

log_info("Install zip, unzip, php-zip...");
jalankan("apt-get install -y zip unzip php-zip");
log_ok("zip/unzip/php-zip terinstall");

log_info("Install jq & imagemagick...");
jalankan("apt install -y jq imagemagick");
log_ok("jq & imagemagick terinstall");

// ══════════════════════════════════════════════════════════════════════════════
// LANGKAH 5: Aktifkan Apache module SSL
// ══════════════════════════════════════════════════════════════════════════════
echo "\n=== [5/14] Aktifkan Apache Modules ===\n";
jalankan("a2enmod ssl cgi rewrite headers");
jalankan("service apache2 restart");
log_ok("Apache module SSL, CGI, Rewrite, Headers aktif");

// ══════════════════════════════════════════════════════════════════════════════
// LANGKAH 6: Backup & copy apache2.conf
// ══════════════════════════════════════════════════════════════════════════════
echo "\n=== [6/14] Konfigurasi Apache ===\n";
if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
jalankan("cp /etc/apache2/apache2.conf $backupDir/apache2.conf.backup");
log_ok("Backup apache2.conf tersimpan di $backupDir");

// Patch apache2.conf agar /home bisa diakses subdomain (tidak 403)
$apacheConf = file_get_contents('/etc/apache2/apache2.conf');
if (strpos($apacheConf, '<Directory /home>') === false) {
    $tambahan = "\n<Directory /home>\n    Options Indexes FollowSymLinks\n    AllowOverride All\n    Require all granted\n</Directory>\n";
    file_put_contents('/etc/apache2/apache2.conf', $apacheConf . $tambahan);
    log_ok("apache2.conf: blok <Directory /home> ditambahkan");
} else {
    jalankan("sed -i '/<Directory \\/home>/,/<\\/Directory>/ s/Require all denied/Require all granted/g' /etc/apache2/apache2.conf");
    log_ok("apache2.conf: <Directory /home> sudah ada, Require all granted dipastikan");
}

// chmod o+x /home agar Apache bisa baca
jalankan("chmod o+x /home");
log_ok("chmod o+x /home — Apache bisa akses direktori user");

// ══════════════════════════════════════════════════════════════════════════════
// LANGKAH 7: Input domain
// ══════════════════════════════════════════════════════════════════════════════
echo "\n=== [7/14] Konfigurasi Domain ===\n";
$domain = tanya("Masukkan nama domain (tanpa http:// dan www) : ");

// Validasi domain sederhana
if (!preg_match('/^[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/', $domain)) {
    die("❌ Format domain tidak valid: $domain\n");
}

// ── PHP.ini backup & copy ─────────────────────────────────────────────────────
$phpVersion = trim(shell_exec("php -r 'echo PHP_MAJOR_VERSION.\".\".PHP_MINOR_VERSION;'"));
$phpIniPath = "/etc/php/$phpVersion/apache2/php.ini";
if (file_exists($phpIniPath) && file_exists("$supportDir/php.ini")) {
    jalankan("cp $phpIniPath {$phpIniPath}.backup");
    jalankan("cp $supportDir/php.ini $phpIniPath");
    log_ok("php.ini dikonfigurasi untuk PHP $phpVersion");
}

// ══════════════════════════════════════════════════════════════════════════════
// LANGKAH 8: Buat direktori sistem
// ══════════════════════════════════════════════════════════════════════════════
echo "\n=== [8/14] Membuat Direktori Sistem ===\n";
$dirs = [
    '/home/root', '/home/pma', '/home/www', '/home/datauser',
    '/home/xcodehoster', '/home/datapengguna', '/home/domain',
    '/home/checkdata', '/home/checkdata2', '/home/filemanager',
    '/home/server', '/home/recovery', '/etc/apache2/ssl',
    '/etc/apache2/sites-available'
];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    chmod($dir, 0777);
}
// Lock files sesuai bash asli
$lockFiles = ['/home/root/locked', '/home/pma/locked', '/home/www/locked',
              '/home/datauser/locked', '/home/datapengguna/locked',
              '/home/domain/locked', '/home/checkdata/locked', '/home/checkdata2/locked'];
foreach ($lockFiles as $lf) {
    if (!file_exists($lf)) touch($lf);
}
log_ok("Semua direktori sistem berhasil dibuat");

// ── Filemanager copy ──────────────────────────────────────────────────────────
if (is_dir($fmDir)) {
    jalankan("cp -r $fmDir/. /home/filemanager/");
    jalankan("chmod -R 777 /home/filemanager");
    jalankan("chown -R www-data:www-data /home/filemanager");
    log_ok("File Manager disalin ke /home/filemanager/");
}

jalankan("chmod -R 777 /home");
jalankan("chmod 777 /usr/lib/cgi-bin");
jalankan("chmod 777 /etc/apache2/sites-available");

// ── sudoers www-data ──────────────────────────────────────────────────────────
$sudoers = file_get_contents('/etc/sudoers');
if (strpos($sudoers, 'www-data ALL=(ALL) NOPASSWD: ALL') === false) {
    jalankan("sed -i '/more/i\\www-data ALL=(ALL) NOPASSWD: ALL' /etc/sudoers");
    log_ok("www-data ditambahkan ke sudoers");
}

// ══════════════════════════════════════════════════════════════════════════════
// LANGKAH 9: Update support files dengan domain & konfigurasi
// ══════════════════════════════════════════════════════════════════════════════
echo "\n=== [9/14] Update File Konfigurasi ===\n";
$passwordFlag = "-p$passwordmysql";

$filesToUpdate = ['formdata.cgi', 'run.cgi', 'aktivasi3.cgi',
                  'subdomain.conf', 'domain.conf', 'domain2.conf', 'index.html'];

foreach ($filesToUpdate as $file) {
    $path = "$supportDir/$file";
    if (!file_exists($path)) continue;

    $isi = file_get_contents($path);
    $isi = str_replace('xcodehoster.com.pem', "$domain.pem", $isi);
    $isi = str_replace('xcodehoster.com.key', "$domain.key", $isi);
    $isi = str_replace('xcodehoster.com',     $domain,       $isi);
    $isi = str_replace('sample.xcodehoster.com', $domain,    $isi);
    $isi = str_replace('-ppasswordmysql',      $passwordFlag, $isi);
    file_put_contents($path, $isi);
}
log_ok("Support files diupdate dengan domain: $domain");

// Update filemanager index.html juga
if (file_exists('/home/filemanager/index.html')) {
    $isi = file_get_contents('/home/filemanager/index.html');
    $isi = str_replace('xcodehoster.com', $domain, $isi);
    file_put_contents('/home/filemanager/index.html', $isi);
}

// ══════════════════════════════════════════════════════════════════════════════
// LANGKAH 10: Copy file ke /home/xcodehoster & /var/www/html
// ══════════════════════════════════════════════════════════════════════════════
echo "\n=== [10/14] Copy File ke Direktori Web ===\n";
$supportFiles = ['domain.conf', 'domain2.conf', 'subdomain.conf', 'index.html',
                 'bootstrap.min.css', 'hosting.jpg', 'xcodehoster21x.png',
                 'coverxcodehoster.png'];

foreach ($supportFiles as $file) {
    if (file_exists("$supportDir/$file")) {
        copy("$supportDir/$file", "/home/xcodehoster/$file");
        @copy("$supportDir/$file", "/var/www/html/$file");
    }
}
log_ok("File disalin ke /home/xcodehoster/ dan /var/www/html/");

// ══════════════════════════════════════════════════════════════════════════════
// LANGKAH 11: Copy CGI files
// ══════════════════════════════════════════════════════════════════════════════
echo "\n=== [11/14] Setup CGI Files ===\n";
$cgiFiles = ['formdata.cgi', 'run.cgi', 'aktivasi3.cgi'];
foreach ($cgiFiles as $file) {
    if (file_exists("$supportDir/$file")) {
        copy("$supportDir/$file", "/usr/lib/cgi-bin/$file");
        chmod("/usr/lib/cgi-bin/$file", 0777);
    }
}
// acak.txt (berisi string random)
$acak = substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'), 0, 10);
file_put_contents('/usr/lib/cgi-bin/acak.txt', $acak . "\n");
chmod('/usr/lib/cgi-bin/acak.txt', 0666);
// ip.txt
file_put_contents('/usr/lib/cgi-bin/ip.txt', $ipserver . "\n");
chmod('/usr/lib/cgi-bin/ip.txt', 0666);
// vouchers.txt (placeholder, akan dibuat ulang di langkah 13)
if (!file_exists('/usr/lib/cgi-bin/vouchers.txt')) touch('/usr/lib/cgi-bin/vouchers.txt');
chmod('/usr/lib/cgi-bin/vouchers.txt', 0777);
log_ok("CGI files disalin + acak.txt & ip.txt dibuat");

// ══════════════════════════════════════════════════════════════════════════════
// LANGKAH 12: SSL Certificate & VirtualHost
// ══════════════════════════════════════════════════════════════════════════════
echo "\n=== [12/14] SSL Certificate & VirtualHost ===\n";

$pemFile = "/etc/apache2/ssl/$domain.pem";
$keyFile = "/etc/apache2/ssl/$domain.key";

// Buat SSL self-signed
jalankan("openssl req -x509 -nodes -days 365 -newkey rsa:2048 " .
         "-keyout $keyFile -out $pemFile " .
         "-subj '/CN=$domain' -addext 'basicConstraints=CA:FALSE'");
chmod($pemFile, 0644);
chmod($keyFile, 0644);
log_ok("SSL self-signed dibuat: $domain.pem & $domain.key");

// ── Input SSL certificate manual (paste dari Cloudflare) ─────────────────────
echo "\n  ℹ️  Untuk menggunakan SSL dari Cloudflare (Origin Certificate):\n";
echo "     Anda perlu paste isi certificate ke file berikut setelah instalasi:\n";
echo "     Certificate : $pemFile\n";
echo "     Private Key : $keyFile\n";
echo "\n  Tekan Enter untuk lanjut (atau paste nanti secara manual)...\n";
fgets(STDIN);

// ── Buat VirtualHost ──────────────────────────────────────────────────────────
$vhostConf = "/etc/apache2/sites-available/$domain.conf";
$vhostContent = <<<VHOST
<VirtualHost *:80>
    ServerName $domain
    ServerAlias www.$domain
    DocumentRoot /home/xcodehoster
    ScriptAlias /cgi-bin/ /usr/lib/cgi-bin/
    <Directory /home/xcodehoster>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    <Directory /usr/lib/cgi-bin>
        Options +ExecCGI
        AddHandler cgi-script .cgi .sh
        Require all granted
    </Directory>
</VirtualHost>
<VirtualHost *:443>
    ServerName $domain
    ServerAlias www.$domain
    DocumentRoot /home/xcodehoster
    SSLEngine on
    SSLCertificateFile $pemFile
    SSLCertificateKeyFile $keyFile
    ScriptAlias /cgi-bin/ /usr/lib/cgi-bin/
    <Directory /home/xcodehoster>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    <Directory /usr/lib/cgi-bin>
        Options +ExecCGI
        AddHandler cgi-script .cgi .sh
        Require all granted
    </Directory>
</VirtualHost>
VHOST;
file_put_contents($vhostConf, $vhostContent);

// Perbaiki domain.conf di sites-available (dari support/domain.conf)
$domainConfPath = "/etc/apache2/sites-available/$domain.conf";
$domainConf2 = "/home/xcodehoster/domain.conf";
// Update domain di conf yang sudah ada di /home/xcodehoster
if (file_exists($domainConf2)) {
    $isi = file_get_contents($domainConf2);
    $isi = str_replace('sample.' . $domain, $domain, $isi);
    $isi = str_replace('sample', 'xcodehoster', $isi);
    file_put_contents($domainConf2, $isi);
}

// ServerName global
$apacheConf2 = file_get_contents('/etc/apache2/apache2.conf');
if (strpos($apacheConf2, 'ServerName') === false) {
    file_put_contents('/etc/apache2/apache2.conf', $apacheConf2 . "\nServerName $domain\n");
}

jalankan("a2ensite $domain.conf");
log_ok("VirtualHost $domain.conf dibuat & diaktifkan");

// ══════════════════════════════════════════════════════════════════════════════
// LANGKAH 13: Cloudflare konfigurasi
// ══════════════════════════════════════════════════════════════════════════════
echo "\n=== [13/14] Konfigurasi Cloudflare ===\n";
$zoneid      = tanya("Masukkan Zone ID Cloudflare : ");
$email       = tanya("Masukkan e-mail Cloudflare  : ");
$globalapikey = tanya("Masukkan Global API Key Cloudflare : ");

// Update aktivasi3.cgi di cgi-bin dengan data cloudflare
$aktivasiPath = '/usr/lib/cgi-bin/aktivasi3.cgi';
if (file_exists($aktivasiPath)) {
    $isi = file_get_contents($aktivasiPath);
    $isi = str_replace('zoneid',      $zoneid,       $isi);
    $isi = str_replace('email',       $email,        $isi);
    $isi = str_replace('globalapikey', $globalapikey, $isi);
    $isi = str_replace('ipserver',    $ipserver,     $isi);
    $isi = str_replace('domain',      $domain,       $isi);
    file_put_contents($aktivasiPath, $isi);
    chmod($aktivasiPath, 0777);
}
// Update aktivasi3.cgi di support juga
$aktivasiSupport = "$supportDir/aktivasi3.cgi";
if (file_exists($aktivasiSupport)) {
    $isi = file_get_contents($aktivasiSupport);
    $isi = str_replace('zoneid',       $zoneid,       $isi);
    $isi = str_replace('email',        $email,        $isi);
    $isi = str_replace('globalapikey', $globalapikey, $isi);
    $isi = str_replace('ipserver',     $ipserver,     $isi);
    $isi = str_replace('domain',       $domain,       $isi);
    file_put_contents($aktivasiSupport, $isi);
}
log_ok("Konfigurasi Cloudflare (Zone ID, Email, API Key) disimpan ke aktivasi3.cgi");

// ── Simpan IP ke cgi-bin/ip.txt ───────────────────────────────────────────────
// IP sudah diminta di awal, simpan ulang untuk memastikan
file_put_contents('/usr/lib/cgi-bin/ip.txt', $ipserver . "\n");
log_ok("IP server ($ipserver) disimpan ke /usr/lib/cgi-bin/ip.txt");

// ══════════════════════════════════════════════════════════════════════════════
// LANGKAH 14: Generate voucher & restart Apache
// ══════════════════════════════════════════════════════════════════════════════
echo "\n=== [14/14] Generate Voucher & Restart Apache ===\n";

// Generate 1000 kode voucher random (8 karakter) — sama dengan bash asli
$vouchers = [];
while (count($vouchers) < 1000) {
    $kode = substr(str_shuffle(str_repeat('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789', 2)), 0, 8);
    $vouchers[$kode] = true;
}
$voucherTxt = implode("\n", array_keys($vouchers)) . "\n";
file_put_contents('/usr/lib/cgi-bin/vouchers.txt', $voucherTxt);
chmod('/usr/lib/cgi-bin/vouchers.txt', 0777);

// Copy voucher ke folder xcodehoster dengan nama random (seperti bash asli)
$randomNum = str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT);
$voucherFilename = "vouchers$randomNum.txt";
copy('/usr/lib/cgi-bin/vouchers.txt', "/home/xcodehoster/$voucherFilename");
copy('/usr/lib/cgi-bin/vouchers.txt', "/var/www/html/$voucherFilename");
log_ok("1000 kode voucher dibuat: $voucherFilename");

// Restart Apache
$configTest = jalankan("apache2ctl configtest");
if (strpos($configTest, 'Syntax OK') !== false) {
    log_ok("Apache config: Syntax OK");
} else {
    log_warn("Apache config ada warning (lanjut restart):\n$configTest");
}
jalankan("service apache2 restart");
log_ok("Apache berhasil direstart");

// ── Simpan .env ───────────────────────────────────────────────────────────────
$envContent = "APP_VERSION=11\n" .
              "SERVER_IP=$ipserver\n" .
              "MAIN_DOMAIN=$domain\n" .
              "DB_PASS=$passwordmysql\n" .
              "CF_ZONE_ID=$zoneid\n" .
              "CF_EMAIL=$email\n" .
              "CF_API_KEY=$globalapikey\n" .
              "SSL_PEM=$pemFile\n" .
              "SSL_KEY=$keyFile\n" .
              "INSTALLED=" . date('Y-m-d H:i:s') . "\n";
file_put_contents("$installDir/.env", $envContent);
log_ok("File .env konfigurasi disimpan");

// ══════════════════════════════════════════════════════════════════════════════
// SELESAI
// ══════════════════════════════════════════════════════════════════════════════
echo "\n";
echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║          INSTALASI XCODEHOSTER V11 SELESAI!             ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";
echo "🌐 Website utama   : https://$domain\n";
echo "📝 Form pendaftaran: https://$domain/cgi-bin/formdata.cgi\n";
echo "🎟️  File voucher    : https://$domain/$voucherFilename\n";
echo "🗄️  phpMyAdmin      : http://$ipserver/phpmyadmin\n";
echo "\n";
echo "⚠️  Langkah berikutnya:\n";
echo "   1. Arahkan DNS domain ke IP: $ipserver (via Cloudflare, SSL Flexible)\n";
echo "   2. Paste Cloudflare Origin Certificate ke:\n";
echo "      - $pemFile\n";
echo "      - $keyFile\n";
echo "   3. Kemudian jalankan: service apache2 restart\n";
echo "\nInstalasi selesai. Xcode.or.id\n";
