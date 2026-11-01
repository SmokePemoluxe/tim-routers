<?php
// Проверка хостинга для бота. После проверки удалите этот файл с сервера.
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(120);

echo "PHP: " . PHP_VERSION . "\n";
echo "Сервер: " . (isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '?') . "\n";
echo "HTTPS: " . ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'да' : 'нет') . "\n\n";

foreach (array('pdo_sqlite', 'sqlite3', 'pdo_mysql', 'curl', 'openssl', 'json', 'mbstring') as $ext) {
	echo str_pad($ext, 12) . (extension_loaded($ext) ? 'OK' : 'НЕТ') . "\n";
}
echo str_pad('запись', 12) . (is_writable(__DIR__) ? 'OK' : 'НЕТ') . "\n\n";

function probe($url, $resolve = null) {
	if (!function_exists('curl_init')) {
		return 'нет curl';
	}
	$ch = curl_init($url);
	$opt = array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_NOBODY => true,
		CURLOPT_CONNECTTIMEOUT => 15,
		CURLOPT_TIMEOUT => 20,
		CURLOPT_FOLLOWLOCATION => true,
	);
	if ($resolve) {
		$opt[CURLOPT_RESOLVE] = array($resolve);
	}
	curl_setopt_array($ch, $opt);
	$t = microtime(true);
	curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$ip = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
	$err = curl_error($ch);
	$sec = round(microtime(true) - $t, 1);
	return ($code ? "HTTP $code" : "ошибка: $err") . " ($ip, {$sec} с)";
}

echo "DNS api.telegram.org: " . implode(', ', gethostbynamel('api.telegram.org') ?: array('не резолвится')) . "\n";
echo "Telegram API:            " . probe('https://api.telegram.org/') . "\n";
echo "Telegram API (149.154.167.220): " . probe('https://api.telegram.org/', 'api.telegram.org:443:149.154.167.220') . "\n";
echo "Telegram API (149.154.166.110): " . probe('https://api.telegram.org/', 'api.telegram.org:443:149.154.166.110') . "\n";
echo "telegram.org (сайт):     " . probe('https://telegram.org/') . "\n";
echo "GitHub raw:              " . probe('https://raw.githubusercontent.com/') . "\n";
echo "Cloudflare:              " . probe('https://1.1.1.1/') . "\n";
