#!/bin/bash
echo "Content-type: text/html"
echo ""
read -n "$CONTENT_LENGTH" POST_DATA
namedomain=$(echo "$POST_DATA" | awk '{split($0,array,"&")} END{print array[1]}' | awk '{split($0,array,"=")} END{print array[2]}' | tr '[:upper:]' '[:lower:]')
name=$(echo "$POST_DATA" | awk '{split($0,array,"&")} END{print array[2]}' | awk '{split($0,array,"=")} END{print array[2]}' | tr '[:upper:]' '[:lower:]')
password=$(echo "$POST_DATA" | awk '{split($0,array,"&")} END{print array[3]}' | awk '{split($0,array,"=")} END{print array[2]}')
email=$(echo "$POST_DATA" | awk '{split($0,array,"&")} END{print array[4]}' | awk '{split($0,array,"=")} END{print array[2]}')
wa=$(echo "$POST_DATA" | awk '{split($0,array,"&")} END{print array[5]}' | awk '{split($0,array,"=")} END{print array[2]}')
cek=$(echo "$POST_DATA" | awk '{split($0,array,"&")} END{print array[6]}' | awk '{split($0,array,"=")} END{print array[2]}')
ip=$(head -n 1 ip.txt)
tanggal=$(date +%d-%m-%Y)
random=$(tr -dc a-z0-9 </dev/urandom | head -c 13 ; echo '')
email=$(printf '%b' "${email//%/\\x}")

if [[ "${namedomain}" =~ [^a-z0-9.-] ]]; then
echo "Domain hanya boleh huruf kecil, strip dan angka, domain harus ada titik"
elif [[ "${name}" =~ [^a-z0-9-] ]]; then
echo "subdomain hanya boleh huruf kecil, strip dan angka"
elif [[ "${password}" =~ [^a-z0-9] ]]; then
echo "Password hanya boleh huruf kecil dan angka"
elif [[ "${email}" =~ [^a-z0-9.@-] ]]; then
echo "Hanya mendukung format e-mail"
elif [ -f "/home/checkdata/$email" ]; then
echo "E-mail ini sudah digunakan, silahkan gunakan e-mail yang lain"
elif [ -f "/home/domain/$namedomain" ]; then
echo "Domain ini sudah digunakan, silahkan gunakan domain yang lain"
elif ! grep -Fxq "$cek" vouchers.txt; then
echo "Kode voucher tersebut sudah digunakan, silahkan menggunakan voucher lain"
else
  sed -i "/^$cek$/d" vouchers.txt
  echo $namedomain > /home/domain/$namedomain
  echo $email > /home/checkdata/$email
  echo $name, $password, $email, $wa, $tanggal. > /home/checkdata2/$email
  echo $name, $password, $email, $wa, $tanggal. > /home/datapengguna/$name.$tanggal

  # Buat direktori user
  sudo mkdir -p /home/$name
  sudo mkdir -p /home/$name/recovery

  # Salin filemanager ke direktori user
  sudo cp -r /home/filemanager/. /home/$name/
  sudo cp -r /home/filemanager/. /home/$name/recovery/

  # Set permission
  sudo chmod -R 777 /home/$name
  sudo chown -R www-data:www-data /home/$name

  # Ganti password filemanager dengan password user
  sudo sed -i "s|password_hash('unik', PASSWORD_DEFAULT)|password_hash('$password', PASSWORD_DEFAULT)|g" /home/$name/config.php
  sudo sed -i "s|password_hash('unik', PASSWORD_DEFAULT)|password_hash('$password', PASSWORD_DEFAULT)|g" /home/$name/recovery/config.php

  # Salin gambar ke direktori user
  sudo cp /home/xcodehoster/coverxcodehoster.png /home/$name/
  sudo cp /home/xcodehoster/hosting.jpg /home/$name/
 sudo cp /home/xcodehoster/panel.php /home/$name/
  sudo cp /home/xcodehoster/xcodehoster21x.png /home/$name/

  # Buat Apache VirtualHost subdomain
  sudo cp /home/xcodehoster/subdomain.conf /etc/apache2/sites-available/$name.conf
  sudo sed -i "s/sample/$name/g" /etc/apache2/sites-available/$name.conf

  # Buat Apache VirtualHost domain user
  sudo cp /home/xcodehoster/domain2.conf /etc/apache2/sites-available/$namedomain.conf
  sudo sed -i "s/contoh.com/$namedomain/g" /etc/apache2/sites-available/$namedomain.conf
  sudo sed -i "s/sample/$name/g" /etc/apache2/sites-available/$namedomain.conf

  # Aktifkan site dan reload Apache
  sudo a2ensite $name.conf
  sudo a2ensite $namedomain.conf
  sudo systemctl reload apache2

  # Buat user & database MySQL/MariaDB (support keduanya)
  mysql -uroot -ppasswordmysql -e "CREATE USER '$name'@'localhost' IDENTIFIED BY '$password';" 2>/dev/null
  mysql -uroot -ppasswordmysql -e "CREATE DATABASE $name;" 2>/dev/null
  mysql -uroot -ppasswordmysql -e "GRANT ALL PRIVILEGES ON $name.* TO '$name'@'localhost';" 2>/dev/null
  mysql -uroot -ppasswordmysql -e "FLUSH PRIVILEGES;" 2>/dev/null

