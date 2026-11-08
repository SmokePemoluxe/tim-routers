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
function install_command(string $invite = ''): string
{
	return 'wget -O /tmp/tr-install.sh https://raw.githubusercontent.com/' . GITHUB_REPO . '/main/router/install.sh && sh /tmp/tr-install.sh '
		. rtrim((string)cfg('base_url'), '/') . ($invite !== '' ? ' --code ' . $invite : '');
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
		return (sdata($s)['type'] ?? '') === 'block' ? '⛔' : '⚪';
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
	if ($type === 'block') {
		return 'блокировка (домены не открываются)';
	}
	if ($type === 'vpn') {
		return 'через VPN' . (!empty($d['interface']) ? ' (' . $d['interface'] . ')' : '');
	}
	if ($type === 'proxy') {
		$cfgType = ['url' => 'ссылка', 'outbound' => 'outbound JSON', 'urltest' => 'несколько ссылок'][$d['config'] ?? ''] ?? ($d['config'] ?? '');
		return 'через прокси (' . $cfgType . ')';
	}
	return $type ?: '?';
}

// Страны в списке серверов подписки: первое слово названия («🇩🇪 Германия №7» → Германия) → сколько серверов
function sub_countries(array $servers): array
{
	$out = [];
	foreach ($servers as $x) {
		if (preg_match('/\p{L}[\p{L}-]+/u', (string)($x['name'] ?? ''), $m)) {
			$out[$m[0]] = ($out[$m[0]] ?? 0) + 1;
		}
	}
	ksort($out);
	return $out;
}

// Строка секции для карточки роутера и уведомлений
function section_line(array $s, array $byName = []): string
{
	$d = sdata($s);
	$t = section_icon($s) . ' <b>' . h($s['name']) . '</b> — ';
	if (($d['type'] ?? '') === 'exclusion') {
		return $t . 'мимо прокси';
	}
	if (($d['type'] ?? '') === 'block') {
		return $t . 'блокирует домены';
	}
	if (is_array($d['sub'] ?? null)) {
		// Подписка: сколько серверов живо и какой сейчас выбран
		$srv = array_filter($d['sub']['servers'] ?? [], fn($x) => $x['in'] ?? true);
		$alive = count(array_filter($srv, fn($x) => !empty($x['delay'])));
		$t .= 'подписка · отвечают ' . $alive . ' из ' . count($srv);
		foreach ($srv as $x) {
			if (!empty($x['now'])) {
				$t .= ' · сейчас ' . h($x['name'] ?: $x['host']);
			}
		}
		if (!empty($d['sub']['pin'])) {
			$t .= ' · 📌 закреплён';
		} elseif (!empty($d['sub']['fb'])) {
			$t .= ' · ⚠️ на запасных странах';
		} elseif (!empty($d['sub']['only'])) {
			$t .= ' · только ' . h($d['sub']['only']);
		}
		if (empty($d['sub']['pin']) && !empty($d['sub']['sticky'])) {
			$t .= ' · держится за текущий';
		}
	} else {
		$t .= h($d['scheme'] ?? '?');
		if (!empty($d['host'])) {
			$t .= ' ' . h($d['host']);
		}
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
// Только среди роутеров того же владельца: чужие роутеры пользователю не показываем
function find_same_router(array $info, int $exceptId = 0, ?int $uid = null): ?array
{
	if ($uid === null) {
		$uid = $exceptId ? (int)val('SELECT user_id FROM routers WHERE id = ?', [$exceptId]) : cur_uid();
	}
	foreach (['mac_wan', 'mac_lan'] as $k) {
		// У роутеров пользователей MAC хранится только хэшем — сравниваем и так, и так
		$mac = strtolower((string)($info[$k] ?? ''));
		$hash = preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac) ? mac_hash($mac) : (string)($info[$k . '_h'] ?? '');
		if ($hash === '') {
			continue;
		}
		foreach (rows('SELECT * FROM routers WHERE id != ? AND user_id = ? AND (info LIKE ? OR info LIKE ?) ORDER BY id DESC', [$exceptId, $uid, '%' . ($mac ?: $hash) . '%', '%' . $hash . '%']) as $r) {
			$ri = rinfo($r);
			if (($mac !== '' && strtolower((string)($ri[$k] ?? '')) === $mac) || ($ri[$k . '_h'] ?? '') === $hash) {
				return $r;
			}
		}
	}
	return null;
}

// Последняя версия podkop на GitHub (обновляется из cron не чаще раза в 12 часов)
function podkop_latest(bool $refresh = false): string
{
	$c = kv_get('podkop_latest', ['v' => '', 'ts' => 0]);
	if ($refresh && time() - (int)$c['ts'] > 12 * 3600) {
		$ch = curl_init('https://api.github.com/repos/itdoginfo/podkop/releases/latest');
		curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_USERAGENT => 'tim-routers']);
		$j = json_decode((string)curl_exec($ch), true);
		$c = ['v' => (string)($j['tag_name'] ?? $c['v']), 'ts' => time()];
		kv_set('podkop_latest', $c);
	}
	return (string)$c['v'];
}

