#!/bin/sh
# Установка одной командой: podkop (если его нет) + сторож интернета + агент управления.
#
#   wget -O /tmp/tr-install.sh https://raw.githubusercontent.com/SmokePemoluxe/tim-routers/main/router/install.sh && sh /tmp/tr-install.sh АДРЕС-СЕРВЕРА
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
	wget -q -O /tmp/podkop-install.sh "$PODKOP_INSTALLER" || die "не удалось скачать установщик podkop с GitHub"
	sh /tmp/podkop-install.sh
	rm -f /tmp/podkop-install.sh
	[ -x /etc/init.d/podkop ] || die "podkop не установился"
	# Совет разработчика podkop: после установки/обновления очистить кэш LuCI
	rm -rf /tmp/luci-*
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
	wget -q -O "$d/$f" "$RAW/$f" || die "не удалось скачать $f"
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
