<?php
// Веб-панель: сессия, вход, защита форм, общее оформление.

function panel_start(): void
{
	// Защитные заголовки: панель нельзя встроить в чужой сайт, браузер не угадывает типы файлов
	header('X-Frame-Options: DENY');
	header("Content-Security-Policy: frame-ancestors 'none'");
	header('X-Content-Type-Options: nosniff');
	header('Referrer-Policy: same-origin');

	// Сессии — 30 дней, в своей закрытой папке (на общем хостинге стандартные живут ~24 мин).
	// У кабинета пользователей — своя папка, своё имя cookie и свой путь: вход в кабинет не открывает админку.
	$dir = data_dir() . (is_service() ? '/sessions-svc' : '/sessions');
	if (!is_dir($dir)) {
		mkdir($dir, 0700, true);
	}
	session_save_path($dir);
	ini_set('session.gc_maxlifetime', (string)(30 * 86400));
	ini_set('session.use_strict_mode', '1');
	if (is_service()) {
		session_name('TRSVC');
	}
	session_set_cookie_params([
		'lifetime' => 30 * 86400,
		'path' => cookie_path(),
		'httponly' => true,
		'samesite' => 'Lax',
		'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
	]);
	session_start();
	if (empty($_SESSION['csrf'])) {
		$_SESSION['csrf'] = bin2hex(random_bytes(16));
	}
	if (is_service() && !empty($_SESSION['uid'])) {
		// Пароль сменили, «выйти везде», блокировка или удаление — сессия больше не действует
		$u = get_user((int)$_SESSION['uid']);
		if (!$u || $u['status'] !== 'active' || (int)$u['sess_ver'] !== (int)($_SESSION['ver'] ?? 0)) {
			$_SESSION = ['csrf' => bin2hex(random_bytes(16))];
		} else {
			set_uid((int)$u['id']);
			$GLOBALS['USER'] = $u;
		}
	}
}

function panel_authed(): bool
{
	return is_service() ? cur_uid() > 0 : !empty($_SESSION['ok']);
}

// Страница только для режима «сервис» или только для админки
function only_mode(string $mode): void
{
	if (($mode === 'service') !== is_service()) {
		http_response_code(404);
		exit('Not found');
	}
}

function panel_require(): void
{
	if (!panel_authed()) {
		header('Location: index.php');
		exit;
	}
}

function csrf_field(): string
{
	return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf'] ?? '') . '">';
}

function csrf_ok(): bool
{
	return !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''));
}

