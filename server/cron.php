<?php
// Периодические проверки: кто пропал со связи, у кого не работают секции, напоминания.
// На хостинге — запускать раз в минуту из cron:
//   php /путь/к/сайту/cron.php
// или по HTTP: https://домен/cron.php?key=<webhook_secret>

require __DIR__ . '/inc/bootstrap.php';

// Только в админке: в кабинете пользователей этого нет
if (is_service()) {
	http_response_code(404);
	exit;
}

if (PHP_SAPI !== 'cli' && !hash_equals((string)cfg('webhook_secret'), (string)($_GET['key'] ?? ''))) {
	http_response_code(403);
	exit;
}

check_alerts();
echo "ok\n";
