<?php
// Роутеры, секции, задачи, уведомления.

// Готовые списки podkop (community_lists). Если какого-то нет в версии podkop
// на роутере — podkop просто не скачает его.
const KNOWN_LISTS = [
	'russia_inside', 'russia_outside', 'ukraine_inside', 'geoblock', 'block', 'porn', 'news',
	'anime', 'youtube', 'discord', 'meta', 'twitter', 'hdrezka', 'tiktok', 'telegram',
	'cloudflare', 'google_ai', 'google_play', 'hodca', 'roblox', 'hetzner', 'ovh',
	'digitalocean', 'cloudfront',
];

const KEY_SCHEMES = 'hy2|hysteria2|vless|vmess|ss|trojan|tuic|socks4|socks5|anytls';

// Откуда роутеры берут скрипты (установка и обновления)
const GITHUB_REPO = 'SmokePemoluxe/tim-routers';

// Команда установки на новый роутер — с адресом этого сервера
function install_command(): string
{
	return 'wget -O /tmp/tr-install.sh https://raw.githubusercontent.com/' . GITHUB_REPO . '/main/router/install.sh && sh /tmp/tr-install.sh '
		. rtrim((string)cfg('base_url'), '/');
}

// ---------- Форматирование ----------

function dur(int $s): string
{
	$s = max(0, $s);
	if ($s < 60) {
		return $s . ' с';
	}
	if ($s < 3600) {
		return intdiv($s, 60) . ' мин';
	}
	if ($s < 86400) {
		return intdiv($s, 3600) . ' ч ' . intdiv($s % 3600, 60) . ' мин';
	}
	return intdiv($s, 86400) . ' д ' . intdiv($s % 86400, 3600) . ' ч';
}

function ago($ts): string
{
	return $ts ? dur(time() - (int)$ts) . ' назад' : 'никогда';
}

// ---------- Роутеры и секции ----------

function get_router(int $id): ?array
{
	return row('SELECT * FROM routers WHERE id = ?', [$id]);
}

function get_section(int $id): ?array
{
	return row('SELECT * FROM sections WHERE id = ?', [$id]);
}

function router_sections(int $routerId): array
{
	return rows('SELECT * FROM sections WHERE router_id = ? ORDER BY pos, id', [$routerId]);
}

function rinfo(array $r): array
{
	return json_decode($r['info'], true) ?: [];
}

function sdata(array $s): array
{
	return json_decode($s['data'], true) ?: [];
}

function is_online(array $r): bool
{
	return $r['last_seen'] && time() - (int)$r['last_seen'] <= (int)cfg('offline_after', 900);
}

function is_muted(array $r): bool
{
	return (int)$r['mute_until'] > time();
}

function section_failed(array $s): bool
{
	return $s['ok'] !== null && (int)$s['ok'] === 0;
}

function router_icon(array $r): string
{
	if ($r['status'] === 'pending') {
		return '🆕';
	}
	if (!is_online($r)) {
		return '⚫';
	}
	foreach (router_sections((int)$r['id']) as $s) {
		if (section_failed($s)) {
			return '🔴';
		}
	}
	return '🟢';
}

function section_icon(array $s): string
{
	if ($s['ok'] === null) {
		return '⚪';
	}
	return (int)$s['ok'] ? '🟢' : '🔴';
}

function by_name(array $sections): array
{
	$m = [];
	foreach ($sections as $s) {
		$m[$s['name']] = $s;
	}
	return $m;
}

function section_kind(array $d): string
{
	$type = $d['type'] ?? '';
	if ($type === 'exclusion') {
		return 'мимо прокси';
	}
	if ($type === 'proxy') {
		$cfgType = ['url' => 'ссылка', 'outbound' => 'outbound JSON', 'urltest' => 'несколько ссылок'][$d['config'] ?? ''] ?? ($d['config'] ?? '');
		return 'через прокси (' . $cfgType . ')';
	}
	return $type ?: '?';
}

