<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));

$cfgFile = ROOT . '/config.php';
if (!is_file($cfgFile)) {
	http_response_code(500);
	exit("Нет config.php — скопируйте config.sample.php в config.php и заполните.\n");
}
$GLOBALS['CFG'] = require $cfgFile;

function cfg(string $key, $default = null)
{
	return $GLOBALS['CFG'][$key] ?? $default;
}

date_default_timezone_set(cfg('timezone', 'Europe/Moscow'));

require __DIR__ . '/db.php';
require __DIR__ . '/tg.php';
require __DIR__ . '/logic.php';
require __DIR__ . '/links.php';
require __DIR__ . '/vk.php';
require __DIR__ . '/security.php';
require __DIR__ . '/bot.php';
