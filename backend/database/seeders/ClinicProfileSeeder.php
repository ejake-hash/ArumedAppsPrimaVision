<?php

namespace Database\Seeders;

use App\Models\ClinicProfile;
use Illuminate\Database\Seeder;

class ClinicProfileSeeder extends Seeder
{
    public function run(): void
    {
        ClinicProfile::updateOrCreate(
            ['clinic_code' => 'KMA'],
            [
                'clinic_name'       => 'Klinik Mata Arunika',
                'clinic_code'       => 'KMA',
                'address'           => 'Jl. Kesehatan Mata No. 1',
                'phone'             => '(021) 00000000',
                'email'             => 'info@klinikmataarunika.id',
                'director_name'     => null,
                'director_sip'      => null,
                'rm_format'         => 'YYYYMMSEQ',
                'rm_seq_length'     => 4,
                'rm_last_seq'       => 0,
                'pdf_engine'        => 'puppeteer',
                'watermark_enabled' => false,
                'watermark_type'    => 'ORIGINAL',
            ]
        );
    }
}
