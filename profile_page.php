<?php
// ============================================================
// СТРАНИЦА ПРОФИЛЯ ПОЛЬЗОВАТЕЛЯ — /u/USERNAME
// Вставить в index.php ПОСЛЕ подключения functions.php
// Обрабатывает маршруты: /u/USERNAME и /user/USERNAME
// ============================================================

// Маршруты профиля: /tg?user_id=TELEGRAM_ID
$profile = null;
$isProfileRoute = false;

// Маршрут: /tg?user_id=TELEGRAM_ID
if ($path === '/tg' && !empty($_GET['user_id'])) {
    $isProfileRoute = true;
    $tgUserId = (int)$_GET['user_id'];
    
    // Найти аккаунт по Telegram ID
    $tgStmt = $pdo->prepare("SELECT id FROM accounts WHERE tg_user_id=?");
    $tgStmt->execute([$tgUserId]);
    $tgAccount = $tgStmt->fetch();
    
    if ($tgAccount) {
        $idStmt = $pdo->prepare("SELECT username FROM accounts WHERE id=?");
        $idStmt->execute([(int)$tgAccount['id']]);
        $accData = $idStmt->fetch();
        if ($accData) {
            $profile = getUserProfile($pdo, $accData['username']);
        }
    }
}
// Маршрут: /user/USERNAME (с полным словом — без конфликта с /u/ из index.php)
elseif (preg_match('#^/user/([a-zA-Z0-9_]{1,50})$#', $path, $m)) {
    $isProfileRoute = true;
    $profile = getUserProfile($pdo, $m[1]);
}

