<?php
require_once __DIR__ . '/includes/auth.php';

if (current_user()) {
    redirect('dashboard.php');
}

$shopName = get_setting('shop_name', "Pia's Laundry Shop");
$address = get_setting('shop_address', '');
$phone = get_setting('shop_phone', '');
$services = db()->query('SELECT name, category, unit, price, description FROM services WHERE is_active = 1 ORDER BY category, name')->fetchAll();
$appDownloadUrl = 'https://github.com/genanncarpio-source/pias-laundry-shop/releases/download/pias-laundry-app-v1.0.5/pias-laundry-shop-v1.0.5.apk';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#58265f">
<meta name="description" content="Discover <?= e($shopName) ?> laundry services and download our customer app.">
<title><?= e($shopName) ?> · Laundry made simple</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css?v=9">
<style>
:root{--site-ink:#102a35;--site-muted:#58717a;--site-teal:#087f8c;--site-aqua:#c9eff0;--site-yellow:#ffc928}
*{box-sizing:border-box}html{scroll-behavior:smooth}body.public-site{margin:0;background:#f4f9f7;color:var(--site-ink);font:16px 'DM Sans',sans-serif;line-height:1.6}.public-site a{color:inherit;text-decoration:none}.site-wrap{max-width:1160px;width:calc(100% - 48px);margin:auto}.site-nav{height:80px;display:flex;align-items:center;justify-content:space-between}.site-logo{display:flex;align-items:center;gap:11px;font:800 19px Manrope,sans-serif;letter-spacing:-.7px}.logo-mark{width:39px;height:39px;display:grid;place-items:center;border-radius:13px;background:var(--site-teal);color:#fff;font-size:21px}.site-links{display:flex;align-items:center;gap:30px;color:#34505b;font-weight:600;font-size:14px}.pill-button{display:inline-flex;align-items:center;justify-content:center;gap:9px;padding:13px 21px;border-radius:12px;background:var(--site-yellow);color:#17242a!important;font-weight:700;box-shadow:0 7px 18px #ffc92838;transition:transform .2s,box-shadow .2s}.pill-button:hover{transform:translateY(-2px);box-shadow:0 10px 23px #ffc92855}.pill-button.teal{background:var(--site-teal);color:#fff!important;box-shadow:0 7px 18px #087f8c38}.hero{position:relative;isolation:isolate;overflow:hidden;min-height:590px;display:grid;grid-template-columns:1fr .88fr;align-items:center;gap:35px;padding:64px max(24px,calc((100% - 1160px)/2));background:linear-gradient(115deg,#073b4c 0%,#0e5c68 53%,#128b90 100%);color:white}.hero:before{content:"";position:absolute;z-index:-1;inset:0;background:linear-gradient(90deg,#073b4cf2 2%,#073b4cbb 53%,#073b4c2b 100%),url('img/laundry-hero.svg') center/cover no-repeat;opacity:.8}.hero-copy{max-width:590px}.eyebrow{display:inline-flex;gap:8px;align-items:center;padding:7px 13px;border:1px solid #ffffff4d;border-radius:999px;background:#ffffff12;color:#d2f7f4;font-size:13px;font-weight:700}.hero h1{font:800 clamp(42px,6vw,70px)/1.04 Manrope,sans-serif;letter-spacing:-2.8px;margin:23px 0 18px}.hero h1 span{color:var(--site-yellow)}.hero-copy>p{max-width:500px;color:#d2e7e8;font-size:17px}.hero-actions{display:flex;gap:12px;flex-wrap:wrap;margin:27px 0}.outline-button{display:inline-flex;align-items:center;justify-content:center;border:1px solid #ffffff87;border-radius:12px;padding:12px 20px;color:white!important;font-weight:700}.hero-facts{display:flex;gap:24px;flex-wrap:wrap;margin-top:27px;color:#d1eff0;font-size:13px}.hero-facts span:before{content:'✦';color:var(--site-yellow);margin-right:7px}.washer-art{position:relative;min-height:400px;display:grid;place-items:center}.washer-card{width:min(100%,370px);aspect-ratio:.83;border:7px solid #e6f7f4;border-radius:40px;background:linear-gradient(145deg,#e8f7f5,#a9d9dc);box-shadow:0 34px 85px #00191f80;transform:rotate(3deg);padding:22px;position:relative}.washer-window{position:absolute;inset:16% 12% 19%;border:16px solid #d6e5e4;border-radius:50%;background:radial-gradient(circle at 43% 37%,#e4faf5 0 13%,#72c8c8 14% 35%,#247e8c 60%,#164657 100%);box-shadow:inset 0 0 35px #103943;overflow:hidden}.washer-window:before,.washer-window:after{content:'';position:absolute;border-radius:50%;background:#ffffff55;filter:blur(2px)}.washer-window:before{width:110px;height:33px;top:26px;left:32px;transform:rotate(-34deg)}.washer-window:after{width:160px;height:85px;bottom:-26px;right:-10px;background:#74dad2a3}.washer-controls{position:absolute;top:6%;left:13%;right:13%;height:8%;border-radius:14px;background:#fafffb;display:flex;align-items:center;justify-content:space-between;padding:0 16px;color:#39808a}.washer-controls:after{content:'◉   ◉   ◉';letter-spacing:8px}.floating-note{position:absolute;right:-15px;bottom:44px;padding:14px 18px;border-radius:15px;background:white;color:var(--site-ink);font-weight:700;box-shadow:0 13px 38px #002b3540}.floating-note strong{display:block;color:var(--site-teal);font-size:13px}.section{padding:85px 0}.section-head{text-align:center;max-width:630px;margin:0 auto 38px}.section-head .tag{font-size:12px;color:var(--site-teal);font-weight:800;text-transform:uppercase;letter-spacing:1.4px}.section-head h2{font:800 clamp(30px,4vw,42px)/1.15 Manrope,sans-serif;letter-spacing:-1.4px;margin:9px 0}.section-head p{color:var(--site-muted);margin:10px 0}.services-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:17px}.service-card{padding:23px;border:1px solid #dbe9e7;border-radius:17px;background:white;box-shadow:0 7px 25px #163b4109}.service-icon{width:43px;height:43px;display:grid;place-items:center;background:#ddf4f1;border-radius:13px;color:var(--site-teal);font-size:21px}.service-card h3{margin:15px 0 5px;font:700 17px Manrope,sans-serif}.service-card p{margin:0;color:var(--site-muted);font-size:14px}.service-price{margin-top:16px;color:var(--site-teal);font-weight:800}.app-section{padding:5px 0 86px}.app-panel{display:grid;grid-template-columns:1.2fr .8fr;gap:35px;align-items:center;border-radius:25px;padding:48px 55px;background:#dff3ef;overflow:hidden}.app-panel h2{font:800 36px/1.15 Manrope,sans-serif;letter-spacing:-1px;margin:10px 0}.app-panel p{max-width:560px;color:var(--site-muted)}.phone-mock{width:220px;height:295px;border:8px solid #17323c;border-radius:31px;background:#fff;margin:auto;padding:17px 13px;box-shadow:0 20px 30px #13343a38;transform:rotate(5deg)}.phone-top{height:15px;width:62px;border-radius:99px;background:#17323c;margin:0 auto 20px}.app-icon{width:62px;height:62px;border-radius:18px;background:#087f8c;color:white;display:grid;place-items:center;font-size:32px;margin:10px auto}.phone-mock h3{text-align:center;font:700 14px Manrope}.app-row{height:35px;border-radius:9px;background:#eff8f6;margin-top:9px;padding:8px;color:#52727a;font-size:11px}.business{display:grid;grid-template-columns:1fr 1fr;gap:18px}.business-card{padding:26px;border-radius:17px;background:#fff;border:1px solid #dbe9e7}.business-card h3{margin:0 0 10px;font:700 18px Manrope}.business-card p{color:var(--site-muted);margin:5px 0}.site-footer{padding:32px 0;background:#073b4c;color:#c8e4e5}.footer-row{display:flex;justify-content:space-between;gap:20px;align-items:center}.site-footer a{color:white;font-weight:600}.footer-note{font-size:13px;color:#92bfc1}.empty-services{grid-column:1/-1;text-align:center;color:var(--site-muted);padding:30px}.staff-link{opacity:.8;font-size:13px}
@media(max-width:800px){.site-links{gap:16px}.site-links a:not(.pill-button){display:none}.hero{grid-template-columns:1fr;padding-top:65px}.hero-copy{max-width:650px}.washer-art{min-height:310px}.washer-card{width:250px}.services-grid{grid-template-columns:repeat(2,1fr)}.app-panel{padding:35px;grid-template-columns:1fr .7fr}.business{grid-template-columns:1fr 1fr}}
@media(max-width:560px){.site-wrap{width:calc(100% - 34px)}.site-nav{height:70px}.site-logo{font-size:16px}.logo-mark{width:35px;height:35px}.site-nav>.pill-button{padding:10px 13px;font-size:13px}.hero{min-height:auto;padding:56px 22px 40px}.hero h1{font-size:48px;letter-spacing:-2px}.hero-copy>p{font-size:15px}.hero-actions{gap:9px}.hero-actions .pill-button,.outline-button{padding:11px 14px;font-size:13px}.hero-facts{gap:10px 18px}.washer-art{min-height:285px}.washer-card{width:230px}.floating-note{right:0;bottom:17px}.section{padding:62px 0}.services-grid{grid-template-columns:1fr}.app-panel{grid-template-columns:1fr;padding:28px 22px}.app-panel h2{font-size:29px}.phone-mock{display:none}.business{grid-template-columns:1fr}.footer-row{align-items:flex-start;flex-direction:column}}
</style>
<style>
/* Match the public preview to the shop system's plum and warm cream palette. */
:root{--site-ink:#201820;--site-muted:#786f78;--site-teal:#58265f;--site-aqua:#f2eaf4;--site-yellow:#d7a54a}
body.public-site{background:#f6efe6;color:var(--site-ink)}
.site-nav{background:#fffaf2}
.site-links{color:#584d59}
.hero{background:linear-gradient(115deg,#18131a 0%,#49204f 56%,#70447a 100%)}
.hero:before{background:linear-gradient(90deg,#18131af5 2%,#49204fdf 53%,#58265f85 100%),url('img/laundry-hero.svg') center/cover no-repeat;opacity:.88}
.hero h1 span{color:#e4b957}
.eyebrow{color:#f2d8f4}
.hero-facts{color:#eadfec}
.hero-facts span:before{color:#e4b957}
.pill-button,.pill-button.teal{background:#58265f;color:#fffaf2!important;box-shadow:0 7px 18px #49204f38}
.pill-button:hover{background:#49204f;box-shadow:0 10px 23px #49204f55}
.outline-button{border-color:#d6b7d9}
.section-head .tag,.service-price,.floating-note strong{color:#58265f}
.service-card,.business-card{background:#fffaf2;border-color:#ded3df;box-shadow:0 7px 25px #2417240b}
.service-icon{background:#f2eaf4;color:#58265f}
.app-panel{background:#f2eaf4}
.phone-mock{border-color:#49204f;background:#fffaf2;box-shadow:0 20px 30px #49204f30}
.phone-top{background:#49204f}
.app-icon{background:#58265f}
.app-row{background:#f2eaf4;color:#66586a}
.site-footer{background:#18131a;color:#ded3df}
.site-footer a{color:#fffaf2}
.footer-note{color:#b5a7b8}
.washer-card{border-color:#f4edf3;background:linear-gradient(145deg,#f5edf4,#d8c5de)}
.washer-window{border-color:#d8cadb;background:radial-gradient(circle at 43% 37%,#f2eaf4 0 13%,#bd91c4 14% 35%,#81548c 60%,#49204f 100%);box-shadow:inset 0 0 35px #29182d}
.washer-window:after{background:#c99bd6a3}
.washer-controls{background:#fffaf2;color:#704478}
</style>
</head>
<body class="public-site">
<header class="site-wrap site-nav">
  <a class="site-logo" href="index.php"><span class="logo-mark">◉</span><span><?= e($shopName) ?></span></a>
  <nav class="site-links" aria-label="Main navigation"><a href="#services">Services</a><a href="#app">Mobile App</a><a href="#visit">Visit Us</a><a class="pill-button" href="<?= e($appDownloadUrl) ?>" target="_blank" rel="noopener">↓ Get the App</a></nav>
</header>
<main>
  <section class="hero">
    <div class="hero-copy">
      <span class="eyebrow">✦ FRESH CARE, MADE EASY</span>
      <h1>Fresh clothes.<br><span>More time for you.</span></h1>
      <p>Meet <?= e($shopName) ?>: your neighborhood laundry team for everyday washing, careful garment care, and a simpler way to keep laundry moving.</p>
      <div class="hero-actions"><a class="pill-button" href="<?= e($appDownloadUrl) ?>" target="_blank" rel="noopener">↓ Download our app</a><a class="outline-button" href="#services">Explore services&nbsp; →</a></div>
      <div class="hero-facts"><span>Care for every load</span><span>Simple mobile booking</span></div>
    </div>
    <div class="washer-art" aria-hidden="true"><div class="washer-card"><div class="washer-controls"></div><div class="washer-window"></div></div><div class="floating-note"><strong>LAUNDRY DAY, SORTED</strong>Fresh care, less hassle ✨</div></div>
  </section>
  <section class="section" id="services"><div class="site-wrap"><div class="section-head"><span class="tag">What we do</span><h2>Care for your everyday</h2><p>Browse the services currently available at <?= e($shopName) ?>.</p></div><div class="services-grid">
  <?php if ($services): foreach ($services as $service): ?>
    <article class="service-card"><span class="service-icon" aria-hidden="true">✳</span><h3><?= e($service['name']) ?></h3><p><?= e($service['description'] ?: $service['category']) ?></p><div class="service-price">₱<?= number_format((float)$service['price'], 2) ?> / <?= e($service['unit']) ?></div></article>
  <?php endforeach; else: ?><p class="empty-services">Service details are being updated. Please contact the shop for current offerings.</p><?php endif; ?>
  </div></div></section>
  <section class="app-section" id="app"><div class="site-wrap"><div class="app-panel"><div><span class="tag" style="color:var(--site-teal);font-weight:800;text-transform:uppercase;letter-spacing:1.3px;font-size:12px">Laundry Pia's on your phone</span><h2>Your laundry, a little easier.</h2><p>Get the <?= e($shopName) ?> customer app to browse services, place orders, and keep up with your laundry. The app is a separate customer experience from the staff website.</p><a class="pill-button teal" href="<?= e($appDownloadUrl) ?>" target="_blank" rel="noopener">↓ Download the Android app (APK)</a><p style="font-size:12px;margin-top:11px">Android APK · Direct download</p></div><div class="phone-mock" aria-label="Preview of the customer mobile app"><div class="phone-top"></div><div class="app-icon">◉</div><h3><?= e($shopName) ?></h3><div class="app-row">Book a laundry service</div><div class="app-row">Track your order</div><div class="app-row">Browse services</div></div></div></div></section>
  <section class="section" id="visit" style="padding-top:5px"><div class="site-wrap"><div class="section-head"><span class="tag">Come say hello</span><h2>Find your laundry place</h2><p>Questions about a service? Get in touch or drop by.</p></div><div class="business"><article class="business-card"><h3>📍 Shop address</h3><p><?= $address !== '' ? nl2br(e($address)) : 'Please contact us for our current shop address.' ?></p></article><article class="business-card"><h3>☎ Get in touch</h3><p><?= $phone !== '' ? '<a href="tel:' . e(preg_replace('/[^0-9+]/', '', $phone)) . '">' . e($phone) . '</a>' : 'Contact details are available at the shop.' ?></p></article></div></div></section>
</main>
<footer class="site-footer"><div class="site-wrap footer-row"><div><strong><?= e($shopName) ?></strong><div class="footer-note">Laundry care made simpler.</div></div><a class="staff-link" href="login.php">Staff &amp; admin login&nbsp; →</a></div></footer>
</body>
</html>
