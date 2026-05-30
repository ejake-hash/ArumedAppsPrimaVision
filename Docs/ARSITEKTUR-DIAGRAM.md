# Arumed Apps — Diagram Arsitektur

> Instance: **RS Mata Prima Vision (Medan)** — DB `dbprimavision`, tema biru.
> HIS oftalmologi paperless (PMK 24/2022), terintegrasi BPJS (VClaim/Antrean/iCare/INA-CBGs) + Satu Sehat.
>
> Render Mermaid: VSCode (extension *Markdown Preview Mermaid Support*) atau GitHub.
> Snapshot codebase: **36 controllers · 34 services · 95 models · 149 migrations · 20 views · 17 stores**.

---

## 1. Arsitektur Sistem (High-Level)

```mermaid
flowchart TB
    subgraph CLIENT["CLIENT / BROWSER"]
        direction LR
        U1["Petugas HIS<br/>(login)"]
        U2["TV Ruang Tunggu<br/>(no-auth)"]
        U3["Kiosk Anjungan<br/>(no-auth)"]
        U4["Dokter / Nakes"]
    end

    subgraph FE["FRONTEND — Vue 3.5 SPA (arumed-frontend/ :5173)"]
        direction TB
        ROUTER["router/index.js<br/>beforeEach: auth + permission guard"]
        LAYOUT["AppLayout<br/>sidebar (permission-filtered) + topbar + RouterView"]
        VIEWS["20 Views<br/>(1 view = 1 modul)"]
        STORES["17 Pinia Stores"]
        API["services/api.js (axios)<br/>inject Bearer · 401→session-expired · 403→forbidden"]
        ROUTER --> LAYOUT --> VIEWS
        VIEWS <--> STORES <--> API
    end

    subgraph BE["BACKEND — Laravel 13.8 (backend/ :8000)"]
        direction TB
        BOOT["bootstrap/app.php<br/>CORS · apiPrefix=api · alias role/permission"]
        ROUTES["routes/api.php — prefix /v1 (±310 endpoint)"]
        CTRL["36 CONTROLLERS<br/>thin: validate + delegate (DI)"]
        SVC["34 SERVICES<br/>business logic + integrasi pihak ketiga"]
        QUEUE["★ QueueService — ORCHESTRATOR PUSAT ★<br/>advanceFromStation · resolveNextStation · enqueue"]
        PRICE["KasirService::getPrice<br/>resolver tarif sentral (TPA-aware)"]
        MODELS["95 MODELS (Eloquent, domain-grouped)"]
        EVENTS["Events → Reverb broadcast (WS)<br/>AdmisiQueueUpdated · TriaseQueueUpdated · AntreanTvUpdated"]
        BOOT --> ROUTES --> CTRL --> SVC
        SVC --> QUEUE
        SVC --> PRICE
        SVC --> MODELS
        SVC --> EVENTS
    end

    subgraph EXT["INTEGRASI EKSTERNAL"]
        direction TB
        BPJS["BPJS VClaim / Antrean<br/>iCare / INA-CBGs"]
        SATU["Satu Sehat (FHIR R4 Kemenkes)"]
        LOGS["tiap call → tabel *_logs (audit)"]
        BPJS --- LOGS
        SATU --- LOGS
    end

    DB[("PostgreSQL `dbprimavision`<br/>149 migrations / domain-grouped")]

    U1 & U4 -->|"HTTP /api/v1 + WS"| FE
    U2 & U3 -->|"HTTP (no-auth) + WS"| FE
    API -->|"axios Bearer JWT · Vite proxy /api → :8000"| ROUTES
    SVC -->|"HTTP"| EXT
    EVENTS -.->|"WebSocket"| FE
    MODELS -->|"PDO"| DB
```

---

## 2. Layer Backend — Request Lifecycle

```mermaid
flowchart TB
    REQ["HTTP Request<br/>PUT /api/v1/dokter/antrian/{id}/selesai"]
    AUTH{"auth:api guard<br/>(tymon/jwt-auth)"}
    PERM{"role / permission MW<br/>permission:rme_dokter.write"}
    CTRL{"Controller (thin)<br/>$request->validate()"}
    SVCD["DokterService<br/>gate validasi domain"]
    QS["★ QueueService::advanceFromStation() — DB txn ★"]
    R1["1. resolveNextStation(visit, 'DOKTER')"]
    R2["2. close current queue → COMPLETED"]
    R3["3. enqueue next: PENUNJANG | BEDAH | KASIR"]
    R4["4. broadcast event → Reverb"]
    OUT["Envelope JSON<br/>{ success, data, message, errors }"]

    REQ --> AUTH
    AUTH -->|"✗"| E401["401 session-expired"]
    AUTH -->|"✓"| PERM
    PERM -->|"✗"| E403["403 forbidden"]
    PERM -->|"✓"| CTRL
    CTRL -->|"✗"| E422["422 (format Laravel default)"]
    CTRL -->|"✓ DI"| SVCD
    SVCD -->|"delegate"| QS
    QS --> R1 --> R2 --> R3 --> R4 --> OUT
```

