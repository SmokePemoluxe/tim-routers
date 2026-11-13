<?php
const MSG_MAX = 5000; // предел одного сообщения в обращении (символов)

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
		'router_limit' => 2,        // роутеров в пробный период
		'base_routers' => 5,        // роутеров, входящих в цену подписки; каждый следующий — цена / base_routers
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
	if ($until > $now && ((int)$u['paid_until'] > $now || (int)$u['balance'] >= daily_kop($u))) {
		// Бесплатные дни (пробный период или подаренные) идут без списаний с баланса
		$fu = user_free_until($u);
		$kind = $fu ? user_free_kind($u) : '';
		$label = $fu ? $kind . ' до ' . date('d.m.Y', $fu) . ((int)$u['balance'] >= daily_kop($u) ? ', с балансом — до ' . date('d.m.Y', $until) : '')
			: 'работает до ' . date('d.m.Y', $until);
		return ['active' => true, 'notify' => true, 'free' => false, 'until' => $until, 'free_until' => $fu, 'free_kind' => $kind, 'label' => $label];
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

// Сколько роутеров входит в цену подписки
function base_routers(): int
{
	return max(1, (int)svc()['base_routers']);
}

// Цена одного роутера сверх включённых, копеек в месяц (цена / base_routers)
function router_price_kop(): int
{
	return (int)round(price_rub() * 100 / base_routers());
}

// Оплачиваемый лимит роутеров кабинета: не меньше включённых в цену (users.router_limit — сколько выбрано)
function user_slots(array $u): int
{
	return max(base_routers(), (int)$u['router_limit']);
}

// Цена в месяц для кабинета, копеек: базовая цена + каждый роутер сверх включённых
function user_month_kop(?array $u = null): int
{
	$extra = $u ? user_slots($u) - base_routers() : 0;
	return price_rub() * 100 + $extra * router_price_kop();
}

function price_text(?array $u = null): string
{
	if (price_rub() <= 0) {
		return 'бесплатно';
	}
	if ($u && user_slots($u) > base_routers()) {
		return money(user_month_kop($u)) . ' в месяц за ' . user_slots($u) . ' роутеров';
	}
	return price_rub() . ' ₽ в месяц за ' . base_routers() . ' роутеров';
}

// Списание за сутки: цена в месяц / 30
function daily_kop(?array $u = null): int
{
	return (int)round(user_month_kop($u) / 30);
}

function money(int $kop): string
{
	$r = $kop / 100;
	return (fmod($r, 1.0) == 0.0 ? number_format($r, 0, ',', ' ') : number_format($r, 2, ',', ' ')) . ' ₽';
}

// До какого момента идут бесплатные дни (пробный период, подаренные) — 0, если их нет.
// Списание продлевает срок на сутки, поэтому срок дальше суток вперёд — это дни без списаний.
function user_free_until(array $u): int
{
	$pu = (int)$u['paid_until'];
	return $pu > time() + 86400 + 900 ? $pu : 0;
}

// «пробный период» — пока не было ни списаний, ни подарков и срок не дальше пробного; иначе «бесплатные дни»
function user_free_kind(array $u): string
{
	$trialEnd = (int)$u['created_at'] + (int)svc()['trial_days'] * 86400 + 3600;
	$other = val("SELECT 1 FROM payments WHERE user_id = ? AND kind IN ('charge', 'gift') LIMIT 1", [(int)$u['id']]);
	return !$other && (int)$u['paid_until'] <= $trialEnd ? 'пробный период' : 'бесплатные дни';
}

// До какого дня хватит оплаченного срока и баланса
function paid_until_projected(array $u): int
{
	$base = max(time(), (int)$u['paid_until']);
	$d = daily_kop($u);
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
	if (price_rub() <= 0) {
		return;
	}
	foreach (rows("SELECT * FROM users WHERE status = 'active' AND free = 0 AND paid_until < ? AND balance > 0", [time() + 600]) as $u) {
		$d = daily_kop($u); // у каждого своя цена: зависит от выбранного числа роутеров
		if ($d <= 0 || (int)$u['balance'] < $d) {
			continue;
		}
		q('UPDATE users SET balance = balance - ?, paid_until = ?, paid_ever = 1 WHERE id = ? AND balance >= ?', [$d, max(time(), (int)$u['paid_until']) + 86400, $u['id'], $d]);
		q('INSERT INTO payments (user_id, ts, amount, kind, comment) VALUES (?, ?, ?, ?, ?)', [$u['id'], time(), -$d, 'charge', 'сутки работы сервиса']);
	}
	plan_warnings();
}

// Предупреждения за 3 дня и за 1 день до окончания подписки (пробного периода, подаренных дней
// или денег на балансе). Каждое — один раз на дату окончания; после пополнения дата сдвигается — счёт заново.
function plan_warnings(): void
{
	$now = time();
	foreach (rows("SELECT * FROM users WHERE status = 'active' AND free = 0") as $u) {
		$p = user_plan($u);
		if (!$p['active'] || $p['free'] || $p['until'] <= $now) {
			continue;
		}
		$left = $p['until'] - $now;
		$step = $left <= 86400 ? 1 : ($left <= 3 * 86400 ? 3 : 0);
		if (!$step) {
			continue;
		}
		$key = okey('plan_warn', (int)$u['id']);
		$w = kv_get($key, []);
		$day = date('Y-m-d', $p['until']);
		if (($w['day'] ?? '') === $day && in_array($step, (array)($w['sent'] ?? []), true)) {
			continue;
		}
		$sent = ($w['day'] ?? '') === $day ? (array)$w['sent'] : [];
		$sent[] = $step;
		if ($step === 1) {
			$sent[] = 3; // за день до конца — предупреждение «за 3 дня» уже не нужно
		}
		kv_set($key, ['day' => $day, 'sent' => array_values(array_unique($sent))]);
		$what = !empty($p['free_until']) && (int)$u['balance'] < daily_kop($u)
			? (($p['free_kind'] ?? '') === 'пробный период' ? 'Пробный период закончится' : 'Бесплатные дни закончатся')
			: 'Средства на балансе закончатся';
		$when = $step === 1 ? 'меньше чем через сутки' : 'через ' . max(1, (int)round($left / 86400)) . ' дн.';
		notify_user((int)$u['id'], '⏳ ' . $what . ' ' . $when . ' — ' . dt((int)$p['until'], 'd.m.Y H:i') . '. Чтобы сервис продолжил работать, пополните баланс в «Настройках» кабинета: ' . price_text($u) . ' (' . money(daily_kop($u)) . ' в сутки). Без подписки роутеры продолжат работать с текущими настройками podkop, но выключатся сторож интернета, автоматика подписок и изменения с сайта.');
	}
}


// Пробный период: ещё ни разу не платил, не «бесплатно для своих», сервис платный
function user_in_trial(array $u): bool
{
	return empty($u['free']) && empty($u['paid_ever']) && price_rub() > 0;
}

// Лимит роутеров: 0 — без ограничений («бесплатно для своих» или сервис бесплатный).
// Пробный период — svc router_limit; после оплаты — выбранное число роутеров (не меньше включённых в цену).
function user_router_limit(array $u): int
{
	if (!empty($u['free']) || price_rub() <= 0) {
		return 0;
	}
	return user_in_trial($u) ? max(1, (int)svc()['router_limit']) : user_slots($u);
}

// Почему нельзя добавить ещё роутер — для сообщений и плашки
function user_limit_msg(array $u): string
{
	$l = user_router_limit($u);
	if (user_in_trial($u)) {
		return "В пробный период можно подключить до $l роутеров. После оплаты подписки — до " . user_slots($u) . ', а при необходимости — больше: лимит увеличивается в «Настройках».';
	}
	return "Подключено максимальное число роутеров по вашей подписке ($l). Чтобы подключить больше, увеличьте лимит в «Настройках» — каждый роутер сверх " . base_routers() . ' стоит ' . money(router_price_kop()) . ' в месяц.';
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
	return $count . ' из ' . $l . (user_in_trial($u) ? ' (пробный период; после оплаты — до ' . user_slots($u) . ')' : '');
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
		$out .= '<div class="msg" style="' . ($m['from_admin'] ? 'border-color:#22a06b' : '') . '"><span class="muted">' . $who . ' · ' . dt((int)$m['ts'], 'd.m.Y H:i') . '</span><br>' . nl2br(h($m['text'])) . '</div>';
	}
	return $out;
}
