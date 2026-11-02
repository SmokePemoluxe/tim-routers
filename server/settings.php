<?php
// Настройки панели: уведомления во ВКонтакте, двухфакторная защита, устройства, резервные копии.

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/panel.php';

panel_start();
panel_require();

$vk = vk_settings();
$msg = null;
$found = null; // найденные собеседники сообщества (после кнопки «Найти»)

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
	if (!csrf_ok()) {
		$msg = ['err', 'Форма устарела — обновите страницу.'];
	} else {
		switch ($_POST['action'] ?? '') {
			case 'save_token':
				$token = trim((string)($_POST['token'] ?? ''));
				$r = $token !== '' ? vk_api('groups.getById', [], $token) : ['ok' => false, 'error' => 'вставьте ключ'];
				$g = $r['ok'] ? ($r['response']['groups'][0] ?? $r['response'][0] ?? null) : null;
				if (!$g) {
					$msg = ['err', 'Ключ не подошёл: ' . ($r['error'] ?? 'это не ключ сообщества')];
				} else {
					$vk['token'] = $token;
					$vk['group'] = (string)($g['name'] ?? ('id' . ($g['id'] ?? '')));
					vk_save($vk);
					audit('сохранён ключ сообщества ВКонтакте «' . $vk['group'] . '»');
					$msg = ['ok', 'Ключ сохранён: сообщество «' . $vk['group'] . '». Теперь напишите сообществу любое сообщение и нажмите «Найти, кто написал».'];
				}
				break;

			case 'find':
				$r = vk_api('messages.getConversations', ['count' => 50, 'extended' => 1]);
				if (!$r['ok']) {
					$msg = ['err', 'Не получилось: ' . $r['error']];
					break;
				}
				$names = [];
				foreach ($r['response']['profiles'] ?? [] as $p) {
					$names[(int)$p['id']] = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
				}
				$found = [];
				foreach ($r['response']['items'] ?? [] as $it) {
					$conv = $it['conversation'] ?? [];
					$peer = $conv['peer'] ?? [];
					$id = (int)($peer['id'] ?? 0);
					if (($peer['type'] ?? '') === 'user') {
						$found[] = ['id' => $id, 'name' => $names[$id] ?? ('id' . $id)];
					} elseif (($peer['type'] ?? '') === 'chat') {
						// Беседа, в которую добавили сообщество (peer_id = 2000000000 + номер беседы)
						$found[] = ['id' => $id, 'name' => '💬 беседа «' . ($conv['chat_settings']['title'] ?? ('№' . ($id - 2000000000))) . '»'];
					}
				}
				// Беседы, где бот не видит обычных сообщений, в списке переписок не появляются.
				// Но у сообщества они нумеруются по порядку (2000000001, 2000000002, …) — спрашиваем напрямую.
				// По одной: если беседы с таким номером нет, ВКонтакте отвечает ошибкой на весь запрос.
				// Если у бота нет доступа ко всей переписке, беседа «существует», но без названия — тоже подходит.
				$known = array_column($found, 'id');
				for ($id = 2000000001; $id <= 2000000010; $id++) {
					if (in_array($id, $known, true)) {
						continue;
					}
					$p = vk_api('messages.getConversationsById', ['peer_ids' => $id]);
					if ($p['ok']) {
						$title = $p['response']['items'][0]['chat_settings']['title'] ?? '';
						$found[] = ['id' => $id, 'name' => '💬 беседа ' . ($title !== '' ? '«' . $title . '»' : '№' . ($id - 2000000000))];
					}
				}
				if (!$found) {
					$msg = ['err', 'Сообщество пока не видит ни одной переписки. Проверьте, что сообщество добавлено в беседу (в списке участников беседы), и нажмите кнопку снова.'];
				}
				break;

			case 'save_peers':
				$peers = [];
				foreach ((array)($_POST['peer'] ?? []) as $id) {
					$id = (int)$id;
					if ($id > 0) {
						$peers[] = ['id' => $id, 'name' => mb_substr(trim((string)($_POST['name'][$id] ?? '')), 0, 60) ?: 'id' . $id];
					}
				}
				audit('получатели уведомлений ВК: ' . ($peers ? implode(', ', array_column($peers, 'name')) : 'никого (уведомления ВК выключаются)'));
				$vk['peers'] = $peers;
				$vk['enabled'] = (bool)$peers;
				vk_save($vk);
				$msg = ['ok', $peers ? 'Получатели сохранены, уведомления включены.' : 'Получателей нет — уведомления во ВКонтакте выключены.'];
				break;

			case 'toggle':
				if ($vk['enabled']) {
					audit('уведомления ВКонтакте ВЫКЛЮЧЕНЫ');
				}
				$vk['enabled'] = !$vk['enabled'] && $vk['peers'];
				vk_save($vk);
				if ($vk['enabled']) {
					audit('уведомления ВКонтакте включены');
				}
				$msg = ['ok', $vk['enabled'] ? 'Уведомления во ВКонтакте включены.' : 'Уведомления во ВКонтакте выключены.'];
				break;

			case 'test':
				kv_del('vk_last_error');
				$save = $vk['enabled'];
				$vk['enabled'] = true;
				vk_save($vk);
				vk_notify("🔔 Тестовое уведомление от панели роутеров.\nЕсли вы это читаете — всё настроено.", rtrim((string)cfg('base_url'), '/') . '/');
				$vk['enabled'] = $save;
				vk_save($vk);
				$err = kv_get('vk_last_error');
				$msg = $err ? ['err', 'Не отправилось: ' . $err['error']] : ['ok', 'Отправлено — проверьте сообщения ВКонтакте.'];
				break;

			case 'forget':
				audit('ключ сообщества ВКонтакте и получатели УДАЛЕНЫ');
				vk_save(['token' => '', 'group' => '', 'peers' => [], 'enabled' => false]);
				$vk = vk_settings();
				$msg = ['ok', 'Ключ и получатели удалены.'];
				break;

			// --- Двухфакторная защита ---
			case 'totp_start':
				$_SESSION['totp_new'] = base32_encode(random_bytes(20));
				break;

			case 'totp_confirm':
				$secret = (string)($_SESSION['totp_new'] ?? '');
				if ($secret === '' || !totp_verify_secret($secret, (string)($_POST['code'] ?? ''))) {
					$msg = ['err', 'Код не подошёл. Проверьте, что время на телефоне точное, и введите свежий код.'];
					break;
				}
				kv_set('totp', ['enabled' => true, 'secret' => $secret, 'backup' => []]);
				$_SESSION['totp_codes'] = totp_new_backup();
				unset($_SESSION['totp_new']);
				device_remember(device_id(), true);
				audit('включена двухфакторная защита входа');
				$msg = ['ok', 'Двухфакторная защита включена. Сохраните резервные коды ниже — они показываются один раз.'];
				break;

			case 'totp_backup':
			case 'totp_off':
				if (!totp_check((string)($_POST['code'] ?? ''))) {
					$msg = ['err', 'Неверный код из приложения.'];
					break;
				}
				if ($_POST['action'] === 'totp_off') {
					audit('ВЫКЛЮЧЕНА двухфакторная защита входа');
					kv_set('totp', ['enabled' => false, 'secret' => '', 'backup' => []]);
					$msg = ['ok', 'Двухфакторная защита выключена.'];
				} else {
					$_SESSION['totp_codes'] = totp_new_backup();
					audit('созданы новые резервные коды двухфакторки (старые больше не действуют)');
					$msg = ['ok', 'Новые резервные коды — ниже. Старые больше не действуют.'];
				}
				break;

			// --- Устройства ---
			case 'devices_forget':
				audit('забыты все устройства: на каждом снова спросят пароль и код');
				devices_forget();
				$cur = session_id();
				foreach (glob(data_dir() . '/sessions/sess_*') ?: [] as $f) {
					if (basename($f) !== 'sess_' . $cur) {
						@unlink($f);
					}
				}
				device_remember(device_id(), totp_settings()['enabled']);
				$msg = ['ok', 'Все устройства забыты, все остальные входы завершены. Это устройство запомнено заново.'];
				break;
		}
	}
}

