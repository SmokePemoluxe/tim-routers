#!/bin/sh
# tim-agent — агент удалённого управления podkop для OpenWrt.
#
# Раз в INTERVAL секунд (по умолчанию 2 мин) выходит на связь с сервером:
#   - отправляет состояние роутера и каждой секции podkop (задержка через clash API sing-box);
#   - получает задачи: сменить ключ, добавить/убрать домены, готовые списки,
#     создать/удалить секцию, прислать отчёт, обновить скрипты с GitHub;
#   - выполняет их и сразу сообщает результат.
#
# Агент НЕ выполняет произвольные команды с сервера: только перечисленные задачи,
# и только с проверкой входных данных. Новый ключ проверяется; не заработал — возвращается старый.
#
# Установка:   sh tim-agent.sh install https://адрес-сервера  (адрес в скрипт не зашит — передаётся при установке)
# Удаление:    tim-agent uninstall
# Состояние:   tim-agent status
# Разовый выход на связь: tim-agent sync

VERSION="2.14"
NAME="tim-agent"
REPO_RAW="https://raw.githubusercontent.com/SmokePemoluxe/tim-routers"
PODKOP_INSTALLER="https://raw.githubusercontent.com/itdoginfo/podkop/refs/heads/main/install.sh"
# Зеркало разработчиков podkop (если GitHub недоступен); ставит podkop и движок podkop-engine.
# Скрипт зеркала отправляет разработчикам podkop телеметрию установки (модель, пакеты, память).
PODKOP_MIRROR="https://mirror.podkop.net/urjw/scripts/bootstrap.sh"

BIN="/usr/bin/$NAME"
INIT="/etc/init.d/$NAME"
CONF_DIR="/etc/$NAME"
CONF="$CONF_DIR/config"
RESULTS="$CONF_DIR/results"
EVENTS="$CONF_DIR/events"
RUN_DIR="/tmp/$NAME"
TEST_URL="https%3A%2F%2Fwww.gstatic.com%2Fgenerate_204"

SERVER=""
TOKEN=""
INTERVAL=120
QUICK_EVERY=20 # быстрая проверка подписок между выходами на связь, с
STATUS=""
TASKS_DONE=0
RESTART_FOR_UPDATE=0

log() {
	logger -t "$NAME" "$*"
	[ -t 1 ] && echo "$*"
	return 0
}

load_conf() {
	[ -f "$CONF" ] && . "$CONF"
}

save_conf() {
	mkdir -p "$CONF_DIR"
	(umask 077; printf 'SERVER="%s"\nTOKEN="%s"\nINVITE="%s"\n' "$SERVER" "$TOKEN" "$INVITE" > "$CONF")
}

# ---------- JSON ----------

# Строки из stdin → JSON-массив строк (пустые строки пропускаются)
json_list() {
	tr -d '\r' | tr '\t' ' ' | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' |
		awk 'BEGIN { printf "[" } NF { printf "%s\"%s\"", (n++ ? "," : ""), $0 } END { printf "]" }'
}

# Одна строка → JSON-строка (переводы строк заменяются на « / »)
json_str() {
	printf '"%s"' "$(printf '%s' "$1" | tr -d '\r' | tr '\t\n' '  ' | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g')"
}

json_num() {
	case "$1" in
		''|*[!0-9]*) printf 'null' ;;
		*) printf '%s' "$1" ;;
	esac
}

# ---------- Сеть ----------

# Адрес сервера в обход DNS роутера (для curl --resolve): если DNS сломан — например, podkop
# без ключа перехватил его, — спрашиваем публичные DNS напрямую. Иначе роутер пропал бы из панели
# как раз тогда, когда его нужно чинить удалённо.
server_resolve() {
	host=$(printf '%s' "$SERVER" | sed -E 's#^[a-z]+://##; s#[/:].*##')
	port=$(printf '%s' "$SERVER" | sed -nE 's#^[a-z]+://[^/:]+:([0-9]+).*#\1#p')
	if [ -z "$port" ]; then
		case "$SERVER" in https://*) port=443 ;; *) port=80 ;; esac
	fi
	for ns in 1.1.1.1 8.8.8.8 77.88.8.8; do
		ip=$(nslookup "$host" "$ns" 2>/dev/null | sed -n '/^Name/,$p' | awk '/^Address/ && $2 !~ /:/ { print $2; exit }')
		if [ -n "$ip" ]; then
			echo "$host:$port:$ip"
			return
		fi
	done
}

# curl к серверу; при ошибке «не удалось узнать адрес» (код 6) — повтор в обход DNS роутера
server_curl() {
	out=$(curl "$@")
	rc=$?
	if [ "$rc" = 6 ]; then
		r=$(server_resolve)
		if [ -n "$r" ]; then
			log "DNS роутера не работает — связываюсь с сервером через публичный DNS"
			out=$(curl --resolve "$r" "$@")
			rc=$?
		fi
	fi
	printf '%s' "$out"
	return "$rc"
}

http_post() { # $1 — действие API, $2 — файл с телом запроса
	server_curl -s -m 40 -X POST -H "X-Token: $TOKEN" -H 'Content-Type: application/json' \
		--data-binary "@$2" "$SERVER/api.php?a=$1"
}

clash_api_addr() {
	cfg=$(uci -q get podkop.settings.config_path)
	[ -n "$cfg" ] || cfg=/etc/sing-box/config.json
	CLASH_ADDR=$(jsonfilter -i "$cfg" -e '@.experimental.clash_api.external_controller' 2>/dev/null)
	CLASH_SECRET=$(jsonfilter -i "$cfg" -e '@.experimental.clash_api.secret' 2>/dev/null)
	case "$CLASH_ADDR" in
		0.0.0.0:*) CLASH_ADDR="127.0.0.1:${CLASH_ADDR#*:}" ;;
		:*) CLASH_ADDR="127.0.0.1$CLASH_ADDR" ;;
	esac
}

clash_get() {
	if [ -n "$CLASH_SECRET" ]; then
		curl -s -m 15 -H "Authorization: Bearer $CLASH_SECRET" "http://$CLASH_ADDR$1"
	else
		curl -s -m 15 "http://$CLASH_ADDR$1"
	fi
}

# Проверка выхода секции через sing-box. Результат: DELAY (мс) или пусто + DELAY_ERR.
section_delay() {
	DELAY=""
	DELAY_ERR=""
	if [ -z "$CLASH_ADDR" ] || ! pidof sing-box >/dev/null; then
		DELAY_ERR="podkop не запущен — возможно, в одной из секций нет ключа или ошибка в настройках"
		return
	fi
	for try in $(seq 1 "${2:-2}"); do
		resp=$(clash_get "/proxies/$1-out/delay?timeout=8000&url=$TEST_URL")
		d=$(jsonfilter -s "$resp" -e '@.delay' 2>/dev/null)
		case "$d" in
			''|*[!0-9]*|0) ;;
			*) DELAY=$d; return ;;
		esac
		[ "$try" -lt "${2:-2}" ] && sleep 3
	done
	DELAY_ERR=$(jsonfilter -s "$resp" -e '@.message' 2>/dev/null)
	[ -n "$resp" ] || DELAY_ERR="sing-box не отвечает"
	[ -n "$DELAY_ERR" ] || DELAY_ERR="нет ответа"
}

# ---------- Состояние роутера ----------

podkop_sections() {
	uci -q show podkop | sed -n 's/^podkop\.\([A-Za-z0-9_]*\)=section$/\1/p'
}

opt() {
	uci -q get "podkop.$1.$2"
}

# scheme://user@host:port/?…#name → host:port
host_of() {
	printf '%s' "$1" | sed -e 's#^[A-Za-z0-9+.-]*://##' -e 's#[/?\#].*##' -e 's#^.*@##'
}

section_json() {
	s=$1
	ct=$(opt "$s" connection_type)
	pc=$(opt "$s" proxy_config_type)
	scheme=""
	host=""
	detour=""
	DELAY=""
	DELAY_ERR=""
	vif=""
	if [ "$ct" = "vpn" ]; then
		vif=$(opt "$s" interface)
		scheme=vpn
		host=$vif
		section_delay "$s"
	elif [ "$ct" = "proxy" ]; then
		case "$pc" in
			url)
				ps=$(opt "$s" proxy_string)
				scheme=${ps%%://*}
				[ "$scheme" = "vmess" ] || host=$(host_of "$ps")
				;;
			outbound)
				ob=$(opt "$s" outbound_json)
				scheme=$(jsonfilter -s "$ob" -e '@.type' 2>/dev/null)
				detour=$(jsonfilter -s "$ob" -e '@.detour' 2>/dev/null)
				detour=${detour%-out}
				;;
			*)
				scheme=$pc
				;;
		esac
		section_delay "$s"
	fi
	lt=$(opt "$s" user_domain_list_type)
	case "$lt" in
		text) domains=$(opt "$s" user_domains_text) ;;
		dynamic) domains=$(opt "$s" user_domains | tr ' ' '\n') ;;
		*) domains="" ;;
	esac
	case "$(opt "$s" user_subnet_list_type)" in
		text) subnets=$(opt "$s" user_subnets_text) ;;
		dynamic) subnets=$(opt "$s" user_subnets | tr ' ' '\n') ;;
		*) subnets="" ;;
	esac
	printf '{"name":%s,"type":%s,"config":%s,"scheme":%s,"host":%s,"interface":%s,"dr_enabled":%s,"dr_type":%s,"dr_server":%s,"detour":%s,"delay":%s,"error":%s,"list_type":%s,"subnets":%s,"domains":%s,"community":%s,"remote":%s,"strict":%s,"exit":%s,"sub":%s}' \
		"$(json_str "$s")" "$(json_str "$ct")" "$(json_str "$pc")" "$(json_str "$scheme")" "$(json_str "$host")" \
		"$(json_str "$vif")" "$([ "$(opt "$s" domain_resolver_enabled)" = 1 ] && echo true || echo false)" \
		"$(json_str "$(opt "$s" domain_resolver_dns_type)")" "$(json_str "$(opt "$s" domain_resolver_dns_server)")" \
		"$(json_str "$detour")" "$(json_num "$DELAY")" "$(json_str "$DELAY_ERR")" "$(json_str "$lt")" \
		"$(printf '%s\n' "$subnets" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//' | json_list)" \
		"$(printf '%s\n' "$domains" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//' | json_list)" \
		"$(opt "$s" community_lists | tr ' ' '\n' | json_list)" \
		"$(opt "$s" remote_domain_lists | tr ' ' '\n' | json_list)" \
		"$([ -f "$STRICT_DIR/$s" ] && echo true || echo false)" \
		"$(ip_json "$IP_DIR/$s.json")" \
		"$(sub_json "$s")"
}

sections_json() {
	clash_api_addr
	printf '['
	first=1
	for s in $(podkop_sections); do
		[ "$first" = 1 ] || printf ','
		first=0
		section_json "$s"
	done
	printf ']'
}

watchdog_json() {
	if [ -x /usr/bin/inet-watchdog ]; then
		printf '{"installed":true,"version":%s,"reboots_row":%s,"history":%s}' \
			"$(json_str "$(sed -n 's/^VERSION="\(.*\)"$/\1/p' /usr/bin/inet-watchdog | head -n 1)")" \
			"$(json_num "$(cat /etc/inet-watchdog/reboots 2>/dev/null)")" \
			"$(grep -v '^ ' /etc/inet-watchdog/history.log 2>/dev/null | tail -n 8 | json_list)"
	else
		printf '{"installed":false}'
	fi
}

# VPN-интерфейсы роутера (WireGuard, AmneziaWG, OpenVPN, tun…) — для выбора на сайте
vpn_ifaces() {
	for d in /sys/class/net/*; do
		n=${d##*/}
		case "$n" in
			wg*|awg*|tun*|tap*|ovpn*|vpn*|nordlynx*|zt*) echo "$n" ;;
			lo|br-*|eth*|lan*|wan*|phy*|wlan*|ifb*|gre*|ip6*|sit*|teql*|erspan*|bond*) ;;
			*) [ "$(cat "$d/type" 2>/dev/null)" = "65534" ] && echo "$n" ;;
		esac
	done
}

# MAC порта WAN (по нему роутер узнаётся, даже если его переустановили)
wan_mac() {
	dev=$(ubus call network.interface.wan status 2>/dev/null | jsonfilter -e '@.device' 2>/dev/null)
	[ -n "$dev" ] || dev=$(uci -q get network.wan.device)
	[ -n "$dev" ] && cat "/sys/class/net/$dev/address" 2>/dev/null
}

info_json() {
	. /etc/openwrt_release
	board=$(ubus call system board 2>/dev/null)
	sb=false
	pidof sing-box >/dev/null && sb=true
	printf '{"agent":%s,"model":%s,"openwrt":%s,"podkop":%s,"singbox":%s,"hostname":%s,"uptime":%s,"mem_free":%s,"mem_total":%s,"flash_free":%s,"tz":%s,"zone":%s,"wan":%s,"lan_ip":%s,"mac_lan":%s,"mac_wan":%s,"vpn_ifaces":%s,"singbox_running":%s,"podkop_settings":{"dns_type":%s,"dns_server":%s,"bootstrap_dns_server":%s,"dl_proxy":%s,"dl_proxy_section":%s,"update_interval":%s},' \
		"$(json_str "$VERSION")" \
		"$(json_str "$(jsonfilter -s "$board" -e '@.model' 2>/dev/null)")" \
		"$(json_str "$DISTRIB_RELEASE")" \
		"$(json_str "$(podkop show_version 2>/dev/null | head -n 1)")" \
		"$(json_str "$(sing-box version 2>/dev/null | head -n 1 | awk '{print $3}')")" \
		"$(json_str "$(uci -q get system.@system[0].hostname)")" \
		"$(json_num "$(cut -d. -f1 /proc/uptime)")" \
		"$(json_num "$(awk '/^MemTotal:/{t=$2} /^MemAvailable:/{a=$2} END{if (t) print int(a*100/t)}' /proc/meminfo)")" \
		"$(json_num "$(awk '/^MemTotal:/{print int($2/1024)}' /proc/meminfo)")" \
		"$(json_num "$(df -k /overlay 2>/dev/null | awk 'NR==2 {print $4}')")" \
		"$(json_str "$(date +%z)")" \
		"$(json_str "$(uci -q get system.@system[0].zonename)")" \
		"$(ip_json "$IP_DIR/wan.json")" \
		"$(json_str "$(uci -q get network.lan.ipaddr | cut -d/ -f1)")" \
		"$(json_str "$(cat /sys/class/net/br-lan/address 2>/dev/null)")" \
		"$(json_str "$(wan_mac)")" \
		"$(vpn_ifaces | json_list)" \
		"$sb" \
		"$(json_str "$(uci -q get podkop.settings.dns_type)")" \
		"$(json_str "$(uci -q get podkop.settings.dns_server)")" \
		"$(json_str "$(uci -q get podkop.settings.bootstrap_dns_server)")" \
		"$({ [ "$(uci -q get podkop.settings.download_lists_via_proxy)" = 1 ] || [ -f "$CONF_DIR/dl_auto_off" ]; } && echo true || echo false)" \
		"$(json_str "$(uci -q get podkop.settings.download_lists_via_proxy_section)")" \
		"$(json_str "$(uci -q get podkop.settings.update_interval)")"
	# Движок: podkop-engine понимает xhttp и новые REALITY-серверы (xray-core 26.9+); обычный sing-box — нет
	printf '"engine":%s,"reality_fail":%s,"sb_fatal":%s,"dl_auto_off":%s,"podkop_safe":%s,"watchdog":' \
		"$(sb_engine && echo true || echo false)" \
		"$(json_num "$(logread 2>/dev/null | grep -c 'reality verification failed')")" \
		"$(json_str "$(sb_fatal)")" \
		"$([ -f "$CONF_DIR/dl_auto_off" ] && echo true || echo false)" \
		"$([ -f /tmp/inet-watchdog.podkop_safe ] && echo true || echo false)"
	watchdog_json
	printf '}'
}

# ---------- Результаты задач ----------

add_result() { # id ok(true|false) сообщение
	mkdir -p "$CONF_DIR"
	printf '{"id":%s,"ok":%s,"msg":%s}\n' "$(json_num "$1")" "$2" "$(json_str "$3")" >> "$RESULTS"
	log "задача #$1: $2 — $3"
	TASKS_DONE=1
}

results_json() {
	if [ -s "$RESULTS.sending" ]; then
		awk 'BEGIN { printf "[" } { printf "%s%s", (NR > 1 ? "," : ""), $0 } END { printf "]" }' "$RESULTS.sending"
	else
		printf '[]'
	fi
}

# ---------- Наблюдение за роутером ----------
# Изменения настроек НЕ через панель (кто-то зашёл в LuCI/SSH и поменял) и входы на роутер.
# Сравниваем «снимки» с прошлой проверкой; секреты (ключи, пароли Wi-Fi) не отправляем —
# вместо них короткий отпечаток, по которому видно только сам факт изменения.

WATCH_DIR="$CONF_DIR/watch"
WATCH_LIST="podkop firewall network wireless dhcp system dropbear uhttpd ssh_keys root_password crontab packages"

hide_secrets() {
	while IFS= read -r line; do
		case "$line" in
			*://*|*.key=*|*private_key=*|*preshared_key=*|*.password=*|*outbound_json=*|*proxy_string=*|*_links=*|*.auth_secret=*)
				printf '%s=[скрыто #%s]\n' "${line%%=*}" "$(printf '%s' "${line#*=}" | md5sum | cut -c1-6)"
				;;
			*) printf '%s\n' "$line" ;;
		esac
	done
}

watch_dump() {
	case "$1" in
		# podkop и сторож сами меняют служебные отметки при каждом перезапуске — их не считаем
		podkop) uci -q show podkop | grep -v '\.shutdown_correctly=' | hide_secrets ;;
		dhcp) uci -q show dhcp | grep -vE '^dhcp\.@dnsmasq\[[0-9]+\]\.(server|noresolv|cachesize|podkop_[a-z_]+|doh_[a-z_]+)=' ;;
		firewall|network|wireless|system|dropbear|uhttpd) uci -q show "$1" | hide_secrets ;;
		ssh_keys) awk '{ print $1, $NF }' /etc/dropbear/authorized_keys 2>/dev/null ;;
		root_password) echo "отпечаток пароля: $(grep '^root:' /etc/shadow 2>/dev/null | cut -d: -f2 | md5sum | cut -c1-8)" ;;
		crontab) grep -v 'podkop' /etc/crontabs/root 2>/dev/null ;;
		packages) { opkg list-installed 2>/dev/null || apk list -I 2>/dev/null; } | awk '{ print $1 }' | sort ;;
	esac
}

