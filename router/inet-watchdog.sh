#!/bin/sh
# inet-watchdog — сторож интернета и роутера для OpenWrt (учитывает podkop).
#
# Каждые CHECK_INTERVAL секунд проверяет:
#   1. Связь — пинг хостов из HOSTS (достаточно ответа одного).
#   2. DNS — резолв доменов из DNS_DOMAINS через сам роутер. Podkop перехватывает DNS,
#      и если он завис, пинг по IP проходит, а сайты не открываются.
#   3. Память — если свободно меньше MEM_MIN_PERCENT, роутер скоро начнёт «тупить».
#   4. LuCI — отвечает ли веб-интерфейс (uhttpd).
# Действия по нарастающей:
#   нет связи   → перезапуск WAN и podkop  → перезагрузка через REBOOT_AFTER;
#   нет DNS     → перезапуск podkop/dnsmasq → перезагрузка через REBOOT_AFTER;
#   мало памяти → перезапуск podkop        → перезагрузка;
#   LuCI молчит → перезапуск uhttpd        → перезагрузка.
#
# podkop (если установлен):
#   - раз в PODKOP_CHECK_EVERY секунд — его собственная диагностика (sing-box, правила nft)
#     и проверка, что домен из секции прокси получает подменный адрес 198.18.x.x;
#     две неудачные проверки подряд → перезапуск podkop;
#   - после полного перезапуска podkop (сохранение в LuCI и т.п.) устройства успевают
#     получить «прямые» адреса и запомнить их. Поэтому, как только podkop снова заработал,
#     сторож на несколько секунд переподключает LAN-порты и Wi-Fi (CLIENT_RESET=1) —
#     устройства сбрасывают запомненные адреса, как после перезагрузки роутера.
#
# Защита от бесконечных перезагрузок: после MAX_QUICK_REBOOTS ребутов подряд
# (без появления интернета между ними) следующий только через REBOOT_AFTER_LONG.
#
# Страница в LuCI: Службы → Сторож интернета.
#
# Установка:   sh inet-watchdog.sh install
# Удаление:    inet-watchdog uninstall
# Состояние:   inet-watchdog status
# Проверка:    inet-watchdog test
# Отчёт:       inet-watchdog report > /tmp/report.txt
# Настройки:   /etc/inet-watchdog.conf  (после правки: inet-watchdog restart)

VERSION="3.2"
NAME="inet-watchdog"
BIN="/usr/bin/$NAME"
INIT="/etc/init.d/$NAME"
CONF="/etc/$NAME.conf"
STATE_DIR="/etc/$NAME"
REBOOTS_FILE="$STATE_DIR/reboots"
HISTORY_FILE="$STATE_DIR/history.log"
WD_REBOOT_FLAG="$STATE_DIR/rebooted_by_watchdog"
STATE_FILE="/tmp/$NAME.json"
BOOT_MARK="/tmp/$NAME.booted"
LAST_RESET_FILE="/tmp/$NAME.last_reset"
LUCI_VIEW="/www/luci-static/resources/view/$NAME.js"
LUCI_MENU="/usr/share/luci/menu.d/luci-app-$NAME.json"
LUCI_ACL="/usr/share/rpcd/acl.d/luci-app-$NAME.json"

# --- Настройки по умолчанию (переопределяются в /etc/inet-watchdog.conf) ---
HOSTS="1.1.1.1 8.8.8.8 77.88.8.8"
PING_TIMEOUT=3
DNS_DOMAINS="ya.ru google.com"
CHECK_INTERVAL=30
BOOT_DELAY=120
WAN_IFACES="wan"
FIX_AFTER=60
FIX_REPEAT=180
MEM_MIN_PERCENT=8
MEM_BAD_CHECKS=4
WEB_CHECK=1
WEB_BAD_CHECKS=4
ENABLE_REBOOT=1
REBOOT_AFTER=300
MAX_QUICK_REBOOTS=3
REBOOT_AFTER_LONG=1800
PODKOP_CHECK=1
PODKOP_CHECK_EVERY=180
CLIENT_RESET=1

load_conf() {
	[ -f "$CONF" ] && . "$CONF"
}

uptime_s() {
	cut -d. -f1 /proc/uptime
}

log() {
	logger -t "$NAME" "$*"
}

# Важные события пишем ещё и во флеш — они переживут перезагрузку
# (системный logread после ребута очищается).
log_history() {
	log "$*"
	mkdir -p "$STATE_DIR"
	echo "$(date '+%Y-%m-%d %H:%M:%S') [uptime $(uptime_s)s] $*" >> "$HISTORY_FILE"
	if [ "$(wc -l < "$HISTORY_FILE")" -gt 400 ]; then
		tail -n 250 "$HISTORY_FILE" > "$HISTORY_FILE.tmp" && mv "$HISTORY_FILE.tmp" "$HISTORY_FILE"
	fi
}

# Снимок состояния роутера в историю — чтобы потом понять, что его «положило».
log_diag() {
	mkdir -p "$STATE_DIR"
	{
		echo "    память: свободно $(mem_free_percent)% ($(mem_avail_mb) МБ), нагрузка: $(cut -d' ' -f1-3 /proc/loadavg)"
		for p in $(pidof sing-box); do
			awk '/^VmRSS:/{print "    sing-box занимает " int($2/1024) " МБ"}' "/proc/$p/status" 2>/dev/null
		done
		top -b -n 1 2>/dev/null | sed -n '4,10p' | sed 's/^/    /'
	} >> "$HISTORY_FILE"
}

get_reboots() {
	n=$(cat "$REBOOTS_FILE" 2>/dev/null)
	case "$n" in ''|*[!0-9]*) n=0 ;; esac
	echo "$n"
}

# Пишем во флеш только при изменении значения.
set_reboots() {
	[ "$(get_reboots)" = "$1" ] && return
	mkdir -p "$STATE_DIR"
	echo "$1" > "$REBOOTS_FILE"
}

# Запуск с ограничением по времени, если в системе есть timeout.
with_timeout() {
	t=$1
	shift
	if command -v timeout >/dev/null 2>&1; then
		timeout "$t" "$@"
	else
		"$@"
	fi
}

check_web() {
	with_timeout 10 wget -q -T 8 --no-check-certificate -O /dev/null http://127.0.0.1/ >/dev/null 2>&1
}

