# OCT Maestro → Orthanc → Arumed (Fase E)

OCT Maestro (IMAGEnet, `192.168.100.21`) berbicara **DICOM penuh** (Modality Worklist +
Storage). Arumed tidak bicara DICOM — perantaranya **Orthanc** di server (`192.168.100.20`):

```
OCT Maestro ──C-FIND MWL──►  Orthanc (4242)  ◄── feeder  ◄── Arumed /worklist
            ──C-STORE────►  Orthanc (4242)  ──► bridge   ──► Arumed /ingest
```

- **feeder** (`worklist-feeder.py`): tarik order hari ini dari Arumed → tulis file `.wl` → Orthanc layani worklist ke OCT.
- **bridge** (`oct-bridge.py`): study yang masuk Orthanc → render JPG / ambil PDF → POST ke Arumed `/ingest` (match `accession_number`).

Pendekatan **bertahap & diuji per langkah** — jangan loncat; tiap langkah punya verifikasi.

> Prasyarat (SUDAH beres): `PENUNJANG_BRIDGE_TOKEN` terset di `.env` Arumed; endpoint `/worklist` & `/ingest` live.

---

## Langkah 1 — Install Orthanc + plugin Worklist + dcmtk (server, sudo)
```bash
sudo apt update
sudo apt install -y orthanc orthanc-imagej dcmtk
# Plugin Worklists: cek apakah sudah ada (biasanya ikut paket orthanc):
ls /usr/share/orthanc/plugins/ | grep -i worklist
# Jika TIDAK ada, cari paketnya:
apt-cache search orthanc | grep -i worklist     # mis. 'orthanc-worklists' → sudo apt install -y orthanc-worklists
```
Verifikasi: `dpkg -l | grep orthanc`, `which dump2dcm echoscu`.

## Langkah 2 — Pasang konfig Orthanc (server, sudo)
```bash
# dari folder repo ini (salin dulu ke server, mis. ke ~/arumed-oct/)
sudo cp orthanc-arumed.json /etc/orthanc/arumed.json
sudo mkdir -p /var/lib/orthanc/worklists
sudo chown -R orthanc:orthanc /var/lib/orthanc/worklists
sudo systemctl restart orthanc
sudo systemctl status orthanc --no-pager | head -8
```
Verifikasi Orthanc hidup + plugin worklist termuat:
```bash
ss -ltn | grep -E ':4242|:8042'                       # 4242 (DICOM) & 8042 (REST lokal) LISTEN
curl -s http://127.0.0.1:8042/plugins | tr ',' '\n' | grep -i worklist
echoscu -aec ORTHANC 127.0.0.1 4242                   # C-ECHO lokal → harus sukses (Association Release)
```

## Langkah 3 — Stage skrip feeder & bridge (server, sudo)
```bash
sudo mkdir -p /opt/arumed-oct
sudo cp worklist-feeder.py oct-bridge.py /opt/arumed-oct/
sudo cp arumed-oct.env /etc/arumed-oct.env
sudo chmod 600 /etc/arumed-oct.env          # berisi token rahasia
sudo chown root:root /etc/arumed-oct.env
# pastikan token di /etc/arumed-oct.env == PENUNJANG_BRIDGE_TOKEN di .env Arumed
```

## Langkah 4 — Uji FEEDER (server) — sebelum sentuh alat
Buat dulu **1 order penunjang OCT** untuk pasien uji di Arumed (dokter → order penunjang
jenis OCT, atau langsung di dev). Lalu:
```bash
sudo bash -c 'set -a; . /etc/arumed-oct.env; set +a; python3 /opt/arumed-oct/worklist-feeder.py'
ls -l /var/lib/orthanc/worklists/                 # harus muncul <accession>.wl
dcmdump /var/lib/orthanc/worklists/*.wl | head -30   # cek PatientName/PatientID/Modality/Accession benar
```
> Bila `dump2dcm` mengeluh format, sesuaikan `dump_text()` di `worklist-feeder.py` lalu ulang (ini titik yang paling mungkin perlu di-tweak per versi dcmtk).

## Langkah 5 — Konfig IMAGEnet di PC OCT `192.168.100.21` (GUI, manual)
Di tab **DICOM** IMAGEnet (`localhost/IMAGEnet/GeneralConfig`):
- **Worklist**: Server AE Title `ORTHANC`, Server IP `192.168.100.20`, Server Port `4242`, Client AE Title `MAESTRO` → tekan **Verification** (harus sukses = C-FIND/C-ECHO OK).
- **Storage**: Server AE Title `ORTHANC`, Server IP `192.168.100.20`, Server Port `4242`, Client AE Title `MAESTRO`, OCT Data Storage Type `OP + OPT` → **Verification**.
> Jika Verification gagal: cek firewall server (port 4242 dari `.21`), AE Title cocok, dan `DicomCheckCalledAet:false` (sudah di konfig).

## Langkah 6 — Uji WORKLIST dari alat
Di OCT, buka layar Worklist/Query → harus muncul pasien yang ordernya kamu buat (Langkah 4).
Pilih pasien → mulai pemeriksaan.

## Langkah 7 — Uji STORAGE (scan → Orthanc)
Lakukan 1 scan → kirim. Cek di server study masuk:
```bash
curl -s http://127.0.0.1:8042/studies | python3 -m json.tool | head
# atau buka web UI lewat SSH tunnel: ssh -L 8042:127.0.0.1:8042 vision@192.168.100.20 → http://localhost:8042
```

## Langkah 8 — Aktifkan BRIDGE + FEEDER otomatis (server, sudo)
```bash
sudo cp systemd/arumed-oct-bridge.service /etc/systemd/system/
sudo cp systemd/arumed-oct-feeder.service /etc/systemd/system/
sudo cp systemd/arumed-oct-feeder.timer   /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now arumed-oct-bridge.service
sudo systemctl enable --now arumed-oct-feeder.timer
sudo systemctl status arumed-oct-bridge --no-pager | head -8
journalctl -u arumed-oct-bridge -f          # pantau: "INGEST acc=... -> HTTP 201"
```

## Langkah 9 — Verifikasi END-TO-END
Order OCT di Arumed → muncul di worklist alat → scan → Orthanc terima → bridge POST →
hasil tertaut ke order (atau Inbox bila accession tak match) → tampil di **Hasil Penunjang** dokter.

---

## Catatan / troubleshooting
- **Keamanan**: REST Orthanc (8042) hanya localhost (`RemoteAccessAllowed:false`); bridge jalan lokal. Hanya DICOM 4242 yang terbuka ke LAN (untuk OCT).
- **Firewall**: jika `.21` tak bisa C-ECHO ke 4242, cek `sudo ufw status` / iptables di server.
- **Encapsulated PDF**: bridge otomatis kirim PDF laporan bila OCT mengirim DICOM PDF; selain itu render JPG.
- **Idempoten**: bridge kirim `external_ref=StudyInstanceUID` → Arumed tak menggandakan bila terkirim ulang.
- **Match**: hasil tertaut via `accession_number` (paling andal). Pastikan worklist yang dipakai alat memang dari feeder (bukan input manual), agar accession ikut ke study.
- File: `orthanc-arumed.json`, `arumed-oct.env`, `worklist-feeder.py`, `oct-bridge.py`, `systemd/*`.