add_event() { # JSON-строка события; дописываем время события по часам роутера (unix-время)
	mkdir -p "$CONF_DIR"
	echo "${1%\}},\"ts\":$(date +%s)}" >> "$EVENTS"
}

# $1: report — сообщить об изменениях; silent — только запомнить (после своих же задач)
watch_check() {
	mkdir -p "$WATCH_DIR" "$RUN_DIR"
	now="$RUN_DIR/w.now.$$"
	changed=""
	evs="$RUN_DIR/w.events.$$"
	: > "$evs"
	for w in $WATCH_LIST; do
		watch_dump "$w" > "$now" 2>/dev/null
		last="$WATCH_DIR/$w"
		if [ ! -f "$last" ]; then
			cp "$now" "$last"
			continue
		fi
		[ "$(md5sum < "$now")" = "$(md5sum < "$last")" ] && continue
		changed="$changed $w"
		if [ "$1" = "report" ]; then
			printf '{"type":"config","file":%s,"added":%s,"removed":%s}\n' "$(json_str "$w")" \
				"$(grep -vxFf "$last" "$now" | head -n 12 | json_list)" \
				"$(grep -vxFf "$now" "$last" | head -n 12 | json_list)" >> "$evs"
		fi
		cp "$now" "$last"
	done
	if [ "$1" = "report" ] && [ -n "$changed" ]; then
		log "изменено не через панель:$changed"
		if [ "$(echo $changed | wc -w)" -gt 4 ]; then
			# Сразу много разделов — похоже на сброс/переустановку: одно короткое событие, без подробностей
			add_event "$(printf '{"type":"config_mass","files":%s}' "$(echo $changed | tr ' ' '\n' | json_list)")"
		else
			while IFS= read -r e; do add_event "$e"; done < "$evs"
		fi
	fi
	rm -f "$now" "$evs"
}

# Входы в LuCI, по SSH и в веб-терминал — из системного журнала
login_check() {
	seen="$RUN_DIR/logins.seen"
	now="$RUN_DIR/logins.now"
	logread 2>/dev/null | grep -E 'luci: (accepted|failed) login|auth succeeded for|Bad password attempt|login\[[0-9]+\]: (root login|invalid password)' > "$now"
	if [ ! -f "$seen" ] && [ "$(cut -d. -f1 /proc/uptime)" -gt 900 ]; then
		# Агент запущен на давно работающем роутере — старые входы уже не новость
		cp "$now" "$seen"
		return
	fi
	# Пустой список образцов в BusyBox grep -f не совпадает ни с чем — тогда новые входы терялись бы
	[ -s "$seen" ] || echo '#' > "$seen"
	grep -vxFf "$seen" "$now" | tail -n 20 | while IFS= read -r line; do
		via=""; ok=false; key=false; user=""; ip=""
		case "$line" in
			*"luci: accepted login"*) via=luci; ok=true ;;
			*"luci: failed login"*) via=luci ;;
			*"Pubkey auth succeeded"*) via=ssh; ok=true; key=true ;;
			*"auth succeeded"*) via=ssh; ok=true ;;
			*"Bad password attempt"*) via=ssh ;;
			*"root login on"*) via=terminal; ok=true; user=root ;;
			*"invalid password"*) via=terminal ;;
		esac
		[ -n "$via" ] || continue
		[ -n "$user" ] || user=$(printf '%s' "$line" | sed -nE "s/.* for '?([A-Za-z0-9_.-]+)'? .*/\1/p")
		ip=$(printf '%s' "$line" | sed -nE 's/.* from ([0-9a-fA-F.:]+[0-9a-fA-F]).*/\1/p' | sed -E 's/:[0-9]+$//')
		# Имя устройства по DHCP этого роутера (телефон, компьютер) — если адрес выдавал он
		host=""
		[ -n "$ip" ] && host=$(awk -v ip="$ip" '$3 == ip && $4 != "*" { print $4; exit }' /tmp/dhcp.leases 2>/dev/null | tr -cd 'A-Za-z0-9._-' | cut -c1-63)
		add_event "$(printf '{"type":"login","via":"%s","ok":%s,"key":%s,"user":%s,"ip":%s,"host":%s}' "$via" "$ok" "$key" "$(json_str "$user")" "$(json_str "$ip")" "$(json_str "$host")")"
	done
	cp "$now" "$seen"
}

events_json() {
	if [ -s "$EVENTS.sending" ]; then
		awk 'BEGIN { printf "[" } { printf "%s%s", (NR > 1 ? "," : ""), $0 } END { printf "]" }' "$EVENTS.sending"
	else
		printf '[]'
	fi
}

# ---------- Обмен с сервером ----------

# ---------- Подписки (ссылка, по которой провайдер отдаёт список ключей) ----------
# Роутер сам берёт свежий список, оставляет то, что умеет podkop, и кладёт в секцию режимом
# автовыбора (urltest). Ссылка хранится только здесь, на роутере.
# Для провайдера роутер — обычное приложение на телефоне с постоянным идентификатором:
# одно «устройство» в лимите подписки, без лишних отметок.
#
# В настройки podkop попадают только разрешённые сейчас серверы (sub_wanted), поэтому выбор страны
# и закрепление держатся и сразу после перезагрузки, пока агент ещё не запущен.
#
# Файлы секции в SUB_DIR:
#   .url .exclude   ссылка; слова, которые исключают сервер
#   .links .names   все подходящие ключи; их адреса и названия (host:port|тип|название)
#   .stat .err      всего/подходят/когда обновлено; последняя ошибка
#   .only           разрешённые страны (слова из названий через запятую)
#   .fallback       any — если страны не отвечают, взять любые; strict — не переключать никогда
#   .fb             сейчас работаем на чужих странах (режим any, свои не отвечают)
#   .pin            закреплённый сервер: host:port|название
#   .pick           сервер, выбранный вручную «прямо сейчас» (не навсегда)
#   .policy         обновление списка: 6h, 24h, dead (только при потере связи), off (только вручную)
#   .sticky         не менять живой сервер на более быстрый (уходить с него только при отказе)

SUB_DIR="$CONF_DIR/subs"
SUB_UA="Incy/2.1 CFNetwork/1568.100.1 Darwin/24.0.0"

sub_hwid() {
	if [ ! -s "$CONF_DIR/sub_hwid" ]; then
		mkdir -p "$CONF_DIR"
		cat /proc/sys/kernel/random/uuid | tr -d '-' | cut -c1-16 > "$CONF_DIR/sub_hwid"
	fi
	cat "$CONF_DIR/sub_hwid"
}

# Модель устройства для подписки: SM- и 5 знаков из HWID — постоянная и своя у каждого роутера
sub_model() {
	if [ ! -s "$CONF_DIR/sub_model" ]; then
		printf 'SM-%s' "$(sub_hwid | cut -c1-5 | tr a-z A-Z)" > "$CONF_DIR/sub_model"
	fi
	cat "$CONF_DIR/sub_model"
}

urldecode() {
	printf '%b' "$(printf '%s' "$1" | sed 's/+/ /g; s/%\([0-9A-Fa-f][0-9A-Fa-f]\)/\\x\1/g')"
}

# Скачать список ключей по ссылке → строки «схема://…» в $2. Сначала как обычный клиент,
# затем как приложение (многие провайдеры отдают ключи только приложениям).
sub_fetch() {
	url=$1
	out=$2
	raw="$RUN_DIR/sub.raw"
	for mode in plain app; do
		if [ "$mode" = plain ]; then
			curl -sSfL -m 30 -o "$raw" "$url" 2>/dev/null || continue
		else
			# Провайдер показывает устройство как «ОС модель»: Android и свой код у каждого роутера,
			# чтобы роутеры различались в списке устройств подписки
			curl -sSfL -m 30 -A "$SUB_UA" -H "x-hwid: $(sub_hwid)" -H "x-device-os: Android" -H "x-ver-os: 14" \
				-H "x-device-model: $(sub_model)" -o "$raw" "$url" 2>/dev/null || continue
		fi
		if grep -qi '<html' "$raw"; then
			continue
		elif grep -qE '^[a-z0-9]+://' "$raw"; then
			tr -d '\r' < "$raw" > "$out"
		elif base64 -d "$raw" 2>/dev/null | tr -d '\r' > "$out" && grep -qE '^[a-z0-9]+://' "$out"; then
			:
		else
			continue
		fi
		# Одни заглушки (0.0.0.0, порт 1) — значит, этому клиенту ключи не отдали
		if grep -E '^[a-z0-9]+://' "$out" | grep -vqE '@(0\.0\.0\.0|127\.0\.0\.1):'; then
			rm -f "$raw"
			return 0
		fi
	done
	rm -f "$raw"
	return 1
}

# Записать настройку, только если она изменилась (флэш бережём). 0 — изменилась; пустая удаляет файл.
sub_opt_set() { # файл значение
	[ "$(cat "$1" 2>/dev/null)" = "$2" ] && return 1
	if [ -n "$2" ]; then
		printf '%s' "$2" > "$1"
	else
		rm -f "$1"
	fi
	return 0
}

# Слова с сайта: убрать всё, что могло бы быть понято как шаблон или разметка
sub_clean() {
	tr -d '\000-\037"`$\\*?[]|'"'"
}

# Есть ли в названии хотя бы одно слово из списка через запятую
words_match() { # название слова
	wm_ifs=$IFS
	IFS=','
	for wm_w in $2; do
		wm_w=$(echo "$wm_w" | sed 's/^ *//; s/ *$//')
		[ -n "$wm_w" ] || continue
		case "$1" in
			*"$wm_w"*)
				IFS=$wm_ifs
				return 0
				;;
		esac
	done
	IFS=$wm_ifs
	return 1
}

# Параметр из ссылки (до #), как его читает podkop
link_param() { # ссылка имя
	lp_q=${1%%#*}
	case "$lp_q" in
		*\?*) lp_q="?${lp_q#*\?}" ;;
		*) return ;;
	esac
	printf '%s' "$lp_q" | sed -n "s/.*[?&]$2=\([^&]*\).*/\1/p" | head -n 1
}

# Почему podkop не сможет работать с этой ссылкой (пусто — сможет). SB_XHTTP=1 — стоит podkop-engine.
link_unsupported() { # ссылка
	case "$1" in
		vless://*|trojan://*|hysteria2://*|hy2://*|ss://*) ;;
		vmess://*) echo "VMess podkop не поддерживает"; return ;;
		*) echo "тип ${1%%://*} podkop не поддерживает"; return ;;
	esac
	if printf '%s' "$1" | grep -qE '@(0\.0\.0\.0|127\.0\.0\.1):'; then
		echo "заглушка провайдера, не сервер"
		return
	fi
	valid_key "$1" || { echo "ссылка с недопустимыми символами"; return; }
	case "$1" in
		vless://*|trojan://*)
			lu_t=$(link_param "$1" type)
			case "$lu_t" in
				''|tcp|raw|ws|grpc) ;;
				xhttp|splithttp) [ "$SB_XHTTP" = 1 ] || { echo "транспорт xhttp — нужен движок podkop-engine"; return; } ;;
				*) echo "транспорт $lu_t podkop не поддерживает"; return ;;
			esac
			case "$(link_param "$1" security)" in
				''|tls|reality|none) ;;
				*) echo "шифрование $(link_param "$1" security) podkop не поддерживает"; return ;;
			esac
			;;
		ss://*)
			[ -n "$(link_param "$1" plugin)" ] && { echo "Shadowsocks с плагином podkop не поддерживает"; return; }
			;;
	esac
}

# Стоит ли движок podkop-engine (понимает xhttp и новые REALITY-серверы)
sb_engine() {
	sing-box version 2>/dev/null | grep -q 'tools.decode-link'
}

# Последняя причина падения sing-box из журнала (без цветовых кодов)
sb_fatal() {
	pidof sing-box >/dev/null && return
	logread 2>/dev/null | grep 'sing-box' | grep -F 'FATAL' | tail -n 1 | sed 's/\x1b\[[0-9;]*m//g; s/.*FATAL[^ ]* *//' | cut -c1-300
}

# Оставить ключи, которые понимает podkop/sing-box, и выкинуть серверы со словами из исключений.
# Отброшенные — в файл $SUB_SKIP строками «host:port|тип|название|причина» (для показа на сайте).
sub_filter() { # $1 — исключения через запятую; stdin → stdout
	sb_engine && SB_XHTTP=1 || SB_XHTTP=0
	[ -n "$SUB_SKIP" ] && : > "$SUB_SKIP"
	while IFS= read -r l; do
		sf_why=$(link_unsupported "$l")
		sf_name=""
		case "$l" in *#*) sf_name=$(urldecode "${l#*#}" | tr -d '|') ;; esac
		if [ -z "$sf_why" ] && words_match "$sf_name" "$1"; then
			sf_why="исключён по названию"
		fi
		if [ -n "$sf_why" ]; then
			[ -n "$SUB_SKIP" ] && printf '%s|%s|%s|%s\n' "$(host_of "$l")" "${l%%://*}" "$sf_name" "$sf_why" >> "$SUB_SKIP"
			continue
		fi
		printf '%s\n' "$l"
	done
}

