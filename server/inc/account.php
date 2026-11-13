<?php
// Настройки кабинета пользователя: пароль, подписка, ВКонтакте (через бота сервиса),
// доступ поддержки, выгрузка и удаление своих данных.

// Обработать действие формы. Возвращает [класс, текст] или null, если действие не наше.
function settings_service_action(string $act): ?array
{
	$u = cur_user();
	$uid = cur_uid();
	switch ($act) {
		case 'pw_change':
			if (!password_verify((string)($_POST['old'] ?? ''), $u['pass_hash'])) {
				sleep(1);
				return ['err', 'Текущий пароль неверный.'];
			}
			$new = (string)($_POST['new'] ?? '');
			if ($new !== (string)($_POST['new2'] ?? '')) {
				return ['err', 'Новые пароли не совпадают.'];
			}
			if ($p = password_problem($new, $u['email'])) {
				return ['err', $p];
			}
			// Остальные входы завершаются: в их сессиях старая «версия»
			q('UPDATE users SET pass_hash = ?, sess_ver = sess_ver + 1 WHERE id = ?', [hash_password($new), $uid]);
			$_SESSION['ver'] = (int)val('SELECT sess_ver FROM users WHERE id = ?', [$uid]);
			audit('сменён пароль кабинета, остальные входы завершены');
			return ['ok', 'Пароль изменён. На других устройствах нужно войти заново.'];

		case 'vk_code':
			if (!vk_group()) {
				return ['err', 'Уведомления во ВКонтакте сейчас недоступны — бот сервиса не настроен.'];
			}
			$rl = kv_get(okey('vkcode_rl'), ['n' => 0, 'ts' => time()]);
			if (time() - (int)$rl['ts'] > 3600) {
				$rl = ['n' => 0, 'ts' => time()];
			}
			if ($rl['n'] >= 10) {
				return ['err', 'Слишком много кодов за час — попробуйте позже.'];
			}
			$rl['n']++;
			kv_set(okey('vkcode_rl'), $rl);
			vk_link_code($uid);
			return null;

		case 'vk_check':
			$err = vk_link_check($uid);
			if ($err !== null) {
				return ['err', $err];
			}
			$l = vk_link($uid);
			audit('подключены уведомления во ВКонтакте: ' . $l['name']);
			vk_notify("✅ Уведомления о ваших роутерах будут приходить сюда.\nОтключить их можно в настройках кабинета.", service_url() . '/settings.php', $uid);
			return ['ok', 'Готово: уведомления будут приходить в личные сообщения ВКонтакте (' . $l['name'] . ').'];

		case 'vk_toggle':
			$l = vk_link($uid);
			if (!$l['id']) {
				return ['err', 'ВКонтакте не подключён.'];
			}
			$l['enabled'] = !$l['enabled'];
			vk_link_save($uid, $l);
			audit('уведомления ВКонтакте ' . ($l['enabled'] ? 'включены' : 'выключены'));
			return ['ok', $l['enabled'] ? 'Уведомления во ВКонтакте включены.' : 'Уведомления во ВКонтакте выключены.'];

		case 'vk_test':
			kv_del(okey('vk_last_error'));
			$l = vk_link($uid);
			if (!$l['id'] || !$l['enabled']) {
				return ['err', 'Сначала подключите и включите уведомления.'];
			}
			vk_notify('🔔 Тестовое уведомление. Если вы это читаете — всё настроено.', service_url() . '/', $uid);
			$e = kv_get(okey('vk_last_error'));
			return $e ? ['err', 'Не отправилось: ' . $e['error']] : ['ok', 'Отправлено — проверьте сообщения ВКонтакте.'];

		case 'vk_unlink':
			vk_link_save($uid, ['id' => 0, 'name' => '', 'enabled' => false, 'code' => '', 'code_exp' => 0]);
			audit('ВКонтакте отвязан от кабинета');
			return ['ok', 'ВКонтакте отвязан, уведомления туда больше не приходят.'];

		case 'slots':
			// Сколько роутеров в подписке: от включённых в цену; меньше, чем уже подключено, — нельзя
			if (!empty($u['free']) || price_rub() <= 0) {
				return ['ok', 'У вас нет ограничения на число роутеров.'];
			}
			$n = (int)($_POST['slots'] ?? 0);
			$min = max(base_routers(), user_router_count($uid));
			if ($n < $min || $n > 100) {
				return ['err', "Укажите число от $min до 100" . ($min > base_routers() ? " — сейчас подключено роутеров: $min; чтобы уменьшить лимит, сначала удалите лишние" : '') . '.'];
			}
			q('UPDATE users SET router_limit = ? WHERE id = ?', [$n > base_routers() ? $n : 0, $uid]);
			$u = get_user($uid);
			audit('роутеров в подписке: ' . $n . ' (' . price_text($u) . ')');
			return ['ok', "Роутеров в подписке: $n. Цена — " . price_text($u) . ', ' . money(daily_kop($u)) . ' в сутки' . (user_in_trial($u) ? ' (после пробного периода; сейчас действует лимит пробного периода — ' . user_router_limit($u) . ')' : ', со следующего ежедневного списания') . '.'];

		case 'support_on':
			$days = max(1, min(14, (int)($_POST['days'] ?? 3)));
			q('UPDATE users SET support_until = ? WHERE id = ?', [time() + $days * 86400, $uid]);
			audit("открыт доступ поддержки к роутерам на $days дн.");
			return ['ok', "Поддержка сможет менять настройки ваших роутеров и запрашивать отчёты в течение $days дн. Все её действия будут в журнале событий."];

		case 'support_off':
			q('UPDATE users SET support_until = 0 WHERE id = ?', [$uid]);
			audit('закрыт доступ поддержки к роутерам');
			return ['ok', 'Доступ поддержки закрыт.'];

		case 'export':
			account_export($uid);
			exit;

		case 'delete_account':
			if (!password_verify((string)($_POST['password'] ?? ''), $u['pass_hash'])) {
				sleep(1);
				return ['err', 'Пароль неверный — кабинет не удалён.'];
			}
			if (mb_strtoupper(trim((string)($_POST['confirm'] ?? ''))) !== 'УДАЛИТЬ') {
				return ['err', 'Чтобы удалить кабинет, впишите слово УДАЛИТЬ.'];
			}
			event(null, 'Кабинет удалён по просьбе пользователя');
			delete_user($uid);
			$_SESSION = ['csrf' => bin2hex(random_bytes(16)), 'flash_guest' => 'Кабинет и все его данные удалены. Не забудьте удалить агент с роутеров: tim-agent uninstall'];
			header('Location: index.php');
			exit;
	}
	return null;
}

