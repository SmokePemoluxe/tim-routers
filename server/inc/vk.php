<?php
// Уведомления во ВКонтакте через сообщество администратора.
//   Администратор: ключ сообщества и получатели (люди, беседы) — на странице «Настройки» админки.
//   Пользователи сервиса: тот же бот пишет им в личные сообщения. Привязка — по одноразовому коду,
//   который пользователь сам отправляет сообществу (так видно, что страница ВК — его).

const VK_API = 'https://api.vk.com/method/';
const VK_VERSION = '5.199';

// Настройки бота администратора; ключ сообщества хранится зашифрованным
function vk_settings(): array
{
	$s = (kv_get('vk', []) ?: []) + ['token' => '', 'group' => '', 'peers' => [], 'enabled' => false];
	$s['token'] = secret_dec((string)$s['token']);
	return $s;
}

function vk_save(array $s): void
{
	$s['token'] = secret_enc((string)$s['token']);
	kv_set('vk', $s);
}

// Возвращает ['ok' => true, 'response' => …] или ['ok' => false, 'code' => …, 'error' => текст]
function vk_api(string $method, array $params, ?string $token = null): array
{
	$params['access_token'] = $token ?? vk_settings()['token'];
	$params['v'] = VK_VERSION;
	$ch = curl_init(VK_API . $method);
	curl_setopt_array($ch, [
		CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => http_build_query($params),
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CONNECTTIMEOUT => 10,
		CURLOPT_TIMEOUT => 20,
	]);
	$res = curl_exec($ch);
	if ($res === false) {
		return ['ok' => false, 'code' => 0, 'error' => 'нет связи с ВКонтакте: ' . curl_error($ch)];
	}
	$j = json_decode((string)$res, true);
	if (isset($j['error'])) {
		return ['ok' => false, 'code' => (int)($j['error']['error_code'] ?? 0), 'error' => vk_error_text($j['error'])];
	}
	return ['ok' => true, 'response' => $j['response'] ?? null];
}

function vk_error_text(array $e): string
{
	$code = (int)($e['error_code'] ?? 0);
	$hints = [
		5 => 'ключ неверный или отозван',
		15 => 'у ключа нет нужных прав — создайте ключ с правом «Сообщения сообщества»',
		27 => 'это ключ пользователя, а нужен ключ сообщества',
		901 => 'человек не разрешил сообществу писать ему — пусть напишет сообществу любое сообщение',
		902 => 'человек запретил сообщения от сообщества',
		917 => 'нет доступа к беседе',
		945 => 'в сообществе выключены сообщения — включите их в управлении сообществом',
	];
	return ($hints[$code] ?? ($e['error_msg'] ?? 'ошибка')) . " (код $code)";
}

