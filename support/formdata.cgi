#!/bin/bash
echo "Content-type: text/html"
echo ""

# Generate captcha image
patch=$(tr -dc A-Za-z0-9 </dev/urandom | head -c 13 ; echo '')
convert \
    -size 725x100 \
    xc:lightblue \
    -font Bookman-DemiItalic \
    -pointsize 18 \
    -fill blue \
    -gravity center \
    -draw "text 0,0 '$(cat /usr/lib/cgi-bin/acak.txt)'" \
    /home/server/image.png

cat <<EOT
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Pendaftaran VPS</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.3/dist/css/bootstrap.min.css">
  <style>
    body {
      background-color: #f8f9fa;
      font-family: sans-serif;
    }

    .form-container {
      margin-top: 40px;
      max-width: 1000px;
      background: white;
      padding: 30px;
      border-radius: 10px;
      box-shadow: 0 0 10px rgba(0,0,0,0.1);
    }

    .form-group {
      margin-bottom: 15px;
    }

    .form-control {
      width: 100%;
    }

    .image-side {
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }

    .image-side img {
      max-width: 100%;
      height: auto;
      border-radius: 10px;
    }

    @media (max-width: 768px) {
      .form-row {
        flex-direction: column;
      }

      .image-side {
        margin-top: 20px;
      }
    }
  </style>
</head>
<body>
  <div class="container form-container">
    <div class="row form-row">
      <div class="col-md-6">
        <h3 class="text-center mb-4">Daftar hosting Xcodehoster v11 - File manager, Apache Web Server, PHP 8.3, MySQL dan PHPMyAdmin</h3>
        <form action="run.cgi" method="post">
          <div class="form-group">
            <label>Nama domain (harus disi, harus ada titik, jika belum memiliki domain minimal diisi perkiraan nama domain ke depan)</label>
            <input type="text" name="namedomain" pattern="^[a-zA-Z0-9.@-]+$" required class="form-control" placeholder="nama domain">
          </div>
          <div class="form-group">
            <label>Nama subdomain</label>
            <div class="input-group">
              <input type="text" name="name" pattern="^[a-z0-9-]+$" required class="form-control" placeholder="nama subdomain">
              <div class="input-group-append">
                <span class="input-group-text">.xcodehoster.com</span>
              </div>
            </div>
          </div>
          <div class="form-group">
            <label>Password (minimal 8 karakter)</label>
            <input type="password" name="password" pattern="^[a-z0-9]{8,}$" required class="form-control">
          </div>
          <div class="form-group">
            <label>Alamat e-mail</label>
            <input type="email" name="email" pattern="^[a-zA-Z0-9.@-]+$" required class="form-control">
          </div>
          <div class="form-group">
            <label>Nomor WhatsApp</label>
            <input type="text" name="wa" pattern="^[0-9]+$" required class="form-control" placeholder="08xxxxxxxxxx">
          </div>
          <div class="form-group">
            <label>Kode Aktivasi</label><br>
            <input type="text" name="cek" pattern="^[a-zA-Z0-9]+$" required class="form-control" placeholder="Masukkan Kode aktivasi">
          </div>
          <div class="form-group text-end">
            <input type="submit" value="Daftar" class="btn btn-primary">
          </div>
        </form>
      </div>
      <div class="col-md-6 image-side">
        <img src="https://xcodehoster.com/coverxcodehoster.png" alt="VPS Image">
      </div>
    </div>
  </div>
</body>
</html>
EOT
