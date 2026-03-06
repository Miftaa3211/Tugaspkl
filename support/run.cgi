#!/bin/bash
echo "Content-type: text/html"
echo ""

read -n "$CONTENT_LENGTH" POST_DATA

# Parse POST data
namedomain=$(echo "$POST_DATA" | sed 's/&/\n/g' | grep '^namedomain=' | cut -d= -f2- | tr '[:upper:]' '[:lower:]')
name=$(echo "$POST_DATA" | sed 's/&/\n/g' | grep '^name=' | cut -d= -f2- | tr '[:upper:]' '[:lower:]')
password=$(echo "$POST_DATA" | sed 's/&/\n/g' | grep '^password=' | cut -d= -f2-)
email=$(echo "$POST_DATA" | sed 's/&/\n/g' | grep '^email=' | cut -d= -f2-)
wa=$(echo "$POST_DATA" | sed 's/&/\n/g' | grep '^wa=' | cut -d= -f2-)
cek=$(echo "$POST_DATA" | sed 's/&/\n/g' | grep '^cek=' | cut -d= -f2-)

# Fallback parse jika field tidak punya nama (format lama)
if [ -z "$namedomain" ]; then
  namedomain=$(echo "$POST_DATA" | awk '{split($0,a,"&")} END{split(a[1],b,"="); print b[2]}' | tr '[:upper:]' '[:lower:]')
  name=$(echo "$POST_DATA" | awk '{split($0,a,"&")} END{split(a[2],b,"="); print b[2]}' | tr '[:upper:]' '[:lower:]')
  password=$(echo "$POST_DATA" | awk '{split($0,a,"&")} END{split(a[3],b,"="); print b[2]}')
  email=$(echo "$POST_DATA" | awk '{split($0,a,"&")} END{split(a[4],b,"="); print b[2]}')
  wa=$(echo "$POST_DATA" | awk '{split($0,a,"&")} END{split(a[5],b,"="); print b[2]}')
  cek=$(echo "$POST_DATA" | awk '{split($0,a,"&")} END{split(a[6],b,"="); print b[2]}')
fi

# Decode URL encoding
email=$(printf '%b' "${email//%/\\x}")
name=$(printf '%b' "${name//+/ }")
namedomain=$(printf '%b' "${namedomain//+/ }")

# Baca konfigurasi sistem
ip=$(cat /usr/lib/cgi-bin/ip.txt 2>/dev/null | tr -d '[:space:]')
tanggal=$(date +%d-%m-%Y)
random=$(tr -dc a-z0-9 </dev/urandom | head -c 13)

# Baca domain utama dari .env
MAINDOMAIN=$(grep -h 'MAIN_DOMAIN=' /var/www/html/.env /home/xcodehoster/.env 2>/dev/null | head -1 | cut -d= -f2 | tr -d '[:space:]')
[ -z "$MAINDOMAIN" ] && MAINDOMAIN="xcodehoster.com"

# ── VALIDASI INPUT ──────────────────────────────────────────
if [[ "${namedomain}" =~ [^a-z0-9.-] ]]; then
  echo "<p style='color:red;font-family:sans-serif;padding:20px'>❌ Domain hanya boleh huruf kecil, titik, strip, dan angka</p>"
elif [[ "${name}" =~ [^a-z0-9-] ]]; then
  echo "<p style='color:red;font-family:sans-serif;padding:20px'>❌ Subdomain hanya boleh huruf kecil, strip, dan angka</p>"
elif [[ "${password}" =~ [^a-z0-9] ]]; then
  echo "<p style='color:red;font-family:sans-serif;padding:20px'>❌ Password hanya boleh huruf kecil dan angka</p>"
elif [[ "${email}" =~ [^a-zA-Z0-9.@_-] ]]; then
  echo "<p style='color:red;font-family:sans-serif;padding:20px'>❌ Format e-mail tidak valid</p>"
elif [ -f "/home/checkdata/$email" ]; then
  echo "<p style='color:red;font-family:sans-serif;padding:20px'>❌ E-mail ini sudah digunakan, silahkan gunakan e-mail yang lain</p>"
elif [ -f "/home/domain/$namedomain" ]; then
  echo "<p style='color:red;font-family:sans-serif;padding:20px'>❌ Domain ini sudah digunakan, silahkan gunakan domain yang lain</p>"
elif ! grep -Fxq "$cek" /usr/lib/cgi-bin/vouchers.txt 2>/dev/null; then
  echo "<p style='color:red;font-family:sans-serif;padding:20px'>❌ Kode voucher tidak valid atau sudah digunakan</p>"
elif [[ "${wa}" =~ [^0-9] ]] || [ -z "$wa" ]; then
  echo "<p style='color:red;font-family:sans-serif;padding:20px'>❌ Nomor WhatsApp hanya boleh angka</p>"
elif [ -d "/home/$name" ] && [ -n "$(ls -A /home/$name 2>/dev/null)" ]; then
  echo "<p style='color:red;font-family:sans-serif;padding:20px'>❌ Subdomain '$name' sudah ada pemiliknya</p>"
