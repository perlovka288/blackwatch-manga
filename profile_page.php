<?php
// ============================================================
// СТРАНИЦА ПРОФИЛЯ ПОЛЬЗОВАТЕЛЯ — /u/USERNAME
// Вставить в index.php ПОСЛЕ подключения functions.php
// Обрабатывает маршруты: /u/USERNAME и /user/USERNAME
// ============================================================

// Маршрут: /u/USERNAME или /user/USERNAME
if (preg_match('#^/u(?:ser)?/([a-zA-Z0-9_]{1,50})$#', $path, $m)) {
    $targetUsername = $m[1];
    $profile = getUserProfile($pdo, $targetUsername);

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
.lib-bar{height:100%;border-radius:4px;transition:width .6s ease}
.lib-count{font-size:11px;font-weight:600;color:var(--muted);width:28px;text-align:right;flex-shrink:0}

/* ACHIEVEMENTS */
.ach-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(60px,1fr));gap:10px}
.ach-item{aspect-ratio:1;border-radius:12px;background:rgba(255,255,255,.04);border:1px solid var(--border);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;cursor:default;position:relative;transition:all .18s}
.ach-item:hover{background:rgba(124,92,255,.1);border-color:var(--accent)}
.ach-item.common{border-color:var(--border)}
.ach-item.rare{border-color:#3b82f6;box-shadow:0 0 8px #3b82f633}
.ach-item.legendary{border-color:#f59e0b;box-shadow:0 0 12px #f59e0b44}
.ach-icon{font-size:24px;line-height:1}
.ach-name{font-size:8px;color:var(--muted);text-align:center;padding:0 2px;line-height:1.2}
.ach-tooltip{position:absolute;bottom:calc(100% + 8px);left:50%;transform:translateX(-50%);background:#222;border:1px solid var(--border2);border-radius:8px;padding:6px 10px;font-size:11px;white-space:nowrap;z-index:10;pointer-events:none;opacity:0;transition:opacity .2s;color:var(--text2)}
.ach-item:hover .ach-tooltip{opacity:1}
.ach-empty{color:var(--muted);font-size:12px;padding:16px 0}

/* COMMENTS */
.comment-card{padding:12px 0;border-bottom:1px solid var(--border)}
.comment-card:last-child{border-bottom:none}
.comment-manga{font-size:11px;color:var(--accent);margin-bottom:4px;text-decoration:none;display:block}
.comment-manga:hover{text-decoration:underline}
.comment-text{font-size:13px;color:var(--text2);line-height:1.5;margin-bottom:4px}
.comment-meta{font-size:10px;color:var(--muted);display:flex;align-items:center;gap:8px}
.comment-likes{color:var(--muted);display:flex;align-items:center;gap:3px}

/* PRIVATE */
.private-block{text-align:center;padding:40px 20px;color:var(--muted)}
.private-icon{font-size:48px;margin-bottom:12px}
.private-text{font-size:14px}

/* TOAST */
.toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:rgba(22,22,22,.97);color:var(--text);padding:9px 20px;border-radius:8px;font-size:12px;font-weight:500;z-index:9999;border:1px solid var(--border2);animation:ti .25s ease;pointer-events:none}
@keyframes ti{from{opacity:0;transform:translateX(-50%) translateY(8px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}
@media(max-width:480px){.stats-grid{grid-template-columns:repeat(2,1fr)}.profile-top{flex-wrap:wrap}.profile-actions{margin-left:0;width:100%}}
</style>
</head>
<body>

<div class="page-header">
    <a href="/" class="back-btn">← Назад</a>
    <div class="page-title"><?=$username?></div>
</div>

<div class="profile-banner">
    <?php if ($bannerUrl): ?><img src="<?=htmlspecialchars($bannerUrl)?>" class="banner-img" alt=""><?php endif; ?>
    <div class="banner-gradient"></div>
</div>

<div class="profile-wrap">
    <div class="profile-top">
        <div class="avatar-wrap">
            <div class="avatar-ring">
                <?php if ($avatarUrl): ?>
                    <img src="<?=htmlspecialchars($avatarUrl)?>" alt="">
                <?php else: ?>
                    <?=mb_strtoupper(mb_substr($username, 0, 1))?>
                <?php endif; ?>
            </div>
            <?php
            $onlineStatus = $profile['online']['status'];
            $dotColor = $onlineStatus === 'online' ? '#4ade80' : ($onlineStatus === 'recently' ? '#fb923c' : '#555');
            ?>
            <div class="online-dot-big" style="background:<?=$dotColor?>"></div>
        </div>

        <div class="profile-name-area">
            <div class="profile-username">
                <?=$username?>
                <?php if (!empty($profile['user']['is_verified'])): ?><span class="verified-badge" title="Верифицирован">✓</span><?php endif; ?>
                <?php if (!empty($profile['user']['is_admin'])): ?>
                    <span class="admin-badge"><?=htmlspecialchars($profile['user']['admin_tag'] ?? 'Админ')?></span>
                <?php endif; ?>
            </div>
            <div class="profile-level"><?=$frame['label']?> &nbsp;·&nbsp; Уровень <?=(int)$profile['xp']['level']?></div>
            <div class="profile-online"><?=htmlspecialchars($profile['online']['label'])?></div>
        </div>

        <div class="profile-actions">
            <?php if ($isSelf): ?>
                <a href="/settings" class="btn btn-outline">⚙️ Настройки</a>
            <?php elseif ($currentAccount): ?>
                <button class="btn btn-msg" onclick="openMessages(<?=$uid?>, '<?=$username?>')">💬 Написать</button>
                <?php
                $fsId     = $friendship ? (int)$friendship['id'] : 0;
                $fsStatus = $friendship['status'] ?? '';
                $fsIsMine = $friendship && (int)$friendship['requester_id'] === (int)$currentAccount['id'];
                ?>
                <?php if (!$friendship): ?>
                    <button class="btn btn-accent" id="friend-btn" onclick="friendRequest(<?=$uid?>)">+ В друзья</button>
                <?php elseif ($fsStatus === 'accepted'): ?>
                    <button class="btn btn-outline" id="friend-btn" onclick="friendAction(<?=$fsId?>,'remove')">👥 Удалить</button>
                <?php elseif ($fsStatus === 'pending' && $fsIsMine): ?>
                    <button class="btn btn-outline" id="friend-btn" style="color:var(--muted)" onclick="friendAction(<?=$fsId?>,'reject')">⏳ Отменить</button>
                <?php elseif ($fsStatus === 'pending' && !$fsIsMine): ?>
                    <button class="btn btn-green" id="friend-btn" onclick="friendAction(<?=$fsId?>,'accept')">✓ Принять</button>
                <?php endif; ?>
                <button class="btn <?=$isSubscribed?'btn-green':'btn-accent'?>" id="sub-btn" onclick="toggleSubscribe(<?=$uid?>)">
                    <?=$isSubscribed?'✓ Подписан':'+ Подписаться'?>
                </button>
            <?php else: ?>
                <a href="/login" class="btn btn-accent">Войти</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- XP BAR -->
    <div class="xp-bar-wrap">
        <div class="xp-bar-label">
            <span>⭐ <?=number_format((int)$profile['xp']['total'], 0, ',', ' ')?> XP</span>
            <span>Lv<?=(int)$profile['xp']['level']?> → Lv<?=(int)$xpProg['next_lvl']?> &nbsp; <?=(int)$xpProg['current']?> / <?=(int)$xpProg['needed']?> XP</span>
        </div>
        <div class="xp-bar">
            <div class="xp-bar-fill" style="width:<?=(int)$xpProg['pct']?>%"></div>
        </div>
    </div>

    <!-- BIO -->
    <?php if ($bio): ?>
    <div class="bio-box"><?=$bio?></div>
    <?php elseif ($isSelf): ?>
    <div class="bio-box empty">Добавь описание профиля в настройках</div>
    <?php endif; ?>

    <?php if ($canView): ?>

    <!-- STATS GRID -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?=number_format((int)($profile['user']['total_manga_read'] ?? $libStats['read'] ?? 0))?></div>
            <div class="stat-label">📚 Прочитано</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?=number_format((int)($profile['user']['total_chapters_read'] ?? 0))?></div>
            <div class="stat-label">📑 Глав</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?=number_format((int)($profile['user']['total_pages_read'] ?? 0))?></div>
            <div class="stat-label">📄 Страниц</div>
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
<?php exit; }