mem_free_percent() {
	awk '/^MemTotal:/{t=$2} /^MemAvailable:/{a=$2} END{if (t > 0) print int(a * 100 / t); else print 100}' /proc/meminfo
}

mem_avail_mb() {
	awk '/^MemAvailable:/{print int($2 / 1024)}' /proc/meminfo
}

has_podkop() {
	[ -x /etc/init.d/podkop ] && /etc/init.d/podkop enabled
}

podkop_state() {
	if ! has_podkop; then
		echo off
	elif pidof sing-box >/dev/null; then
		echo running
	else
		echo stopped
	fi
}

# Все проверки разом, параллельно (чтобы уложиться в ~10 с даже без интернета).
# Результат: ping_ok, dns_ok, web (ok|fail|off), mem, ping_json, dns_json и файлы в $check_dir.
collect() {
	check_dir="/tmp/$NAME.check.$$"
	rm -rf "$check_dir"
	mkdir -p "$check_dir"
	for h in $HOSTS; do
		( ping -c 1 -W "$PING_TIMEOUT" "$h" >/dev/null 2>&1 && touch "$check_dir/p_$h" ) &
	done
	for d in $DNS_DOMAINS; do
		( with_timeout 8 nslookup "$d" 127.0.0.1 2>/dev/null | grep -q '^Name:' && touch "$check_dir/d_$d" ) &
	done
	[ "$WEB_CHECK" = "1" ] && ( check_web && touch "$check_dir/web" ) &
	wait

	ping_ok=0
	ping_json=""
	for h in $HOSTS; do
		if [ -e "$check_dir/p_$h" ]; then r=true; ping_ok=1; else r=false; fi
		ping_json="$ping_json${ping_json:+,}\"$h\":$r"
	done

	dns_ok=0
	dns_json=""
	[ -z "$DNS_DOMAINS" ] && dns_ok=1
	for d in $DNS_DOMAINS; do
		if [ -e "$check_dir/d_$d" ]; then r=true; dns_ok=1; else r=false; fi
		dns_json="$dns_json${dns_json:+,}\"$d\":$r"
	done

	if [ "$WEB_CHECK" != "1" ]; then
		web=off
	elif [ -e "$check_dir/web" ]; then
		web=ok
	else
		web=fail
	fi

	mem=$(mem_free_percent)
}

# Состояние для страницы LuCI: $1 — фаза (boot_wait|ok|problem), $2 — проблема, $3 — сколько длится.
state_json() {
	echo "{\"version\":\"$VERSION\",\"time\":\"$(date '+%Y-%m-%d %H:%M:%S')\",\"uptime\":$(uptime_s),\"phase\":\"$1\",\"problem\":\"$2\",\"problem_for\":${3:-0},\"ping\":{$ping_json},\"dns\":{$dns_json},\"web\":\"$web\",\"mem_percent\":$mem,\"mem_mb\":$(mem_avail_mb),\"load\":\"$(cut -d' ' -f1-3 /proc/loadavg)\",\"podkop\":\"$(podkop_state)\",\"reboots_row\":$(get_reboots)}"
}

write_state() {
	state_json "$@" > "$STATE_FILE.tmp" && mv "$STATE_FILE.tmp" "$STATE_FILE"
}

restart_podkop() {
	log_history "перезапускаю podkop"
	with_timeout 120 /etc/init.d/podkop restart >/dev/null 2>&1
}

restart_wan() {
	for i in $WAN_IFACES; do
		ifup "$i"
	done
	# podkop, запущенный без интернета, часто остаётся нерабочим — перезапускаем после WAN
	if has_podkop; then
		sleep 20
		restart_podkop
	fi
}

restart_dns() {
	if has_podkop; then
		restart_podkop
	else
		/etc/init.d/dnsmasq restart
	fi
}

# ---------- podkop ----------

# Домен для проверки подмены DNS: первый свой домен первой секции «через прокси»,
# а если своих доменов нет — известный домен из её готовых списков.
podkop_test_domain() {
	for s in $(uci -q show podkop | sed -n 's/^podkop\.\([A-Za-z0-9_]*\)=section$/\1/p'); do
		[ "$(uci -q get "podkop.$s.connection_type")" = "proxy" ] || continue
		case "$(uci -q get "podkop.$s.user_domain_list_type")" in
			text) d=$(uci -q get "podkop.$s.user_domains_text" | awk 'NF && $1 !~ /^(\/\/|#)/ { print $1; exit }') ;;
			dynamic) d=$(uci -q get "podkop.$s.user_domains" | awk '{ print $1 }') ;;
			*) d="" ;;
		esac
		if [ -z "$d" ]; then
			for l in $(uci -q get "podkop.$s.community_lists"); do
				case "$l" in
					telegram) d=telegram.org ;;
					youtube) d=youtube.com ;;
					discord) d=discord.com ;;
					twitter) d=twitter.com ;;
					tiktok) d=tiktok.com ;;
					meta|russia_inside|russia_outside|block) d=instagram.com ;;
					google_ai) d=gemini.google.com ;;
				esac
				[ -n "$d" ] && break
			done
		fi
		[ -n "$d" ] && { echo "$d"; return; }
	done
}

# Пусто — podkop работает; иначе — описание проблемы.
# Использует встроенную диагностику podkop + проверку подменного адреса.
podkop_problem() {
	st=$(podkop get_sing_box_status 2>/dev/null)
	[ "$(jsonfilter -s "$st" -e '@.running' 2>/dev/null)" = "1" ] || { echo "sing-box не запущен"; return; }
	nft=$(podkop check_nft_rules 2>/dev/null)
	[ "$(jsonfilter -s "$nft" -e '@.table_exist' 2>/dev/null)" = "1" ] || { echo "нет правил podkop в nftables"; return; }
	if [ "$(uci -q get podkop.settings.dont_touch_dhcp)" != "1" ]; then
		case " $(uci -q get dhcp.@dnsmasq[0].server) " in
			*" 127.0.0.42 "*) ;;
			*) echo "DNS роутера не направлен в podkop"; return ;;
		esac
	fi
	d=$(podkop_test_domain)
	[ -n "$d" ] || return
	ip=$(with_timeout 10 nslookup "$d" 127.0.0.1 2>/dev/null | sed -n '/^Name/,$p' | awk '/^Address/ { print $2; exit }')
	case "$ip" in
		198.18.*|198.19.*) ;;
		*) echo "домен $d получает прямой адрес (${ip:-нет ответа}) вместо подменного" ;;
	esac
}

