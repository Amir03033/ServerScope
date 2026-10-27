#!/bin/bash
# ServerScope bash agent 0.2.0 — for hosts or containers without Python or
# systemd. Reads /proc only (no root rights needed, no remote commands) and
# posts to /api/agent/metrics. Configuration in $DIR/.env:
#   SERVERSCOPE_URL=https://serverscope.nl
#   SERVERSCOPE_TOKEN=...
#   SERVERSCOPE_SERVICES=apache2,mariadb     (optional, process names)
#   SERVERSCOPE_INTERVAL=30                  (optional, seconds, >= 10)
# SERVERSCOPE_DRY_RUN=1 prints one payload and exits without sending.
DIR=${SERVERSCOPE_DIR:-$(cd "$(dirname "$0")" && pwd)}
set -a; . "$DIR/.env"; set +a
URL="${SERVERSCOPE_URL%/}/api/agent/metrics"
INTERVAL=${SERVERSCOPE_INTERVAL:-30}
[ "$INTERVAL" -ge 10 ] 2>/dev/null || INTERVAL=30
VERSION="0.2.0"
CHECKSUM=$(sha256sum "$0" | cut -c1-64)

read_cpu() { awk '/^cpu /{idle=$5+$6; t=0; for(i=2;i<=NF;i++) t+=$i; print idle, t}' /proc/stat; }

services_json() {
  local out="" name state
  IFS=',' read -ra names <<< "${SERVERSCOPE_SERVICES:-}"
  for name in "${names[@]}"; do
    name=$(echo "$name" | tr -d '[:space:]')
    [[ "$name" =~ ^[A-Za-z0-9@._:-]{1,100}$ ]] || continue
    if pgrep -x "${name%.service}" >/dev/null 2>&1; then state=running; else state=stopped; fi
    out+=$(printf '%s{"name":"%s","state":"%s","detail":"proces %s"}' "${out:+,}" "$name" "$state" "$([ $state = running ] && echo gevonden || echo niet gevonden)")
  done
  echo "$out"
}

prev=($(read_cpu))
[ -n "$SERVERSCOPE_DRY_RUN" ] && sleep 1

while true; do
  [ -z "$SERVERSCOPE_DRY_RUN" ] && sleep "$INTERVAL"
  cur=($(read_cpu))
  didle=$((cur[0]-prev[0])); dtotal=$((cur[1]-prev[1])); prev=("${cur[@]}")
  if [ "$dtotal" -gt 0 ]; then
    cpu=$(awk -v i="$didle" -v t="$dtotal" 'BEGIN{printf "%.1f",(1-i/t)*100}')
  else cpu=0; fi

  read -r l1 l5 l15 _ < /proc/loadavg
  mem_total=$(awk '/^MemTotal:/{printf "%.0f",$2*1024}' /proc/meminfo)
  mem_avail=$(awk '/^MemAvailable:/{printf "%.0f",$2*1024}' /proc/meminfo)
  mem_used=$((mem_total-mem_avail))
  swap_total=$(awk '/^SwapTotal:/{printf "%.0f",$2*1024}' /proc/meminfo)
  swap_free=$(awk '/^SwapFree:/{printf "%.0f",$2*1024}' /proc/meminfo)
  swap_used=$((swap_total-swap_free))

  read -r dsize dused davail < <(df -B1 --output=size,used,avail / | tail -1)
  dpct=$(awk -v u="$dused" -v a="$davail" 'BEGIN{printf "%.1f", (u+a>0)?u/(u+a)*100:0}')

  # Network: bytes, errors and drops of all interfaces except loopback.
  read -r rx tx rxerr txerr rxdrop txdrop < <(sed 's/:/ /' /proc/net/dev | awk 'NR>2 && $1!="lo"{r+=$2;re+=$4;rd+=$5;t+=$10;te+=$12;td+=$13} END{printf "%.0f %.0f %.0f %.0f %.0f %.0f",r,t,re,te,rd,td}')

  # Disk I/O of whole disks (not partitions): sectors x 512 bytes.
  read -r dread dwrite < <(awk '$3 ~ /^(sd[a-z]+|vd[a-z]+|xvd[a-z]+|nvme[0-9]+n[0-9]+|mmcblk[0-9]+)$/ {r+=$6;w+=$10} END{printf "%.0f %.0f",r*512,w*512}' /proc/diskstats 2>/dev/null || echo "0 0")

  procs=$(ls -d /proc/[0-9]* 2>/dev/null | wc -l)
  up=$(cut -d. -f1 /proc/uptime)
  ts=$(date -u +%Y-%m-%dT%H:%M:%SZ)
  svc=$(services_json)
  services_field=""
  [ -n "${SERVERSCOPE_SERVICES:-}" ] && services_field=",\"services\":[${svc}]"

  json=$(printf '{"collected_at":"%s","cpu_percent":%s,"load_1":%s,"load_5":%s,"load_15":%s,"memory_total_bytes":%s,"memory_used_bytes":%s,"memory_available_bytes":%s,"swap_total_bytes":%s,"swap_used_bytes":%s,"disks":[{"path":"/","total_bytes":%s,"used_bytes":%s,"available_bytes":%s,"percent":%s}],"disk_read_bytes":%s,"disk_write_bytes":%s,"network_rx_bytes":%s,"network_tx_bytes":%s,"network_errors_in":%s,"network_errors_out":%s,"network_drops_in":%s,"network_drops_out":%s,"process_count":%s,"uptime_seconds":%s,"agent_version":"%s","agent_checksum":"%s"%s}' \
    "$ts" "$cpu" "$l1" "$l5" "$l15" "$mem_total" "$mem_used" "$mem_avail" "$swap_total" "$swap_used" "$dsize" "$dused" "$davail" "$dpct" \
    "$dread" "$dwrite" "$rx" "$tx" "$rxerr" "$txerr" "$rxdrop" "$txdrop" "$procs" "$up" "$VERSION" "$CHECKSUM" "$services_field")

  if [ -n "$SERVERSCOPE_DRY_RUN" ]; then echo "$json"; exit 0; fi

  code=$(curl -sS -m 10 -o "$DIR/last.out" -w "%{http_code}" -X POST "$URL" \
    -H "Authorization: Bearer $SERVERSCOPE_TOKEN" \
    -H "Accept: application/json" -H "Content-Type: application/json" -d "$json")
  echo "$(date -u +%FT%TZ) HTTP $code"
done
