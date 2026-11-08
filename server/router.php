<?php
// Редактор настроек podkop роутера: секции, списки, ключи, DNS → «Отправить на роутер».

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/panel.php';

panel_start();
panel_require();

const MIN_AGENT = '2.0';
const SUB_OPTS_AGENT = '2.2'; // выбор стран, закрепление, переключение сервера
const SUB_STICKY_AGENT = '2.3'; // «держаться за текущий сервер»

$rid = (int)($_GET['id'] ?? 0);
$router = router_for_page($rid); // чужой роутер — «не найден»
if (!$router || $router['status'] !== 'active') {
	panel_header('Роутер не найден');
	echo '<div class="card">Роутер не найден. <a href="index.php">К списку</a></div>';
	panel_footer();
	exit;
}
$info = rinfo($router);
$agentOk = version_compare((string)($info['agent'] ?? '0'), MIN_AGENT, '>=');
$subOptsOk = version_compare((string)($info['agent'] ?? '0'), SUB_OPTS_AGENT, '>=');
$subStickyOk = version_compare((string)($info['agent'] ?? '0'), SUB_STICKY_AGENT, '>=');

// Кто смотрит: владелец; администратор с разрешением пользователя (поддержка); администратор без разрешения — только просмотр
$acc = router_access($router);
$owner = (int)$router['user_id'] > 0 ? get_user((int)$router['user_id']) : null;
$readonlyWhy = '';
if ($acc === 'view') {
	// Без разрешения пользователя администратор не видит ни настроек, ни данных его роутера
	panel_header('Нет доступа');
	echo '<div class="card"><h2>🔒 Роутер пользователя</h2><p>Настройки и данные роутеров пользователей видны только с их разрешения. Попросите пользователя открыть доступ поддержке — в обращении или в «Настройках» кабинета.</p><p><a href="users.php?id=' . (int)$router['user_id'] . '">← К пользователю</a></p></div>';
	panel_footer();
	exit;
} elseif (is_service() && !user_can_manage(cur_user())) {
	$readonlyWhy = 'Подписка не активна — роутер работает как настроен, но менять настройки нельзя. Как продлить — в «Настройках».';
}
$canEdit = $readonlyWhy === '';

// ---------- Модель секций для формы ----------

function sections_from_db(int $rid): array
{
	$out = [];
	$byName = by_name(router_sections($rid));
	foreach (router_sections($rid) as $s) {
		$d = sdata($s);
		$out[] = [
			'orig' => $s['name'],
			'name' => $s['name'],
			'type' => $d['type'] ?? '',
			'config' => $d['config'] ?? '',
			'status' => section_line($s, $byName),
			'detour' => (string)($d['detour'] ?? ''),
			'interface' => (string)($d['interface'] ?? ''),
			'dr_enabled' => !empty($d['dr_enabled']),
			'dr_type' => (string)($d['dr_type'] ?? ''),
			'dr_server' => (string)($d['dr_server'] ?? ''),
			'cur_detour' => (string)($d['detour'] ?? ''),
			'key' => '',
			'sub' => is_array($d['sub'] ?? null) ? $d['sub'] : null,
			'source' => is_array($d['sub'] ?? null) ? 'sub' : 'key',
			'sub_url' => '',
			'sub_exclude' => is_array($d['sub'] ?? null) ? (string)($d['sub']['exclude'] ?? '') : 'Россия',
			'sub_only' => (string)($d['sub']['only'] ?? ''),
			'sub_fallback' => (string)($d['sub']['fallback'] ?? 'any'),
			'sub_pin' => (string)($d['sub']['pin'] ?? ''),
			'sub_policy' => (string)($d['sub']['policy'] ?? '6h'),
			'sub_sticky' => !empty($d['sub']['sticky']),
			'domains' => implode("\n", $d['domains'] ?? []),
			'subnets' => implode("\n", $d['subnets'] ?? []),
			'community' => $d['community'] ?? [],
			'remote' => implode("\n", $d['remote'] ?? []),
			'delete' => false,
		];
	}
	return $out;
}

function sections_from_post(int $rid): array
{
	$db = [];
	foreach (sections_from_db($rid) as $s) {
		$db[$s['orig']] = $s;
	}
	$out = [];
	foreach ((array)($_POST['sections'] ?? []) as $p) {
		if (!is_array($p)) {
			continue;
		}
		$orig = (string)($p['orig'] ?? '');
		$base = $db[$orig] ?? ['config' => 'url', 'status' => '', 'cur_detour' => '', 'sub' => null];
		$out[] = [
			'orig' => $orig,
			'name' => $orig !== '' ? $orig : trim((string)($p['name'] ?? '')),
			'type' => (string)($p['type'] ?? ''),
			'config' => $base['config'],
			'status' => $base['status'],
			'detour' => trim((string)($p['detour'] ?? '')),
			'interface' => trim((string)($p['interface'] ?? '')),
			'dr_enabled' => !empty($p['dr_enabled']),
			'dr_type' => (string)($p['dr_type'] ?? 'doh'),
			'dr_server' => trim((string)($p['dr_server'] ?? '')),
			'cur_detour' => $base['cur_detour'],
			'key' => trim((string)($p['key'] ?? '')),
			'sub' => $base['sub'],
			'source' => ($p['source'] ?? 'key') === 'sub' ? 'sub' : 'key',
			'sub_url' => trim((string)($p['sub_url'] ?? '')),
			'sub_exclude' => trim((string)($p['sub_exclude'] ?? '')),
			'sub_only' => trim((string)($p['sub_only'] ?? '')),
			'sub_fallback' => ($p['sub_fallback'] ?? '') === 'strict' ? 'strict' : 'any',
			'sub_pin' => trim((string)($p['sub_pin'] ?? '')),
			'sub_policy' => in_array($p['sub_policy'] ?? '', ['6h', '24h', 'dead', 'off'], true) ? $p['sub_policy'] : '6h',
			'sub_sticky' => !empty($p['sub_sticky']),
			'domains' => (string)($p['domains'] ?? ''),
			'subnets' => (string)($p['subnets'] ?? ''),
			'community' => array_values(array_filter((array)($p['community'] ?? []), fn($l) => is_string($l) && preg_match('/^[a-z0-9_]{1,30}$/', $l))),
			'remote' => (string)($p['remote'] ?? ''),
			'delete' => !empty($p['delete']),
		];
	}
	return $out;
}

