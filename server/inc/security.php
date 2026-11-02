<?php
// Безопасность панели: блокировка подбора пароля, устройства, двухфакторная защита (TOTP),
// уведомления о входах и изменениях, приглашения для новых роутеров, резервные копии.

// ---------- Кто и откуда ----------

function client_ua(): string
{
	return mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300);
}

// «Chrome на Windows», «Safari на iPhone» …
function ua_human(string $ua): string
{
	$os = 'неизвестная система';
	foreach ([
		'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Windows' => 'Windows',
		'Macintosh' => 'Mac', 'CrOS' => 'ChromeOS', 'Linux' => 'Linux',
	] as $k => $v) {
		if (stripos($ua, $k) !== false) {
			$os = $v;
			break;
		}
	}
	$br = 'браузер';
	foreach ([
		'YaBrowser' => 'Яндекс Браузер', 'Edg/' => 'Edge', 'OPR/' => 'Opera', 'Firefox' => 'Firefox',
		'SamsungBrowser' => 'Samsung Internet', 'Chrome' => 'Chrome', 'Safari' => 'Safari',
		'curl' => 'curl', 'python' => 'скрипт Python',
	] as $k => $v) {
		if (stripos($ua, $k) !== false) {
			$br = $v;
			break;
		}
	}
	return "$br на $os";
}

// Город, страна, провайдер; кэш на сутки
function ip_geo(string $ip): array
{
	$cache = kv_get('geo_' . $ip);
	if ($cache && time() - (int)$cache['ts'] < 86400) {
		return $cache;
	}
	$g = ['ts' => time(), 'text' => '', 'proxy' => false];
	if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
		$ch = curl_init('http://ip-api.com/json/' . rawurlencode($ip) . '?lang=ru&fields=status,country,regionName,city,isp,proxy,hosting');
		curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
		$j = json_decode((string)curl_exec($ch), true);
		if (($j['status'] ?? '') === 'success') {
			$place = implode(', ', array_unique(array_filter([$j['country'] ?? '', $j['regionName'] ?? '', $j['city'] ?? ''])));
			$g['text'] = $place . (!empty($j['isp']) ? ' · провайдер ' . $j['isp'] : '');
			$g['proxy'] = !empty($j['proxy']) || !empty($j['hosting']);
		}
	} else {
		$g['text'] = 'локальная сеть';
	}
	kv_set('geo_' . $ip, $g);
	return $g;
}

// Многострочное «кто это»: для уведомлений о входах и изменениях
function who_text(bool $long = false): string
{
	$ip = client_ip();
	$g = ip_geo($ip);
	$t = 'IP: ' . $ip . ($g['text'] !== '' ? ' — ' . $g['text'] : '');
	if ($g['proxy']) {
		$t .= ' ⚠️ (VPN/прокси/хостинг)';
	}
	$t .= "\nУстройство: " . ua_human(client_ua());
	if ($long) {
		$tz = date_default_timezone_get();
		$t .= "\nВремя: " . date('d.m.Y H:i:s') . ' (' . ($tz === 'Europe/Moscow' ? 'МСК' : $tz) . ')';
		$t .= "\nБраузер полностью: " . client_ua();
	}
	return $t;
}

// Уведомление об изменении настроек, сделанном через панель
function audit(string $what): void
{
	event(null, 'Панель: ' . $what . ' (IP ' . client_ip() . ')');
	notify_admins('⚙️ <b>Изменение в панели:</b> ' . h($what) . "\n" . h(who_text()));
}

// ---------- Устройства ----------

const DEVICE_COOKIE = 'tr_dev';
const TRUST_DAYS = 30;

function device_id(): string
{
	$id = (string)($_COOKIE[DEVICE_COOKIE] ?? '');
	if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
		$id = bin2hex(random_bytes(16));
	}
	setcookie(DEVICE_COOKIE, $id, [
		'expires' => time() + 400 * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
		'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
	]);
	return $id;
}

function devices(): array
{
	return kv_get('devices', []);
}

function device_known(string $id): ?array
{
	return devices()[hash('sha256', $id)] ?? null;
}

