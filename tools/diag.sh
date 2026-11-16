#!/bin/sh
# Diagnostics for planning the router agent. Output is ASCII; secrets are masked.
# Run on the router:  sh /tmp/diag.sh > /tmp/diag.txt

mask() {
	sed -E "s#(hysteria2|hy2|vless|vmess|ss|trojan|tuic|socks5?|https?)://[^' \"]*#\1://HIDDEN#g; s#(\"(server|password|username|uuid|server_port|private_key|public_key|short_id)\" *: *)(\"[^\"]*\"|[0-9]+)#\1\"HIDDEN\"#g"
}

section() {
	echo
	echo "===== $1"
}

section "system"
grep -E '^DISTRIB_(RELEASE|TARGET)' /etc/openwrt_release
ubus call system board 2>/dev/null | grep -E '"(model|board_name|kernel)"'
free

section "packages"
(opkg list-installed 2>/dev/null; apk list -I 2>/dev/null) | grep -iE 'podkop|sing-box|curl|jsonfilter|usign|uclient|ustream'

section "tools"
for t in wget uclient-fetch curl jsonfilter usign sha256sum md5sum timeout base64; do
	printf '%-14s %s\n' "$t" "$(command -v "$t" || echo MISSING)"
done

section "podkop version"
podkop show_version 2>&1 | head -5
podkop show_sing_box_version 2>&1 | head -5

section "podkop uci (masked)"
uci show podkop | mask

section "sing-box outbound tags"
grep -E '"tag"' /etc/sing-box/config.json | sed 's/^ *//' | sort -u

API=$(grep -A3 '"clash_api"' /etc/sing-box/config.json | sed -n 's/.*"external_controller": *"\([^"]*\)".*/\1/p')
section "clash_api: $API"
wget -qO- "http://$API/proxies" 2>&1 | mask | head -c 4000
echo

section "delay per outbound"
for s in $(uci show podkop | sed -n "s/^podkop\.\([^.=]*\)=section$/\1/p"); do
	printf '%s-out: ' "$s"
	wget -qO- -T 10 "http://$API/proxies/$s-out/delay?timeout=5000&url=https%3A%2F%2Fwww.gstatic.com%2Fgenerate_204" 2>&1 | mask
	echo
done

section "podkop get_status"
podkop get_status 2>&1 | mask | head -20

section "podkop check_proxy"
podkop check_proxy 2>&1 | mask | head -40

section "podkop get_sing_box_features"
podkop get_sing_box_features 2>&1 | head -c 1500
echo
