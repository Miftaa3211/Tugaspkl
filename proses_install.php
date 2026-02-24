<?php
// Pastikan hanya bisa diakses via POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Akses ditolak.");
}

// Menangkap data dari form
$ipserver = $_POST['ipserver'];
$domain = $_POST['domain'];
$passwordmysql = $_POST['passwordmysql'];
$zoneid = $_POST['zoneid'];
$email = $_POST['email'];
$globalapikey = $_POST['globalapikey'];

echo "<h3>Memulai Instalasi Xcodehoster v11...</h3>";
echo "<pre style='background:#222; color:#0f0; padding:15px;'>";

function run_cmd($cmd) {
    echo "Mengeksekusi: $cmd\n";
    $output = shell_exec($cmd . " 2>&1");
    echo $output . "\n";
}

// 1. Instalasi Paket Server (Tetap butuh shell_exec karena ini perintah OS)
run_cmd("sudo apt-get update");
run_cmd("sudo apt-get -y install software-properties-common mysql-server phpmyadmin zip unzip php-zip jq imagemagick");

// 2. Set Password MySQL
run_cmd("sudo mysql -e \"ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '$passwordmysql';\"");

// 3. Konfigurasi Apache & CGI (Syarat bisa run .cgi / .sh)
run_cmd("sudo a2enmod ssl cgi");
run_cmd("sudo chmod 777 /usr/lib/cgi-bin");

// 4. Menerjemahkan logika pembuatan folder bash "mkdir" & "touch" ke PHP
$folders = ['root', 'pma', 'www', 'datauser', 'xcodehoster', 'datapengguna', 'domain', 'checkdata', 'checkdata2'];
foreach ($folders as $folder) {
    $path = "/home/$folder";
    if (!is_dir($path)) {
        mkdir($path, 0777, true); // Sama dengan sudo mkdir
        run_cmd("sudo chmod 777 $path");
    }
    touch("$path/locked"); // Sama dengan sudo touch
}

// 5. Menerjemahkan logika SED (Find & Replace) ke PHP murni
function replace_in_file($filepath, $search, $replace) {
    if (file_exists($filepath)) {
        $content = file_get_contents($filepath);
        $content = str_replace($search, $replace, $content);
        file_put_contents($filepath, $content);
        echo "Teks diganti pada: $filepath\n";
    }
}

// Manipulasi file konfigurasi (Persis seperti baris sed di bash)
replace_in_file("support/subdomain.conf", "xcodehoster.com", $domain);
replace_in_file("support/domain.conf", "sample.xcodehoster.com", $domain);
replace_in_file("support/subdomain.conf", "xcodehoster.com.pem", "$domain.pem");
replace_in_file("support/subdomain.conf", "xcodehoster.com.key", "$domain.key");

// Manipulasi file CGI & Integrasi Cloudflare
$cgi_files = ["support/run.cgi", "support/formdata.cgi", "support/aktivasi3.cgi"];
foreach ($cgi_files as $cgi) {
    replace_in_file($cgi, "xcodehoster.com", $domain);
    replace_in_file($cgi, "-ppasswordmysql", "-p$passwordmysql");
}

replace_in_file("support/aktivasi3.cgi", "zoneid", $zoneid);
replace_in_file("support/aktivasi3.cgi", "email", $email);
replace_in_file("support/aktivasi3.cgi", "globalapikey", $globalapikey);
replace_in_file("support/aktivasi3.cgi", "ipserver", $ipserver);

// 6. Menyalin file ke sistem operasi (Menerjemahkan perintah 'cp')
run_cmd("sudo cp support/run.cgi /usr/lib/cgi-bin/");
run_cmd("sudo cp support/aktivasi3.cgi /usr/lib/cgi-bin/");
run_cmd("sudo chmod 777 /usr/lib/cgi-bin/*");

// 7. Generate Voucher Random
$vouchers = "";
for ($i = 0; $i < 1000; $i++) {
    $vouchers .= substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8) . "\n";
}
file_put_contents("/usr/lib/cgi-bin/vouchers.txt", $vouchers);
run_cmd("sudo chmod 777 /usr/lib/cgi-bin/vouchers.txt");

$random_number = str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT);
run_cmd("sudo cp /usr/lib/cgi-bin/vouchers.txt /home/xcodehoster/vouchers{$random_number}.txt");

// 8. Restart Apache
run_cmd("sudo service apache2 restart");

echo "</pre>";
echo "<div class='alert alert-success mt-4'>";
echo "<h4>Instalasi Selesai!</h4>";
echo "<p>Panel dapat diakses di: <a href='https://$domain' target='_blank'>https://$domain</a></p>";
echo "<p>Daftar Voucher: <a href='https://$domain/vouchers{$random_number}.txt' target='_blank'>https://$domain/vouchers{$random_number}.txt</a></p>";
echo "</div>";
?>