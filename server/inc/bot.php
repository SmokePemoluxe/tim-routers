<?php
// Telegram-бот: экраны и обработка нажатий/сообщений.

function is_admin($id): bool
{
	return in_array((int)$id, array_map('intval', cfg('admin_ids', [])), true);
}

function state_get(int $uid): array
{
	return kv_get("state_$uid", []);
}

function state_set(int $uid, array $s): void
{
	kv_set("state_$uid", $s);
}

function state_clear(int $uid): void
{
	kv_del("state_$uid");
}

function cancel_kb(string $back = 'cx'): array
{
	return kb([[['✖️ Отмена', $back]]]);
}

// ---------- Экраны: каждый возвращает [текст, клавиатура] ----------

function screen_main(): array
{
	$routers = rows("SELECT * FROM routers WHERE status = 'active' AND user_id = 0");
	$cnt = ['🟢' => 0, '🔴' => 0, '⚫' => 0];
	foreach ($routers as $r) {
		$icon = router_icon($r);
		$cnt[$icon] = ($cnt[$icon] ?? 0) + 1;
	}
	$pending = (int)val("SELECT COUNT(*) FROM routers WHERE status = 'pending' AND user_id = 0");

	$text = "🛰 <b>Мои роутеры</b>\n\n";
	if (!$routers) {
		$text .= "Роутеров пока нет. Установите агент на роутер — он появится здесь для подтверждения.";
	} else {
		$text .= "🟢 в порядке: {$cnt['🟢']}\n🔴 есть проблемы: {$cnt['🔴']}\n⚫ не на связи: {$cnt['⚫']}";
	}
	$rows = [[['📋 Роутеры', 'rl']]];
	if ($pending) {
		$rows[] = [["🆕 Ждут подтверждения ($pending)", 'pl']];
	}
	$rows[] = [['➕ Добавить роутер', 'add']];
	$rows[] = [['⬆️ Обновить скрипты на всех', 'upa']];
	$rows[] = [['🔄 Обновить экран', 'm']];
	return [$text, kb($rows)];
}

// Как подключить новый роутер: адрес сервера выдаётся здесь, в скриптах его нет
function screen_add_router(): array
{
	$text = "➕ <b>Как подключить новый роутер</b>\n\n" .
		"<b>1.</b> Зайти на роутер по SSH: <code>ssh root@192.168.1.1</code>\n\n" .
		"<b>2.</b> Вставить команду (поставит podkop, если его нет, сторожа и агента):\n" .
		'<code>' . h(install_command()) . "</code>\n\n" .
		"<b>3.</b> Через 1–3 минуты сюда придёт «🆕 Новый роутер просится в систему» — примите его и дайте имя.\n\n" .
		"<i>Адрес сервера не зашит в скрипты и не попадает на GitHub — передавайте эту команду только тем, кому доверяете.</i>";
	return [$text, kb([[['⬅️ Назад', 'm']]])];
}

function screen_routers(): array
{
	$routers = rows("SELECT * FROM routers WHERE status = 'active' AND user_id = 0 ORDER BY name COLLATE NOCASE");
	if (!$routers) {
		return ['Роутеров пока нет.', kb([[['⬅️ Назад', 'm']]])];
	}
	$rows = [];
	foreach ($routers as $r) {
		$rows[] = [[router_icon($r) . ' ' . $r['name'], 'r:' . $r['id']]];
	}
	$rows[] = [['⬅️ Назад', 'm']];
	return ["📋 <b>Роутеры</b>\n🟢 в порядке · 🔴 проблема · ⚫ не на связи", kb($rows)];
}

function screen_pending(): array
{
	$list = rows("SELECT * FROM routers WHERE status = 'pending' AND user_id = 0 ORDER BY id");
	if (!$list) {
		return ['Новых роутеров нет.', kb([[['⬅️ Назад', 'm']]])];
	}
	$text = "🆕 <b>Ждут подтверждения</b>\n";
	$rows = [];
	foreach ($list as $r) {
		$text .= "\n<b>#{$r['id']} " . h($r['name']) . "</b>\n" . router_hw_line(rinfo($r)) . "\n" . router_mac_line(rinfo($r)) . "\nIP: " . h($r['ip']) . ' · ' . ago($r['created_at']) . "\n";
		$rows[] = [['✅ Принять #' . $r['id'], 'ac:' . $r['id']], ['❌ Отклонить #' . $r['id'], 'rj:' . $r['id']]];
	}
	$rows[] = [['⬅️ Назад', 'm']];
	return [$text, kb($rows)];
}