# Порядок ключей для podkop: при запуске автовыбор сначала берёт первый сервер (через него же качаются
# списки), поэтому первым — последний сервер, который точно работал; REALITY без podkop-engine — в конец
# (новые серверы xray-core обычный sing-box не понимает).
sub_order() { # секция; stdin → stdout
	so_good=$(cat "$SUB_DIR/$1.good" 2>/dev/null)
	[ -f "$SUB_DIR/$1.fb" ] && so_good=""
	sb_engine && so_eng=1 || so_eng=0
	awk -v g="$so_good" -v eng="$so_eng" '
		{
			k = 1
			p = (g != "") ? index($0, "@" g) : 0
			if (p) { c = substr($0, p + length(g) + 1, 1); if (c == "" || c == "/" || c == "?" || c == "#") k = 0 }
			if (k && eng != 1 && $0 ~ /^vless:\/\// && $0 ~ /[?&]security=reality/) k = 2
			printf "%d\t%06d\t%s\n", k, NR, $0
		}' | sort -k1,1n -k2,2n | cut -f3-
}

# Запомнить сервер, через который секция сейчас работает (не чаще раза в час — флэш бережём)
sub_note_good() { # секция
	ng_f="$SUB_DIR/$1.good"
	if [ -f "$ng_f" ] && [ $(( $(date +%s) - $(date -r "$ng_f" +%s 2>/dev/null || echo 0) )) -lt 3600 ]; then
		return
	fi
	ng_sel=$(jsonfilter -s "$(clash_get "/proxies/$1-out")" -e '@.now' 2>/dev/null)
	if [ -z "$ng_sel" ] || [ "$ng_sel" = "$1-urltest-out" ]; then
		ng_sel=$(jsonfilter -s "$(clash_get "/proxies/$1-urltest-out")" -e '@.now' 2>/dev/null)
	fi
	[ -n "$ng_sel" ] || return
	ng_h=$(sub_members "$1" | awk -F'|' -v t="$ng_sel" '$1 == t { print $2; exit }')
	[ -n "$ng_h" ] || return
	if [ "$(cat "$ng_f" 2>/dev/null)" = "$ng_h" ]; then
		touch "$ng_f"
	else
		printf '%s' "$ng_h" > "$ng_f"
	fi
}

# Название сервера по его адресу
sub_name_of() { # секция host:port
	awk -F'|' -v h="$2" '$1 == h { print $3; exit }' "$SUB_DIR/$1.names" 2>/dev/null
}

# Какие ключи должны быть в podkop прямо сейчас → файл $2.
# 1 — менять нельзя (в SUB_NOTE причина), остаётся то, что уже настроено.
sub_wanted() { # секция файл
	sw_f="$SUB_DIR/$1"
	SUB_NOTE=""
	: > "$2"
	sw_pin=$(cat "$sw_f.pin" 2>/dev/null)
	if [ -n "$sw_pin" ]; then
		sw_h=${sw_pin%%|*}
		sw_n=${sw_pin#*|}
		sw_found=""
		while IFS= read -r sw_l; do
			[ "$(host_of "$sw_l")" = "$sw_h" ] && { sw_found=$sw_l; break; }
		done < "$sw_f.links"
		# Адрес мог смениться, а сервер остался — ищем по названию
		if [ -z "$sw_found" ] && [ -n "$sw_n" ]; then
			while IFS= read -r sw_l; do
				[ "$(urldecode "${sw_l#*#}")" = "$sw_n" ] && { sw_found=$sw_l; break; }
			done < "$sw_f.links"
		fi
		if [ -z "$sw_found" ]; then
			SUB_NOTE="закреплённого сервера «${sw_n:-$sw_h}» больше нет в подписке — оставлен прежний ключ, выберите другой сервер"
			return 1
		fi
		printf '%s\n' "$sw_found" > "$2"
		return 0
	fi
	sw_only=$(cat "$sw_f.only" 2>/dev/null)
	if [ -z "$sw_only" ]; then
		cp "$sw_f.links" "$2"
		return 0
	fi
	while IFS= read -r sw_l; do
		words_match "$(urldecode "${sw_l#*#}")" "$sw_only" && printf '%s\n' "$sw_l"
	done < "$sw_f.links" > "$2"
	if [ -s "$2" ] && [ ! -f "$sw_f.fb" ]; then
		return 0
	fi
	if [ "$(cat "$sw_f.fallback" 2>/dev/null)" = "strict" ]; then
		[ -s "$2" ] && return 0
		SUB_NOTE="в подписке нет серверов: $sw_only — оставлены прежние, автопереход на другие страны выключен"
		return 1
	fi
	[ -s "$2" ] || SUB_NOTE="в подписке нет серверов: $sw_only — работают все остальные"
	# Чужие страны — первыми: сразу после запуска автовыбор стоит на первом сервере, а свои сейчас молчат
	while IFS= read -r sw_l; do
		words_match "$(urldecode "${sw_l#*#}")" "$sw_only" || printf '%s
' "$sw_l"
	done < "$sw_f.links" > "$2.others"
	cat "$2" >> "$2.others"
	mv "$2.others" "$2"
	return 0
}

# Привести список в podkop к нужному. SUB_CHANGED=1, если что-то поменялось (применяет вызывающий).
sub_apply_list() { # секция
	sa_f="$SUB_DIR/$1"
	sa_want="$RUN_DIR/sub_$1.want"
	[ -s "$sa_f.links" ] || (umask 077; uci -q get "podkop.$1.urltest_proxy_links" | tr ' ' '\n' > "$sa_f.links")
	if sub_wanted "$1" "$sa_want"; then
		[ -f "$sa_f.pin" ] || { sub_order "$1" < "$sa_want" > "$sa_want.o" && mv "$sa_want.o" "$sa_want"; }
		sa_cur=$(uci -q get "podkop.$1.urltest_proxy_links" | tr ' ' '\n')
		# Переписываем, когда изменился состав. Только порядок — лишь пока sing-box лежит: тогда важно,
		# с какого сервера он начнёт (через него качаются списки), а перезапуск всё равно нужен.
		sa_need=0
		if [ "$(opt "$1" proxy_config_type)" != "urltest" ] || [ "$(printf '%s\n' "$sa_cur" | sort)" != "$(sort "$sa_want")" ]; then
			sa_need=1
		elif ! pidof sing-box >/dev/null && [ "$(printf '%s\n' "$sa_cur" | head -n 1)" != "$(head -n 1 "$sa_want")" ]; then
			sa_need=1
		fi
		if [ "$sa_need" = 1 ]; then
			uci set "podkop.$1.proxy_config_type=urltest"
			uci -q delete "podkop.$1.urltest_proxy_links"
			while IFS= read -r sa_l; do
				uci add_list "podkop.$1.urltest_proxy_links=$sa_l"
			done < "$sa_want"
			uci -q delete "podkop.$1.proxy_string"
			uci -q delete "podkop.$1.outbound_json"
			SUB_CHANGED=1
			# Выбранного вручную сервера могло не остаться в списке
			sa_pick=$(cat "$sa_f.pick" 2>/dev/null)
			if [ -n "$sa_pick" ] && ! awk -v h="$sa_pick" 'index($0, "@" h) { f = 1 } END { exit !f }' "$sa_want"; then
				sub_drop_pick "$1"
			fi
		fi
	fi
	# «Держаться за текущий сервер»: автовыбор не меняет живой сервер на более быстрый (допуск 1 с —
	# максимум, который принимает LuCI podkop: 50–1000 мс), а уходит с него, когда тот перестал отвечать
	if [ -f "$sa_f.sticky" ]; then sa_tol=1000; else sa_tol=""; fi
	if [ "$(uci -q get "podkop.$1.urltest_tolerance")" != "$sa_tol" ]; then
		if [ -n "$sa_tol" ]; then
			uci set "podkop.$1.urltest_tolerance=$sa_tol"
		else
			uci -q delete "podkop.$1.urltest_tolerance"
		fi
		SUB_CHANGED=1
	fi
	[ -n "$SUB_NOTE" ] && echo "$SUB_NOTE" > "$sa_f.err"
	rm -f "$sa_want"
}

# Обновить секцию из её подписки. 0 — ок (SUB_CHANGED=1, если список ключей поменялся); 1 — ошибка (SUB_ERR)
sub_refresh() { # секция
	s=$1
	f="$SUB_DIR/$s"
	SUB_ERR=""
	[ -s "$f.url" ] || { SUB_ERR="подписка не задана"; return 1; }
	mkdir -p "$RUN_DIR"
	date +%s > "$RUN_DIR/sub_$s.ts"
	if ! sub_fetch "$(cat "$f.url")" "$RUN_DIR/sub_$s.all"; then
		SUB_ERR="подписка не отдала ключи — закончилась оплата, занят лимит устройств или ссылка неверна"
		echo "$SUB_ERR" > "$f.err"
		return 1
	fi
	SUB_SKIP="$RUN_DIR/sub_$s.skip"
	grep -E '^[a-z0-9]+://' "$RUN_DIR/sub_$s.all" | sub_filter "$(cat "$f.exclude" 2>/dev/null)" > "$RUN_DIR/sub_$s.use"
	SUB_SKIP=""
	total=$(grep -cE '^[a-z0-9]+://' "$RUN_DIR/sub_$s.all")
	usable=$(grep -c . "$RUN_DIR/sub_$s.use")
	# Какие серверы не взяты и почему — на сайт (без ключей)
	if [ "$(cat "$f.skipped" 2>/dev/null)" != "$(cat "$RUN_DIR/sub_$s.skip" 2>/dev/null)" ]; then
		if [ -s "$RUN_DIR/sub_$s.skip" ]; then cp "$RUN_DIR/sub_$s.skip" "$f.skipped"; else rm -f "$f.skipped"; fi
	fi
	rm -f "$RUN_DIR/sub_$s.skip"
	# Что изменилось в подписке — для ответа на кнопку «Обновить» и журнала
	set -- $( {
		cut -d'|' -f1 "$f.names" 2>/dev/null | sed 's/^/o /'
		while IFS= read -r l; do printf 'n %s\n' "$(host_of "$l")"; done < "$RUN_DIR/sub_$s.use"
	} | awk '$1 == "o" { o[$2] = 1 } $1 == "n" { n[$2] = 1 } END { for (h in n) if (!(h in o)) a++; for (h in o) if (!(h in n)) r++; printf "%d %d", a + 0, r + 0 }')
	SUB_ADDED=${1:-0}
	SUB_GONE=${2:-0}
	if [ "$usable" -eq 0 ]; then
		SUB_ERR="в подписке нет серверов, которые подходят для podkop (из $total)"
		echo "$SUB_ERR" > "$f.err"
		rm -f "$RUN_DIR/sub_$s.all" "$RUN_DIR/sub_$s.use"
		return 1
	fi
	rm -f "$f.err"
	echo "$total $usable $(date +%s)" > "$f.stat"
	# Флэш бережём: файлы переписываем, только если список действительно изменился
	if [ "$(cat "$f.links" 2>/dev/null)" != "$(cat "$RUN_DIR/sub_$s.use")" ]; then
		(umask 077; cp "$RUN_DIR/sub_$s.use" "$f.links")
		# Названия серверов (без ключей) — для показа в панели
		while IFS= read -r l; do
			printf '%s|%s|%s\n' "$(host_of "$l")" "${l%%://*}" "$(urldecode "${l#*#}" | tr -d '|')"
		done < "$RUN_DIR/sub_$s.use" > "$f.names"
	fi
	sub_apply_list "$s"
	rm -f "$RUN_DIR/sub_$s.all" "$RUN_DIR/sub_$s.use"
	return 0
}

# Серверы группы из настроек sing-box: строки «тег|host:port»
sub_members() { # секция
	sm_cfg=$(uci -q get podkop.settings.config_path)
	[ -n "$sm_cfg" ] || sm_cfg=/etc/sing-box/config.json
	for sm_m in $(jsonfilter -i "$sm_cfg" -e "@.outbounds[@.tag=\"$1-urltest-out\"].outbounds[*]" 2>/dev/null); do
		printf '%s|%s:%s\n' "$sm_m" \
			"$(jsonfilter -i "$sm_cfg" -e "@.outbounds[@.tag=\"$sm_m\"].server" 2>/dev/null)" \
			"$(jsonfilter -i "$sm_cfg" -e "@.outbounds[@.tag=\"$sm_m\"].server_port" 2>/dev/null)"
	done
}

# Отвечает ли хотя бы один сервер группы; $2 — проверять только серверы с этими словами в названии
sub_any_alive() { # секция [слова]
	aa_tmp="$RUN_DIR/sub_$1.members"
	aa_rc=1
	sub_members "$1" > "$aa_tmp"
	# Все серверы группы разом (параллельно, ~5 с); в ответе только ответившие. Не вышло — по одному, как раньше.
	aa_g=$(clash_get "/group/$1-urltest-out/delay?timeout=5000&url=$TEST_URL")
	case "$aa_g" in
		\{*) aa_par=1 ;;
		*) aa_par=0 ;;
	esac
	while IFS='|' read -r aa_tag aa_host; do
		if [ -n "$2" ] && ! words_match "$(sub_name_of "$1" "$aa_host")" "$2"; then
			continue
		fi
		if [ "$aa_par" = 1 ]; then
			aa_d=$(jsonfilter -s "$aa_g" -e "@[\"$aa_tag\"]" 2>/dev/null)
		else
			aa_d=$(jsonfilter -s "$(clash_get "/proxies/$aa_tag/delay?timeout=6000&url=$TEST_URL")" -e '@.delay' 2>/dev/null)
		fi
		case "$aa_d" in
			''|*[!0-9]*|0) ;;
			*) aa_rc=0; break ;;
		esac
	done < "$aa_tmp"
	rm -f "$aa_tmp"
	return $aa_rc
}

# Переключить группу секции на тег (без перезапуска podkop). 0 — переключено.
sub_select() { # секция тег
	if [ -n "$CLASH_SECRET" ]; then
		ss_code=$(curl -s -m 8 -o /dev/null -w '%{http_code}' -X PUT -H "Authorization: Bearer $CLASH_SECRET" -d "{\"name\":\"$2\"}" "http://$CLASH_ADDR/proxies/$1-out")
	else
		ss_code=$(curl -s -m 8 -o /dev/null -w '%{http_code}' -X PUT -d "{\"name\":\"$2\"}" "http://$CLASH_ADDR/proxies/$1-out")
	fi
	[ "$ss_code" = "204" ]
}

# Автовыбор сам перепроверяет серверы раз в 3 минуты. Если текущий умер, а другие живы, просим сделать это сейчас.
sub_nudge() { # секция
	clash_get "/group/$1-urltest-out/delay?timeout=6000&url=$TEST_URL" >/dev/null 2>&1
}

# Закрыть открытые соединения, чтобы они сразу пошли через новый сервер
sub_close_conns() {
	if [ -n "$CLASH_SECRET" ]; then
		curl -s -m 8 -o /dev/null -X DELETE -H "Authorization: Bearer $CLASH_SECRET" "http://$CLASH_ADDR/connections"
	else
		curl -s -m 8 -o /dev/null -X DELETE "http://$CLASH_ADDR/connections"
	fi
}

# Забыть ручной выбор. Если sing-box запомнил его номер, вернуть автовыбор при следующей проверке.
sub_drop_pick() { # секция
	rm -f "$SUB_DIR/$1.pick"
	: > "$RUN_DIR/sub_$1.reselect"
}

sub_inet_ok() {
	for sio_ip in 1.1.1.1 8.8.8.8 77.88.8.8; do
		ping -c 1 -W 3 "$sio_ip" >/dev/null 2>&1 && return 0
	done
	return 1
}

sub_note_event() { # секция текст
	log "подписка $1: $2"
	add_event "$(printf '{"type":"sub_note","section":%s,"text":%s}' "$(json_str "$1")" "$(json_str "$2")")"
}

# Как часто обновлять список по времени (0 — по времени не обновлять)
sub_every() { # секция
	case "$(cat "$SUB_DIR/$1.policy" 2>/dev/null)" in
		24h) echo 86400 ;;
		dead|off) echo 0 ;;
		*) echo 21600 ;;
	esac
}

# Одна секция за один проход. SUB_CHANGED=1, если список в podkop поменялся.
sub_tick_one() { # секция
	t_s=$1
	t_f="$SUB_DIR/$t_s"
	t_only=$(cat "$t_f.only" 2>/dev/null)
	t_pin=$(cat "$t_f.pin" 2>/dev/null)
	t_policy=$(cat "$t_f.policy" 2>/dev/null)
	t_last=$(cat "$RUN_DIR/sub_$t_s.ts" 2>/dev/null)
	t_upd=$(cut -d' ' -f3 "$t_f.stat" 2>/dev/null)
	[ "${t_upd:-0}" -gt "${t_last:-0}" ] 2>/dev/null && t_last=$t_upd
	t_age=$((t_now - ${t_last:-0}))
	t_every=$(sub_every "$t_s")
	t_fetch=0
	[ "$t_every" -gt 0 ] && [ "$t_age" -ge "$t_every" ] && t_fetch=1

	if ! pidof sing-box >/dev/null; then
		# podkop не работает: свежий список мог бы помочь (но не чаще раза в 10 минут)
		[ "$t_policy" != "off" ] && [ "$t_age" -ge 600 ] && t_fetch=1
		# Без скачивания: поставить первым последний рабочий сервер (с него sing-box начнёт и скачает списки)
		if [ "$t_fetch" = 0 ] && [ -s "$t_f.links" ]; then
			SUB_CHANGED=0
			sub_apply_list "$t_s"
			t_reorder=$SUB_CHANGED
		fi
	else
		# Вернуть автовыбор после снятого ручного выбора
		if [ -f "$RUN_DIR/sub_$t_s.reselect" ]; then
			[ -f "$t_f.pick" ] || sub_select "$t_s" "$t_s-urltest-out"
			rm -f "$RUN_DIR/sub_$t_s.reselect"
		fi
		# Ручной выбор держим, в том числе после перезапуска sing-box
		t_pick=$(cat "$t_f.pick" 2>/dev/null)
		if [ -n "$t_pick" ]; then
			t_tag=$(sub_members "$t_s" | awk -F'|' -v h="$t_pick" '$2 == h { print $1; exit }')
			if [ -z "$t_tag" ]; then
				sub_drop_pick "$t_s"
				t_pick=""
			elif [ "$(jsonfilter -s "$(clash_get "/proxies/$t_s-out")" -e '@.now' 2>/dev/null)" != "$t_tag" ]; then
				sub_select "$t_s" "$t_tag"
			fi
		fi

		section_delay "$t_s" 1
		if [ -n "$DELAY" ]; then
			rm -f "$RUN_DIR/sub_$t_s.fails"
			sub_note_good "$t_s"
		elif [ -n "$t_pick" ]; then
			# Выбранный вручную сервер умер: «навсегда» — это закрепление, а здесь возвращаем автовыбор
			rm -f "$t_f.pick"
			sub_select "$t_s" "$t_s-urltest-out"
			sub_note_event "$t_s" "выбранный вручную сервер «$(sub_name_of "$t_s" "$t_pick")» перестал отвечать — вернулся автовыбор"
		elif sub_any_alive "$t_s"; then
			# Текущий сервер молчит, другие живы: автовыбор уже перепроверил группу — уводим открытые соединения
			sub_nudge "$t_s"
			sub_close_conns
			rm -f "$RUN_DIR/sub_$t_s.fails"
		else
			t_fails=$(( $(cat "$RUN_DIR/sub_$t_s.fails" 2>/dev/null || echo 0) + 1 ))
			echo "$t_fails" > "$RUN_DIR/sub_$t_s.fails"
			# Две проверки подряд (быстрые проверки идут раз в 20 с) и интернет есть: дело в серверах, а не в связи
			if [ "$t_fails" -ge 2 ] && sub_inet_ok; then
				[ "$t_policy" != "off" ] && [ "$t_age" -ge 300 ] && t_fetch=1
				t_tofb=1
			fi
		fi
	fi

	t_changed=0
	if [ "$t_fetch" = 1 ]; then
		SUB_CHANGED=0
		if sub_refresh "$t_s"; then
			log "подписка $t_s обновлена"
		else
			log "подписка $t_s: $SUB_ERR"
		fi
		t_changed=$SUB_CHANGED
	fi
	[ "${t_reorder:-0}" = 1 ] && t_changed=1
	t_reorder=0

	# Свои страны молчат: берём любые другие. Если список только что обновился — сначала даём шанс ему.
	if [ "${t_tofb:-0}" = 1 ] && [ "$t_changed" = 0 ] && [ -n "$t_only" ] && [ -z "$t_pin" ] && \
		[ ! -f "$t_f.fb" ] && [ "$(cat "$t_f.fallback" 2>/dev/null)" != "strict" ]; then
		: > "$t_f.fb"
		SUB_CHANGED=0
		sub_apply_list "$t_s"
		t_changed=$SUB_CHANGED
		rm -f "$RUN_DIR/sub_$t_s.fails" "$RUN_DIR/sub_$t_s.back"
		sub_note_event "$t_s" "ни один сервер ($t_only) не отвечает — временно работают другие страны"
	elif [ -f "$t_f.fb" ] && pidof sing-box >/dev/null; then
		# Вернуться на свои страны, как только они стабильно отвечают
		if sub_any_alive "$t_s" "$t_only"; then
			t_back=$(( $(cat "$RUN_DIR/sub_$t_s.back" 2>/dev/null || echo 0) + 1 ))
			echo "$t_back" > "$RUN_DIR/sub_$t_s.back"
			if [ "$t_back" -ge 2 ]; then
				rm -f "$t_f.fb" "$RUN_DIR/sub_$t_s.back"
				SUB_CHANGED=0
				sub_apply_list "$t_s"
				[ "$SUB_CHANGED" = 1 ] && t_changed=1
				sub_note_event "$t_s" "серверы ($t_only) снова отвечают — вернулись на них"
			fi
		else
			rm -f "$RUN_DIR/sub_$t_s.back"
		fi
	fi
	t_tofb=0
	SUB_CHANGED=$t_changed
}

# Быстрая проверка между выходами на связь (раз в QUICK_EVERY секунд): отвечает ли текущий сервер каждой подписки.
# Если нет — сразу полный разбор (переключение, обновление подписки, запасные страны), не дожидаясь цикла агента.
quick_check() {
	[ -f "$SVC_OFF" ] && return 0
	[ -d "$SUB_DIR" ] || return 0
	pidof sing-box >/dev/null || return 0
	clash_api_addr
	[ -n "$CLASH_ADDR" ] || return 0
	qc_bad=""
	for u in "$SUB_DIR"/*.url; do
		[ -f "$u" ] || continue
		qc_s=$(basename "$u" .url)
		is_section "$qc_s" || continue
		[ "$(opt "$qc_s" connection_type)" = "proxy" ] || continue
		qc_d=$(jsonfilter -s "$(clash_get "/proxies/$qc_s-out/delay?timeout=5000&url=$TEST_URL")" -e '@.delay' 2>/dev/null)
		case "$qc_d" in
			''|*[!0-9]*|0) qc_bad=1 ;;
		esac
	done
	if [ -n "$qc_bad" ] && sync_lock; then
		subs_tick
		sync_unlock
	fi
}

# ---------- Здоровье sing-box ----------
# 1) Процесс есть, а API не отвечает минуту — sing-box завис: перезапускаем podkop.
# 2) sing-box падает при запуске, потому что не может скачать списки через прокси (например, первый сервер
#    группы не подключается) — временно качаем списки напрямую; через 6 часов стабильной работы возвращаем.
SB_HANG=0
SB_DOWN=0
podkop_note_event() { # текст
	log "podkop: $1"
	add_event "$(printf '{"type":"podkop_note","text":%s}' "$(json_str "$1")")"
}
DL_AUTO_OFF="$CONF_DIR/dl_auto_off"
sb_health() {
	[ -f "$SVC_OFF" ] && return 0
	[ -x /etc/init.d/podkop ] && /etc/init.d/podkop enabled || return 0
	if pidof sing-box >/dev/null; then
		SB_DOWN=0
		clash_api_addr
		[ -n "$CLASH_ADDR" ] || return 0
		if [ -z "$(clash_get /version)" ]; then
			SB_HANG=$((SB_HANG + 1))
			if [ "$SB_HANG" -ge 3 ]; then
				SB_HANG=0
				podkop_note_event "sing-box завис: работает, но не отвечает больше минуты — перезапускаю podkop"
				/etc/init.d/podkop restart >/dev/null 2>&1
			fi
			return 0
		fi
		SB_HANG=0
		# Вернуть скачивание списков через прокси после 6 часов стабильной работы
		if [ -f "$DL_AUTO_OFF" ] && [ $(( $(date +%s) - $(date -r "$DL_AUTO_OFF" +%s 2>/dev/null || echo 0) )) -ge 21600 ]; then
			rm -f "$DL_AUTO_OFF"
			if dl_proxy_ok_section "$(uci -q get podkop.settings.download_lists_via_proxy_section)"; then
				uci set podkop.settings.download_lists_via_proxy=1
				uci commit podkop
				/etc/init.d/podkop reload >/dev/null 2>&1
				watch_check silent
				podkop_note_event "6 часов без сбоев — снова скачиваю списки через прокси"
			fi
		fi
		return 0
	fi
	SB_HANG=0
	SB_DOWN=$((SB_DOWN + 1))
	[ "$SB_DOWN" -ge 3 ] || return 0
	[ "$(uci -q get podkop.settings.download_lists_via_proxy)" = 1 ] || return 0
	sh_f=$(sb_fatal)
	case "$sh_f" in
		*rule-set*|*ruleset*) ;;
		*) return 0 ;;
	esac
	uci set podkop.settings.download_lists_via_proxy=0
	uci commit podkop
	date +%s > "$DL_AUTO_OFF"
	watch_check silent
	podkop_note_event "sing-box не запускается: списки не скачиваются через прокси ($(printf '%s' "$sh_f" | sed 's/.*: //' | cut -c1-80)) — временно качаю их напрямую и перезапускаю podkop"
	/etc/init.d/podkop restart >/dev/null 2>&1
	SB_DOWN=0
}

dl_proxy_ok_section() { # секция
	is_section "$1" && case "$(opt "$1" connection_type)" in proxy|vpn) return 0 ;; esac
	return 1
}

# Ожидание до следующего выхода на связь, с быстрыми проверками подписок
agent_wait() { # секунд
	aw_end=$(( $(date +%s) + $1 ))
	while :; do
		aw_left=$(( aw_end - $(date +%s) ))
		[ "$aw_left" -gt 0 ] || break
		[ "$aw_left" -gt "$QUICK_EVERY" ] && aw_left=$QUICK_EVERY
		sleep "$aw_left"
		if [ "$STATUS" = "active" ] && [ "$(date +%s)" -lt "$aw_end" ]; then
			sb_health
			quick_check
			# Автовыбор сменил сервер — выход перемерить сразу, а не через пару минут
			pidof sing-box >/dev/null && ip_tick
		fi
	done
}

# ---------- Внешние IP: провайдер и выход каждой секции ----------
# Запрос о своём IP и стране делает сам роутер (ip-api.com), сайт никому IP не передаёт.
# Выход секции: собираем маленький конфиг sing-box только из её текущего сервера (и цепочки) и спрашиваем через него —
# основной sing-box держит свой кэш, поэтому `sing-box tools fetch` по полному конфигу не работает.
IP_DIR="$RUN_DIR/ip"
IP_EVERY=1800
IP_URL="http://ip-api.com/json/?lang=ru&fields=status,query,countryCode,country,city,isp,org"

ip_norm() { # ответ ip-api → короткий JSON или пусто
	[ "$(jsonfilter -s "$1" -e '@.status' 2>/dev/null)" = "success" ] || return 1
	printf '{"ip":%s,"cc":%s,"country":%s,"city":%s,"isp":%s,"ts":%s}' \
		"$(json_str "$(jsonfilter -s "$1" -e '@.query')")" "$(json_str "$(jsonfilter -s "$1" -e '@.countryCode')")" \
		"$(json_str "$(jsonfilter -s "$1" -e '@.country')")" "$(json_str "$(jsonfilter -s "$1" -e '@.city')")" \
		"$(json_str "$(jsonfilter -s "$1" -e '@.isp' 2>/dev/null || jsonfilter -s "$1" -e '@.org')")" "$(date +%s)"
}

# Конечный сервер выхода: через selector/urltest — до настоящего сервера
ip_leaf() { # тег
	il_t=$1
	for il_i in 1 2 3 4; do
		il_r=$(clash_get "/proxies/$il_t")
		case "$(jsonfilter -s "$il_r" -e '@.type' 2>/dev/null)" in
			Selector|URLTest|Fallback) il_t=$(jsonfilter -s "$il_r" -e '@.now' 2>/dev/null) ;;
			'') return 1 ;;
			*) echo "$il_t"; return 0 ;;
		esac
		[ -n "$il_t" ] || return 1
	done
	return 1
}

# Серверы для маленького конфига: сам сервер и (если он идёт через другую секцию) цепочка — на их текущие серверы.
# Без ссылок на DNS основного конфига. Вывод — объекты outbound через запятую.
ip_outbounds() { # тег
	io_t=$1
	io_all=""
	for io_i in 1 2 3 4; do
		io_o=$(jsonfilter -i "$IP_CFG" -e "@.outbounds[@.tag=\"$io_t\"]" 2>/dev/null)
		[ -n "$io_o" ] || return 1
		io_o=$(printf '%s' "$io_o" | sed -E 's/"domain_resolver": *\{[^}]*\} *,?//; s/"domain_resolver": *"[^"]*" *,?//; s/, *\}$/ }/')
		io_d=$(jsonfilter -s "$io_o" -e '@.detour' 2>/dev/null)
		io_l=""
		if [ -n "$io_d" ]; then
			io_l=$(ip_leaf "$io_d") || return 1
			io_o=$(printf '%s' "$io_o" | sed "s/\"detour\": *\"$io_d\"/\"detour\": \"$io_l\"/")
		fi
		io_all="$io_all${io_all:+,}$io_o"
		if [ -z "$io_l" ]; then
			printf '%s' "$io_all"
			return 0
		fi
		io_t=$io_l
	done
	return 1
}

# Вся цепочка, через которую сейчас выходит секция: её сервер и текущие серверы секций, через которые она идёт.
# Меняется любой из них (автовыбор нашёл сервер быстрее) — выход перемеряем.
ip_chain_key() { # тег конечного сервера
	ick_k=$1
	ick_t=$1
	for ick_i in 1 2 3 4; do
		ick_d=$(jsonfilter -i "$IP_CFG" -e "@.outbounds[@.tag=\"$ick_t\"].detour" 2>/dev/null)
		[ -n "$ick_d" ] || break
		ick_t=$(ip_leaf "$ick_d") || break
		ick_k="$ick_k>$ick_t"
	done
	echo "$ick_k"
}

ip_probe_section() { # секция → JSON в $IP_DIR/<секция>.json
	ips_f="$IP_DIR/$1"
	if [ "$(opt "$1" connection_type)" = "vpn" ]; then
		ips_leaf="vpn:$(opt "$1" interface)"
	else
		ips_leaf=$(ip_leaf "$1-out") || return 1
	fi
	IP_CFG=$(uci -q get podkop.settings.config_path)
	[ -n "$IP_CFG" ] || IP_CFG=/etc/sing-box/config.json
	ips_key=$ips_leaf
	[ "${ips_leaf%%:*}" = "vpn" ] || ips_key=$(ip_chain_key "$ips_leaf")
	# Свежий результат для того же сервера — не повторяем
	if [ "$(cat "$ips_f.leaf" 2>/dev/null)" = "$ips_key" ] && [ -f "$ips_f.json" ] && \
		[ $(( $(date +%s) - $(date -r "$ips_f.json" +%s 2>/dev/null || echo 0) )) -lt "$IP_EVERY" ]; then
		return 0
	fi
	ips_r=""
	if [ "${ips_leaf%%:*}" = "vpn" ]; then
		ips_r=$(curl -s -m 10 --interface "${ips_leaf#vpn:}" "$IP_URL" 2>/dev/null)
	else
		# Хватит ли памяти на второй, маленький sing-box
		[ "$(awk '/^MemAvailable:/{print int($2/1024)}' /proc/meminfo)" -ge 30 ] || return 1
		ips_o=$(ip_outbounds "$ips_leaf") || return 1
		printf '{"log":{"level":"panic"},"outbounds":[%s]}' "$ips_o" > "$IP_DIR/mini.json"
		# Не дольше 15 секунд: замер идёт в быстром цикле, мёртвый сервер не должен его держать
		sing-box tools fetch -c "$IP_DIR/mini.json" -o "$ips_leaf" "$IP_URL" > "$IP_DIR/fetch.out" 2>/dev/null &
		ips_p=$!
		( sleep 15; kill "$ips_p" 2>/dev/null ) &
		ips_k=$!
		wait "$ips_p" 2>/dev/null
		kill "$ips_k" 2>/dev/null
		ips_r=$(cat "$IP_DIR/fetch.out" 2>/dev/null)
		rm -f "$IP_DIR/fetch.out"
		rm -f "$IP_DIR/mini.json"
	fi
	ips_j=$(ip_norm "$ips_r") || ips_j=$(printf '{"error":"не удалось определить — сервер не отвечает","ts":%s}' "$(date +%s)")
	echo "$ips_j" > "$ips_f.json"
	echo "$ips_key" > "$ips_f.leaf"
}

ip_tick() {
	[ -f "$SVC_OFF" ] && return 0
	mkdir -p "$IP_DIR"
	if [ ! -f "$IP_DIR/wan.json" ] || [ $(( $(date +%s) - $(date -r "$IP_DIR/wan.json" +%s 2>/dev/null || echo 0) )) -ge "$IP_EVERY" ]; then
		# Трафик самого роутера podkop не трогает — это адрес от провайдера
		ip_w=$(ip_norm "$(curl -4 -s -m 10 "$IP_URL" 2>/dev/null)") && echo "$ip_w" > "$IP_DIR/wan.json"
	fi
	pidof sing-box >/dev/null || return 0
	clash_api_addr
	[ -n "$CLASH_ADDR" ] || return 0
	for s in $(podkop_sections); do
		case "$(opt "$s" connection_type)" in
			proxy|vpn) ip_probe_section "$s" ;;
		esac
	done
}

ip_json() { # файл → JSON или null
	if [ -s "$1" ]; then cat "$1"; else printf 'null'; fi
}

# ---------- «Никогда напрямую» ----------
# Домены секций с этим флажком dnsmasq разрешает только через DNS podkop (127.0.0.42). Пока podkop работает,
# всё как обычно; если podkop остановлен или перезапускается — эти домены не разрешаются вовсе, и соединения
# не уходят в обычный интернет. Свой файл серверов dnsmasq (servers-file): podkop его не трогает.
STRICT_DIR="$CONF_DIR/strict"
STRICT_FILE="$CONF_DIR/strict.servers"

strict_domains() { # секция → домены по одному в строке
	case "$(opt "$1" user_domain_list_type)" in
		text) opt "$1" user_domains_text ;;
		dynamic) opt "$1" user_domains | tr ' ' '\n' ;;
	esac | sed 's/^[[:space:]]*//; s/[[:space:]]*$//; s/^\*\.//; s/^\.//' | grep -E '^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?\.[A-Za-z0-9-]+$'
}

strict_sync() {
	ss_new=""
	for ss_f in "$STRICT_DIR"/*; do
		[ -e "$ss_f" ] || continue
		ss_s=${ss_f##*/}
		if ! is_section "$ss_s"; then
			rm -f "$ss_f"
			continue
		fi
		ss_new="$ss_new$(strict_domains "$ss_s" | sed 's#.*#server=/&/127.0.0.42#')
"
	done
	ss_new=$(printf '%s' "$ss_new" | sed '/^$/d' | sort -u)
	ss_cur=$(uci -q get dhcp.@dnsmasq[0].serversfile)
	if [ -z "$ss_new" ]; then
		if [ "$ss_cur" = "$STRICT_FILE" ]; then
			uci -q delete dhcp.@dnsmasq[0].serversfile
			uci commit dhcp
			rm -f "$STRICT_FILE"
			/etc/init.d/dnsmasq restart >/dev/null 2>&1
			watch_check silent
			log "«никогда напрямую»: выключено"
		fi
		return 0
	fi
	[ "$ss_new" = "$(cat "$STRICT_FILE" 2>/dev/null)" ] && [ "$ss_cur" = "$STRICT_FILE" ] && return 0
	mkdir -p "$CONF_DIR"
	printf '%s\n' "$ss_new" > "$STRICT_FILE"
	if [ "$ss_cur" != "$STRICT_FILE" ]; then
		uci set "dhcp.@dnsmasq[0].serversfile=$STRICT_FILE"
		uci commit dhcp
		/etc/init.d/dnsmasq restart >/dev/null 2>&1
		watch_check silent
	else
		killall -HUP dnsmasq 2>/dev/null
	fi
	log "«никогда напрямую»: доменов $(printf '%s\n' "$ss_new" | wc -l)"
}