dnsmasq_pids() {
	pidof dnsmasq | tr ' ' '\n' | sort -n | tr '\n' ' '
}

# Переподключение устройств: LAN-порты и Wi-Fi гаснут на несколько секунд.
# Устройства видят «отключение от сети» и сбрасывают запомненные DNS-ответы и соединения —
# так же, как после перезагрузки роутера, но за секунды.
client_reset() { # $1 — причина (для истории)
	ports=""
	for p in $(ls /sys/class/net/br-lan/brif/ 2>/dev/null); do
		[ -d "/sys/class/net/$p/wireless" ] && continue
		[ -e "/sys/class/net/$p/phy80211" ] && continue
		case "$p" in wlan*|phy*|ra*|rai*|apcli*|wl*) continue ;; esac
		ports="$ports $p"
	done
	log_history "переподключаю устройства (LAN:${ports:- нет}, Wi-Fi) ${1:-после перезапуска podkop}, чтобы они забыли старые адреса"
	for p in $ports; do
		ip link set "$p" down
	done
	wifi down >/dev/null 2>&1
	sleep 4
	for p in $ports; do
		ip link set "$p" up
	done
	wifi up >/dev/null 2>&1
	uptime_s > "$LAST_RESET_FILE"
}

# Недавно уже переподключали (за последние 2 мин)? Чтобы не моргать сетью дважды подряд.
recently_reset() {
	t=$(cat "$LAST_RESET_FILE" 2>/dev/null)
	case "$t" in ''|*[!0-9]*) return 1 ;; esac
	[ $(($(uptime_s) - t)) -lt 120 ]
}

# Команда для агента: после изменений podkop дождаться, пока он заработает, и переподключить устройства.
do_reset_clients() {
	load_conf
	if [ "$CLIENT_RESET" != "1" ]; then
		echo "переподключение устройств выключено (CLIENT_RESET=0)"
		return
	fi
	if has_podkop; then
		i=0
		pp=$(podkop_problem)
		while [ -n "$pp" ] && [ $i -lt 9 ]; do
			sleep 10
			pp=$(podkop_problem)
			i=$((i + 1))
		done
		[ -n "$pp" ] && log_history "podkop не заработал после изменения настроек: $pp"
	fi
	client_reset "${1:-после изменения настроек}"
	echo "устройства переподключены"
}

reboot_limit() {
	if [ "$(get_reboots)" -ge "$MAX_QUICK_REBOOTS" ]; then
		echo "$REBOOT_AFTER_LONG"
	else
		echo "$REBOOT_AFTER"
	fi
}

do_reboot() {
	[ "$ENABLE_REBOOT" = "1" ] || return
	n=$(($(get_reboots) + 1))
	set_reboots "$n"
	log_history "ПЕРЕЗАГРУЗКА: $1 (подряд: $n)"
	log_diag
	touch "$WD_REBOOT_FLAG"
	sync
	reboot
	sleep 120
}

# Запись о загрузке роутера — один раз за загрузку (метка в /tmp пропадает при ребуте).
log_boot() {
	[ -f "$BOOT_MARK" ] && return
	touch "$BOOT_MARK"
	if [ -f "$WD_REBOOT_FLAG" ]; then
		rm -f "$WD_REBOOT_FLAG"
		[ "$(uptime_s)" -lt 600 ] && log_history "ЗАГРУЗКА: после перезагрузки сторожем"
	elif [ "$(uptime_s)" -lt 600 ]; then
		log_history "ЗАГРУЗКА: включение питания или ручная перезагрузка"
	fi
}