function screen_router(int $rid): array
{
	$r = get_router($rid);
	if (!$r || !in_array($r['status'], ['active', 'pending'], true)) {
		return ['Роутер не найден или удалён.', kb([[['⬅️ К списку', 'rl']]])];
	}
	$info = rinfo($r);
	$sections = router_sections($rid);
	$byName = by_name($sections);

	$text = router_icon($r) . ' <b>' . h($r['name']) . "</b>\n" . router_hw_line($info) . "\n";
	if ($mac = router_mac_line($info)) {
		$text .= $mac . "\n";
	}
	$text .= 'На связи: ' . ago($r['last_seen']);
	if (!empty($info['uptime'])) {
		$text .= ' · работает ' . dur((int)$info['uptime']);
	}
	if (isset($info['mem_free'])) {
		$text .= ' · свободно памяти ' . (int)$info['mem_free'] . '%';
	}
	$text .= "\n";
	if (isset($info['singbox_running']) && !$info['singbox_running']) {
		$text .= "⚠️ <b>sing-box не запущен</b> — podkop не работает\n";
	}

	$text .= "\n<b>Секции:</b>\n";
	foreach ($sections as $s) {
		$text .= section_line($s, $byName) . "\n";
	}
	if (!$sections) {
		$text .= "нет данных\n";
	}

	$wd = $info['watchdog'] ?? [];
	if (!empty($wd['installed'])) {
		$text .= "\n<b>Сторож</b>";
		if (!empty($wd['reboots_row'])) {
			$text .= ' (перезагрузок подряд: ' . (int)$wd['reboots_row'] . ')';
		}
		$text .= ":\n";
		$hist = array_slice($wd['history'] ?? [], -4);
		foreach ($hist as $line) {
			$line = preg_replace('/^\d{4}-(\d\d-\d\d \d\d:\d\d):\d\d \[uptime \d+s\] /', '$1 ', (string)$line);
			$text .= '• ' . h(mb_substr($line, 0, 120)) . "\n";
		}
		if (!$hist) {
			$text .= "событий нет\n";
		}
	}

	$tasks = pending_tasks($rid);
	if ($tasks) {
		$text .= "\n⏳ <b>Ждут отправки на роутер:</b>\n";
		foreach ($tasks as $t) {
			$text .= '• ' . h(task_label($t['type'], json_decode($t['payload'], true) ?: [])) . "\n";
		}
	}
	if (is_muted($r)) {
		$text .= "\n🔕 Уведомления выключены до " . date('d.m H:i', (int)$r['mute_until']);
	}

	$rows = [];
	$line = [];
	foreach ($sections as $s) {
		$line[] = ['⚙️ ' . $s['name'], 's:' . $s['id']];
		if (count($line) === 2) {
			$rows[] = $line;
			$line = [];
		}
	}
	if ($line) {
		$rows[] = $line;
	}
	$rows[] = [['➕ Новая секция', 'ns:' . $rid], ['📄 Отчёт', 'rep:' . $rid]];
	$rows[] = [[is_muted($r) ? '🔔 Включить уведомления' : '🔕 Тишина 24ч', 'mu:' . $rid], ['✏️ Переименовать', 'rn:' . $rid]];
	$rows[] = [['⬆️ Обновить скрипты', 'up:' . $rid], ['🗑 Удалить', 'rd:' . $rid]];
	$rows[] = [['🔄 Обновить', 'r:' . $rid], ['⬅️ К списку', 'rl']];
	return [$text, kb($rows)];
}

