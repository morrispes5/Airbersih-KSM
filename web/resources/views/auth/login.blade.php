<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Masuk · KSM Banyu Bening</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="login-page">
<main class="login-layout">
    <section class="login-story">
        <a class="brand" href="/login"><span class="brand-symbol">B<span>≈</span></span><span>BANYU BENING<small>Pengelolaan air bersih</small></span></a>
        <div>
            <div class="eyebrow">RUANG PENGURUS KSM</div>
            <h1>Air bersih.<br>Catatan yang<br>lebih tertib.</h1>
            <p>Kelola meter, tagihan, dan keuangan<br>dalam satu ruang kerja.</p>
            <div class="water-lines" aria-hidden="true">≈<br>≈<br>≈</div>
        </div>
        <p class="login-foot">KSM Banyu Bening · Taman Kedaung</p>
    </section>
    <section class="login-panel">
        <div class="login-card">
            <span class="simulation-pill">{{ config('airbersih.mode') === 'operational' ? 'PENGURUS RT 05' : 'LINGKUNGAN SIMULASI' }}</span>
            <h2>Selamat datang</h2>
            <p>Masuk menggunakan akun pengurus.</p>
            @if($errors->any())
                <div class="alert error" role="alert">{{ $errors->first() }}</div>
            @endif
            <form method="POST" action="/login">
                @csrf
                <label class="field"><span>Email</span><input name="email" type="email" value="{{ old('email') }}" required autocomplete="username" autocapitalize="none" spellcheck="false" placeholder="Email pengurus"></label>
                <label class="field" for="login-password"><span>Password</span></label>
                <div class="login-password">
                    <input id="login-password" name="password" type="password" required autocomplete="current-password" placeholder="Masukkan password">
                    <button type="button" class="password-toggle" data-password-toggle aria-controls="login-password" aria-pressed="false">Lihat password</button>
                </div>
                <label class="login-remember"><input name="remember" type="checkbox" value="1"> Tetap masuk di perangkat ini</label>
                <button class="primary wide" type="submit">Masuk ke ruang pengurus <span>→</span></button>
            </form>
            <p class="help">Pemulihan akun dilakukan oleh superadmin.<br>{{ config('airbersih.mode') === 'operational' ? 'Gunakan akun operasional yang diberikan pengurus.' : 'Akun simulasi tersedia pada berkas privat proyek.' }}</p>
        </div>
    </section>
</main>
</body>
</html>