else
  # ── PROSES PENDAFTARAN ──────────────────────────────────
  
  # Hapus kode voucher yang digunakan
  sed -i "/^${cek}$/d" /usr/lib/cgi-bin/vouchers.txt
  
  # Catat data user
  echo "$namedomain" > "/home/domain/$namedomain"
  echo "$email"      > "/home/checkdata/$email"
  echo "$name, $password, $email, $wa, $tanggal." > "/home/checkdata2/$email"
  echo "$name, $password, $email, $wa, $tanggal." > "/home/datapengguna/$name.$tanggal"
  
  # FIX: Buat folder user dan copy filemanager dengan benar (recursive)
  sudo mkdir -p "/home/$name"
  sudo mkdir -p "/home/$name/recovery"
  sudo cp -r /home/filemanager/. "/home/$name/"
  sudo cp -r /home/filemanager/. "/home/$name/recovery/"
  
  # FIX KRITIS: Update password di config.php pakai password yang didaftarkan
  # (bukan hardcode 'unik')
  sudo sed -i "s/password_hash('unik', PASSWORD_DEFAULT)/password_hash('${password}', PASSWORD_DEFAULT)/g" "/home/$name/config.php"
  sudo sed -i "s/password_hash('unik', PASSWORD_DEFAULT)/password_hash('${password}', PASSWORD_DEFAULT)/g" "/home/$name/recovery/config.php"
  # Fallback jika format berbeda
  sudo sed -i "s/'unik'/'${password}'/g" "/home/$name/config.php"
  sudo sed -i "s/'unik'/'${password}'/g" "/home/$name/recovery/config.php"
  
  # FIX: Permission yang benar (755 bukan 777 untuk keamanan)
  sudo chmod o+x /home
  sudo chmod 755 "/home/$name"
  sudo find "/home/$name" -type f -exec chmod 644 {} \;
  sudo find "/home/$name" -type d -exec chmod 755 {} \;
  sudo chown -R www-data:www-data "/home/$name"
  
  # FIX: Setup VirtualHost domain custom user (Require all granted)
  sudo cp /home/xcodehoster/domain2.conf "/etc/apache2/sites-available/$namedomain.conf"
  sudo sed -i "s/contoh\.com/$namedomain/g" "/etc/apache2/sites-available/$namedomain.conf"
  sudo sed -i "s/sample/$name/g"           "/etc/apache2/sites-available/$namedomain.conf"
  
  # FIX: Setup VirtualHost subdomain (pakai MAINDOMAIN, bukan hardcode xcodehoster.com)
  sudo cp /home/xcodehoster/subdomain.conf "/etc/apache2/sites-available/$name.$MAINDOMAIN.conf"
  sudo sed -i "s/sample/$name/g"              "/etc/apache2/sites-available/$name.$MAINDOMAIN.conf"
  sudo sed -i "s/xcodehoster\.com/$MAINDOMAIN/g" "/etc/apache2/sites-available/$name.$MAINDOMAIN.conf"
  
  sudo a2ensite "$namedomain.conf"        >/dev/null 2>&1
  sudo a2ensite "$name.$MAINDOMAIN.conf"  >/dev/null 2>&1
  sudo systemctl reload apache2           >/dev/null 2>&1
  
  # FIX: Buat DNS record Cloudflare otomatis untuk subdomain
  sudo cp /usr/lib/cgi-bin/aktivasi3.cgi /usr/lib/cgi-bin/aktivasi4.cgi
  sudo sed -i "s/unik/$name/g" /usr/lib/cgi-bin/aktivasi4.cgi
  sudo chmod 777 /usr/lib/cgi-bin/aktivasi4.cgi
  cd /usr/lib/cgi-bin && sudo ./aktivasi4.cgi >/dev/null 2>&1
  sudo rm -f /usr/lib/cgi-bin/aktivasi4.cgi
  
  # FIX: Buat database MySQL (gunakan IF NOT EXISTS agar tidak error jika sudah ada)
  DBPASS=$(grep 'DB_PASS=' /var/www/html/.env 2>/dev/null | cut -d= -f2 | tr -d '[:space:]')
  [ -z "$DBPASS" ] && DBPASS=$(grep 'DB_ROOT_PASSWORD=' /var/www/html/.env 2>/dev/null | cut -d= -f2 | tr -d '[:space:]')
  [ -z "$DBPASS" ] && DBPASS=$(grep 'DB_PASS=' /home/xcodehoster/.env 2>/dev/null | cut -d= -f2 | tr -d '[:space:]')
  
  mysql -uroot -p"$DBPASS" -e "CREATE DATABASE IF NOT EXISTS \`$name\`;" 2>/dev/null
  mysql -uroot -p"$DBPASS" -e "CREATE USER IF NOT EXISTS '$name'@'localhost' IDENTIFIED WITH mysql_native_password BY '$password';" 2>/dev/null
  mysql -uroot -p"$DBPASS" -e "GRANT ALL PRIVILEGES ON \`$name\`.* TO '$name'@'localhost';" 2>/dev/null
  mysql -uroot -p"$DBPASS" -e "FLUSH PRIVILEGES;" 2>/dev/null

  # ── TAMPILKAN HALAMAN INFORMASI AKUN ───────────────────
  LOGINURL="https://$name.$MAINDOMAIN/login.php"
  SUBURL="https://$name.$MAINDOMAIN"
  PMAURL="https://$name.$MAINDOMAIN/phpmyadmin"
  RECURL="https://$name.$MAINDOMAIN/recovery/login.php"
  DOMAINURL="https://$namedomain"
  WALINK="https://wa.me/62${wa}?text=Akun+Hosting+Anda%0A%0ASubdomain%3A+${SUBURL}%0ALogin%3A+${LOGINURL}%0AUsername%3A+admin%0APassword%3A+${password}%0A%0AphpMyAdmin%3A+${PMAURL}%0ADB+User%3A+${name}%0ADB+Pass%3A+${password}"