// Запомнить устройство; $trust — не спрашивать код двухфакторки TRUST_DAYS дней
function device_remember(string $id, bool $trust): bool
{
	$all = devices();
	$k = hash('sha256', $id);
	$new = !isset($all[$k]);
	$d = $all[$k] ?? ['first' => time(), 'trusted_until' => 0];
	$d['last'] = time();
	$d['ip'] = client_ip();
	$d['ua'] = ua_human(client_ua());
	if ($trust) {
		$d['trusted_until'] = time() + TRUST_DAYS * 86400;
	}
	$all[$k] = $d;
	kv_set('devices', $all);
	return $new;
}

function device_trusted(string $id): bool
{
	$d = device_known($id);
	return $d && (int)$d['trusted_until'] > time();
}

// Забыть все устройства (например, если подозреваете кражу пароля)
function devices_forget(): void
{
	kv_set('devices', []);
}

// ---------- Блокировка подбора ----------

const FAIL_LIMIT = 5;
const FAIL_LOCK = 900;

function login_locked(): int
{
	$f = kv_get('fail_' . client_ip());
	if ($f && $f['n'] >= FAIL_LIMIT && time() - $f['ts'] < FAIL_LOCK) {
		return FAIL_LOCK - (time() - $f['ts']);
	}
	return 0;
}

function login_failed(string $what): void
{
	$k = 'fail_' . client_ip();
	$f = kv_get($k);
	if (!$f || time() - $f['ts'] > FAIL_LOCK) {
		$f = ['n' => 0, 'ts' => time()];
	}
	$f['n']++;
	$f['ts'] = time();
	kv_set($k, $f);
	event(null, "Неудачный вход ($what), IP " . client_ip());
	if ($f['n'] === FAIL_LIMIT) {
		notify_admins('🚨 <b>Подбор пароля к панели!</b> ' . FAIL_LIMIT . " неудачных попыток подряд ($what). Вход с этого IP заблокирован на " . (FAIL_LOCK / 60) . " мин.\n" . h(who_text(true)));
	}
}

function login_fail_reset(): void
{
	kv_del('fail_' . client_ip());
}

// ---------- Двухфакторная защита (TOTP, Google Authenticator) ----------

function totp_settings(): array
{
	return kv_get('totp', []) + ['enabled' => false, 'secret' => '', 'backup' => []];
}

function base32_encode(string $bin): string
{
	$alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	$bits = '';
	foreach (str_split($bin) as $c) {
		$bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
	}
	$out = '';
	foreach (str_split($bits, 5) as $chunk) {
		$out .= $alpha[bindec(str_pad($chunk, 5, '0'))];
	}
	return $out;
}

function base32_decode(string $s): string
{
	$alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	$bits = '';
	foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s))) as $c) {
		$bits .= str_pad(decbin(strpos($alpha, $c)), 5, '0', STR_PAD_LEFT);
	}
	$out = '';
	foreach (str_split($bits, 8) as $byte) {
		if (strlen($byte) === 8) {
			$out .= chr(bindec($byte));
		}
	}
	return $out;
}

function totp_code(string $secret, int $step): string
{
	$h = hash_hmac('sha1', pack('N*', 0, $step), base32_decode($secret), true);
	$o = ord($h[19]) & 0xf;
	$n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
	return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
}

// Код из приложения (допускаем расхождение часов ±30 с) или одноразовый резервный код
function totp_check(string $input): bool
{
	$t = totp_settings();
	$code = preg_replace('/\s+/', '', $input);
	if (preg_match('/^\d{6}$/', $code)) {
		$step = intdiv(time(), 30);
		foreach ([0, -1, 1] as $d) {
			if (hash_equals(totp_code($t['secret'], $step + $d), $code)) {
				// Один и тот же код дважды не принимаем
				if ((int)kv_get('totp_last_step', 0) >= $step + $d) {
					return false;
				}
				kv_set('totp_last_step', $step + $d);
				return true;
			}
		}
		return false;
	}
	$hash = hash('sha256', strtolower($code));
	if (in_array($hash, $t['backup'], true)) {
		$t['backup'] = array_values(array_diff($t['backup'], [$hash]));
		kv_set('totp', $t);
		notify_admins('🔑 Для входа в панель использован резервный код. Осталось: ' . count($t['backup']) . ".\n" . h(who_text()));
		return true;
	}
	return false;
}

