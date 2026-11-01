<?php
// Веб-панель: обзор роутеров, подтверждение новых, уведомления, события.
// Настройка конкретного роутера — router.php.

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/panel.php';

panel_start();
$notice = '';

if (isset($_POST['password'])) {
	if (cfg('panel_password') && hash_equals((string)cfg('panel_password'), (string)$_POST['password'])) {
		session_regenerate_id(true);
		$_SESSION['ok'] = true;
		$_SESSION['csrf'] = bin2hex(random_bytes(16));
		header('Location: index.php');
		exit;
	}
	sleep(2);
	$notice = 'Неверный пароль';
}
if (isset($_GET['logout'])) {
	$_SESSION = [];
	session_destroy();
	header('Location: index.php');
	exit;
}

function fmt_time($ts): string
{
	return $ts ? date('d.m H:i', (int)$ts) : '—';
}

// Разметку уведомлений (<b>, <i>, <code>) показываем как есть, остальное экранируем
function tg_html(string $text): string
{
	return nl2br(preg_replace('#&lt;(/?)(b|i|code)&gt;#', '<$1$2>', h($text)));
}

if (!panel_authed()) {
	panel_header('Вход');
	?>
	<div class="card login">
		<h2>Панель роутеров</h2>
		<?php if ($notice): ?><div class="notice err"><?= h($notice) ?></div><?php endif; ?>
		<form method="post">
			<input type="password" name="password" placeholder="Пароль" autofocus>
			<p><button type="submit" class="primary" style="width:100%">Войти</button></p>
		</form>
	</div>
	<?php
	panel_footer();
	exit;
}

// ---------- Действия ----------

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
	$rid = (int)($_POST['rid'] ?? 0);
	$r = get_router($rid);
	if (!csrf_ok()) {
		$notice = 'Форма устарела — обновите страницу.';
	} elseif ($r && $r['status'] === 'pending' && $_POST['action'] === 'accept') {
		$name = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST['name'] ?? ''))), 0, 40);
		if ($name === '') {
			$notice = 'Укажите имя роутера.';
		} else {
			q("UPDATE routers SET name = ?, status = 'active' WHERE id = ?", [$name, $rid]);
			event($rid, 'Принят в систему как «' . $name . '»');
			$_SESSION['flash'] = 'Роутер «' . $name . '» принят. Полные данные появятся после его следующего выхода на связь (до 2 минут).';
			header('Location: index.php');
			exit;
		}
	} elseif ($_POST['action'] === 'update_all') {
		$ref = trim((string)($_POST['ref'] ?? ''));
		if (preg_match('/^[A-Za-z0-9._-]{1,40}$/', $ref)) {
			$ids = array_column(rows("SELECT id FROM routers WHERE status = 'active'"), 'id');
			foreach ($ids as $id) {
				queue_task((int)$id, 'update', ['ref' => $ref]);
			}
			$_SESSION['flash'] = 'Обновление скриптов до «' . $ref . '» отправлено на роутеров: ' . count($ids) . '. Каждый применит его при следующем выходе на связь (до 2 минут).';
		}
		header('Location: index.php');
		exit;
	} elseif ($_POST['action'] === 'podkop_all') {
		$n = 0;
		foreach (rows("SELECT * FROM routers WHERE status = 'active'") as $rr) {
			if (podkop_outdated((string)(rinfo($rr)['podkop'] ?? ''))) {
				queue_task((int)$rr['id'], 'update_podkop');
				$n++;
			}
		}
		$_SESSION['flash'] = $n ? "Обновление podkop отправлено на роутеров: $n (только где версия устарела). Займёт несколько минут; результат — в «Задачах» и уведомлениях."
			: 'Устаревшего podkop нет ни на одном роутере.';
		header('Location: index.php');
		exit;
	} elseif ($r && $r['status'] === 'pending' && $_POST['action'] === 'reject') {
		q("UPDATE routers SET status = 'rejected' WHERE id = ?", [$rid]);
		event($rid, 'Отклонён');
		$_SESSION['flash'] = 'Роутер отклонён.';
		header('Location: index.php');
		exit;
	}
}
if (!empty($_SESSION['flash'])) {
	$notice = $_SESSION['flash'];
	unset($_SESSION['flash']);
}

