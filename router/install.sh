#!/bin/sh
# Установка одной командой: podkop (если его нет) + сторож интернета + агент управления.
#
#   cd /tmp && { wget -4 -q -O tr-install.sh https://raw.githubusercontent.com/SmokePemoluxe/tim-routers/main/router/install.sh || wget -q -O tr-install.sh "АДРЕС-СЕРВЕРА/dl.php?f=install.sh"; } && sh tr-install.sh АДРЕС-СЕРВЕРА
#   (готовая команда с кодом — в кабинете или панели)
#
# АДРЕС-СЕРВЕРА выдаёт панель («Как подключить роутер»). Без него установщик спросит адрес сам.
# Флаги:
#   --no-podkop      не ставить podkop, даже если его нет
#   --update-podkop  обновить уже установленный podkop официальным установщиком
#   --ref ВЕРСИЯ     ветка или тег на GitHub (по умолчанию main)
#   --code КОД       одноразовый код приглашения из панели (нужен для нового роутера)

REPO="SmokePemoluxe/tim-routers"
REF="main"
SERVER=""
WITH_PODKOP=1
UPDATE_PODKOP=0
CODE=""
PODKOP_INSTALLER="https://raw.githubusercontent.com/itdoginfo/podkop/refs/heads/main/install.sh"

say() { printf '\n\033[1m%s\033[0m\n' "$*"; }

# Скачать: сначала по IPv4 (частая причина «Operation not permitted» — IPv6 без маршрута), потом как есть, потом curl
fetch_url() { # адрес файл
	wget -4 -q -O "$2" "$1" 2>/dev/null && [ -s "$2" ] && return 0
	wget -q -O "$2" "$1" 2>/dev/null && [ -s "$2" ] && return 0
	command -v curl >/dev/null 2>&1 && curl -4 -sfL -m 60 -o "$2" "$1" && [ -s "$2" ] && return 0
	rm -f "$2"
	return 1
}

# Наш скрипт: с GitHub, а если не вышло — через сервер (он берёт тот же файл с GitHub)
fetch_script() { # имя файл
	fetch_url "$RAW/$1" "$2" && return 0
	if [ -n "$SERVER" ] && fetch_url "$SERVER/dl.php?f=$1" "$2"; then
		echo "($1 — через сервер: GitHub с роутера недоступен)"
		return 0
	fi
	return 1
}

# Что мешает скачивать — чтобы было что прислать в поддержку
net_diag() {
	echo "--- Диагностика сети"
	echo "время роутера: $(date)"
	echo "маршрут по умолчанию: $(ip route show default 2>/dev/null | head -n 1)"
	echo "IPv6 по умолчанию: $(ip -6 route show default 2>/dev/null | head -n 1)"
	echo "DNS github: $(nslookup raw.githubusercontent.com 2>/dev/null | awk '/^Address/ {print $NF}' | tr '\n' ' ')"
	if ping -c 1 -W 3 8.8.8.8 >/dev/null 2>&1; then echo "пинг 8.8.8.8: есть"; else echo "пинг 8.8.8.8: НЕТ"; fi
	for p in banip adblock adblock-fast https-dns-proxy podkop; do
		[ -x "/etc/init.d/$p" ] && echo "установлен: $p (может ограничивать исходящие соединения)"
	done
	echo "правил drop/reject в исходящем фильтре: $(nft list chain inet fw4 output 2>/dev/null | grep -ciE 'drop|reject')"
}
die() { printf '\n\033[31mОшибка: %s\033[0m\n' "$*"; exit 1; }

while [ $# -gt 0 ]; do
	case "$1" in
		--no-podkop) WITH_PODKOP=0 ;;
		--update-podkop) UPDATE_PODKOP=1 ;;
		--ref) shift; REF=$1 ;;
		--code) shift; CODE=$1 ;;
		http://*|https://*) SERVER=${1%/} ;;
		*) die "непонятный параметр: $1" ;;
	esac
	shift
