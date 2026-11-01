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

VERSION="1.6"
NAME="tim-agent"
REPO_RAW="https://raw.githubusercontent.com/SmokePemoluxe/tim-routers"

BIN="/usr/bin/$NAME"
INIT="/etc/init.d/$NAME"
CONF_DIR="/etc/$NAME"
CONF="$CONF_DIR/config"
RESULTS="$CONF_DIR/results"
RUN_DIR="/tmp/$NAME"
TEST_URL="https%3A%2F%2Fwww.gstatic.com%2Fgenerate_204"

SERVER=""
TOKEN=""
INTERVAL=120
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
	(umask 077; printf 'SERVER="%s"\nTOKEN="%s"\n' "$SERVER" "$TOKEN" > "$CONF")
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
	if [ "$ct" = "proxy" ] || [ "$ct" = "vpn" ]; then
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
	printf '{"name":%s,"type":%s,"config":%s,"scheme":%s,"host":%s,"detour":%s,"delay":%s,"error":%s,"list_type":%s,"subnets":%s,"domains":%s,"community":%s,"remote":%s}' \
		"$(json_str "$s")" "$(json_str "$ct")" "$(json_str "$pc")" "$(json_str "$scheme")" "$(json_str "$host")" \
		"$(json_str "$detour")" "$(json_num "$DELAY")" "$(json_str "$DELAY_ERR")" "$(json_str "$lt")" \
		"$(printf '%s\n' "$subnets" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//' | json_list)" \
		"$(printf '%s\n' "$domains" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//' | json_list)" \
		"$(opt "$s" community_lists | tr ' ' '\n' | json_list)" \
		"$(opt "$s" remote_domain_lists | tr ' ' '\n' | json_list)"
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
	printf '{"agent":%s,"model":%s,"openwrt":%s,"podkop":%s,"singbox":%s,"hostname":%s,"uptime":%s,"mem_free":%s,"lan_ip":%s,"mac_lan":%s,"mac_wan":%s,"singbox_running":%s,"podkop_settings":{"dns_type":%s,"dns_server":%s,"bootstrap_dns_server":%s},"watchdog":' \
		"$(json_str "$VERSION")" \
		"$(json_str "$(jsonfilter -s "$board" -e '@.model' 2>/dev/null)")" \
		"$(json_str "$DISTRIB_RELEASE")" \
		"$(json_str "$(podkop show_version 2>/dev/null | head -n 1)")" \
		"$(json_str "$(sing-box version 2>/dev/null | head -n 1 | awk '{print $3}')")" \
		"$(json_str "$(uci -q get system.@system[0].hostname)")" \
		"$(json_num "$(cut -d. -f1 /proc/uptime)")" \
		"$(json_num "$(awk '/^MemTotal:/{t=$2} /^MemAvailable:/{a=$2} END{if (t) print int(a*100/t)}' /proc/meminfo)")" \
		"$(json_str "$(uci -q get network.lan.ipaddr | cut -d/ -f1)")" \
		"$(json_str "$(cat /sys/class/net/br-lan/address 2>/dev/null)")" \
		"$(json_str "$(wan_mac)")" \
		"$sb" \
		"$(json_str "$(uci -q get podkop.settings.dns_type)")" \
		"$(json_str "$(uci -q get podkop.settings.dns_server)")" \
		"$(json_str "$(uci -q get podkop.settings.bootstrap_dns_server)")"
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

# ---------- Обмен с сервером ----------

register() {
	mkdir -p "$RUN_DIR"
	{
		printf '{"info":'
		info_json
		printf '}'
	} > "$RUN_DIR/register.json"
	resp=$(http_post register "$RUN_DIR/register.json")
	t=$(jsonfilter -s "$resp" -e '@.token' 2>/dev/null)
	case "$t" in
		''|*[!0-9a-f]*)
			log "регистрация не удалась: ${resp:-сервер недоступен}"
			return 1
			;;
	esac
	TOKEN=$t
	save_conf
	log "зарегистрирован на $SERVER — подтвердите роутер в панели («Ждут подтверждения»)"
}

sync_once() {
	if [ -z "$TOKEN" ]; then
		register || return 1
	fi
	mkdir -p "$RUN_DIR"
	if [ -s "$RESULTS" ]; then
		cat "$RESULTS" >> "$RESULTS.sending"
		rm -f "$RESULTS"
	fi
	body="$RUN_DIR/sync.json"
	{
		printf '{"info":'
		info_json
		printf ',"sections":'
		sections_json
		printf ',"results":'
		results_json
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
	rm -f "$RESULTS.sending"
	date '+%Y-%m-%d %H:%M:%S' > "$RUN_DIR/last_sync"
	echo "$STATUS" > "$RUN_DIR/status"

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
			*) add_result "$id" false "агент $VERSION не знает задачу «$type» — обновите скрипты" ;;
		esac
		TASK_IDX=$((TASK_IDX + 1))
	done
	[ "$NEED_RESTART" = 1 ] && podkop_apply
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
podkop_apply() {
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
		else
			errors="$errors; нет секции $s для удаления"
		fi
	done

	# --- Секции ---
	SEC_IDX=0
	for name in $(task_field 'sections[*].name'); do
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

		if ! is_section "$name"; then
			case "$ct" in
				proxy)
					if [ -z "$key" ] && [ -z "$ob" ]; then
						errors="$errors; $name: для новой секции через прокси нужен ключ"
						SEC_IDX=$((SEC_IDX + 1))
						continue
					fi
					;;
				exclusion) ;;
				*)
					errors="$errors; $name: неизвестный тип «$ct»"
					SEC_IDX=$((SEC_IDX + 1))
					continue
					;;
			esac
			uci set "podkop.$name=section"
			uci set "podkop.$name.connection_type=$ct"
			if [ "$ct" = "proxy" ]; then
				snapshot_key "$name" new
				if [ -n "$ob" ]; then
					set_outbound "$name" "$ob"
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
					exclusion) uci set "podkop.$name.connection_type=exclusion" ;;
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

	# --- Порядок секций (важен: домен из двух секций обработает первая) ---
	uci -q reorder podkop.settings=0
	pos=1
	for name in $(task_field 'sections[*].name'); do
		if is_section "$name"; then
			uci reorder "podkop.$name=$pos"
			pos=$((pos + 1))
		fi
	done

	podkop_apply
	check_changed_keys

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
	podkop_apply_later
	add_result "$id" true "секция $s удалена"
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
		echo "===== Журнал podkop / sing-box / агента"
		logread 2>/dev/null | grep -iE "podkop|sing-box|$NAME" | tail -n 150
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
		if ! curl -sfL -m 90 -o "$d/$f" "$REPO_RAW/$ref/router/$f"; then
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

	while true; do
		TASKS_DONE=0
		sync_once
		# Результаты задач отправляем сразу, не дожидаясь следующего цикла
		[ "$TASKS_DONE" = 1 ] && sync_once
		[ "$RESTART_FOR_UPDATE" = 1 ] && exit 0
		case "$STATUS" in
			rejected|removed) sleep 3600 ;;
			pending) sleep 60 ;;
			*) sleep "$INTERVAL" ;;
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
	install)       do_install "$2" ;;
	install-files) install_files ;;
	uninstall)     do_uninstall ;;
	run)           run ;;
	sync)
		load_conf
		sync_once && echo "OK: статус на сервере — $STATUS"
		[ "$TASKS_DONE" = 1 ] && sync_once
		true
		;;
	status)        do_status ;;
	*)
		echo "$NAME v$VERSION"
		echo "Использование: $0 {install [адрес-сервера]|uninstall|status|sync}"
		exit 1
		;;
esac