function panel_header(string $title, bool $autoRefresh = false): void
{
	?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($autoRefresh): ?><meta http-equiv="refresh" content="30"><?php endif; ?>
<title><?= h($title) ?> — <?= is_service() ? h(svc()['name']) : 'роутеры' ?></title>
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<meta name="apple-mobile-web-app-title" content="Роутеры">
<meta name="theme-color" content="#1d2a3a">
<style>
	body { font: 14px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; margin: 0; background: #f4f5f7; color: #1d2125; }
	header { background: #1d2a3a; color: #fff; padding: 12px 20px; display: flex; justify-content: space-between; align-items: center; gap: 12px; }
	header a { color: #9cc3ff; text-decoration: none; }
	main { max-width: 1100px; margin: 0 auto; padding: 16px; }
	.card { background: #fff; border-radius: 8px; padding: 14px 16px; margin-bottom: 14px; box-shadow: 0 1px 2px rgba(0,0,0,.08); }
	h2 { font-size: 16px; margin: 0 0 10px; }
	table { border-collapse: collapse; width: 100%; }
	td, th { text-align: left; padding: 5px 8px; border-bottom: 1px solid #eceef1; vertical-align: top; }
	th { color: #626f86; font-weight: 600; font-size: 12px; }
	.muted { color: #626f86; font-size: 12px; }
	.notice { background: #fff4d6; padding: 10px 14px; border-radius: 6px; margin-bottom: 14px; }
	.notice.ok { background: #dcfff1; }
	.notice.err { background: #ffe2dd; }
	.msg { border-left: 3px solid #9cc3ff; padding: 4px 10px; margin: 6px 0; }
	code { background: #eceef1; padding: 0 4px; border-radius: 3px; }
	button, .btn { cursor: pointer; padding: 6px 12px; border: 1px solid #c1c7d0; background: #fff; border-radius: 4px; font: inherit; color: inherit; text-decoration: none; display: inline-block; }
	button.primary { background: #0c66e4; border-color: #0c66e4; color: #fff; font-weight: 600; }
	button.danger { color: #c9372c; }
	.login { max-width: 360px; margin: 60px auto; }
	input[type=email] { width: 100%; box-sizing: border-box; padding: 6px 8px; border: 1px solid #c1c7d0; border-radius: 4px; font: inherit; min-height: 34px; }
	footer { max-width: 1100px; margin: 0 auto; padding: 10px 16px 24px; color: #626f86; font-size: 12px; }
	footer a { color: #626f86; }
	.limitline { background: #f4f5f7; color: #44546f; border-left: 3px solid #b3b9c4; padding: 6px 10px; margin: 6px 0 10px; font-size: 13px; border-radius: 3px; }
	.warnline { background: #fff4d6; border-left: 3px solid #f5a623; padding: 3px 8px; margin: 4px 0; font-size: 13px; border-radius: 3px; }
	.badge { background: #c9372c; color: #fff; border-radius: 9px; padding: 0 6px; font-size: 11px; font-weight: 700; }
	.legal h3 { font-size: 15px; margin: 18px 0 6px; }
	.legal p, .legal li { line-height: 1.55; }
	.hp { position: absolute; left: -5000px; width: 1px; height: 1px; overflow: hidden; }
	input[type=text], input[type=password], select, textarea { width: 100%; box-sizing: border-box; padding: 6px 8px; border: 1px solid #c1c7d0; border-radius: 4px; font: inherit; }
	textarea { font-family: ui-monospace, Consolas, monospace; font-size: 13px; }
	label.field { display: block; margin: 8px 0 3px; font-weight: 600; font-size: 13px; }
	.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
	@media (max-width: 760px) { .grid { grid-template-columns: 1fr; } }
	.lists { display: flex; flex-wrap: wrap; gap: 4px 14px; }
	.lists label { white-space: nowrap; font-size: 13px; }
	.section { border: 1px solid #dfe1e6; border-radius: 8px; padding: 12px 14px; margin-bottom: 12px; background: #fff; }
	.section.deleted { opacity: .45; }
	.section-head { display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; }
	.section-head b { font-size: 15px; }
	.sticky { position: sticky; bottom: 0; background: #f4f5f7; padding: 10px 0; border-top: 1px solid #dfe1e6; }
	.item { display: block; padding: 8px 0; border-bottom: 1px solid #eceef1; }
	.item:last-child { border-bottom: 0; }
	a.router-link { color: inherit; text-decoration: none; padding: 10px 8px; margin: 0 -8px; border-radius: 6px; }
	a.router-link:hover { background: #f1f2f4; }
	.row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
	.row input[type=text] { width: auto; }
	pre.cmd { background: #1d2a3a; color: #e6edf5; padding: 10px; border-radius: 6px; white-space: pre-wrap; word-break: break-all; font-size: 12px; }
	details summary { cursor: pointer; }
	button, .btn, select, textarea, input:not([type=checkbox]):not([type=radio]) { min-height: 34px; }
	/* Галочки: квадратик и текст на одной линии, текст переносится рядом, а не под квадратиком */
	input[type=checkbox], input[type=radio] { width: auto; min-height: 0; margin: 0 6px 0 0; vertical-align: -2px; flex-shrink: 0; }
	label.check, label:has(> input[type=checkbox]) { display: inline-flex; align-items: flex-start; gap: 0; line-height: 1.4; }
	label.check > input, label:has(> input[type=checkbox]) > input[type=checkbox] { margin-top: 2px; }
	button:disabled { opacity: .5; cursor: not-allowed; }
	@media (max-width: 600px) {
		main { padding: 10px 8px; }
		.card { padding: 12px; }
		header { padding: 10px 12px; }
		.sticky .muted { display: block; margin-top: 6px; }
		.section { padding: 10px; }
	}
</style>
</head>
<body>
<header>
	<span><b>🛰 <?= is_service() ? h(svc()['name']) : 'Роутеры' ?></b><?php if (panel_authed()): ?> &nbsp; <a href="index.php">Обзор</a>
		<?php if (is_service()): ?> &nbsp; <a href="support.php">💬 Поддержка</a><?php else: $open = open_tickets_count(); ?> &nbsp; <a href="users.php">👥 Пользователи<?= $open ? ' <span class="badge" id="open-badge">' . $open . '</span>' : '<span class="badge" id="open-badge" style="display:none"></span>' ?></a><?php endif; ?>
		 &nbsp; <a href="settings.php">⚙️ Настройки</a><?php endif; ?></span>
	<?php if (panel_authed()): ?><span><?php if (is_service()): ?><span class="muted" style="color:#9fb0c4"><?= h(cur_user()['email'] ?? '') ?></span> &nbsp; <?php endif; ?><a href="index.php?logout=<?= h($_SESSION['csrf'] ?? '') ?>">выйти</a></span><?php endif; ?>
</header>
<main>
<?php
}

function panel_footer(): void
{
	echo "</main>\n";
	if (is_service()) {
		echo '<footer>© ' . date('Y') . ' ' . h(svc()['name']) . ' · <a href="legal.php?doc=terms">Пользовательское соглашение</a> · <a href="legal.php?doc=privacy">Политика конфиденциальности</a> · <a href="legal.php?doc=consent">Согласие на обработку данных</a></footer>' . "\n";
	}
	if (panel_authed()) {
		?>
<script>
(function () {
	// Блоки с data-poll обновляются сами раз в 15 секунд (переписка в поддержке), значок «ждут ответа» — тоже
	function poll() {
		if (document.hidden) return;
		document.querySelectorAll('[data-poll]').forEach(function (el) {
			fetch(el.getAttribute('data-poll'), { credentials: 'same-origin', cache: 'no-store' })
				.then(function (r) { return r.ok && !r.redirected ? r.text() : null; })
				.then(function (html) { if (html !== null && html !== el.innerHTML) { el.innerHTML = html; } })
				.catch(function () {});
		});
		var b = document.getElementById('open-badge');
		if (b) {
			fetch('users.php?badge=1', { credentials: 'same-origin', cache: 'no-store' })
				.then(function (r) { return r.ok ? r.json() : null; })
				.then(function (j) { if (j) { b.textContent = j.open; b.style.display = j.open ? '' : 'none'; } })
				.catch(function () {});
		}
	}
	// Счётчик символов у полей с пределом (<textarea data-max>): при превышении — красным и кнопка неактивна
	document.querySelectorAll('textarea[data-max]').forEach(function (ta) {
		var max = +ta.getAttribute('data-max'), c = document.createElement('div');
		c.className = 'muted'; c.style.cssText = 'font-size:12px;text-align:right;margin-top:2px';
		ta.insertAdjacentElement('afterend', c);
		var btns = ta.form ? ta.form.querySelectorAll('button.primary') : [];
		function upd() {
			var n = Array.from(ta.value.trim()).length, over = n > max;
			c.textContent = over ? 'Слишком длинно: ' + n + ' из ' + max + ' символов — сократите на ' + (n - max) : n + ' / ' + max;
			c.style.color = over ? '#c0392b' : '';
			c.style.fontWeight = over ? '600' : '';
			btns.forEach(function (b) { b.disabled = over; });
		}
		ta.addEventListener('input', upd); upd();
	});
	// Кнопка «Копировать» под командами и кодами (<pre data-copy>)
	document.querySelectorAll('pre[data-copy]').forEach(function (pre) {
		var btn = document.createElement('button');
		btn.type = 'button';
		btn.textContent = '📋 Копировать';
		btn.style.marginTop = '-6px';
		btn.addEventListener('click', function () {
			var text = pre.textContent.trim();
			var done = function () { btn.textContent = '✅ Скопировано'; setTimeout(function () { btn.textContent = '📋 Копировать'; }, 2000); };
			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(text).then(done, function () { fallback(); });
			} else {
				fallback();
			}
			function fallback() {
				var ta = document.createElement('textarea');
				ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
				document.body.appendChild(ta); ta.select();
				try { document.execCommand('copy'); done(); } catch (e) { btn.textContent = 'Выделите текст и скопируйте вручную'; }
				ta.remove();
			}
		});
		pre.insertAdjacentElement('afterend', btn);
	});
	setInterval(poll, 15000);
	document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
})();
</script>
<?php
	}
	echo "</body>\n</html>\n";
}
