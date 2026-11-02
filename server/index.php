<?php
// Веб-панель: обзор роутеров, подтверждение новых, уведомления, события.
// Настройка конкретного роутера — router.php, настройки панели — settings.php.

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/panel.php';

panel_start();
$notice = '';

// ---------- Вход: пароль → (если нужно) код двухфакторки ----------

if (!panel_authed() && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (isset($_POST['password']) || isset($_POST['code']))) {
	if ($left = login_locked()) {
		$notice = 'Слишком много неудачных попыток. Попробуйте через ' . ceil($left / 60) . ' мин.';
	} elseif (isset($_POST['password'])) {
		if (cfg('panel_password') && hash_equals((string)cfg('panel_password'), (string)$_POST['password'])) {
			$dev = device_id();
			if (totp_settings()['enabled'] && !device_trusted($dev)) {
				$_SESSION['pw_ok'] = time();
			} else {
				complete_login(false);
				header('Location: index.php');
				exit;
			}
		} else {
			sleep(1);
			login_failed('неверный пароль');
			$notice = 'Неверный пароль';
		}
	} elseif (isset($_POST['code']) && !empty($_SESSION['pw_ok']) && time() - (int)$_SESSION['pw_ok'] < 300) {
		if (totp_check((string)$_POST['code'])) {
			complete_login(true);
			header('Location: index.php');
			exit;
		}
		sleep(1);
		login_failed('неверный код двухфакторки');
		$notice = 'Неверный код';
	} else {
		unset($_SESSION['pw_ok']);
		$notice = 'Время на ввод кода вышло — введите пароль заново.';
	}
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
	$step2 = !empty($_SESSION['pw_ok']) && time() - (int)$_SESSION['pw_ok'] < 300;
	panel_header('Вход');
	?>
	<div class="card login">
		<h2>Панель роутеров</h2>
		<?php if ($notice): ?><div class="notice err"><?= h($notice) ?></div><?php endif; ?>
		<?php if ($step2): ?>
			<form method="post">
				<p>Введите код из Google Authenticator (или резервный код):</p>
				<input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" autofocus style="font-size:20px;letter-spacing:4px;text-align:center">
				<p><button type="submit" class="primary" style="width:100%">Подтвердить</button></p>
				<p class="muted">На этом устройстве код не будет спрашиваться <?= TRUST_DAYS ?> дней.</p>
			</form>
		<?php else: ?>
			<form method="post">
				<input type="password" name="password" placeholder="Пароль" autofocus>
				<p><button type="submit" class="primary" style="width:100%">Войти</button></p>
			</form>
		<?php endif; ?>
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
			audit('принят новый роутер «' . $name . '» (#' . $rid . ', IP ' . $r['ip'] . ')');
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
			audit('обновление скриптов до «' . $ref . '» на всех роутерах (' . count($ids) . ')');
			$_SESSION['flash'] = 'Обновление скриптов до «' . $ref . '» отправлено на роутеров: ' . count($ids) . '. Каждый применит его при следующем выходе на связь (до 2 минут).';
		}
		header('Location: index.php');
		exit;
	} elseif ($_POST['action'] === 'invite') {
		$_SESSION['invite'] = ['code' => invite_create(), 'ts' => time()];
		audit('создан код приглашения для нового роутера (действует ' . (INVITE_TTL / 3600) . ' ч, один раз)');
		header('Location: index.php#install');
		exit;
	} elseif ($_POST['action'] === 'podkop_all') {
		$n = 0;
		foreach (rows("SELECT * FROM routers WHERE status = 'active'") as $rr) {
			if (podkop_outdated((string)(rinfo($rr)['podkop'] ?? ''))) {
				queue_task((int)$rr['id'], 'update_podkop');
				$n++;
			}
		}
		if ($n) {
			audit("обновление podkop на роутерах: $n");
		}
		$_SESSION['flash'] = $n ? "Обновление podkop отправлено на роутеров: $n (только где версия устарела). Займёт несколько минут; результат — в «Задачах» и уведомлениях."
			: 'Устаревшего podkop нет ни на одном роутере.';
		header('Location: index.php');
		exit;
	} elseif ($r && $r['status'] === 'pending' && $_POST['action'] === 'reject') {
		q("UPDATE routers SET status = 'rejected' WHERE id = ?", [$rid]);
		event($rid, 'Отклонён');
		audit('отклонён роутер «' . $r['name'] . '» (#' . $rid . ')');
		$_SESSION['flash'] = 'Роутер отклонён.';
		header('Location: index.php');
		exit;
	}
}
if (!empty($_SESSION['flash'])) {
	$notice = $_SESSION['flash'];
	unset($_SESSION['flash']);
}