// Все мои данные одним файлом (право субъекта персональных данных на доступ к ним)
function account_export(int $uid): void
{
	$u = get_user($uid);
	unset($u['pass_hash']);
	$routers = rows('SELECT id, name, status, created_at, last_seen, ip, info FROM routers WHERE user_id = ?', [$uid]);
	foreach ($routers as &$r) {
		$r['info'] = rinfo($r);
		$r['sections'] = array_map(fn($s) => ['name' => $s['name'], 'data' => sdata($s)], router_sections((int)$r['id']));
	}
	unset($r);
	$l = vk_link($uid);
	$out = [
		'exported_at' => date('c'),
		'account' => $u,
		'vk' => $l['id'] ? ['id' => $l['id'], 'name' => $l['name'], 'enabled' => $l['enabled']] : null,
		'devices' => array_values(devices()),
		'two_factor' => totp_settings()['enabled'],
		'routers' => $routers,
		'events' => rows('SELECT ts, router_id, text FROM events WHERE user_id = ? ORDER BY id', [$uid]),
		'notifications' => rows('SELECT ts, text FROM outbox WHERE user_id = ? ORDER BY id', [$uid]),
		'tickets' => array_map(fn($t) => $t + ['messages' => rows('SELECT ts, from_admin, text FROM ticket_msgs WHERE ticket_id = ? ORDER BY id', [$t['id']])],
			rows('SELECT id, subject, status, created_at FROM tickets WHERE user_id = ?', [$uid])),
	];
	event(null, 'Скачана выгрузка всех данных кабинета');
	header('Content-Type: application/json; charset=utf-8');
	header('Content-Disposition: attachment; filename="my-data-' . date('Y-m-d') . '.json"');
	echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function settings_service_cards(): void
{
	$u = cur_user();
	$uid = cur_uid();
	$plan = user_plan($u);
	$svc = svc();
	$l = vk_link($uid);
	$g = vk_group();
	$vkErr = kv_get(okey('vk_last_error'));
	?>
	<div class="card">
		<h2>👤 Аккаунт</h2>
		<p>Почта: <b><?= h($u['email']) ?></b> <?= $u['verified_at'] ? '<span style="color:#22a06b">✓ подтверждена</span>' : '<span style="color:#c9372c">не подтверждена</span>' ?><br>
			<span class="muted">Кабинет создан <?= date('d.m.Y', (int)$u['created_at']) ?><?= $u['last_login'] ? ' · последний вход ' . date('d.m.Y H:i', (int)$u['last_login']) : '' ?></span></p>
		<?php if (!$u['verified_at']): ?>
			<form method="post" action="verify.php"><?= csrf_field() ?><button name="resend" value="1">📧 Отправить письмо для подтверждения ещё раз</button></form>
		<?php endif; ?>
		<details style="margin-top:8px">
			<summary><b>Сменить пароль</b></summary>
			<form method="post" style="max-width:360px">
				<?= csrf_field() ?>
				<label class="field">Текущий пароль</label><input type="password" name="old" autocomplete="current-password" required>
				<label class="field">Новый пароль <span class="muted">(не короче 10 символов)</span></label><input type="password" name="new" autocomplete="new-password" required>
				<label class="field">Новый пароль ещё раз</label><input type="password" name="new2" autocomplete="new-password" required>
				<p><button name="action" value="pw_change" class="primary">Сменить пароль</button></p>
			</form>
		</details>
	</div>

	<div class="card" id="plan">
		<h2>💳 Подписка и баланс</h2>
		<p>Состояние: <b style="color:<?= $plan['active'] ? '#22a06b' : '#c9372c' ?>"><?= h($plan['label']) ?></b><br>
			<span class="muted">Роутеров: <?= h(user_limit_text($u, user_router_count($uid))) ?></span></p>
		<?php if (!$plan['free']): ?>
			<p>Цена: <b><?= h(price_text($u)) ?></b> — списывается с баланса по дням (<?= money(daily_kop($u)) ?> в сутки), пока на нём есть деньги.<br>
				Баланс: <b><?= money((int)$u['balance']) ?></b><?= (int)$u['balance'] >= daily_kop($u) && daily_kop($u) > 0 ? ' — хватит примерно до <b>' . date('d.m.Y', paid_until_projected($u)) . '</b>' : '' ?></p>
			<?php if (!empty($plan['free_until'])): ?>
				<div class="notice">🎁 Сейчас идёт <b><?= h($plan['free_kind']) ?></b> — до <b><?= date('d.m.Y H:i', $plan['free_until']) ?></b>. Баланс в это время не списывается; списания (<?= money(daily_kop($u)) ?> в сутки) начнутся <b><?= date('d.m.Y', $plan['free_until']) ?></b>.
					<?= (int)$u['balance'] >= daily_kop($u) ? 'С текущим балансом сервис проработает примерно до <b>' . date('d.m.Y', $plan['until']) . '</b>.' : 'Чтобы сервис продолжил работать после этого, пополните баланс.' ?></div>
			<?php endif; ?>
			<?php $slotMin = max(base_routers(), user_router_count($uid)); ?>
			<form method="post" class="row" id="slots"><?= csrf_field() ?>
				<span>Роутеров в подписке:</span>
				<input type="number" name="slots" value="<?= user_slots($u) ?>" min="<?= $slotMin ?>" max="100" style="width:80px"
					oninput="var n = Math.max(<?= base_routers() ?>, +this.value || 0), k = <?= price_rub() * 100 ?> + (n - <?= base_routers() ?>) * <?= router_price_kop() ?>; document.getElementById('slots-price').textContent = (k / 100).toFixed(2).replace('.', ',').replace(',00', '') + ' ₽ в месяц, ' + (Math.round(k / 30) / 100).toFixed(2).replace('.', ',') + ' ₽ в сутки'">
				<button name="action" value="slots">Изменить</button>
				<span class="muted">будет: <b id="slots-price"><?= money(user_month_kop($u)) ?> в месяц, <?= money(daily_kop($u)) ?> в сутки</b></span>
			</form>
			<p class="muted"><?= base_routers() ?> роутеров входят в цену <?= price_rub() ?> ₽, каждый следующий — +<?= money(router_price_kop()) ?> в месяц.<?= user_in_trial($u) ? ' В пробный период можно подключить до ' . (int)$svc['router_limit'] . ' роутеров.' : '' ?></p>
			<?php if ($svc['pay_text'] !== ''): ?>
				<div class="notice"><b>Как пополнить баланс:</b><br><?= nl2br(h($svc['pay_text'])) ?><br><span class="muted">Номер вашего кабинета для оплаты: <b>#<?= $uid ?></b></span></div>
			<?php else: ?>
				<p class="muted">Онлайн-оплата скоро появится. Чтобы пополнить баланс сейчас, напишите в <a href="support.php">поддержку</a>.</p>
			<?php endif; ?>
			<p class="muted">Деньги на балансе не сгорают. Если вы откажетесь от сервиса, неизрасходованный остаток возвращается по вашему заявлению за вычетом фактических расходов (комиссии платёжной системы); подаренные дни не возвращаются.</p>
			<p class="muted">Без активной подписки роутеры продолжают работать с последними настройками podkop, но выключаются сторож интернета, автоматика подписок и изменения настроек с сайта; уведомления прекращаются через <?= (int)$svc['grace_days'] ?> дн.</p>
			<?php $pays = rows("SELECT * FROM payments WHERE user_id = ? AND kind != 'charge' ORDER BY id DESC LIMIT 10", [$uid]); if ($pays): ?>
				<details><summary>История пополнений</summary>
					<?php foreach ($pays as $p): ?><div class="muted"><?= date('d.m.Y', (int)$p['ts']) ?> · <?= ['topup' => 'пополнение', 'refund' => 'возврат', 'gift' => 'подарок'][$p['kind']] ?? h($p['kind']) ?> <?= $p['amount'] ? money((int)$p['amount']) : h($p['comment']) ?></div><?php endforeach; ?>
				</details>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<div class="card">
		<h2>🐕 Сторож интернета</h2>
		<p>На каждом роутере работает сторож интернета: каждые 30 секунд проверяет связь, DNS, работу podkop, память и веб-интерфейс роутера. При сбое он по очереди перезапускает подключение к интернету и podkop, переподключает устройства, а если не помогает — перезагружает роутер (не чаще нескольких раз подряд, дальше — с большими паузами). Историю его действий видно на странице роутера и в LuCI: «Службы → Сторож интернета».</p>
		<p class="muted">Сторож работает при активной подписке (в том числе в пробный период) и пока роутер на связи с сервисом: для правильной работы сервиса нужен постоянный доступ роутера к нему. Если доступа к сервису нет больше 30 минут при работающем интернете, сторож и автоматика подписок выключаются до восстановления связи.</p>
	</div>

	<div class="card">
		<h2>🔔 Уведомления во ВКонтакте</h2>
		<?php if (!$g): ?>
			<p class="muted">Сейчас недоступны: бот сервиса не настроен. Уведомления видны на главной странице кабинета.</p>
		<?php elseif ($l['id']): ?>
			<p>Приходят в личные сообщения: <b><?= h($l['name']) ?></b> — <?= $l['enabled'] ? '<b style="color:#22a06b">включены</b>' : '<b style="color:#c9372c">выключены</b>' ?>.
				<br><span class="muted">Пишет сообщество «<?= h($g['name']) ?>». О чём: роутер пропал со связи и вернулся, не работает ключ или VPN, вход на роутер, результаты задач.</span></p>
			<?php if ($vkErr): ?><div class="notice err">Последняя ошибка отправки (<?= date('d.m H:i', (int)$vkErr['ts']) ?>): <?= h($vkErr['error']) ?></div><?php endif; ?>
			<div class="row">
				<form method="post"><?= csrf_field() ?><button name="action" value="vk_test">✉️ Отправить тестовое</button></form>
				<form method="post"><?= csrf_field() ?><button name="action" value="vk_toggle"><?= $l['enabled'] ? '🔕 Выключить' : '🔔 Включить' ?></button></form>
				<form method="post" onsubmit="return confirm('Отвязать ВКонтакте?')"><?= csrf_field() ?><button name="action" value="vk_unlink" class="danger">Отвязать</button></form>
			</div>
		<?php else: ?>
			<p>Уведомления о ваших роутерах может присылать в личные сообщения сообщество <b>«<?= h($g['name']) ?>»</b>. Подключение займёт минуту:</p>
			<?php if ($l['code'] !== '' && (int)$l['code_exp'] > time()): ?>
				<ol>
					<li>Откройте сообщество: <a class="btn" href="<?= h(vk_chat_url($g)) ?>" target="_blank" rel="noopener">💬 Написать сообществу</a></li>
					<li>Отправьте ему <b>одним сообщением</b> этот код (действует до <?= date('H:i', (int)$l['code_exp']) ?>):
						<pre class="cmd" data-copy style="font-size:20px;letter-spacing:2px;text-align:center"><?= h($l['code']) ?></pre></li>
					<li>Вернитесь сюда и нажмите:</li>
				</ol>
				<form method="post"><?= csrf_field() ?><button name="action" value="vk_check" class="primary">✅ Я отправил код</button></form>
				<p class="muted">Код подтверждает, что это ваша страница ВКонтакте, и разрешает сообществу писать вам. Больше сообществу писать ничего не нужно.</p>
			<?php else: ?>
				<form method="post"><?= csrf_field() ?><button name="action" value="vk_code" class="primary">Подключить ВКонтакте</button></form>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<div class="card">
		<h2>🛟 Доступ поддержки</h2>
		<?php if ((int)$u['support_until'] > time()): ?>
			<p>Открыт до <b><?= date('d.m.Y H:i', (int)$u['support_until']) ?></b>: поддержка может менять настройки ваших роутеров и запрашивать отчёты. Каждое её действие записывается в журнал событий.</p>
			<form method="post"><?= csrf_field() ?><button name="action" value="support_off" class="danger">Закрыть доступ сейчас</button></form>
		<?php else: ?>
			<p>Закрыт. Поддержка видит только, на связи ли ваши роутеры и работают ли они (зелёный / красный / серый значок) — ни настроек podkop, ни названий, ни адресов.
				Если нужна помощь — откройте доступ на время (здесь или кнопкой в обращении): поддержка сможет посмотреть настройки и исправить их сама. Все её действия будут в журнале.</p>
			<form method="post" class="row"><?= csrf_field() ?>
				<select name="days" style="width:auto"><option value="1">на 1 день</option><option value="3" selected>на 3 дня</option><option value="7">на 7 дней</option></select>
				<button name="action" value="support_on">Открыть доступ</button>
			</form>
		<?php endif; ?>
	</div>

	<div class="card">
		<h2>📦 Мои данные</h2>
		<div class="row">
			<form method="post"><?= csrf_field() ?><button name="action" value="export">⬇️ Скачать все мои данные</button></form>
		</div>
		<details style="margin-top:10px">
			<summary><b style="color:#c9372c">Удалить кабинет</b></summary>
			<p>Удалятся кабинет, роутеры, их настройки на сервере, журнал, уведомления и обращения. С роутеров при следующем выходе на связь удалятся агент и сторож интернета; podkop и его настройки останутся, интернет продолжит работать. На старых агентах (до 2.4) удалите вручную: <code>inet-watchdog uninstall; tim-agent uninstall</code>.</p>
			<?php if ((int)$u['balance'] > 0): ?><p class="notice">На балансе <?= money((int)$u['balance']) ?>. Чтобы вернуть остаток, сначала напишите в <a href="support.php">поддержку</a> — после удаления кабинета номер оплаты не найти.</p><?php endif; ?>
			<form method="post" style="max-width:360px" onsubmit="return confirm('Удалить кабинет без возможности восстановления?')">
				<?= csrf_field() ?>
				<label class="field">Пароль</label><input type="password" name="password" autocomplete="current-password" required>
				<label class="field">Впишите слово УДАЛИТЬ</label><input type="text" name="confirm" required>
				<p><button name="action" value="delete_account" class="danger">Удалить навсегда</button></p>
			</form>
		</details>
	</div>
	<?php
}