function screen_section(int $sid): array
{
	$s = get_section($sid);
	if (!$s) {
		return ['Секция не найдена (возможно, удалена на роутере).', kb([[['⬅️ К списку', 'rl']]])];
	}
	$r = get_router((int)$s['router_id']);
	$d = sdata($s);
	$byName = by_name(router_sections((int)$r['id']));

	$text = '⚙️ <b>' . h($s['name']) . '</b> · роутер <b>' . h($r['name']) . "</b>\n\n";
	$text .= 'Тип: ' . h(section_kind($d)) . "\n";
	if (!empty($d['detour'])) {
		$text .= 'Идёт через: ' . h($d['detour']) . "\n";
	}
	$text .= 'Состояние: ' . section_line($s, $byName) . "\n";
	$text .= 'Свои домены: ' . count($d['domains'] ?? []) . "\n";
	$text .= 'Готовые списки: ' . h(implode(', ', $d['community'] ?? []) ?: 'нет') . "\n";
	if (!empty($d['remote'])) {
		$text .= 'Внешние списки: ' . count($d['remote']) . "\n";
	}

	$rows = [];
	if (key_changeable($d)) {
		$rows[] = [['🔑 Сменить ключ', 'sk:' . $sid]];
	}
	$rows[] = [['📝 Домены (' . count($d['domains'] ?? []) . ')', 'sd:' . $sid], ['📚 Готовые списки', 'sc:' . $sid]];
	$rows[] = [['🗑 Удалить секцию', 'sx:' . $sid]];
	$rows[] = [['⬅️ К роутеру', 'r:' . $r['id']]];
	return [$text, kb($rows)];
}

function screen_domains(int $sid): array
{
	$s = get_section($sid);
	if (!$s) {
		return ['Секция не найдена.', kb([[['⬅️ К списку', 'rl']]])];
	}
	$d = sdata($s);
	$domains = $d['domains'] ?? [];
	$text = '📝 <b>Домены секции ' . h($s['name']) . '</b> (' . count($domains) . ")\n\n";
	$text .= $domains ? '<code>' . h(implode("\n", $domains)) . '</code>' : 'Список пуст.';
	return [$text, kb([
		[['➕ Добавить', 'sa:' . $sid], ['➖ Убрать', 'sr:' . $sid]],
		[['⬅️ К секции', 's:' . $sid]],
	])];
}

function screen_community(int $sid, array $draft): array
{
	$s = get_section($sid);
	if (!$s) {
		return ['Секция не найдена.', kb([[['⬅️ К списку', 'rl']]])];
	}
	$current = sdata($s)['community'] ?? [];
	$all = array_values(array_unique(array_merge(KNOWN_LISTS, $current, $draft)));
	$text = '📚 <b>Готовые списки — ' . h($s['name']) . "</b>\n\nНажимайте, чтобы включить ✅ или выключить ▫️, затем «Применить».";
	$rows = [];
	$line = [];
	foreach ($all as $name) {
		$line[] = [(in_array($name, $draft, true) ? '✅ ' : '▫️ ') . $name, 'ct:' . $sid . ':' . $name];
		if (count($line) === 2) {
			$rows[] = $line;
			$line = [];
		}
	}
	if ($line) {
		$rows[] = $line;
	}
	$rows[] = [['💾 Применить', 'ca:' . $sid], ['✖️ Отмена', 's:' . $sid]];
	return [$text, kb($rows)];
}

function screen_confirm(string $question, string $yes, string $no): array
{
	return [$question, kb([[['✅ Да', $yes], ['✖️ Нет', $no]]])];
}

// Все секции, в которых можно сменить ключ: сначала неработающие
function key_targets(): array
{
	$dead = [];
	$alive = [];
	foreach (rows("SELECT s.*, r.name AS rname FROM sections s JOIN routers r ON r.id = s.router_id WHERE r.status = 'active' AND r.user_id = 0 ORDER BY r.name COLLATE NOCASE, s.pos") as $s) {
		if (!key_changeable(sdata($s))) {
			continue;
		}
		if (section_failed($s)) {
			$dead[] = $s;
		} else {
			$alive[] = $s;
		}
	}
	return [$dead, $alive];
}