// ---------- Блоки страницы ----------
// Каждый блок рендерится отдельно: при первой загрузке — в странице, потом — по запросу ?partial=1,
// и скрипт на странице заменяет только изменившиеся блоки (без перезагрузки и «дёрганья»).

function capture(callable $fn): string
{
	ob_start();
	$fn();
	return (string)ob_get_clean();
}

function block_pending(array $pending): string
{
	return capture(function () use ($pending) {
		if (!$pending) {
			return;
		}
		?>
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
		<?php
	});
}

function block_routers(array $routers): string
{
	return capture(function () use ($routers) {
		$cnt = ['🟢' => 0, '🔴' => 0, '⚫' => 0];
		foreach ($routers as $r) {
			$i = router_icon($r);
			$cnt[$i] = ($cnt[$i] ?? 0) + 1;
		}
		?>
		<div class="card">
			<h2>Роутеры <span class="muted">🟢 всё работает: <?= $cnt['🟢'] ?> · 🔴 на связи, но есть проблема: <?= $cnt['🔴'] ?> · ⚫ не на связи: <?= $cnt['⚫'] ?></span></h2>
			<?php if (!$routers): ?>
				<p class="muted">Пока пусто. Как подключить роутер — внизу страницы.</p>
			<?php endif; ?>
			<?php foreach ($routers as $r):
				$sections = router_sections((int)$r['id']);
				$byName = by_name($sections);
				$failed = array_map(fn($s) => $s['name'], array_filter($sections, 'section_failed'));
				if (!is_online($r)) {
					$status = '<span style="color:#626f86;font-weight:600">⚫ не на связи · последний раз ' . ago($r['last_seen']) . '</span>';
				} elseif ($failed) {
					$status = '<span style="color:#c9372c;font-weight:600">на связи · не работает: ' . h(implode(', ', $failed)) . '</span>';
				} else {
					$status = '<span style="color:#22a06b;font-weight:600">на связи · всё работает</span>';
				}
				if (is_online($r)) {
					$status .= ' <span class="muted">· запрос ' . ago($r['last_seen']) . '</span>';
				}
				?>
				<a class="item router-link" href="router.php?id=<?= (int)$r['id'] ?>">
					<div class="row" style="justify-content:space-between">
						<b style="font-size:15px"><?= router_icon($r) ?> <?= h($r['name']) ?></b>
						<span><?= $status ?></span>
					</div>
					<div class="muted"><?= router_hw_line(rinfo($r)) ?></div>
					<div style="margin-top:4px">
						<?php foreach ($sections as $s): ?><div><?= tg_html(section_line($s, $byName)) ?></div><?php endforeach; ?>
					</div>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
	});
}

function block_updates(array $routers): string
{
	return capture(function () use ($routers) {
		if (!$routers) {
			return;
		}
		$outdated = 0;
		foreach ($routers as $r) {
			if (podkop_outdated((string)(rinfo($r)['podkop'] ?? ''))) {
				$outdated++;
			}
		}
		?>
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
		<?php
	});
}

function block_alerts(): string
{
	return capture(function () {
		$alerts = rows('SELECT * FROM outbox ORDER BY id DESC LIMIT 15');
		?>
		<div class="card">
			<h2>🔔 Уведомления</h2>
			<?php if (!$alerts): ?><p class="muted">Пока тихо.</p><?php endif; ?>
			<?php foreach ($alerts as $m): ?>
				<div class="msg"><span class="muted"><?= fmt_time($m['ts']) ?></span><br><?= tg_html($m['text']) ?></div>
			<?php endforeach; ?>
		</div>
		<?php
	});
}

function block_tasks(): string
{
	return capture(function () {
		$tasks = rows('SELECT t.*, r.name AS rname FROM tasks t LEFT JOIN routers r ON r.id = t.router_id ORDER BY t.id DESC LIMIT 15');
		?>
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
		<?php
	});
}

function block_events(): string
{
	return capture(function () {
		foreach (rows('SELECT e.*, r.name AS rname FROM events e LEFT JOIN routers r ON r.id = e.router_id ORDER BY e.id DESC LIMIT 50') as $e) {
			?><div class="item"><span class="muted"><?= fmt_time($e['ts']) ?> · <?= h($e['rname'] ?? '') ?></span><br><?= h($e['text']) ?></div><?php
		}
	});
}

