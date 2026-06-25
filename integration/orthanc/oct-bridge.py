#!/usr/bin/env python3
"""
Bridge OCT: study yang masuk ke Orthanc -> kirim ke Arumed /ingest.

Pantau Orthanc REST /changes (localhost). Saat sebuah STUDY stabil (selesai
diterima), ambil AccessionNumber + 1 berkas representatif:
  - bila instance = Encapsulated PDF  -> ambil PDF aslinya (lebih kaya: laporan OCT)
  - selain itu                        -> render gambar JPG (ringan)
lalu POST multipart ke Arumed:
  POST {ARUMED}/api/v1/integrasi/penunjang/ingest
       file=<jpg|pdf>, accession_number=<acc>, source=OCT, external_ref=<StudyUID>
Arumed mencocokkan accession -> order OCT (idempoten via external_ref).

Jalan sebagai service loop. Hanya stdlib + `curl` (untuk multipart).
ENV (arumed-oct.env): ORTHANC_URL, ARUMED_BASE, PENUNJANG_BRIDGE_TOKEN, INGEST_SOURCE.
"""
import os, sys, json, time, tempfile, subprocess, urllib.request, urllib.error

ORTHANC = os.environ.get("ORTHANC_URL", "http://127.0.0.1:8042").rstrip("/")
ARUMED  = os.environ.get("ARUMED_BASE", "http://127.0.0.1:8000").rstrip("/")
TOKEN   = os.environ.get("PENUNJANG_BRIDGE_TOKEN", "")
SOURCE  = os.environ.get("INGEST_SOURCE", "OCT")
STATE   = os.environ.get("BRIDGE_STATE", "/var/lib/arumed-oct/last_change")
POLL    = int(os.environ.get("BRIDGE_POLL_SECONDS", "10"))
INGEST  = ARUMED + "/api/v1/integrasi/penunjang/ingest"

PDF_SOP = "1.2.840.10008.5.1.4.1.1.104.1"  # Encapsulated PDF Storage


def oget(path, accept=None):
    req = urllib.request.Request(ORTHANC + path)
    if accept:
        req.add_header("Accept", accept)
    with urllib.request.urlopen(req, timeout=30) as r:
        return r.read()


def ojson(path):
    return json.loads(oget(path).decode("utf-8", "replace"))


def load_seq():
    try:
        with open(STATE) as f:
            return int(f.read().strip() or "0")
    except Exception:
        return 0


def save_seq(seq):
    os.makedirs(os.path.dirname(STATE), exist_ok=True)
    with open(STATE, "w") as f:
        f.write(str(seq))


def pick_file(study_id):
    """Kembalikan (path_tmp, field_suffix) berkas representatif study, atau (None,None)."""
    instances = ojson("/studies/%s/instances" % study_id)
    if not instances:
        return None, None
    # Utamakan instance PDF (laporan), else instance pertama yang bisa dirender.
    pdf_iid = None
    for ins in instances:
        sop = (ins.get("MainDicomTags", {}) or {}).get("SOPClassUID", "")
        if sop == PDF_SOP:
            pdf_iid = ins["ID"]
            break
    if pdf_iid:
        data = oget("/instances/%s/content/0042-0011" % pdf_iid)  # EncapsulatedDocument
        t = tempfile.NamedTemporaryFile(suffix=".pdf", delete=False)
        t.write(data); t.close()
        return t.name, "pdf"
    # Render JPG dari instance pertama.
    iid = instances[0]["ID"]
    try:
        data = oget("/instances/%s/rendered" % iid, accept="image/jpeg")
    except urllib.error.HTTPError:
        data = oget("/instances/%s/preview" % iid)  # PNG fallback
    suffix = ".jpg" if data[:2] == b"\xff\xd8" else ".png"
    t = tempfile.NamedTemporaryFile(suffix=suffix, delete=False)
    t.write(data); t.close()
    return t.name, "img"


def study_uids(study_id):
    """UID DICOM (series + instance representatif) untuk ImagingStudy SATUSEHAT.
    Ambil series pertama + instance pertamanya. Kembalikan ('','','') bila tak ada."""
    try:
        series = ojson("/studies/%s/series" % study_id) or []
        if not series:
            return "", "", ""
        s0 = series[0]
        series_uid = (s0.get("MainDicomTags", {}) or {}).get("SeriesInstanceUID", "")
        instances = ojson("/series/%s/instances" % s0["ID"]) or []
        if not instances:
            return series_uid, "", ""
        i0 = (instances[0].get("MainDicomTags", {}) or {})
        return series_uid, i0.get("SOPInstanceUID", ""), i0.get("SOPClassUID", "")
    except Exception:
        return "", "", ""


def send(study_id):
    tags = (ojson("/studies/%s" % study_id).get("MainDicomTags", {}) or {})
    acc  = tags.get("AccessionNumber", "")
    suid = tags.get("StudyInstanceUID", study_id)
    if not acc:
        print("SKIP study %s: tanpa AccessionNumber" % study_id, flush=True)
        return
    path, kind = pick_file(study_id)
    if not path:
        print("SKIP study %s: tanpa berkas" % study_id, flush=True)
        return
    series_uid, sop_uid, sop_class = study_uids(study_id)
    try:
        cmd = [
            "curl", "-s", "-S", "-o", "/dev/null", "-w", "%{http_code}",
            "-X", "POST", INGEST,
            "-H", "Authorization: Bearer " + TOKEN,
            "-H", "Accept: application/json",
            "-F", "file=@%s" % path,
            "-F", "accession_number=%s" % acc,
            "-F", "source=%s" % SOURCE,
            "-F", "external_ref=%s" % suid,
            # UID DICOM untuk ImagingStudy SATUSEHAT (backend abaikan bila kosong).
            "-F", "series_instance_uid=%s" % series_uid,
            "-F", "sop_instance_uid=%s" % sop_uid,
            "-F", "sop_class_uid=%s" % sop_class,
        ]
        code = subprocess.run(cmd, capture_output=True, text=True).stdout.strip()
        print("INGEST acc=%s study=%s (%s) -> HTTP %s" % (acc, study_id, kind, code), flush=True)
    finally:
        try: os.unlink(path)
        except OSError: pass


def main():
    if not TOKEN:
        print("ERROR: PENUNJANG_BRIDGE_TOKEN kosong", file=sys.stderr); return 2
    print("OCT bridge mulai. Orthanc=%s -> Arumed=%s" % (ORTHANC, INGEST), flush=True)
    seq = load_seq()
    while True:
        try:
            done = False
            while not done:
                ch = ojson("/changes?since=%d&limit=100" % seq)
                for c in ch.get("Changes", []):
                    if c.get("ChangeType") == "StableStudy":
                        try:
                            send(c["ID"])
                        except Exception as e:
                            print("ERROR kirim study %s: %s" % (c.get("ID"), e), file=sys.stderr, flush=True)
                seq = ch.get("Last", seq)
                done = ch.get("Done", True)
                save_seq(seq)
        except Exception as e:
            print("WARN loop: %s" % e, file=sys.stderr, flush=True)
        time.sleep(POLL)


if __name__ == "__main__":
    sys.exit(main())
