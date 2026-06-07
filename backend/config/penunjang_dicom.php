<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Peta test-type penunjang → DICOM Modality
    |--------------------------------------------------------------------------
    |
    | Dipakai PenunjangWorklistController/AccessionService untuk mengisi field
    | Modality di worklist DICOM (alat menyaring worklist per modalitas).
    | Kunci = kode test (diagnostic_test_types.code / procedures.code), nilai =
    | kode modalitas DICOM. Editable agar cocok dengan yang dipancarkan alat.
    |
    | Modality DICOM umum mata: OPT = Ophthalmic Tomography (OCT), US = Ultrasound,
    | OT = Other (fallback).
    |
    */

    'modality_map' => [
        'OCT'  => 'OPT',
        'USG'  => 'US',
        'BIOM' => 'US',   // biometri ultrasonik (Aviso)
    ],

    'modality_default' => 'OT',

];
