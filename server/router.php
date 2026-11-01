<?php
// Редактор настроек podkop роутера: секции, списки, ключи, DNS → «Отправить на роутер».

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/panel.php';

panel_start();
panel_require();

const MIN_AGENT = '1.3';

$rid = (int)($_GET['id'] ?? 0);
$router = get_router($rid);
if (!$router || $router['status'] !== 'active') {
	panel_header('Роутер не найден');
	echo '<div class="card">Роутер не найден. <a href="index.php">К списку</a></div>';
	panel_footer();
	exit;
}
$info = rinfo($router);
$agentOk = version_compare((string)($info['agent'] ?? '0'), MIN_AGENT, '>=');

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
			'cur_detour' => (string)($d['detour'] ?? ''),
			'key' => '',
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
		$base = $db[$orig] ?? ['config' => 'url', 'status' => '', 'cur_detour' => ''];
		$out[] = [
			'orig' => $orig,
			'name' => $orig !== '' ? $orig : trim((string)($p['name'] ?? '')),
			'type' => (string)($p['type'] ?? ''),
			'config' => $base['config'],
			'status' => $base['status'],
			'detour' => trim((string)($p['detour'] ?? '')),
			'cur_detour' => $base['cur_detour'],
			'key' => trim((string)($p['key'] ?? '')),
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
		if ($s['orig'] !== '' && !$s['delete'] && $s['type'] === 'proxy') {
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

		if (!in_array($s['type'], ['proxy', 'exclusion'], true)) {
			$errors[] = "{$s['name']}: выберите, куда направлять домены";
		}
		if ($s['key'] !== '' && !valid_key($s['key'])) {
			$errors[] = "{$s['name']}: ключ не похож на ссылку прокси (hy2://, vless://, ss://, trojan://…)";
		}
		if ($s['orig'] === '' && $s['type'] === 'proxy' && $s['key'] === '') {
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
		if ($s['type'] === 'proxy') {
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
			'set_detour' => $setDetour,
			'detour' => $detour,
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
	} else {
		switch ($_POST['action']) {
			case 'rename':
				$name = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST['name'] ?? ''))), 0, 40);
				if ($name !== '') {
					q('UPDATE routers SET name = ? WHERE id = ?', [$name, $rid]);
					event($rid, 'Переименован в «' . $name . '»');
					$_SESSION['flash'] = ['ok', 'Имя сохранено.'];
				}
				break;
			case 'report':
				queue_task($rid, 'upload_report');
				$_SESSION['flash'] = ['ok', 'Роутер пришлёт отчёт при следующем выходе на связь (до 2 минут). Ссылка для скачивания появится в блоке «Управление».'];
				break;
			case 'update':
				$ref = trim((string)($_POST['ref'] ?? ''));
				if (preg_match('/^[A-Za-z0-9._-]{1,40}$/', $ref)) {
					queue_task($rid, 'update', ['ref' => $ref]);
					$_SESSION['flash'] = ['ok', "Обновление до «{$ref}» отправлено. Роутер скачает скрипты с GitHub и перезапустит их."];
				}
				break;
			case 'update_podkop':
				queue_task($rid, 'update_podkop');
				$_SESSION['flash'] = ['ok', 'Обновление podkop отправлено. Роутер скачает официальный установщик podkop, обновит, очистит кэш LuCI и переподключит устройства — это займёт несколько минут.'];
				break;
			case 'delete':
				q("UPDATE routers SET status = 'removed' WHERE id = ?", [$rid]);
				q('DELETE FROM sections WHERE router_id = ?', [$rid]);
				q("DELETE FROM tasks WHERE router_id = ? AND status IN ('queued', 'sent')", [$rid]);
				event($rid, 'Роутер удалён из системы');
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
			queue_task($rid, 'apply_config', $payload);
			event($rid, 'Настройки отправлены с сайта');
			$_SESSION['flash'] = ['ok', 'Отправлено. Роутер заберёт настройки при следующем выходе на связь (до 2 минут), применит, проверит ключи и переподключит устройства. Результат появится здесь и в Telegram.'];
			header('Location: router.php?id=' . $rid);
			exit;
		}
	}
}

$proxyNames = [];
foreach (sections_from_db($rid) as $s) {
	if ($s['type'] === 'proxy') {
		$proxyNames[] = $s['name'];
	}
}

$pending = rows("SELECT * FROM tasks WHERE router_id = ? AND type = 'apply_config' AND status IN ('queued', 'sent') ORDER BY id DESC LIMIT 1", [$rid]);
$last = row("SELECT * FROM tasks WHERE router_id = ? AND type = 'apply_config' AND status IN ('done', 'failed') AND result != 'заменено более новой отправкой' ORDER BY id DESC LIMIT 1", [$rid]);

// ---------- Вывод ----------