function split_lines(string $text): array
{
	$out = [];
	foreach (preg_split('/[\s,;]+/u', trim($text)) as $x) {
		if ($x !== '') {
			$out[$x] = true;
		}
	}
	return array_keys($out);
}

// Проверка формы → [задача, ошибки]
function build_payload(array $sections, array $settings): array
{
	$errors = [];
	$payload = ['settings' => [], 'sections' => [], 'delete' => []];

	if (!in_array($settings['dns_type'], ['doh', 'dot', 'udp'], true)) {
		$errors[] = 'Тип DNS: выберите DoH, DoT или UDP';
	}
	foreach (['dns_server' => 'DNS-сервер', 'bootstrap_dns_server' => 'Bootstrap DNS-сервер'] as $k => $label) {
		if (!preg_match('#^[A-Za-z0-9.:/_-]{2,100}$#', $settings[$k])) {
			$errors[] = "$label: недопустимое значение";
		}
	}
	$payload['settings'] = $settings;

	// Через какие секции можно пускать: существующие (не новые и не удаляемые) секции «через прокси»
	$targets = [];
	foreach ($sections as $s) {
		if ($s['orig'] !== '' && !$s['delete'] && in_array($s['type'], ['proxy', 'vpn'], true)) {
			$targets[$s['name']] = $s['detour'];
		}
	}
	// Петли вида «A через B, B через A»
	foreach ($targets as $start => $_) {
		$seen = [$start => true];
		$cur = $targets[$start];
		while ($cur !== '' && isset($targets[$cur])) {
			if (isset($seen[$cur])) {
				$errors[] = "Цепочка секций зациклена: {$start} → … → {$cur}";
				break 2;
			}
			$seen[$cur] = true;
			$cur = $targets[$cur];
		}
	}

	$names = [];
	foreach ($sections as $s) {
		$title = $s['name'] !== '' ? $s['name'] : 'новая секция';
		if ($s['delete']) {
			if ($s['orig'] !== '') {
				$payload['delete'][] = $s['orig'];
			}
			continue;
		}
		if (!valid_section_name($s['name']) || in_array($s['name'], ['settings', 'main'], true) && $s['orig'] === '') {
			$errors[] = "«{$title}»: имя — латиница, цифры и _, начинается с буквы, до 30 символов";
			continue;
		}
		if (isset($names[$s['name']])) {
			$errors[] = "Секция {$s['name']} указана дважды";
			continue;
		}
		$names[$s['name']] = true;

		if (!in_array($s['type'], ['proxy', 'vpn', 'exclusion', 'block'], true)) {
			$errors[] = "{$s['name']}: выберите, куда направлять домены";
		}
		if ($s['type'] === 'vpn') {
			if ($s['interface'] === '') {
				$errors[] = "{$s['name']}: укажите VPN-интерфейс роутера (например wg0)";
			} elseif (!preg_match('/^[A-Za-z0-9_.-]{1,15}$/', $s['interface'])) {
				$errors[] = "{$s['name']}: «{$s['interface']}» — не похоже на имя интерфейса";
			}
			if ($s['dr_enabled'] && (!in_array($s['dr_type'], ['doh', 'dot', 'udp'], true) || !preg_match('#^[A-Za-z0-9.:/_-]{2,100}$#', $s['dr_server']))) {
				$errors[] = "{$s['name']}: проверьте DNS для VPN (тип и сервер)";
			}
		}
		if ($s['key'] !== '' && !valid_key($s['key'])) {
			$errors[] = "{$s['name']}: ключ не похож на ссылку прокси (hy2://, vless://, ss://, trojan://…)";
		}
		if ($s['orig'] === '' && $s['type'] === 'proxy' && $s['key'] === '' && $s['source'] !== 'sub') {
			$errors[] = "{$s['name']}: для новой секции через прокси нужен ключ";
		}

		[$domains, $bad] = parse_domains($s['domains']);
		if ($bad) {
			$errors[] = "{$s['name']}: не похожи на домены — " . implode(', ', array_slice($bad, 0, 5));
		}
		$subnets = split_lines($s['subnets']);
		foreach ($subnets as $n) {
			if (!preg_match('#^\d{1,3}(\.\d{1,3}){3}(/\d{1,2})?$#', $n)) {
				$errors[] = "{$s['name']}: «{$n}» — не IPv4-подсеть (пример: 149.154.160.0/20)";
			}
		}
		$remote = split_lines($s['remote']);
		foreach ($remote as $u) {
			if (!preg_match('#^https?://[^\s"\'\\\\]+$#', $u)) {
				$errors[] = "{$s['name']}: «{$u}» — не ссылка на список";
			}
		}

		// Цепочка «эта секция через другую»
		$key = $s['key'];
		$outbound = '';
		$setDetour = false;
		$detour = $s['type'] === 'proxy' ? $s['detour'] : '';
		if ($detour !== '') {
			if ($detour === $s['name'] || !isset($targets[$detour])) {
				$errors[] = "{$s['name']}: пускать можно только через другую существующую секцию «через прокси»";
				$detour = '';
			}
		}
		// Источник ключей — подписка
		$subOn = $s['type'] === 'proxy' && $s['source'] === 'sub';
		$subOff = !$subOn && !empty($s['sub']);
		if ($subOn) {
			if ($s['sub_url'] !== '' && !preg_match('#^https?://[^\s"\'\\\\<>]+$#', $s['sub_url'])) {
				$errors[] = "{$s['name']}: ссылка подписки должна начинаться с https:// и быть без пробелов";
			}
			if ($s['sub_url'] === '' && empty($s['sub'])) {
				$errors[] = "{$s['name']}: вставьте ссылку подписки";
			}
			if ($detour !== '') {
				$errors[] = "{$s['name']}: секцию с подпиской пока нельзя пустить через другую секцию";
			}
			if (!mb_check_encoding($s['sub_only'] . $s['sub_exclude'], 'UTF-8')) {
				$errors[] = "{$s['name']}: в списке стран или исключений неверная кодировка";
				$s['sub_only'] = $s['sub_exclude'] = '';
			}
			if ($s['sub_pin'] !== '' && !preg_match('/^[\w.:\[\]-]{1,100}$/', $s['sub_pin'])) {
				$errors[] = "{$s['name']}: неверный закреплённый сервер";
			}
			$key = '';
			$detour = '';
		} elseif ($subOff && $s['type'] === 'proxy' && $key === '') {
			$errors[] = "{$s['name']}: чтобы перейти с подписки на один ключ, вставьте ключ";
		}
		if ($s['type'] === 'proxy' && !$subOn) {
			if ($key !== '' && $detour !== '') {
				$conv = link_to_outbound($key, $s['name'], $detour);
				if ($conv['ok']) {
					$outbound = $conv['json'];
					$key = '';
				} else {
					$errors[] = "{$s['name']}: {$conv['error']}";
				}
			} elseif ($key === '' && $detour !== $s['cur_detour']) {
				if ($s['config'] === 'outbound') {
					$setDetour = true;
				} elseif ($detour !== '') {
					$errors[] = "{$s['name']}: чтобы пустить через {$detour}, вставьте ключ ещё раз — роутеру нужен ключ целиком";
				}
			}
		}

		$payload['sections'][] = [
			'name' => $s['name'],
			'connection_type' => $s['type'],
			'key' => $key,
			'outbound' => $outbound,
			'interface' => $s['type'] === 'vpn' ? $s['interface'] : '',
			'dr_enabled' => $s['type'] === 'vpn' && $s['dr_enabled'],
			'dr_type' => $s['dr_type'],
			'dr_server' => $s['dr_server'],
			'set_detour' => $setDetour,
			'detour' => $detour,
			'sub_on' => $subOn,
			'sub_url' => $subOn ? $s['sub_url'] : '',
			'sub_exclude' => $subOn ? mb_substr(preg_replace('/[^\p{L}\p{N} ,._-]/u', '', $s['sub_exclude']), 0, 200) : '',
			'sub_only' => $subOn ? mb_substr(preg_replace('/[^\p{L}\p{N} ,._-]/u', '', $s['sub_only']), 0, 200) : '',
			'sub_fallback' => $s['sub_fallback'],
			'sub_pin' => $subOn ? $s['sub_pin'] : '',
			'sub_policy' => $s['sub_policy'],
			'sub_sticky' => $subOn && !empty($s['sub_sticky']),
			'sub_off' => $subOff,
			'domains' => $domains,
			'subnets' => $subnets,
			'community' => $s['community'],
			'remote' => $remote,
		];
	}
	return [$payload, $errors];
}

