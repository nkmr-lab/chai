<?php
// /var/www/chai/index.php — chai.nkmr.io SPA シェル
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

$me = nkmrauth_require();            // ログイン必須（未ログインは auth.nkmr.io へ）
$state = tier_state($me['email']);
$logout = nkmrauth_logout_url();
$ver = 50;                            // ★アプリ版。反映のたびに +1（cache-bust＆画面表示の単一の源）
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
<title>chai — 中村研 AI チャット</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Ctext y='26' font-size='26'%3E%F0%9F%8D%B5%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="/assets/styles.css?v=<?= $ver ?>">
<link rel="stylesheet" href="/assets/katex/katex.min.css">
<!-- PWA（ホーム追加でアプリ化） -->
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#0a7c5a">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="chai">
<link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
</head>
<body>
<div id="app" class="app" aria-busy="true">
  <!-- サイドバー -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-head">
      <div class="brand">🍵 chai</div>
      <button class="btn-new" id="btnNew" title="新しいチャット">＋ 新しいチャット</button>
      <button class="btn-stock" id="btnStock" title="ストック（切り抜き保存）">⭐ ストック</button>
    </div>
    <div class="side-scroll">
      <div class="todos" id="todos"></div>
      <div class="bookmarks" id="bookmarks"></div>
      <div class="pinsets" id="pinsets"></div>
      <nav class="conv-list" id="convList" aria-label="会話一覧"></nav>
    </div>
    <div class="sidebar-foot">
      <div class="tier-badge" id="tierBadge"></div>
      <div class="tier-note" id="tierNote"></div>
      <button class="btn-feedback" id="btnFeedback">💬 要望・不具合を送る</button>
      <div class="user-row">
        <span class="user-name" id="userName"></span>
        <span class="app-ver" id="appVer">v<?= $ver ?></span>
        <a class="logout" href="<?= htmlspecialchars($logout, ENT_QUOTES) ?>">ログアウト</a>
      </div>
    </div>
  </aside>

  <!-- メイン -->
  <main class="main">
    <header class="topbar">
      <button class="hamburger" id="btnMenu" aria-label="メニュー">☰</button>
      <div class="topbar-title" id="topbarTitle">🍵 chai</div>
      <button class="btn-speed" id="btnSpeed" title="表示のしかたを切り替え"></button>
      <button class="btn-plan" id="btnPlan">プラン</button>
    </header>
    <div class="panes" id="panes"></div>
  </main>

  <!-- 要望・不具合モーダル -->
  <div class="modal-backdrop" id="fbModal" hidden>
    <div class="modal" role="dialog" aria-modal="true">
      <button class="modal-close" id="fbClose" aria-label="閉じる">×</button>
      <h2 id="fbTitle">💬 要望・不具合</h2>
      <div id="fbBody"></div>
    </div>
  </div>

  <!-- 共有モーダル -->
  <div class="modal-backdrop" id="shareModal" hidden>
    <div class="modal" role="dialog" aria-modal="true">
      <button class="modal-close" id="shareClose" aria-label="閉じる">×</button>
      <h2>👥 このチャットを共有</h2>
      <div id="shareBody"></div>
    </div>
  </div>

  <!-- ピンセット編集モーダル -->
  <div class="modal-backdrop" id="pinsetModal" hidden>
    <div class="modal" role="dialog" aria-modal="true">
      <button class="modal-close" id="pinsetClose" aria-label="閉じる">×</button>
      <h2 id="pinsetTitle">ピンセットを編集</h2>
      <div id="pinsetBody"></div>
    </div>
  </div>

  <!-- ストックモーダル -->
  <div class="modal-backdrop" id="stockModal" hidden>
    <div class="modal" role="dialog" aria-modal="true">
      <button class="modal-close" id="stockClose" aria-label="閉じる">×</button>
      <h2>⭐ ストック</h2>
      <div id="stockBody"></div>
    </div>
  </div>

  <!-- プラン/課金モーダル -->
  <div class="modal-backdrop" id="planModal" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="planTitle">
      <button class="modal-close" id="planClose" aria-label="閉じる">×</button>
      <h2 id="planTitle">プラン</h2>
      <div id="planBody"></div>
    </div>
  </div>
</div>

<script>
window.CHAI = {
  user:  <?= json_encode(['email'=>$me['email'],'name'=>$me['name']??'','user'=>$me['user']??''], JSON_UNESCAPED_UNICODE) ?>,
  tier:  <?= json_encode($state, JSON_UNESCAPED_UNICODE) ?>,
  version: <?= $ver ?>
};
</script>
<script>if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js').catch(() => {});</script>
<script src="/assets/katex/katex.min.js"></script>
<script src="/assets/app.js?v=<?= $ver ?>"></script>
</body>
</html>