strict_off() { # при удалении агента
	if [ "$(uci -q get dhcp.@dnsmasq[0].serversfile)" = "$STRICT_FILE" ]; then
		uci -q delete dhcp.@dnsmasq[0].serversfile
		uci commit dhcp
		/etc/init.d/dnsmasq restart >/dev/null 2>&1
	fi
	rm -rf "$STRICT_FILE" "$STRICT_DIR"
}

# Проход по всем подпискам (раз в цикл агента)
# Сменился движок sing-box (поставили podkop-engine или вернули обычный) — подписки перечитать сразу:
# от движка зависит, какие серверы подходят (xhttp)
sub_engine_check() {
	sec_e=$(sb_engine && echo 1 || echo 0)
	[ "$(cat "$CONF_DIR/engine" 2>/dev/null)" = "$sec_e" ] && return
	echo "$sec_e" > "$CONF_DIR/engine"
	for sec_st in "$SUB_DIR"/*.stat; do
		[ -f "$sec_st" ] && sed -i 's/ [0-9]*$/ 0/' "$sec_st"
	done
	rm -f "$RUN_DIR"/sub_*.ts
}

subs_tick() {
	[ -f "$SVC_OFF" ] && return 0
	[ -d "$SUB_DIR" ] || return 0
	sub_engine_check
	t_now=$(date +%s)
	t_any=0
	clash_api_addr
	for u in "$SUB_DIR"/*.url; do
		[ -f "$u" ] || continue
		st_s=$(basename "$u" .url)
		is_section "$st_s" || continue
		[ "$(opt "$st_s" connection_type)" = "proxy" ] || continue
		SUB_CHANGED=0
		sub_tick_one "$st_s"
		[ "$SUB_CHANGED" = 1 ] && t_any=1
	done
	if [ "$t_any" = 1 ]; then
		uci commit podkop
		podkop_apply
		PODKOP_CHANGED=0
		watch_check silent
	fi
}

# Сведения о подписке секции для панели (JSON) — без ссылки и ключей
sub_json() { # секция
	js_f="$SUB_DIR/$1"
	if [ ! -s "$js_f.url" ]; then
		printf 'null'
		return
	fi
	js_stat=$(cat "$js_f.stat" 2>/dev/null)
	js_tag="$1-urltest-out"
	js_pf="$RUN_DIR/sub_$1.proxies"
	js_mf="$RUN_DIR/sub_$1.map"
	clash_get "/proxies" > "$js_pf" 2>/dev/null
	sub_members "$1" > "$js_mf"
	# Группа либо на автовыборе (тогда сервер назначает urltest), либо на сервере, выбранном вручную
	js_sel=$(jsonfilter -i "$js_pf" -e "@.proxies[\"$1-out\"].now" 2>/dev/null)
	if [ -z "$js_sel" ] || [ "$js_sel" = "$js_tag" ]; then
		js_sel=$(jsonfilter -i "$js_pf" -e "@.proxies[\"$js_tag\"].now" 2>/dev/null)
	fi
	# Серверы группы podkop называет <секция>-<номер>-out по порядку ссылок. Сопоставляем по адресу И названию:
	# у провайдера под одним адресом бывает несколько серверов, и по одному адресу «сейчас» досталось бы всем
	js_tm="$RUN_DIR/sub_$1.tags"
	js_i=0
	uci -q get "podkop.$1.urltest_proxy_links" | tr ' ' '\n' | grep . | while IFS= read -r js_l; do
		js_i=$((js_i + 1))
		printf '%s|%s|%s\n' "$1-$js_i-out" "$(host_of "$js_l")" "$(urldecode "${js_l#*#}" | tr -d '|')"
	done > "$js_tm"
	js_servers=""
	js_nowhost=""
	js_nowdone=""
	while IFS='|' read -r js_host js_type js_name; do
		js_m=$(awk -F'|' -v h="$js_host" -v n="$js_name" '$2 == h && $3 == n { print $1; exit }' "$js_tm")
		# Такого тега в sing-box нет (другая схема имён) — как раньше, по адресу
		if [ -z "$js_m" ] || ! grep -q "^$js_m|" "$js_mf"; then
			js_m=$(awk -F'|' -v h="$js_host" '$2 == h { print $1; exit }' "$js_mf")
		fi
		js_d=""
		js_in=false
		js_now=false
		if [ -n "$js_m" ]; then
			js_in=true
			js_d=$(jsonfilter -i "$js_pf" -e "@.proxies[\"$js_m\"].history[*].delay" 2>/dev/null | tail -n 1)
			if [ "$js_m" = "$js_sel" ] && [ -z "$js_nowdone" ]; then
				js_now=true
				js_nowhost=$js_host
				js_nowdone=1
			fi
		fi
		js_servers="$js_servers${js_servers:+,}$(printf '{"host":%s,"type":%s,"name":%s,"delay":%s,"now":%s,"in":%s}' \
			"$(json_str "$js_host")" "$(json_str "$js_type")" "$(json_str "$js_name")" "$(json_num "$js_d")" "$js_now" "$js_in")"
	done < "$js_f.names"
	rm -f "$js_pf" "$js_mf" "$js_tm"
	js_pin=$(cat "$js_f.pin" 2>/dev/null)
	js_skip=""
	[ -s "$js_f.skipped" ] && while IFS='|' read -r js_host js_type js_name js_why; do
		js_skip="$js_skip${js_skip:+,}$(printf '{"host":%s,"type":%s,"name":%s,"why":%s}' "$(json_str "$js_host")" "$(json_str "$js_type")" "$(json_str "$js_name")" "$(json_str "$js_why")")"
	done < "$js_f.skipped"
	set -- "$1" $js_stat
	printf '{"total":%s,"usable":%s,"updated":%s,"exclude":%s,"only":%s,"fallback":%s,"fb":%s,"pin":%s,"pin_name":%s,"pick":%s,"policy":%s,"sticky":%s,"device":%s,"error":%s,"now":%s,"servers":[%s],"skipped":[%s],"engine":%s}' \
		"$(json_num "$2")" "$(json_num "$3")" "$(json_num "$4")" \
		"$(json_str "$(cat "$js_f.exclude" 2>/dev/null)")" \
		"$(json_str "$(cat "$js_f.only" 2>/dev/null)")" \
		"$(json_str "$(cat "$js_f.fallback" 2>/dev/null || echo any)")" \
		"$([ -f "$js_f.fb" ] && echo true || echo false)" \
		"$(json_str "${js_pin%%|*}")" "$(json_str "$([ -n "$js_pin" ] && echo "${js_pin#*|}")")" \
		"$(json_str "$(cat "$js_f.pick" 2>/dev/null)")" \
		"$(json_str "$(cat "$js_f.policy" 2>/dev/null || echo 6h)")" \
		"$([ -f "$js_f.sticky" ] && echo true || echo false)" \
		"$(json_str "Android $(sub_model)")" \
		"$(json_str "$(cat "$js_f.err" 2>/dev/null)")" \
		"$(json_str "$js_nowhost")" "$js_servers" "$js_skip" "$(sb_engine && echo true || echo false)"
}

register() {
	mkdir -p "$RUN_DIR"
	{
		printf '{"invite":%s,"info":' "$(json_str "$INVITE")"
		info_json
		printf '}'
	} > "$RUN_DIR/register.json"
	resp=$(http_post register "$RUN_DIR/register.json")
	t=$(jsonfilter -s "$resp" -e '@.token' 2>/dev/null)
	case "$t" in
		''|*[!0-9a-f]*)
			if [ "$(jsonfilter -s "$resp" -e '@.error' 2>/dev/null)" = "invite_required" ]; then
				log "сервер не принял роутер: нужен действующий код приглашения — получите команду установки в панели и запустите её заново"
			else
				log "регистрация не удалась: ${resp:-сервер недоступен}"
			fi
			return 1
			;;
	esac
	TOKEN=$t
	INVITE=""
	save_conf
	log "зарегистрирован на $SERVER — подтвердите роутер в панели («Ждут подтверждения»)"
}

# Только один выход на связь за раз (служба и ручной «tim-agent sync» не должны пересекаться)
sync_lock() {
	l="$RUN_DIR/sync.lock"
	mkdir -p "$RUN_DIR"
	if ! mkdir "$l" 2>/dev/null; then
		p=$(cat "$l/pid" 2>/dev/null)
		if [ -n "$p" ] && kill -0 "$p" 2>/dev/null; then
			return 1
		fi
		rm -rf "$l"
		mkdir "$l" 2>/dev/null || return 1
	fi
	echo $$ > "$l/pid"
}

sync_unlock() {
	rm -rf "$RUN_DIR/sync.lock"
}

sync_once() {
	if ! sync_lock; then
		log "другой выход на связь ещё идёт — пропускаю"
		return 1
	fi
	sync_once_inner
	rc=$?
	sync_unlock
	return $rc
}

sync_once_inner() {
	if [ -z "$TOKEN" ]; then
		register || return 1
	fi
	mkdir -p "$RUN_DIR"
	watch_check report
	login_check
	if [ -s "$RESULTS" ]; then
		cat "$RESULTS" >> "$RESULTS.sending"
		rm -f "$RESULTS"
	fi
	if [ -s "$EVENTS" ]; then
		cat "$EVENTS" >> "$EVENTS.sending"
		rm -f "$EVENTS"
	fi
	body="$RUN_DIR/sync.json"
	{
		printf '{"info":'
		info_json
		printf ',"sections":'
		sections_json
		printf ',"results":'
		results_json
		printf ',"events":'
		events_json
		printf '}'
	} > "$body"

	resp=$(http_post sync "$body")
	if [ -z "$resp" ]; then
		log "сервер $SERVER недоступен"
		return 1
	fi
	if [ "$(jsonfilter -s "$resp" -e '@.error' 2>/dev/null)" = "unknown_token" ]; then
		log "сервер не знает этот роутер — регистрируюсь заново"
		TOKEN=""
		save_conf
		return 1
	fi
	STATUS=$(jsonfilter -s "$resp" -e '@.status' 2>/dev/null)
	if [ -z "$STATUS" ]; then
		log "непонятный ответ сервера: $(printf '%s' "$resp" | head -c 200)"
		return 1
	fi
	rm -f "$RESULTS.sending" "$EVENTS.sending"
	date '+%Y-%m-%d %H:%M:%S' > "$RUN_DIR/last_sync"
	echo "$STATUS" > "$RUN_DIR/status"
	svc_seen "$resp"

	i=$(jsonfilter -s "$resp" -e '@.interval' 2>/dev/null)
	case "$i" in
		''|*[!0-9]*) ;;
		*) [ "$i" -ge 30 ] && INTERVAL=$i ;;
	esac

	[ "$STATUS" = "active" ] && run_tasks "$resp"
	return 0
}

# ---------- Задачи ----------

task_field() {
	jsonfilter -s "$TASKS_RESP" -e "@.tasks[$TASK_IDX].$1" 2>/dev/null
}

run_tasks() {
	TASKS_RESP=$1
	TASK_IDX=0
	for id in $(jsonfilter -s "$TASKS_RESP" -e '@.tasks[*].id' 2>/dev/null); do
		case "$id" in
			''|*[!0-9]*) TASK_IDX=$((TASK_IDX + 1)); continue ;;
		esac
		type=$(task_field type)
		log "задача #$id: $type"
		case "$type" in
			set_key) t_set_key "$id" ;;
			add_domains|remove_domains) t_domains "$id" "$type" ;;
			set_community) t_community "$id" ;;
			create_section) t_create_section "$id" ;;
			delete_section) t_delete_section "$id" ;;
			apply_config) t_apply_config "$id" ;;
			upload_report) t_report "$id" ;;
			update) t_update "$id" ;;
			update_podkop) t_update_podkop "$id" ;;
			refresh_subscription) t_refresh_subscription "$id" ;;
			sub_switch) t_sub_switch "$id" ;;
			reboot) t_reboot "$id" ;;
			show_keys) t_show_keys "$id" ;;
			*) add_result "$id" false "агент $VERSION не знает задачу «$type» — обновите скрипты" ;;
		esac
		TASK_IDX=$((TASK_IDX + 1))
	done
	[ "$NEED_RESTART" = 1 ] && podkop_apply
	# Изменения, сделанные по задачам из панели, — не «чужие»: только запоминаем
	watch_check silent
	# Устройства могли запомнить «прямые» адреса доменов, которые теперь идут через прокси, —
	# переподключаем их (это делает сторож, один раз после всех изменений)
	if [ "$PODKOP_CHANGED" = 1 ]; then
		PODKOP_CHANGED=0
		if [ -x /usr/bin/inet-watchdog ]; then
			log "переподключаю устройства после изменений"
			/usr/bin/inet-watchdog reset-clients "после изменений с сервера" >/dev/null 2>&1
		fi
	fi
}

valid_name() {
	case "$1" in
		''|[0-9_]*|*[!A-Za-z0-9_]*) return 1 ;;
	esac
	[ ${#1} -le 30 ]
}

is_section() {
	valid_name "$1" && [ "$(uci -q get "podkop.$1")" = "section" ]
}

valid_key() {
	[ ${#1} -le 4096 ] || return 1
	[ "$(printf '%s' "$1" | wc -l)" -eq 0 ] || return 1
	printf '%s' "$1" | grep -q "[[:space:]\"'\\\\]" && return 1
	case "$1" in
		hy2://?*|hysteria2://?*|vless://?*|vmess://?*|ss://?*|trojan://?*|tuic://?*|socks4://?*|socks5://?*|anytls://?*) return 0 ;;
	esac
	return 1
}

valid_domain() {
	case "$1" in
		''|*[!a-z0-9.-]*|.*|*.|*..*) return 1 ;;
		*.*) return 0 ;;
	esac
	return 1
}

# Применение настроек podkop. Если podkop уже работает — мягкий reload: он не трогает DNS роутера,
# и устройства не получают «прямые» адреса на время перезапуска (их потом не исправить без
# переподключения). Полный restart — только если podkop не работает.
# «Скачивать списки через прокси/VPN»: секция должна существовать и быть прокси или VPN,
# иначе podkop собрал бы неработающий конфиг — тогда галочку снимаем
dl_proxy_ok() {
	[ "$(uci -q get podkop.settings.download_lists_via_proxy)" = 1 ] || return 0
	dl_s=$(uci -q get podkop.settings.download_lists_via_proxy_section)
	is_section "$dl_s" && case "$(opt "$dl_s" connection_type)" in proxy|vpn) return 0 ;; esac
	return 1
}
dl_proxy_guard() {
	dl_proxy_ok && return 1
	uci set podkop.settings.download_lists_via_proxy=0
	log "секции «$dl_s» для скачивания списков больше нет — скачивание через прокси выключено"
	return 0
}

podkop_apply() {
	dl_proxy_guard
	uci commit podkop
	PODKOP_CHANGED=1
	case " $(uci -q get dhcp.@dnsmasq[0].server) " in
		*" 127.0.0.42 "*)
			if pidof sing-box >/dev/null; then
				/etc/init.d/podkop reload >/dev/null 2>&1
				NEED_RESTART=0
				return
			fi
			;;
	esac
	/etc/init.d/podkop restart >/dev/null 2>&1
	NEED_RESTART=0
}

# Для задач, которым не нужна проверка сразу: перезапуск podkop один раз после всех задач
podkop_apply_later() {
	dl_proxy_guard
	uci commit podkop
	NEED_RESTART=1
	PODKOP_CHANGED=1
}

# Ждём до ~70 с, пока секция заработает после перезапуска podkop
wait_section() {
	for i in 1 2 3 4 5 6; do
		sleep 8
		clash_api_addr
		section_delay "$1" 1
		[ -n "$DELAY" ] && return 0
	done
	return 1
}

t_set_key() {
	id=$1
	s=$(task_field section)
	key=$(task_field key)
	ob=$(task_field outbound)
	force=$(task_field force)
	if ! is_section "$s"; then
		add_result "$id" false "на роутере нет секции $s"
		return
	fi
	rm -rf "$RUN_DIR/changed_keys" "$RUN_DIR/rollback"
	if [ -n "$ob" ]; then
		# Секция в цепочке: сервер прислал ключ уже в виде outbound с «через что»
		if ! valid_outbound "$s" "$ob"; then
			add_result "$id" false "неверная настройка цепочки для $s"
			return
		fi
		snapshot_key "$s"
		set_outbound "$s" "$ob"
	else
		if [ "$(opt "$s" proxy_config_type)" != "url" ]; then
			add_result "$id" false "в секции $s ключ задан не ссылкой — агент его не меняет"
			return
		fi
		if ! valid_key "$key"; then
			add_result "$id" false "ключ не похож на ссылку прокси"
			return
		fi
		snapshot_key "$s"
		uci set "podkop.$s.proxy_string=$key"
	fi
	podkop_apply
	if [ "$force" = "true" ] || [ "$force" = "1" ]; then
		rm -rf "$RUN_DIR/changed_keys" "$RUN_DIR/rollback"
		add_result "$id" true "ключ применён без проверки"
		return
	fi
	check_changed_keys
	if [ "$KEY_ROLLED" = 1 ]; then
		add_result "$id" false "${KEY_NOTES#; }"
	else
		add_result "$id" true "${KEY_NOTES#; }"
	fi
}

t_domains() {
	id=$1
	mode=$2
	s=$(task_field section)
	if ! is_section "$s"; then
		add_result "$id" false "на роутере нет секции $s"
		return
	fi
	list=""
	bad=""
	for d in $(task_field 'domains[*]'); do
		if valid_domain "$d"; then
			list="$list $d"
		else
			bad="$bad $d"
		fi
	done
	if [ -z "$list" ]; then
		add_result "$id" false "нет правильных доменов"
		return
	fi

	if [ "$(opt "$s" user_domain_list_type)" = "dynamic" ]; then
		for d in $list; do
			uci -q del_list "podkop.$s.user_domains=$d"
			[ "$mode" = "add_domains" ] && uci add_list "podkop.$s.user_domains=$d"
		done
	else
		cur=$(opt "$s" user_domains_text)
		for d in $list; do
			cur=$(printf '%s\n' "$cur" | grep -vxF "$d")
		done
		if [ "$mode" = "add_domains" ]; then
			for d in $list; do
				cur="$cur
$d"
			done
		fi
		cur=$(printf '%s\n' "$cur" | sed '/^[[:space:]]*$/d')
		if [ -n "$cur" ]; then
			uci set "podkop.$s.user_domain_list_type=text"
			uci set "podkop.$s.user_domains_text=$cur"
		else
			uci set "podkop.$s.user_domain_list_type=disabled"
			uci -q delete "podkop.$s.user_domains_text"
		fi
	fi
	podkop_apply_later
	n=$(echo $list | wc -w)
	if [ "$mode" = "add_domains" ]; then msg="добавлено доменов: $n"; else msg="убрано доменов: $n"; fi
	[ -n "$bad" ] && msg="$msg; пропущены:$bad"
	add_result "$id" true "$msg"
}

t_community() {
	id=$1
	s=$(task_field section)
	if ! is_section "$s"; then
		add_result "$id" false "на роутере нет секции $s"
		return
	fi
	uci -q delete "podkop.$s.community_lists"
	set_lists=""
	for l in $(task_field 'lists[*]'); do
		case "$l" in
			''|*[!a-z0-9_]*) continue ;;
		esac
		uci add_list "podkop.$s.community_lists=$l"
		set_lists="$set_lists $l"
	done
	podkop_apply_later
	add_result "$id" true "готовые списки:${set_lists:- нет}"
}

t_create_section() {
	id=$1
	name=$(task_field name)
	ctype=$(task_field ctype)
	key=$(task_field key)
	if ! valid_name "$name" || [ "$name" = "settings" ]; then
		add_result "$id" false "недопустимое имя секции"
		return
	fi
	if [ -n "$(uci -q get "podkop.$name")" ]; then
		add_result "$id" false "секция $name уже есть"
		return
	fi
	case "$ctype" in
		proxy)
			if ! valid_key "$key"; then
				add_result "$id" false "ключ не похож на ссылку прокси"
				return
			fi
			;;
		exclusion) ;;
		*)
			add_result "$id" false "неизвестный тип секции: $ctype"
			return
			;;
	esac
	domains=""
	for d in $(task_field 'domains[*]'); do
		valid_domain "$d" && domains="$domains
$d"
	done
	domains=$(printf '%s\n' "$domains" | sed '/^$/d')

	uci set "podkop.$name=section"
	uci set "podkop.$name.connection_type=$ctype"
	if [ "$ctype" = "proxy" ]; then
		uci set "podkop.$name.proxy_config_type=url"
		uci set "podkop.$name.proxy_string=$key"
		uci set "podkop.$name.enable_udp_over_tcp=0"
		uci set "podkop.$name.mixed_proxy_enabled=0"
		uci set "podkop.$name.resolve_real_ip_for_routing=0"
	fi
	if [ -n "$domains" ]; then
		uci set "podkop.$name.user_domain_list_type=text"
		uci set "podkop.$name.user_domains_text=$domains"
	else
		uci set "podkop.$name.user_domain_list_type=disabled"
	fi
	uci set "podkop.$name.user_subnet_list_type=disabled"
	podkop_apply

	if [ "$ctype" = "proxy" ]; then
		if wait_section "$name"; then
			add_result "$id" true "секция $name создана, прокси работает ($DELAY мс)"
		else
			add_result "$id" true "секция $name создана, но прокси пока не отвечает ($DELAY_ERR)"
		fi
	else
		add_result "$id" true "секция $name создана"
	fi
}

# Имя сетевого интерфейса Linux (wg0, awg0, tun0, ovpn …): до 15 символов
valid_iface() {
	case "$1" in
		''|*[!A-Za-z0-9_.-]*) return 1 ;;
	esac
	[ ${#1} -le 15 ]
}

valid_subnet() {
	case "$1" in
		''|*[!0-9./]*) return 1 ;;
		*.*.*.*) return 0 ;;
	esac
	return 1
}

valid_url() {
	case "$1" in
		http://?*|https://?*) ;;
		*) return 1 ;;
	esac
	! printf '%s' "$1" | grep -q "[[:space:]\"'\\\\]"
}

# Список в виде текста (по строке) или выключен, если пусто
set_text_list() { # секция опция_типа опция_текста значение
	uci -q delete "podkop.$1.${3%_text}"
	if [ -n "$4" ]; then
		uci set "podkop.$1.$2=text"
		uci set "podkop.$1.$3=$4"
	else
		uci set "podkop.$1.$2=disabled"
		uci -q delete "podkop.$1.$3"
	fi
}

sec_field() {
	task_field "sections[$SEC_IDX].$1"
}

# ---------- Ключи, цепочки и откат ----------

# Готовый outbound sing-box от сервера (для цепочек «секция через секцию»).
# Проверяем: одна строка, понятный тип, тег = <секция>-out, detour — на другую существующую секцию.
valid_outbound() { # секция json
	[ -n "$2" ] && [ "$(printf '%s' "$2" | wc -l)" -eq 0 ] || return 1
	case "$(jsonfilter -s "$2" -e '@.type' 2>/dev/null)" in
		hysteria2|vless|trojan|shadowsocks|socks|vmess|tuic) ;;
		*) return 1 ;;
	esac
	[ "$(jsonfilter -s "$2" -e '@.tag' 2>/dev/null)" = "$1-out" ] || return 1
	det=$(jsonfilter -s "$2" -e '@.detour' 2>/dev/null)
	if [ -n "$det" ]; then
		dn=${det%-out}
		[ "$det" = "$dn-out" ] && [ "$dn" != "$1" ] && is_section "$dn" || return 1
	fi
	return 0
}

# Переименовать секцию podkop: имя, тег в цепочке, ссылки из других секций, файлы подписки
section_rename() { # старое новое
	uci rename "podkop.$1=$2"
	ob=$(opt "$2" outbound_json)
	if [ -n "$ob" ]; then
		uci set "podkop.$2.outbound_json=$(printf '%s' "$ob" | sed "s/\"tag\"[[:space:]]*:[[:space:]]*\"$1-out\"/\"tag\":\"$2-out\"/")"
	fi
	for sr_s in $(podkop_sections); do
		[ "$sr_s" = "$2" ] && continue
		ob=$(opt "$sr_s" outbound_json)
		case "$ob" in
			*"\"$1-out\""*) uci set "podkop.$sr_s.outbound_json=$(printf '%s' "$ob" | sed "s/\"detour\"[[:space:]]*:[[:space:]]*\"$1-out\"/\"detour\":\"$2-out\"/")" ;;
		esac
	done
	for sr_f in "$SUB_DIR/$1".*; do
		[ -e "$sr_f" ] && mv "$sr_f" "$SUB_DIR/$2.${sr_f##*/$1.}"
	done
	[ -f "$STRICT_DIR/$1" ] && mv "$STRICT_DIR/$1" "$STRICT_DIR/$2"
	rm -f "$IP_DIR/$1".*
	for sr_f in "$RUN_DIR/sub_$1".*; do
		[ -e "$sr_f" ] && mv "$sr_f" "$RUN_DIR/sub_$2.${sr_f##*/sub_$1.}"
	done
	[ "$(uci -q get podkop.settings.download_lists_via_proxy_section)" = "$1" ] && uci set "podkop.settings.download_lists_via_proxy_section=$2"
	PODKOP_CHANGED=1
	log "секция $1 переименована в $2"
}

