<?php
// Пользователи сервиса. Один код и одна база работают в двух режимах:
//   mode = 'admin'   — панель владельца (его роутеры, пользователи сервиса, настройки сервиса);
//   mode = 'service' — личные кабинеты пользователей (регистрация, только свои роутеры).
// «Владелец» роутера и настроек: 0 — администратор, иначе id пользователя.

function is_service(): bool
{
	return cfg('mode') === 'service';
}

$GLOBALS['UID'] = 0;
$GLOBALS['USER'] = null;

// Чьи данные сейчас обрабатываются (вход в кабинет, запрос роутера пользователя)
function cur_uid(): int
{
	return (int)$GLOBALS['UID'];
}

function set_uid(int $uid): void
{
	$GLOBALS['UID'] = $uid;
}

function cur_user(): ?array
{
	return $GLOBALS['USER'];
}

// Ключ настроек в kv: у администратора — как раньше, у пользователя — со своим префиксом
function okey(string $k, ?int $uid = null): string
{
	$u = $uid ?? cur_uid();
	return $u ? "u$u:$k" : $k;
}

function get_user(int $id): ?array
{
	return $id ? row('SELECT * FROM users WHERE id = ?', [$id]) : null;
}

function user_by_email(string $email): ?array
{
	return row('SELECT * FROM users WHERE email = ? COLLATE NOCASE', [trim($email)]);
}

// Адрес кабинета пользователей (для ссылок в письмах и уведомлениях; админка его знает из своего config.php)
function service_url(): string
{
	return rtrim((string)(is_service() ? cfg('base_url') : (cfg('service_url') ?: cfg('base_url'))), '/');
}

// ---------- Настройки сервиса (задаёт администратор) ----------

function svc(): array
{
	return (kv_get('svc', []) ?: []) + [
		'registration' => 'open',   // open — свободная, closed — закрыта
		'trial_days' => 14,
		'router_limit' => 3,
		'grace_days' => 7,          // после окончания оплаты уведомления ещё идут столько дней
		'price' => 0,               // рублей в месяц (списывается с баланса по дням); 0 — бесплатно для всех
		'pay_text' => '',           // как оплатить (пока нет платёжного шлюза)
		'name' => 'Tim Routers',    // название сервиса в документах
		'op_name' => '',            // оператор ПДн: ФИО (самозанятый) или ИП/ООО
		'op_inn' => '',
		'op_ogrn' => '',
		'op_email' => '',
		'op_address' => '',
		'docs_date' => '',          // дата редакции документов
	];
}

// ---------- Подписка ----------

// ['active' => можно управлять, 'notify' => уведомления ещё идут, 'label' => текст, 'until' => …]
function user_plan(array $u): array
{
	$now = time();
	if (!empty($u['free']) || price_rub() <= 0) {
		return ['active' => true, 'notify' => true, 'free' => true, 'until' => 0, 'label' => 'бесплатно (без срока)'];
	}
	// С учётом баланса: деньги списываются по дням, пока их хватает
	$until = paid_until_projected($u);
	if ($until > $now && ((int)$u['paid_until'] > $now || (int)$u['balance'] >= daily_kop())) {
		$trial = empty($u['paid_ever']);
		return ['active' => true, 'notify' => true, 'free' => false, 'until' => $until,
			'label' => ($trial ? 'пробный период до ' : 'работает до ') . date('d.m.Y', $until)];
	}
	$until = (int)$u['paid_until'];
	$grace = $until + (int)svc()['grace_days'] * 86400;
	return ['active' => false, 'notify' => $grace > $now, 'free' => false, 'until' => $until,
		'label' => 'закончилась ' . ($until ? date('d.m.Y', $until) : '')];
}

// ---------- Баланс ----------
// Пользователь пополняет баланс; каждый день, когда оплаченный срок кончается, списывается цена за день
// (цена в месяц / 30) и срок продлевается на сутки. Пробный и подаренные дни — без списаний.

function price_rub(): int
{
	return max(0, (int)svc()['price']);
}

function price_text(): string
{
	return price_rub() > 0 ? price_rub() . ' ₽ в месяц' : 'бесплатно';
}

function daily_kop(): int
{
	return (int)round(price_rub() * 100 / 30);
}