function podkop_outdated(string $v): bool
{
	$latest = podkop_latest();
	return $v !== '' && $latest !== '' && version_compare(ltrim($v, 'v'), ltrim($latest, 'v'), '<');
}

// Последние версии агента и сторожа на GitHub (из cron не чаще раза в 6 часов)
function scripts_latest(bool $refresh = false): array
{
	$c = kv_get('scripts_latest', ['agent' => '', 'watchdog' => '', 'ts' => 0]);
	if ($refresh && time() - (int)$c['ts'] > 6 * 3600) {
		foreach (['agent' => 'tim-agent.sh', 'watchdog' => 'inet-watchdog.sh'] as $k => $f) {
			$ch = curl_init('https://raw.githubusercontent.com/' . GITHUB_REPO . '/main/router/' . $f);
			curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_RANGE => '0-4000']);
			if (preg_match('/^VERSION="([0-9.]+)"/m', (string)curl_exec($ch), $m)) {
				$c[$k] = $m[1];
			}
		}
		$c['ts'] = time();
		kv_set('scripts_latest', $c);
	}
	return $c;
}

// Что на роутере устарело: ['агент 2.1 (есть 2.4)', …]
function router_outdated(array $info): array
{
	$l = scripts_latest();
	$out = [];
	$a = (string)($info['agent'] ?? '');
	if ($a !== '' && $l['agent'] !== '' && version_compare($a, $l['agent'], '<')) {
		$out[] = 'агент ' . $a . ' (есть ' . $l['agent'] . ')';
	}
	$w = (string)($info['watchdog']['version'] ?? '');
	if ($w !== '' && $l['watchdog'] !== '' && version_compare($w, $l['watchdog'], '<')) {
		$out[] = 'сторож ' . $w . ' (есть ' . $l['watchdog'] . ')';
	}
	$p = (string)($info['podkop'] ?? '');
	if ($p !== '' && podkop_outdated($p)) {
		$out[] = 'podkop ' . $p . ' (есть ' . podkop_latest() . ')';
	}
	return $out;
}

// Предупреждения о самом роутере: мало места, мало памяти
function router_warnings(array $info): array
{
	$w = [];
	if (isset($info['flash_free']) && (int)$info['flash_free'] < 1024) {
		$w[] = 'во флеш-памяти свободно меньше 1 МБ — обновления могут не установиться';
	}
	if (isset($info['mem_free']) && (int)$info['mem_free'] > 0 && (int)$info['mem_free'] < 10) {
		$w[] = 'свободной памяти меньше 10%';
	}
	return $w;
}