run() {
	load_conf
	log_boot
	log "запущен v$VERSION; ожидание после старта ${BOOT_DELAY}с"

	ping_json=""
	dns_json=""
	web=off
	mem=$(mem_free_percent)
	write_state boot_wait "" 0
	sleep "$BOOT_DELAY"

	# Если LuCI не отвечает уже на старте — значит, он так настроен (или не установлен),
	# и проверять его бессмысленно: иначе получим ложные перезагрузки.
	if [ "$WEB_CHECK" = "1" ] && ! check_web; then
		log_history "LuCI не отвечает на http://127.0.0.1/ при старте — проверка LuCI отключена"
		WEB_CHECK=0
	fi

	problem_since=0
	last_fix=0
	mem_bad=0
	web_bad=0
	last_dpids=""
	reset_pending=0
	reset_quiet_until=0
	last_pcheck=0
	podkop_bad=0

	while true; do
		collect
		now=$(uptime_s)

		# --- Интернет и DNS ---
		problem=""
		if [ "$ping_ok" != "1" ]; then
			problem="нет связи"
		elif [ "$dns_ok" != "1" ]; then
			problem="не работает DNS"
		fi

		if [ -z "$problem" ]; then
			if [ "$problem_since" -ne 0 ]; then
				log_history "интернет восстановлен (не было $((now - problem_since))с)"
				problem_since=0
				last_fix=0
			fi
			set_reboots 0
			write_state ok "" 0
		else
			if [ "$problem_since" -eq 0 ]; then
				problem_since=$now
				log_history "проблема: $problem"
				log_diag
			fi
			down=$((now - problem_since))
			write_state problem "$problem" "$down"

			if [ "$down" -ge "$FIX_AFTER" ] && { [ "$last_fix" -eq 0 ] || [ $((now - last_fix)) -ge "$FIX_REPEAT" ]; }; then
				if [ "$problem" = "нет связи" ]; then
					log_history "$problem ${down}с — перезапускаю WAN ($WAN_IFACES)"
					restart_wan
				else
					log_history "$problem ${down}с — перезапускаю DNS"
					restart_dns
				fi
				last_fix=$(uptime_s)
			fi

			[ "$down" -ge "$(reboot_limit)" ] && do_reboot "$problem ${down}с"
		fi

		# --- Память ---
		if [ "$mem" -lt "$MEM_MIN_PERCENT" ]; then
			mem_bad=$((mem_bad + 1))
			if [ "$mem_bad" -eq "$MEM_BAD_CHECKS" ]; then
				log_history "мало памяти: свободно ${mem}%"
				log_diag
				has_podkop && restart_podkop
			elif [ "$mem_bad" -ge $((MEM_BAD_CHECKS * 2)) ]; then
				do_reboot "мало памяти: свободно ${mem}%"
			fi
		else
			mem_bad=0
		fi

		# --- LuCI ---
		if [ "$web" = "ok" ]; then
			[ "$web_bad" -ge "$WEB_BAD_CHECKS" ] && log_history "LuCI снова отвечает"
			web_bad=0
		elif [ "$web" = "fail" ]; then
			web_bad=$((web_bad + 1))
			if [ "$web_bad" -eq "$WEB_BAD_CHECKS" ]; then
				log_history "LuCI не отвечает — перезапускаю uhttpd"
				log_diag
				/etc/init.d/uhttpd restart
			elif [ "$web_bad" -ge $((WEB_BAD_CHECKS * 2)) ]; then
				do_reboot "LuCI не отвечает"
			fi
		fi

		# --- podkop ---
		if [ "$PODKOP_CHECK" = "1" ] && has_podkop; then
			now=$(uptime_s)

			# Полный перезапуск podkop (сохранение в LuCI, restart) перезапускает dnsmasq.
			# Мягкий reload (так применяет изменения агент) dnsmasq не трогает.
			dpids=$(dnsmasq_pids)
			if [ -n "$last_dpids" ] && [ "$dpids" != "$last_dpids" ] && [ "$now" -ge "$reset_quiet_until" ] && [ "$reset_pending" -eq 0 ] && ! recently_reset; then
				reset_pending=$now
				log_history "podkop перезапущен — когда заработает, переподключу устройства"
			fi
			last_dpids=$dpids

			if [ "$reset_pending" -ne 0 ]; then
				pp=$(podkop_problem)
				if [ -z "$pp" ]; then
					[ "$CLIENT_RESET" = "1" ] && ! recently_reset && client_reset
					reset_pending=0
					reset_quiet_until=$(($(uptime_s) + 90))
					last_dpids=$(dnsmasq_pids)
					last_pcheck=$(uptime_s)
				elif [ $((now - reset_pending)) -ge 300 ]; then
					log_history "podkop не заработал за 5 мин после перезапуска: $pp"
					reset_pending=0
				fi
			elif [ $((now - last_pcheck)) -ge "$PODKOP_CHECK_EVERY" ]; then
				last_pcheck=$now
				pp=$(podkop_problem)
				if [ -z "$pp" ]; then
					[ "$podkop_bad" -ge 2 ] && log_history "podkop снова работает"
					podkop_bad=0
				else
					podkop_bad=$((podkop_bad + 1))
					[ "$podkop_bad" -eq 1 ] && log "проверка podkop: $pp"
					if [ "$podkop_bad" -eq 2 ]; then
						log_history "podkop не работает: $pp — перезапускаю"
						log_diag
						restart_podkop
					fi
				fi
			fi
		fi

		sleep "$CHECK_INTERVAL"
	done
}

write_conf() {
	cat > "$CONF" <<EOF
# Настройки $NAME. После правки: $NAME restart
# (или правьте на странице LuCI: Службы → Сторож интернета)

# Хосты для пинга (IP). Связь есть, если ответил ХОТЯ БЫ ОДИН.
HOSTS="$HOSTS"
# Таймаут одного пинга, сек
PING_TIMEOUT=$PING_TIMEOUT
# Домены для проверки DNS роутера. Пусто — не проверять DNS.
DNS_DOMAINS="$DNS_DOMAINS"
# Интервал проверок, сек
CHECK_INTERVAL=$CHECK_INTERVAL
# Пауза после загрузки роутера перед первой проверкой, сек
BOOT_DELAY=$BOOT_DELAY

# Интерфейсы WAN для перезапуска (через пробел, например "wan wan6")
WAN_IFACES="$WAN_IFACES"
# Через сколько секунд проблемы перезапускать WAN / podkop
FIX_AFTER=$FIX_AFTER
# Повторять перезапуск каждые N секунд, пока проблема не ушла
FIX_REPEAT=$FIX_REPEAT

# Порог свободной памяти, %
MEM_MIN_PERCENT=$MEM_MIN_PERCENT
# Сколько проверок подряд памяти мало, чтобы перезапустить podkop (вдвое больше — ребут)
MEM_BAD_CHECKS=$MEM_BAD_CHECKS

# 1 — следить за LuCI, 0 — нет
WEB_CHECK=$WEB_CHECK
# Сколько проверок подряд LuCI молчит, чтобы перезапустить uhttpd (вдвое больше — ребут)
WEB_BAD_CHECKS=$WEB_BAD_CHECKS

# 1 — разрешить перезагрузку роутера, 0 — только перезапуски служб
ENABLE_REBOOT=$ENABLE_REBOOT
# Через сколько секунд без интернета перезагружать роутер
REBOOT_AFTER=$REBOOT_AFTER
# Сколько перезагрузок подряд допустимо без появления интернета
MAX_QUICK_REBOOTS=$MAX_QUICK_REBOOTS
# После этого — перезагружать не раньше, чем через столько секунд без интернета
REBOOT_AFTER_LONG=$REBOOT_AFTER_LONG

# 1 — проверять podkop (его диагностика + подменный адрес для домена из секции прокси)
PODKOP_CHECK=$PODKOP_CHECK
# Как часто проверять podkop, сек
PODKOP_CHECK_EVERY=$PODKOP_CHECK_EVERY
# 1 — после перезапуска podkop переподключать устройства (LAN и Wi-Fi гаснут на ~10 с),
# чтобы они забыли «прямые» адреса, полученные во время перезапуска
CLIENT_RESET=$CLIENT_RESET
EOF
}

write_init() {
	cat > "$INIT" <<EOF
#!/bin/sh /etc/rc.common
# $NAME — сторож интернета

START=99
USE_PROCD=1

start_service() {
	procd_open_instance
	procd_set_param command $BIN run
	procd_set_param respawn 3600 5 0
	procd_close_instance
}
EOF
	chmod 755 "$INIT"
}