function money(int $kop): string
{
	$r = $kop / 100;
	return (fmod($r, 1.0) == 0.0 ? number_format($r, 0, ',', ' ') : number_format($r, 2, ',', ' ')) . ' ₽';
}

// До какого дня хватит оплаченного срока и баланса
function paid_until_projected(array $u): int
{
	$base = max(time(), (int)$u['paid_until']);
	$d = daily_kop();
	return $d > 0 ? $base + intdiv((int)$u['balance'], $d) * 86400 : $base;
}

// Операция по балансу: + пополнение / − возврат; kind: topup|refund|charge|gift
function balance_op(int $uid, int $kop, string $kind, string $comment = ''): void
{
	q('UPDATE users SET balance = balance + ?' . ($kind === 'topup' ? ', paid_ever = 1' : '') . ' WHERE id = ?', [$kop, $uid]);
	q('INSERT INTO payments (user_id, ts, amount, kind, comment) VALUES (?, ?, ?, ?, ?)', [$uid, time(), $kop, $kind, mb_substr($comment, 0, 200)]);
}

// Из cron: продлить на сутки тех, у кого срок кончается, а на балансе есть деньги
function billing_tick(): void
{
	$d = daily_kop();
	if ($d <= 0) {
		return;
	}
	foreach (rows("SELECT * FROM users WHERE status = 'active' AND free = 0 AND paid_until < ? AND balance >= ?", [time() + 600, $d]) as $u) {
		q('UPDATE users SET balance = balance - ?, paid_until = ?, paid_ever = 1 WHERE id = ? AND balance >= ?', [$d, max(time(), (int)$u['paid_until']) + 86400, $u['id'], $d]);
		q('INSERT INTO payments (user_id, ts, amount, kind, comment) VALUES (?, ?, ?, ?, ?)', [$u['id'], time(), -$d, 'charge', 'сутки работы сервиса']);
		$left = (int)$u['balance'] - $d;
		if ($left < $d * 3 && $left + $d >= $d * 3) {
			notify_user((int)$u['id'], '💳 На балансе осталось ' . money($left) . ' — это примерно на ' . intdiv($left, $d) . ' дн. Пополните баланс в «Настройках» кабинета, чтобы сервис не отключился.');
		}
	}
}


// Пробный период: ещё ни разу не платил, не «бесплатно для своих», сервис платный
function user_in_trial(array $u): bool
{
	return empty($u['free']) && empty($u['paid_ever']) && price_rub() > 0;
}

// Лимит роутеров: 0 — без ограничений. Ограничение — только в пробный период;
// администратор может задать лимит конкретному кабинету вручную.
function user_router_limit(array $u): int
{
	if ((int)$u['router_limit'] > 0) {
		return (int)$u['router_limit'];
	}
	return user_in_trial($u) ? max(1, (int)svc()['router_limit']) : 0;
}

function user_limit_reached(array $u, int $count): bool
{
	$l = user_router_limit($u);
	return $l > 0 && $count >= $l;
}

// «2 из 3 (пробный период)» или «2 (без ограничений)»
function user_limit_text(array $u, int $count): string
{
	$l = user_router_limit($u);
	if ($l <= 0) {
		return $count . ' (без ограничений)';
	}
	return $count . ' из ' . $l . ((int)$u['router_limit'] > 0 ? '' : ' (в пробный период; после оплаты — без ограничений)');
}

function user_router_count(int $uid): int
{
	return (int)val("SELECT COUNT(*) FROM routers WHERE user_id = ? AND status IN ('active', 'pending')", [$uid]);
}

// Можно ли пользователю сейчас что-то менять (активная подписка, не заблокирован)
function user_can_manage(?array $u): bool
{
	return $u && $u['status'] === 'active' && user_plan($u)['active'];
}

// ---------- Доступ к роутеру ----------

// 'own' — свой; 'support' — администратор, пользователь открыл доступ; 'view' — администратор, только просмотр
function router_access(array $r): ?string
{
	$owner = (int)$r['user_id'];
	if (is_service()) {
		return $owner === cur_uid() && cur_uid() > 0 ? 'own' : null;
	}
	if ($owner === 0) {
		return 'own';
	}
	$u = get_user($owner);
	if (!$u) {
		return 'view';
	}
	return (int)$u['support_until'] > time() ? 'support' : 'view';
}