cat <<EOT
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Xcodehoster — Registrasi Berhasil</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; font-family: 'Segoe UI', sans-serif; background: #f4f7fa; color: #333; padding: 20px; }
  .container { max-width: 720px; margin: 0 auto; background: #fff; border-radius: 8px; box-shadow: 0 4px 14px rgba(0,0,0,.1); padding: 30px 40px; }
  h2 { margin-top: 0; color: #2a7ae2; font-size: 1.8rem; border-bottom: 2px solid #2a7ae2; padding-bottom: 10px; }
  a { color: #2a7ae2; text-decoration: none; word-break: break-all; }
  a:hover { text-decoration: underline; }
  .section { margin-top: 20px; }
  .label { font-weight: 600; color: #555; }
  .info { background: #eef4ff; border-left: 4px solid #2a7ae2; padding: 12px 16px; margin: 12px 0; border-radius: 4px; word-wrap: break-word; }
  .note { font-size: .9rem; color: #777; margin-top: 25px; border-top: 1px solid #ddd; padding-top: 12px; }
  @media (max-width: 480px) { .container { padding: 20px; } }
</style>
</head>
<body>
  <div class="container">
    <h2>Welcome <span style="color:#2a7ae2;">$name</span> 🎉</h2>
    <div class="section">
      <div class="label">Alamat website untuk domain:</div>
      <div class="info">
        <a href="https://$namedomain" target="_blank">https://$namedomain</a><br />
        Arahkan domain ke IP: <strong>$ip</strong> (Cloudflare DNS, SSL Flexible)
      </div>
    </div>
    <div class="section">
      <div class="label">Alamat website untuk subdomain:</div>
      <div class="info">
        <a href="https://$name.xcodehoster.com" target="_blank">https://$name.xcodehoster.com</a><br />
        Login: <a href="https://$name.xcodehoster.com/login.php">https://$name.xcodehoster.com/login.php</a><br />
        Username: <strong>admin</strong><br />
        Password: <strong>$password</strong>
      </div>
    </div>
    <div class="section">
      <div class="label">Login phpMyAdmin:</div>
      <div class="info">
        <a href="https://$name.xcodehoster.com/phpmyadmin" target="_blank">https://$name.xcodehoster.com/phpmyadmin</a><br />
        Username: <strong>$name</strong><br />
        Password: <strong>$password</strong>
      </div>
    </div>
    <div class="section">
      <div class="label">Recovery (jika login.php terhapus):</div>
      <div class="info">
        <a href="https://$name.xcodehoster.com/recovery/login.php">https://$name.xcodehoster.com/recovery/login.php</a><br />
        Username: <strong>admin</strong> | Password: <strong>$password</strong><br /><br />
        <a href="https://wa.me/62$wa?text=Login%20website%3A%20https%3A%2F%2F$name.xcodehoster.com%2Flogin.php%0AUsername%3A%20admin%0APassword%3A%20$password"
           style="background:#25D366;color:#fff;padding:10px 20px;border-radius:5px;font-weight:bold;display:inline-block;">
          Kirim Info Login ke WhatsApp
        </a>
      </div>
    </div>
    <div class="note">Subdomain Anda sudah aktif. Jangan hapus <code>login.php</code> dan <code>config.php</code>.</div>
  </div>
</body>
</html>
EOT
fi