# Записать outbound в секцию
set_outbound() { # секция json
	uci set "podkop.$1.proxy_config_type=outbound"
	uci set "podkop.$1.outbound_json=$2"
	uci -q delete "podkop.$1.proxy_string"
}

# Поменять «через что» в уже заданном outbound JSON секции (как у KZ)
set_detour() { # секция новая_секция_или_пусто
	ob=$(opt "$1" outbound_json)
	ob=$(printf '%s\n' "$ob" | sed -E 's/,[[:space:]]*"detour"[[:space:]]*:[[:space:]]*"[^"]*"//; s/"detour"[[:space:]]*:[[:space:]]*"[^"]*"[[:space:]]*,?//')
	if [ -n "$2" ]; then
		is_section "$2" && [ "$2" != "$1" ] || return 1
		ob=$(printf '%s\n' "$ob" | sed -E '$ s/\}[[:space:]]*$/,"detour":"'"$2"'-out"}/')
	fi
	[ -n "$(jsonfilter -s "$ob" -e '@.type' 2>/dev/null)" ] || return 1
	uci set "podkop.$1.outbound_json=$ob"
}

# Снимок ключа секции до изменения — чтобы вернуть, если новый не заработает.
# Возвращать есть смысл, только если секция работала до изменения: иначе помечаем «dead».
snapshot_key() { # секция [new]
	d="$RUN_DIR/rollback"
	mkdir -p "$d"
	for o in proxy_config_type proxy_string outbound_json; do
		if v=$(uci -q get "podkop.$1.$o"); then
			printf '%s' "$v" > "$d/$1.$o"
		else
			rm -f "$d/$1.$o"
		fi
	done
	mark=$2
	if [ -z "$mark" ]; then
		[ -n "$CLASH_ADDR" ] || clash_api_addr
		section_delay "$1"
		[ -n "$DELAY" ] || mark=dead
	fi
	echo "$1|$mark" >> "$RUN_DIR/changed_keys"
}