write_luci() {
	if [ ! -d /www/luci-static/resources ] || [ ! -d /usr/share/rpcd/acl.d ]; then
		echo "LuCI не найден — страница в веб-интерфейсе не установлена."
		return
	fi
	mkdir -p /www/luci-static/resources/view /usr/share/luci/menu.d

	cat > "$LUCI_MENU" <<EOF
{
	"admin/services/$NAME": {
		"title": "Сторож интернета",
		"order": 90,
		"action": { "type": "view", "path": "$NAME" },
		"depends": { "acl": [ "luci-app-$NAME" ] }
	}
}
EOF

	cat > "$LUCI_ACL" <<EOF
{
	"luci-app-$NAME": {
		"description": "Сторож интернета",
		"read": {
			"file": {
				"$STATE_FILE": [ "read" ],
				"$HISTORY_FILE": [ "read" ],
				"$CONF": [ "read" ],
				"$BIN json": [ "exec" ],
				"$BIN report": [ "exec" ]
			},
			"ubus": {
				"file": [ "read", "exec" ],
				"service": [ "list" ]
			}
		},
		"write": {
			"file": {
				"$CONF": [ "write" ],
				"$BIN restart": [ "exec" ],
				"$BIN clear": [ "exec" ]
			},
			"ubus": {
				"file": [ "write", "exec" ]
			}
		}
	}
}
EOF

	cat > "$LUCI_VIEW" <<'LUCI_JS_EOF'
'use strict';
'require view';
'require fs';
'require ui';
'require rpc';
'require poll';
'require dom';

var BIN = '/usr/bin/inet-watchdog',
	STATE = '/tmp/inet-watchdog.json',
	HISTORY = '/etc/inet-watchdog/history.log',
	CONF = '/etc/inet-watchdog.conf';

var SETTINGS = [
	{ key: 'HOSTS', title: 'Хосты для пинга', descr: 'IP-адреса через пробел. Связь есть, если ответил хотя бы один.' },
	{ key: 'DNS_DOMAINS', title: 'Домены для проверки DNS', descr: 'Через пробел. Пусто — DNS не проверять.' },
	{ key: 'CHECK_INTERVAL', title: 'Интервал проверок, с', num: true, min: 10 },
	{ key: 'BOOT_DELAY', title: 'Пауза после загрузки роутера, с', num: true },
	{ key: 'WAN_IFACES', title: 'Интерфейсы WAN', descr: 'Через пробел, например «wan wan6».' },
	{ key: 'FIX_AFTER', title: 'Перезапуск WAN / podkop через, с', num: true },
	{ key: 'FIX_REPEAT', title: 'Повторять перезапуск каждые, с', num: true, min: 30 },
	{ key: 'MEM_MIN_PERCENT', title: 'Порог свободной памяти, %', num: true },
	{ key: 'MEM_BAD_CHECKS', title: 'Проверок с нехваткой памяти до перезапуска podkop', descr: 'Вдвое больше — перезагрузка роутера.', num: true, min: 1 },
	{ key: 'WEB_CHECK', title: 'Следить за LuCI', bool: true },
	{ key: 'WEB_BAD_CHECKS', title: 'Проверок без ответа LuCI до перезапуска uhttpd', descr: 'Вдвое больше — перезагрузка роутера.', num: true, min: 1 },
	{ key: 'ENABLE_REBOOT', title: 'Разрешить перезагрузку роутера', descr: 'Если выключить — только перезапуск служб.', bool: true },
	{ key: 'REBOOT_AFTER', title: 'Перезагрузка, если интернета нет, с', num: true, min: 60 },
	{ key: 'MAX_QUICK_REBOOTS', title: 'Перезагрузок подряд до замедления', num: true, min: 1 },
	{ key: 'REBOOT_AFTER_LONG', title: 'После этого перезагрузка не раньше чем через, с', num: true, min: 60 },
	{ key: 'PODKOP_CHECK', title: 'Проверять podkop', descr: 'Встроенная диагностика podkop и проверка, что домены из секции прокси получают подменный адрес. Две неудачи подряд — перезапуск podkop.', bool: true },
	{ key: 'PODKOP_CHECK_EVERY', title: 'Проверять podkop каждые, с', num: true, min: 60 },
	{ key: 'CLIENT_RESET', title: 'Переподключать устройства после перезапуска podkop', descr: 'LAN и Wi-Fi гаснут на ~10 с, и устройства забывают «прямые» адреса, полученные во время перезапуска. Иначе сайты могут идти мимо прокси, пока не перезагрузишь роутер.', bool: true }
];

var COLORS = { ok: '#2e7d32', bad: '#c62828', warn: '#ef6c00', info: '#1565c0', muted: '#888' };

var callServiceList = rpc.declare({
	object: 'service',
	method: 'list',
	params: [ 'name' ],
	expect: { '': {} }
});

function isRunning() {
	return L.resolveDefault(callServiceList('inet-watchdog'), {}).then(function(res) {
		var inst = (res['inet-watchdog'] || {}).instances || {};
		for (var k in inst)
			if (inst[k].running)
				return true;
		return false;
	});
}

function readState() {
	return L.resolveDefault(fs.read(STATE), null).then(function(s) {
		try { return s ? JSON.parse(s) : null; } catch (e) { return null; }
	});
}

function readHistory() {
	return L.resolveDefault(fs.read(HISTORY), '');
}

function notify(text, kind) {
	ui.addNotification(null, E('p', {}, [ text ]), kind || 'info');
}

function parseHistory(text) {
	var events = [];
	(text || '').split('\n').forEach(function(line) {
		var m = line.match(/^(\S+ \S+) \[uptime (\d+)s\] (.*)$/);
		if (m)
			events.push({ time: m[1], uptime: +m[2], text: m[3], diag: [] });
		else if (line.trim() && events.length)
			events[events.length - 1].diag.push(line.replace(/^    /, ''));
	});
	return events;
}

function fmtDuration(s) {
	s = +s || 0;
	var d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60);
	if (d) return d + ' д ' + h + ' ч';
	if (h) return h + ' ч ' + m + ' мин';
	if (m) return m + ' мин ' + (s % 60) + ' с';
	return s + ' с';
}

function badge(text, kind) {
	return E('span', {
		style: 'display:inline-block;padding:1px 8px;margin:1px 4px 1px 0;border-radius:10px;color:#fff;background:' + COLORS[kind]
	}, [ text ]);
}

function cell(c) {
	return Array.isArray(c) ? c : [ c ];
}

function row(title, value) {
	return E('tr', { 'class': 'tr' }, [
		E('td', { 'class': 'td left', style: 'width:35%' }, [ E('strong', {}, [ title ]) ]),
		E('td', { 'class': 'td left' }, cell(value))
	]);
}

