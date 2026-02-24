<?php
// Mencegah akses langsung tanpa form
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Akses ditolak. Silakan lewat index.php");
}

// Menangkap inputan dari index.php
$ipserver      = $_POST['ipserver'];
$domain        = $_POST['domain'];
$passwordmysql = $_POST['passwordmysql'];
$zoneid        = $_POST['zoneid'];
$email         = $_POST['email'];
$globalapikey  = $_POST['globalapikey'];

// Fungsi untuk mengeksekusi perintah terminal dengan aman
function run_cmd($cmd) {
    echo "<b>Menjalankan:</b> $cmd <br>";
    $output = shell_exec($cmd . " 2>&1");
    echo "<pre style='color: #0f0; background: #000; padding: 10px;'>" . htmlspecialchars($output) . "</pre>";
}

// Fungsi pengganti perintah "sed" di Bash (Mencari dan Mengganti teks di dalam file)
function replace_text($filepath, $search, $replace) {
    if (file_exists($filepath)) {
        $content = file_get_contents($filepath);
        $content = str_replace($search, $replace, $content);
        file_put_contents($filepath, $content);
        echo "<span style='color:blue;'>Teks diperbarui pada: $filepath</span><br>";
    }
}

echo "<h2>Memproses Instalasi Xcodehoster...</h2>";
echo "<hr>";

// 1. Install Paket-paket yang dibutuhkan
run_cmd("sudo apt-get update");
run_cmd("sudo DEBIAN_FRONTEND=noninteractive apt-get -y install software-properties-common mysql-server phpmyadmin zip unzip php-zip jq imagemagick");

// 2. Set Password MySQL
run_cmd("sudo mysql -e \"ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '$passwordmysql';\"");

// 3. Konfigurasi Apache Dasar
run_cmd("sudo a2enmod ssl cgi");
run_cmd("sudo cp support/phpinfo.php /var/www/html/");
run_cmd("sudo cp /etc/apache2/apache2.conf backup_apache2.conf");
run_cmd("sudo cp support/apache2.conf /etc/apache2/");
run_cmd("sudo cp support/php.ini /etc/php/8.3/apache2/");

// 4. Membuat Struktur Folder Home persis seperti script Bash
$folders = ['root', 'pma', 'www', 'datauser', 'xcodehoster', 'datapengguna', 'domain', 'checkdata', 'checkdata2'];
foreach ($folders as $folder) {
    run_cmd("sudo mkdir -p /home/$folder");
    run_cmd("sudo touch /home/$folder/locked");
}
run_cmd("sudo chmod 777 /home/datauser");
run_cmd("sudo cp -r filemanager /home/filemanager");
run_cmd("sudo chmod -R 777 /home");

// 5. Konfigurasi CGI & Hak Akses
run_cmd("sudo chmod 777 /usr/lib/cgi-bin");
run_cmd("sudo chmod 777 /etc/apache2/sites-available");
run_cmd("sudo mkdir -p /etc/apache2/ssl");
run_cmd("sudo chmod 777 /etc/apache2/ssl");

// 6. Manipulasi File (Find & Replace - Pengganti SED)
// Modifikasi file conf
replace_text("support/subdomain.conf", "xcodehoster.com", $domain);
replace_text("support/domain.conf", "sample.xcodehoster.com", $domain);
replace_text("support/subdomain.conf", "xcodehoster.com.pem", "$domain.pem");
replace_text("support/subdomain.conf", "xcodehoster.com.key", "$domain.key");
replace_text("support/domain.conf", "xcodehoster.com.pem", "$domain.pem");

// Modifikasi file CGI
replace_text("support/run.cgi", "-ppasswordmysql", "-p$passwordmysql");
replace_text("support/run.cgi", "xcodehoster.com", $domain);
replace_text("support/formdata.cgi", "xcodehoster.com", $domain);
replace_text("support/aktivasi3.cgi", "xcodehoster.com", $domain);
replace_text("support/aktivasi3.cgi", "domain", $domain);
replace_text("support/aktivasi3.cgi", "zoneid", $zoneid);
replace_text("support/aktivasi3.cgi", "email", $email);
replace_text("support/aktivasi3.cgi", "globalapikey", $globalapikey);
replace_text("support/aktivasi3.cgi", "ipserver", $ipserver);