restore_key() { # секция
	d="$RUN_DIR/rollback"
	for o in proxy_config_type proxy_string outbound_json; do
		if [ -f "$d/$1.$o" ]; then
			uci set "podkop.$1.$o=$(cat "$d/$1.$o")"
		else
			uci -q delete "podkop.$1.$o"
		fi
	done
}

# После podkop_apply: проверить изменённые ключи, неработающие — вернуть. Пишет KEY_NOTES, KEY_ROLLED.
check_changed_keys() {
	KEY_NOTES=""
	KEY_ROLLED=0
	[ -s "$RUN_DIR/changed_keys" ] || return 0
	while IFS='|' read -r s new; do
		[ -n "$s" ] || continue
		if wait_section "$s"; then
			KEY_NOTES="$KEY_NOTES; $s: ключ работает ($DELAY мс)"
		elif [ "$new" = "new" ] || [ "$new" = "dead" ]; then
			KEY_NOTES="$KEY_NOTES; $s: ключ не отвечает ($DELAY_ERR)"
		else
			restore_key "$s"
			KEY_ROLLED=1
			KEY_NOTES="$KEY_NOTES; $s: новый ключ не заработал ($DELAY_ERR), вернул прежний"
		fi
	done < "$RUN_DIR/changed_keys"
	rm -rf "$RUN_DIR/changed_keys" "$RUN_DIR/rollback"
	[ "$KEY_ROLLED" = 1 ] && podkop_apply
	return 0
}

# Полный набор настроек с сайта: секции (создать/изменить), удаление, порядок, DNS.
# Секции, которых нет ни в списке, ни в удалении, не трогаются.
t_apply_config() {
	id=$1
	errors=""
	KEY_NOTES=""
	rm -rf "$RUN_DIR/changed_keys" "$RUN_DIR/rollback"

	# --- DNS ---
	v=$(task_field settings.dns_type)
	if [ -n "$v" ]; then
		case "$v" in
			doh|dot|udp) uci set "podkop.settings.dns_type=$v" ;;
			*) errors="$errors; тип DNS «$v» не поддерживается" ;;
		esac
	fi
	for k in dns_server bootstrap_dns_server; do
		v=$(task_field "settings.$k")
		[ -n "$v" ] || continue
		case "$v" in
			*[!A-Za-z0-9.:/_-]*) errors="$errors; недопустимое значение $k" ;;
			*) uci set "podkop.settings.$k=$v" ;;
		esac
	done

	# --- Удаление ---
	for s in $(task_field 'delete[*]'); do
		if is_section "$s"; then
			uci delete "podkop.$s"
			rm -f "$SUB_DIR/$s".* "$STRICT_DIR/$s" "$IP_DIR/$s".*
		else
			errors="$errors; нет секции $s для удаления"
		fi
	done

	# --- Переименование секций (до остальных изменений: дальше секция уже под новым именем) ---
	# Не удалось переименовать — эту секцию дальше не трогаем (иначе создалась бы новая с этим именем)
	RENAME_FAILED=" "
	SEC_IDX=0
	for name in $(task_field 'sections[*].name'); do
		old=$(sec_field rename_from)
		if [ -n "$old" ] && [ "$old" != "$name" ]; then
			if ! valid_name "$name" || [ "$name" = "settings" ]; then
				errors="$errors; недопустимое новое имя секции $name"
				RENAME_FAILED="$RENAME_FAILED$name "
			elif ! is_section "$old"; then
				errors="$errors; нет секции $old для переименования"
				RENAME_FAILED="$RENAME_FAILED$name "
			elif is_section "$name"; then
				errors="$errors; секция $name уже есть — $old не переименована"
				RENAME_FAILED="$RENAME_FAILED$name "
			else
				section_rename "$old" "$name"
			fi
		fi
		SEC_IDX=$((SEC_IDX + 1))
	done

	# --- Секции ---
	SEC_IDX=0
	for name in $(task_field 'sections[*].name'); do
		case "$RENAME_FAILED" in
			*" $name "*)
				SEC_IDX=$((SEC_IDX + 1))
				continue
				;;
		esac
		ct=$(sec_field connection_type)
		key=$(sec_field key)
		ob=$(sec_field outbound)
		if ! valid_name "$name" || [ "$name" = "settings" ]; then
			errors="$errors; недопустимое имя секции $name"
			SEC_IDX=$((SEC_IDX + 1))
			continue
		fi
		if [ -n "$key" ] && ! valid_key "$key"; then
			errors="$errors; $name: ключ не похож на ссылку прокси"
			key=""
		fi
		if [ -n "$ob" ] && ! valid_outbound "$name" "$ob"; then
			errors="$errors; $name: неверная настройка цепочки"
			ob=""
		fi

		NEW_SECTION=""
		vif=$(sec_field interface)
		if [ -n "$vif" ] && ! valid_iface "$vif"; then
			errors="$errors; $name: недопустимое имя VPN-интерфейса"
			vif=""
		fi

		if ! is_section "$name"; then
			case "$ct" in
				proxy)
					if [ -z "$key" ] && [ -z "$ob" ] && [ "$(sec_field sub_on)" != "true" ]; then
						errors="$errors; $name: для новой секции через прокси нужен ключ или подписка"
						SEC_IDX=$((SEC_IDX + 1))
						continue
					fi
					;;
				vpn)
					if [ -z "$vif" ]; then
						errors="$errors; $name: для секции через VPN нужен интерфейс"
						SEC_IDX=$((SEC_IDX + 1))
						continue
					fi
					;;
				exclusion|block) ;;
				*)
					errors="$errors; $name: неизвестный тип «$ct»"
					SEC_IDX=$((SEC_IDX + 1))
					continue
					;;
			esac
			uci set "podkop.$name=section"
			uci set "podkop.$name.connection_type=$ct"
			NEW_SECTION=$name
			if [ "$ct" = "proxy" ]; then
				[ -n "$key$ob" ] && snapshot_key "$name" new
				if [ -n "$ob" ]; then
					set_outbound "$name" "$ob"
				elif [ -z "$key" ]; then
					uci set "podkop.$name.proxy_config_type=urltest"
				else
					uci set "podkop.$name.proxy_config_type=url"
					uci set "podkop.$name.proxy_string=$key"
				fi
				uci set "podkop.$name.enable_udp_over_tcp=0"
				uci set "podkop.$name.mixed_proxy_enabled=0"
				uci set "podkop.$name.resolve_real_ip_for_routing=0"
			fi
		else
			cur_ct=$(opt "$name" connection_type)
			if [ -n "$ct" ] && [ "$ct" != "$cur_ct" ]; then
				case "$ct" in
					exclusion|block) uci set "podkop.$name.connection_type=$ct" ;;
					vpn)
						if [ -n "$vif" ] || [ -n "$(opt "$name" interface)" ]; then
							uci set "podkop.$name.connection_type=vpn"
						else
							errors="$errors; $name: чтобы пустить через VPN, нужен интерфейс"
						fi
						;;
					proxy)
						if [ -n "$key$ob" ] || [ -n "$(opt "$name" proxy_string)$(opt "$name" outbound_json)" ]; then
							uci set "podkop.$name.connection_type=proxy"
							[ -n "$(opt "$name" proxy_config_type)" ] || uci set "podkop.$name.proxy_config_type=url"
						else
							errors="$errors; $name: чтобы пустить через прокси, нужен ключ"
						fi
						;;
				esac
			fi
			if [ -n "$ob" ]; then
				snapshot_key "$name"
				set_outbound "$name" "$ob"
			elif [ -n "$key" ]; then
				if [ "$(opt "$name" proxy_config_type)" != "url" ] || [ "$(opt "$name" proxy_string)" != "$key" ]; then
					snapshot_key "$name"
					uci set "podkop.$name.proxy_config_type=url"
					uci set "podkop.$name.proxy_string=$key"
					uci -q delete "podkop.$name.outbound_json"
				fi
			elif [ "$(sec_field set_detour)" = "true" ]; then
				if [ "$(opt "$name" proxy_config_type)" = "outbound" ]; then
					snapshot_key "$name"
					set_detour "$name" "$(sec_field detour)" || errors="$errors; $name: не удалось поменять «через что» — измените в LuCI"
				else
					errors="$errors; $name: чтобы пустить через другую секцию, нужен ключ"
				fi
			fi
		fi

		# Источник ключей — подписка: ссылку храним только на роутере, ключи берём из неё
		su=$(sec_field sub_url)
		if [ "$(sec_field sub_off)" = "true" ] && [ -f "$SUB_DIR/$name.url" ]; then
			rm -f "$SUB_DIR/$name".*
		fi
		if [ "$(sec_field sub_on)" = "true" ]; then
			mkdir -p "$SUB_DIR"
			chmod 700 "$SUB_DIR"
			# Свежий список нужен, только если поменялась ссылка или исключения; выбор стран и
			# закрепление применяются к уже скачанному — подписку лишний раз не дёргаем
			sf="$SUB_DIR/$name"
			sub_need=0
			# У новой секции не должно остаться выбора от прежней с тем же именем (sing-box помнит его в кэше)
			[ "$NEW_SECTION" = "$name" ] && : > "$RUN_DIR/sub_$name.reselect"
			[ -s "$sf.links" ] || sub_need=1
			if [ -n "$su" ]; then
				case "$su" in
					http://?*|https://?*)
						[ "$su" = "$(cat "$sf.url" 2>/dev/null)" ] || sub_need=1
						(umask 077; printf '%s' "$su" > "$sf.url")
						;;
					*) errors="$errors; $name: ссылка подписки должна начинаться с https://" ;;
				esac
			fi
			sub_opt_set "$sf.exclude" "$(sec_field sub_exclude | sub_clean)" && sub_need=1
			sub_opt_set "$sf.only" "$(sec_field sub_only | sub_clean)" && rm -f "$sf.fb"
			v=$(sec_field sub_fallback)
			[ "$v" = "strict" ] || v=any
			sub_opt_set "$sf.fallback" "$v"
			[ "$v" = "strict" ] && rm -f "$sf.fb"
			v=$(sec_field sub_policy)
			case "$v" in 6h|24h|dead|off) ;; *) v=6h ;; esac
			sub_opt_set "$sf.policy" "$v"
			if [ "$(sec_field sub_sticky)" = "true" ]; then
				sub_opt_set "$sf.sticky" 1
			else
				sub_opt_set "$sf.sticky" ""
			fi
			v=$(sec_field sub_pin)
			if [ -z "$v" ]; then
				rm -f "$sf.pin"
			elif [ "$v" != "$(sed 's/|.*//' "$sf.pin" 2>/dev/null)" ]; then
				pin_name=$(sub_name_of "$name" "$v")
				if grep -qF "$v|" "$sf.names" 2>/dev/null; then
					sub_opt_set "$sf.pin" "$v|$pin_name"
				else
					errors="$errors; $name: сервера $v нет в подписке — закрепление не изменено"
				fi
			fi
			if [ -f "$sf.pin" ]; then
				rm -f "$sf.fb"
				[ -f "$sf.pick" ] && sub_drop_pick "$name"
			fi
			if [ -s "$sf.url" ]; then
				SUB_CHANGED=0
				if [ "$sub_need" = 0 ]; then
					rm -f "$sf.err"
					sub_apply_list "$name"
					KEY_NOTES="$KEY_NOTES; $name: серверов в работе: $(uci -q get "podkop.$name.urltest_proxy_links" | wc -w)"
				elif sub_refresh "$name"; then
					set -- $(cat "$sf.stat")
					KEY_NOTES="$KEY_NOTES; $name: из подписки подходят: $2 из $1, в работе: $(uci -q get "podkop.$name.urltest_proxy_links" | wc -w)"
				else
					errors="$errors; $name: $SUB_ERR"
					if [ "$NEW_SECTION" = "$name" ] && [ -z "$(uci -q get "podkop.$name.urltest_proxy_links")" ]; then
						uci delete "podkop.$name"
						rm -f "$sf".*
						errors="$errors (секция $name не создана)"
						SEC_IDX=$((SEC_IDX + 1))
						continue
					fi
				fi
			fi
		fi
		# VPN: интерфейс и (по желанию) отдельный DNS для доменов этой секции
		if [ "$(opt "$name" connection_type)" = "vpn" ]; then
			[ -n "$vif" ] && uci set "podkop.$name.interface=$vif"
			if [ "$(sec_field dr_enabled)" = "true" ]; then
				drt=$(sec_field dr_type)
				drs=$(sec_field dr_server)
				case "$drt" in doh|dot|udp) ;; *) drt=doh ;; esac
				case "$drs" in ''|*[!A-Za-z0-9.:/_-]*) drs=1.1.1.1 ;; esac
				uci set "podkop.$name.domain_resolver_enabled=1"
				uci set "podkop.$name.domain_resolver_dns_type=$drt"
				uci set "podkop.$name.domain_resolver_dns_server=$drs"
			else
				uci set "podkop.$name.domain_resolver_enabled=0"
			fi
		fi

		# «Никогда напрямую» (поле есть только у сайта с этой настройкой)
		case "$(sec_field strict)" in
			true) mkdir -p "$STRICT_DIR"; : > "$STRICT_DIR/$name" ;;
			false) rm -f "$STRICT_DIR/$name" ;;
		esac

		# Списки: сервер присылает их целиком
		list=""
		for d in $(sec_field 'domains[*]'); do
			valid_domain "$d" && list="$list
$d"
		done
		set_text_list "$name" user_domain_list_type user_domains_text "$(printf '%s\n' "$list" | sed '/^$/d')"

		list=""
		for d in $(sec_field 'subnets[*]'); do
			valid_subnet "$d" && list="$list