function table(head, rows) {
	return E('table', { 'class': 'table' }, [
		E('tr', { 'class': 'tr table-titles' }, head.map(function(h) {
			return E('th', { 'class': 'th left' }, [ h ]);
		}))
	].concat(rows.map(function(r) {
		return E('tr', { 'class': 'tr' }, r.map(function(c) {
			return E('td', { 'class': 'td left' }, cell(c));
		}));
	})));
}

function eventKind(text) {
	if (/^ПЕРЕЗАГРУЗКА/.test(text)) return 'bad';
	if (/^ЗАГРУЗКА/.test(text)) return 'info';
	if (/восстановлен|снова отвечает/.test(text)) return 'ok';
	return 'warn';
}

function renderStatus(running, st) {
	var rows = [ row('Служба сторожа', running ? badge('работает', 'ok') : badge('НЕ ЗАПУЩЕНА', 'bad')) ];

	if (!st) {
		rows.push(row('Состояние', 'нет данных — служба ещё не сделала ни одной проверки'));
		return E('table', { 'class': 'table' }, rows);
	}

	var phase;
	if (st.phase == 'boot_wait')
		phase = badge('ожидание после загрузки роутера', 'info');
	else if (st.phase == 'ok')
		phase = badge('всё в порядке', 'ok');
	else
		phase = [ badge('ПРОБЛЕМА', 'bad'), st.problem + (st.problem_for ? ' — уже ' + fmtDuration(st.problem_for) : '') ];

	var checks = function(obj) {
		var keys = Object.keys(obj || {});
		if (!keys.length)
			return '—';
		return keys.map(function(k) { return badge(k, obj[k] ? 'ok' : 'bad'); });
	};

	var web = {
		ok: badge('отвечает', 'ok'),
		fail: badge('НЕ ОТВЕЧАЕТ', 'bad'),
		off: badge('не проверяется', 'muted')
	}[st.web] || '—';

	var podkop = {
		running: badge('работает', 'ok'),
		stopped: badge('sing-box НЕ ЗАПУЩЕН', 'bad'),
		off: badge('не установлен или выключен', 'muted')
	}[st.podkop] || '—';

	rows.push(
		row('Интернет', phase),
		row('Пинг', checks(st.ping)),
		row('DNS роутера', checks(st.dns)),
		row('LuCI', web),
		row('Свободная память', badge(st.mem_percent + '% (' + st.mem_mb + ' МБ)', st.mem_percent < 15 ? 'warn' : 'ok')),
		row('Нагрузка (1 / 5 / 15 мин)', st.load),
		row('podkop', podkop),
		row('Роутер работает без перезагрузки', fmtDuration(st.uptime)),
		row('Перезагрузок сторожем подряд', String(st.reboots_row)),
		row('Последняя проверка', st.time + (st.live ? ' (ручная)' : ''))
	);
	return E('table', { 'class': 'table' }, rows);
}

function renderBoots(events) {
	var boots = [], lastReboot = null;
	events.forEach(function(ev) {
		if (/^ПЕРЕЗАГРУЗКА/.test(ev.text)) {
			lastReboot = ev;
		}
		else if (/^ЗАГРУЗКА/.test(ev.text)) {
			var wd = /сторожем/.test(ev.text);
			boots.push({
				time: wd && lastReboot ? lastReboot.time : ev.time,
				wd: wd,
				reason: wd && lastReboot ? lastReboot.text.replace(/^ПЕРЕЗАГРУЗКА: /, '') : null
			});
			lastReboot = null;
		}
	});

	if (!boots.length)
		return E('p', {}, [ 'Загрузок пока не записано.' ]);

	var byWd = boots.filter(function(b) { return b.wd; }).length;
	return E('div', {}, [
		E('p', {}, [ 'Всего записано: ' + boots.length + ', из них сторожем: ' + byWd + ', из-за света или вручную: ' + (boots.length - byWd) + '.' ]),
		table([ 'Когда', 'Причина' ], boots.reverse().slice(0, 30).map(function(b) {
			return [ b.time, b.wd
				? [ badge('сторож', 'bad'), b.reason || 'причина не записана' ]
				: [ badge('питание / вручную', 'info'), 'отключали свет или перезагрузили вручную' ] ];
		}))
	]);
}

function renderHistory(events) {
	if (!events.length)
		return E('p', {}, [ 'История пуста.' ]);

	return table([ 'Время', 'Uptime', 'Событие' ], events.slice().reverse().slice(0, 200).map(function(ev) {
		var c = [ E('span', { style: 'font-weight:bold;color:' + COLORS[eventKind(ev.text)] }, [ ev.text ]) ];
		if (ev.diag.length)
			c.push(E('details', {}, [
				E('summary', { style: 'cursor:pointer' }, [ 'состояние роутера в этот момент' ]),
				E('pre', { style: 'font-size:11px;white-space:pre-wrap' }, [ ev.diag.join('\n') ])
			]));
		return [ ev.time, fmtDuration(ev.uptime), c ];
	}));
}

function parseConf(text) {
	var v = {};
	(text || '').split('\n').forEach(function(l) {
		var m = l.match(/^([A-Z_]+)=(.*)$/);
		if (m)
			v[m[1]] = m[2].replace(/^"(.*)"$/, '$1');
	});
	return v;
}