// Показываем профиль или ошибку 404
if ($isProfileRoute) {
    if (!$profile) {
        http_response_code(404);
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>404</title></head><body style="background:#0c0c0c;color:#fff;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;text-align:center"><div><div style="font-size:60px">👤</div><h1 style="margin:16px 0 8px">Пользователь не найден</h1><a href="/" style="color:#7c5cff">На главную</a></div></body></html>';
        exit;
    }

    $uid = (int)$profile['user']['id'];
    $isSelf = $currentAccount && (int)$currentAccount['id'] === $uid;
    $isAdm = $currentAccount && isAccountAdmin($pdo, (int)$currentAccount['id']);
    $privacy = $profile['user']['profile_privacy'] ?? 'public';
    $canView = ($privacy === 'public') || $isSelf || $isAdm;

    if ($currentAccount && !$isSelf && $privacy === 'friends') {
        $fStmt = $pdo->prepare("SELECT id FROM friendships WHERE ((requester_id=? AND addressee_id=?) OR (requester_id=? AND addressee_id=?)) AND status='accepted'");
        $fStmt->execute([(int)$currentAccount['id'], $uid, $uid, (int)$currentAccount['id']]);
        $canView = (bool)$fStmt->fetch();
    }
    if ($isSelf || $isAdm) $canView = true;

    $frame  = getLevelFrame((int)$profile['xp']['level']);
    $xpProg = $profile['xp']['progress'];

    // Статус подписки
    $isSubscribed = false;
    if ($currentAccount && !$isSelf) {
        $subStmt = $pdo->prepare("SELECT 1 FROM user_subscriptions WHERE follower_id=? AND following_id=?");
        $subStmt->execute([(int)$currentAccount['id'], $uid]);
        $isSubscribed = (bool)$subStmt->fetch();
    }

    // Дружба
    $friendship = null;
    if ($currentAccount && !$isSelf) {
        $fsStmt = $pdo->prepare("SELECT id, status, requester_id FROM friendships WHERE (requester_id=? AND addressee_id=?) OR (requester_id=? AND addressee_id=?)");
        $fsStmt->execute([(int)$currentAccount['id'], $uid, $uid, (int)$currentAccount['id']]);
        $friendship = $fsStmt->fetch() ?: null;
    }

    $bannerColor = htmlspecialchars($profile['user']['banner_color'] ?? '#1a1a2e');
    $bio = $profile['user']['bio'] ? htmlspecialchars($profile['user']['bio']) : '';
    $avatarUrl = $profile['user']['avatar_url'] ?? '';
    $bannerUrl = $profile['user']['banner_url'] ?? '';
    $username = htmlspecialchars($profile['user']['username']);

    $regDate = $profile['user']['reg_date'] ?? $profile['user']['created_at'] ?? '';
    $regFormatted = $regDate ? date('d.m.Y', strtotime($regDate)) : '—';

    $libStats = $profile['lib_stats'] ?? ['read'=>0,'reading'=>0,'plan_to_read'=>0,'dropped'=>0,'on_hold'=>0];

?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=$username?> | BLACKWATCH</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--bg:#0c0c0c;--card:#161616;--border:#242424;--border2:#2e2e2e;--text:#f2f2f2;--text2:#c8c8c8;--muted:#666;--accent:#7c5cff;--green:#4ade80;--orange:#fb923c;--red:#ef4444}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:'Inter',sans-serif;min-height:100vh}

/* HEADER */
.page-header{padding:14px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;position:sticky;top:0;z-index:100;background:rgba(12,12,12,.92);backdrop-filter:blur(12px)}
.back-btn{color:var(--muted);text-decoration:none;font-size:13px;transition:color .2s}.back-btn:hover{color:var(--text)}
.page-title{font-family:'Syne',sans-serif;font-weight:800;font-size:17px}

/* BANNER */
.profile-banner{height:160px;background:<?=$bannerColor?>;position:relative;overflow:hidden}
.banner-img{width:100%;height:100%;object-fit:cover;position:absolute;inset:0}
.banner-gradient{position:absolute;inset:0;background:linear-gradient(to bottom,transparent 40%,rgba(12,12,12,.9) 100%)}

/* PROFILE CARD */
.profile-wrap{max-width:720px;margin:0 auto;padding:0 16px 40px}
.profile-top{display:flex;align-items:flex-end;gap:16px;margin-top:-50px;padding-bottom:16px;position:relative;z-index:2}
.avatar-ring{width:90px;height:90px;border-radius:50%;border:3px solid <?=$frame['color']?>;background:var(--card);display:flex;align-items:center;justify-content:center;font-size:36px;overflow:hidden;flex-shrink:0;<?=$frame['glow']?'box-shadow:0 0 20px '.$frame['color'].'66;':''?>}
.avatar-ring img{width:100%;height:100%;object-fit:cover;border-radius:50%}
.avatar-wrap{position:relative}
.online-dot-big{position:absolute;bottom:4px;right:4px;width:14px;height:14px;border-radius:50%;border:2.5px solid var(--bg)}
.profile-name-area{flex:1;padding-bottom:4px}
.profile-username{font-family:'Syne',sans-serif;font-size:22px;font-weight:800;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.admin-badge{font-size:9px;font-weight:700;padding:2px 8px;border-radius:10px;background:rgba(239,68,68,.15);color:#f87171;border:1px solid rgba(239,68,68,.3)}
.verified-badge{color:#60a5fa;font-size:14px;title:"Верифицирован"}
.profile-level{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:3px 10px;border-radius:12px;background:<?=$frame['color']?>22;color:<?=$frame['color']?>;border:1px solid <?=$frame['color']?>44;margin-top:5px}
.profile-online{font-size:12px;color:var(--muted);margin-top:4px}
.profile-actions{display:flex;gap:8px;margin-left:auto;align-items:flex-end;padding-bottom:4px;flex-wrap:wrap}
.btn{padding:8px 18px;border-radius:10px;font-size:12px;font-weight:700;border:none;cursor:pointer;font-family:inherit;transition:all .18s;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.btn-accent{background:var(--accent);color:#fff}.btn-accent:hover{opacity:.85}
.btn-outline{background:transparent;border:1px solid var(--border2);color:var(--text2)}.btn-outline:hover{border-color:var(--accent);color:var(--accent)}
.btn-green{background:rgba(74,222,128,.15);border:1px solid rgba(74,222,128,.3);color:var(--green)}.btn-green:hover{background:rgba(74,222,128,.25)}
.btn-msg{background:rgba(255,255,255,.06);border:1px solid var(--border2);color:var(--text2)}.btn-msg:hover{background:rgba(124,92,255,.1);border-color:var(--accent);color:var(--accent)}

/* XP BAR */
.xp-bar-wrap{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:14px 18px;margin-bottom:14px}
.xp-bar-label{display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-bottom:8px}
.xp-bar{height:7px;background:rgba(255,255,255,.07);border-radius:6px;overflow:hidden}
.xp-bar-fill{height:100%;border-radius:6px;background:linear-gradient(90deg,<?=$frame['color']?>,<?=$frame['color']?>88);transition:width .6s ease}

/* BIO */
.bio-box{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:14px 18px;margin-bottom:14px;font-size:13px;color:var(--text2);line-height:1.6}
.bio-box.empty{color:var(--muted);font-style:italic}

/* STATS GRID */
.stats-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:14px 12px;text-align:center}
.stat-value{font-family:'Syne',sans-serif;font-size:22px;font-weight:800;color:var(--text);line-height:1}
.stat-label{font-size:10px;color:var(--muted);margin-top:5px;text-transform:uppercase;letter-spacing:.5px}

/* LIBRARY BARS */
.lib-section{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px 18px;margin-bottom:14px}
.section-title{font-family:'Syne',sans-serif;font-size:14px;font-weight:700;margin-bottom:14px;display:flex;align-items:center;gap:7px}
.lib-row{display:flex;align-items:center;gap:10px;margin-bottom:8px}
.lib-row:last-child{margin-bottom:0}
.lib-label{font-size:12px;color:var(--text2);width:110px;flex-shrink:0}
.lib-bar-wrap{flex:1;height:6px;background:rgba(255,255,255,.07);border-radius:4px;overflow:hidden}
.lib-bar{height:100%;border-radius:4px;transition:width .4s ease}
.lib-count{font-size:11px;color:var(--muted);width:30px;text-align:right}

/* ACHIEVEMENTS */
.ach-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:8px}
.ach-item{position:relative;width:100%;aspect-ratio:1;background:rgba(255,255,255,.05);border:1px solid var(--border);border-radius:10px;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .2s;overflow:hidden}
.ach-item.common{background:rgba(100,150,200,.1);border-color:rgba(100,150,200,.3)}
.ach-item.rare{background:rgba(150,100,255,.1);border-color:rgba(150,100,255,.3)}
.ach-item.epic{background:rgba(255,150,100,.1);border-color:rgba(255,150,100,.3)}
.ach-item.legendary{background:rgba(255,200,100,.1);border-color:rgba(255,200,100,.3)}
.ach-item:hover{transform:scale(1.05)}
.ach-icon{font-size:28px;line-height:1}
.ach-name{font-size:9px;color:var(--text2);text-align:center;margin-top:4px;word-break:break-word}
.ach-tooltip{position:absolute;bottom:-30px;left:50%;transform:translateX(-50%);background:var(--card);border:1px solid var(--border);border-radius:8px;padding:6px 10px;font-size:10px;white-space:nowrap;opacity:0;pointer-events:none;transition:opacity .2s}
.ach-item:hover .ach-tooltip{opacity:1;bottom:100%}
.ach-empty{text-align:center;color:var(--muted);font-style:italic;padding:20px 0}

/* COMMENTS */
.comment-card{background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin-bottom:10px;font-size:12px}
.comment-manga{color:var(--accent);text-decoration:none;font-weight:600}.comment-manga:hover{text-decoration:underline}
.comment-text{color:var(--text2);margin:8px 0;line-height:1.5}
.comment-meta{display:flex;justify-content:space-between;font-size:10px;color:var(--muted)}
.comment-likes{color:var(--orange)}

/* PRIVATE BLOCK */
.private-block{text-align:center;padding:60px 20px;color:var(--muted)}
.private-icon{font-size:60px;margin-bottom:20px}
.private-text{font-size:16px}

/* TOAST */
.toast{position:fixed;bottom:20px;right:20px;background:rgba(0,0,0,.8);color:#fff;padding:12px 20px;border-radius:10px;z-index:10000;animation:slideIn .3s ease}
@keyframes slideIn{from{transform:translateX(400px);opacity:0}to{transform:translateX(0);opacity:1}}
</style>
</head>
<body>

<div class="page-header">
    <a href="/" class="back-btn">← На главную</a>
    <div class="page-title">Профиль</div>
</div>

<div class="profile-banner" style="<?=$bannerUrl?'background-image:url('.htmlspecialchars($bannerUrl).');background-size:cover;':''?>">
    <?php if ($bannerUrl): ?><img src="<?=htmlspecialchars($bannerUrl)?>" class="banner-img" alt=""><?php endif; ?>
    <div class="banner-gradient"></div>
</div>

<div class="profile-wrap">
    <?php if ($canView): ?>
    <!-- ПРОФИЛЬ ИНФОРМАЦИЯ -->
    <div class="profile-top">
        <div class="avatar-wrap">
            <div class="avatar-ring">
                <?php if ($avatarUrl): ?>
                <img src="<?=htmlspecialchars($avatarUrl)?>" alt="<?=$username?>">
                <?php else: ?>
                👤
                <?php endif; ?>
            </div>
            <?php if ($profile['online']['status'] === 'online'): ?>
            <div class="online-dot-big" style="background:#4ade80;box-shadow:0 0 8px #4ade80"></div>
            <?php endif; ?>
        </div>
        <div class="profile-name-area">
            <div class="profile-username">
                <?=$username?>
                <?php if ($profile['user']['is_admin']): ?><span class="admin-badge">АДМИН</span><?php endif; ?>
                <?php if ($profile['user']['is_verified']): ?><span class="verified-badge">✓</span><?php endif; ?>
            </div>
            <div class="profile-level">
                <?=$frame['label']?> · <?=(int)$profile['xp']['level']?> уровень
            </div>
            <div class="profile-online"><?=$profile['online']['label']?></div>
        </div>
        <div class="profile-actions">
            <?php if ($currentAccount && !$isSelf): ?>
            <button class="btn btn-msg" onclick="openMessages(<?=$uid?>, '<?=addslashes($username)?>')">💬 Написать</button>
            <button class="btn btn-accent" id="sub-btn" onclick="toggleSubscribe(<?=$uid?>)" style="<?=$isSubscribed?'background:rgba(74,222,128,.15);border:1px solid rgba(74,222,128,.3);color:#4ade80;':''?>">
                <?=$isSubscribed?'✓ Подписан':'+ Подписаться'?>
            </button>
            <?php if ($friendship): ?>
                <?php if ($friendship['status'] === 'pending' && $friendship['requester_id'] !== (int)$currentAccount['id']): ?>
                <button class="btn btn-outline" onclick="friendAction(<?=(int)$friendship['id']?>, 'accept')">✓ Принять</button>
                <button class="btn btn-outline" onclick="friendAction(<?=(int)$friendship['id']?>, 'reject')">✕ Отклонить</button>
                <?php elseif ($friendship['status'] === 'accepted'): ?>
                <button class="btn btn-outline" onclick="friendAction(<?=(int)$friendship['id']?>, 'remove')">👥 В друзьях</button>
                <?php elseif ($friendship['status'] === 'pending'): ?>
                <button class="btn btn-outline" onclick="friendAction(<?=(int)$friendship['id']?>, 'cancel')">⏳ Отменить</button>
                <?php endif; ?>
            <?php else: ?>
            <button class="btn btn-outline" id="friend-btn" onclick="friendRequest(<?=$uid?>)">👥 Добавить</button>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- XP ПРОГРЕСС -->
    <div class="xp-bar-wrap">
        <div class="xp-bar-label">
            <span>XP: <?=number_format($xpProg['current'])?> / <?=number_format($xpProg['needed'])?></span>
            <span>→ Уровень <?=$xpProg['next_lvl']?></span>
        </div>
        <div class="xp-bar"><div class="xp-bar-fill" style="width:<?=$xpProg['pct']?>%"></div></div>
    </div>

    <!-- BIO -->
    <?php if ($bio): ?>
    <div class="bio-box"><?=$bio?></div>
    <?php else: ?>
    <div class="bio-box empty">Нет описания профиля</div>
    <?php endif; ?>

    <!-- STATS GRID -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?=number_format((int)($profile['user']['total_manga_read'] ?? 0))?></div>
            <div class="stat-label">📚 Манг прочитано</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?=number_format((int)($profile['user']['total_chapters_read'] ?? 0))?></div>
            <div class="stat-label">📄 Глав прочитано</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?=number_format((int)($profile['user']['total_pages_read'] ?? 0))?></div>
            <div class="stat-label">📖 Страниц прочитано</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?=number_format((int)($profile['user']['total_ratings'] ?? 0))?></div>
            <div class="stat-label">⭐ Оценок</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?=number_format((int)($profile['user']['total_comments'] ?? 0))?></div>
            <div class="stat-label">💬 Комментариев</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">🔥 <?=(int)($profile['user']['reading_streak'] ?? 0)?></div>
            <div class="stat-label">Дней стрик</div>
        </div>
    </div>

    <!-- LIBRARY BARS -->
    <?php $totalLib = array_sum($libStats); ?>
    <div class="lib-section">
        <div class="section-title">📚 Библиотека</div>
        <?php
        $libItems = [
            ['label'=>'Прочитано',   'key'=>'read',         'color'=>'#4ade80'],
            ['label'=>'Читаю',       'key'=>'reading',      'color'=>'#7c5cff'],
            ['label'=>'В планах',    'key'=>'plan_to_read', 'color'=>'#60a5fa'],
            ['label'=>'Брошено',     'key'=>'dropped',      'color'=>'#ef4444'],
            ['label'=>'На паузе',    'key'=>'on_hold',      'color'=>'#fb923c'],
        ];
        foreach ($libItems as $li):
            $cnt = (int)($libStats[$li['key']] ?? 0);
            $pct = $totalLib > 0 ? round($cnt / $totalLib * 100) : 0;
        ?>
        <div class="lib-row">
            <div class="lib-label"><?=$li['label']?></div>
            <div class="lib-bar-wrap"><div class="lib-bar" style="width:<?=$pct?>%;background:<?=$li['color']?>"></div></div>
            <div class="lib-count"><?=$cnt?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ДОСТИЖЕНИЯ -->
    <div class="lib-section">
        <div class="section-title">🏆 Достижения <span style="font-size:11px;color:var(--muted);font-family:'Inter',sans-serif;font-weight:400"><?=count($profile['achievements'])?> получено</span></div>
        <?php if (count($profile['achievements']) > 0): ?>
        <div class="ach-grid">
        <?php foreach ($profile['achievements'] as $ach): ?>
            <div class="ach-item <?=htmlspecialchars($ach['rarity'])?>">
                <div class="ach-icon"><?=htmlspecialchars($ach['icon'])?></div>
                <div class="ach-name"><?=htmlspecialchars(mb_substr($ach['name'], 0, 16))?></div>
                <div class="ach-tooltip"><?=htmlspecialchars($ach['name'])?></div>
            </div>
        <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="ach-empty">Пока нет достижений</div>
        <?php endif; ?>
    </div>

    <!-- ПОСЛЕДНИЕ КОММЕНТАРИИ -->
    <?php if (count($profile['comments']) > 0): ?>
    <div class="lib-section">
        <div class="section-title">💬 Последние комментарии</div>
        <?php foreach ($profile['comments'] as $c): ?>
        <div class="comment-card">
            <a href="/manga/<?=(int)$c['manga_id']?>" class="comment-manga">📖 <?=htmlspecialchars($c['manga_title'])?></a>
            <div class="comment-text"><?=htmlspecialchars(mb_substr($c['text'], 0, 200))?><?=mb_strlen($c['text'])>200?'…':''?></div>
            <div class="comment-meta">
                <span><?=date('d.m.Y H:i', strtotime($c['created_at']))?></span>
                <span class="comment-likes">❤️ <?=(int)$c['likes']?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ИНФО -->
    <div class="lib-section" style="font-size:12px;color:var(--muted)">
        <div style="display:flex;flex-wrap:wrap;gap:16px">
            <span>📅 Зарегистрирован: <span style="color:var(--text2)"><?=$regFormatted?></span></span>
            <span>🏅 Недельный XP: <span style="color:var(--text2)"><?=number_format((int)$profile['xp']['weekly'])?></span></span>
        </div>
    </div>

    <?php else: /* private */ ?>
    <div class="private-block">
        <div class="private-icon">🔒</div>
        <div class="private-text">Этот профиль приватный</div>
    </div>
    <?php endif; ?>
</div>

<script>
function showToast(msg, dur=2500){const t=document.createElement('div');t.className='toast';t.textContent=msg;document.body.appendChild(t);setTimeout(()=>t.remove(),dur);}

function openMessages(uid, username) {
    window.location.href = '/messages?with=' + uid;
}

async function friendRequest(targetId) {
    try {
        const res = await fetch('/api/friends/add', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({target_id: targetId})
        });
        const d = await res.json();
        if (d.success) { showToast('✅ Запрос отправлен!'); const btn = document.getElementById('friend-btn'); if(btn){btn.textContent='⏳ Отменить';btn.className='btn btn-outline';} }
        else showToast('❌ ' + (d.error || 'Ошибка'));
    } catch(e) { showToast('Ошибка'); }
}

async function friendAction(id, action) {
    try {
        await fetch(`/api/friends/${id}/${action}`, {method: 'POST'});
        const msgs = {accept: '✅ Добавлен в друзья!', reject: 'Отклонено', remove: 'Удалён из друзей'};
        showToast(msgs[action] || 'OK');
        setTimeout(() => location.reload(), 800);
    } catch(e) { showToast('Ошибка'); }
}

async function toggleSubscribe(targetId) {
    try {
        const res = await fetch('/api/subscribe', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({target_id: targetId})
        });
        const d = await res.json();
        if (d.success) {
            const btn = document.getElementById('sub-btn');
            if (d.subscribed) {
                btn.textContent = '✓ Подписан';
                btn.className = 'btn btn-green';
                showToast('Подписка оформлена');
            } else {
                btn.textContent = '+ Подписаться';
                btn.className = 'btn btn-accent';
                showToast('Подписка отменена');
            }
        }
    } catch(e) { showToast('Ошибка'); }
}

// Ping онлайн статус
<?php if ($currentAccount): ?>
setInterval(() => fetch('/api/ping', {method:'POST'}), 60000);
<?php endif; ?>
</script>

</body>
</html>
<?php 
}  // Закрытие if ($isProfileRoute) блока
?>