$d"
		done
		set_text_list "$name" user_subnet_list_type user_subnets_text "$(printf '%s\n' "$list" | sed '/^$/d')"

		uci -q delete "podkop.$name.community_lists"
		for l in $(sec_field 'community[*]'); do
			case "$l" in
				''|*[!a-z0-9_]*) ;;
				*) uci add_list "podkop.$name.community_lists=$l" ;;
			esac
		done

		uci -q delete "podkop.$name.remote_domain_lists"
		for u in $(sec_field 'remote[*]'); do
			if valid_url "$u"; then
				uci add_list "podkop.$name.remote_domain_lists=$u"
			else
				errors="$errors; $name: недопустимая ссылка на список"
			fi
		done

		SEC_IDX=$((SEC_IDX + 1))
	done

	# --- Скачивание списков через прокси/VPN (поле есть только у сайта с этой настройкой) ---
	v=$(task_field settings.dl_proxy)
	if [ "$v" = 1 ]; then
		v=$(task_field settings.dl_proxy_section)
		if is_section "$v" && case "$(opt "$v" connection_type)" in proxy|vpn) true ;; *) false ;; esac; then
			# Временно выключено агентом после сбоя — включится само через 6 часов работы
			[ -f "$DL_AUTO_OFF" ] || uci set podkop.settings.download_lists_via_proxy=1
			uci set "podkop.settings.download_lists_via_proxy_section=$v"
		else
			errors="$errors; скачивание списков: секции $v нет или она не через прокси/VPN — настройка не изменена"
		fi
	elif [ "$v" = 0 ]; then
		rm -f "$DL_AUTO_OFF"
		uci set podkop.settings.download_lists_via_proxy=0
	fi
	# Как часто podkop обновляет списки (значения — как в LuCI podkop)
	v=$(task_field settings.update_interval)
	case "$v" in
		'') ;;
		1h|3h|12h|1d|3d) uci set "podkop.settings.update_interval=$v" ;;
		*) errors="$errors; частота обновления списков «$v» не поддерживается" ;;
	esac

	# --- Порядок секций (важен: домен из двух секций обработает первая) ---
	uci -q reorder podkop.settings=0
	pos=1
	for name in $(task_field 'sections[*].name'); do
		if is_section "$name"; then
			uci reorder "podkop.$name=$pos"
			pos=$((pos + 1))
		fi
	done

	SUB_NOTES=$KEY_NOTES
	podkop_apply
	strict_sync
	check_changed_keys
	KEY_NOTES="$SUB_NOTES$KEY_NOTES"

	if [ -n "$errors" ]; then
		add_result "$id" false "применено частично${KEY_NOTES}; ошибки${errors}"
	elif [ "$KEY_ROLLED" = 1 ]; then
		add_result "$id" false "настройки применены${KEY_NOTES}"
	else
		add_result "$id" true "настройки применены${KEY_NOTES}"
	fi
}

t_delete_section() {
	id=$1
	s=$(task_field section)
	if ! is_section "$s"; then
		add_result "$id" false "на роутере нет секции $s"
		return
	fi
	uci delete "podkop.$s"
	rm -f "$SUB_DIR/$s".* "$STRICT_DIR/$s" "$IP_DIR/$s".*
	podkop_apply_later
	strict_sync
	add_result "$id" true "секция $s удалена"
}

# Разбор подписок для отчёта: что отдаёт провайдер (как обычный клиент и как приложение), какие серверы
# подходят и почему отброшены, что сейчас стоит в podkop. Без ключей: только адреса, типы и названия серверов.
sub_link_line() { # ссылка → «тип | адрес | транспорт/шифрование | название | итог»
	sl_n=""
	case "$1" in *\#*) sl_n=$(urldecode "${1#*#}") ;; esac
	sl_why=$(link_unsupported "$1")
	[ -z "$sl_why" ] && words_match "$sl_n" "$2" && sl_why="исключён по названию"
	printf '  %-9s | %-28s | %-14s | %s | %s\n' "${1%%://*}" "$(host_of "$1")" \
		"$(link_param "$1" type)/$(link_param "$1" security)" "$sl_n" "${sl_why:-ПОДХОДИТ}"
}

sub_diag() {
	sb_engine && SB_XHTTP=1 || SB_XHTTP=0
	echo "движок: $(sing-box version 2>/dev/null | head -n 1)$([ "$SB_XHTTP" = 1 ] && echo ' (podkop-engine: xhttp и новый REALITY есть)' || echo ' (обычный sing-box: без xhttp и без REALITY xray-core 26.9+)')"
	for sd_u in "$SUB_DIR"/*.url; do
		[ -f "$sd_u" ] || continue
		sd_s=$(basename "$sd_u" .url)
		sd_f="$SUB_DIR/$sd_s"
		sd_url=$(cat "$sd_u")
		sd_ex=$(cat "$sd_f.exclude" 2>/dev/null)
		echo
		echo "----- секция $sd_s"
		echo "адрес подписки: $(printf '%s' "$sd_url" | sed -E 's#^(https?://[^/]+).*#\1/…#') (путь с токеном скрыт)"
		echo "исключить: ${sd_ex:-—} · только страны: $(cat "$sd_f.only" 2>/dev/null || echo —) · закреплён: $(cat "$sd_f.pin" 2>/dev/null || echo —) · вручную: $(cat "$sd_f.pick" 2>/dev/null || echo —)"
		echo "обновлять: $(cat "$sd_f.policy" 2>/dev/null || echo 6h) · запасные страны сейчас: $([ -f "$sd_f.fb" ] && echo да || echo нет) · держаться за сервер: $([ -f "$sd_f.sticky" ] && echo да || echo нет) · последний рабочий: $(cat "$sd_f.good" 2>/dev/null || echo —)"
		sd_st=$(cat "$sd_f.stat" 2>/dev/null)
		set -- $sd_st
		echo "последнее обновление: ${3:+$(date -d "@$3" '+%Y-%m-%d %H:%M:%S' 2>/dev/null || echo "$3")} · всего ${1:-?}, подходят ${2:-?} · ошибка: $(cat "$sd_f.err" 2>/dev/null || echo нет)"
		echo "проверка подписки (этим агентом) была: $(date -d "@$(cat "$RUN_DIR/sub_$sd_s.ts" 2>/dev/null || echo 0)" '+%Y-%m-%d %H:%M:%S' 2>/dev/null)"
		for sd_mode in plain app; do
			sd_raw="$RUN_DIR/diag.raw"
			sd_hdr="$RUN_DIR/diag.hdr"
			if [ "$sd_mode" = plain ]; then
				sd_code=$(curl -sSL -m 30 -D "$sd_hdr" -o "$sd_raw" -w '%{http_code}' "$sd_url" 2>&1)
			else
				sd_code=$(curl -sSL -m 30 -D "$sd_hdr" -A "$SUB_UA" -H "x-hwid: $(sub_hwid)" -H "x-device-os: Android" -H "x-ver-os: 14" \
					-H "x-device-model: $(sub_model)" -o "$sd_raw" -w '%{http_code}' "$sd_url" 2>&1)
			fi
			echo
			echo "  [$([ "$sd_mode" = plain ] && echo 'как обычный клиент' || echo "как приложение: $SUB_UA, Android $(sub_model)")] HTTP $sd_code, $(wc -c < "$sd_raw" 2>/dev/null || echo 0) байт"
			grep -iE '^(content-type|subscription-userinfo|profile-update-interval|profile-title|announce|support-url):' "$sd_hdr" 2>/dev/null | tr -d '\r' | \
				while IFS= read -r sd_h; do
					case "$sd_h" in
						*': base64:'*) printf '    %s: %s\n' "${sd_h%%:*}" "$(printf '%s' "${sd_h#*base64:}" | base64 -d 2>/dev/null | tr '\n' ' ')" ;;
						*) printf '    %s\n' "$sd_h" ;;
					esac
				done
			if grep -qi '<html' "$sd_raw" 2>/dev/null; then
				echo "    ответ — HTML-страница, не список ключей"
				sd_list=""
			elif grep -qE '^[a-z0-9]+://' "$sd_raw" 2>/dev/null; then
				echo "    формат: ссылки текстом"
				sd_list=$(tr -d '\r' < "$sd_raw" | grep -E '^[a-z0-9]+://')
			elif base64 -d "$sd_raw" 2>/dev/null | grep -qE '^[a-z0-9]+://'; then
				echo "    формат: base64"
				sd_list=$(base64 -d "$sd_raw" 2>/dev/null | tr -d '\r' | grep -E '^[a-z0-9]+://')
			else
				echo "    формат не распознан, начало ответа (без ключей): $(head -c 120 "$sd_raw" 2>/dev/null | tr -d '\r\n' | sed -E 's#://[^@ ]*@#://СКРЫТО@#g')"
				sd_list=""
			fi
			if [ -n "$sd_list" ]; then
				echo "    серверов: $(printf '%s\n' "$sd_list" | grep -c .) — $(printf '%s\n' "$sd_list" | sed 's#://.*##' | sort | uniq -c | awk '{printf "%s %s; ", $2, $1}')"
				printf '%s\n' "$sd_list" | while IFS= read -r sd_l; do sub_link_line "$sd_l" "$sd_ex"; done
			fi
			rm -f "$sd_raw" "$sd_hdr"
		done
		echo
		echo "  Сейчас в podkop ($(opt "$sd_s" proxy_config_type)): $(uci -q get "podkop.$sd_s.urltest_proxy_links" | tr ' ' '\n' | grep -c .) серверов"
		uci -q get "podkop.$sd_s.urltest_proxy_links" | tr ' ' '\n' | grep . | while IFS= read -r sd_l; do
			sd_n=""
			case "$sd_l" in *\#*) sd_n=$(urldecode "${sd_l#*#}") ;; esac
			echo "    ${sd_l%%://*} | $(host_of "$sd_l") | $sd_n"
		done
		echo "  Серверы группы в sing-box: $(sub_members "$sd_s" | wc -l)"
	done
}

t_report() {
	id=$1
	f="$RUN_DIR/report.txt"
	{
		echo "===== $NAME $VERSION — отчёт от $(date '+%Y-%m-%d %H:%M:%S')"
		echo
		if [ -x /usr/bin/inet-watchdog ]; then
			/usr/bin/inet-watchdog report
		else
			echo "(сторож inet-watchdog не установлен)"
			free
		fi
		echo
		echo "===== Секции podkop (ключи скрыты)"
		uci -q show podkop | sed -E "s#(hysteria2|hy2|vless|vmess|ss|trojan|tuic|socks[45]?|anytls)://[^' ]*#\1://СКРЫТО#g; s#(\"(server|password|username|uuid|server_port)\" *: *)(\"[^\"]*\"|[0-9]+)#\1\"СКРЫТО\"#g"
		echo
		echo "===== Подписки (без ключей)"
		[ -d "$SUB_DIR" ] && sub_diag || echo "подписок нет"
		echo
		echo "===== Журнал podkop / sing-box / агента"
		logread 2>/dev/null | grep -iE "podkop|sing-box|$NAME" | tail -n 200
	} > "$f" 2>&1
	if server_curl -s -m 60 -X POST -H "X-Token: $TOKEN" --data-binary "@$f" "$SERVER/api.php?a=upload&task=$id" | grep -q '"ok":true'; then
		add_result "$id" true "отчёт отправлен"
	else
		add_result "$id" false "не удалось отправить отчёт"
	fi
	rm -f "$f"
}

# Обновление скриптов с GitHub. Сервер выбирает только версию (ref);
# откуда качать, зашито в агенте — подменить код через сервер нельзя.
t_update() {
	id=$1
	ref=$(task_field ref)
	case "$ref" in
		''|*[!A-Za-z0-9._-]*)
			add_result "$id" false "недопустимая версия: $ref"
			return
			;;
	esac
	d="$RUN_DIR/update"
	rm -rf "$d"
	mkdir -p "$d"
	for f in tim-agent.sh inet-watchdog.sh; do
		if ! curl -4 -sfL -m 90 -o "$d/$f" "$REPO_RAW/$ref/router/$f" && ! curl -sfL -m 90 -o "$d/$f" "$REPO_RAW/$ref/router/$f"; then
			add_result "$id" false "не удалось скачать $f версии $ref с GitHub"
			return
		fi
		if ! head -n 1 "$d/$f" | grep -q '^#!/bin/sh' || ! sh -n "$d/$f"; then
			add_result "$id" false "$f версии $ref повреждён — обновление отменено"
			return
		fi
	done
	new_ver=$(sed -n 's/^VERSION="\(.*\)"$/\1/p' "$d/tim-agent.sh" | head -n 1)

	sh "$d/inet-watchdog.sh" install >/dev/null 2>&1

	cp "$BIN" "$BIN.prev" 2>/dev/null
	if ! sh "$d/tim-agent.sh" install-files >/dev/null 2>&1; then
		add_result "$id" false "не удалось установить агент $ref"
		return
	fi
	add_result "$id" true "скрипты обновлены до $ref (агент $new_ver)"
	# Текущий процесс завершится после отправки результата, procd запустит новую версию
	RESTART_FOR_UPDATE=1
}

# Обновить список серверов подписки сейчас (кнопка на сайте)
t_refresh_subscription() {
	id=$1
	s=$(task_field section)
	if ! is_section "$s" || [ ! -s "$SUB_DIR/$s.url" ]; then
		add_result "$id" false "у секции $s нет подписки"
		return
	fi
	SUB_CHANGED=0
	if sub_refresh "$s"; then
		set -- $(cat "$SUB_DIR/$s.stat")
		rs_note=""
		[ "${SUB_ADDED:-0}" -gt 0 ] && rs_note="новых серверов: $SUB_ADDED"
		[ "${SUB_GONE:-0}" -gt 0 ] && rs_note="$rs_note${rs_note:+, }пропало: $SUB_GONE"
		rs_skip=$(grep -c . "$SUB_DIR/$s.skipped" 2>/dev/null)
		[ "${rs_skip:-0}" -gt 0 ] && rs_note="$rs_note${rs_note:+; }не подошли $rs_skip (причины — в списке серверов на сайте)"
		if [ "$SUB_CHANGED" = 1 ]; then
			uci commit podkop
			podkop_apply
			add_result "$id" true "список серверов обновлён: работают $2 из $1${rs_note:+; $rs_note}"
		else
			add_result "$id" true "подписка скачана заново, состав серверов тот же: работают $2 из $1${rs_note:+; $rs_note}"
		fi
	else
		add_result "$id" false "$SUB_ERR"
	fi
}

# «Переключись на этот сервер прямо сейчас» — без перезапуска podkop и без переподключения устройств.
# Выбор держится, пока сервер отвечает; server=auto возвращает автовыбор.
t_sub_switch() {
	id=$1
	s=$(task_field section)
	target=$(task_field server)
	if ! is_section "$s" || [ ! -s "$SUB_DIR/$s.url" ]; then
		add_result "$id" false "у секции $s нет подписки"
		return
	fi
	if [ -f "$SUB_DIR/$s.pin" ]; then
		add_result "$id" false "в секции $s сервер закреплён — сначала снимите закрепление"
		return
	fi
	clash_api_addr
	if [ -z "$CLASH_ADDR" ] || ! pidof sing-box >/dev/null; then
		add_result "$id" false "podkop не запущен — переключиться нельзя"
		return
	fi
	if [ "$target" = "auto" ]; then
		rm -f "$SUB_DIR/$s.pick"
		if sub_select "$s" "$s-urltest-out"; then
			sub_close_conns
			add_result "$id" true "$s: снова автовыбор самого быстрого сервера"
		else
			add_result "$id" false "$s: sing-box не принял переключение"
		fi
		return
	fi
	tag=$(sub_members "$s" | awk -F'|' -v h="$target" '$2 == h { print $1; exit }')
	if [ -z "$tag" ]; then
		add_result "$id" false "$s: сервер $target сейчас не в работе (другая страна или уже нет в подписке)"
		return
	fi
	if ! sub_select "$s" "$tag"; then
		add_result "$id" false "$s: sing-box не принял переключение"
		return
	fi
	section_delay "$s" 2
	if [ -z "$DELAY" ]; then
		rm -f "$SUB_DIR/$s.pick"
		sub_select "$s" "$s-urltest-out"
		add_result "$id" false "$s: сервер «$(sub_name_of "$s" "$target")» не отвечает — остался автовыбор"
		return
	fi
	printf '%s' "$target" > "$SUB_DIR/$s.pick"
	sub_close_conns
	add_result "$id" true "$s: работает через «$(sub_name_of "$s" "$target")», $DELAY мс"
}

# Обновление podkop официальным установщиком или скриптом зеркала podkop (адреса зашиты в агенте,
# сервер их не выбирает). На любые вопросы отвечаем «нет»: так установщик никогда не сбросит настройки
# podkop (это он предлагает для версий < 0.7) и не удалит конфликтующие пакеты — просто остановится.
# engine=1 — перейти на движок podkop-engine (новые REALITY-серверы и xhttp).
t_update_podkop() {
	id=$1
	want_engine=$(task_field engine)
	if [ ! -x /etc/init.d/podkop ]; then
		add_result "$id" false "podkop не установлен"
		return
	fi
	old=$(podkop show_version 2>/dev/null | head -n 1)
	old_sb=$(sing-box version 2>/dev/null | head -n 1 | awk '{print $3}')
	case "$old" in
		''|v0.[0-6].*|0.[0-6].*)
			add_result "$id" false "podkop ${old:-неизвестной версии} слишком старый: обновление сбросит его настройки — обновите вручную по SSH (команда установки с --update-podkop)"
			return
			;;
	esac
	if [ "$want_engine" = 1 ] && sb_engine; then
		want_engine=0
	fi
	f="$RUN_DIR/podkop-install.sh"
	ulog="$RUN_DIR/podkop-update.log"
	mkdir -p "$RUN_DIR"
	via=""
	# Официальный установщик с GitHub; движок podkop-engine и недоступный GitHub — через зеркало разработчиков podkop
	# Где уже стоит podkop-engine, официальный установщик мог бы вернуть обычный sing-box — только зеркало
	if [ "$want_engine" != 1 ] && ! sb_engine && curl -sfL -m 90 -o "$f" "$PODKOP_INSTALLER" && head -n 1 "$f" | grep -q '^#!/bin/sh' && sh -n "$f"; then
		via=github
	elif curl -sfL -m 120 -o "$f" "$PODKOP_MIRROR" && head -n 1 "$f" | grep -q '^#!/bin/sh' && sh -n "$f"; then
		via=mirror
	else
		rm -f "$f"
		add_result "$id" false "не удалось скачать установщик podkop ни с GitHub, ни с зеркала podkop"
		return
	fi
	log "обновляю podkop ($old, sing-box $old_sb) через $via$([ "$want_engine" = 1 ] && echo ', переход на podkop-engine')"
	: > "$ulog"
	if [ "$via" = github ]; then
		printf 'n\nn\nn\nn\nn\n' | sh "$f" >> "$ulog" 2>&1
		rc=$?
		# Официальный установщик не справился (часто пакеты с GitHub не скачиваются) — зеркало podkop
		if [ "$rc" != 0 ] && curl -sfL -m 120 -o "$f" "$PODKOP_MIRROR" && head -n 1 "$f" | grep -q '^#!/bin/sh' && sh -n "$f"; then
			log "официальный установщик podkop завершился с ошибкой — пробую зеркало podkop"
			via=mirror
			sh "$f" < /dev/null >> "$ulog" 2>&1
			rc=$?
		fi
	else
		# Без терминала скрипт зеркала на все вопросы отвечает «нет». Для перехода на podkop-engine отвечаем «да»
		# ровно на один вопрос — «заменить sing-box на podkop-engine?»: строку вопроса находим и меняем, только если
		# она в скрипте есть и одна. Саму замену скрипт делает своими средствами — одинаково для opkg и apk (OpenWrt 25+).
		if [ "$want_engine" = 1 ]; then
			if [ "$(grep -c 'if ask "$_pq" pdk_replace; then' "$f")" = 1 ]; then
				sed -i 's/if ask "$_pq" pdk_replace; then/if true; then/' "$f"
				if ! sh -n "$f"; then
					rm -f "$f"
					add_result "$id" false "скрипт зеркала podkop изменился — перейти на podkop-engine автоматически не получилось; на роутере по SSH: wget -O - $PODKOP_MIRROR | sh"
					return
				fi
			elif command -v opkg >/dev/null 2>&1; then
				# Скрипт зеркала изменился: старый способ — убрать обычный sing-box заранее, тогда скрипт ставит движок сам
				/etc/init.d/podkop stop >/dev/null 2>&1
				opkg remove sing-box --force-depends >> "$ulog" 2>&1
			else
				rm -f "$f"
				add_result "$id" false "скрипт зеркала podkop изменился — перейти на podkop-engine автоматически не получилось; на роутере по SSH: wget -O - $PODKOP_MIRROR | sh"
				return
			fi
		fi
		sh "$f" < /dev/null >> "$ulog" 2>&1
		rc=$?
		# Движок не встал — вернуть обычный sing-box, чтобы podkop не остался без него
		if ! command -v sing-box >/dev/null 2>&1; then
			log "sing-box после установки не найден — возвращаю пакет sing-box"
			if command -v apk >/dev/null 2>&1; then
				apk update >/dev/null 2>&1
				apk add sing-box >> "$ulog" 2>&1
			else
				opkg update >/dev/null 2>&1
				opkg install sing-box >> "$ulog" 2>&1
			fi
			rc=1
		fi
		if [ "$want_engine" = 1 ] && ! sb_engine; then
			rc=1
			echo "podkop-engine не установился" >> "$ulog"
		fi
	fi
	rm -f "$f"
	# Совет разработчика podkop: после обновления очистить кэш LuCI
	rm -rf /tmp/luci-*
	new=$(podkop show_version 2>/dev/null | head -n 1)
	new_sb=$(sing-box version 2>/dev/null | head -n 1 | awk '{print $3}')
	/etc/init.d/podkop restart >/dev/null 2>&1
	PODKOP_CHANGED=1
	eng_note=""
	sb_engine && eng_note=" (движок podkop-engine)"
	if [ "$rc" != 0 ] && [ "$new" = "$old" ] && [ "$new_sb" = "$old_sb" ]; then
		add_result "$id" false "установщик podkop ($via) остановился: $(grep -v '^[[:space:]]*$' "$ulog" | tail -n 2 | sed 's/\x1b\[[0-9;]*m//g' | tr '\n' ' ' | cut -c1-300)"
		return
	fi
	if [ "$new" = "$old" ] && [ "$new_sb" = "$old_sb" ]; then
		add_result "$id" true "podkop уже последней версии ($new), sing-box $new_sb$eng_note; кэш LuCI очищен"
	else
		add_result "$id" true "podkop $old → $new, sing-box $old_sb → $new_sb$eng_note (через $([ "$via" = github ] && echo GitHub || echo зеркало podkop)); кэш LuCI очищен"
	fi
}

# Перезагрузка по кнопке на сайте: сначала отправляем ответ, потом перезагружаемся (в run).
# Если ответ не дошёл и сервер прислал задачу снова — второй раз не перезагружаемся.
t_reboot() {
	id=$1
	if [ "$(cat "$CONF_DIR/reboot_id" 2>/dev/null)" = "$id" ]; then
		add_result "$id" true "роутер перезагружен"
		return
	fi
	echo "$id" > "$CONF_DIR/reboot_id"
	add_result "$id" true "перезагружаюсь — роутер вернётся на связь через 1–3 минуты"
	REBOOT_NOW=1
}

# Ключи по запросу владельца: ссылка подписки, все ключи из неё, одиночные ключи и настройки outbound.
# Уходят только в ответ на эту задачу; сайт показывает их владельцу и сразу забывает.
add_result_secret() { # id ok сообщение json
	mkdir -p "$CONF_DIR"
	printf '{"id":%s,"ok":%s,"msg":%s,"secret":%s}\n' "$(json_num "$1")" "$2" "$(json_str "$3")" "$4" >> "$RESULTS"
	log "задача #$1: $2 — $3"
	TASKS_DONE=1
}

keys_links_json() { # файл со ссылками → [{"name":…,"link":…}]
	kl_out=""
	while IFS= read -r kl_l; do
		case "$kl_l" in
			[a-z0-9]*://*) ;;
			*) continue ;;
		esac
		kl_n=""
		case "$kl_l" in *\#*) kl_n=$(urldecode "${kl_l#*#}") ;; esac
		kl_why=$(link_unsupported "$kl_l")
		kl_out="$kl_out${kl_out:+,}$(printf '{"name":%s,"link":%s,"why":%s}' "$(json_str "$kl_n")" "$(json_str "$kl_l")" "$(json_str "$kl_why")")"
	done < "$1"
	printf '[%s]' "$kl_out"
}

t_show_keys() {
	id=$1
	sk_only=$(task_field section)
	sb_engine && SB_XHTTP=1 || SB_XHTTP=0
	sk_out=""
	sk_n=0
	for sk_s in $(podkop_sections); do
		[ -n "$sk_only" ] && [ "$sk_s" != "$sk_only" ] && continue
		[ "$(opt "$sk_s" connection_type)" = proxy ] || continue
		sk_pc=$(opt "$sk_s" proxy_config_type)
		sk_key=""
		sk_ob=""
		sk_url=""
		sk_src=""
		: > "$RUN_DIR/keys.list"
		case "$sk_pc" in
			url) sk_key=$(opt "$sk_s" proxy_string) ;;
			outbound) sk_ob=$(opt "$sk_s" outbound_json) ;;
		esac
		if [ -s "$SUB_DIR/$sk_s.url" ]; then
			sk_url=$(cat "$SUB_DIR/$sk_s.url")
			# Все ключи подписки, как их видит приложение; не скачалась — те, что сейчас в работе
			if sub_fetch "$sk_url" "$RUN_DIR/keys.all"; then
				grep -E '^[a-z0-9]+://' "$RUN_DIR/keys.all" > "$RUN_DIR/keys.list"
				sk_src=fresh
			else
				cp "$SUB_DIR/$sk_s.links" "$RUN_DIR/keys.list" 2>/dev/null
				sk_src=saved
			fi
			rm -f "$RUN_DIR/keys.all"
		elif [ "$sk_pc" = urltest ] || [ "$sk_pc" = selector ]; then
			uci -q get "podkop.$sk_s.${sk_pc}_proxy_links" | tr ' ' '\n' > "$RUN_DIR/keys.list"
		fi
		sk_out="$sk_out${sk_out:+,}$(printf '{"name":%s,"key":%s,"outbound":%s,"sub_url":%s,"src":%s,"links":%s}' \
			"$(json_str "$sk_s")" "$(json_str "$sk_key")" "$(json_str "$sk_ob")" "$(json_str "$sk_url")" "$(json_str "$sk_src")" \
			"$(keys_links_json "$RUN_DIR/keys.list")")"
		sk_n=$((sk_n + 1))
	done
	rm -f "$RUN_DIR/keys.list"
	if [ "$sk_n" = 0 ]; then
		add_result "$id" false "${sk_only:+в секции $sk_only }нет ключей прокси"
		return
	fi
	add_result_secret "$id" true "ключи переданы на сайт (секций: $sk_n)" "{\"sections\":[$sk_out]}"
}

# ---------- Сервис включён / выключен ----------
# Сторож и автоматика подписок работают только при активной подписке и пока роутер на связи с сервисом.
# podkop не трогаем: интернет работает с последними настройками.

SVC_OFF="$CONF_DIR/svc_off"
WD_SVC_OFF="/etc/inet-watchdog/svc_off"
LAST_OK="$CONF_DIR/last_ok"
OFFLINE_LIMIT=1800 # 30 минут без связи с сервисом при работающем интернете

svc_set() { # on|off [причина]
	if [ "$1" = "on" ]; then
		[ -f "$SVC_OFF" ] || return 0
		rm -f "$SVC_OFF" "$WD_SVC_OFF"
		log "сервис снова включён — запускаю сторожа"
		if [ -x /etc/init.d/inet-watchdog ]; then
			/etc/init.d/inet-watchdog enable
			/etc/init.d/inet-watchdog restart >/dev/null 2>&1
		fi
		return 0
	fi
	[ "$(cat "$SVC_OFF" 2>/dev/null)" = "$2" ] && return 0
	mkdir -p "$CONF_DIR" /etc/inet-watchdog
	printf '%s' "$2" > "$SVC_OFF"
	printf '%s' "$2" > "$WD_SVC_OFF"
	log "сервис выключен: $2"
	if [ -x /etc/init.d/inet-watchdog ]; then
		/etc/init.d/inet-watchdog stop >/dev/null 2>&1
		/etc/init.d/inet-watchdog disable
	fi
}

# После удачного выхода на связь
svc_seen() {
	now=$(date +%s)
	echo "$now" > "$RUN_DIR/last_ok"
	# На флэш — не чаще раза в 20 минут (чтобы после перезагрузки знать, когда была связь)
	[ $((now - $(cat "$LAST_OK" 2>/dev/null || echo 0))) -ge 1200 ] && echo "$now" > "$LAST_OK"
	SVC_UNINSTALL=0
	[ "$(jsonfilter -s "$1" -e '@.uninstall' 2>/dev/null)" = "true" ] && SVC_UNINSTALL=1
	case "$(jsonfilter -s "$1" -e '@.svc' 2>/dev/null)" in
		on) svc_set on ;;
		off) svc_set off "$(jsonfilter -s "$1" -e '@.svc_reason' 2>/dev/null | head -c 200)" ;;
	esac
}

# Сервис долго недоступен, а интернет есть — значит, доступ к нему закрыт: выключаем наши службы
svc_offline_check() {
	last=$(cat "$RUN_DIR/last_ok" 2>/dev/null || cat "$LAST_OK" 2>/dev/null)
	if [ -z "$last" ]; then
		date +%s > "$LAST_OK"
		return 1
	fi
	[ $(($(date +%s) - last)) -lt "$OFFLINE_LIMIT" ] && return 1
	sub_inet_ok || return 1
	nslookup raw.githubusercontent.com >/dev/null 2>&1 || return 1
	svc_set off "нет связи с сервисом больше 30 минут при работающем интернете — для работы сервиса нужен постоянный доступ к нему"
	return 1
}

# Кабинет удалён или роутер удалён из системы: убираем сторожа и агента (podkop и его настройки остаются)
self_uninstall() {
	log "роутер удалён из сервиса — удаляю сторожа и агента (podkop остаётся)"
	[ -x /usr/bin/inet-watchdog ] && /usr/bin/inet-watchdog uninstall >/dev/null 2>&1
	rm -rf /etc/inet-watchdog
	( trap '' HUP TERM; sleep 2; do_uninstall ) </dev/null >/dev/null 2>&1 &
}

# ---------- Режимы ----------

run() {
	load_conf
	if [ -z "$SERVER" ]; then
		log "не задан адрес сервера — переустановите агент"
		sleep 3600
		exit 1
	fi
	log "запущен v$VERSION, сервер $SERVER"
	# После загрузки роутера даём подняться сети и podkop
	up=$(cut -d. -f1 /proc/uptime)
	[ "$up" -lt 180 ] && sleep $((180 - up))

	# С 2.11 допуск «держаться за текущий сервер» — 1000 (LuCI не принимает больше): правим старые 30000 сразу
	mig=""
	for f in "$SUB_DIR"/*.sticky; do
		[ -e "$f" ] || continue
		s=${f##*/}
		s=${s%.sticky}
		if [ "$(uci -q get "podkop.$s.urltest_tolerance")" = 30000 ]; then
			uci set "podkop.$s.urltest_tolerance=1000"
			mig=1
		fi
	done
	if [ -n "$mig" ]; then
		uci commit podkop
		/etc/init.d/podkop reload >/dev/null 2>&1
		watch_check silent # своё изменение — не сообщать как «изменено не через панель»
		log "допуск автовыбора для «держаться за сервер»: 30000 → 1000 мс"
	fi

	while true; do
		TASKS_DONE=0
		sync_once || svc_offline_check
		# Результаты задач отправляем сразу, не дожидаясь следующего цикла
		[ "$TASKS_DONE" = 1 ] && sync_once
		if [ "$REBOOT_NOW" = 1 ]; then
			log "перезагрузка по команде из панели"
			[ -d /etc/inet-watchdog ] && echo "$(date '+%Y-%m-%d %H:%M:%S') [uptime $(cut -d. -f1 /proc/uptime)s] ПЕРЕЗАГРУЗКА: по команде из панели" >> /etc/inet-watchdog/history.log
			sync
			reboot
			sleep 120
		fi
		[ "$RESTART_FOR_UPDATE" = 1 ] && exit 0
		if [ "$STATUS" = "active" ] && sync_lock; then
			# Секцию для скачивания списков могли удалить в LuCI
			dl_proxy_guard && podkop_apply
			subs_tick
			strict_sync
			ip_tick
			sync_unlock
		fi
		if [ "$STATUS" = "removed" ] && [ "$SVC_UNINSTALL" = 1 ]; then
			self_uninstall
			exit 0
		fi
		case "$STATUS" in
			rejected|removed) sleep 3600 ;;
			pending) sleep 60 ;;
			*) agent_wait "$INTERVAL" ;;
		esac
	done
}

