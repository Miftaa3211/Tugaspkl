<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Xcodehoster v11 - Web Installer</title>
    <link rel="stylesheet" href="support/bootstrap.min.css"> <style>
        body { background-color: #f4f6f9; }
        .installer-card { max-width: 600px; margin: 50px auto; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card shadow installer-card">
            <div class="card-header bg-dark text-white text-center">
                <h4>Instalasi Xcodehoster v11</h4>
                <small>Ubuntu 24.04 Edition</small>
            </div>
            <div class="card-body">
                <form action="proses_install.php" method="POST">
                    <h5 class="text-primary border-bottom pb-2">1. Konfigurasi Server Dasar</h5>
                    <div class="mb-3">
                        <label>IP Publik Server</label>
                        <input type="text" class="form-control" name="ipserver" value="202.10.42.16" required>
                    </div>
                    <div class="mb-3">
                        <label>Nama Domain</label>
                        <input type="text" class="form-control" name="domain" value="tugaspkl.my.id" required>
                    </div>
                    <div class="mb-3">
                        <label>Password MySQL Root (Baru)</label>
                        <input type="password" class="form-control" name="passwordmysql" required>
                    </div>

                    <h5 class="text-primary border-bottom pb-2 mt-4">2. Integrasi API Cloudflare</h5>
                    <div class="mb-3">
                        <label>Zone ID Cloudflare</label>
                        <input type="text" class="form-control" name="zoneid" required>
                    </div>
                    <div class="mb-3">
                        <label>E-mail Akun Cloudflare</label>
                        <input type="email" class="form-control" name="email" required>
                    </div>
                    <div class="mb-3">
                        <label>Global API Key Cloudflare</label>
                        <input type="text" class="form-control" name="globalapikey" required>
                    </div>

                    <button type="submit" class="btn btn-success w-100 mt-3">Mulai Proses Instalasi</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>