// Роутер из адресной строки: чужой — как будто его нет
function router_for_page(int $id): ?array
{
	$r = get_router($id);
	return $r && router_access($r) ? $r : null;
}

// ---------- Уведомления владельцу ----------

function notify_owner(int $uid, string $text, ?array $kb = null): void
{
	if ($uid === 0) {
		notify_admins($text, $kb);
		return;
	}
	notify_user($uid, $text, $kb);
}

// Пользователю: лента в кабинете + его ВКонтакте (если подключил). Администратору — ничего.
function notify_user(int $uid, string $text, ?array $kb = null): void
{
	$u = get_user($uid);
	if (!$u || $u['status'] === 'deleted') {
		return;
	}
	q('INSERT INTO outbox (ts, chat_id, text, user_id) VALUES (?, ?, ?, ?)', [time(), 'site', $text, $uid]);
	if ($u['status'] !== 'active' || !user_plan($u)['notify']) {
		return;
	}
	try {
		vk_notify($text, notify_link($kb, $uid), $uid);
	} catch (Throwable $e) {
		error_log('vk_notify user ' . $uid . ': ' . $e->getMessage());
	}
}

// ---------- Шифрование секретов в базе (ключи ВК, секреты двухфакторки) ----------

function app_key(): ?string
{
	$k = (string)cfg('app_key', '');
	if ($k === '' || !function_exists('sodium_crypto_secretbox')) {
		return null;
	}
	return hash('sha256', $k, true);
}

function secret_enc(string $plain): string
{
	$key = app_key();
	if ($plain === '' || !$key) {
		return $plain;
	}
	$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
	return 'enc:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
}

function secret_dec(string $stored): string
{
	if (strncmp($stored, 'enc:', 4) !== 0) {
		return $stored;
	}
	$key = app_key();
	$bin = base64_decode(substr($stored, 4), true);
	if (!$key || $bin === false || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
		return '';
	}
	$plain = sodium_crypto_secretbox_open(substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
	return $plain === false ? '' : $plain;
}

// ---------- Одноразовые коды (подтверждение почты, сброс пароля) ----------

function token_create(int $uid, string $type, int $ttl): string
{
	$code = bin2hex(random_bytes(20));
	q('DELETE FROM tokens WHERE (user_id = ? AND type = ?) OR expires < ?', [$uid, $type, time()]);
	q('INSERT INTO tokens (hash, user_id, type, expires) VALUES (?, ?, ?, ?)', [hash('sha256', $code), $uid, $type, time() + $ttl]);
	return $code;
}

// id пользователя, если код действителен (код при этом сгорает)
function token_use(string $code, string $type): ?int
{
	if (!preg_match('/^[a-f0-9]{40}$/', $code)) {
		return null;
	}
	$t = row('SELECT * FROM tokens WHERE hash = ? AND type = ?', [hash('sha256', $code), $type]);
	if (!$t || (int)$t['expires'] < time()) {
		return null;
	}
	q('DELETE FROM tokens WHERE hash = ?', [$t['hash']]);
	return (int)$t['user_id'];
}

// ---------- Почта ----------

function send_mail(string $to, string $subject, string $text): bool
{
	if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
		return false;
	}
	$host = parse_url(service_url(), PHP_URL_HOST) ?: 'localhost';
	$from = (string)(cfg('mail_from') ?: 'noreply@' . $host);
	$name = '=?UTF-8?B?' . base64_encode(svc()['name']) . '?=';
	$headers = implode("\r\n", [
		'From: ' . $name . ' <' . $from . '>',
		'MIME-Version: 1.0',
		'Content-Type: text/plain; charset=UTF-8',
		'Content-Transfer-Encoding: base64',
		'Auto-Submitted: auto-generated',
	]);
	$text .= "\n\n—\n" . svc()['name'] . ' · ' . service_url() . "/\nЭто письмо отправлено автоматически, отвечать на него не нужно.";
	if (cfg('mail_log')) {
		// Для локальной проверки: письма складываются в файл вместо отправки
		file_put_contents(data_dir() . '/mail.log', date('c') . " TO $to\nSUBJECT $subject\n$text\n\n", FILE_APPEND);
		return true;
	}
	$ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', chunk_split(base64_encode($text)), $headers, '-f' . $from);
	if (!$ok) {
		error_log('mail to ' . $to . ' failed');
	}
	return $ok;
}

