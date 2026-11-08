<?php
// Админка: пользователи сервиса, их подписки, роутеры, обращения; настройки сервиса.
// Уведомлений от пользователей администратору не приходит — всё здесь.

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/panel.php';
require __DIR__ . '/inc/legal.php';

only_mode('admin');
panel_start();
panel_require();

$msg = null;
$id = (int)($_GET['id'] ?? 0);
$tid = (int)($_GET['t'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
	$act = (string)($_POST['action'] ?? '');
	$u = get_user((int)($_POST['uid'] ?? 0));
	if (!csrf_ok()) {
		$msg = ['err', 'Форма устарела — обновите страницу.'];
	} elseif ($act === 'svc_save') {
		$s = svc();
		foreach (['price', 'name', 'op_name', 'op_inn', 'op_ogrn', 'op_email', 'op_address', 'docs_date'] as $k) {
			$s[$k] = mb_substr(trim((string)($_POST[$k] ?? '')), 0, 300);
		}
		$s['pay_text'] = mb_substr(trim((string)($_POST['pay_text'] ?? '')), 0, 2000);
		$s['registration'] = ($_POST['registration'] ?? '') === 'open' ? 'open' : 'closed';
		$s['trial_days'] = max(0, min(365, (int)($_POST['trial_days'] ?? 14)));
		$s['router_limit'] = max(1, min(100, (int)($_POST['router_limit'] ?? 3)));
		$s['grace_days'] = max(0, min(60, (int)($_POST['grace_days'] ?? 7)));
		if ($s['name'] === '') {
			$s['name'] = 'Tim Routers';
		}
		if ($s['docs_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s['docs_date'])) {
			$s['docs_date'] = '';
		}
		kv_set('svc', $s);
		audit('изменены настройки сервиса (регистрация: ' . $s['registration'] . ', пробный период ' . $s['trial_days'] . ' дн., лимит ' . $s['router_limit'] . ')');
		$msg = ['ok', 'Настройки сервиса сохранены.'];
	} elseif ($u) {
		$uid = (int)$u['id'];
		switch ($act) {
			case 'free':
				q('UPDATE users SET free = ? WHERE id = ?', [$u['free'] ? 0 : 1, $uid]);
				audit(($u['free'] ? 'снят бесплатный доступ: ' : 'выдан бесплатный доступ «для своих»: ') . $u['email']);
				$msg = ['ok', $u['free'] ? 'Бесплатный доступ снят.' : 'Теперь у пользователя бесплатный доступ без срока.'];
				break;
			case 'extend':
				$days = max(1, min(3650, (int)($_POST['days'] ?? 30)));
				$from = max(time(), (int)$u['paid_until']);
				q('UPDATE users SET paid_until = ?, paid_ever = 1 WHERE id = ?', [$from + $days * 86400, $uid]);
				audit("подписка {$u['email']} продлена на $days дн. (до " . date('d.m.Y', $from + $days * 86400) . ')');
				notify_user($uid, '💳 Подписка продлена до <b>' . date('d.m.Y', $from + $days * 86400) . '</b>. Спасибо!');
				$msg = ['ok', 'Подписка продлена до ' . date('d.m.Y', $from + $days * 86400) . '.'];
				break;
			case 'until':
				$t = strtotime((string)($_POST['date'] ?? '') . ' 23:59:59');
				if ($t) {
					q('UPDATE users SET paid_until = ? WHERE id = ?', [$t, $uid]);
					audit("подписка {$u['email']}: дата окончания " . date('d.m.Y', $t));
					$msg = ['ok', 'Дата окончания подписки: ' . date('d.m.Y', $t) . '.'];
				}
				break;
			case 'limit':
				$lim = max(0, min(100, (int)($_POST['limit'] ?? 0)));
				q('UPDATE users SET router_limit = ? WHERE id = ?', [$lim, $uid]);
				audit("лимит роутеров {$u['email']}: " . ($lim ?: 'по умолчанию'));
				$msg = ['ok', 'Лимит роутеров: ' . ($lim ?: 'по умолчанию (' . svc()['router_limit'] . ')') . '.'];
				break;
			case 'verify':
				q('UPDATE users SET verified_at = COALESCE(verified_at, ?) WHERE id = ?', [time(), $uid]);
				audit("почта {$u['email']} подтверждена вручную");
				$msg = ['ok', 'Почта отмечена как подтверждённая.'];
				break;
			case 'block':
				$new = $u['status'] === 'blocked' ? 'active' : 'blocked';
				q('UPDATE users SET status = ?, sess_ver = sess_ver + 1 WHERE id = ?', [$new, $uid]);
				audit(($new === 'blocked' ? 'заблокирован' : 'разблокирован') . ' кабинет ' . $u['email']);
				$msg = ['ok', $new === 'blocked' ? 'Кабинет заблокирован, входы завершены. Роутеры продолжают работать.' : 'Кабинет разблокирован.'];
				break;
			case 'note':
				q('UPDATE users SET admin_note = ? WHERE id = ?', [mb_substr(trim((string)($_POST['note'] ?? '')), 0, 2000), $uid]);
				$msg = ['ok', 'Заметка сохранена.'];
				break;
			case 'delete':
				if (trim((string)($_POST['confirm'] ?? '')) !== $u['email']) {
					$msg = ['err', 'Для удаления впишите почту пользователя.'];
					break;
				}
				audit('удалён кабинет ' . $u['email'] . ' со всеми данными');
				delete_user($uid);
				$_SESSION['flash_u'] = 'Кабинет ' . $u['email'] . ' удалён.';
				header('Location: users.php');
				exit;
			case 'reply':
				$t = row('SELECT * FROM tickets WHERE id = ? AND user_id = ?', [(int)($_POST['tid'] ?? 0), $uid]);
				$text = trim((string)($_POST['text'] ?? ''));
				if ($t && $text !== '') {
					q('INSERT INTO ticket_msgs (ticket_id, from_admin, ts, text) VALUES (?, 1, ?, ?)', [$t['id'], time(), mb_substr($text, 0, 5000)]);
					q("UPDATE tickets SET status = ?, updated_at = ? WHERE id = ?", [!empty($_POST['close']) ? 'closed' : 'answered', time(), $t['id']]);
					notify_user($uid, '💬 <b>Ответ поддержки</b> по обращению «' . h($t['subject']) . "»:\n" . h(mb_substr($text, 0, 1500)), null);
					header('Location: users.php?id=' . $uid . '&t=' . $t['id']);
					exit;
				}
				break;
			case 'close':
				q("UPDATE tickets SET status = 'closed', updated_at = ? WHERE id = ? AND user_id = ?", [time(), (int)($_POST['tid'] ?? 0), $uid]);
				break;
		}
		if (!$msg) {
			header('Location: users.php?id=' . $uid);
			exit;
		}
		$id = $uid;
	}
}
if (!empty($_SESSION['flash_u'])) {
	$msg = ['ok', $_SESSION['flash_u']];
	unset($_SESSION['flash_u']);
}

function plan_badge(array $u): string
{
	$p = user_plan($u);
	$c = $p['free'] ? '#0c66e4' : ($p['active'] ? '#22a06b' : '#c9372c');
	return '<span style="color:' . $c . ';font-weight:600">' . h($p['label']) . '</span>';
}

function routers_summary(int $uid): string
{
	$rs = rows("SELECT * FROM routers WHERE user_id = ? AND status = 'active'", [$uid]);
	$on = count(array_filter($rs, 'is_online'));
	$bad = 0;
	foreach ($rs as $r) {
		if (is_online($r) && array_filter(router_sections((int)$r['id']), 'section_failed')) {
			$bad++;
		}
	}
	return count($rs) . ' (🟢 ' . ($on - $bad) . ($bad ? ' · 🔴 ' . $bad : '') . (count($rs) - $on ? ' · ⚫ ' . (count($rs) - $on) : '') . ')';
}

$statusRu = ['open' => '⏳ ждёт ответа', 'answered' => '💬 отвечено', 'closed' => '✅ закрыто'];
panel_header('Пользователи');
?>
	<?php if ($msg): ?><div class="notice <?= h($msg[0]) ?>"><?= h($msg[1]) ?></div><?php endif; ?>

<?php if ($id && ($u = get_user($id))): $plan = user_plan($u); ?>
	<p><a href="users.php">← Все пользователи</a></p>
	<h2 style="font-size:20px;margin:4px 0 2px"><?= h($u['email']) ?> <span class="muted">#<?= (int)$u['id'] ?></span></h2>
	<p class="muted" style="margin-top:0">
		создан <?= date('d.m.Y H:i', (int)$u['created_at']) ?> (IP <?= h($u['reg_ip']) ?>) ·
		почта <?= $u['verified_at'] ? 'подтверждена' : '<b>не подтверждена</b>' ?> ·
		вход <?= $u['last_login'] ? ago($u['last_login']) . ' (IP ' . h($u['last_ip']) . ')' : 'не было' ?> ·
		2FA <?= (kv_get(okey('totp', (int)$u['id']))['enabled'] ?? false) ? 'включена' : 'нет' ?> ·
		ВК <?= vk_link((int)$u['id'])['id'] ? 'подключён' : 'нет' ?>
		<?= $u['status'] === 'blocked' ? ' · <b style="color:#c9372c">ЗАБЛОКИРОВАН</b>' : '' ?>
	</p>

	<?php if ($tid && ($t = row('SELECT * FROM tickets WHERE id = ? AND user_id = ?', [$tid, $id]))): ?>
		<div class="card" style="border:2px solid #9cc3ff">
			<h2>💬 <?= h($t['subject']) ?> <span class="muted"><?= $statusRu[$t['status']] ?? '' ?><?= $t['router_id'] ? ' · роутер #' . (int)$t['router_id'] : '' ?></span></h2>
			<?php foreach (rows('SELECT * FROM ticket_msgs WHERE ticket_id = ? ORDER BY id', [$t['id']]) as $m): ?>
				<div class="msg" style="<?= $m['from_admin'] ? 'border-color:#22a06b' : '' ?>"><span class="muted"><?= $m['from_admin'] ? 'Вы' : 'Пользователь' ?> · <?= date('d.m.Y H:i', (int)$m['ts']) ?></span><br><?= nl2br(h($m['text'])) ?></div>
			<?php endforeach; ?>
			<form method="post" style="margin-top:8px">
				<?= csrf_field() ?><input type="hidden" name="uid" value="<?= $id ?>"><input type="hidden" name="tid" value="<?= (int)$t['id'] ?>">
				<textarea name="text" rows="4" placeholder="Ответ пользователю (придёт ему в кабинет и во ВКонтакте, если подключён)" style="font-family:inherit"></textarea>
				<p class="row"><button name="action" value="reply" class="primary">Ответить</button>
					<label><input type="checkbox" name="close" value="1"> и закрыть обращение</label>
					<button name="action" value="close">Закрыть без ответа</button></p>
			</form>
		</div>
	<?php endif; ?>

	<div class="grid">
		<div class="card">
			<h2>💳 Подписка</h2>
			<p>Сейчас: <?= plan_badge($u) ?><br><span class="muted">Роутеров: <?= user_router_count($id) ?> из <?= user_router_limit($u) ?><?= $u['router_limit'] ? '' : ' (по умолчанию)' ?></span></p>
			<form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="uid" value="<?= $id ?>">
				<button name="action" value="free"><?= $u['free'] ? 'Снять «бесплатно»' : '🎁 Бесплатно для своих (без срока)' ?></button></form>
			<form method="post" class="row" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="uid" value="<?= $id ?>">
				<select name="days" style="width:auto"><option value="30">+30 дней</option><option value="90">+90 дней</option><option value="365">+1 год</option></select>
				<button name="action" value="extend">Продлить (оплата получена)</button></form>
			<form method="post" class="row" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="uid" value="<?= $id ?>">
				<input type="date" name="date" value="<?= $u['paid_until'] ? date('Y-m-d', (int)$u['paid_until']) : '' ?>">
				<button name="action" value="until">Задать дату окончания</button></form>
			<form method="post" class="row" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="uid" value="<?= $id ?>">
				<input type="text" name="limit" value="<?= (int)$u['router_limit'] ?: '' ?>" placeholder="<?= (int)svc()['router_limit'] ?>" style="width:70px">
				<button name="action" value="limit">Лимит роутеров</button> <span class="muted">пусто — по умолчанию</span></form>
		</div>
		<div class="card">
			<h2>🛟 Доступ и управление</h2>
			<p>Доступ поддержки: <?= (int)$u['support_until'] > time() ? '<b style="color:#22a06b">открыт до ' . date('d.m H:i', (int)$u['support_until']) . '</b> — можно менять настройки и запрашивать отчёты' : '<b>закрыт</b> — только просмотр. Попросите пользователя открыть его в «Настройках» кабинета.' ?></p>
			<div class="row">
				<?php if (!$u['verified_at']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="uid" value="<?= $id ?>"><button name="action" value="verify">Подтвердить почту вручную</button></form><?php endif; ?>
				<form method="post" onsubmit="return confirm('<?= $u['status'] === 'blocked' ? 'Разблокировать?' : 'Заблокировать кабинет? Пользователь не сможет войти.' ?>')"><?= csrf_field() ?><input type="hidden" name="uid" value="<?= $id ?>"><button name="action" value="block" class="danger"><?= $u['status'] === 'blocked' ? 'Разблокировать' : '⛔ Заблокировать' ?></button></form>
			</div>
			<form method="post" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="uid" value="<?= $id ?>">
				<label class="field">Заметка (видите только вы)</label><textarea name="note" rows="2" style="font-family:inherit"><?= h($u['admin_note']) ?></textarea>
				<button name="action" value="note">Сохранить заметку</button></form>
			<details style="margin-top:8px"><summary class="muted">Удалить кабинет со всеми данными</summary>
				<form method="post" class="row" style="margin-top:6px"><?= csrf_field() ?><input type="hidden" name="uid" value="<?= $id ?>">
					<input type="text" name="confirm" placeholder="впишите почту пользователя" style="flex:1"><button name="action" value="delete" class="danger">Удалить</button></form></details>
		</div>
	</div>

	<div class="card">
		<h2>📟 Роутеры</h2>
		<?php $rs = rows("SELECT * FROM routers WHERE user_id = ? AND status IN ('active', 'pending') ORDER BY name COLLATE NOCASE", [$id]); ?>
		<?php if (!$rs): ?><p class="muted">Роутеров нет.</p><?php endif; ?>
		<?php foreach ($rs as $r): $sections = router_sections((int)$r['id']); $byName = by_name($sections); ?>
			<a class="item router-link" href="<?= $r['status'] === 'active' ? 'router.php?id=' . (int)$r['id'] : '#' ?>">
				<b><?= router_icon($r) ?> <?= h($r['name']) ?></b> <span class="muted"><?= $r['status'] === 'pending' ? '· ждёт подтверждения пользователем' : '· на связи ' . ago($r['last_seen']) ?></span>
				<div class="muted"><?= router_hw_line(rinfo($r)) ?></div>
				<?php foreach ($sections as $s): ?><div><?= section_line($s, $byName) ?></div><?php endforeach; ?>
			</a>
		<?php endforeach; ?>
	</div>

	<div class="card">
		<h2>💬 Обращения</h2>
		<?php $ts = rows('SELECT * FROM tickets WHERE user_id = ? ORDER BY updated_at DESC LIMIT 30', [$id]); ?>
		<?php if (!$ts): ?><p class="muted">Обращений не было.</p><?php endif; ?>
		<?php foreach ($ts as $t): ?>
			<a class="item router-link" href="users.php?id=<?= $id ?>&t=<?= (int)$t['id'] ?>"><b><?= h($t['subject']) ?></b> <span class="muted">· <?= $statusRu[$t['status']] ?? '' ?> · <?= date('d.m.Y H:i', (int)$t['updated_at']) ?></span></a>
		<?php endforeach; ?>
	</div>

	<details class="card">
		<summary><h2 style="display:inline">📜 Журнал пользователя</h2> <span class="muted">последние 100 событий</span></summary>
		<?php foreach (rows('SELECT e.*, r.name AS rname FROM events e LEFT JOIN routers r ON r.id = e.router_id WHERE e.user_id = ? ORDER BY e.id DESC LIMIT 100', [$id]) as $e): ?>
			<div class="item"><span class="muted"><?= date('d.m H:i', (int)$e['ts']) ?> · <?= h($e['rname'] ?? '') ?></span><br><?= h($e['text']) ?></div>
		<?php endforeach; ?>
	</details>

<?php else: $svc = svc(); ?>
	<h2 style="font-size:20px;margin:4px 0 12px">👥 Пользователи сервиса</h2>
	<?php if (!legal_ready()): ?>
		<div class="notice err">⚠️ Не заполнены реквизиты оператора (ФИО/ИП, ИНН, почта) — без них соглашение и политика конфиденциальности неполные. Заполните их ниже, прежде чем открывать регистрацию для всех.</div>
	<?php endif; ?>

	<?php $open = rows("SELECT t.*, u.email FROM tickets t JOIN users u ON u.id = t.user_id WHERE t.status = 'open' ORDER BY t.updated_at"); if ($open): ?>
		<div class="card" style="border:2px solid #f5cd47">
			<h2>💬 Ждут ответа (<?= count($open) ?>)</h2>
			<?php foreach ($open as $t): ?>
				<a class="item router-link" href="users.php?id=<?= (int)$t['user_id'] ?>&t=<?= (int)$t['id'] ?>"><b><?= h($t['subject']) ?></b> <span class="muted">· <?= h($t['email']) ?> · <?= ago($t['updated_at']) ?></span></a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<div class="card">
		<?php $q = trim((string)($_GET['q'] ?? '')); $users = $q !== '' ? rows('SELECT * FROM users WHERE email LIKE ? OR id = ? ORDER BY id DESC LIMIT 200', ['%' . $q . '%', (int)$q]) : rows('SELECT * FROM users ORDER BY id DESC LIMIT 200'); ?>
		<form class="row" method="get"><input type="text" name="q" value="<?= h($q) ?>" placeholder="почта или номер кабинета" style="flex:1;min-width:180px"><button>Найти</button></form>
		<p class="muted">Всего пользователей: <?= (int)val('SELECT COUNT(*) FROM users') ?> · с активной подпиской: <?= (int)val('SELECT COUNT(*) FROM users WHERE free = 1 OR paid_until > ?', [time()]) ?></p>
		<table>
			<tr><th>#</th><th>Почта</th><th>Подписка</th><th>Роутеры</th><th>Последний вход</th></tr>
			<?php foreach ($users as $x): ?>
				<tr onclick="location='users.php?id=<?= (int)$x['id'] ?>'" style="cursor:pointer">
					<td><?= (int)$x['id'] ?></td>
					<td><a href="users.php?id=<?= (int)$x['id'] ?>"><?= h($x['email']) ?></a><?= $x['verified_at'] ? '' : ' <span class="muted">(почта не подтверждена)</span>' ?><?= $x['status'] === 'blocked' ? ' ⛔' : '' ?></td>
					<td><?= plan_badge($x) ?></td>
					<td><?= routers_summary((int)$x['id']) ?></td>
					<td class="muted"><?= $x['last_login'] ? ago($x['last_login']) : '—' ?></td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php if (!$users): ?><p class="muted">Пока никого.</p><?php endif; ?>
	</div>

	<details class="card" <?= legal_ready() ? '' : 'open' ?>>
		<summary><h2 style="display:inline">⚙️ Настройки сервиса</h2> <span class="muted">регистрация, пробный период, цена, реквизиты для документов</span></summary>
		<form method="post">
			<?= csrf_field() ?>
			<div class="grid">
				<div>
					<label class="field">Регистрация</label>
					<select name="registration"><option value="open" <?= $svc['registration'] === 'open' ? 'selected' : '' ?>>Открыта для всех</option><option value="closed" <?= $svc['registration'] !== 'open' ? 'selected' : '' ?>>Закрыта</option></select>
					<label class="field">Пробный период, дней</label><input type="text" name="trial_days" value="<?= (int)$svc['trial_days'] ?>">
					<label class="field">Роутеров в кабинете по умолчанию</label><input type="text" name="router_limit" value="<?= (int)$svc['router_limit'] ?>">
					<label class="field">Уведомления после окончания подписки ещё, дней</label><input type="text" name="grace_days" value="<?= (int)$svc['grace_days'] ?>">
					<label class="field">Стоимость (текст для кабинета)</label><input type="text" name="price" value="<?= h($svc['price']) ?>" placeholder="например: 200 ₽ в месяц">
					<label class="field">Как оплатить (пока нет онлайн-оплаты)</label><textarea name="pay_text" rows="4" style="font-family:inherit" placeholder="Например: перевод по СБП на номер …, в комментарии укажите номер кабинета. Подписка продлевается в течение суток."><?= h($svc['pay_text']) ?></textarea>
				</div>
				<div>
					<label class="field">Название сервиса</label><input type="text" name="name" value="<?= h($svc['name']) ?>">
					<label class="field">Оператор (кто оказывает услугу)</label><input type="text" name="op_name" value="<?= h($svc['op_name']) ?>" placeholder="Иванов Иван Иванович (самозанятый) или ИП Иванов И. И.">
					<label class="field">ИНН</label><input type="text" name="op_inn" value="<?= h($svc['op_inn']) ?>">
					<label class="field">ОГРНИП / ОГРН (если есть)</label><input type="text" name="op_ogrn" value="<?= h($svc['op_ogrn']) ?>">
					<label class="field">Почта для обращений</label><input type="text" name="op_email" value="<?= h($svc['op_email']) ?>">
					<label class="field">Адрес (необязательно)</label><input type="text" name="op_address" value="<?= h($svc['op_address']) ?>">
					<label class="field">Дата редакции документов</label><input type="text" name="docs_date" value="<?= h($svc['docs_date']) ?>" placeholder="<?= h(legal_version()) ?>">
				</div>
			</div>
			<p><button name="action" value="svc_save" class="primary">Сохранить</button>
				<span class="muted">Документы: <a href="<?= h(service_url()) ?>/legal.php?doc=terms" target="_blank">соглашение</a> · <a href="<?= h(service_url()) ?>/legal.php?doc=privacy" target="_blank">политика</a> · <a href="<?= h(service_url()) ?>/legal.php?doc=consent" target="_blank">согласие</a></span></p>
		</form>
	</details>
<?php endif; ?>
<?php
panel_footer();
