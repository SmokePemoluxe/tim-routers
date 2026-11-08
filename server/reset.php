<?php
// Восстановление пароля: письмо со ссылкой (действует 1 час) → новый пароль.
// Ответ одинаковый, есть такая почта или нет — чтобы нельзя было проверить, кто зарегистрирован.

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/panel.php';

only_mode('service');
panel_start();

$err = '';
$code = (string)($_GET['c'] ?? $_POST['c'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $code === '') {
	$email = trim((string)($_POST['email'] ?? ''));
	$ip = client_ip();
	$rl = kv_get('rst_' . $ip, ['n' => 0, 'ts' => time()]);
	if (time() - (int)$rl['ts'] > 3600) {
		$rl = ['n' => 0, 'ts' => time()];
	}
	if (!csrf_ok()) {
		$err = 'Страница устарела — попробуйте ещё раз.';
	} elseif ($rl['n'] >= 5) {
		$err = 'Слишком много запросов. Попробуйте через час.';
	} else {
		$rl['n']++;
		kv_set('rst_' . $ip, $rl);
		$u = user_by_email($email);
		if ($u && $u['status'] === 'active' && time() - (int)kv_get(okey('reset_sent', (int)$u['id']), 0) > 120) {
			kv_set(okey('reset_sent', (int)$u['id']), time());
			$c = token_create((int)$u['id'], 'reset', 3600);
			send_mail($u['email'], 'Восстановление пароля', "Кто-то (надеемся, вы) запросил смену пароля кабинета.\nЧтобы задать новый пароль, откройте ссылку (действует 1 час):\n\n"
				. service_url() . '/reset.php?c=' . $c . "\n\nЕсли вы не запрашивали — просто удалите письмо, пароль останется прежним.\nЗапрос был с IP " . $ip);
		}
		$_SESSION['flash_guest'] = 'Если такая почта зарегистрирована, на неё отправлено письмо со ссылкой. Проверьте и папку «Спам».';
		header('Location: index.php');
		exit;
	}
}

$valid = false;
if ($code !== '') {
	$t = preg_match('/^[a-f0-9]{40}$/', $code) ? row("SELECT * FROM tokens WHERE hash = ? AND type = 'reset' AND expires > ?", [hash('sha256', $code), time()]) : null;
	$valid = (bool)$t;
	if ($valid && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
		$u = get_user((int)$t['user_id']);
		$pw = (string)($_POST['password'] ?? '');
		if (!csrf_ok()) {
			$err = 'Страница устарела — попробуйте ещё раз.';
		} elseif ($p = password_problem($pw, $u['email'] ?? '')) {
			$err = $p;
		} elseif ($pw !== (string)($_POST['password2'] ?? '')) {
			$err = 'Пароли не совпадают.';
		} elseif (token_use($code, 'reset') === (int)$u['id']) {
			// Новый пароль завершает все входы; почта подтверждена — письмо же дошло
			q('UPDATE users SET pass_hash = ?, sess_ver = sess_ver + 1, verified_at = COALESCE(verified_at, ?) WHERE id = ?', [hash_password($pw), time(), $u['id']]);
			kv_del('ufail_' . $u['id']);
			set_uid((int)$u['id']);
			event(null, 'Пароль восстановлен по ссылке из письма, IP ' . client_ip());
			notify_user((int)$u['id'], '🔑 <b>Пароль кабинета изменён</b> по ссылке из письма. IP: ' . h(client_ip()) . "\nЕсли это были не вы — срочно восстановите пароль снова и включите двухфакторную защиту.");
			set_uid(0);
			$_SESSION = ['csrf' => bin2hex(random_bytes(16)), 'flash_guest' => 'Пароль изменён. Войдите с новым паролем.'];
			header('Location: index.php');
			exit;
		}
	}
}

panel_header('Восстановление пароля');
?>
	<div class="card login">
		<h2>Восстановление пароля</h2>
		<?php if ($err): ?><div class="notice err"><?= h($err) ?></div><?php endif; ?>
		<?php if ($code !== '' && !$valid): ?>
			<p>Ссылка устарела или уже использована. Запросите новую.</p>
			<p><a href="reset.php">Запросить ссылку</a></p>
		<?php elseif ($valid): ?>
			<form method="post">
				<?= csrf_field() ?>
				<input type="hidden" name="c" value="<?= h($code) ?>">
				<label class="field">Новый пароль <span class="muted">(не короче 10 символов)</span></label>
				<input type="password" name="password" autocomplete="new-password" required minlength="10" autofocus>
				<label class="field">Ещё раз</label>
				<input type="password" name="password2" autocomplete="new-password" required minlength="10">
				<p><button type="submit" class="primary" style="width:100%">Сохранить пароль</button></p>
			</form>
		<?php else: ?>
			<form method="post">
				<?= csrf_field() ?>
				<label class="field">Почта кабинета</label>
				<input type="email" name="email" autocomplete="email" required autofocus>
				<p><button type="submit" class="primary" style="width:100%">Прислать ссылку</button></p>
			</form>
		<?php endif; ?>
		<p style="text-align:center"><a href="index.php">← Вход</a></p>
	</div>
<?php
panel_footer();
