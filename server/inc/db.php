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
	db_migrate($pdo);
	return $pdo;
}

// Изменения схемы для сервиса с пользователями. Старые данные принадлежат администратору (владелец 0).
function db_migrate(PDO $pdo): void
{
	$ver = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
	if ($ver >= 2) {
		return;
	}
	if ($ver === 1) {
		// v2: баланс кабинета (в копейках) и история операций по нему
		$pdo->exec('BEGIN IMMEDIATE');
		try {
			if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() === 1) {
				$pdo->exec("
					ALTER TABLE users ADD COLUMN balance INTEGER NOT NULL DEFAULT 0;
					CREATE TABLE IF NOT EXISTS payments (
						id INTEGER PRIMARY KEY AUTOINCREMENT,
						user_id INTEGER NOT NULL,
						ts INTEGER NOT NULL,
						amount INTEGER NOT NULL,
						kind TEXT NOT NULL,
						comment TEXT NOT NULL DEFAULT ''
					);
					CREATE INDEX IF NOT EXISTS payments_user ON payments(user_id, id);
				");
				$pdo->exec('PRAGMA user_version = 2');
			}
			$pdo->exec('COMMIT');
		} catch (Throwable $e) {
			$pdo->exec('ROLLBACK');
			throw $e;
		}
		return;
	}
	$pdo->exec('BEGIN IMMEDIATE');
	try {
		// Пока ждали блокировку, другой запрос мог уже всё сделать
		if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() >= 1) {
			$pdo->exec('COMMIT');
			return;
		}
		$cols = function (string $t) use ($pdo): array {
			return array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
		};
		foreach (['routers', 'events', 'outbox'] as $t) {
			if (!in_array('user_id', $cols($t), true)) {
				$pdo->exec("ALTER TABLE $t ADD COLUMN user_id INTEGER NOT NULL DEFAULT 0");
			}
		}
		$pdo->exec("
			CREATE INDEX IF NOT EXISTS routers_user ON routers(user_id);
			CREATE INDEX IF NOT EXISTS events_user ON events(user_id, id);
			CREATE INDEX IF NOT EXISTS outbox_user ON outbox(user_id, id);
			CREATE TABLE IF NOT EXISTS users (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				email TEXT NOT NULL UNIQUE COLLATE NOCASE,
				pass_hash TEXT NOT NULL,
				created_at INTEGER NOT NULL,
				verified_at INTEGER,
				status TEXT NOT NULL DEFAULT 'active',
				sess_ver INTEGER NOT NULL DEFAULT 1,
				last_login INTEGER,
				last_ip TEXT,
				reg_ip TEXT,
				paid_until INTEGER NOT NULL DEFAULT 0,
				paid_ever INTEGER NOT NULL DEFAULT 0,
				free INTEGER NOT NULL DEFAULT 0,
				router_limit INTEGER NOT NULL DEFAULT 0,
				support_until INTEGER NOT NULL DEFAULT 0,
				consent_at INTEGER,
				consent_ver TEXT,
				admin_note TEXT NOT NULL DEFAULT ''
			);
			CREATE TABLE IF NOT EXISTS tokens (
				hash TEXT PRIMARY KEY,
				user_id INTEGER NOT NULL,
				type TEXT NOT NULL,
				expires INTEGER NOT NULL
			);
			CREATE TABLE IF NOT EXISTS tickets (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				user_id INTEGER NOT NULL,
				router_id INTEGER,
				subject TEXT NOT NULL,
				status TEXT NOT NULL DEFAULT 'open',
				created_at INTEGER NOT NULL,
				updated_at INTEGER NOT NULL
			);
			CREATE TABLE IF NOT EXISTS ticket_msgs (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				ticket_id INTEGER NOT NULL,
				from_admin INTEGER NOT NULL DEFAULT 0,
				ts INTEGER NOT NULL,
				text TEXT NOT NULL
			);
		");
		$pdo->exec('PRAGMA user_version = 1');
		$pdo->exec('COMMIT');
	} catch (Throwable $e) {
		$pdo->exec('ROLLBACK');
		throw $e;
	}
	db_migrate($pdo); // следующие версии схемы
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

// Событие в журнал владельца: роутера (если указан) или того, кто сейчас работает в панели
function event(?int $routerId, string $text): void
{
	$uid = $routerId ? (int)val('SELECT user_id FROM routers WHERE id = ?', [$routerId]) : cur_uid();
	q('INSERT INTO events (router_id, ts, text, user_id) VALUES (?, ?, ?, ?)', [$routerId, time(), $text, $uid]);
	// Журнал и ленту храним 90 дней (так и написано в политике конфиденциальности)
	if (random_int(1, 100) === 1) {
		q('DELETE FROM events WHERE ts < ?', [time() - 90 * 86400]);
		q('DELETE FROM outbox WHERE ts < ?', [time() - 90 * 86400]);
	}
}
