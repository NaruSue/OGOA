#!/usr/bin/env bash
set -u

END_TS="$(date -d 2026-07-14T00:00:00 +%s)"
NOW_TS="$(date +%s)"

if [ "$NOW_TS" -ge "$END_TS" ]; then
  exit 0
fi

{
  echo "===== $(date '+%F %T %z') ====="
  echo "-- ip route --"
  ip route
  echo "-- ping gateway 157.7.140.1 --"
  ping -c 1 -W 2 157.7.140.1 >/dev/null 2>&1 && echo OK || echo NG
  echo "-- ping 1.1.1.1 --"
  ping -c 1 -W 2 1.1.1.1 >/dev/null 2>&1 && echo OK || echo NG
  echo "-- getent loose.bz --"
  getent hosts loose.bz || true
  echo "-- curl loose.bz --"
  curl -I --max-time 5 https://loose.bz >/dev/null 2>&1 && echo OK || echo NG
  echo "-- curl 1.1.1.1 --"
  curl -I --max-time 5 http://1.1.1.1 >/dev/null 2>&1 && echo OK || echo NG
  echo
} >> /var/log/netdiag.log 2>&1