---

## 3. Alur Pasien — Patient Journey / Queue Stations

```mermaid
flowchart TB
    KIOSK["KIOSK / ANJUNGAN (no-auth)<br/>UMUM → tiket A-NNN"]
    DIRECT["DIRECT (datang ke loket)<br/>registerVisit() — SKIP antrian ADMISI"]

    A["ANTREAN ADMISI (A)<br/>merge walk-in → daftarkanWalkIn()"]

    TR{{"TRIASE (T) ║ REFRAKSIONIS (R)<br/>2 baris queues paralel · share visit_id (TR-NNN)"}}
    GATE{"GATE (AND)<br/>NurseAssessment.is_finalized<br/>&& RefractionRecord.is_finalized"}

    D["DOKTER (D)<br/>prefix dinamis D{room}"]
    NEXT{"resolveNextStation"}

    P["PENUNJANG (P)<br/>ada DiagnosticOrder open"]
    B["BEDAH (B)<br/>planning=BEDAH && jadwal=TODAY"]
    K["KASIR (K)<br/>default"]
    KPOST["KASIR (post-op)"]
    RX{"ada Prescription open?"}
    F["FARMASI (F)"]
    DONE["SELESAI (pulang)"]

    KIOSK --> A
    A -->|"auto-advance"| TR
    DIRECT -->|"langsung"| TR
    TR --> GATE
    GATE -->|"belum lolos → 422"| TR
    GATE -->|"lolos"| D
    D --> NEXT
    NEXT --> P
    NEXT --> B
    NEXT --> K
    P -->|"hasil rilis → kembali"| D
    B -->|"post-op"| KPOST
    K --> RX
    KPOST --> RX
    RX -->|"YES"| F
    RX -->|"NO"| DONE
    F --> DONE
```

> **Catatan BEDAH jadwal hari lain:** kalau `surgery_schedules.scheduled_date > today`, Dokter selesai → **KASIR** (bukan BEDAH). Pasien pulang lewat Kasir & Farmasi, lalu daftar ulang dari ADMISI saat hari operasi tiba.

---

## 4. Stasiun Antrean (ringkasan)

| Kode | Station | Prefix | View | Catatan |
|------|---------|--------|------|---------|
| `A` | ADMISI | `A` | `AdmisiView` | hanya walk-in kiosk; direct daftar skip ADMISI |
| `T`+`R` | TRIASE + REFRAKSIONIS | `TR` | `PerawatView` + `RefraksionisView` | 2 baris paralel, gate-ke-D = AND |
| `D` | DOKTER | `D{room}` | `DokterView` | prefix dinamis per ruangan |
| `P` | PENUNJANG | `P` | `PenunjangView` | opsional (ada DiagnosticOrder) → balik ke D |
| `B` | BEDAH | `B` | `BedahView` | opsional (planning=BEDAH & jadwal TODAY) |
| `K` | KASIR | `K` | `KasirView` | billing + COB |
| `F` | FARMASI | `F` | `FarmasiView` | opsional (ada Prescription open) |

**Lifecycle status queue:** `WAITING → CALLED → IN_PROGRESS → COMPLETED` (atau `CANCELLED`). `lewati` = pindah ke akhir antrean station yang sama.

---

## 5. Prinsip Arsitektur (pola wajib)

- **Routes → Controller (thin) → Service (logic + integrasi) → Model.** Controller hanya `validate()` + delegate via DI.
- **`QueueService::advanceFromStation()` = satu-satunya** sumber routing antar-stasiun + broadcast TV. Semua station-service hanya thin wrapper + gate validasi domain.
- **`KasirService::getPrice`** = resolver tarif sentral per-insurer (procedure/medication/bhp/iol/equipment), TPA-aware.
- **RBAC:** 23 modul × R/W/D, middleware `role`/`permission`, Superadmin bypass via sentinel `["*"]`.
- **Frontend:** 1 view = 1 modul, 1 Pinia store per domain, tanpa UI lib eksternal (CSS scoped + design token). Sidebar di-filter `auth.can()`.
- **Integrasi eksternal** wajib lewat Service class + tulis ke tabel `*_logs` (audit dispute vendor).
- **Real-time** via Reverb (WS); fallback polling (8–30s) di store kalau WS tidak aktif.

---

_Dibuat untuk instance Prima Vision. Sinkronkan dengan `ARCHITECTURE.md` setiap ada perubahan endpoint / model / view._