function downloadReport() {
	return fs.exec(BIN, [ 'report' ]).then(function(res) {
		var blob = new Blob([ res.stdout || '' ], { type: 'text/plain;charset=utf-8' }),
			name = 'inet-watchdog-report-' + new Date().toISOString().slice(0, 16).replace(/[T:]/g, '-') + '.txt',
			a = E('a', { href: URL.createObjectURL(blob), download: name, style: 'display:none' });
		document.body.appendChild(a);
		a.click();
		setTimeout(function() { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
	}).catch(function(e) { notify('Не удалось сформировать отчёт: ' + e.message, 'danger'); });
}

return view.extend({
	load: function() {
		return Promise.all([ isRunning(), readState(), readHistory(), L.resolveDefault(fs.read(CONF), '') ]);
	},

	render: function(data) {
		var statusBox = E('div', {}, [ renderStatus(data[0], data[1]) ]),
			bootsBox = E('div', {}),
			histBox = E('div', {}),
			lastHistory = null,
			confText = data[3];

		var showHistory = function(text) {
			if (text === lastHistory)
				return;
			lastHistory = text;
			var ev = parseHistory(text);
			dom.content(bootsBox, renderBoots(ev));
			dom.content(histBox, renderHistory(ev));
		};
		showHistory(data[2]);

		var refresh = function() {
			return Promise.all([ isRunning(), readState(), readHistory() ]).then(function(r) {
				dom.content(statusBox, renderStatus(r[0], r[1]));
				showHistory(r[2]);
			});
		};
		poll.add(refresh, 10);

		var buttons = E('div', { style: 'margin:1em 0' }, [
			E('button', {
				'class': 'btn cbi-button cbi-button-action',
				click: ui.createHandlerFn(this, function() {
					return fs.exec(BIN, [ 'json' ]).then(function(res) {
						var st = JSON.parse(res.stdout);
						st.live = true;
						return isRunning().then(function(run) { dom.content(statusBox, renderStatus(run, st)); });
					}).catch(function(e) { notify('Проверка не удалась: ' + e.message, 'danger'); });
				})
			}, [ 'Проверить сейчас' ]), ' ',
			E('button', {
				'class': 'btn cbi-button cbi-button-apply',
				click: ui.createHandlerFn(this, downloadReport)
			}, [ 'Скачать отчёт' ]), ' ',
			E('button', {
				'class': 'btn cbi-button cbi-button-neutral',
				click: ui.createHandlerFn(this, function() {
					return fs.exec(BIN, [ 'restart' ]).then(function() {
						notify('Сторож перезапущен. Первая проверка — после паузы BOOT_DELAY.');
						return refresh();
					});
				})
			}, [ 'Перезапустить сторож' ]), ' ',
			E('button', {
				'class': 'btn cbi-button cbi-button-remove',
				click: ui.createHandlerFn(this, function() {
					if (!confirm('Очистить историю событий?'))
						return;
					return fs.exec(BIN, [ 'clear' ]).then(refresh);
				})
			}, [ 'Очистить историю' ])
		]);

		var vals = parseConf(confText), inputs = {};
		var fields = SETTINGS.map(function(s) {
			var inp;
			if (s.bool) {
				inp = E('input', { type: 'checkbox' });
				inp.checked = (vals[s.key] == '1');
			}
			else {
				inp = E('input', { type: 'text', 'class': 'cbi-input-text', style: 'width:100%;max-width:420px' });
				inp.value = vals[s.key] || '';
			}
			inputs[s.key] = inp;
			return E('div', { 'class': 'cbi-value' }, [
				E('label', { 'class': 'cbi-value-title' }, [ s.title ]),
				E('div', { 'class': 'cbi-value-field' }, [ inp ].concat(s.descr ? [ E('div', { 'class': 'cbi-value-description' }, [ s.descr ]) ] : []))
			]);
		});

		var save = function() {
			var newVals = {};
			for (var i = 0; i < SETTINGS.length; i++) {
				var s = SETTINGS[i], inp = inputs[s.key], v;
				if (s.bool)
					v = inp.checked ? '1' : '0';
				else
					v = inp.value.trim().replace(/\s+/g, ' ').replace(/["'`$\\;&|<>]/g, '');
				if (s.num && (!/^\d+$/.test(v) || (s.min && +v < s.min))) {
					notify('«' + s.title + '»: нужно целое число' + (s.min ? ' не меньше ' + s.min : ''), 'danger');
					return Promise.resolve();
				}
				newVals[s.key] = v;
			}

			var seen = {};
			var lines = (confText || '').split('\n').map(function(l) {
				var m = l.match(/^([A-Z_]+)=/);
				if (m && newVals.hasOwnProperty(m[1])) {
					seen[m[1]] = true;
					return m[1] + '="' + newVals[m[1]] + '"';
				}
				return l;
			});
			Object.keys(newVals).forEach(function(k) {
				if (!seen[k])
					lines.push(k + '="' + newVals[k] + '"');
			});
			var out = lines.join('\n').replace(/\n*$/, '\n');

			return fs.write(CONF, out).then(function() {
				confText = out;
				return fs.exec(BIN, [ 'restart' ]);
			}).then(function() {
				notify('Настройки сохранены, сторож перезапущен.');
			}).catch(function(e) {
				notify('Не удалось сохранить: ' + e.message, 'danger');
			});
		};

		return E('div', { 'class': 'cbi-map' }, [
			E('h2', {}, [ 'Сторож интернета' ]),
			E('div', { 'class': 'cbi-map-descr' }, [
				'Следит за интернетом, DNS (podkop), памятью и веб-интерфейсом. При сбое перезапускает WAN, podkop или uhttpd, а если не помогает — перезагружает роутер. ',
				'Если что-то непонятно — нажмите «Скачать отчёт» и отправьте файл.'
			]),
			E('div', { 'class': 'cbi-section' }, [ E('h3', {}, [ 'Сейчас' ]), statusBox, buttons ]),
			E('div', { 'class': 'cbi-section' }, [
				E('h3', {}, [ 'Загрузки роутера' ]),
				E('div', { 'class': 'cbi-section-descr' }, [
					'Время загрузки после отключения света может быть неточным: у роутера нет часов с батарейкой, точное время он узнаёт из интернета.'
				]),
				bootsBox
			]),
			E('div', { 'class': 'cbi-section' }, [ E('h3', {}, [ 'История событий' ]), histBox ]),
			E('div', { 'class': 'cbi-section' }, [
				E('h3', {}, [ 'Настройки' ]),
				E('div', {}, fields),
				E('div', { 'class': 'cbi-page-actions' }, [
					E('button', { 'class': 'btn cbi-button cbi-button-save', click: ui.createHandlerFn(this, save) }, [ 'Сохранить и перезапустить сторож' ])
				])
			])
		]);
	},

	handleSaveApply: null,
	handleSave: null,
	handleReset: null
});
LUCI_JS_EOF

	rm -f /tmp/luci-indexcache* 2>/dev/null
	rm -rf /tmp/luci-modulecache 2>/dev/null
	/etc/init.d/rpcd reload >/dev/null 2>&1
	echo "Страница LuCI: Службы → Сторож интернета (если её не видно — выйдите из LuCI и зайдите снова)."
}

do_install() {
	[ "$(id -u)" = "0" ] || { echo "Нужно запускать от root"; exit 1; }
	[ -f /etc/openwrt_release ] || echo "Внимание: это не похоже на OpenWrt"

	tmp="/tmp/$NAME.new"
	tr -d '\r' < "$0" > "$tmp" || { echo "Не удалось прочитать $0"; exit 1; }
	[ -f "$INIT" ] && "$INIT" stop 2>/dev/null
	mv "$tmp" "$BIN"
	chmod 755 "$BIN"

	write_init
	if [ -f "$CONF" ]; then
		echo "Настройки сохранены: $CONF"
		# Новые параметры, которых не было в старой версии, — со значениями по умолчанию
		for k in HOSTS PING_TIMEOUT DNS_DOMAINS CHECK_INTERVAL BOOT_DELAY WAN_IFACES FIX_AFTER FIX_REPEAT \
			MEM_MIN_PERCENT MEM_BAD_CHECKS WEB_CHECK WEB_BAD_CHECKS ENABLE_REBOOT REBOOT_AFTER \
			MAX_QUICK_REBOOTS REBOOT_AFTER_LONG PODKOP_CHECK PODKOP_CHECK_EVERY CLIENT_RESET; do
			grep -q "^$k=" "$CONF" || eval "echo \"$k=\\\"\$$k\\\"\"" >> "$CONF"
		done
	else
		write_conf
		echo "Создан файл настроек: $CONF"
	fi
	write_luci

	# Чтобы сторож пережил обновление прошивки с сохранением настроек
	for f in "$BIN" "$INIT" "$CONF" "$STATE_DIR/" "$LUCI_VIEW" "$LUCI_MENU" "$LUCI_ACL"; do
		grep -qxF "$f" /etc/sysupgrade.conf 2>/dev/null || echo "$f" >> /etc/sysupgrade.conf
	done

	# Установка на работающем роутере — это не загрузка, не пишем её в историю
	touch "$BOOT_MARK"
	"$INIT" enable
	"$INIT" start
	echo "$NAME v$VERSION установлен и запущен."
	has_podkop && echo "Найден podkop — будет перезапускаться при проблемах с DNS и памятью."
	echo
	do_test
}

do_uninstall() {
	[ -f "$INIT" ] && { "$INIT" stop; "$INIT" disable; }
	rm -f "$INIT" "$BIN" "$CONF" "$STATE_FILE" "$BOOT_MARK" "$LUCI_VIEW" "$LUCI_MENU" "$LUCI_ACL"
	rm -rf "$STATE_DIR"
	sed -i "\#$NAME#d" /etc/sysupgrade.conf 2>/dev/null
	rm -f /tmp/luci-indexcache* 2>/dev/null
	rm -rf /tmp/luci-modulecache 2>/dev/null
	/etc/init.d/rpcd reload >/dev/null 2>&1
	echo "$NAME удалён."
}

do_status() {
	load_conf
	if [ -x "$INIT" ]; then
		printf "Сервис: "
		"$INIT" status
	else
		echo "Сервис: не установлен"
	fi
	echo "Перезагрузок подряд: $(get_reboots)"
	echo "--- История (последние 40 строк) ---"
	tail -n 40 "$HISTORY_FILE" 2>/dev/null || echo "(пусто)"
}

do_test() {
	load_conf
	collect
	for h in $HOSTS; do
		if [ -e "$check_dir/p_$h" ]; then echo "OK    пинг $h"; else echo "FAIL  пинг $h"; fi
	done
	for d in $DNS_DOMAINS; do
		if [ -e "$check_dir/d_$d" ]; then echo "OK    DNS $d"; else echo "FAIL  DNS $d"; fi
	done
	case "$web" in
		ok)   echo "OK    LuCI" ;;
		fail) echo "FAIL  LuCI" ;;
	esac
	rm -rf "$check_dir"
	echo "      свободно памяти: $mem% (порог $MEM_MIN_PERCENT%)"
	case "$(podkop_state)" in
		running) echo "      podkop: включён, sing-box работает" ;;
		stopped) echo "      podkop: включён, sing-box НЕ ЗАПУЩЕН" ;;
		off)     echo "      podkop: не найден или выключен" ;;
	esac
}