function router_hw_line(array $info): string
{
	$podkop = (string)($info['podkop'] ?? '');
	$parts = array_filter([
		$info['model'] ?? '',
		!empty($info['openwrt']) ? 'OpenWrt ' . $info['openwrt'] : '',
		$podkop !== '' ? 'podkop ' . $podkop . (podkop_outdated($podkop) ? ' (есть ' . podkop_latest() . ')' : '') : '',
		!empty($info['agent']) ? 'агент ' . $info['agent'] : '',
		!empty($info['watchdog']['version']) ? 'сторож ' . $info['watchdog']['version'] : '',
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
		case 'update_podkop':
			return 'обновление podkop';
		case 'refresh_subscription':
			return 'обновление списка серверов подписки ' . $sec;
		case 'sub_switch':
			return ($p['server'] ?? '') === 'auto'
				? 'автовыбор сервера подписки ' . $sec
				: 'переключить ' . $sec . ' на сервер ' . ($p['label'] ?? $p['server'] ?? '');
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
	foreach (['key', 'outbound', 'sub_url'] as $f) {
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
	notify_owner((int)$r['user_id'], ($ok ? '✅' : '❌') . ' <b>' . h($r['name']) . '</b>: ' . h($label) . "\n" . h($msg), kb($buttons));
}

// ---------- API для роутеров ----------

function client_ip(): string
{
	return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function clean_info($info, int $uid = 0): array
{
	$info = is_array($info) ? $info : [];
	if ($uid > 0) {
		// Роутеры пользователей: адрес в локальной сети не храним, MAC — только как хэш
		// (по нему видно «это железо уже было», но сам адрес не виден никому)
		foreach (['mac_wan', 'mac_lan'] as $k) {
			$mac = strtolower((string)($info[$k] ?? ''));
			unset($info[$k]);
			if (preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac)) {
				$info[$k . '_h'] = mac_hash($mac);
			}
		}
		unset($info['lan_ip']);
	}
	return $info;
}

function mac_hash(string $mac): string
{
	return substr(hash('sha256', 'mac:' . strtolower($mac)), 0, 32);
}

// Внешний IP роутера пользователя не храним
function router_ip(int $uid): string
{
	return $uid > 0 ? '' : client_ip();
}

// Включён ли сервис на роутере (сторож и автоматика подписок): у пользователя — только при активной подписке
function svc_flag(array $r): array
{
	$uid = (int)$r['user_id'];
	if ($uid <= 0) {
		return ['svc' => 'on'];
	}
	$u = get_user($uid);
	if (!$u || $u['status'] !== 'active') {
		return ['svc' => 'off', 'svc_reason' => 'кабинет заблокирован'];
	}
	if (!user_plan($u)['active']) {
		return ['svc' => 'off', 'svc_reason' => 'подписка не активна — продлите её в кабинете ' . service_url() . '/'];
	}
	return ['svc' => 'on'];
}

function api_register(array $in): array
{
	if ((int)val("SELECT COUNT(*) FROM routers WHERE status = 'pending'") >= 200) {
		http_response_code(429);
		return ['error' => 'too_many_pending'];
	}
	$info = clean_info($in['info'] ?? null);

	// Без действующего кода приглашения (из панели или кабинета) новый роутер не принимаем.
	// Код знает, чей будет роутер.
	$uid = invite_use((string)($in['invite'] ?? ''));
	if ($uid !== null && $uid > 0) {
		$u = get_user($uid);
		if (!user_can_manage($u) || user_router_count($uid) >= user_router_limit($u)) {
			event(null, 'Отклонена регистрация роутера: в кабинете ' . ($u ? $u['email'] : '#' . $uid) . ' нет места или подписка не активна');
			http_response_code(403);
			return ['error' => 'invite_required'];
		}
		set_uid($uid);
	}
	if ($uid === null) {
		event(null, 'Отклонена регистрация без кода приглашения: ' . mb_substr((string)($info['model'] ?? '?'), 0, 40) . ', IP ' . client_ip());
		if (!is_service() && time() - (int)kv_get('invite_reject_notified', 0) > 3600) {
			kv_set('invite_reject_notified', time());
			notify_admins('🚫 Роутер пытался зарегистрироваться без кода приглашения (IP ' . h(client_ip()) . ', ' . h((string)($info['model'] ?? '?')) . "). Если это ваш — получите команду с кодом в панели. Следующие такие попытки — только в журнале событий, не чаще раза в час.");
		}
		http_response_code(403);
		return ['error' => 'invite_required'];
	}

	$token = bin2hex(random_bytes(24));
	$name = mb_substr(trim((string)($info['hostname'] ?? '')), 0, 40) ?: 'Новый роутер';
	q('INSERT INTO routers (token_hash, name, status, created_at, last_seen, ip, info, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
		hash('sha256', $token), $name, 'pending', time(), time(), router_ip($uid),
		json_encode(clean_info($info, $uid), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $uid,
	]);
	$id = (int)db()->lastInsertId();
	event($id, 'Новый роутер просится в систему');
	$same = find_same_router($info, $id, $uid);
	$statusRu = ['active' => 'в работе', 'pending' => 'ждёт подтверждения', 'rejected' => 'отклонён', 'removed' => 'удалён'];
	notify_owner(
		$uid,
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

	if ($r['status'] === 'removed') {
		// Агент 2.4+ удалит себя и сторожа (podkop остаётся)
		return ['status' => 'removed', 'interval' => 3600, 'uninstall' => true];
	}
	if ($r['status'] === 'rejected') {
		return ['status' => 'rejected', 'interval' => 3600];
	}
	$info = clean_info($in['info'] ?? null, (int)$r['user_id']);

	q('UPDATE routers SET last_seen = ?, ip = ?, info = ?, offline_notified = 0 WHERE id = ?', [
		$now, router_ip((int)$r['user_id']), json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $rid,
	]);
	if ($r['status'] === 'active' && $r['offline_notified']) {
		$gone = $r['last_seen'] ? dur($now - (int)$r['last_seen']) : '?';
		event($rid, "Снова на связи (не было $gone)");
		if (!is_muted($r)) {
			notify_owner((int)$r['user_id'], '🟢 <b>' . h($r['name']) . "</b> снова на связи (не было $gone)", kb([[['📟 Роутер', 'r:' . $rid]]]));
		}
	}
	if ($r['status'] !== 'active') {
		return ['status' => $r['status'], 'interval' => 60] + svc_flag($r);
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

	router_events($r, (array)($in['events'] ?? []));

	// Задачи, которые роутер взял, но не ответил за 15 минут, — повторяем (до 3 раз)
	foreach (rows("SELECT * FROM tasks WHERE router_id = ? AND status = 'sent' AND sent_at < ? AND attempts >= 3", [$rid, $now - 900]) as $t) {
		finish_task($r, $t, false, 'роутер трижды взял задачу, но не ответил');
	}
	$out = [];
	foreach (rows("SELECT * FROM tasks WHERE router_id = ? AND (status = 'queued' OR (status = 'sent' AND sent_at < ?)) ORDER BY id LIMIT 10", [$rid, $now - 900]) as $t) {
		q("UPDATE tasks SET status = 'sent', sent_at = ?, attempts = attempts + 1 WHERE id = ?", [$now, $t['id']]);
		$out[] = ['id' => (int)$t['id'], 'type' => $t['type']] + (json_decode($t['payload'], true) ?: []);
	}

	return ['status' => 'active', 'interval' => (int)cfg('sync_interval', 120), 'tasks' => $out] + svc_flag($r);
}

function watch_label(string $file): string
{
	return [
		'podkop' => 'podkop', 'firewall' => 'брандмауэр', 'network' => 'сеть и интерфейсы', 'wireless' => 'Wi-Fi',
		'dhcp' => 'DHCP и DNS', 'system' => 'система', 'dropbear' => 'SSH-сервер', 'uhttpd' => 'веб-сервер LuCI',
		'ssh_keys' => 'SSH-ключи доступа', 'root_password' => 'пароль root', 'crontab' => 'расписание задач (cron)',
		'packages' => 'установленные пакеты',
	][$file] ?? $file;
}

// События от агента: настройки изменены НЕ через панель; входы на роутер (LuCI, SSH, терминал)
function router_events(array $r, array $events): void
{
	$rid = (int)$r['id'];
	$cfg = [];
	$logins = [];
	$subNotes = [];
	foreach (array_slice($events, 0, 60) as $ev) {
		if (!is_array($ev)) {
			continue;
		}
		if (($ev['type'] ?? '') === 'config') {
			$file = preg_replace('/[^a-z_]/', '', (string)($ev['file'] ?? ''));
			event($rid, 'Изменено не через панель: ' . watch_label($file));
			$b = '• <b>' . h(watch_label($file)) . '</b>';
			foreach (array_slice((array)($ev['removed'] ?? []), 0, 12) as $l) {
				$b .= "\n   − " . h(mb_substr((string)$l, 0, 160));
			}
			foreach (array_slice((array)($ev['added'] ?? []), 0, 12) as $l) {
				$b .= "\n   + " . h(mb_substr((string)$l, 0, 160));
			}
			$cfg[] = $b;
		} elseif (($ev['type'] ?? '') === 'config_mass') {
			$files = array_map(fn($x) => watch_label(preg_replace('/[^a-z_]/', '', (string)$x)), array_slice((array)($ev['files'] ?? []), 0, 15));
			event($rid, 'Изменено сразу много настроек не через панель: ' . implode(', ', $files));
			$cfg[] = '• изменено сразу много разделов: <b>' . h(implode(', ', $files)) . '</b> — похоже на сброс настроек или переустановку. Подробности — в LuCI роутера.';
		} elseif (($ev['type'] ?? '') === 'login') {
			$ip = (string)($ev['ip'] ?? '');
			$via = ['luci' => 'LuCI', 'ssh' => 'SSH', 'terminal' => 'терминал'][$ev['via'] ?? ''] ?? (string)($ev['via'] ?? '?');
			$line = '• ' . $via . ' ' . h((string)($ev['user'] ?? '')) . ($ip !== '' ? ' с ' . h($ip) : '');
			if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
				$g = ip_geo($ip);
				$line .= $g['text'] !== '' ? ' (' . h($g['text']) . ($g['proxy'] ? ', VPN/хостинг' : '') . ')' : '';
			}
			$line .= !empty($ev['key']) ? ' по SSH-ключу' : '';
			$line .= !empty($ev['ok']) ? ' — вход выполнен' : ' — <b>НЕВЕРНЫЙ пароль</b>';
			event($rid, strip_tags(str_replace('• ', 'Вход на роутер: ', $line)));
			$logins[] = $line;
		} elseif (($ev['type'] ?? '') === 'sub_note') {
			// Подписка сама сменила страну или вернула автовыбор
			$sec = preg_replace('/[^A-Za-z0-9_]/', '', (string)($ev['section'] ?? ''));
			$text = mb_substr((string)($ev['text'] ?? ''), 0, 300);
			event($rid, 'Подписка ' . $sec . ': ' . $text);
			$subNotes[] = '• <b>' . h($sec) . '</b>: ' . h($text);
		}
	}
	$kb = kb([[['📟 Роутер', 'r:' . $rid]]]);
	if ($subNotes) {
		notify_owner((int)$r['user_id'], '🌍 <b>' . h($r['name']) . "</b>: подписка\n" . implode("\n", array_slice($subNotes, 0, 10)), $kb);
	}
	if ($cfg) {
		notify_owner((int)$r['user_id'],
			'⚠️ <b>' . h($r['name']) . "</b>: настройки изменены <b>НЕ через панель</b>\n" . implode("\n", $cfg) .
			"\n\nЕсли это не вы и не владелец роутера — возможно, кто-то получил к нему доступ.",
			$kb
		);
	}
	if ($logins) {
		notify_owner((int)$r['user_id'], '🔑 <b>' . h($r['name']) . "</b>: вход на роутер\n" . implode("\n", array_slice($logins, 0, 15)), $kb);
	}
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
	if (!cfg('bot_token') || (int)$r['user_id'] !== 0) {
		notify_owner((int)$r['user_id'], '📄 <b>' . h($r['name']) . '</b>: получен отчёт — скачать на странице роутера');
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
	podkop_latest(true);
	scripts_latest(true);
	try {
		backup_daily();
	} catch (Throwable $e) {
		error_log('backup: ' . $e->getMessage());
	}
	// Ключи из неудачных смен — не дольше суток
	foreach (rows("SELECT id, payload FROM tasks WHERE status = 'failed' AND done_at < ? AND (payload LIKE '%\"key\":\"%' OR payload LIKE '%\"outbound\":\"%')", [$now - 86400]) as $t) {
		$clean = json_encode(scrub_keys(json_decode($t['payload'], true) ?: []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($clean !== $t['payload']) {
			q('UPDATE tasks SET payload = ? WHERE id = ?', [$clean, $t['id']]);
		}
	}
	q('DELETE FROM tokens WHERE expires < ?', [$now]);
	try {
		billing_tick();
	} catch (Throwable $e) {
		error_log('billing: ' . $e->getMessage());
	}
	$failAfter = (int)cfg('fail_notify_after', 600);
	// Всё раскладываем по владельцам роутеров: каждому — только о его роутерах
	$offline = [];
	$new = [];
	$remind = [];
	$recovered = [];

	foreach (rows("SELECT * FROM routers WHERE status = 'active'") as $r) {
		$rid = (int)$r['id'];
		$uid = (int)$r['user_id'];
		$muted = is_muted($r);

		if (!is_online($r)) {
			if (!$r['offline_notified']) {
				q('UPDATE routers SET offline_notified = 1 WHERE id = ?', [$rid]);
				event($rid, 'Не выходит на связь');
				if (!$muted) {
					$offline[$uid][] = $r;
				}
			}
			continue;
		}

		foreach (router_sections($rid) as $s) {
			if ($s['ok'] !== null && (int)$s['ok'] === 1 && $s['notified']) {
				q('UPDATE sections SET notified = 0, reminders = 0, fail_since = NULL WHERE id = ?', [$s['id']]);
				event($rid, 'Секция ' . $s['name'] . ' снова работает');
				if (!$muted) {
					$recovered[$uid][$rid]['r'] = $r;
					$recovered[$uid][$rid]['s'][] = $s;
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
					$new[$uid][$rid]['r'] = $r;
					$new[$uid][$rid]['s'][] = $s;
				}
			} else {
				$gap = (int)$s['reminders'] === 0 ? (int)cfg('remind_first', 10800) : (int)cfg('remind_every', 86400);
				if ($now - (int)$s['last_notify'] >= $gap) {
					q('UPDATE sections SET last_notify = ?, reminders = reminders + 1 WHERE id = ?', [$now, $s['id']]);
					$remind[$uid][$rid]['r'] = $r;
					$remind[$uid][$rid]['s'][] = $s;
				}
			}
		}
	}

	foreach ($offline as $uid => $list) {
		if (count($list) === 1) {
			$r = $list[0];
			notify_owner(
				$uid,
				'⚫ <b>' . h($r['name']) . '</b> не выходит на связь (последний раз ' . ago($r['last_seen']) . ')',
				kb([[['📟 Роутер', 'r:' . $r['id']], ['🔕 Тишина 24ч', 'mu:' . $r['id']]]])
			);
		} else {
			$lines = array_map(fn($r) => '• ' . h($r['name']) . ' — ' . ago($r['last_seen']), $list);
			notify_owner($uid, "⚫ <b>Не выходят на связь:</b>\n" . implode("\n", $lines), kb([[['📋 Роутеры', 'rl']]]));
		}
	}
	foreach ($new as $uid => $groups) {
		send_section_alerts($uid, $groups, '🔴 <b>Не работает</b>');
	}
	foreach ($remind as $uid => $groups) {
		send_section_alerts($uid, $groups, '⏰ <b>Всё ещё не работает</b>');
	}
	foreach ($recovered as $uid => $groups) {
		foreach ($groups as $rid => $x) {
			$lines = array_map(fn($s) => '• ' . h($s['name']) . ($s['fail_since'] ? ' (не работала ' . dur($now - (int)$s['fail_since']) . ')' : ''), $x['s']);
			notify_owner($uid, '✅ <b>' . h($x['r']['name']) . "</b>: снова работает\n" . implode("\n", $lines), kb([[['📟 Роутер', 'r:' . $rid]]]));
		}
	}
}

function send_section_alerts(int $uid, array $groups, string $title): void
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
		notify_owner(
			$uid,
			$title . ' — <b>' . h($r['name']) . "</b>\n" . implode("\n", $lines) .
			(cfg('bot_token') && !$uid ? "\n\n💡 Новый ключ можно просто переслать сюда — сообщением из бота ImpVPN." : ''),
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
	notify_owner($uid, $text, kb([[['📋 Роутеры', 'rl']]]));
}
