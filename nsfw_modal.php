<?php
// ============================================================
// nsfw_modal.php — NSFW модаль (подключать в конце <body>)
// require_once __DIR__ . '/nsfw_modal.php';
// ============================================================
$nsfwConfirmed = !empty($_COOKIE['nsfw_ok']) && $_COOKIE['nsfw_ok']==='1';
if (!$nsfwConfirmed && $currentAccount) {
    $nsfwConfirmed = !empty($currentAccount['nsfw_confirmed']) && !empty($currentAccount['show_nsfw']);
}
?>
<?php if (!$nsfwConfirmed): ?>
<div id="nsfw-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.92);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px">
<div style="background:#161616;border:1px solid #2e2e2e;border-radius:20px;padding:40px 32px;max-width:400px;width:100%;text-align:center">
    <div style="font-size:40px;margin-bottom:16px">🔞</div>
    <h2 style="font-family:'Syne',sans-serif;font-size:22px;font-weight:800;color:#f2f2f2;margin-bottom:12px">Подтверждение возраста</h2>
    <p style="color:#888;font-size:14px;line-height:1.6;margin-bottom:28px">Сайт содержит контент для взрослых.<br>Вам есть <strong style="color:#f2f2f2">18 лет</strong>?</p>
    <div style="display:flex;gap:12px">
        <button onclick="confirmNsfw(true)" style="flex:1;padding:13px;background:#7c5cff;border:none;border-radius:10px;color:#fff;font-size:14px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif">Да, мне есть 18</button>
        <button onclick="confirmNsfw(false)" style="flex:1;padding:13px;background:#242424;border:1px solid #2e2e2e;border-radius:10px;color:#888;font-size:14px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif">Нет</button>
    </div>
    <p style="color:#555;font-size:11px;margin-top:16px">Нажимая «Да», вы подтверждаете что вам 18+</p>
</div>
</div>
<script>
async function confirmNsfw(confirmed) {
    try {
        await fetch('/api/nsfw-confirm',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({confirm:confirmed})});
    } catch(e){}
    if (confirmed) {
        document.getElementById('nsfw-overlay').remove();
    } else {
        // Скрыть весь NSFW-контент на странице
        document.querySelectorAll('[data-nsfw]').forEach(el => el.style.display='none');
        document.getElementById('nsfw-overlay').remove();
    }
}
</script>
<?php endif; ?>