<?php
// Имитация роутера для проверки сервера и бота без настоящего железа.
// Ходит на сервер так же, как агент tim-agent.sh: регистрация, sync, задачи, отчёт.
//
//   php fake-router.php run  [http://127.0.0.1:8080]   — работать (выход на связь каждые 15 с)
//   php fake-router.php break FREEDOM                  — «заблокировать» ключ секции
//   php fake-router.php fix FREEDOM                    — «починить»
//   php fake-router.php reset                          — забыть всё (зарегистрируется заново)

$stateFile = 'C:/Users/Public/tim-routers/data/fake-router.json';

function load_state(string $file): array
{
	$st = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
	return $st ?: [
		'token' => '',
		'broken' => [],
		'results' => [],
		'sections' => [
			['name' => 'FREEDOM', 'type' => 'proxy', 'config' => 'url', 'key' => 'hy2://demo@203.0.113.10:443', 'domains' => ['openwrt.org', 'trello.com'], 'community' => ['discord', 'telegram'], 'remote' => []],
			['name' => 'KZ', 'type' => 'proxy', 'config' => 'outbound', 'scheme' => 'socks', 'detour' => 'FREEDOM', 'domains' => ['claude.ai'], 'community' => [], 'remote' => []],
			['name' => 'Twitch', 'type' => 'exclusion', 'config' => '', 'domains' => ['twitch.tv'], 'community' => [], 'remote' => []],
		],
	];
}