done
case "$REF" in
	''|*[!A-Za-z0-9._-]*) die "недопустимая версия: $REF" ;;
esac
case "$CODE" in
	*[!a-f0-9]*) die "недопустимый код приглашения: $CODE" ;;
esac
RAW="https://raw.githubusercontent.com/$REPO/$REF/router"

[ "$(id -u)" = "0" ] || die "запускайте от root"
[ -f /etc/openwrt_release ] || die "это не OpenWrt"
. /etc/openwrt_release
say "Роутер: $(ubus call system board 2>/dev/null | jsonfilter -e '@.model' 2>/dev/null), OpenWrt $DISTRIB_RELEASE"

# --- Проверка системы: до любых изменений. Не подходит — ничего не ставим. ---
# podkop + sing-box реально занимают около 20 МБ флеша (флеш у роутеров сжимающий; в распакованном виде sing-box — ~40 МБ)
# и требуют памяти: sing-box на роутере с 64 МБ не уживается.
say "Проверяю, подходит ли роутер"
check_fail=""
major=$(echo "$DISTRIB_RELEASE" | cut -d. -f1)
case "$major" in
	''|*[!0-9]*) [ "$DISTRIB_RELEASE" = "SNAPSHOT" ] || check_fail="$check_fail\n  - не удалось определить версию OpenWrt ($DISTRIB_RELEASE)" ;;
	*) [ "$major" -ge 23 ] || check_fail="$check_fail\n  - OpenWrt $DISTRIB_RELEASE слишком старый: нужен 23.05 или новее" ;;
esac
mem_mb=$(awk '/^MemTotal:/{print int($2/1024)}' /proc/meminfo)
[ "${mem_mb:-0}" -ge 120 ] || check_fail="$check_fail\n  - мало оперативной памяти: ${mem_mb} МБ, нужно от 128 МБ"
flash_kb=$(df -k /overlay 2>/dev/null | awk 'NR==2 {print $4}')
[ -n "$flash_kb" ] || flash_kb=$(df -k / | awk 'NR==2 {print $4}')
if [ -x /etc/init.d/podkop ] || [ "$WITH_PODKOP" = "0" ]; then
	need_kb=2048
else
	need_kb=24576
fi
[ "${flash_kb:-0}" -ge "$need_kb" ] || check_fail="$check_fail\n  - мало свободного места во флеш-памяти: $((${flash_kb:-0} / 1024)) МБ, нужно от $((need_kb / 1024)) МБ$([ "$need_kb" = 24576 ] && echo ' (podkop и sing-box занимают около 20 МБ; роутеры с флешем 16 МБ и меньше не подходят)')"
if [ -n "$check_fail" ]; then
	printf '\n\033[31m%s\033[0m' "Роутер не подходит — ничего не установлено:"
	printf "$check_fail\n"
	echo
	echo "Требования: OpenWrt 23.05 или новее, оперативная память от 128 МБ, свободно во флеше от 24 МБ (если podkop ещё не установлен)."
	exit 1
fi
echo "OK: OpenWrt $DISTRIB_RELEASE, память ${mem_mb} МБ, свободно во флеше $((flash_kb / 1024)) МБ"

if [ -z "$SERVER" ]; then
	printf 'Адрес сервера (в панели: «Как подключить роутер»): '
	read -r SERVER
	SERVER=${SERVER%/}
fi
case "$SERVER" in
	http://*|https://*) ;;
	*) die "адрес сервера должен начинаться с https://" ;;