// Текст уведомления сайта (с <b>, <i>, <code>) → обычный текст для ВКонтакте
function vk_plain(string $html): string
{
	return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

// Отправить уведомление. $uid = 0 — получателям администратора, иначе — пользователю сервиса.
function vk_notify(string $html, string $link = '', ?int $uid = null): void
{
	$uid = $uid ?? cur_uid();
	$s = vk_settings();
	if ($s['token'] === '') {
		return;
	}
	if ($uid > 0) {
		$l = vk_link($uid);
		if (empty($l['enabled']) || empty($l['id'])) {
			return;
		}
		$peers = [['id' => (int)$l['id']]];
	} else {
		if (!$s['enabled'] || !$s['peers']) {
			return;
		}
		$peers = $s['peers'];
	}
	$text = vk_plain($html) . ($link !== '' ? "\n\n" . $link : '');
	foreach ($peers as $p) {
		$r = vk_api('messages.send', [
			'peer_id' => (int)$p['id'],
			'random_id' => random_int(1, PHP_INT_MAX),
			'message' => mb_substr($text, 0, 4000),
			'dont_parse_links' => 1,
		], $s['token']);
		if (!$r['ok']) {
			error_log('vk_notify: ' . $r['error']);
			kv_set(okey('vk_last_error', $uid), ['ts' => time(), 'error' => $r['error']]);
		}
	}
}

// Ссылка на сайт для уведомления: на роутер, если он понятен из кнопок уведомления
function notify_link(?array $kb, int $uid = 0): string
{
	$base = $uid ? service_url() : rtrim((string)cfg('base_url'), '/');
	foreach ($kb['inline_keyboard'] ?? [] as $row) {
		foreach ($row as $b) {
			if (preg_match('/^(?:r|mu):(\d+)$/', (string)($b['callback_data'] ?? ''), $m)) {
				return $base . '/router.php?id=' . $m[1];
			}
		}
	}
	return $base . '/';
}

// ---------- Привязка ВКонтакте пользователя сервиса ----------

// Сообщество-бот: id, название, короткий адрес (кэш на сутки)
function vk_group(): ?array
{
	$s = vk_settings();
	if ($s['token'] === '') {
		return null;
	}
	$c = kv_get('vk_group');
	if ($c && time() - (int)$c['ts'] < 86400 && ($c['tok'] ?? '') === hash('sha256', $s['token'])) {
		return $c;
	}
	$r = vk_api('groups.getById', [], $s['token']);
	$g = $r['ok'] ? ($r['response']['groups'][0] ?? $r['response'][0] ?? null) : null;
	if (!$g) {
		return $c ?: null;
	}
	$c = ['ts' => time(), 'tok' => hash('sha256', $s['token']), 'id' => (int)$g['id'], 'name' => (string)($g['name'] ?? ''),
		'screen' => (string)($g['screen_name'] ?? ('club' . $g['id']))];
	kv_set('vk_group', $c);
	return $c;
}

// Ссылка «написать сообществу»
function vk_chat_url(array $g): string
{
	return 'https://vk.me/' . rawurlencode($g['screen']);
}

function vk_link(int $uid): array
{
	return (kv_get(okey('vklink', $uid), []) ?: []) + ['id' => 0, 'name' => '', 'enabled' => false, 'code' => '', 'code_exp' => 0];
}

function vk_link_save(int $uid, array $l): void
{
	kv_set(okey('vklink', $uid), $l);
}

// Новый код для привязки: «RT-» и 6 букв/цифр без похожих символов, живёт 15 минут
function vk_link_code(int $uid): string
{
	$alpha = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
	$code = 'RT-';
	for ($i = 0; $i < 6; $i++) {
		$code .= $alpha[random_int(0, strlen($alpha) - 1)];
	}
	$l = vk_link($uid);
	$l['code'] = $code;
	$l['code_exp'] = time() + 900;
	vk_link_save($uid, $l);
	return $code;
}

// Найти, кто прислал сообществу код. Строка ошибки или null (привязано).
function vk_link_check(int $uid): ?string
{
	$l = vk_link($uid);
	if ($l['code'] === '' || (int)$l['code_exp'] < time()) {
		return 'Код устарел — получите новый.';
	}
	$r = vk_api('messages.getConversations', ['count' => 200, 'extended' => 1]);
	if (!$r['ok']) {
		return 'ВКонтакте не ответил: ' . $r['error'];
	}
	$names = [];
	foreach ($r['response']['profiles'] ?? [] as $p) {
		$names[(int)$p['id']] = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
	}
	foreach ($r['response']['items'] ?? [] as $it) {
		$peer = $it['conversation']['peer'] ?? [];
		$msg = $it['last_message'] ?? [];
		$text = strtoupper(trim((string)($msg['text'] ?? '')));
		// Только личные сообщения, только от самого человека и только свежий код
		if (($peer['type'] ?? '') === 'user' && (int)($msg['from_id'] ?? 0) === (int)$peer['id']
			&& $text === $l['code'] && (int)($msg['date'] ?? 0) > (int)$l['code_exp'] - 900) {
			$id = (int)$peer['id'];
			$l = ['id' => $id, 'name' => $names[$id] ?? ('id' . $id), 'enabled' => true, 'code' => '', 'code_exp' => 0];
			vk_link_save($uid, $l);
			return null;
		}
	}
	return 'Сообщение с кодом пока не найдено. Отправьте сообществу именно код (одним сообщением, без других слов) и нажмите кнопку ещё раз.';
}
