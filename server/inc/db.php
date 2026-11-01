<?php
// База данных (SQLite) и мелкие помощники для запросов.

function data_dir(): string
{
	$dir = cfg('data_dir') ?: ROOT . '/data';
	if (!is_dir($dir)) {
		mkdir($dir, 0700, true);
		// Если папка внутри сайта — закрываем её от просмотра из интернета
		file_put_contents($dir . '/.htaccess', "Require all denied\n");
	}
	return $dir;
}

function db(): PDO
{
	static $pdo = null;
	if ($pdo) {
		return $pdo;
	}
	$pdo = new PDO('sqlite:' . data_dir() . '/routers.sqlite');
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
	$pdo->exec('PRAGMA busy_timeout = 5000');
	$pdo->exec('PRAGMA journal_mode = WAL');
	$pdo->exec("
		CREATE TABLE IF NOT EXISTS routers (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			token_hash TEXT UNIQUE NOT NULL,
			name TEXT NOT NULL DEFAULT '',
			status TEXT NOT NULL DEFAULT 'pending',
			created_at INTEGER NOT NULL,
			last_seen INTEGER,
			ip TEXT,
			info TEXT NOT NULL DEFAULT '{}',
			offline_notified INTEGER NOT NULL DEFAULT 0,
			mute_until INTEGER NOT NULL DEFAULT 0
		);
		CREATE TABLE IF NOT EXISTS sections (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			router_id INTEGER NOT NULL,
			name TEXT NOT NULL,
			pos INTEGER NOT NULL DEFAULT 0,
			data TEXT NOT NULL DEFAULT '{}',
			ok INTEGER,
			fail_since INTEGER,
			notified INTEGER NOT NULL DEFAULT 0,
			last_notify INTEGER,
			reminders INTEGER NOT NULL DEFAULT 0,
			UNIQUE(router_id, name)
		);
		CREATE TABLE IF NOT EXISTS tasks (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			router_id INTEGER NOT NULL,
			type TEXT NOT NULL,
			payload TEXT NOT NULL DEFAULT '{}',
			status TEXT NOT NULL DEFAULT 'queued',
			attempts INTEGER NOT NULL DEFAULT 0,
			created_at INTEGER NOT NULL,
			sent_at INTEGER,
			done_at INTEGER,
			result TEXT
		);
		CREATE TABLE IF NOT EXISTS events (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			router_id INTEGER,
			ts INTEGER NOT NULL,
			text TEXT NOT NULL
		);
		CREATE TABLE IF NOT EXISTS outbox (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			ts INTEGER NOT NULL,
			chat_id TEXT,
			text TEXT NOT NULL
		);
		CREATE TABLE IF NOT EXISTS kv (
			k TEXT PRIMARY KEY,
			v TEXT
		);
	");
	return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
	$st = db()->prepare($sql);
	$st->execute($params);
	return $st;
}

function row(string $sql, array $params = []): ?array
{
	$r = q($sql, $params)->fetch();
	return $r ?: null;
}

function rows(string $sql, array $params = []): array
{
	return q($sql, $params)->fetchAll();
}

function val(string $sql, array $params = [])
{
	return q($sql, $params)->fetchColumn();
}

function kv_get(string $k, $default = null)
{
	$r = row('SELECT v FROM kv WHERE k = ?', [$k]);
	return $r ? json_decode($r['v'], true) : $default;
}

function kv_set(string $k, $v): void
{
	q('INSERT INTO kv (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v', [$k, json_encode($v, JSON_UNESCAPED_UNICODE)]);
}

function kv_del(string $k): void
{
	q('DELETE FROM kv WHERE k = ?', [$k]);
}

function event(?int $routerId, string $text): void
{
	q('INSERT INTO events (router_id, ts, text) VALUES (?, ?, ?)', [$routerId, time(), $text]);
	// Храним только последние 5000 событий
	if (random_int(1, 100) === 1) {
		q('DELETE FROM events WHERE id < (SELECT MAX(id) - 5000 FROM events)');
		q('DELETE FROM outbox WHERE id < (SELECT MAX(id) - 1000 FROM outbox)');
	}
}
