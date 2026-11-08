<?php
// API для агентов на роутерах.
//   POST api.php?a=register          — новый роутер (тело: {"info": {...}})
//   POST api.php?a=sync              — состояние + результаты задач, в ответ — новые задачи
//   POST api.php?a=upload&task=ID    — отчёт с роутера (тело — текст)
// Роутер подписывается заголовком X-Token.

require __DIR__ . '/inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function out(array $data): void
{
	echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	http_response_code(405);
	out(['error' => 'post_only']);
}

$action = (string)($_GET['a'] ?? '');
$raw = (string)file_get_contents('php://input', false, null, 0, 2 * 1024 * 1024);

try {
	if ($action === 'register') {
		$in = json_decode($raw, true);
		out(api_register(is_array($in) ? $in : []));
	}

	$token = (string)($_SERVER['HTTP_X_TOKEN'] ?? '');
	$router = $token !== '' ? row('SELECT * FROM routers WHERE token_hash = ?', [hash('sha256', $token)]) : null;
	if (!$router) {
		http_response_code(401);
		out(['error' => 'unknown_token']);
	}
	// Дальше всё (журнал, уведомления) — от имени владельца роутера
	set_uid(max(0, (int)$router['user_id']));

	if ($action === 'sync') {
		$in = json_decode($raw, true);
		if (!is_array($in)) {
			http_response_code(400);
			out(['error' => 'bad_json']);
		}
		out(api_sync($router, $in));
	}

	if ($action === 'upload') {
		out(api_upload($router, (int)($_GET['task'] ?? 0), $raw));
	}

	http_response_code(404);
	out(['error' => 'unknown_action']);
} catch (Throwable $e) {
	error_log((string)$e);
	http_response_code(500);
	out(['error' => 'server_error']);
}