function all_blocks(): array
{
	$pending = rows("SELECT * FROM routers WHERE status = 'pending' ORDER BY id");
	$routers = rows("SELECT * FROM routers WHERE status = 'active' ORDER BY name COLLATE NOCASE");
	return [
		'pending' => block_pending($pending),
		'routers' => block_routers($routers),
		'updates' => block_updates($routers),
		'alerts' => block_alerts(),
		'tasks' => block_tasks(),
		'events' => block_events(),
	];
}

// Тихое обновление данных: только блоки, в формате JSON
if (isset($_GET['partial'])) {
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	echo json_encode(all_blocks(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

$blocks = all_blocks();
panel_header('Обзор');
?>
	<?php if ($notice): ?><div class="notice"><?= h($notice) ?></div><?php endif; ?>

	<div id="b-pending"><?= $blocks['pending'] ?></div>
	<div id="b-routers"><?= $blocks['routers'] ?></div>
	<div id="b-updates"><?= $blocks['updates'] ?></div>
	<div id="b-alerts"><?= $blocks['alerts'] ?></div>
	<div id="b-tasks"><?= $blocks['tasks'] ?></div>

	<?php $inv = $_SESSION['invite'] ?? null; $invFresh = $inv && time() - $inv['ts'] < INVITE_TTL; ?>
	<details class="card" id="install" <?= $invFresh && time() - $inv['ts'] < 600 ? 'open' : '' ?>>
		<summary><h2 style="display:inline">➕ Как подключить роутер</h2></summary>
		<p>Нужно: роутер с OpenWrt и интернетом. podkop ставить заранее не обязательно — установщик поставит его сам и сразу выставит DNS через DoH 1.1.1.1.</p>
		<p><b>1.</b> Зайти на роутер по SSH: в PowerShell <code>ssh root@192.168.1.1</code> (адрес роутера может отличаться).</p>
		<p><b>2.</b> Вставить одну команду и нажать Enter. В команде — <b>одноразовый код приглашения</b>: без него сервер не примет новый роутер. Код действует <?= INVITE_TTL / 3600 ?> ч и подходит для одного роутера.</p>
		<?php if ($invFresh): ?>
			<pre class="cmd"><?= h(install_command($inv['code'])) ?></pre>
			<p class="muted">Код создан <?= ago($inv['ts']) ?>. Для следующего роутера получите новый.</p>
		<?php endif; ?>
		<form method="post"><?= csrf_field() ?><button name="action" value="invite"><?= $invFresh ? '🔄 Новый код приглашения' : '🎟 Получить команду с кодом приглашения' ?></button></form>
		<p><b>3.</b> Через 1–3 минуты роутер появится вверху этой страницы в «Ждут подтверждения» — дайте ему имя и примите.</p>
		<p class="muted">Команда содержит адрес вашего сервера — его нет ни в скриптах, ни на GitHub. Передавайте её только тем, кому доверяете.
			Если podkop ставить не нужно — добавьте в конец <code>--no-podkop</code>.</p>
		<p><b>Обновить podkop</b> на уже подключённом роутере — та же команда с <code>--update-podkop</code> в конце
			(настройки podkop и регистрация роутера сохраняются). Если podkop устарел, в списке роутеров рядом с его версией написано «есть vX».</p>
	</details>

	<details class="card">
		<summary><h2 style="display:inline">События</h2></summary>
		<div id="b-events"><?= $blocks['events'] ?></div>
	</details>

	<p class="muted" id="live-note" style="text-align:center">Данные обновляются сами, раз в 20 секунд.</p>

	<script>
	(function () {
		// Последний полученный с сервера HTML каждого блока — сравниваем с ним, а не с DOM
		const last = <?= json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;

		async function refresh() {
			if (document.hidden) {
				return;
			}
			let data;
			try {
				const r = await fetch('index.php?partial=1', { credentials: 'same-origin', cache: 'no-store' });
				if (r.redirected || !r.ok || !(r.headers.get('content-type') || '').includes('json')) {
					// Сессия истекла — пусть пользователь увидит страницу входа
					if (r.redirected || r.status === 200) {
						location.reload();
					}
					return;
				}
				data = await r.json();
			} catch (e) {
				return; // нет связи — попробуем в следующий раз
			}
			for (const [key, html] of Object.entries(data)) {
				const el = document.getElementById('b-' + key);
				if (!el) {
					continue;
				}
				if (html === last[key]) {
					continue;
				}
				// Не мешаем, если в этом блоке что-то вводят
				const a = document.activeElement;
				if (a && el.contains(a) && (a.tagName === 'INPUT' || a.tagName === 'TEXTAREA' || a.tagName === 'SELECT')) {
					continue;
				}
				el.innerHTML = html;
				last[key] = html;
			}
		}
		setInterval(refresh, 20000);
		document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
	})();
	</script>
<?php
panel_footer();