// ---------- Отчёты роутера ----------

function report_files(int $rid): array
{
	$files = glob(data_dir() . "/reports/router-$rid-*.txt") ?: [];
	rsort($files);
	return array_slice($files, 0, 5);
}

if (isset($_GET['dl'])) {
	if ($acc === 'view') {
		http_response_code(403);
		exit('Нет доступа');
	}
	$name = basename((string)$_GET['dl']);
	$file = data_dir() . '/reports/' . $name;
	if (!preg_match('/^router-' . $rid . '-[0-9-]+\.txt$/', $name) || !is_file($file)) {
		http_response_code(404);
		exit('Нет такого отчёта');
	}
	header('Content-Type: text/plain; charset=utf-8');
	header('Content-Disposition: attachment; filename="' . $name . '"');
	readfile($file);
	exit;
}

// ---------- Действия с роутером ----------

$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
	if (!csrf_ok()) {
		$errors[] = 'Форма устарела — обновите страницу и повторите.';
	} elseif (!$canEdit) {
		$errors[] = $readonlyWhy;
	} else {
		if ($acc === 'support') {
			// Пользователь видит в своём журнале, что делала поддержка
			event($rid, 'Поддержка сервиса: действие «' . mb_substr((string)$_POST['action'], 0, 60) . '»');
		}
		// «🔄 Обновить список серверов» у секции с подпиской
		if (preg_match('/^sub_refresh:([A-Za-z][A-Za-z0-9_]{0,29})$/', (string)$_POST['action'], $m)) {
			queue_task($rid, 'refresh_subscription', ['section' => $m[1]]);
			audit('обновление списка серверов подписки секции ' . $m[1] . ' на роутере «' . $router['name'] . '»');
			$_SESSION['flash'] = ['ok', 'Роутер обновит список серверов подписки секции ' . $m[1] . ' при следующем выходе на связь (до 2 минут).'];
			header('Location: router.php?id=' . $rid);
			exit;
		}
		// «Переключиться на этот сервер» и «вернуть автовыбор»
		if (preg_match('/^sub_switch:([A-Za-z][A-Za-z0-9_]{0,29}):([\w.:\[\]-]{1,100})$/', (string)$_POST['action'], $m)) {
			$label = $m[2];
			foreach (router_sections($rid) as $sec) {
				if ($sec['name'] === $m[1]) {
					foreach (sdata($sec)['sub']['servers'] ?? [] as $x) {
						if (($x['host'] ?? '') === $m[2] && !empty($x['name'])) {
							$label = $x['name'];
						}
					}
				}
			}
			queue_task($rid, 'sub_switch', ['section' => $m[1], 'server' => $m[2], 'label' => $label]);
			audit('переключение сервера подписки ' . $m[1] . ' на роутере «' . $router['name'] . '»: ' . ($m[2] === 'auto' ? 'автовыбор' : $label));
			$_SESSION['flash'] = ['ok', ($m[2] === 'auto' ? 'Секция ' . $m[1] . ' вернётся на автовыбор' : 'Секция ' . $m[1] . ' переключится на «' . $label . '»') . ' при следующем выходе роутера на связь (до 2 минут). Устройства при этом не отключаются.'];
			header('Location: router.php?id=' . $rid);
			exit;
		}
		switch ($_POST['action']) {
			case 'rename':
				$name = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST['name'] ?? ''))), 0, 40);
				if ($name !== '') {
					q('UPDATE routers SET name = ? WHERE id = ?', [$name, $rid]);
					event($rid, 'Переименован в «' . $name . '»');
					audit('роутер «' . $router['name'] . '» переименован в «' . $name . '»');
					$_SESSION['flash'] = ['ok', 'Имя сохранено.'];
				}
				break;
			case 'report':
				queue_task($rid, 'upload_report');
				$_SESSION['flash'] = ['ok', 'Роутер пришлёт отчёт при следующем выходе на связь (до 2 минут). Ссылка для скачивания появится в блоке «Управление».'];
				break;
			case 'update':
				$ref = (is_service() || $acc !== 'own') ? 'main' : trim((string)($_POST['ref'] ?? ''));
				if (preg_match('/^[A-Za-z0-9._-]{1,40}$/', $ref)) {
					queue_task($rid, 'update', ['ref' => $ref]);
					audit('обновление скриптов до «' . $ref . '» на роутере «' . $router['name'] . '»');
					$_SESSION['flash'] = ['ok', "Обновление до «{$ref}» отправлено. Роутер скачает скрипты с GitHub и перезапустит их."];
				}
				break;
			case 'update_podkop':
				queue_task($rid, 'update_podkop');
				audit('обновление podkop на роутере «' . $router['name'] . '»');
				$_SESSION['flash'] = ['ok', 'Обновление podkop отправлено. Роутер скачает официальный установщик podkop, обновит, очистит кэш LuCI и переподключит устройства — это займёт несколько минут.'];
				break;
			case 'delete':
				q("UPDATE routers SET status = 'removed' WHERE id = ?", [$rid]);
				q('DELETE FROM sections WHERE router_id = ?', [$rid]);
				q("DELETE FROM tasks WHERE router_id = ? AND status IN ('queued', 'sent')", [$rid]);
				event($rid, 'Роутер удалён из системы');
				audit('роутер «' . $router['name'] . '» удалён из системы');
				$_SESSION['flash'] = 'Роутер «' . $router['name'] . '» удалён из системы.';
				header('Location: index.php');
				exit;
		}
		header('Location: router.php?id=' . $rid);
		exit;
	}
}

