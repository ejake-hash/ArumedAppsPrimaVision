<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Survei Kepuasan — URL Google Sheet tanggapan
    |--------------------------------------------------------------------------
    | Sheet HARUS dibagikan "Anyone with the link → Viewer" (atau Publish to web).
    | Untuk Google Form: tautkan tanggapan ke Sheet, lalu bagikan Sheet-nya.
    | Backend menarik CSV via endpoint gviz (tanpa kredensial).
    */
    'survey_sheet_url' => env('MARKETING_SURVEY_SHEET_URL', ''),
];