esac
case "$SERVER" in
	*[\"\'\ \\]*) die "недопустимый адрес сервера" ;;
esac

# --- 0. Есть ли интернет ---
# Частая ловушка: podkop установлен, но не настроен (нет ключа) — sing-box не запущен,
# а DNS роутера всё равно направлен в него. Тогда пинг по IP проходит, а ни один сайт не открывается.
podkop_stopped=0
say "Проверяю интернет"
if ! ping -c 1 -W 3 8.8.8.8 >/dev/null 2>&1 && ! ping -c 1 -W 3 1.1.1.1 >/dev/null 2>&1; then
	die "у роутера нет интернета (не проходит даже пинг до 8.8.8.8) — проверьте подключение WAN"
fi
if ! nslookup raw.githubusercontent.com >/dev/null 2>&1; then
	if [ -x /etc/init.d/podkop ] && ! pidof sing-box >/dev/null; then
		echo "podkop установлен, но не работает (скорее всего, в секции нет ключа) и ломает DNS роутера."
		echo "Временно останавливаю podkop — после установки задайте ключ в панели, и он запустится."
		/etc/init.d/podkop stop >/dev/null 2>&1
		podkop_stopped=1
		sleep 3
	fi
	nslookup raw.githubusercontent.com >/dev/null 2>&1 ||
		die "не работает DNS роутера: пинг по IP есть, а адреса сайтов не узнаются. Проверьте DNS в LuCI (Сеть → DHCP и DNS)"
fi
echo "OK"

pkg_install() {
	if command -v apk >/dev/null 2>&1; then
		apk update >/dev/null 2>&1
		apk add "$@"
	else
		opkg update >/dev/null 2>&1
		opkg install "$@"
	fi
}

# --- 1. Пакеты ---
say "1/4 Проверяю нужные пакеты"
for p in curl jsonfilter; do
	if ! command -v "$p" >/dev/null 2>&1; then
		echo "ставлю $p…"
		pkg_install "$p" || die "не удалось установить $p — нет интернета или места?"
	fi
done
echo "OK"

# --- 2. podkop ---
say "2/4 podkop"
run_podkop_installer() {
	if ! fetch_url "$PODKOP_INSTALLER" /tmp/podkop-install.sh; then
		net_diag
		die "не удалось скачать установщик podkop с GitHub (podkop ставится только оттуда) — пришлите в поддержку текст выше"
	fi
	sh /tmp/podkop-install.sh
	rm -f /tmp/podkop-install.sh
	[ -x /etc/init.d/podkop ] || die "podkop не установился"
	# Совет разработчика podkop: после установки/обновления очистить кэш LuCI
	rm -rf /tmp/luci-*
}

# sing-box в распакованном виде ~40 МБ, и opkg сверяет свободное место именно с этим размером. Но флеш
# у роутеров (ubifs/jffs2) сжимает файлы, и реально нужно ~20 МБ — с 39 МБ свободных opkg отказывал зря.
# Поэтому sing-box ставим сами до официального установщика (он оставит уже стоящий свежий sing-box):
# обычной установкой, а если opkg отказал только из-за подсчёта места — без этой проверки, но лишь на
# сжимающем флеше и когда свободно не меньше 24 МБ. После установки должно остаться хотя бы 1 МБ.
preinstall_singbox() {
	command -v opkg >/dev/null 2>&1 || return 0
	opkg list-installed 2>/dev/null | grep -q '^sing-box ' && return 0
	opkg update >/dev/null 2>&1
	opkg install sing-box > /tmp/tr-singbox.log 2>&1 && { rm -f /tmp/tr-singbox.log; echo "sing-box установлен"; return 0; }
	if grep -q 'verify_pkg_installable' /tmp/tr-singbox.log; then
		fs=$(awk '$2 == "/overlay" { t = $3 } END { print t }' /proc/mounts)
		free_kb=$(df -k /overlay 2>/dev/null | awk 'NR==2 {print $4}')
		case "$fs" in
			ubifs|jffs2)
				if [ "${free_kb:-0}" -ge 24576 ]; then
					echo "opkg считает место по несжатому размеру sing-box — на сжимающем флеше ($fs) его хватает, ставлю без этой проверки"
					if opkg install --force-space sing-box >> /tmp/tr-singbox.log 2>&1 && sing-box version >/dev/null 2>&1; then
						left=$(df -k /overlay | awk 'NR==2 {print $4}')
						if [ "${left:-0}" -ge 1024 ]; then
							rm -f /tmp/tr-singbox.log
							echo "sing-box установлен, свободно во флеше $((left / 1024)) МБ"
							return 0
						fi
						echo "после установки во флеше почти не осталось места — убираю sing-box"
					fi
					opkg remove sing-box >/dev/null 2>&1
				fi
				;;
		esac
	fi
	tail -n 5 /tmp/tr-singbox.log
	rm -f /tmp/tr-singbox.log
	die "не удалось установить sing-box (нужен для podkop): не хватает места во флеше или нет доступа к пакетам OpenWrt"
}