// ---------- Отправка настроек ----------

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$ps = $info['podkop_settings'] ?? [];
$settings = [
	'dns_type' => (string)($ps['dns_type'] ?? 'doh'),
	'dns_server' => (string)($ps['dns_server'] ?? '1.1.1.1'),
	'bootstrap_dns_server' => (string)($ps['bootstrap_dns_server'] ?? '1.1.1.1'),
];
$sections = sections_from_db($rid);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !isset($_POST['action'])) {
	if (!csrf_ok()) {
		$errors[] = 'Форма устарела — обновите страницу и повторите.';
	} elseif (!$canEdit) {
		$errors[] = $readonlyWhy;
	} elseif (!$agentOk) {
		$errors[] = 'Агент на роутере устарел — сначала обновите скрипты.';
	} else {
		$settings = [
			'dns_type' => (string)($_POST['dns_type'] ?? ''),
			'dns_server' => trim((string)($_POST['dns_server'] ?? '')),
			'bootstrap_dns_server' => trim((string)($_POST['bootstrap_dns_server'] ?? '')),
		];
		$sections = sections_from_post($rid);
		[$payload, $errors] = build_payload($sections, $settings);
		if (!$errors) {
			// Неотправленная предыдущая версия больше не нужна
			q("UPDATE tasks SET status = 'failed', done_at = ?, result = 'заменено более новой отправкой', payload = '{}' WHERE router_id = ? AND type = 'apply_config' AND status = 'queued'", [time(), $rid]);
			if ($acc === 'support') {
				event($rid, 'Поддержка сервиса отправила настройки на роутер');
			}
			queue_task($rid, 'apply_config', $payload);
			event($rid, 'Настройки отправлены с сайта');
			audit('отправлены настройки podkop на роутер «' . $router['name'] . '»: ' . task_label('apply_config', $payload));
			$_SESSION['flash'] = ['ok', 'Отправлено. Роутер заберёт настройки при следующем выходе на связь (до 2 минут), применит, проверит ключи и переподключит устройства. Результат появится здесь и в Telegram.'];
			header('Location: router.php?id=' . $rid);
			exit;
		}
	}
}

$proxyNames = [];
foreach (sections_from_db($rid) as $s) {
	if (in_array($s['type'], ['proxy', 'vpn'], true)) {
		$proxyNames[] = $s['name'];
	}
}
// VPN-интерфейсы, которые агент нашёл на роутере (подсказки для поля «интерфейс»)
$vpnIfaces = array_values(array_filter((array)($info['vpn_ifaces'] ?? []), fn($v) => is_string($v) && preg_match('/^[A-Za-z0-9_.-]{1,15}$/', $v)));

$pending = rows("SELECT * FROM tasks WHERE router_id = ? AND type = 'apply_config' AND status IN ('queued', 'sent') ORDER BY id DESC LIMIT 1", [$rid]);
$last = row("SELECT * FROM tasks WHERE router_id = ? AND type = 'apply_config' AND status IN ('done', 'failed') AND result != 'заменено более новой отправкой' ORDER BY id DESC LIMIT 1", [$rid]);

// ---------- Вывод ----------

