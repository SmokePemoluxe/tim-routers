<?php
// Локальный запуск бота (без домена): забирает сообщения у Telegram сам
// и раз в минуту выполняет проверки, как cron на хостинге.
//   php poll.php

require __DIR__ . '/inc/bootstrap.php';

// Только в админке: в кабинете пользователей этого нет
if (is_service()) {
	http_response_code(404);
	exit;
}

if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit;
}

$hasToken = (bool)cfg('bot_token');
if ($hasToken) {
	$info = tg('getWebhookInfo');
	if ($info === null) {
		echo "Не удалось связаться с Telegram: {$GLOBALS['TG_ERROR']}\n";
		echo "Проверьте bot_token в config.php и доступ к api.telegram.org.\n";
		exit(1);
	}
	if (!empty($info['url'])) {
		echo "У бота уже настроен вебхук: {$info['url']}\n";
		echo "Локальный бот не запускаю, иначе сломается бот на хостинге.\n";
		exit(1);
	}
	$me = tg('getMe');
	echo 'Бот @' . ($me['username'] ?? '?') . " запущен. Не закрывайте это окно.\n";
} else {
	echo "bot_token в config.php не задан — бот не подключён к Telegram.\n";
	echo "Работают только проверки; сообщения бота видны в веб-панели.\n";
}

$offset = (int)kv_get('poll_offset', 0);
$lastCron = 0;

while (true) {
	if (time() - $lastCron >= 60) {
		try {
			check_alerts();
		} catch (Throwable $e) {
			echo date('H:i:s') . ' check_alerts: ' . $e->getMessage() . "\n";
		}
		$lastCron = time();
	}

	if (!$hasToken) {
		sleep(10);
		continue;
	}

	$updates = tg('getUpdates', ['offset' => $offset, 'timeout' => 25, 'allowed_updates' => ['message', 'callback_query']]);
	if ($updates === null) {
		echo date('H:i:s') . " ошибка Telegram: {$GLOBALS['TG_ERROR']}\n";
		sleep(5);
		continue;
	}
	foreach ($updates as $u) {
		$offset = (int)$u['update_id'] + 1;
		kv_set('poll_offset', $offset);
		try {
			handle_update($u);
		} catch (Throwable $e) {
			echo date('H:i:s') . ' ошибка: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
		}
	}
}