write_init() {
	cat > "$INIT" <<EOF
#!/bin/sh /etc/rc.common
# $NAME — агент удалённого управления podkop

START=99
USE_PROCD=1

start_service() {
	procd_open_instance
	procd_set_param command $BIN run
	procd_set_param respawn 3600 10 0
	procd_close_instance
}
EOF
	chmod 755 "$INIT"
}

install_files() {
	tr -d '\r' < "$0" > "$BIN.new" || return 1
	chmod 755 "$BIN.new"
	mv "$BIN.new" "$BIN"
	write_init
	for f in "$BIN" "$INIT" "$CONF_DIR/"; do
		grep -qxF "$f" /etc/sysupgrade.conf 2>/dev/null || echo "$f" >> /etc/sysupgrade.conf
	done
}

do_install() {
	[ "$(id -u)" = "0" ] || { echo "Нужно запускать от root"; exit 1; }
	for t in curl jsonfilter uci; do
		command -v "$t" >/dev/null || { echo "Не найден $t. Установите: opkg install $t (или apk add $t)"; exit 1; }
	done
	[ -x /etc/init.d/podkop ] || echo "Внимание: podkop не найден — агент будет работать, но управлять будет нечем."

	load_conf
	[ -n "$1" ] && SERVER=${1%/}
	[ -n "$2" ] && INVITE=$2
	case "$INVITE" in *[!a-f0-9]*) echo "Недопустимый код приглашения"; exit 1 ;; esac
	if [ -z "$SERVER" ]; then
		echo "Укажите адрес сервера: sh $0 install https://адрес-сервера (готовая команда — в панели: «Как подключить роутер»)"
		exit 1
	fi
	case "$SERVER" in
		http://*|https://*) ;;
		*) echo "Адрес сервера должен начинаться с http:// или https://"; exit 1 ;;
	esac
	case "$SERVER" in
		*[\"\'\ \\]*) echo "Недопустимый адрес сервера"; exit 1 ;;
	esac

	install_files || { echo "Не удалось скопировать агент"; exit 1; }
	save_conf
	"$INIT" enable
	"$INIT" restart
	echo "$NAME v$VERSION установлен, сервер: $SERVER"
	if [ -z "$TOKEN" ]; then
		echo "Роутер зарегистрируется в течение 3 минут после загрузки (или сразу, если он уже работает)."
		echo "Подтвердите его в панели: он появится в «Ждут подтверждения»."
	fi
}

do_uninstall() {
	[ -f "$INIT" ] && { "$INIT" stop; "$INIT" disable; }
	strict_off
	rm -f "$INIT" "$BIN" "$BIN.prev"
	rm -rf "$CONF_DIR" "$RUN_DIR"
	sed -i "\#$NAME#d" /etc/sysupgrade.conf 2>/dev/null
	echo "$NAME удалён."
}

do_status() {
	load_conf
	printf 'Агент: v%s\nСервер: %s\nЗарегистрирован: %s\n' "$VERSION" "${SERVER:-не задан}" "$([ -n "$TOKEN" ] && echo да || echo нет)"
	printf 'Статус на сервере: %s\n' "$(cat "$RUN_DIR/status" 2>/dev/null || echo неизвестно)"
	printf 'Последний выход на связь: %s\n' "$(cat "$RUN_DIR/last_sync" 2>/dev/null || echo не было)"
	printf 'Служба: '
	"$INIT" status 2>/dev/null || echo "не установлена"
	echo "--- Секции podkop"
	clash_api_addr
	echo "clash API: ${CLASH_ADDR:-не найден}"
	for s in $(podkop_sections); do
		ct=$(opt "$s" connection_type)
		if [ "$ct" = "proxy" ] || [ "$ct" = "vpn" ]; then
			section_delay "$s"
			if [ -n "$DELAY" ]; then echo "OK    $s — $DELAY мс"; else echo "FAIL  $s — $DELAY_ERR"; fi
		else
			echo "      $s — $ct"
		fi
	done
}

case "$1" in
	install)       do_install "$2" "$3" ;;
	install-files) install_files ;;
	uninstall)     do_uninstall ;;
	run)           run ;;
	sync)
		load_conf
		sync_once && echo "OK: статус на сервере — $STATUS"
		[ "$TASKS_DONE" = 1 ] && sync_once
		[ "$REBOOT_NOW" = 1 ] && { echo "перезагрузка по команде из панели"; sync; reboot; }
		true
		;;
	status)        do_status ;;
	*)
		echo "$NAME v$VERSION"
		echo "Использование: $0 {install [адрес-сервера]|uninstall|status|sync}"
		exit 1
		;;
esac