function render_section(int $i, array $s, array $proxyNames, array $vpnIfaces = []): void
{
	$isNew = $s['orig'] === '';
	$keyEditable = $isNew || in_array($s['config'], ['url', ''], true);
	$all = array_values(array_unique(array_merge(KNOWN_LISTS, $s['community'])));
	global $subOptsOk, $subStickyOk;
	?>
	<div class="section<?= $s['delete'] ? ' deleted' : '' ?>" data-section>
		<input type="hidden" name="sections[<?= $i ?>][orig]" value="<?= h($s['orig']) ?>">
		<div class="section-head">
			<?php if ($isNew): ?>
				<span style="flex:1">Имя новой секции: <input type="text" name="sections[<?= $i ?>][name]" value="<?= h($s['name']) ?>" placeholder="Office" style="max-width:220px"></span>
			<?php else: ?>
				<span><b><?= h($s['name']) ?></b> &nbsp; <span class="muted"><?= preg_replace('#&lt;(/?)b&gt;#', '<$1b>', h(strip_tags($s['status']))) ?></span></span>
			<?php endif; ?>
			<span>
				<button type="button" onclick="moveSection(this, -1)" title="Выше">↑</button>
				<button type="button" onclick="moveSection(this, 1)" title="Ниже">↓</button>
				<label class="btn danger"><input type="checkbox" name="sections[<?= $i ?>][delete]" value="1" <?= $s['delete'] ? 'checked' : '' ?> onchange="this.closest('[data-section]').classList.toggle('deleted', this.checked)"> удалить</label>
			</span>
		</div>
		<div class="grid">
			<div>
				<label class="field">Куда направлять</label>
				<select name="sections[<?= $i ?>][type]" onchange="showType(this)">
					<option value="proxy" <?= $s['type'] === 'proxy' ? 'selected' : '' ?>>🔐 Через прокси</option>
					<option value="vpn" <?= $s['type'] === 'vpn' ? 'selected' : '' ?>>🛡 Через VPN (WireGuard, AmneziaWG, OpenVPN…)</option>
					<option value="exclusion" <?= $s['type'] === 'exclusion' ? 'selected' : '' ?>>↪️ Мимо прокси (напрямую)</option>
					<option value="block" <?= $s['type'] === 'block' ? 'selected' : '' ?>>⛔ Блокировать (не открывать)</option>
				</select>
				<div class="muted" data-for="block" style="margin-top:6px">Домены и подсети этой секции не будут открываться ни на одном устройстве в сети.</div>
				<div class="muted" data-for="exclusion" style="margin-top:6px">Домены этой секции идут напрямую, даже если попадают в списки других секций.</div>
			</div>
			<div>
				<div data-for="proxy">
					<label class="field">Источник ключей</label>
					<select name="sections[<?= $i ?>][source]" onchange="showSource(this)">
						<option value="key" <?= $s['source'] === 'key' ? 'selected' : '' ?>>🔑 Один ключ</option>
						<option value="sub" <?= $s['source'] === 'sub' ? 'selected' : '' ?>>📋 Подписка — ключи обновляются сами</option>
					</select>
					<div data-src="key">
						<label class="field">Ключ (ссылка)</label>
						<?php if ($keyEditable): ?>
							<input type="text" name="sections[<?= $i ?>][key]" value="<?= h($s['key']) ?>" placeholder="<?= $isNew ? 'hy2://…' : 'оставьте пустым — ключ не изменится' ?>" autocomplete="off" spellcheck="false">
						<?php else: ?>
							<input type="text" name="sections[<?= $i ?>][key]" value="<?= h($s['key']) ?>" placeholder="задан как «<?= h($s['config']) ?>» — вставьте ссылку, чтобы заменить" autocomplete="off" spellcheck="false">
						<?php endif; ?>
						<label class="field">Пустить через <span class="muted">(цепочка: эта секция ходит через другую)</span></label>
						<select name="sections[<?= $i ?>][detour]">
							<option value="">— напрямую в интернет —</option>
							<?php foreach ($proxyNames as $n): if ($n === $s['name']) continue; ?>
								<option value="<?= h($n) ?>" <?= $s['detour'] === $n ? 'selected' : '' ?>>через <?= h($n) ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div data-src="sub">
						<label class="field">Ссылка подписки</label>
						<input type="text" name="sections[<?= $i ?>][sub_url]" value="" placeholder="<?= $s['sub'] ? 'подписка задана — вставьте новую ссылку, чтобы заменить' : 'https://… (та, что импортируется в Happ / INCY)' ?>" autocomplete="off" spellcheck="false">
						<div class="muted">Ссылка хранится только на роутере.</div>
						<label class="field">Не использовать серверы, в названии которых есть <span class="muted">(через запятую)</span></label>
						<input type="text" name="sections[<?= $i ?>][sub_exclude]" value="<?= h($s['sub_exclude']) ?>" placeholder="например: Россия">
						<?php $srvAll = $s['sub']['servers'] ?? []; ?>
						<?php if ($subOptsOk): ?>
							<label class="field">Только эти страны <span class="muted">(слово из названия сервера, через запятую; пусто — все)</span></label>
							<input type="text" name="sections[<?= $i ?>][sub_only]" value="<?= h($s['sub_only']) ?>" placeholder="например: Германия, Австрия" data-only>
							<?php if ($countries = sub_countries($srvAll)): ?>
								<div class="lists" style="margin-top:4px">
									<?php foreach ($countries as $c => $n): ?>
										<label><input type="checkbox" data-country="<?= h($c) ?>" onchange="toggleCountry(this)" <?= in_array($c, array_map('trim', explode(',', $s['sub_only'])), true) ? 'checked' : '' ?>> <?= h($c) ?> <span class="muted">(<?= (int)$n ?>)</span></label>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
							<label class="field">Если ни один сервер этих стран не отвечает</label>
							<select name="sections[<?= $i ?>][sub_fallback]">
								<option value="any" <?= $s['sub_fallback'] !== 'strict' ? 'selected' : '' ?>>Временно взять любую другую страну, потом вернуться</option>
								<option value="strict" <?= $s['sub_fallback'] === 'strict' ? 'selected' : '' ?>>Не переключать никогда: домены не откроются, пока страна не оживёт</option>
							</select>
							<label class="field">📌 Закрепить один сервер <span class="muted">(не менять на более быстрый и не переключать)</span></label>
							<select name="sections[<?= $i ?>][sub_pin]">
								<option value="">— не закреплять, выбирать самый быстрый —</option>
								<?php $pinKnown = false; foreach ($srvAll as $x): $pinKnown = $pinKnown || $x['host'] === $s['sub_pin']; ?>
									<option value="<?= h($x['host']) ?>" <?= $x['host'] === $s['sub_pin'] ? 'selected' : '' ?>><?= h($x['name'] ?: $x['host']) ?></option>
								<?php endforeach; ?>
								<?php if ($s['sub_pin'] !== '' && !$pinKnown): ?>
									<option value="<?= h($s['sub_pin']) ?>" selected><?= h(($s['sub']['pin_name'] ?? '') ?: $s['sub_pin']) ?> (нет в подписке)</option>
								<?php endif; ?>
							</select>
							<?php if ($subStickyOk): ?>
								<label style="display:block;margin-top:8px"><input type="checkbox" name="sections[<?= $i ?>][sub_sticky]" value="1" <?= !empty($s['sub_sticky']) ? 'checked' : '' ?>> <b>Держаться за текущий сервер</b> — не менять его на более быстрый, пока он отвечает. Перестал отвечать — взять другой по правилам выше (страны, что делать при отказе).</label>
							<?php else: ?>
								<div class="muted" style="margin-top:6px">«Держаться за текущий сервер» появится после обновления скриптов роутера (нужен агент <?= SUB_STICKY_AGENT ?>).</div>
							<?php endif; ?>
							<?php if (!$srvAll): ?><div class="muted">Список серверов появится, когда роутер скачает подписку.</div><?php endif; ?>
							<label class="field">Когда обновлять список серверов</label>
							<select name="sections[<?= $i ?>][sub_policy]">
								<?php foreach (['6h' => 'Раз в 6 часов и при потере связи', '24h' => 'Раз в сутки и при потере связи', 'dead' => 'Только когда не отвечает ни один разрешённый сервер', 'off' => 'Никогда сам — только кнопкой'] as $v => $l): ?>
									<option value="<?= $v ?>" <?= $s['sub_policy'] === $v ? 'selected' : '' ?>><?= $l ?></option>
								<?php endforeach; ?>
							</select>
						<?php else: ?>
							<div class="muted" style="margin-top:6px">Выбор страны, закрепление и переключение сервера появятся после обновления скриптов роутера (нужен агент <?= SUB_OPTS_AGENT ?>).</div>
						<?php endif; ?>
						<?php if ($sub = $s['sub']): ?>
							<div style="margin-top:8px">
								<?php if (!empty($sub['error'])): ?><div class="notice err" style="margin:0 0 6px"><?= h($sub['error']) ?></div><?php endif; ?>
								<span class="muted">Обновлено <?= $sub['updated'] ? ago($sub['updated']) : '—' ?> · подходят <?= (int)$sub['usable'] ?> из <?= (int)$sub['total'] ?> серверов</span>
								<?php $srv = $sub['servers'] ?? []; $work = array_filter($srv, fn($x) => $x['in'] ?? true); $alive = count(array_filter($work, fn($x) => !empty($x['delay']))); $canSwitch = $subOptsOk && empty($sub['pin']); ?>
								<?php if (!empty($sub['pin'])): ?><div class="notice" style="margin:0 0 6px">📌 Закреплён сервер «<?= h(($sub['pin_name'] ?? '') ?: $sub['pin']) ?>». Если он перестанет отвечать, роутер не перейдёт на другой, и домены этой секции не откроются.</div><?php endif; ?>
								<?php if (!empty($sub['fb'])): ?><div class="notice err" style="margin:0 0 6px">⚠️ Серверы «<?= h($sub['only'] ?? '') ?>» не отвечают — временно работают другие страны. Вернётся сам, когда они оживут.</div><?php endif; ?>
								<?php if (!empty($sub['pick']) && $canSwitch): ?><div class="notice" style="margin:0 0 6px">👆 Сервер выбран вручную. Он останется, пока отвечает; если замолчит, роутер вернётся на автовыбор. <button type="submit" name="action" value="sub_switch:<?= h($s['name']) ?>:auto" formnovalidate onclick="dirty = false">↩️ Вернуть автовыбор</button></div><?php endif; ?>
								<details style="margin-top:4px">
									<summary>Серверы: отвечают <?= $alive ?> из <?= count($work) ?><?php foreach ($srv as $x) { if (!empty($x['now'])) { echo ' · сейчас: ' . h($x['name'] ?: $x['host']); } } ?></summary>
									<?php foreach ($srv as $x): ?>
										<?php $xIn = $x['in'] ?? true; ?>
										<div class="muted" style="margin-top:4px"><?= !empty($x['now']) ? '▶ ' : '' ?><?= h($x['name'] ?: '—') ?> · <?= h($x['type']) ?> · <?= h($x['host']) ?> · <?= !$xIn ? 'не в работе' : (!empty($x['delay']) ? (int)$x['delay'] . ' мс' : 'не отвечает') ?>
											<?php if ($canSwitch && $xIn && empty($x['now'])): ?><button type="submit" name="action" value="sub_switch:<?= h($s['name']) ?>:<?= h($x['host']) ?>" formnovalidate onclick="dirty = false" style="min-height:0;padding:1px 8px;font-size:12px">▶ сюда</button><?php endif; ?>
										</div>
									<?php endforeach; ?>
								</details>
								<button type="submit" name="action" value="sub_refresh:<?= h($s['name']) ?>" formnovalidate onclick="dirty = false" style="margin-top:6px">🔄 Обновить список серверов сейчас</button>
							</div>
						<?php endif; ?>
					</div>
				</div>
				<div data-for="vpn">
					<label class="field">VPN-интерфейс роутера</label>
					<input type="text" name="sections[<?= $i ?>][interface]" value="<?= h($s['interface']) ?>" list="vpn-ifaces" placeholder="например wg0 или awg0" autocomplete="off" spellcheck="false">
					<div class="muted">Интерфейс должен быть уже настроен на роутере (LuCI → Сеть → Интерфейсы).<?= $vpnIfaces ? ' Найдены: ' . h(implode(', ', $vpnIfaces)) . '.' : ' VPN-интерфейсов на роутере не найдено.' ?></div>
					<label style="display:block;margin-top:8px"><input type="checkbox" name="sections[<?= $i ?>][dr_enabled]" value="1" <?= $s['dr_enabled'] ? 'checked' : '' ?>> Узнавать адреса доменов через этот VPN (свой DNS)</label>
					<div class="row" style="margin-top:4px">
						<select name="sections[<?= $i ?>][dr_type]" style="width:auto">
							<?php foreach (['doh' => 'DoH', 'dot' => 'DoT', 'udp' => 'UDP'] as $v => $l): ?>
								<option value="<?= $v ?>" <?= ($s['dr_type'] ?: 'doh') === $v ? 'selected' : '' ?>><?= $l ?></option>
							<?php endforeach; ?>
						</select>
						<input type="text" name="sections[<?= $i ?>][dr_server]" value="<?= h($s['dr_server'] ?: '1.1.1.1') ?>" style="flex:1;min-width:120px">
					</div>
				</div>
			</div>
			<div>
				<label class="field">Свои домены <span class="muted">(по одному в строке)</span></label>
				<textarea name="sections[<?= $i ?>][domains]" rows="7" spellcheck="false"><?= h($s['domains']) ?></textarea>
			</div>
			<div>
				<label class="field">Свои подсети <span class="muted">(IPv4, например 149.154.160.0/20)</span></label>
				<textarea name="sections[<?= $i ?>][subnets]" rows="3" spellcheck="false"><?= h($s['subnets']) ?></textarea>
				<label class="field">Внешние списки доменов <span class="muted">(ссылки, по одной в строке)</span></label>
				<textarea name="sections[<?= $i ?>][remote]" rows="2" spellcheck="false"><?= h($s['remote']) ?></textarea>
			</div>
		</div>
		<label class="field">Готовые списки</label>
		<div class="lists">
			<?php foreach ($all as $l): ?>
				<label><input type="checkbox" name="sections[<?= $i ?>][community][]" value="<?= h($l) ?>" <?= in_array($l, $s['community'], true) ? 'checked' : '' ?>> <?= h($l) ?></label>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
}

