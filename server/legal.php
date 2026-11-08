<?php
// Документы сервиса: доступны без входа.

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/panel.php';
require __DIR__ . '/inc/legal.php';

only_mode('service');
panel_start();

$doc = legal_doc((string)($_GET['doc'] ?? ''));
if (!$doc) {
	http_response_code(404);
	$doc = ['Документ не найден', '<p><a href="legal.php?doc=terms">Пользовательское соглашение</a> · <a href="legal.php?doc=privacy">Политика конфиденциальности</a> · <a href="legal.php?doc=consent">Согласие на обработку данных</a></p>'];
}
panel_header($doc[0]);
?>
	<div class="card legal" style="max-width:820px;margin:0 auto">
		<h2 style="font-size:20px"><?= h($doc[0]) ?></h2>
		<?= $doc[1] ?>
		<p style="margin-top:20px"><a href="<?= panel_authed() ? 'index.php' : 'register.php' ?>">← Назад</a></p>
	</div>
<?php
panel_footer();