// Прислали сообщение с ключом (например, переслали из бота ImpVPN) — куда его применить?
function screen_key_target(string $key, bool $showAll = false): array
{
	[$dead, $alive] = key_targets();
	$text = "🔑 Нашёл ключ: <code>" . h(strtok($key, ':')) . '://…@' . h(key_host($key)) . "</code>\n\n";
	if (count($dead) === 1 && !$showAll) {
		$s = $dead[0];
		return [
			$text . 'Сейчас не работает <b>' . h($s['name']) . '</b> на <b>' . h($s['rname']) . "</b>. Применить ключ туда?",
			kb([
				[['✅ Да, применить', 'fk:' . $s['id']]],
				[['🔀 Выбрать другую секцию', 'fl']],
				[['✖️ Отмена', 'cx']],
			]),
		];
	}
	if (!$dead && !$alive) {
		return [$text . 'Нет секций, в которых можно сменить ключ.', kb([[['✖️ Отмена', 'cx']]])];
	}
	$text .= $dead ? 'В какую секцию его отправить? 🔴 — сейчас не работают.' : 'Все секции сейчас работают. В какую отправить ключ?';
	$rows = [];
	foreach (array_slice(array_merge($dead, $alive), 0, 40) as $s) {
		$rows[] = [[section_icon($s) . ' ' . $s['rname'] . ' → ' . $s['name'], 'fk:' . $s['id']]];
	}
	$rows[] = [['✖️ Отмена', 'cx']];
	return [$text, kb($rows)];
}

// Поставить в очередь смену ключа (с переводом в цепочку, если секция в ней)
function queue_key_change($chat, array $s, string $key): bool
{
	[$payload, $err] = key_task_payload($s, $key);
	if ($payload === null) {
		send($chat, '❌ ' . h($s['name']) . ': ' . h($err));
		return false;
	}
	queue_task((int)$s['router_id'], 'set_key', $payload);
	send($chat, '⏳ Ключ для <b>' . h($s['name']) . "</b> поставлен в очередь. Роутер заберёт его в течение ~2 минут, проверит и сообщит результат.\n\n<i>Сообщение с ключом можно удалить из чата.</i>");
	return true;
}

// Весь текст сообщения, где может быть ключ: текст, подпись, ссылки-«кнопки»
function message_text(array $m): string
{
	$parts = [(string)($m['text'] ?? ''), (string)($m['caption'] ?? '')];
	foreach (array_merge($m['entities'] ?? [], $m['caption_entities'] ?? []) as $e) {
		if (($e['type'] ?? '') === 'text_link' && !empty($e['url'])) {
			$parts[] = (string)$e['url'];
		}
	}
	return implode("\n", $parts);
}

// ---------- Обработка ----------