podkop_fresh=0
if [ -x /etc/init.d/podkop ]; then
	old_ver=$(podkop show_version 2>/dev/null | head -n 1)
	if [ "$UPDATE_PODKOP" = "1" ]; then
		echo "сейчас: $old_ver — обновляю официальным установщиком (он может задать пару вопросов; настройки сохраняются)"
		run_podkop_installer
		echo "теперь: $(podkop show_version 2>/dev/null | head -n 1)"
	else
		echo "уже установлен: $old_ver (обновить: добавьте в конец команды --update-podkop)"
	fi
elif [ "$WITH_PODKOP" = "1" ]; then
	echo "podkop не найден — ставлю официальным установщиком (он может задать пару вопросов)"
	preinstall_singbox
	run_podkop_installer
	podkop_fresh=1
else
	echo "пропускаю (--no-podkop)"
fi

# DNS podkop: DoH 1.1.1.1 вместо стандартного Яндекса (с ним podkop работает хуже).
# Меняем только у свежего podkop или если стоят настройки по умолчанию — свои настройки не трогаем.
if [ -x /etc/init.d/podkop ]; then
	t=$(uci -q get podkop.settings.dns_type)
	s=$(uci -q get podkop.settings.dns_server)
	if [ "$podkop_fresh" = "1" ] || [ -z "$t" ] || { [ "$t" = "udp" ] && [ "$s" = "77.88.8.8" ]; }; then
		uci set podkop.settings=settings
		uci set podkop.settings.dns_type=doh
		uci set podkop.settings.dns_server=1.1.1.1
		uci set podkop.settings.bootstrap_dns_server=1.1.1.1
		uci commit podkop
		echo "DNS podkop: DoH 1.1.1.1"
		pidof sing-box >/dev/null && /etc/init.d/podkop reload >/dev/null 2>&1
	fi
fi

# --- 3. Скрипты ---
say "3/4 Скачиваю скрипты с GitHub ($REF)"
d=/tmp/tr-install
rm -rf "$d"
mkdir -p "$d"
for f in inet-watchdog.sh tim-agent.sh; do
	if ! fetch_script "$f" "$d/$f"; then
		net_diag
		die "не удалось скачать $f ни с GitHub, ни через сервер — пришлите в поддержку текст выше"
	fi
	if ! head -n 1 "$d/$f" | grep -q '^#!/bin/sh' || ! sh -n "$d/$f"; then
		die "$f скачался повреждённым — попробуйте ещё раз"
	fi
done
echo "OK"

# --- 4. Установка ---
say "4/4 Устанавливаю сторожа интернета и агента"
sh "$d/inet-watchdog.sh" install || die "сторож не установился"
echo
if [ -z "$CODE" ] && ! grep -qs "^TOKEN=\"[0-9a-f]" /etc/tim-agent/config; then
	echo "Внимание: команда без кода приглашения — сервер не примет новый роутер. Возьмите команду с кодом в панели."
fi
sh "$d/tim-agent.sh" install "$SERVER" "$CODE" || die "агент не установился"
rm -rf "$d" /tmp/tr-install.sh

say "Готово!"
echo "Через 1–3 минуты роутер появится в панели в «Ждут подтверждения» — дайте ему имя и примите."
echo "Ключ, домены и остальные настройки podkop — со страницы роутера в панели."
if [ "$podkop_stopped" = "1" ]; then
	printf '\n\033[33m%s\033[0m\n' "Важно: podkop сейчас остановлен. Сразу после подтверждения задайте ключ на странице роутера — агент запустит podkop."
fi
