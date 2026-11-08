<?php
// Поддержка в кабинете: обращения пользователя и ответы администратора.
// Администратору о новых обращениях ничего не присылается — он видит их в админке («Пользователи»).

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/panel.php';

only_mode('service');
panel_start();
panel_require();

$uid = cur_uid();
$err = '';
$tid = (int)($_GET['t'] ?? 0);
$ticket = $tid ? row('SELECT * FROM tickets WHERE id = ? AND user_id = ?', [$tid, $uid]) : null;

// Живое обновление переписки
if (($_GET['partial'] ?? '') === 'msgs') {
	header('Cache-Control: no-store');
	echo $ticket ? ticket_msgs_html((int)$ticket['id'], false) : '';
	exit;
}

// Доступ поддержки к роутерам — на 3 дня, из обращения
function support_grant(int $days): void
{
	q('UPDATE users SET support_until = ? WHERE id = ?', [time() + $days * 86400, cur_uid()]);
	audit("открыт доступ поддержки к роутерам на $days дн. (из обращения)");
	$GLOBALS['USER'] = get_user(cur_uid());
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
	$text = trim((string)($_POST['text'] ?? ''));
	$rl = kv_get(okey('ticket_rl'), ['n' => 0, 'ts' => time()]);
	if (time() - (int)$rl['ts'] > 86400) {
		$rl = ['n' => 0, 'ts' => time()];
	}
	if (csrf_ok() && isset($_POST['grant']) && $ticket) {
		support_grant(3);
		header('Location: support.php?t=' . $ticket['id']);
		exit;
	}
	if (!csrf_ok()) {
		$err = 'Форма устарела — обновите страницу.';
	} elseif (mb_strlen($text) < 5) {
		$err = 'Опишите, пожалуйста, что случилось.';
	} elseif ($rl['n'] >= 30) {
		$err = 'Слишком много сообщений за сутки.';
	} elseif (isset($_POST['new'])) {
		$subject = mb_substr(trim((string)($_POST['subject'] ?? '')), 0, 120) ?: mb_substr($text, 0, 60);
		$rid = (int)($_POST['router'] ?? 0);
		if ($rid && !val('SELECT 1 FROM routers WHERE id = ? AND user_id = ?', [$rid, $uid])) {
			$rid = 0;
		}
		q("INSERT INTO tickets (user_id, router_id, subject, status, created_at, updated_at) VALUES (?, ?, ?, 'open', ?, ?)", [$uid, $rid ?: null, $subject, time(), time()]);
		$tid = (int)db()->lastInsertId();
		q('INSERT INTO ticket_msgs (ticket_id, from_admin, ts, text) VALUES (?, 0, ?, ?)', [$tid, time(), mb_substr($text, 0, 5000)]);
		if (!empty($_POST['grant'])) {
			support_grant(3);
		}
		$rl['n']++;
		kv_set(okey('ticket_rl'), $rl);
		header('Location: support.php?t=' . $tid . '&sent=1');
		exit;
	} elseif ($ticket) {
		q('INSERT INTO ticket_msgs (ticket_id, from_admin, ts, text) VALUES (?, 0, ?, ?)', [$ticket['id'], time(), mb_substr($text, 0, 5000)]);
		q("UPDATE tickets SET status = 'open', updated_at = ? WHERE id = ?", [time(), $ticket['id']]);
		$rl['n']++;
		kv_set(okey('ticket_rl'), $rl);
		header('Location: support.php?t=' . $ticket['id'] . '&sent=1');
		exit;
	}
}

$statusRu = ['open' => '⏳ ждёт ответа', 'answered' => '💬 есть ответ', 'closed' => '✅ закрыто'];
panel_header('Поддержка');
?>
	<h2 style="font-size:20px;margin:4px 0 12px">💬 Поддержка</h2>
	<?php if ($err): ?><div class="notice err"><?= h($err) ?></div><?php endif; ?>
	<?php if (isset($_GET['sent'])): ?><div class="notice ok">Отправлено. Ответ появится здесь<?= vk_link($uid)['enabled'] ? ' и придёт во ВКонтакте' : '' ?>.</div><?php endif; ?>

	<?php if ($ticket): ?>
		<div class="card">
			<h2><?= h($ticket['subject']) ?> <span class="muted"><?= $statusRu[$ticket['status']] ?? '' ?></span></h2>
			<div id="ticket-msgs" data-poll="support.php?t=<?= (int)$ticket['id'] ?>&amp;partial=msgs"><?= ticket_msgs_html((int)$ticket['id'], false) ?></div>
			<?php $su = (int)cur_user()['support_until']; ?>
			<div class="notice" style="margin-top:10px">🛟 Доступ поддержки к вашим роутерам: <?php if ($su > time()): ?><b>открыт до <?= date('d.m H:i', $su) ?></b> — закрыть можно в «Настройках».<?php else: ?><b>закрыт</b> — поддержка видит только, на связи ли роутеры и работают ли они.
				<form method="post" style="display:inline"><?= csrf_field() ?><button name="grant" value="1">Открыть доступ на 3 дня</button></form><?php endif; ?></div>
			<form method="post" style="margin-top:10px">
				<?= csrf_field() ?>
				<textarea name="text" rows="4" placeholder="Ответить" style="font-family:inherit"></textarea>
				<p><button class="primary">Отправить</button> &nbsp; <a href="support.php">← Все обращения</a></p>
			</form>
		</div>
	<?php else: ?>
		<div class="card">
			<h2>Новое обращение</h2>
			<p class="muted">Опишите, что не работает. Без вашего разрешения поддержка видит только, на связи ли роутеры и работают ли они — ни настроек, ни данных роутеров.</p>
			<form method="post">
				<?= csrf_field() ?>
				<input type="hidden" name="new" value="1">
				<label class="field">Тема</label><input type="text" name="subject" maxlength="120">
				<?php $myRouters = rows("SELECT id, name FROM routers WHERE user_id = ? AND status = 'active' ORDER BY name", [$uid]); if ($myRouters): ?>
					<label class="field">Роутер</label>
					<select name="router"><option value="0">— не важно —</option><?php foreach ($myRouters as $r): ?><option value="<?= (int)$r['id'] ?>"><?= h($r['name']) ?></option><?php endforeach; ?></select>
				<?php endif; ?>
				<label class="field">Что случилось</label><textarea name="text" rows="5" required style="font-family:inherit"></textarea>
				<p><label class="check"><input type="checkbox" name="grant" value="1"> <span>Открыть поддержке доступ к настройкам моих роутеров на 3 дня — чтобы она могла посмотреть и исправить сама</span></label></p>
				<p><button class="primary">Отправить</button></p>
			</form>
		</div>
		<?php $list = rows('SELECT * FROM tickets WHERE user_id = ? ORDER BY updated_at DESC LIMIT 50', [$uid]); if ($list): ?>
			<div class="card">
				<h2>Мои обращения</h2>
				<?php foreach ($list as $t): ?>
					<a class="item router-link" href="support.php?t=<?= (int)$t['id'] ?>"><b><?= h($t['subject']) ?></b> <span class="muted">· <?= $statusRu[$t['status']] ?? '' ?> · <?= date('d.m.Y H:i', (int)$t['updated_at']) ?></span></a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	<?php endif; ?>
<?php
panel_footer();
