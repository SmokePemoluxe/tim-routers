<?php
// Уведомления во ВКонтакте: сообщество присылает личные сообщения выбранным людям.
// Настройки (ключ сообщества и получатели) хранятся в базе и задаются на странице «Настройки».

const VK_API = 'https://api.vk.com/method/';
const VK_VERSION = '5.199';

function vk_settings(): array
{
	return kv_get('vk', []) + ['token' => '', 'group' => '', 'peers' => [], 'enabled' => false];
}

function vk_save(array $s): void
{
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

// Отправить уведомление всем получателям. $link — ссылка «открыть на сайте».
function vk_notify(string $html, string $link = ''): void
{
	$s = vk_settings();
	if (!$s['enabled'] || $s['token'] === '' || !$s['peers']) {
		return;
	}
	$text = vk_plain($html) . ($link !== '' ? "\n\n" . $link : '');
	foreach ($s['peers'] as $p) {
		$r = vk_api('messages.send', [
			'peer_id' => (int)$p['id'],
			'random_id' => random_int(1, PHP_INT_MAX),
			'message' => mb_substr($text, 0, 4000),
			'dont_parse_links' => 1,
		]);
		if (!$r['ok']) {
			error_log('vk_notify: ' . $r['error']);
			kv_set('vk_last_error', ['ts' => time(), 'error' => $r['error']]);
		}
	}
}

// Ссылка на сайт для уведомления: на роутер, если он понятен из кнопок уведомления
function notify_link(?array $kb): string
{
	$base = rtrim((string)cfg('base_url'), '/');
	foreach ($kb['inline_keyboard'] ?? [] as $row) {
		foreach ($row as $b) {
			if (preg_match('/^(?:r|mu):(\d+)$/', (string)($b['callback_data'] ?? ''), $m)) {
				return $base . '/router.php?id=' . $m[1];
			}
		}
	}
	return $base . '/';
}