// Скачать резервную копию базы
if (isset($_GET['backup'])) {
	$name = basename((string)$_GET['backup']);
	$file = data_dir() . '/backups/' . $name;
	if (!preg_match('/^routers-\d{4}-\d{2}-\d{2}\.sqlite$/', $name) || !is_file($file)) {
		http_response_code(404);
		exit('Нет такой копии');
	}
	audit('скачана резервная копия базы ' . $name);
	header('Content-Type: application/octet-stream');
	header('Content-Disposition: attachment; filename="' . $name . '"');
	readfile($file);
	exit;
}

$lastErr = kv_get('vk_last_error');
$totp = totp_settings();
$newCodes = $_SESSION['totp_codes'] ?? null;
unset($_SESSION['totp_codes']);

panel_header('Настройки');
?>
	<h2 style="font-size:20px;margin:4px 0 12px">⚙️ Настройки</h2>
	<?php if ($msg): ?><div class="notice <?= h($msg[0]) ?>"><?= h($msg[1]) ?></div><?php endif; ?>

	<div class="card">
		<h2>🔔 Уведомления во ВКонтакте</h2>
		<p>
			Состояние:
			<?php if ($vk['enabled'] && $vk['peers']): ?>
				<b style="color:#22a06b">включены</b> — сообщество «<?= h($vk['group']) ?>» пишет:
				<?= h(implode(', ', array_column($vk['peers'], 'name'))) ?>
			<?php elseif ($vk['token']): ?>
				<b style="color:#c9372c">выключены</b> (ключ сообщества «<?= h($vk['group']) ?>» сохранён)
			<?php else: ?>
				<b>не настроены</b>
			<?php endif; ?>
		</p>
		<p class="muted">Приходят те же уведомления, что в ленте на главной: роутер пропал со связи и вернулся, не работает ключ или VPN, результаты задач. Повторы ограничены, как и раньше.</p>
		<?php if ($lastErr): ?>
			<div class="notice err">Последняя ошибка отправки (<?= date('d.m H:i', (int)$lastErr['ts']) ?>): <?= h($lastErr['error']) ?></div>
		<?php endif; ?>

		<details <?= $vk['token'] ? '' : 'open' ?>>
			<summary><b>Как подключить (один раз, ~5 минут)</b></summary>
			<ol>
				<li><b>Создайте сообщество</b> ВКонтакте (можно закрытое): «Сообщества» → «Создать сообщество» → тип «Группа по интересам», любое название, например «Мои роутеры».</li>
				<li>В сообществе: <b>Управление → Сообщения</b> → «Сообщения сообщества» — <b>Включены</b>.</li>
				<li><b>Управление → Работа с API → Ключи доступа → Создать ключ</b>, отметьте право <b>«Сообщения сообщества»</b>, подтвердите. Скопируйте ключ и вставьте ниже.</li>
				<li><b>Куда будут приходить уведомления.</b> Администратор не может написать своему же сообществу, поэтому для себя удобнее беседа:
					<ul>
						<li><b>Управление → Сообщения → Настройки для бота</b>: «Возможности ботов» — <b>Включены</b>, отметьте <b>«Разрешать добавлять сообщество в беседы»</b>, сохраните.</li>
						<li>На главной странице сообщества появится кнопка <b>«Добавить в беседу»</b> (на телефоне — в меню «⋯»). Нажмите её и выберите <b>«Создать беседу»</b>, например «Роутеры». Можно сразу добавить туда членов семьи — уведомления увидят все.</li>
						<li>Если беседа не находится — <b>упомяните сообщество</b> в беседе: напишите «@», выберите сообщество из списка и отправьте. Обычные сообщения бот в беседе не видит.</li>
					</ul>
					Для других людей (не администраторов) достаточно, чтобы они просто написали сообществу в личные сообщения.</li>
				<li>Нажмите «Найти, кто написал», отметьте беседу (или людей) и сохраните. Затем «Отправить тестовое».</li>
			</ol>
		</details>

		<form method="post" class="row" style="margin-top:10px">
			<?= csrf_field() ?>
			<input type="password" name="token" placeholder="<?= $vk['token'] ? 'ключ сохранён (…' . h(substr($vk['token'], -4)) . ') — вставьте новый, чтобы заменить' : 'ключ доступа сообщества' ?>" autocomplete="off" style="flex:1;min-width:200px">
			<button name="action" value="save_token" class="primary">Сохранить ключ</button>
		</form>

		<?php if ($vk['token']): ?>
			<form method="post" style="margin-top:10px"><?= csrf_field() ?><button name="action" value="find">🔍 Найти, кто написал сообществу</button></form>

			<?php if ($found): ?>
				<form method="post" style="margin-top:10px">
					<?= csrf_field() ?>
					<p style="margin-bottom:4px">Кому присылать уведомления:</p>
					<?php $chosen = array_column($vk['peers'], 'id'); foreach ($found as $p): ?>
						<label style="display:block"><input type="checkbox" name="peer[]" value="<?= (int)$p['id'] ?>" <?= in_array($p['id'], $chosen, true) ? 'checked' : '' ?>> <?= h($p['name']) ?></label>
						<input type="hidden" name="name[<?= (int)$p['id'] ?>]" value="<?= h($p['name']) ?>">
					<?php endforeach; ?>
					<p><button name="action" value="save_peers" class="primary">Сохранить получателей</button></p>
				</form>
			<?php endif; ?>

			<div class="row" style="margin-top:10px">
				<form method="post"><?= csrf_field() ?><button name="action" value="test" <?= $vk['peers'] ? '' : 'disabled' ?>>✉️ Отправить тестовое</button></form>
				<form method="post"><?= csrf_field() ?><button name="action" value="toggle" <?= $vk['peers'] ? '' : 'disabled' ?>><?= $vk['enabled'] ? '🔕 Выключить' : '🔔 Включить' ?></button></form>
				<form method="post" onsubmit="return confirm('Удалить ключ и получателей?')"><?= csrf_field() ?><button name="action" value="forget" class="danger">Удалить ключ</button></form>
			</div>
		<?php endif; ?>
	</div>

	<div class="card">
		<h2>🔐 Двухфакторная защита (Google Authenticator)</h2>
		<?php if ($newCodes): ?>
			<div class="notice">
				<b>Резервные коды</b> — на случай, если телефона не будет под рукой. Каждый действует один раз. Сохраните их в менеджер паролей или запишите; больше они не покажутся.
				<pre class="cmd" style="font-size:15px"><?= h(implode("\n", $newCodes)) ?></pre>
			</div>
		<?php endif; ?>

		<?php if ($totp['enabled']): ?>
			<p>Состояние: <b style="color:#22a06b">включена</b>. Код спрашивается при входе с нового устройства; на проверенном устройстве — раз в <?= TRUST_DAYS ?> дней. Резервных кодов осталось: <?= count($totp['backup']) ?>.</p>
			<form method="post" class="row">
				<?= csrf_field() ?>
				<input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="код из приложения" style="width:160px">
				<button name="action" value="totp_backup">Новые резервные коды</button>
				<button name="action" value="totp_off" class="danger" onclick="return confirm('Выключить двухфакторную защиту?')">Выключить</button>
			</form>
		<?php elseif (!empty($_SESSION['totp_new'])): $sec = $_SESSION['totp_new']; ?>
			<ol>
				<li>Установите <b>Google Authenticator</b> (или Яндекс Ключ и др.) на телефон.</li>
				<li>
					На телефоне можно просто нажать: <a class="btn" href="<?= h(totp_uri($sec)) ?>">📲 Добавить в приложение</a><br>
					Или в приложении: «+» → «Ввести ключ настройки» → название «Роутеры», ключ:
					<pre class="cmd" style="font-size:16px;letter-spacing:2px"><?= h(trim(chunk_split($sec, 4, ' '))) ?></pre>
					тип — «По времени».
				</li>
				<li>Введите 6-значный код, который покажет приложение:</li>
			</ol>
			<form method="post" class="row">
				<?= csrf_field() ?>
				<input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" style="width:140px;font-size:18px;letter-spacing:3px">
				<button name="action" value="totp_confirm" class="primary">Включить</button>
			</form>
		<?php else: ?>
			<p>Состояние: <b>выключена</b>. С ней для входа с нового устройства нужен не только пароль, но и код из телефона — украденного пароля станет недостаточно.</p>
			<form method="post"><?= csrf_field() ?><button name="action" value="totp_start" class="primary">Настроить</button></form>
		<?php endif; ?>
	</div>

	<div class="card">
		<h2>💻 Устройства, с которых входили</h2>
		<?php $myKey = hash('sha256', (string)($_COOKIE[DEVICE_COOKIE] ?? '')); $devs = devices(); uasort($devs, fn($a, $b) => $b['last'] <=> $a['last']); ?>
		<?php foreach ($devs as $k => $d): ?>
			<div class="item">
				<b><?= h($d['ua']) ?></b><?= $k === $myKey ? ' <span class="muted">— это устройство</span>' : '' ?><br>
				<span class="muted">последний вход <?= date('d.m.Y H:i', (int)$d['last']) ?> с IP <?= h($d['ip']) ?> · впервые <?= date('d.m.Y', (int)$d['first']) ?>
					<?= (int)$d['trusted_until'] > time() ? ' · код не спрашивается до ' . date('d.m', (int)$d['trusted_until']) : '' ?></span>
			</div>
		<?php endforeach; ?>
		<?php if (!$devs): ?><p class="muted">Пока пусто — список появится после следующего входа.</p><?php endif; ?>
		<form method="post" style="margin-top:10px" onsubmit="return confirm('Забыть все устройства и завершить все остальные входы?')">
			<?= csrf_field() ?><button name="action" value="devices_forget" class="danger">Забыть все устройства и выйти везде</button>
		</form>
		<p class="muted">Нажмите, если подозреваете, что пароль украли: все входы, кроме этого, завершатся. Затем смените пароль панели.</p>
	</div>

	<div class="card">
		<h2>💾 Резервные копии базы</h2>
		<p class="muted">Делаются автоматически раз в сутки (если на хостинге настроен cron), хранятся 14 последних. В копии — роутеры, настройки панели и ключ сообщества ВК, поэтому храните скачанные копии в надёжном месте.</p>
		<?php $bk = glob(data_dir() . '/backups/routers-*.sqlite') ?: []; rsort($bk); ?>
		<?php foreach ($bk as $f): ?>
			<div><a href="settings.php?backup=<?= urlencode(basename($f)) ?>">💾 <?= h(basename($f)) ?></a> <span class="muted"><?= round(filesize($f) / 1024) ?> КБ</span></div>
		<?php endforeach; ?>
		<?php if (!$bk): ?><p class="muted">Копий пока нет — первая появится при ближайшем запуске cron.</p><?php endif; ?>
	</div>
<?php
panel_footer();
