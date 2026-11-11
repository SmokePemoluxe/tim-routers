<?php
// Что нового в обновлении скриптов роутера. Пользователям — только текущее обновление,
// администратору — вся история.

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/panel.php';

panel_start();
panel_require();
panel_header('Что нового');
$latest = changelog_latest();
?>
	<div class="card">
		<h2>🆕 Что нового в обновлении скриптов роутера</h2>
		<p class="muted">Скрипты роутера — это агент (связь с сервисом и настройка podkop) и сторож интернета. Обновить их: на обзоре «⬆️ Обновить скрипты на всех» или на странице роутера «🛠 Управление» → «⬆️ Обновить скрипты». Настройки podkop при обновлении сохраняются.</p>
		<?= changelog_html($latest) ?>
	</div>
	<?php if (!is_service()): ?>
		<details class="card">
			<summary><h2 style="display:inline">📜 Прошлые обновления</h2> <span class="muted">— видно только в админке, пользователям показывается лишь текущее</span></summary>
			<?php foreach (array_slice(changelog(), 1) as $e): ?><?= changelog_html($e) ?><?php endforeach; ?>
		</details>
	<?php endif; ?>
<?php
panel_footer();
