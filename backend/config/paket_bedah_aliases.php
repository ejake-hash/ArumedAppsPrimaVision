<?php

/*
 * Alias nama item "Docs/PAKET BEDAH.xlsx" → master data.
 * Dipakai command `paket:import-excel` SEBELUM lookup exact/normalized.
 *
 * Key   = nama item di Excel (lowercase, trim).
 * Value = [item_type master, nama master persis ATAU array kandidat nama].
 *         Kandidat dicoba berurutan — master dev vs live bisa beda nama
 *         (live banyak berprefix "Alk - "). Semua kandidat tak ketemu →
 *         command AUTO-CREATE memakai kandidat PERTAMA (nama kanonis).
 *         item_type boleh BEDA dari section Excel (koreksi salah tipe:
 *         mis. IOL/obat yang ditulis di section "Bahan Habis Pakai").
 *         'code:XXX' = lookup by kode master (nama duplikat/ambigu).
 *
 * Nama yang tidak ada di alias dan tidak ketemu exact/normalized akan
 * DIBUATKAN master baru oleh command (lihat ImportPaketBedahExcel).
 */
return [
    // ── OBAT: beda format/typo penulisan ────────────────────────────────────
    'c. pantocain 0.05% drop 5 ml'    => ['MEDICATION', 'Cendo Pantocain 0,5% Drops'],
    'gentamicin 80 mg / 2 ml injeksi' => ['MEDICATION', 'Gentamicin 80 Mg/2 Ml Injeksi'],
    'ringer lactate / rl-mjb'         => ['MEDICATION', 'Ringer Lactat Mjb'],
    'ceftazidime 1 gr inj'            => ['MEDICATION', 'Ceftazidime 1G Inj'],
    'c. mydriatyl eye drop 1% 5ml'    => ['MEDICATION', 'Cendo Midriatil 1% Drops'],
    'c. mydriatyl eye drop 1% 5 ml'   => ['MEDICATION', 'Cendo Midriatil 1% Drops'],
    'epinephrine amp'                 => ['MEDICATION', 'Epinephrine Inj 1mg'],
    'lidocaine 2% / 2 ml amp'         => ['MEDICATION', 'Lidocain Hcl 2% Injeksi'],
    'flamicort 40 mg'                 => ['MEDICATION', 'Flamicort 40mg/ml'],
    'c. mycos ointment 3.5 gr'        => ['MEDICATION', 'Mycos Eye Oinment 3,5 Gr'],
    'getamicin oinment (genoint)'     => ['MEDICATION', 'Genoint Salep Mata'],
    'ondansetron inj 8 mg/ 4ml'       => ['MEDICATION', 'Ondansetron Inj 8 mg/4 ml'],
    'secadum inj 15 mg/ 3ml'          => ['MEDICATION', 'Sedacum Inj 15 mg/3 ml'],
    'sagestam inj 17x5'               => ['MEDICATION', 'Sagestam Injeksi'],
    'dexamethasone 5 mg / ml inj'     => ['MEDICATION', 'Dexamethasone 5mg/ml Inj'],
    'ketorolac injeksi'               => ['MEDICATION', 'Ketorolac'],
    'ketamine 1000 mg / 10 ml injeksi`' => ['MEDICATION', 'Ketamin 1000 mg/10 ml Inj'],
    'levica inj 50 mg / 10ml'         => ['MEDICATION', 'Levica Inj 50mg/10ml'],
    'atropin sulfate inj 0.25 mg'     => ['MEDICATION', 'Atropin Inj 0,25mg/ml'],
    'rocuronium injection 10 mg/ml'   => ['MEDICATION', 'Rocuronium Inj'],
    'aquabidest 25 ml'                => ['MEDICATION', 'Aquades 25 ml'],
    // Nama generik Excel → nama dagang di master
    'aflibercept'                     => ['MEDICATION', 'Eylea Inj'],
    'bevacizumab'                     => ['MEDICATION', 'Avastin 100mg/4ml Inj'],
    'propofol 10 mg/ml'               => ['MEDICATION', 'Recofol N'],

    // ── Koreksi salah tipe (section Excel ≠ master) ─────────────────────────
    'membran blue (ocublue)'          => ['BHP', 'Membran Blue (Ocublue)'],
    'bss ngp bag'                     => ['BHP', 'BSS NGP Bag'],
    'molcin drop'                     => ['MEDICATION', 'Molcin Drop'],
    'lensa iris claw premium'         => ['IOL', 'Lensa Iris Claw Premium'],
    'intra ocular lens monofocal premium' => ['IOL', 'Intra Ocular Lens Monofocal Premium'],

    // ── BHP: nama duplikat di master (2 baris beda kapital) → kunci by kode ──
    // BHP-017 = baris ber-tarif UMUM yang dipakai 7 paket; "bantal retina"
    // (BHPS-0073) duplikat tanpa tarif.
    'bantal retina'                   => ['BHP', 'code:BHP-017'],

    // ── BHP: typo / beda nama dev vs live (live berprefix "Alk - ") ─────────
    'micropore dispencer'             => ['BHP', ['Alk - Micropore 1 Inci 3m', 'Micropore Dispenser']],
    'cutton swab sterlil'             => ['BHP', ['Cotton Swab Steril M', 'Cotton Swab Steril']],
    'cataract surgary set'            => ['BHP', 'Cataract Surgery Set'],
    'trabec surgary set'              => ['BHP', 'Trabec Surgery Set'],
    'nasal oksigen dewasa'            => ['BHP', 'Nasal Oxygen Dewasa'],
    'capsul tension ring'             => ['BHP', 'Capsule Tension Ring'],
    'alcohol swabs'                   => ['BHP', ['Alkohol Swabs', 'Alcohol Swabs']],
    'iris care'                       => ['BHP', ['Alk - Iris Care', 'Iris Care']],
    'needle 23'                       => ['BHP', ['Alk - Needle 23 Onemed', 'Needle 23']],
    'needle 30'                       => ['BHP', ['Alk - Needle 30G Terumo', 'Needle 30']],
    'spuit 1 cc'                      => ['BHP', ['Alk - Spuit 1 Cc Onemed', 'Spuit 1 cc']],
    'spuit 3 cc'                      => ['BHP', ['Alk - Spuit 3 Cc Onemed', 'Spuit 3 cc']],
    'spuit 5 cc'                      => ['BHP', ['Alk - Spuit 5 Cc Onemed', 'Spuit 5 cc']],
    'spuit 10 cc'                     => ['BHP', ['Alk - Spuit 10 Cc Onemed', 'Spuit 10 cc']],

    // ── TINDAKAN: typo / beda penamaan ──────────────────────────────────────
    'injeksi intravitreal aflibercept' => ['PROCEDURE', 'Injeksi Intravitreal Anti VEGF Aflibercept'],
    'injeksi intravitreal bevacizumab' => ['PROCEDURE', 'Injeksi Intravitreal Anti VEGF Bevacizumab'],
    'pars plana vitrectomy + albatio retina + silicone implantation'
                                      => ['PROCEDURE', 'Pars Plana Vitrectomy + Ablatio Retina + Silicone Implantation'],
    'ektraksi corpus alienum diruang operasi' => ['PROCEDURE', 'Ekstraksi Corpus Alienum di Ruang Operasi'],
    'ektraksi corpus alienum minor'   => ['PROCEDURE', 'Ekstraksi Corpus Alienum Minor'],
    'nursing servive'                 => ['PROCEDURE', 'Nursing Service'],
    'reposisi iris'                   => ['PROCEDURE', 'Reposisi Iris Prolapse'],
    'tindakan phacoemulsifikasi'      => ['PROCEDURE', 'Phacoemulsifikasi'],
    'tindakan phacoemulsifikasi + penyulit' => ['PROCEDURE', 'Phacoemulsifikasi + Penyulit'],
];
