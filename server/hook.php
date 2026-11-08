<?php
// Вебхук Telegram (на хостинге). Telegram присылает сюда каждое сообщение боту.

require __DIR__ . '/inc/bootstrap.php';

// Только в админке: в кабинете пользователей этого нет
if (is_service()) {
	http_response_code(404);
	exit;
}

if (!hash_equals((string)cfg('webhook_secret'), (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''))) {
	http_response_code(403);
	exit;
}

$update = json_decode((string)file_get_contents('php://input'), true);
if (is_array($update)) {
	try {
		handle_update($update);
	} catch (Throwable $e) {
		error_log((string)$e);
	}
}
echo 'ok';