function render_section(int $i, array $s, array $proxyNames): void
{
	$isNew = $s['orig'] === '';
	$keyEditable = $isNew || in_array($s['config'], ['url', ''], true);
	$all = array_values(array_unique(array_merge(KNOWN_LISTS, $s['community'])));
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
				<select name="sections[<?= $i ?>][type]">
					<option value="proxy" <?= $s['type'] === 'proxy' ? 'selected' : '' ?>>🔐 Через прокси</option>
					<option value="exclusion" <?= $s['type'] === 'exclusion' ? 'selected' : '' ?>>↪️ Мимо прокси (напрямую)</option>
				</select>
			</div>
			<div>
				<label class="field">Ключ (ссылка)</label>
				<?php if ($keyEditable): ?>
					<input type="text" name="sections[<?= $i ?>][key]" value="<?= h($s['key']) ?>" placeholder="<?= $isNew ? 'hy2://…' : 'оставьте пустым — ключ не изменится' ?>" autocomplete="off" spellcheck="false">
				<?php else: ?>
					<input type="text" name="sections[<?= $i ?>][key]" value="<?= h($s['key']) ?>" placeholder="задан JSON (<?= h($s['config']) ?>) — вставьте ссылку, чтобы заменить" autocomplete="off" spellcheck="false">
				<?php endif; ?>
				<label class="field">Пустить через <span class="muted">(цепочка: эта секция ходит через другую)</span></label>
				<select name="sections[<?= $i ?>][detour]">
					<option value="">— напрямую в интернет —</option>
					<?php foreach ($proxyNames as $n): if ($n === $s['name']) continue; ?>
						<option value="<?= h($n) ?>" <?= $s['detour'] === $n ? 'selected' : '' ?>>через <?= h($n) ?></option>
					<?php endforeach; ?>
				</select>
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

	<?php if ($flash): ?><div class="notice <?= h($flash[0]) ?>"><?= h($flash[1]) ?></div><?php endif; ?>
	<?php if ($errors): ?>
		<div class="notice err"><b>Не отправлено — исправьте:</b><ul style="margin:6px 0 0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
	<?php endif; ?>
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

		<form method="post" class="row" style="margin-top:10px">
			<?= csrf_field() ?>
			<input type="text" name="name" value="<?= h($router['name']) ?>" style="flex:1;min-width:160px">
			<button name="action" value="rename">✏️ Переименовать</button>
		</form>

		<div class="row" style="margin-top:10px">
			<form method="post"><?= csrf_field() ?><button name="action" value="report">📄 Запросить отчёт</button></form>
			<form method="post" class="row">
				<?= csrf_field() ?>
				<input type="text" name="ref" value="main" style="width:90px" title="Версия с GitHub: main или тег, например v1.4">
				<button name="action" value="update">⬆️ Обновить скрипты</button>
			</form>
			<form method="post" onsubmit="return confirm('Обновить podkop на этом роутере? Откатить обновление podkop нельзя.')">
				<?= csrf_field() ?><button name="action" value="update_podkop">📦 Обновить podkop<?= podkop_outdated((string)($info['podkop'] ?? '')) ? ' до ' . h(podkop_latest()) : '' ?></button>
			</form>
			<form method="post" onsubmit="return confirm('Удалить «<?= h(addslashes($router['name'])) ?>» из системы? Агент перестанет выходить на связь; чтобы вернуть роутер, агент нужно переустановить.')">
				<?= csrf_field() ?><button name="action" value="delete" class="danger">🗑 Удалить из системы</button>
			</form>
		</div>

		<?php if ($reports = report_files($rid)): ?>
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
					render_section($i, $s, $proxyNames);
				} ?>
			</div>
			<button type="button" onclick="addSection()">➕ Добавить секцию</button>
		</div>

		<div class="sticky">
			<button type="submit" class="primary" <?= $agentOk ? '' : 'disabled' ?>>📤 Отправить на роутер</button>
			<a class="btn" href="router.php?id=<?= $rid ?>">Сбросить изменения</a>
			<span class="muted">&nbsp; Роутер применит всё разом, проверит новые ключи (неработающий — вернёт прежний) и на ~10 с переподключит устройства.</span>
		</div>
	</form>

	<template id="tpl"><?php render_section(9999, ['orig' => '', 'name' => '', 'type' => 'proxy', 'config' => 'url', 'status' => '', 'detour' => '', 'cur_detour' => '', 'key' => '', 'domains' => '', 'subnets' => '', 'community' => [], 'remote' => '', 'delete' => false], $proxyNames); ?></template>

	<script>
		let next = <?= count($sections) + 1 ?>;
		function addSection() {
			const html = document.getElementById('tpl').innerHTML.replaceAll('[9999]', '[' + (next++) + ']');
			document.getElementById('sections').insertAdjacentHTML('beforeend', html);
		}
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