// Строка секции для карточки роутера и уведомлений
function section_line(array $s, array $byName = []): string
{
	$d = sdata($s);
	$t = section_icon($s) . ' <b>' . h($s['name']) . '</b> — ';
	if (($d['type'] ?? '') === 'exclusion') {
		return $t . 'мимо прокси';
	}
	$t .= h($d['scheme'] ?? '?');
	if (!empty($d['host'])) {
		$t .= ' ' . h($d['host']);
	}
	if ($s['ok'] !== null && (int)$s['ok'] === 1) {
		$t .= ' · ' . (int)($d['delay'] ?? 0) . ' мс';
	} elseif (section_failed($s)) {
		$t .= ' · <b>не работает</b>' . ($s['fail_since'] ? ' ' . dur(time() - (int)$s['fail_since']) : '');
		$det = $d['detour'] ?? '';
		if ($det !== '' && isset($byName[$det]) && section_failed($byName[$det])) {
			$t .= ' (идёт через ' . h($det) . ', а он тоже не работает)';
		} elseif (!empty($d['error'])) {
			$t .= ' (' . h(mb_substr((string)$d['error'], 0, 80)) . ')';
		}
	}
	return $t;
}

function router_mac_line(array $info): string
{
	$parts = [];
	if (!empty($info['mac_wan'])) {
		$parts[] = 'WAN ' . $info['mac_wan'];
	}
	if (!empty($info['mac_lan'])) {
		$parts[] = 'LAN ' . $info['mac_lan'];
	}
	return $parts ? 'MAC: ' . h(implode(' · ', $parts)) : '';
}

// Роутер с теми же MAC уже был в системе (переустановили агент, сбросили роутер)?
function find_same_router(array $info, int $exceptId = 0): ?array
{
	foreach (['mac_wan', 'mac_lan'] as $k) {
		$mac = strtolower((string)($info[$k] ?? ''));
		if (!preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac)) {
			continue;
		}
		foreach (rows('SELECT * FROM routers WHERE id != ? AND info LIKE ? ORDER BY id DESC', [$exceptId, '%' . $mac . '%']) as $r) {
			if (strtolower((string)(rinfo($r)[$k] ?? '')) === $mac) {
				return $r;
			}
		}
	}
	return null;
}

function router_hw_line(array $info): string
{
	$parts = array_filter([
		$info['model'] ?? '',
		!empty($info['openwrt']) ? 'OpenWrt ' . $info['openwrt'] : '',
		!empty($info['podkop']) ? 'podkop ' . $info['podkop'] : '',
		!empty($info['agent']) ? 'агент ' . $info['agent'] : '',
	]);
	return h(implode(' · ', $parts));
}

// Можно ли менять ключ секции из бота: ссылка или outbound (в т.ч. цепочка)
function key_changeable(array $d): bool
{
	return ($d['type'] ?? '') === 'proxy' && in_array($d['config'] ?? '', ['url', 'outbound'], true);
}

// Задача смены ключа: для секции в цепочке (outbound) ключ переводится в outbound с тем же «через что».
// Возвращает [payload, ошибка]
function key_task_payload(array $section, string $key): array
{
	$d = sdata($section);
	if (($d['config'] ?? '') === 'outbound') {
		$conv = link_to_outbound($key, $section['name'], (string)($d['detour'] ?? ''));
		if (!$conv['ok']) {
			return [null, $conv['error']];
		}
		return [['section' => $section['name'], 'key' => '', 'outbound' => $conv['json'], 'force' => false], null];
	}
	return [['section' => $section['name'], 'key' => $key, 'force' => false], null];
}

// ---------- Проверка ввода ----------

function valid_key(string $k): bool
{
	return strlen($k) <= 4096
		&& preg_match('#^(' . KEY_SCHEMES . ')://\S+$#', $k)
		&& !preg_match('/["\'\\\\]/', $k);
}

function valid_section_name(string $n): bool
{
	return (bool)preg_match('/^[A-Za-z][A-Za-z0-9_]{0,29}$/', $n);
}

// Возвращает [правильные домены, неправильные]
function parse_domains(string $text): array
{
	$good = [];
	$bad = [];
	// Комментарии в списках podkop: «// …» и «# …» до конца строки
	$text = preg_replace('~(//|#).*$~m', '', $text);
	foreach (preg_split('/[\s,;]+/u', mb_strtolower(trim($text))) as $d) {
		$d = preg_replace('#^[a-z]+://#', '', $d);
		$d = preg_replace('#[/:?].*$#', '', $d);
		$d = trim($d, '.');
		if ($d === '') {
			continue;
		}
		if (preg_match('/[^\x00-\x7f]/', $d) && function_exists('idn_to_ascii')) {
			$d = idn_to_ascii($d) ?: $d;
		}
		if (preg_match('/^(?=.{3,253}$)([a-z0-9-]{1,63}\.)+[a-z0-9-]{2,63}$/', $d)) {
			$good[$d] = true;
		} else {
			$bad[] = $d;
		}
	}
	return [array_keys($good), $bad];
}