cat << EOT
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Akun Berhasil Dibuat — Xcodehoster</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Segoe UI', sans-serif; background: #f0f4f8; color: #2d3748; padding: 24px 16px; }
  .wrap { max-width: 700px; margin: 0 auto; }
  .hero { background: linear-gradient(135deg,#2a7ae2,#1a4fa0); color: #fff; border-radius: 16px 16px 0 0; padding: 28px 32px; }
  .hero h1 { font-size: 1.6rem; margin-bottom: 4px; }
  .hero p  { opacity: .8; font-size: .95rem; }
  .body { background: #fff; border-radius: 0 0 16px 16px; box-shadow: 0 4px 20px rgba(0,0,0,.08); padding: 28px 32px; }
  .section { margin-bottom: 22px; }
  .label { font-size: .75rem; text-transform: uppercase; letter-spacing: 1px; color: #718096; font-weight: 700; margin-bottom: 8px; }
  .info-box { background: #ebf4ff; border-left: 4px solid #2a7ae2; border-radius: 8px; padding: 14px 18px; line-height: 1.8; }
  .info-box a { color: #2a7ae2; font-weight: 600; word-break: break-all; text-decoration: none; }
  .info-box a:hover { text-decoration: underline; }
  strong { color: #1a202c; }
  .btn-login { display: inline-block; background: #2a7ae2; color: #fff !important; padding: 10px 24px; border-radius: 8px; font-weight: 700; margin-top: 10px; text-decoration: none; font-size: .95rem; }
  .btn-login:hover { background: #1a4fa0; text-decoration: none; }
  .btn-wa { display: inline-block; background: #25D366; color: #fff !important; padding: 10px 24px; border-radius: 8px; font-weight: 700; margin-top: 10px; text-decoration: none; font-size: .95rem; }
  .btn-wa:hover { background: #128C7E; text-decoration: none; }
  .alert { background: #fff3cd; border-left: 4px solid #f6c90e; border-radius: 8px; padding: 12px 16px; font-size: .88rem; color: #856404; margin-top: 20px; }
  @media(max-width:500px){ .hero,.body{padding:20px} }
</style>
</head>
<body>
<div class="wrap">
  <div class="hero">
    <h1>✅ Selamat, $name!</h1>
    <p>Akun hosting Anda berhasil dibuat dan sudah aktif.</p>
  </div>
  <div class="body">

    <div class="section">
      <div class="label">🌐 Subdomain & File Manager</div>
      <div class="info-box">
        <a href="$SUBURL" target="_blank">$SUBURL</a><br>
        <a href="$LOGINURL" target="_blank" class="btn-login">🔐 Login File Manager</a>
        <br><br>
        Username : <strong>admin</strong><br>
        Password : <strong>$password</strong>
      </div>
    </div>

    <div class="section">
      <div class="label">🌍 Domain Custom Anda</div>
      <div class="info-box">
        <a href="$DOMAINURL" target="_blank">$DOMAINURL</a><br>
        Arahkan domain ke IP: <strong>$ip</strong><br>
        (DNS Cloudflare, SSL mode Flexible)
      </div>
    </div>

    <div class="section">
      <div class="label">🗄️ phpMyAdmin</div>
      <div class="info-box">
        <a href="$PMAURL" target="_blank">$PMAURL</a><br>
        Username: <strong>$name</strong> &nbsp;|&nbsp; Password: <strong>$password</strong>
      </div>
    </div>

    <div class="section">
      <div class="label">🆘 Recovery (jika login.php / config.php terhapus)</div>
      <div class="info-box">
        <a href="$RECURL" target="_blank">$RECURL</a><br>
        Username: <strong>admin</strong> &nbsp;|&nbsp; Password: <strong>$password</strong>
      </div>
    </div>

    <div class="section">
      <a href="$WALINK" target="_blank" class="btn-wa">📱 Kirim Info Login ke WhatsApp</a>
    </div>

    <div class="alert">
      ⚠️ Jangan hapus file <code>login.php</code> dan <code>config.php</code>.<br>
      Subdomain Anda sudah aktif dan langsung bisa digunakan.
    </div>
  </div>
</div>
</body>
</html>
EOT
fi
