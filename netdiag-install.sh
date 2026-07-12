#!/usr/bin/env bash
set -eu

install -m 755 /tmp/netdiag.sh /usr/local/sbin/netdiag.sh
cat >/etc/cron.d/netdiag <<'EOF'
*/10 * * * * root /usr/local/sbin/netdiag.sh
EOF
chmod 644 /etc/cron.d/netdiag
/usr/local/sbin/netdiag.sh
systemctl restart cron || true
ls -l /usr/local/sbin/netdiag.sh /etc/cron.d/netdiag /var/log/netdiag.log