// ---------- Задачи ----------

function task_label(string $type, array $p): string
{
	$sec = $p['section'] ?? '';
	$short = function (array $list): string {
		$s = implode(', ', array_slice($list, 0, 5));
		return count($list) > 5 ? $s . ' и ещё ' . (count($list) - 5) : $s;
	};
	switch ($type) {
		case 'set_key':
			return 'смена ключа в ' . $sec . (!empty($p['force']) ? ' (без проверки)' : '');
		case 'add_domains':
			return 'добавить в ' . $sec . ': ' . $short($p['domains'] ?? []);
		case 'remove_domains':
			return 'убрать из ' . $sec . ': ' . $short($p['domains'] ?? []);
		case 'set_community':
			return 'готовые списки ' . $sec . ': ' . ($short($p['lists'] ?? []) ?: 'нет');
		case 'create_section':
			return 'новая секция ' . ($p['name'] ?? '');
		case 'delete_section':
			return 'удалить секцию ' . $sec;
		case 'upload_report':
			return 'отчёт с роутера';
		case 'update':
			return 'обновление скриптов (' . ($p['ref'] ?? '') . ')';
		case 'apply_config':
			$parts = ['секций: ' . count($p['sections'] ?? [])];
			if (!empty($p['delete'])) {
				$parts[] = 'удалить: ' . implode(', ', $p['delete']);
			}
			return 'настройки podkop с сайта (' . implode('; ', $parts) . ')';
	}
	return $type;
}

// Ключи не храним дольше, чем нужно: после успешного применения вычищаем их из задачи.
function scrub_keys(array $p): array
{
	foreach (['key', 'outbound'] as $f) {
		if (!empty($p[$f])) {
			$p[$f] = '***';
		}
		foreach ($p['sections'] ?? [] as $i => $s) {
			if (!empty($s[$f])) {
				$p['sections'][$i][$f] = '***';
			}
		}
	}
	return $p;
}

function queue_task(int $routerId, string $type, array $payload = []): int
{
	q('INSERT INTO tasks (router_id, type, payload, created_at) VALUES (?, ?, ?, ?)', [
		$routerId, $type, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), time(),
	]);
	$id = (int)db()->lastInsertId();
	event($routerId, 'В очереди: ' . task_label($type, $payload));
	return $id;
}

function pending_tasks(int $routerId): array
{
	return rows("SELECT * FROM tasks WHERE router_id = ? AND status IN ('queued', 'sent') ORDER BY id", [$routerId]);
}