$pending = rows("SELECT * FROM routers WHERE status = 'pending' ORDER BY id");
$routers = rows("SELECT * FROM routers WHERE status = 'active' ORDER BY name COLLATE NOCASE");
$alerts = rows('SELECT * FROM outbox ORDER BY id DESC LIMIT 15');
$tasks = rows('SELECT t.*, r.name AS rname FROM tasks t LEFT JOIN routers r ON r.id = t.router_id ORDER BY t.id DESC LIMIT 15');
$events = rows('SELECT e.*, r.name AS rname FROM events e LEFT JOIN routers r ON r.id = e.router_id ORDER BY e.id DESC LIMIT 50');

$cnt = ['🟢' => 0, '🔴' => 0, '⚫' => 0];
foreach ($routers as $r) {
	$i = router_icon($r);
	$cnt[$i] = ($cnt[$i] ?? 0) + 1;
}

// Автообновление мешало бы вводить имя нового роутера
panel_header('Обзор', !$pending);
?>
	<?php if ($notice): ?><div class="notice"><?= h($notice) ?></div><?php endif; ?>

	<?php if ($pending): ?>
	<div class="card" style="border:2px solid #f5cd47">
		<h2>🆕 Ждут подтверждения (<?= count($pending) ?>)</h2>
		<?php foreach ($pending as $r): $info = rinfo($r); ?>
			<div class="item">
				<b><?= h($r['name']) ?></b> <span class="muted">#<?= (int)$r['id'] ?> · <?= ago($r['created_at']) ?></span><br>
				<span class="muted"><?= router_hw_line($info) ?><br><?= router_mac_line($info) ?> · IP <?= h($r['ip']) ?></span>
				<?php if ($same = find_same_router($info, (int)$r['id'])): ?>
					<div class="notice" style="margin:6px 0 0">⚠️ Это железо уже было в системе: <b><?= h($same['name']) ?></b> (#<?= (int)$same['id'] ?>). Похоже, агент переустановили.</div>
				<?php endif; ?>
				<form method="post" class="row" style="margin-top:8px">
					<?= csrf_field() ?>
					<input type="hidden" name="rid" value="<?= (int)$r['id'] ?>">
					<input type="text" name="name" placeholder="Имя, например «Офис Ленина»" value="" style="flex:1;min-width:160px">
					<button name="action" value="accept" class="primary">✅ Принять</button>
					<button name="action" value="reject" class="danger" onclick="return confirm('Отклонить этот роутер?')">Отклонить</button>
				</form>
			</div>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>

	<div class="card">
		<h2>Роутеры <span class="muted">🟢 <?= $cnt['🟢'] ?> · 🔴 <?= $cnt['🔴'] ?> · ⚫ <?= $cnt['⚫'] ?></span></h2>
		<?php if (!$routers): ?>
			<p class="muted">Пока пусто. Как подключить роутер — внизу страницы.</p>
		<?php endif; ?>
		<?php foreach ($routers as $r):
			$sections = router_sections((int)$r['id']);
			$byName = by_name($sections); ?>
			<a class="item router-link" href="router.php?id=<?= (int)$r['id'] ?>">
				<div class="row" style="justify-content:space-between">
					<b style="font-size:15px"><?= router_icon($r) ?> <?= h($r['name']) ?></b>
					<span class="muted">на связи <?= ago($r['last_seen']) ?></span>
				</div>
				<div class="muted"><?= router_hw_line(rinfo($r)) ?></div>
				<div style="margin-top:4px">
					<?php foreach ($sections as $s): ?><div><?= tg_html(section_line($s, $byName)) ?></div><?php endforeach; ?>
				</div>
			</a>
		<?php endforeach; ?>
	</div>

	<?php
	$outdated = 0;
	foreach ($routers as $r) {
		if (podkop_outdated((string)(rinfo($r)['podkop'] ?? ''))) {
			$outdated++;
		}
	}
	?>
	<?php if ($routers): ?>
	<div class="card">
		<h2>⬆️ Обновления на всех роутерах</h2>
		<form method="post" class="row" onsubmit="return confirm('Обновить скрипты (сторож и агент) на всех роутерах?')">
			<?= csrf_field() ?>
			<input type="text" name="ref" value="main" style="width:90px" title="Версия с GitHub: main или тег">
			<button name="action" value="update_all">⬆️ Обновить скрипты на всех</button>
		</form>
		<form method="post" class="row" style="margin-top:8px" onsubmit="return confirm('Обновить podkop на роутерах, где он устарел? Откатить обновление podkop нельзя — лучше сначала проверить на одном роутере.')">
			<?= csrf_field() ?>
			<button name="action" value="podkop_all" <?= $outdated ? '' : 'disabled' ?>>📦 Обновить podkop на всех</button>
			<span class="muted"><?= podkop_latest() ? 'последний podkop: ' . h(podkop_latest()) . ' · ' : '' ?>устарел на роутерах: <?= $outdated ?></span>
		</form>
		<p class="muted" style="margin-bottom:0">После выпуска новой версии скриптов подождите ~5 минут: GitHub не сразу отдаёт свежие файлы.</p>
	</div>
	<?php endif; ?>

	<div class="card">
		<h2>🔔 Уведомления</h2>
		<?php if (!$alerts): ?><p class="muted">Пока тихо.</p><?php endif; ?>
		<?php foreach ($alerts as $m): ?>
			<div class="msg"><span class="muted"><?= fmt_time($m['ts']) ?></span><br><?= tg_html($m['text']) ?></div>
		<?php endforeach; ?>
	</div>

	<div class="card">
		<h2>Задачи для роутеров</h2>
		<?php if (!$tasks): ?><p class="muted">Задач ещё не было.</p><?php endif; ?>
		<?php foreach ($tasks as $t):
			$st = ['queued' => '⏳ в очереди', 'sent' => '📨 у роутера', 'done' => '✅ готово', 'failed' => '❌ ошибка'][$t['status']] ?? $t['status']; ?>
			<div class="item">
				<span class="muted"><?= fmt_time($t['created_at']) ?> · <?= h($t['rname']) ?></span> &nbsp; <?= $st ?><br>
				<?= h(task_label($t['type'], json_decode($t['payload'], true) ?: [])) ?>
				<?php if ($t['result']): ?><div class="muted"><?= h($t['result']) ?></div><?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>

	<details class="card">
		<summary><h2 style="display:inline">➕ Как подключить роутер</h2></summary>
		<p>Нужно: роутер с OpenWrt и интернетом. podkop ставить заранее не обязательно — установщик поставит его сам и сразу выставит DNS через DoH 1.1.1.1.</p>
		<p><b>1.</b> Зайти на роутер по SSH: в PowerShell <code>ssh root@192.168.1.1</code> (адрес роутера может отличаться).</p>
		<p><b>2.</b> Вставить одну команду и нажать Enter:</p>
		<pre class="cmd"><?= h(install_command()) ?></pre>
		<p><b>3.</b> Через 1–3 минуты роутер появится вверху этой страницы в «Ждут подтверждения» — дайте ему имя и примите.</p>
		<p class="muted">Команда содержит адрес вашего сервера — его нет ни в скриптах, ни на GitHub. Передавайте её только тем, кому доверяете.
			Если podkop ставить не нужно — добавьте в конец <code>--no-podkop</code>.</p>
		<p><b>Обновить podkop</b> на уже подключённом роутере — та же команда с <code>--update-podkop</code> в конце
			(настройки podkop и регистрация роутера сохраняются). Если podkop устарел, в списке роутеров рядом с его версией написано «есть vX».</p>
	</details>

	<details class="card">
		<summary><h2 style="display:inline">События</h2></summary>
		<?php foreach ($events as $e): ?>
			<div class="item"><span class="muted"><?= fmt_time($e['ts']) ?> · <?= h($e['rname'] ?? '') ?></span><br><?= h($e['text']) ?></div>
		<?php endforeach; ?>
	</details>
<?php
panel_footer();
