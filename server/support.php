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

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
	$text = trim((string)($_POST['text'] ?? ''));
	$rl = kv_get(okey('ticket_rl'), ['n' => 0, 'ts' => time()]);
	if (time() - (int)$rl['ts'] > 86400) {
		$rl = ['n' => 0, 'ts' => time()];
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
			<?php foreach (rows('SELECT * FROM ticket_msgs WHERE ticket_id = ? ORDER BY id', [$ticket['id']]) as $m): ?>
				<div class="msg" style="<?= $m['from_admin'] ? 'border-color:#22a06b' : '' ?>">
					<span class="muted"><?= $m['from_admin'] ? 'Поддержка' : 'Вы' ?> · <?= date('d.m.Y H:i', (int)$m['ts']) ?></span><br><?= nl2br(h($m['text'])) ?>
				</div>
			<?php endforeach; ?>
			<form method="post" style="margin-top:10px">
				<?= csrf_field() ?>
				<textarea name="text" rows="4" placeholder="Ответить" style="font-family:inherit"></textarea>
				<p><button class="primary">Отправить</button> &nbsp; <a href="support.php">← Все обращения</a></p>
			</form>
		</div>
	<?php else: ?>
		<div class="card">
			<h2>Новое обращение</h2>
			<p class="muted">Опишите, что не работает. Поддержка видит состояние ваших роутеров; если понадобится что-то поменять, вас попросят открыть доступ в «Настройках» → «Доступ поддержки».</p>
			<form method="post">
				<?= csrf_field() ?>
				<input type="hidden" name="new" value="1">
				<label class="field">Тема</label><input type="text" name="subject" maxlength="120">
				<?php $myRouters = rows("SELECT id, name FROM routers WHERE user_id = ? AND status = 'active' ORDER BY name", [$uid]); if ($myRouters): ?>
					<label class="field">Роутер</label>
					<select name="router"><option value="0">— не важно —</option><?php foreach ($myRouters as $r): ?><option value="<?= (int)$r['id'] ?>"><?= h($r['name']) ?></option><?php endforeach; ?></select>
				<?php endif; ?>
				<label class="field">Что случилось</label><textarea name="text" rows="5" required style="font-family:inherit"></textarea>
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
