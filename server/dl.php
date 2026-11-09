<?php
// Запасной источник скриптов установки: если роутер не может скачать их с GitHub напрямую
// (IPv6 без маршрута, блокировка), он берёт их отсюда. Сервер сам скачивает файлы с GitHub (ветка main)
// и отдаёт только три известных файла — ничего своего в них не добавляет.

require __DIR__ . '/inc/bootstrap.php';

const DL_FILES = ['install.sh', 'tim-agent.sh', 'inet-watchdog.sh'];

$f = (string)($_GET['f'] ?? '');
if (!in_array($f, DL_FILES, true)) {
	http_response_code(404);
	exit("not found\n");
}

$dir = data_dir() . '/dl-cache';
if (!is_dir($dir)) {
	mkdir($dir, 0700, true);
}
$cache = $dir . '/' . $f;

// Кэш на 10 минут: обновления на GitHub доходят быстро, а GitHub не дёргаем на каждый роутер
if (!is_file($cache) || time() - filemtime($cache) > 600) {
	$ch = curl_init('https://raw.githubusercontent.com/' . GITHUB_REPO . '/main/router/' . $f);
	curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => true]);
	$body = curl_exec($ch);
	$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	// Берём только то, что похоже на наш скрипт
	if ($code === 200 && is_string($body) && strncmp($body, "#!/bin/sh", 9) === 0 && strlen($body) < 2 * 1024 * 1024) {
		file_put_contents($cache . '.tmp', $body);
		rename($cache . '.tmp', $cache);
	} elseif (!is_file($cache)) {
		http_response_code(502);
		exit("GitHub недоступен с сервера, попробуйте позже\n");
	}
}

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
readfile($cache);