// Проверка кода для конкретного секрета (при включении — секрет ещё не сохранён)
function totp_verify_secret(string $secret, string $input): bool
{
	$code = preg_replace('/\s+/', '', $input);
	if (!preg_match('/^\d{6}$/', $code)) {
		return false;
	}
	$step = intdiv(time(), 30);
	foreach ([0, -1, 1] as $d) {
		if (hash_equals(totp_code($secret, $step + $d), $code)) {
			return true;
		}
	}
	return false;
}

// 8 одноразовых резервных кодов: показываем один раз, храним только хэши
function totp_new_backup(): array
{
	$codes = [];
	for ($i = 0; $i < 8; $i++) {
		$codes[] = substr(bin2hex(random_bytes(5)), 0, 10);
	}
	$t = totp_settings();
	$t['backup'] = array_map(fn($c) => hash('sha256', $c), $codes);
	kv_set('totp', $t);
	return $codes;
}

function totp_uri(string $secret): string
{
	$host = parse_url((string)cfg('base_url'), PHP_URL_HOST) ?: 'panel';
	return 'otpauth://totp/' . rawurlencode('Роутеры:' . $host) . '?secret=' . $secret . '&issuer=' . rawurlencode('Роутеры');
}

// ---------- Вход ----------

function complete_login(bool $passed2fa): void
{
	session_regenerate_id(true);
	$_SESSION['ok'] = true;
	$_SESSION['csrf'] = bin2hex(random_bytes(16));
	unset($_SESSION['pw_ok']);
	login_fail_reset();
	$dev = device_id();
	$new = device_remember($dev, $passed2fa);
	event(null, 'Вход в панель' . ($new ? ' с нового устройства' : '') . ', IP ' . client_ip());
	$tfa = totp_settings()['enabled'] ? ($passed2fa ? 'код подтверждён' : 'доверенное устройство, код не спрашивался') : 'не включена';
	notify_admins(
		($new ? '🆕🔐 <b>Вход в панель с НОВОГО устройства</b>' : '🔐 <b>Вход в панель</b>') . "\n" .
		h(who_text(true)) . "\nДвухфакторная защита: " . $tfa .
		"\n\nЕсли это были не вы — смените пароль панели и нажмите «Забыть все устройства» в настройках."
	);
}

// ---------- Приглашения для новых роутеров ----------

const INVITE_TTL = 86400;

function invite_create(): string
{
	$code = bin2hex(random_bytes(6));
	$all = array_filter(kv_get('invites', []), fn($i) => $i['expires'] > time() && empty($i['used']));
	$all[hash('sha256', $code)] = ['created' => time(), 'expires' => time() + INVITE_TTL, 'used' => 0];
	kv_set('invites', $all);
	return $code;
}

// true — код действителен (и теперь израсходован)
function invite_use(string $code, int $routerId): bool
{
	$all = kv_get('invites', []);
	$k = hash('sha256', $code);
	if (!isset($all[$k]) || $all[$k]['expires'] < time() || !empty($all[$k]['used'])) {
		return false;
	}
	$all[$k]['used'] = $routerId ?: 1;
	kv_set('invites', $all);
	return true;
}

// ---------- Резервные копии базы (раз в сутки, храним 14) ----------

function backup_daily(): void
{
	$day = date('Y-m-d');
	if (kv_get('backup_day') === $day) {
		return;
	}
	$dir = data_dir() . '/backups';
	if (!is_dir($dir)) {
		mkdir($dir, 0700, true);
	}
	$file = "$dir/routers-$day.sqlite";
	if (!is_file($file)) {
		db()->exec('VACUUM INTO ' . db()->quote($file));
	}
	$all = glob("$dir/routers-*.sqlite") ?: [];
	rsort($all);
	foreach (array_slice($all, 14) as $old) {
		@unlink($old);
	}
	kv_set('backup_day', $day);
}
