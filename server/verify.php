<?php
// Подтверждение почты по ссылке из письма; повторная отправка письма.

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/panel.php';

only_mode('service');
panel_start();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['resend'])) {
	$u = cur_user();
	if ($u && csrf_ok() && !$u['verified_at']) {
		$last = (int)kv_get(okey('verify_sent'), 0);
		if (time() - $last < 120) {
			$_SESSION['flash'] = 'Письмо уже отправлено — подождите пару минут и проверьте папку «Спам».';
		} else {
			kv_set(okey('verify_sent'), time());
			send_verify_mail($u);
			$_SESSION['flash'] = 'Письмо отправлено на ' . $u['email'] . '. Если его нет — проверьте папку «Спам».';
		}
	}
	header('Location: index.php');
	exit;
}

$uid = token_use((string)($_GET['c'] ?? ''), 'verify');
if ($uid && ($u = get_user($uid))) {
	q('UPDATE users SET verified_at = COALESCE(verified_at, ?) WHERE id = ?', [time(), $uid]);
	set_uid($uid);
	event(null, 'Почта подтверждена');
	set_uid(cur_user() ? (int)cur_user()['id'] : 0);
	$text = 'Почта ' . $u['email'] . ' подтверждена.';
	if (panel_authed()) {
		$_SESSION['flash'] = $text . ' Теперь можно добавлять роутеры.';
	} else {
		$_SESSION['flash_guest'] = $text . ' Войдите в кабинет.';
	}
	header('Location: index.php');
	exit;
}

panel_header('Подтверждение почты');
?>
	<div class="card login">
		<h2>Ссылка не подошла</h2>
		<p>Ссылка устарела или уже использована. Войдите в кабинет и нажмите «Отправить письмо ещё раз».</p>
		<p><a href="index.php">← В кабинет</a></p>
	</div>
<?php
panel_footer();
