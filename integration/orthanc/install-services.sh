#!/usr/bin/env bash
# Installer service OCT↔Arumed (feeder + bridge). Jalankan: sudo bash install-services.sh
# Idempoten — aman diulang. Membaca file dari folder yang sama (~/arumed-oct).
set -e
SRC="$(cd "$(dirname "$0")" && pwd)"

echo "== 1) Salin skrip ke /opt/arumed-oct =="
install -d /opt/arumed-oct
install -m 0755 "$SRC/worklist-feeder.py" /opt/arumed-oct/worklist-feeder.py
install -m 0755 "$SRC/oct-bridge.py"      /opt/arumed-oct/oct-bridge.py

echo "== 2) Pasang env (token rahasia) ke /etc/arumed-oct.env (600) =="
install -m 0600 "$SRC/arumed-oct.env" /etc/arumed-oct.env

echo "== 3) State dir bridge =="
install -d /var/lib/arumed-oct

echo "== 4) Pasang unit systemd =="
install -m 0644 "$SRC/systemd/arumed-oct-feeder.service" /etc/systemd/system/
install -m 0644 "$SRC/systemd/arumed-oct-feeder.timer"   /etc/systemd/system/
install -m 0644 "$SRC/systemd/arumed-oct-bridge.service" /etc/systemd/system/
systemctl daemon-reload

echo "== 5) Aktifkan feeder (timer 60s) + bridge =="
systemctl enable --now arumed-oct-feeder.timer
systemctl start  arumed-oct-feeder.service
systemctl enable --now arumed-oct-bridge.service

echo "== 6) Status =="
sleep 1
systemctl is-active arumed-oct-bridge && echo "bridge: aktif"
echo "--- feeder run terakhir ---"
journalctl -u arumed-oct-feeder -n 6 --no-pager 2>/dev/null | tail -6
echo "--- isi folder worklist ---"
ls -la /var/lib/orthanc/worklists/ 2>/dev/null
echo "SELESAI."
