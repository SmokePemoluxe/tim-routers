<?php
// Обёртка над Telegram Bot API. Без bot_token работает «вхолостую»:
// сообщения только записываются в outbox и видны в веб-панели.

$GLOBALS['TG_ERROR'] = '';

function tg(string $method, array $params = [], bool $multipart = false)
{
	$GLOBALS['TG_ERROR'] = '';
	if (in_array($method, ['sendMessage', 'editMessageText'], true) && isset($params['text'])) {
		q('INSERT INTO outbox (ts, chat_id, text) VALUES (?, ?, ?)', [time(), (string)($params['chat_id'] ?? ''), $params['text']]);
	} elseif ($method === 'sendDocument') {
		q('INSERT INTO outbox (ts, chat_id, text) VALUES (?, ?, ?)', [time(), (string)($params['chat_id'] ?? ''), '[файл] ' . ($params['caption'] ?? '')]);
	}

	$token = cfg('bot_token');
	if (!$token) {
		return null;
	}

	$ch = curl_init("https://api.telegram.org/bot$token/$method");
	if ($multipart) {
		curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
	} else {
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params, JSON_UNESCAPED_UNICODE));
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
	}
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CONNECTTIMEOUT => 10,
		CURLOPT_TIMEOUT => $method === 'getUpdates' ? 40 : 20,
	]);
	// Если с хостинга нет прямого доступа к api.telegram.org — через прокси (socks5h://… или http://…)
	if (cfg('tg_proxy')) {
		curl_setopt($ch, CURLOPT_PROXY, (string)cfg('tg_proxy'));
	}
	$res = curl_exec($ch);
	if ($res === false) {
		$GLOBALS['TG_ERROR'] = curl_error($ch);
		error_log("tg $method: " . $GLOBALS['TG_ERROR']);
		return null;
	}
	$j = json_decode($res, true);
	if (empty($j['ok'])) {
		$GLOBALS['TG_ERROR'] = $j['description'] ?? $res;
		if (strpos($GLOBALS['TG_ERROR'], 'not modified') === false) {
			error_log("tg $method: " . $GLOBALS['TG_ERROR']);
		}
		return null;
	}
	return $j['result'];
}

function h($s): string
{
	return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// [[['Текст', 'data'], ...], ...] → inline-клавиатура
function kb(array $rows): array
{
	$out = [];
	foreach ($rows as $r) {
		$line = [];
		foreach ($r as $b) {
			$line[] = ['text' => $b[0], 'callback_data' => $b[1]];
		}
		if ($line) {
			$out[] = $line;
		}
	}
	return ['inline_keyboard' => $out];
}

// Укладываем текст в лимит Telegram, обрезая по строкам (чтобы не порвать HTML-теги).
function fit_text(string $text, int $limit = 3900): string
{
	if (mb_strlen($text) <= $limit) {
		return $text;
	}
	$lines = explode("\n", $text);
	while ($lines && mb_strlen(implode("\n", $lines)) > $limit - 2) {
		array_pop($lines);
	}
	return implode("\n", $lines) . "\n…";
}

function send($chat, string $text, ?array $kb = null)
{
	$p = ['chat_id' => $chat, 'text' => fit_text($text), 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
	if ($kb) {
		$p['reply_markup'] = $kb;
	}
	return tg('sendMessage', $p);
}

// Показать экран: отредактировать сообщение (если нажата кнопка) или отправить новое.
function show($chat, ?int $messageId, array $screen): void
{
	[$text, $kb] = $screen;
	$p = [
		'chat_id' => $chat,
		'text' => fit_text($text),
		'parse_mode' => 'HTML',
		'disable_web_page_preview' => true,
		'reply_markup' => $kb ?: ['inline_keyboard' => []],
	];
	if ($messageId) {
		$p['message_id'] = $messageId;
		if (tg('editMessageText', $p) !== null || !cfg('bot_token') || strpos($GLOBALS['TG_ERROR'], 'not modified') !== false) {
			return;
		}
		unset($p['message_id']);
	}
	tg('sendMessage', $p);
}

// Уведомление: всегда попадает в ленту на сайте (outbox), а если подключён бот — ещё и в Telegram
function notify_admins(string $text, ?array $kb = null): void
{
	if (!cfg('bot_token')) {
		q('INSERT INTO outbox (ts, chat_id, text) VALUES (?, ?, ?)', [time(), 'site', $text]);
		return;
	}
	foreach (cfg('admin_ids', []) as $id) {
		send($id, $text, $kb);
	}
}
