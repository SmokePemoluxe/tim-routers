<?php
// Только для локального запуска (php -S): закрывает служебные файлы, как .htaccess на хостинге.
$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (preg_match('#^/(inc|data)(/|$)|^/(config|config\.sample|poll|dev-router)\.php$|\.sqlite#i', $path)) {
	http_response_code(403);
	exit('Forbidden');
}
return false;
