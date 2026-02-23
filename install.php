<?php
// install.php - Automator ala Mas Kur
echo "Memulai Instalasi Xcodehoster v11...\n";

// 1. Input Data
$domain = "tugaspkl.my.id"; // Sesuaikan dengan domain Mas Rizky
$ip_server = "103.x.x.x";    // Masukkan IP VPS Mas

// 2. Membuat Folder Sistem (Mirip script Bash Mas Kur)
$folders = [
    '/home/xcodehoster', 
    '/home/datauser', 
    '/home/datapengguna', 
    '/home/domain',
    '/etc/apache2/ssl'
];

foreach ($folders as $folder) {
    if (!is_dir($folder)) {
        exec("sudo mkdir -p $folder");
        exec("sudo chmod 777 $folder");
        echo "Folder $folder berhasil dibuat.\n";
    }
}

// 3. Logika 'sed' (Ganti teks otomatis)
$files_to_edit = [
    'support/subdomain.conf',
    'support/domain.conf',
    'support/formdata.cgi',
    'support/run.cgi'
];

foreach ($files_to_edit as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        $new_content = str_replace('xcodehoster.com', $domain, $content);
        file_put_contents($file, $new_content);
        echo "File $file telah diperbarui dengan domain $domain.\n";
    }
}

// 4. Generate 1000 Voucher Otomatis
$vouchers = [];
for ($i = 0; $i < 1000; $i++) {
    $vouchers[] = substr(str_shuffle("0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 8);
}
file_put_contents('vouchers.txt', implode("\n", $vouchers));
exec("sudo cp vouchers.txt /home/xcodehoster/vouchers.txt");
echo "1000 Voucher berhasil dibuat di /home/xcodehoster/vouchers.txt\n";

// 5. Setup Izin Akhir (Anti-Forbidden)
exec("sudo chown -R www-data:www-data /home/xcodehoster");
exec("sudo chmod -R 755 /home/xcodehoster");
echo "Izin folder telah diperbaiki. Instalasi Selesai!\n";
?>