panel_header($router['name']);
?>
	<h2 style="font-size:20px;margin:4px 0 2px"><?= router_icon($router) ?> <?= h($router['name']) ?></h2>
	<p class="muted" style="margin-top:0"><?= router_hw_line($info) ?> · на связи <?= ago($router['last_seen']) ?> · данные — на момент последней связи</p>

	<?php if ($readonlyWhy): ?><div class="notice">🔒 <?= h($readonlyWhy) ?></div><?php endif; ?>
	<?php if ($acc === 'support'): ?><div class="notice">🛟 Роутер пользователя <b><?= h($owner['email'] ?? '') ?></b>: доступ поддержки открыт до <?= date('d.m H:i', (int)$owner['support_until']) ?>. Все ваши действия видны пользователю в его журнале.</div><?php endif; ?>
	<?php if ($flash): ?><div class="notice <?= h($flash[0]) ?>"><?= h($flash[1]) ?></div><?php endif; ?>
	<?php if ($errors): ?>
		<div class="notice err"><b>Не отправлено — исправьте:</b><ul style="margin:6px 0 0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
	<?php endif; ?>
	<?php if ($old = router_outdated($info)): ?>
		<div class="notice" style="border-left:4px solid #f5a623">
			<b>⚠️ Устарело: <?= h(implode(', ', $old)) ?>.</b> Новые версии исправляют ошибки и нужны для новых возможностей.
			<?php if ($canEdit): ?>
				<div class="row" style="margin-top:6px">
					<form method="post"><?= csrf_field() ?><input type="hidden" name="ref" value="main"><button name="action" value="update" class="primary">⬆️ Обновить скрипты</button></form>
					<?php if (podkop_outdated((string)($info['podkop'] ?? ''))): ?>
						<form method="post" onsubmit="return confirm('Обновить podkop? Откатить обновление podkop нельзя.')"><?= csrf_field() ?><button name="action" value="update_podkop">📦 Обновить podkop</button></form>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
	<?php endif; ?>
	<?php foreach (router_warnings($info) as $w): ?><div class="notice">⚠️ <?= h($w) ?></div><?php endforeach; ?>
	<?php if (!$agentOk): ?>
		<div class="notice err">Агент на роутере — версии <?= h($info['agent'] ?? '?') ?>, а для настройки с сайта нужна <?= MIN_AGENT ?> или новее. Обновите скрипты (в боте: роутер → «⬆️ Обновить скрипты»).</div>
	<?php endif; ?>
	<?php if ($pending): ?>
		<div class="notice">⏳ Настройки отправлены <?= ago($pending[0]['created_at']) ?> и ждут роутер (<?= $pending[0]['status'] === 'sent' ? 'роутер применяет' : 'в очереди' ?>). Форма ниже показывает текущее состояние роутера.</div>
	<?php elseif ($last): ?>
		<div class="notice <?= $last['status'] === 'done' ? 'ok' : 'err' ?>">Последняя отправка (<?= ago($last['done_at']) ?>): <?= h($last['result']) ?></div>
	<?php endif; ?>

	<?php
	$wd = $info['watchdog'] ?? [];
	$rtasks = rows('SELECT * FROM tasks WHERE router_id = ? ORDER BY id DESC LIMIT 5', [$rid]);
	?>
	<details class="card" <?= $flash ? 'open' : '' ?>>
		<summary><h2 style="display:inline">🛠 Управление</h2> <span class="muted">имя, отчёт, обновление, удаление, история</span></summary>

		<fieldset <?= $canEdit ? '' : 'disabled' ?> style="border:0;padding:0;margin:0">
		<form method="post" class="row" style="margin-top:10px">
			<?= csrf_field() ?>
			<input type="text" name="name" value="<?= h($router['name']) ?>" style="flex:1;min-width:160px">
			<button name="action" value="rename">✏️ Переименовать</button>
		</form>

		<div class="row" style="margin-top:10px">
			<form method="post"><?= csrf_field() ?><button name="action" value="report">📄 Запросить отчёт</button></form>
			<form method="post" class="row">
				<?= csrf_field() ?>
				<?php if (!is_service() && $acc === 'own'): ?><input type="text" name="ref" value="main" style="width:90px" title="Версия с GitHub: main или тег, например v1.4"><?php endif; ?>
				<button name="action" value="update">⬆️ Обновить скрипты</button>
			</form>
			<form method="post" onsubmit="return confirm('Обновить podkop на этом роутере? Откатить обновление podkop нельзя.')">
				<?= csrf_field() ?><button name="action" value="update_podkop">📦 Обновить podkop<?= podkop_outdated((string)($info['podkop'] ?? '')) ? ' до ' . h(podkop_latest()) : '' ?></button>
			</form>
			<form method="post" onsubmit="return confirm('Удалить «<?= h(addslashes($router['name'])) ?>» из системы? При следующем выходе на связь агент и сторож удалятся с роутера (podkop и его настройки останутся). Чтобы вернуть роутер, нужно будет установить заново.')">
				<?= csrf_field() ?><button name="action" value="delete" class="danger">🗑 Удалить из системы</button>
			</form>
		</div>
		</fieldset>

		<?php if ($acc !== 'view' && ($reports = report_files($rid))): ?>
			<p style="margin-bottom:4px"><b>Отчёты:</b></p>
			<?php foreach ($reports as $f): ?>
				<div><a href="router.php?id=<?= $rid ?>&dl=<?= urlencode(basename($f)) ?>">📄 <?= h(basename($f)) ?></a></div>
			<?php endforeach; ?>
		<?php endif; ?>

		<p style="margin-bottom:4px"><b>Последние задачи:</b></p>
		<?php foreach ($rtasks as $t):
			$st = ['queued' => '⏳', 'sent' => '📨', 'done' => '✅', 'failed' => '❌'][$t['status']] ?? $t['status']; ?>
			<div class="item"><?= $st ?> <span class="muted"><?= date('d.m H:i', (int)$t['created_at']) ?></span> <?= h(task_label($t['type'], json_decode($t['payload'], true) ?: [])) ?>
				<?php if ($t['result']): ?><div class="muted"><?= h($t['result']) ?></div><?php endif; ?></div>
		<?php endforeach; ?>
		<?php if (!$rtasks): ?><p class="muted">Задач ещё не было.</p><?php endif; ?>

		<?php if (!empty($wd['installed'])): ?>
			<p style="margin-bottom:4px"><b>Сторож интернета</b><?= !empty($wd['reboots_row']) ? ' — перезагрузок подряд: ' . (int)$wd['reboots_row'] : '' ?>:</p>
			<?php foreach (array_reverse($wd['history'] ?? []) as $line): ?>
				<div class="muted"><?= h($line) ?></div>
			<?php endforeach; ?>
		<?php endif; ?>
	</details>

	<form method="post" id="cfg">
		<?= csrf_field() ?>
		<fieldset <?= $canEdit ? '' : 'disabled' ?> style="border:0;padding:0;margin:0;min-width:0">
		<div class="card">
			<h2>DNS podkop</h2>
			<div class="grid" style="grid-template-columns: 1fr 1fr 1fr">
				<div>
					<label class="field">Тип протокола DNS</label>
					<select name="dns_type">
						<?php foreach (['doh' => 'DNS через HTTPS (DoH)', 'dot' => 'DNS через TLS (DoT)', 'udp' => 'Обычный DNS (UDP)'] as $v => $l): ?>
							<option value="<?= $v ?>" <?= $settings['dns_type'] === $v ? 'selected' : '' ?>><?= $l ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div><label class="field">DNS-сервер</label><input type="text" name="dns_server" value="<?= h($settings['dns_server']) ?>"></div>
				<div><label class="field">Bootstrap DNS-сервер</label><input type="text" name="bootstrap_dns_server" value="<?= h($settings['bootstrap_dns_server']) ?>"></div>
			</div>
		</div>

		<div class="card">
			<h2>Секции <span class="muted">— порядок важен: если домен есть в двух секциях, сработает верхняя</span></h2>
			<div id="sections">
				<?php foreach ($sections as $i => $s) {
					render_section($i, $s, $proxyNames, $vpnIfaces);
				} ?>
			</div>
			<button type="button" onclick="addSection()">➕ Добавить секцию</button>
		</div>

		<div class="sticky">
			<button type="submit" class="primary" <?= $agentOk ? '' : 'disabled' ?>>📤 Отправить на роутер</button>
			<a class="btn" href="router.php?id=<?= $rid ?>">Сбросить изменения</a>
			<span class="muted">&nbsp; Роутер применит всё разом, проверит новые ключи (неработающий — вернёт прежний) и на ~10 с переподключит устройства.</span>
		</div>
		</fieldset>
	</form>

	<template id="tpl"><?php render_section(9999, ['orig' => '', 'name' => '', 'type' => 'proxy', 'config' => 'url', 'status' => '', 'detour' => '', 'cur_detour' => '', 'sub' => null, 'source' => 'key', 'sub_url' => '', 'sub_exclude' => 'Россия', 'sub_only' => '', 'sub_fallback' => 'any', 'sub_pin' => '', 'sub_policy' => '6h', 'sub_sticky' => false, 'interface' => '', 'dr_enabled' => false, 'dr_type' => 'doh', 'dr_server' => '1.1.1.1', 'key' => '', 'domains' => '', 'subnets' => '', 'community' => [], 'remote' => '', 'delete' => false], $proxyNames, $vpnIfaces); ?></template>

	<datalist id="vpn-ifaces"><?php foreach ($vpnIfaces as $v): ?><option value="<?= h($v) ?>"><?php endforeach; ?></datalist>

	<script>
		let next = <?= count($sections) + 1 ?>;
		// Показать только поля выбранного типа секции (ключ — у прокси, интерфейс — у VPN и т.д.)
		// Внутри «через прокси»: один ключ или подписка
		function showSource(sel) {
			const sec = sel.closest('[data-section]');
			sec.querySelectorAll('[data-src]').forEach(el => {
				el.style.display = el.dataset.src === sel.value ? '' : 'none';
			});
		}
		// Галочка страны дописывает или убирает её в поле «Только эти страны»
		function toggleCountry(box) {
			const inp = box.closest('[data-src]').querySelector('[data-only]');
			const list = inp.value.split(',').map(x => x.trim()).filter(Boolean);
			const at = list.indexOf(box.dataset.country);
			if (box.checked && at < 0) list.push(box.dataset.country);
			if (!box.checked && at >= 0) list.splice(at, 1);
			inp.value = list.join(', ');
			dirty = true;
		}
		function showType(sel) {
			const sec = sel.closest('[data-section]');
			sec.querySelectorAll('[data-for]').forEach(el => {
				el.style.display = el.dataset.for === sel.value ? '' : 'none';
			});
		}
		function addSection() {
			const html = document.getElementById('tpl').innerHTML.replaceAll('[9999]', '[' + (next++) + ']');
			document.getElementById('sections').insertAdjacentHTML('beforeend', html);
			showType(document.getElementById('sections').lastElementChild.querySelector('select[name$="[type]"]'));
			showSource(document.getElementById('sections').lastElementChild.querySelector('select[name$="[source]"]'));
		}
		document.querySelectorAll('#sections select[name$="[type]"]').forEach(showType);
		document.querySelectorAll('#sections select[name$="[source]"]').forEach(showSource);
		function moveSection(btn, dir) {
			const el = btn.closest('[data-section]');
			const sib = dir < 0 ? el.previousElementSibling : el.nextElementSibling;
			if (sib) {
				dir < 0 ? sib.before(el) : sib.after(el);
			}
		}
		// Не потерять несохранённые изменения
		let dirty = false;
		document.getElementById('cfg').addEventListener('input', () => dirty = true);
		document.getElementById('cfg').addEventListener('submit', () => dirty = false);
		window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
	</script>
<?php
panel_footer();