function handle_update(array $u): void
{
	if (isset($u['callback_query'])) {
		$cq = $u['callback_query'];
		$uid = (int)$cq['from']['id'];
		if (!is_admin($uid)) {
			tg('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => 'Нет доступа']);
			return;
		}
		$chat = $cq['message']['chat']['id'] ?? $uid;
		$mid = $cq['message']['message_id'] ?? null;
		$toast = on_callback($uid, $chat, $mid, (string)($cq['data'] ?? ''));
		tg('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => $toast ?? '']);
		return;
	}

	$m = $u['message'] ?? null;
	if (!$m) {
		return;
	}
	$uid = (int)($m['from']['id'] ?? 0);
	$chat = $m['chat']['id'];
	if (!is_admin($uid)) {
		return;
	}
	$full = message_text($m);
	$text = trim((string)($m['text'] ?? $m['caption'] ?? ''));
	if ($text !== '' && $text[0] === '/') {
		state_clear($uid);
		show($chat, null, screen_main());
		return;
	}
	$st = state_get($uid);
	if (!empty($st['await'])) {
		on_text($uid, $chat, $st['await'] === 'key' || $st['await'] === 'ns_key' ? $full : $text, $st);
		return;
	}
	// Сообщение с ключом (например, пересланное из бота ImpVPN) — предложить, куда его применить
	$key = extract_key($full);
	if ($key !== null && valid_key($key)) {
		state_set($uid, ['pending_key' => $key]);
		show($chat, null, screen_key_target($key));
		return;
	}
	if ($text === '' && $full === '') {
		return;
	}
	show($chat, null, screen_main());
}

function on_callback(int $uid, $chat, ?int $mid, string $data): ?string
{
	$p = explode(':', $data);
	$cmd = $p[0];
	$id = (int)($p[1] ?? 0);

	switch ($cmd) {
		case 'm':
		case 'cx':
			state_clear($uid);
			show($chat, $mid, screen_main());
			return $cmd === 'cx' ? 'Отменено' : null;

		case 'rl':
			show($chat, $mid, screen_routers());
			return null;

		case 'pl':
			show($chat, $mid, screen_pending());
			return null;

		case 'add':
			show($chat, $mid, screen_add_router());
			return null;

		case 'r':
			state_clear($uid);
			show($chat, $mid, screen_router($id));
			return null;

		case 's':
			state_clear($uid);
			show($chat, $mid, screen_section($id));
			return null;

		case 'sd':
			show($chat, $mid, screen_domains($id));
			return null;

		case 'sk':
		case 'sa':
		case 'sr':
			$s = get_section($id);
			if (!$s) {
				return 'Секция не найдена';
			}
			$r = get_router((int)$s['router_id']);
			$prompts = [
				'sk' => ['key', '🔑 Пришлите новый ключ для секции <b>' . h($s['name']) . '</b> роутера <b>' . h($r['name']) . "</b>.\n\nВставьте ссылку (hy2://…, vless://… и т.п.) или просто перешлите сообщение с ней из бота ImpVPN."],
				'sa' => ['add_domains', '➕ Пришлите домены для добавления в <b>' . h($s['name']) . "</b>.\nМожно несколько: через пробел, запятую или с новой строки."],
				'sr' => ['rm_domains', '➖ Пришлите домены, которые убрать из <b>' . h($s['name']) . '</b>.'],
			];
			state_set($uid, ['await' => $prompts[$cmd][0], 'sid' => $id]);
			show($chat, $mid, [$prompts[$cmd][1], cancel_kb('s:' . $id)]);
			return null;

		case 'sc':
			$s = get_section($id);
			if (!$s) {
				return 'Секция не найдена';
			}
			$draft = sdata($s)['community'] ?? [];
			state_set($uid, ['draft_sid' => $id, 'draft' => $draft]);
			show($chat, $mid, screen_community($id, $draft));
			return null;

		case 'ct':
			$st = state_get($uid);
			$name = (string)($p[2] ?? '');
			if (($st['draft_sid'] ?? 0) !== $id || !preg_match('/^[a-z0-9_]{1,30}$/', $name)) {
				return 'Откройте списки заново';
			}
			$draft = $st['draft'];
			$draft = in_array($name, $draft, true) ? array_values(array_diff($draft, [$name])) : array_merge($draft, [$name]);
			$st['draft'] = $draft;
			state_set($uid, $st);
			show($chat, $mid, screen_community($id, $draft));
			return null;

		case 'ca':
			$st = state_get($uid);
			$s = get_section($id);
			if (!$s || ($st['draft_sid'] ?? 0) !== $id) {
				return 'Откройте списки заново';
			}
			queue_task((int)$s['router_id'], 'set_community', ['section' => $s['name'], 'lists' => array_values($st['draft'])]);
			state_clear($uid);
			show($chat, $mid, screen_router((int)$s['router_id']));
			return 'Отправлено на роутер';

		case 'sx':
			$s = get_section($id);
			if (!$s) {
				return 'Секция не найдена';
			}
			show($chat, $mid, screen_confirm('🗑 Удалить секцию <b>' . h($s['name']) . '</b> на роутере? Её домены перестанут обрабатываться.', 'sxy:' . $id, 's:' . $id));
			return null;

		case 'sxy':
			$s = get_section($id);
			if (!$s) {
				return 'Секция не найдена';
			}
			queue_task((int)$s['router_id'], 'delete_section', ['section' => $s['name']]);
			show($chat, $mid, screen_router((int)$s['router_id']));
			return 'Отправлено на роутер';

		case 'ns':
			if (!get_router($id)) {
				return 'Роутер не найден';
			}
			state_set($uid, ['await' => 'ns_name', 'rid' => $id]);
			show($chat, $mid, ["➕ <b>Новая секция</b>\n\nПришлите имя секции латиницей, без пробелов (например <code>Office</code> или <code>VPN2</code>).", cancel_kb('r:' . $id)]);
			return null;

		case 'nst':
			$st = state_get($uid);
			if (($st['await'] ?? '') !== 'ns_type') {
				return 'Начните заново';
			}
			$st['ctype'] = $p[1] === 'p' ? 'proxy' : 'exclusion';
			if ($st['ctype'] === 'proxy') {
				$st['await'] = 'ns_key';
				state_set($uid, $st);
				show($chat, $mid, ['🔑 Пришлите ключ (ссылку) для секции <b>' . h($st['name']) . '</b>.', cancel_kb('r:' . $st['rid'])]);
			} else {
				$st['await'] = 'ns_domains';
				state_set($uid, $st);
				show($chat, $mid, ns_domains_prompt($st));
			}
			return null;

		case 'nsd':
			$st = state_get($uid);
			if (($st['await'] ?? '') !== 'ns_domains') {
				return 'Начните заново';
			}
			create_section_task($uid, $chat, $st, []);
			return null;

		case 'rep':
			if (!get_router($id)) {
				return 'Роутер не найден';
			}
			queue_task($id, 'upload_report');
			show($chat, $mid, screen_router($id));
			return 'Роутер пришлёт отчёт в ближайшие минуты';

		case 'mu':
			$r = get_router($id);
			if (!$r) {
				return 'Роутер не найден';
			}
			$until = is_muted($r) ? 0 : time() + 86400;
			q('UPDATE routers SET mute_until = ? WHERE id = ?', [$until, $id]);
			event($id, $until ? 'Уведомления выключены на 24 ч' : 'Уведомления включены');
			show($chat, $mid, screen_router($id));
			return $until ? 'Тишина на 24 часа' : 'Уведомления включены';

		case 'rn':
			$r = get_router($id);
			if (!$r) {
				return 'Роутер не найден';
			}
			state_set($uid, ['await' => 'rename', 'rid' => $id]);
			show($chat, $mid, ['✏️ Пришлите новое имя для <b>' . h($r['name']) . '</b>.', cancel_kb('r:' . $id)]);
			return null;

		case 'rd':
			$r = get_router($id);
			if (!$r) {
				return 'Роутер не найден';
			}
			show($chat, $mid, screen_confirm('🗑 Удалить <b>' . h($r['name']) . "</b> из системы?\n\nАгент на роутере перестанет выходить на связь. Чтобы вернуть роутер, агент нужно будет переустановить.", 'rdy:' . $id, 'r:' . $id));
			return null;

		case 'rdy':
			q("UPDATE routers SET status = 'removed' WHERE id = ?", [$id]);
			q('DELETE FROM sections WHERE router_id = ?', [$id]);
			q("DELETE FROM tasks WHERE router_id = ? AND status IN ('queued', 'sent')", [$id]);
			event($id, 'Роутер удалён из системы');
			show($chat, $mid, screen_routers());
			return 'Удалён';

		case 'ac':
			$r = get_router($id);
			if (!$r || $r['status'] !== 'pending') {
				return 'Уже обработан';
			}
			state_set($uid, ['await' => 'accept_name', 'rid' => $id]);
			show($chat, $mid, ['✅ Как назвать этот роутер? Например: <i>Офис Ленина</i> или <i>Дом Иванова</i>.', cancel_kb('pl')]);
			return null;

		case 'rj':
			$r = get_router($id);
			if (!$r || $r['status'] !== 'pending') {
				return 'Уже обработан';
			}
			q("UPDATE routers SET status = 'rejected' WHERE id = ?", [$id]);
			event($id, 'Отклонён');
			show($chat, $mid, screen_pending());
			return 'Отклонён';

		case 'up':
		case 'upa':
			state_set($uid, ['await' => 'upd_ref', 'rid' => $cmd === 'up' ? $id : 0]);
			show($chat, $mid, [
				"⬆️ <b>Обновление скриптов</b>" . ($cmd === 'up' ? '' : ' на <b>всех</b> роутерах') .
				"\n\nПришлите версию с GitHub (например <code>v1.1</code>) или <code>main</code> — последняя версия.",
				cancel_kb($cmd === 'up' ? 'r:' . $id : 'm'),
			]);
			return null;

		case 'fk':
			$st = state_get($uid);
			$s = get_section($id);
			if (empty($st['pending_key'])) {
				return 'Ключ устарел — перешлите его ещё раз';
			}
			if (!$s) {
				return 'Секция не найдена';
			}
			if (queue_key_change($chat, $s, $st['pending_key'])) {
				state_clear($uid);
				show($chat, $mid, screen_router((int)$s['router_id']));
				return 'Отправлено на роутер';
			}
			return null;

		case 'fl':
			$st = state_get($uid);
			if (empty($st['pending_key'])) {
				return 'Ключ устарел — перешлите его ещё раз';
			}
			show($chat, $mid, screen_key_target($st['pending_key'], true));
			return null;

		case 'kf':
			$t = row('SELECT * FROM tasks WHERE id = ?', [$id]);
			if (!$t || $t['type'] !== 'set_key') {
				return 'Задача не найдена';
			}
			$payload = json_decode($t['payload'], true) ?: [];
			$payload['force'] = true;
			queue_task((int)$t['router_id'], 'set_key', $payload);
			show($chat, $mid, screen_router((int)$t['router_id']));
			return 'Ключ будет применён без проверки';
	}
	return null;
}

function ns_domains_prompt(array $st): array
{
	return [
		'📝 Пришлите домены для секции <b>' . h($st['name']) . "</b> (через пробел, запятую или с новой строки).\n\nИли нажмите «Без доменов» — добавите потом.",
		kb([[['Без доменов', 'nsd']], [['✖️ Отмена', 'r:' . $st['rid']]]]),
	];
}

function create_section_task(int $uid, $chat, array $st, array $domains): void
{
	queue_task((int)$st['rid'], 'create_section', [
		'name' => $st['name'],
		'ctype' => $st['ctype'],
		'key' => $st['key'] ?? '',
		'domains' => $domains,
	]);
	state_clear($uid);
	send($chat, '⏳ Секция <b>' . h($st['name']) . '</b> будет создана, когда роутер выйдет на связь (обычно в течение 2 минут).');
	show($chat, null, screen_router((int)$st['rid']));
}

function on_text(int $uid, $chat, string $text, array $st): void
{
	switch ($st['await']) {
		case 'key':
			$s = get_section((int)$st['sid']);
			if (!$s) {
				state_clear($uid);
				send($chat, 'Секция не найдена.');
				return;
			}
			$key = extract_key($text);
			if ($key === null || !valid_key($key)) {
				send($chat, '❌ Не нашёл ключ. Нужна ссылка вида <code>hy2://…</code>, <code>vless://…</code> и т.п. — можно переслать сообщение целиком. Пришлите ещё раз или нажмите «Отмена».', cancel_kb('s:' . $s['id']));
				return;
			}
			if (queue_key_change($chat, $s, $key)) {
				state_clear($uid);
				show($chat, null, screen_router((int)$s['router_id']));
			}
			return;

		case 'add_domains':
		case 'rm_domains':
			$s = get_section((int)$st['sid']);
			if (!$s) {
				state_clear($uid);
				send($chat, 'Секция не найдена.');
				return;
			}
			[$good, $bad] = parse_domains($text);
			if (!$good) {
				send($chat, '❌ Не нашёл ни одного домена. Пришлите ещё раз или нажмите «Отмена».', cancel_kb('s:' . $s['id']));
				return;
			}
			$type = $st['await'] === 'add_domains' ? 'add_domains' : 'remove_domains';
			queue_task((int)$s['router_id'], $type, ['section' => $s['name'], 'domains' => $good]);
			state_clear($uid);
			$msg = '⏳ ' . ($type === 'add_domains' ? 'Добавляю' : 'Убираю') . ': ' . h(implode(', ', $good));
			if ($bad) {
				$msg .= "\n⚠️ Пропущены (не похожи на домены): " . h(implode(', ', $bad));
			}
			send($chat, $msg);
			show($chat, null, screen_router((int)$s['router_id']));
			return;

		case 'rename':
		case 'accept_name':
			$name = mb_substr(trim(preg_replace('/\s+/u', ' ', $text)), 0, 40);
			if ($name === '') {
				send($chat, 'Имя не может быть пустым.');
				return;
			}
			$rid = (int)$st['rid'];
			if ($st['await'] === 'accept_name') {
				q("UPDATE routers SET name = ?, status = 'active' WHERE id = ? AND status = 'pending'", [$name, $rid]);
				event($rid, 'Принят в систему как «' . $name . '»');
				send($chat, '✅ Роутер принят. Полные данные появятся после его следующего выхода на связь (до 2 минут).');
			} else {
				q('UPDATE routers SET name = ? WHERE id = ?', [$name, $rid]);
				event($rid, 'Переименован в «' . $name . '»');
			}
			state_clear($uid);
			show($chat, null, screen_router($rid));
			return;

		case 'ns_name':
			$rid = (int)$st['rid'];
			if (!valid_section_name($text)) {
				send($chat, '❌ Имя: латинские буквы, цифры и _, начинается с буквы, до 30 символов. Пришлите ещё раз.', cancel_kb('r:' . $rid));
				return;
			}
			$exists = row('SELECT id FROM sections WHERE router_id = ? AND name = ?', [$rid, $text]);
			if ($exists || in_array($text, ['settings', 'main'], true)) {
				send($chat, '❌ Такая секция уже есть. Придумайте другое имя.', cancel_kb('r:' . $rid));
				return;
			}
			state_set($uid, ['await' => 'ns_type', 'rid' => $rid, 'name' => $text]);
			send($chat, 'Секция <b>' . h($text) . '</b>. Куда направлять её домены?', kb([
				[['🔐 Через прокси (нужен ключ)', 'nst:p']],
				[['↪️ Мимо прокси (напрямую)', 'nst:e']],
				[['✖️ Отмена', 'r:' . $rid]],
			]));
			return;

		case 'ns_key':
			$key = extract_key($text);
			if ($key === null || !valid_key($key)) {
				send($chat, '❌ Не нашёл ключ. Пришлите ссылку или перешлите сообщение с ней.', cancel_kb('r:' . $st['rid']));
				return;
			}
			$st['key'] = $key;
			$st['await'] = 'ns_domains';
			state_set($uid, $st);
			show($chat, null, ns_domains_prompt($st));
			return;

		case 'ns_domains':
			[$good, $bad] = parse_domains($text);
			if (!$good) {
				send($chat, '❌ Не нашёл доменов. Пришлите ещё раз или нажмите «Без доменов».');
				return;
			}
			if ($bad) {
				send($chat, '⚠️ Пропущены: ' . h(implode(', ', $bad)));
			}
			create_section_task($uid, $chat, $st, $good);
			return;

		case 'upd_ref':
			if (!preg_match('/^[A-Za-z0-9._-]{1,40}$/', $text)) {
				send($chat, '❌ Версия — например <code>v1.1</code> или <code>main</code>.');
				return;
			}
			$rid = (int)$st['rid'];
			$targets = $rid ? [$rid] : array_map('intval', array_column(rows("SELECT id FROM routers WHERE status = 'active' AND user_id = 0"), 'id'));
			foreach ($targets as $t) {
				queue_task($t, 'update', ['ref' => $text]);
			}
			state_clear($uid);
			send($chat, '⏳ Обновление до <b>' . h($text) . '</b> отправлено на роутеров: ' . count($targets) . '.');
			show($chat, null, $rid ? screen_router($rid) : screen_main());
			return;
	}
	state_clear($uid);
	show($chat, null, screen_main());
}