function send_verify_mail(array $u): bool
{
	$code = token_create((int)$u['id'], 'verify', 3 * 86400);
	return send_mail($u['email'], 'Подтвердите почту',
		"Здравствуйте!\n\nВы зарегистрировались в сервисе «" . svc()['name'] . "».\nЧтобы подтвердить адрес почты, откройте ссылку (действует 3 дня):\n\n"
		. service_url() . '/verify.php?c=' . $code . "\n\nЕсли вы не регистрировались — просто удалите это письмо.");
}

// ---------- Пароли ----------

function password_problem(string $p, string $email = ''): ?string
{
	if (mb_strlen($p) < 10) {
		return 'Пароль должен быть не короче 10 символов.';
	}
	if (mb_strlen($p) > 200) {
		return 'Слишком длинный пароль.';
	}
	$low = mb_strtolower($p);
	if ($email !== '' && mb_strpos($low, mb_strtolower(strtok($email, '@'))) !== false) {
		return 'Пароль не должен содержать имя из адреса почты.';
	}
	foreach (['1234567890', 'qwertyuiop', 'password', 'пароль', '0987654321', 'йцукенгшщз', '1111111111'] as $bad) {
		if (mb_strpos($low, $bad) !== false) {
			return 'Пароль слишком простой — придумайте другой.';
		}
	}
	if (count(array_unique(mb_str_split($p))) < 5) {
		return 'Пароль слишком простой — используйте разные символы.';
	}
	return null;
}

function hash_password(string $p): string
{
	return password_hash($p, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
}

// ---------- Удаление пользователя со всеми данными ----------

function delete_user(int $uid): void
{
	if ($uid <= 0) {
		return;
	}
	$rids = array_map('intval', array_column(rows('SELECT id FROM routers WHERE user_id = ?', [$uid]), 'id'));
	foreach ($rids as $rid) {
		foreach (glob(data_dir() . "/reports/router-$rid-*.txt") ?: [] as $f) {
			@unlink($f);
		}
		q('DELETE FROM sections WHERE router_id = ?', [$rid]);
		q('DELETE FROM tasks WHERE router_id = ?', [$rid]);
		q('DELETE FROM events WHERE router_id = ?', [$rid]);
	}
	// Роутеры остаются строчкой «удалён» без данных: агент получит ответ removed и перестанет присылать данные
	q("UPDATE routers SET status = 'removed', name = '', info = '{}', ip = '', user_id = ? WHERE user_id = ?", [-$uid, $uid]);
	q('DELETE FROM events WHERE user_id = ?', [$uid]);
	q('DELETE FROM outbox WHERE user_id = ?', [$uid]);
	q('DELETE FROM tokens WHERE user_id = ?', [$uid]);
	q('DELETE FROM ticket_msgs WHERE ticket_id IN (SELECT id FROM tickets WHERE user_id = ?)', [$uid]);
	q('DELETE FROM tickets WHERE user_id = ?', [$uid]);
	q("DELETE FROM kv WHERE k LIKE ?", ["u$uid:%"]);
	q('DELETE FROM users WHERE id = ?', [$uid]);
	foreach (glob(data_dir() . '/sessions-svc/sess_*') ?: [] as $f) {
		$c = (string)@file_get_contents($f);
		if (preg_match('/uid\|i:' . $uid . ';/', $c)) {
			@unlink($f);
		}
	}
}

// Обращения в поддержку, на которые администратор ещё не ответил
function open_tickets_count(): int
{
	return (int)val("SELECT COUNT(*) FROM tickets WHERE status = 'open'");
}

// Переписка по обращению (для страницы и для живого обновления)
function ticket_msgs_html(int $tid, bool $forAdmin): string
{
	$out = '';
	foreach (rows('SELECT * FROM ticket_msgs WHERE ticket_id = ? ORDER BY id', [$tid]) as $m) {
		$who = $m['from_admin'] ? ($forAdmin ? 'Вы' : 'Поддержка') : ($forAdmin ? 'Пользователь' : 'Вы');
		$out .= '<div class="msg" style="' . ($m['from_admin'] ? 'border-color:#22a06b' : '') . '"><span class="muted">' . $who . ' · ' . date('d.m.Y H:i', (int)$m['ts']) . '</span><br>' . nl2br(h($m['text'])) . '</div>';
	}
	return $out;
}
