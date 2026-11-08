<?php
// Регистрация в сервисе: почта + пароль, согласие с соглашением и (отдельно) на обработку персональных данных.

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/panel.php';
require __DIR__ . '/inc/legal.php';

only_mode('service');
panel_start();
if (panel_authed()) {
	header('Location: index.php');
	exit;
}

$svc = svc();
$err = '';
$email = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
	$email = mb_strtolower(mb_substr(trim((string)($_POST['email'] ?? '')), 0, 120));
	$pw = (string)($_POST['password'] ?? '');
	$ip = client_ip();
	$rl = kv_get('reg_' . $ip, ['n' => 0, 'ts' => time()]);
	if (time() - (int)$rl['ts'] > 3600) {
		$rl = ['n' => 0, 'ts' => time()];
	}
	$started = (int)($_SESSION['reg_form'] ?? 0);
	if ($svc['registration'] !== 'open') {
		$err = 'Регистрация сейчас закрыта.';
	} elseif (!csrf_ok()) {
		$err = 'Страница устарела — попробуйте ещё раз.';
	} elseif ((string)($_POST['website'] ?? '') !== '' || !$started || time() - $started < 4) {
		// Поле-ловушка заполнено или форму отправили быстрее человека — похоже на бота
		$err = 'Не получилось. Подождите несколько секунд и попробуйте ещё раз.';
		$_SESSION['reg_form'] = time();
	} elseif ($rl['n'] >= 3) {
		$err = 'С этого адреса уже было несколько регистраций за последний час. Попробуйте позже.';
	} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
		$err = 'Проверьте адрес почты.';
	} elseif ($p = password_problem($pw, $email)) {
		$err = $p;
	} elseif ($pw !== (string)($_POST['password2'] ?? '')) {
		$err = 'Пароли не совпадают.';
	} elseif (empty($_POST['agree_terms'])) {
		$err = 'Нужно принять пользовательское соглашение.';
	} elseif (empty($_POST['agree_pd'])) {
		$err = 'Нужно согласие на обработку персональных данных — без него сервис не сможет создать кабинет.';
	} elseif (user_by_email($email)) {
		// Не сообщаем прямо, что почта занята, — пишем владельцу письмо
		send_mail($email, 'Попытка регистрации', "Кто-то пытался зарегистрироваться в сервисе с вашей почтой. Если это были вы — кабинет у вас уже есть, войдите или восстановите пароль:\n" . service_url() . "/reset.php\n\nЕсли не вы — ничего делать не нужно.");
		$rl['n']++;
		kv_set('reg_' . $ip, $rl);
		$_SESSION['flash_guest'] = 'Проверьте почту ' . $email . ' — мы отправили письмо со следующим шагом.';
		header('Location: index.php');
		exit;
	} else {
		q('INSERT INTO users (email, pass_hash, created_at, reg_ip, paid_until, consent_at, consent_ver) VALUES (?, ?, ?, ?, ?, ?, ?)', [
			$email, hash_password($pw), time(), $ip, time() + (int)$svc['trial_days'] * 86400, time(), legal_version(),
		]);
		$uid = (int)db()->lastInsertId();
		$rl['n']++;
		kv_set('reg_' . $ip, $rl);
		set_uid($uid);
		event(null, 'Кабинет создан; приняты соглашение и согласие на обработку данных (редакция ' . legal_version() . '), IP ' . $ip);
		$u = get_user($uid);
		send_verify_mail($u);
		complete_login(false, $uid);
		$_SESSION['flash'] = 'Кабинет создан. Мы отправили письмо на ' . $email . ' — подтвердите почту, чтобы добавлять роутеры. Если письма нет во «Входящих», проверьте папку «Спам» и отметьте письмо как «Не спам».';
		header('Location: index.php');
		exit;
	}
}
$_SESSION['reg_form'] = $_SESSION['reg_form'] ?? time();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	$_SESSION['reg_form'] = time();
}

panel_header('Регистрация');
?>
	<div class="card login" style="max-width:420px">
		<h2>Регистрация</h2>
		<?php if ($svc['registration'] !== 'open'): ?>
			<p>Регистрация сейчас закрыта.</p>
			<p><a href="index.php">← Вход</a></p>
		<?php else: ?>
			<?php if ($err): ?><div class="notice err"><?= h($err) ?></div><?php endif; ?>
			<p class="muted">Удалённое управление роутерами OpenWrt с podkop. <?= (int)$svc['trial_days'] ?> дней бесплатно<?= price_rub() > 0 ? ', дальше — ' . h(price_text()) : '' ?>.</p>
			<form method="post" autocomplete="on">
				<?= csrf_field() ?>
				<div class="hp" aria-hidden="true"><label>Сайт <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
				<label class="field">Почта</label>
				<input type="email" name="email" autocomplete="email" required value="<?= h($email) ?>">
				<label class="field">Пароль <span class="muted">(не короче 10 символов)</span></label>
				<input type="password" name="password" autocomplete="new-password" required minlength="10">
				<label class="field">Пароль ещё раз</label>
				<input type="password" name="password2" autocomplete="new-password" required minlength="10">
				<p style="margin-top:12px"><label class="check"><input type="checkbox" name="agree_terms" value="1" required data-agree>
					<span>Принимаю <a href="legal.php?doc=terms" target="_blank">пользовательское соглашение</a></span></label></p>
				<p><label class="check"><input type="checkbox" name="agree_pd" value="1" required data-agree>
					<span>Даю <a href="legal.php?doc=consent" target="_blank">согласие на обработку персональных данных</a>
					в соответствии с <a href="legal.php?doc=privacy" target="_blank">политикой конфиденциальности</a></span></label></p>
				<p><button type="submit" class="primary" style="width:100%" id="reg-btn" disabled>Создать кабинет</button></p>
				<p class="muted" id="reg-hint">Чтобы создать кабинет, отметьте обе галочки.</p>
			</form>
			<script>
			(function () {
				var boxes = document.querySelectorAll('[data-agree]'), btn = document.getElementById('reg-btn'), hint = document.getElementById('reg-hint');
				function upd() { var ok = Array.prototype.every.call(boxes, function (b) { return b.checked; }); btn.disabled = !ok; hint.style.display = ok ? 'none' : ''; }
				Array.prototype.forEach.call(boxes, function (b) { b.addEventListener('change', upd); });
				upd();
			})();
			</script>
			<p style="text-align:center">Уже есть кабинет? <a href="index.php">Войти</a></p>
		<?php endif; ?>
	</div>
<?php
panel_footer();
