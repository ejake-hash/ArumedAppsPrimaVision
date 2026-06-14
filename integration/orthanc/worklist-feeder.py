#!/usr/bin/env python3
"""
Feeder Modality Worklist: Arumed worklist JSON -> file DICOM .wl untuk Orthanc.

Ambil GET /api/v1/integrasi/penunjang/worklist (Bearer token), ubah tiap order
menjadi file .wl (via dcmtk `dump2dcm`) di folder Worklists Orthanc. Di-regenerate
penuh tiap run (file lama dihapus) supaya worklist selalu cermin order hari ini.

Dijalankan periodik (systemd timer / cron, mis. tiap 60 dtk). Hanya stdlib + dcmtk.

ENV (lihat arumed-oct.env): ARUMED_BASE, PENUNJANG_BRIDGE_TOKEN, WORKLIST_DIR,
OCT_AET, WL_MODALITY.
"""
import os, sys, json, glob, hashlib, subprocess, tempfile, urllib.request, urllib.error

ARUMED   = os.environ.get("ARUMED_BASE", "http://127.0.0.1:8000").rstrip("/")
TOKEN    = os.environ.get("PENUNJANG_BRIDGE_TOKEN", "")
WLDIR    = os.environ.get("WORKLIST_DIR", "/var/lib/orthanc/worklists")
OCT_AET  = os.environ.get("OCT_AET", "MAESTRO")
MODALITY = os.environ.get("WL_MODALITY", "").strip()


def fetch_worklist():
    url = ARUMED + "/api/v1/integrasi/penunjang/worklist"
    if MODALITY:
        url += "?modality=" + MODALITY
    req = urllib.request.Request(url, headers={
        "Authorization": "Bearer " + TOKEN,
        "Accept": "application/json",
    })
    with urllib.request.urlopen(req, timeout=20) as r:
        payload = json.load(r)
    return payload.get("data", []) or []


def study_uid(accession):
    """UID deterministik per accession (2.25.<int> = OID untuk UUID/angka, valid DICOM)."""
    h = int(hashlib.md5(accession.encode()).hexdigest()[:18], 16)
    return "2.25.%d" % h


def dump_text(row):
    name = (row.get("patient_name") or "UNKNOWN").upper().replace("[", "(").replace("]", ")")
    pid  = row.get("no_rm") or ""
    dob  = (row.get("dob") or "").replace("-", "")
    sex  = row.get("gender") or "O"
    acc  = row.get("accession_number") or ""
    mod  = row.get("modality") or "OT"
    desc = (row.get("test_name") or row.get("test_code") or "").replace("[", "(").replace("]", ")")
    sdat = (row.get("scheduled_date") or "").replace("-", "")
    suid = study_uid(acc)
    # Format dump dcmtk (dump2dcm). Sequence ScheduledProcedureStepSequence (0040,0100).
    return (
        "(0008,0005) CS [ISO_IR 100]\n"
        "(0008,0050) SH [%s]\n" % acc +
        "(0010,0010) PN [%s]\n" % name +
        "(0010,0020) LO [%s]\n" % pid +
        "(0010,0030) DA [%s]\n" % dob +
        "(0010,0040) CS [%s]\n" % sex +
        "(0020,000d) UI [%s]\n" % suid +
        "(0032,1060) LO [%s]\n" % desc +
        "(0040,1001) SH [%s]\n" % acc +
        "(0040,0100) SQ\n"
        "(fffe,e000) -\n"
        "  (0008,0060) CS [%s]\n" % mod +
        "  (0040,0001) AE [%s]\n" % OCT_AET +
        "  (0040,0002) DA [%s]\n" % sdat +
        "  (0040,0003) TM [000000]\n"
        "  (0040,0007) LO [%s]\n" % desc +
        "  (0040,0009) SH [%s]\n" % acc +
        "  (0040,0010) SH [ORTHANC]\n"
        "(fffe,e00d) -\n"
        "(fffe,e0dd) -\n"
    )


def main():
    if not TOKEN:
        print("ERROR: PENUNJANG_BRIDGE_TOKEN kosong", file=sys.stderr)
        return 2
    os.makedirs(WLDIR, exist_ok=True)
    try:
        rows = fetch_worklist()
    except urllib.error.HTTPError as e:
        print("ERROR ambil worklist: HTTP %s %s" % (e.code, e.read()[:200]), file=sys.stderr)
        return 1
    except Exception as e:
        print("ERROR ambil worklist: %s" % e, file=sys.stderr)
        return 1

    # Regenerate penuh: hapus .wl lama.
    for f in glob.glob(os.path.join(WLDIR, "*.wl")):
        try:
            os.remove(f)
        except OSError:
            pass

    n = 0
    for row in rows:
        acc = row.get("accession_number")
        if not acc:
            continue
        with tempfile.NamedTemporaryFile("w", suffix=".dump", delete=False) as t:
            t.write(dump_text(row))
            tmp = t.name
        out = os.path.join(WLDIR, acc + ".wl")
        try:
            subprocess.run(["dump2dcm", "-q", "-g", tmp, out], check=True)
            n += 1
        except subprocess.CalledProcessError as e:
            print("WARN dump2dcm gagal utk %s: %s" % (acc, e), file=sys.stderr)
        finally:
            os.unlink(tmp)

    print("worklist: %d item -> %s" % (n, WLDIR))
    return 0


if __name__ == "__main__":
    sys.exit(main())