function finish_task(array $r, array $t, bool $ok, string $msg): void
{
	$p = json_decode($t['payload'], true) ?: [];
	// Ключ неудачной смены оставляем на сутки — для кнопки «Применить без проверки»
	$keep = !$ok && $t['type'] === 'set_key';
	q('UPDATE tasks SET status = ?, done_at = ?, result = ?, payload = ? WHERE id = ?', [
		$ok ? 'done' : 'failed', time(), $msg,
		json_encode($keep ? $p : scrub_keys($p), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $t['id'],
	]);
	$label = task_label($t['type'], $p);
	event((int)$r['id'], ($ok ? 'Выполнено: ' : 'Ошибка: ') . $label . ' — ' . $msg);

	$buttons = [];
	if (!$ok && $t['type'] === 'set_key') {
		$buttons[] = [['🔁 Применить без проверки', 'kf:' . $t['id']]];
	}
	$buttons[] = [['📟 Роутер', 'r:' . $r['id']]];
	notify_admins(($ok ? '✅' : '❌') . ' <b>' . h($r['name']) . '</b>: ' . h($label) . "\n" . h($msg), kb($buttons));
}

// ---------- API для роутеров ----------

function client_ip(): string
{
	return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function clean_info($info): array
{
	return is_array($info) ? $info : [];
}

function api_register(array $in): array
{
	if ((int)val("SELECT COUNT(*) FROM routers WHERE status = 'pending'") >= 20) {
		http_response_code(429);
		return ['error' => 'too_many_pending'];
	}
	$info = clean_info($in['info'] ?? null);
	$token = bin2hex(random_bytes(24));
	$name = mb_substr(trim((string)($info['hostname'] ?? '')), 0, 40) ?: 'Новый роутер';
	q('INSERT INTO routers (token_hash, name, status, created_at, last_seen, ip, info) VALUES (?, ?, ?, ?, ?, ?, ?)', [
		hash('sha256', $token), $name, 'pending', time(), time(), client_ip(),
		json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
	]);
	$id = (int)db()->lastInsertId();
	event($id, 'Новый роутер просится в систему');
	$same = find_same_router($info, $id);
	$statusRu = ['active' => 'в работе', 'pending' => 'ждёт подтверждения', 'rejected' => 'отклонён', 'removed' => 'удалён'];
	notify_admins(
		"🆕 <b>Новый роутер просится в систему</b>\n\n" .
		'Имя на роутере: ' . h($name) . "\n" .
		router_hw_line($info) . "\n" .
		router_mac_line($info) . "\n" .
		'IP: ' . h(client_ip()) .
		($same ? "\n\n⚠️ Это железо уже было в системе: <b>" . h($same['name']) . '</b> (#' . $same['id'] . ', ' . ($statusRu[$same['status']] ?? $same['status']) . '). Похоже, агент переустановили.' : ''),
		kb([[['✅ Принять', 'ac:' . $id], ['❌ Отклонить', 'rj:' . $id]]])
	);
	return ['token' => $token, 'id' => $id, 'status' => 'pending', 'interval' => 60];
}

function api_sync(array $r, array $in): array
{
	$now = time();
	$rid = (int)$r['id'];
	$info = clean_info($in['info'] ?? null);

	if (in_array($r['status'], ['rejected', 'removed'], true)) {
		return ['status' => $r['status'], 'interval' => 3600];
	}

	q('UPDATE routers SET last_seen = ?, ip = ?, info = ?, offline_notified = 0 WHERE id = ?', [
		$now, client_ip(), json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $rid,
	]);
	if ($r['status'] === 'active' && $r['offline_notified']) {
		$gone = $r['last_seen'] ? dur($now - (int)$r['last_seen']) : '?';
		event($rid, "Снова на связи (не было $gone)");
		if (!is_muted($r)) {
			notify_admins('🟢 <b>' . h($r['name']) . "</b> снова на связи (не было $gone)", kb([[['📟 Роутер', 'r:' . $rid]]]));
		}
	}
	if ($r['status'] !== 'active') {
		return ['status' => $r['status'], 'interval' => 60];
	}

	// Секции
	$seen = [];
	foreach (($in['sections'] ?? []) as $pos => $s) {
		if (!is_array($s) || !valid_section_name((string)($s['name'] ?? ''))) {
			continue;
		}
		$name = (string)$s['name'];
		$seen[] = $name;
		$ok = null;
		if (in_array($s['type'] ?? '', ['proxy', 'vpn'], true)) {
			$ok = (isset($s['delay']) && (int)$s['delay'] > 0) ? 1 : 0;
		}
		$data = json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$ex = row('SELECT * FROM sections WHERE router_id = ? AND name = ?', [$rid, $name]);
		if (!$ex) {
			q('INSERT INTO sections (router_id, name, pos, data, ok, fail_since) VALUES (?, ?, ?, ?, ?, ?)', [
				$rid, $name, (int)$pos, $data, $ok, $ok === 0 ? $now : null,
			]);
			continue;
		}
		$failSince = $ex['fail_since'];
		if ($ok === 0 && !$failSince) {
			$failSince = $now;
		} elseif ($ok !== 0 && !$ex['notified']) {
			// Если о сбое уже сообщили, fail_since нужен для сообщения «починилось» — его сбросит check_alerts
			$failSince = null;
		}
		q('UPDATE sections SET pos = ?, data = ?, ok = ?, fail_since = ? WHERE id = ?', [(int)$pos, $data, $ok, $failSince, $ex['id']]);
	}
	if ($seen) {
		$ph = implode(',', array_fill(0, count($seen), '?'));
		q("DELETE FROM sections WHERE router_id = ? AND name NOT IN ($ph)", array_merge([$rid], $seen));
	} else {
		q('DELETE FROM sections WHERE router_id = ?', [$rid]);
	}

	// Результаты задач
	foreach (($in['results'] ?? []) as $res) {
		if (!is_array($res)) {
			continue;
		}
		$t = row('SELECT * FROM tasks WHERE id = ? AND router_id = ?', [(int)($res['id'] ?? 0), $rid]);
		if ($t && !in_array($t['status'], ['done', 'failed'], true)) {
			finish_task($r, $t, !empty($res['ok']), mb_substr((string)($res['msg'] ?? ''), 0, 500));
		}
	}

	// Задачи, которые роутер взял, но не ответил за 15 минут, — повторяем (до 3 раз)
	foreach (rows("SELECT * FROM tasks WHERE router_id = ? AND status = 'sent' AND sent_at < ? AND attempts >= 3", [$rid, $now - 900]) as $t) {
		finish_task($r, $t, false, 'роутер трижды взял задачу, но не ответил');
	}
	$out = [];
	foreach (rows("SELECT * FROM tasks WHERE router_id = ? AND (status = 'queued' OR (status = 'sent' AND sent_at < ?)) ORDER BY id LIMIT 10", [$rid, $now - 900]) as $t) {
		q("UPDATE tasks SET status = 'sent', sent_at = ?, attempts = attempts + 1 WHERE id = ?", [$now, $t['id']]);
		$out[] = ['id' => (int)$t['id'], 'type' => $t['type']] + (json_decode($t['payload'], true) ?: []);
	}

	return ['status' => 'active', 'interval' => (int)cfg('sync_interval', 120), 'tasks' => $out];
}

function api_upload(array $r, int $taskId, string $body): array
{
	$t = row("SELECT * FROM tasks WHERE id = ? AND router_id = ? AND type = 'upload_report'", [$taskId, $r['id']]);
	if (!$t) {
		return ['ok' => false, 'error' => 'no_task'];
	}
	$dir = data_dir() . '/reports';
	if (!is_dir($dir)) {
		mkdir($dir, 0700, true);
	}
	$fname = 'router-' . $r['id'] . '-' . date('Y-m-d-His') . '.txt';
	$file = $dir . '/' . $fname;
	file_put_contents($file, $body);
	q("UPDATE tasks SET status = 'done', done_at = ?, result = ? WHERE id = ?", [time(), 'отчёт получен', $taskId]);
	event((int)$r['id'], 'Получен отчёт: ' . $fname);
	if (!cfg('bot_token')) {
		notify_admins('📄 <b>' . h($r['name']) . '</b>: получен отчёт — скачать на странице роутера');
		return ['ok' => true];
	}
	foreach (cfg('admin_ids', []) as $admin) {
		tg('sendDocument', [
			'chat_id' => $admin,
			'document' => new CURLFile($file, 'text/plain', $fname),
			'caption' => '📄 Отчёт: ' . $r['name'],
		], true);
	}
	return ['ok' => true];
}

// ---------- Уведомления (вызывается раз в минуту) ----------

function check_alerts(): void
{
	$now = time();
	// Ключи из неудачных смен — не дольше суток
	foreach (rows("SELECT id, payload FROM tasks WHERE status = 'failed' AND done_at < ? AND (payload LIKE '%\"key\":\"%' OR payload LIKE '%\"outbound\":\"%')", [$now - 86400]) as $t) {
		$clean = json_encode(scrub_keys(json_decode($t['payload'], true) ?: []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($clean !== $t['payload']) {
			q('UPDATE tasks SET payload = ? WHERE id = ?', [$clean, $t['id']]);
		}
	}
	$failAfter = (int)cfg('fail_notify_after', 600);
	$offline = [];
	$new = [];
	$remind = [];
	$recovered = [];

	foreach (rows("SELECT * FROM routers WHERE status = 'active'") as $r) {
		$rid = (int)$r['id'];
		$muted = is_muted($r);

		if (!is_online($r)) {
			if (!$r['offline_notified']) {
				q('UPDATE routers SET offline_notified = 1 WHERE id = ?', [$rid]);
				event($rid, 'Не выходит на связь');
				if (!$muted) {
					$offline[] = $r;
				}
			}
			continue;
		}

		foreach (router_sections($rid) as $s) {
			if ($s['ok'] !== null && (int)$s['ok'] === 1 && $s['notified']) {
				q('UPDATE sections SET notified = 0, reminders = 0, fail_since = NULL WHERE id = ?', [$s['id']]);
				event($rid, 'Секция ' . $s['name'] . ' снова работает');
				if (!$muted) {
					$recovered[$rid]['r'] = $r;
					$recovered[$rid]['s'][] = $s;
				}
				continue;
			}
			if (!section_failed($s) || !$s['fail_since'] || $muted) {
				continue;
			}
			if (!$s['notified']) {
				if ($now - (int)$s['fail_since'] >= $failAfter) {
					q('UPDATE sections SET notified = 1, last_notify = ?, reminders = 0 WHERE id = ?', [$now, $s['id']]);
					event($rid, 'Секция ' . $s['name'] . ' не работает');
					$new[$rid]['r'] = $r;
					$new[$rid]['s'][] = $s;
				}
			} else {
				$gap = (int)$s['reminders'] === 0 ? (int)cfg('remind_first', 10800) : (int)cfg('remind_every', 86400);
				if ($now - (int)$s['last_notify'] >= $gap) {
					q('UPDATE sections SET last_notify = ?, reminders = reminders + 1 WHERE id = ?', [$now, $s['id']]);
					$remind[$rid]['r'] = $r;
					$remind[$rid]['s'][] = $s;
				}
			}
		}
	}

	if ($offline) {
		if (count($offline) === 1) {
			$r = $offline[0];
			notify_admins(
				'⚫ <b>' . h($r['name']) . '</b> не выходит на связь (последний раз ' . ago($r['last_seen']) . ')',
				kb([[['📟 Роутер', 'r:' . $r['id']], ['🔕 Тишина 24ч', 'mu:' . $r['id']]]])
			);
		} else {
			$lines = array_map(fn($r) => '• ' . h($r['name']) . ' — ' . ago($r['last_seen']), $offline);
			notify_admins("⚫ <b>Не выходят на связь:</b>\n" . implode("\n", $lines), kb([[['📋 Роутеры', 'rl']]]));
		}
	}
	send_section_alerts($new, '🔴 <b>Не работает</b>');
	send_section_alerts($remind, '⏰ <b>Всё ещё не работает</b>');

	foreach ($recovered as $rid => $x) {
		$lines = array_map(fn($s) => '• ' . h($s['name']) . ($s['fail_since'] ? ' (не работала ' . dur($now - (int)$s['fail_since']) . ')' : ''), $x['s']);
		notify_admins('✅ <b>' . h($x['r']['name']) . "</b>: снова работает\n" . implode("\n", $lines), kb([[['📟 Роутер', 'r:' . $rid]]]));
	}
}

function send_section_alerts(array $groups, string $title): void
{
	if (!$groups) {
		return;
	}
	if (count($groups) === 1) {
		$x = reset($groups);
		$r = $x['r'];
		$byName = by_name(router_sections((int)$r['id']));
		$lines = array_map(fn($s) => section_line($s, $byName), $x['s']);
		$buttons = [];
		foreach ($x['s'] as $s) {
			if (key_changeable(sdata($s))) {
				$buttons[] = [['🔑 Сменить ключ ' . $s['name'], 'sk:' . $s['id']]];
			}
		}
		$buttons[] = [['📟 Роутер', 'r:' . $r['id']], ['🔕 Тишина 24ч', 'mu:' . $r['id']]];
		notify_admins(
			$title . ' — <b>' . h($r['name']) . "</b>\n" . implode("\n", $lines) .
			(cfg('bot_token') ? "\n\n💡 Новый ключ можно просто переслать сюда — сообщением из бота ImpVPN." : ''),
			kb($buttons)
		);
		return;
	}
	$text = $title . ' на нескольких роутерах:';
	foreach ($groups as $x) {
		$text .= "\n\n<b>" . h($x['r']['name']) . '</b>';
		foreach ($x['s'] as $s) {
			$text .= "\n" . section_line($s);
		}
	}
	notify_admins($text, kb([[['📋 Роутеры', 'rl']]]));
}
