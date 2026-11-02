<?php
// Настройки панели: уведомления во ВКонтакте.

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
				if (!$found) {
					$msg = ['err', 'Сообщество пока не видит ни одной переписки. Добавьте его в беседу (см. инструкцию) или попросите кого-то написать ему, и нажмите кнопку снова.'];
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
				$vk['peers'] = $peers;
				$vk['enabled'] = (bool)$peers;
				vk_save($vk);
				$msg = ['ok', $peers ? 'Получатели сохранены, уведомления включены.' : 'Получателей нет — уведомления во ВКонтакте выключены.'];
				break;

			case 'toggle':
				$vk['enabled'] = !$vk['enabled'] && $vk['peers'];
				vk_save($vk);
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
				vk_save(['token' => '', 'group' => '', 'peers' => [], 'enabled' => false]);
				$vk = vk_settings();
				$msg = ['ok', 'Ключ и получатели удалены.'];
				break;
		}
	}
}

$lastErr = kv_get('vk_last_error');

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
						<li>Напишите в беседу любое сообщение — так сообщество её «увидит».</li>
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
<?php
panel_footer();