// Modifikasi HTML & UI filemanager
replace_text("/home/filemanager/index.html", "xcodehoster.com", $domain);
replace_text("support/index.html", "xcodehoster.com", $domain);

// 7. Copy file CGI ke sistem
run_cmd("sudo cp support/formfree.cgi /usr/lib/cgi-bin/");
run_cmd("sudo cp support/run.cgi /usr/lib/cgi-bin/");
run_cmd("sudo cp support/aktivasi3.cgi /usr/lib/cgi-bin/");
run_cmd("sudo cp support/formdata.cgi /usr/lib/cgi-bin/");
run_cmd("sudo cp support/acak.txt /usr/lib/cgi-bin/");
run_cmd("sudo chmod 777 /usr/lib/cgi-bin/*");
run_cmd("sudo touch /usr/lib/cgi-bin/vouchers.txt");
run_cmd("sudo chmod 777 /usr/lib/cgi-bin/vouchers.txt");
run_cmd("sudo echo '$ipserver' > /usr/lib/cgi-bin/ip.txt");

// 8. Copy file UI Panel ke folder xcodehoster
$panel_files = ['domain.conf', 'domain2.conf', 'subdomain.conf', 'index.html', 'bootstrap.min.css', 'hosting.jpg', 'xcodehoster21x.png', 'coverxcodehoster.png'];
foreach ($panel_files as $file) {
    run_cmd("sudo cp support/$file /home/xcodehoster/");
}

// 9. Setup Virtual Host Apache
run_cmd("sudo touch /etc/apache2/ssl/$domain.pem");
run_cmd("sudo touch /etc/apache2/ssl/$domain.key");
run_cmd("sudo cp /var/www/html/index.html /var/www/html/backup1.html");
run_cmd("sudo cp -r /home/xcodehoster/* /var/www/html/");
run_cmd("sudo cp support/domain.conf /etc/apache2/sites-available/$domain.conf");

replace_text("/etc/apache2/sites-available/$domain.conf", "sample.$domain", $domain);
replace_text("/etc/apache2/sites-available/$domain.conf", "sample", "xcodehoster");
replace_text("/etc/apache2/sites-available/$domain.conf", "xcodehoster.com.pem", "$domain.pem");
replace_text("/etc/apache2/sites-available/$domain.conf", "xcodehoster.com.key", "$domain.key");

run_cmd("sudo a2ensite $domain.conf");

// 10. Generate Voucher Acak
$vouchers = "";
for ($i = 0; $i < 1000; $i++) {
    $vouchers .= substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8) . "\n";
}
file_put_contents("/usr/lib/cgi-bin/vouchers.txt", $vouchers);

$random_number = str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT);
run_cmd("sudo cp /usr/lib/cgi-bin/vouchers.txt /home/xcodehoster/vouchers{$random_number}.txt");
run_cmd("sudo cp /usr/lib/cgi-bin/vouchers.txt /var/www/html/vouchers{$random_number}.txt");

// 11. Restart Apache Terakhir
run_cmd("sudo service apache2 restart");

echo "<hr>";
echo "<div style='background: #d4edda; color: #155724; padding: 20px; border-radius: 5px;'>";
echo "<h3>✅ Instalasi Selesai!</h3>";
echo "<p>Web hosting anda dapat diakses di: <b><a href='http://$ipserver' target='_blank'>http://$ipserver</a></b> atau domain anda jika DNS sudah propagasi.</p>";
echo "<p>Voucher pendaftaran ada di: <b><a href='http://$ipserver/vouchers{$random_number}.txt' target='_blank'>http://$ipserver/vouchers{$random_number}.txt</a></b></p>";
echo "</div>";
?>