function save_state(string $file, array $st): void
{
	if (!is_dir(dirname($file))) {
		mkdir(dirname($file), 0700, true);
	}
	file_put_contents($file, json_encode($st, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function post(string $url, string $body, string $token): ?array
{
	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => $body,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 30,
		CURLOPT_HTTPHEADER => ['X-Token: ' . $token, 'Content-Type: application/json'],
	]);
	$res = curl_exec($ch);
	return $res === false ? null : json_decode($res, true);
}

function host_of(string $key): string
{
	$s = preg_replace('#^[a-z0-9]+://#i', '', $key);
	$s = preg_replace('#[/?\#].*$#', '', $s);
	return preg_replace('#^.*@#', '', $s);
}

function section_payload(array $st): array
{
	$out = [];
	foreach ($st['sections'] as $s) {
		$p = [
			'name' => $s['name'], 'type' => $s['type'], 'config' => $s['config'],
			'scheme' => $s['scheme'] ?? (isset($s['key']) ? explode('://', $s['key'])[0] : ''),
			'host' => isset($s['key']) ? host_of($s['key']) : '',
			'detour' => $s['detour'] ?? '', 'delay' => null, 'error' => '',
			'list_type' => 'text', 'domains' => $s['domains'], 'community' => $s['community'], 'remote' => $s['remote'],
		];
		if ($s['type'] === 'proxy') {
			$dead = in_array($s['name'], $st['broken'], true) || (!empty($s['detour']) && in_array($s['detour'], $st['broken'], true));
			if ($dead) {
				$p['error'] = 'context deadline exceeded';
			} else {
				$p['delay'] = random_int(180, 420);
			}
		}
		$out[] = $p;
	}
	return $out;
}

function &find_section(array &$st, string $name)
{
	foreach ($st['sections'] as &$s) {
		if ($s['name'] === $name) {
			return $s;
		}
	}
	$null = null;
	return $null;
}

function run_task(array &$st, array $t, string $server): void
{
	$id = $t['id'];
	$ok = true;
	$msg = '';
	$sec = &find_section($st, (string)($t['section'] ?? ''));
	switch ($t['type']) {
		case 'set_key':
			if (!$sec) { $ok = false; $msg = 'секции нет'; break; }
			if (strpos($t['key'], 'bad') !== false && empty($t['force'])) {
				$ok = false;
				$msg = 'новый ключ не заработал (timeout), вернул прежний';
				break;
			}
			$sec['key'] = $t['key'];
			$st['broken'] = array_values(array_diff($st['broken'], [$sec['name']]));
			$msg = empty($t['force']) ? 'ключ применён, задержка ' . random_int(180, 420) . ' мс' : 'ключ применён без проверки';
			break;
		case 'add_domains':
			if (!$sec) { $ok = false; $msg = 'секции нет'; break; }
			$sec['domains'] = array_values(array_unique(array_merge($sec['domains'], $t['domains'])));
			$msg = 'добавлено: ' . count($t['domains']);
			break;
		case 'remove_domains':
			if (!$sec) { $ok = false; $msg = 'секции нет'; break; }
			$sec['domains'] = array_values(array_diff($sec['domains'], $t['domains']));
			$msg = 'убрано: ' . count($t['domains']);
			break;
		case 'set_community':
			if (!$sec) { $ok = false; $msg = 'секции нет'; break; }
			$sec['community'] = $t['lists'];
			$msg = 'готовые списки обновлены';
			break;
		case 'create_section':
			if (find_section($st, $t['name'])) { $ok = false; $msg = 'такая секция уже есть'; break; }
			$new = ['name' => $t['name'], 'type' => $t['ctype'], 'config' => $t['ctype'] === 'proxy' ? 'url' : '', 'domains' => $t['domains'], 'community' => [], 'remote' => []];
			if ($t['ctype'] === 'proxy') {
				$new['key'] = $t['key'];
			}
			$st['sections'][] = $new;
			$msg = 'секция создана';
			break;
		case 'delete_section':
			$st['sections'] = array_values(array_filter($st['sections'], fn($s) => $s['name'] !== $t['section']));
			$msg = 'секция удалена';
			break;
		case 'upload_report':
			$report = "== Тестовый отчёт имитации роутера ==\n" . date('c') . "\n\n" . json_encode($st['sections'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
			post("$server/api.php?a=upload&task=$id", $report, $st['token']);
			$msg = 'отчёт отправлен';
			break;
		case 'update':
			$msg = 'обновлено до ' . $t['ref'] . ' (имитация)';
			break;
		default:
			$ok = false;
			$msg = 'неизвестная задача';
	}
	unset($sec);
	echo date('H:i:s') . " задача #$id {$t['type']}: " . ($ok ? 'OK' : 'ОШИБКА') . " — $msg\n";
	$st['results'][] = ['id' => $id, 'ok' => $ok, 'msg' => $msg];
}

function sync(array &$st, string $server): ?array
{
	$body = json_encode([
		'info' => [
			'agent' => '1.0-fake', 'model' => 'Имитация роутера', 'openwrt' => '24.10.4', 'podkop' => 'v0.7.23',
			'singbox' => '1.12.12', 'hostname' => 'fake-router', 'uptime' => 3600 + time() % 86400,
			'mem_free' => random_int(25, 40), 'lan_ip' => '192.168.1.1', 'singbox_running' => true,
			'watchdog' => ['installed' => true, 'reboots_row' => 0, 'history' => [date('Y-m-d H:i:s') . ' [uptime 130s] ЗАГРУЗКА: включение питания или ручная перезагрузка']],
		],
		'sections' => section_payload($st),
		'results' => $st['results'],
	], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	$res = post("$server/api.php?a=sync", $body, $st['token']);
	if ($res !== null && isset($res['status'])) {
		$st['results'] = [];
	}
	return $res;
}

$cmd = $argv[1] ?? 'run';
$st = load_state($stateFile);

if ($cmd === 'break' || $cmd === 'fix') {
	$name = $argv[2] ?? 'FREEDOM';
	$st['broken'] = $cmd === 'break' ? array_values(array_unique(array_merge($st['broken'], [$name]))) : array_values(array_diff($st['broken'], [$name]));
	save_state($stateFile, $st);
	echo ($cmd === 'break' ? "Секция $name «заблокирована»" : "Секция $name «починена»") . "\n";
	exit;
}
if ($cmd === 'reset') {
	@unlink($stateFile);
	echo "Состояние сброшено\n";
	exit;
}

$server = rtrim($argv[2] ?? 'http://127.0.0.1:8080', '/');
echo "Имитация роутера → $server. Не закрывайте окно.\n";
echo "Ключ со словом «bad» внутри имитирует неработающий ключ.\n\n";

while (true) {
	$st = load_state($stateFile);
	if ($st['token'] === '') {
		$res = post("$server/api.php?a=register", json_encode(['info' => ['hostname' => 'fake-router', 'model' => 'Имитация роутера', 'openwrt' => '24.10.4', 'podkop' => 'v0.7.23', 'agent' => '1.0-fake']]), '');
		if (!empty($res['token'])) {
			$st['token'] = $res['token'];
			echo date('H:i:s') . " зарегистрирован, жду подтверждения в боте\n";
		} else {
			echo date('H:i:s') . " сервер недоступен\n";
		}
	} else {
		$res = sync($st, $server);
		if ($res === null) {
			echo date('H:i:s') . " сервер недоступен\n";
		} elseif (($res['error'] ?? '') === 'unknown_token') {
			echo date('H:i:s') . " сервер не знает этот роутер — регистрируюсь заново\n";
			$st['token'] = '';
		} else {
			foreach ($res['tasks'] ?? [] as $t) {
				run_task($st, $t, $server);
			}
			if (!empty($res['tasks'])) {
				sync($st, $server);
			}
		}
	}
	save_state($stateFile, $st);
	sleep(15);
}