do_json() {
	load_conf
	collect
	rm -rf "$check_dir"
	if [ "$ping_ok" != "1" ]; then
		state_json problem "нет связи" 0
	elif [ "$dns_ok" != "1" ]; then
		state_json problem "не работает DNS" 0
	else
		state_json ok "" 0
	fi
}

do_report() {
	load_conf
	echo "===== $NAME v$VERSION — отчёт от $(date '+%Y-%m-%d %H:%M:%S') ====="
	echo
	echo "===== Система ====="
	grep -E '^DISTRIB_(DESCRIPTION|TARGET)' /etc/openwrt_release 2>/dev/null
	ubus call system board 2>/dev/null | grep -E '"(model|kernel|board_name)"'
	echo "uptime: $(uptime)"
	echo
	echo "===== Проверка сейчас ====="
	do_test
	echo
	echo "===== Память ====="
	free
	echo
	echo "===== Процессы ====="
	top -b -n 1 2>/dev/null | head -n 25
	echo
	echo "===== WAN ====="
	for i in $WAN_IFACES; do
		echo "$i:"
		ifstatus "$i" 2>/dev/null | grep -E '"(up|pending|available|uptime|proto|l3_device)"'
	done
	echo
	echo "===== Настройки ====="
	grep -v -e '^#' -e '^$' "$CONF" 2>/dev/null
	echo
	echo "===== История ====="
	cat "$HISTORY_FILE" 2>/dev/null || echo "(пусто)"
	echo
	echo "===== Системный журнал: сторож, podkop, sing-box, сеть, память ====="
	logread 2>/dev/null | grep -iE "$NAME|podkop|sing-box|netifd|pppd|odhcp|out of memory|oom" | tail -n 300
	echo
	echo "===== dmesg (последние 60 строк) ====="
	dmesg 2>/dev/null | tail -n 60
}

case "$1" in
	install)   do_install ;;
	uninstall) do_uninstall ;;
	run)       run ;;
	status)    do_status ;;
	test)      do_test ;;
	json)      do_json ;;
	report)    do_report ;;
	restart)   "$INIT" restart ;;
	clear)     rm -f "$HISTORY_FILE"; echo "История очищена." ;;
	reset-clients) do_reset_clients "$2" ;;
	*)
		echo "$NAME v$VERSION"
		echo "Использование: $0 {install|uninstall|status|test|report|restart|clear}"
		exit 1
		;;
esac
