<?php
// Перевод ссылки-ключа (hy2://, vless://, trojan://, ss://, socks5://) в outbound sing-box.
// Нужен для цепочек «секция через секцию»: в ссылке нельзя указать detour, а в outbound — можно.

// Ищет в тексте первую ссылку-ключ (для пересланных сообщений)
function extract_key(string $text): ?string
{
	if (preg_match('#\b(' . KEY_SCHEMES . ')://[^\s"\'<>`\\\\]+#', $text, $m)) {
		return rtrim($m[0], '.,;)');
	}
	return null;
}

function key_host(string $key): string
{
	$s = preg_replace('#^[a-z0-9]+://#i', '', $key);
	$s = preg_replace('#[/?\#].*$#', '', $s);
	return preg_replace('#^.*@#', '', $s);
}

function b64decode(string $s): string|false
{
	$s = strtr(rawurldecode($s), '-_', '+/');
	return base64_decode($s . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
}

// $section — имя секции podkop (тег выхода будет «<секция>-out»), $detour — имя секции, через которую идти.
// Возвращает ['ok' => true, 'json' => строка] или ['ok' => false, 'error' => текст]
function link_to_outbound(string $link, string $section, string $detour = ''): array
{
	$fail = fn(string $e) => ['ok' => false, 'error' => $e];
	$tag = $section . '-out';
	$scheme = strtolower((string)strstr($link, '://', true));

	// ss://BASE64(method:password@host:port)#name — старый формат
	if ($scheme === 'ss') {
		$body = substr($link, 5);
		$main = explode('#', $body, 2)[0];
		if (strpos($main, '@') === false) {
			$dec = b64decode(explode('?', $main, 2)[0]);
			if ($dec === false) {
				return $fail('не удалось разобрать ss-ключ');
			}
			$link = 'ss://' . $dec;
		}
	}

	$u = parse_url($link);
	if (!$u || empty($u['host'])) {
		return $fail('не удалось разобрать ключ');
	}
	$q = [];
	parse_str($u['query'] ?? '', $q);
	$host = trim($u['host'], '[]');
	$port = (int)($u['port'] ?? 443);
	if ($port < 1 || $port > 65535) {
		return $fail('неверный порт в ключе');
	}
	$user = isset($u['user']) ? rawurldecode($u['user']) : '';
	$pass = isset($u['pass']) ? rawurldecode($u['pass']) : null;

	$tls = function (string $defaultSni) use ($q): array {
		$t = ['enabled' => true, 'server_name' => (string)($q['sni'] ?? $q['peer'] ?? $defaultSni)];
		if (($q['insecure'] ?? $q['allowInsecure'] ?? '') === '1') {
			$t['insecure'] = true;
		}
		if (!empty($q['alpn'])) {
			$t['alpn'] = array_values(array_filter(explode(',', (string)$q['alpn'])));
		}
		if (!empty($q['fp'])) {
			$t['utls'] = ['enabled' => true, 'fingerprint' => (string)$q['fp']];
		}
		return $t;
	};
	$transport = function () use ($q): array|string|null {
		switch ((string)($q['type'] ?? 'tcp')) {
			case 'tcp':
			case 'raw':
			case '':
				return null;
			case 'ws':
				$t = ['type' => 'ws', 'path' => (string)($q['path'] ?? '/')];
				if (!empty($q['host'])) {
					$t['headers'] = ['Host' => (string)$q['host']];
				}
				return $t;
			case 'grpc':
				return ['type' => 'grpc', 'service_name' => (string)($q['serviceName'] ?? '')];
			case 'httpupgrade':
				return ['type' => 'httpupgrade', 'path' => (string)($q['path'] ?? '/'), 'host' => (string)($q['host'] ?? '')];
			case 'http':
			case 'h2':
				$t = ['type' => 'http', 'path' => (string)($q['path'] ?? '/')];
				if (!empty($q['host'])) {
					$t['host'] = [(string)$q['host']];
				}
				return $t;
		}
		return 'транспорт «' . $q['type'] . '» не поддерживается';
	};

	switch ($scheme) {
		case 'hy2':
		case 'hysteria2':
			$o = ['type' => 'hysteria2', 'tag' => $tag, 'server' => $host];
			if (!empty($q['mport'])) {
				// Смена портов (port hopping): «30000-50000» → «30000:50000»
				$ports = [];
				foreach (explode(',', (string)$q['mport']) as $r) {
					if (!preg_match('/^(\d+)(?:-(\d+))?$/', trim($r), $m)) {
						return $fail('не удалось разобрать mport');
					}
					$ports[] = $m[1] . ':' . ($m[2] ?? $m[1]);
				}
				$o['server_ports'] = $ports;
				$o['hop_interval'] = '30s';
			} else {
				$o['server_port'] = $port;
			}
			$o['password'] = $pass === null ? $user : "$user:$pass";
			if (($q['obfs'] ?? '') === 'salamander') {
				$o['obfs'] = ['type' => 'salamander', 'password' => (string)($q['obfs-password'] ?? '')];
			}
			$o['tls'] = $tls($host);
			unset($o['tls']['utls']);
			break;

		case 'vless':
		case 'trojan':
			$o = ['type' => $scheme, 'tag' => $tag, 'server' => $host, 'server_port' => $port];
			if ($scheme === 'vless') {
				$o['uuid'] = $user;
				if (!empty($q['flow'])) {
					$o['flow'] = (string)$q['flow'];
				}
			} else {
				$o['password'] = $user;
			}
			$sec = (string)($q['security'] ?? ($scheme === 'trojan' ? 'tls' : 'none'));
			if ($sec === 'tls' || $sec === 'reality') {
				$o['tls'] = $tls($host);
				if ($sec === 'reality') {
					if (empty($q['pbk'])) {
						return $fail('в ключе reality нет pbk');
					}
					$o['tls']['reality'] = ['enabled' => true, 'public_key' => (string)$q['pbk'], 'short_id' => (string)($q['sid'] ?? '')];
					$o['tls']['utls'] = ['enabled' => true, 'fingerprint' => (string)($q['fp'] ?? 'chrome')];
				}
			} elseif ($sec !== 'none') {
				return $fail("security=$sec не поддерживается");
			}
			$tr = $transport();
			if (is_string($tr)) {
				return $fail($tr);
			}
			if ($tr) {
				$o['transport'] = $tr;
			}
			break;

		case 'ss':
			if (!empty($q['plugin'])) {
				return $fail('ss с плагином не поддерживается');
			}
			if ($pass === null) {
				$dec = b64decode($u['user'] ?? '');
				if ($dec === false || strpos($dec, ':') === false) {
					return $fail('не удалось разобрать метод и пароль ss');
				}
				[$method, $password] = explode(':', $dec, 2);
			} else {
				[$method, $password] = [$user, $pass];
			}
			$o = ['type' => 'shadowsocks', 'tag' => $tag, 'server' => $host, 'server_port' => $port, 'method' => $method, 'password' => $password];
			break;

		case 'socks4':
		case 'socks5':
			$o = ['type' => 'socks', 'tag' => $tag, 'server' => $host, 'server_port' => $port, 'version' => $scheme === 'socks4' ? '4' : '5'];
			if ($user !== '') {
				$o['username'] = $user;
				$o['password'] = (string)$pass;
			}
			break;

		default:
			return $fail("для ключей $scheme:// цепочка пока не поддерживается");
	}

	if ($detour !== '') {
		$o['detour'] = $detour . '-out';
	}
	return ['ok' => true, 'json' => json_encode($o, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
}
