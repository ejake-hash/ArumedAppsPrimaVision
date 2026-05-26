<?php

namespace Database\Seeders;

use App\Models\Icd9Code;
use Illuminate\Database\Seeder;

class ICD9Seeder extends Seeder
{
    public function run(): void
    {
        $codes = [
            // Pemeriksaan Mata
            ['code' => '95.02', 'description' => 'Pemeriksaan oftalmologi komprehensif', 'is_favorite' => true],
            ['code' => '95.11', 'description' => 'Tonometri', 'is_favorite' => true],
            ['code' => '95.12', 'description' => 'Oftalmoskopi (Fundoskopi)', 'is_favorite' => true],
            ['code' => '95.16', 'description' => 'Pemeriksaan refraksi', 'is_favorite' => true],
            ['code' => '95.23', 'description' => 'Refraksi sikloplegik', 'is_favorite' => false],

            // Operasi Katarak
            ['code' => '13.41', 'description' => 'Fakoemulsifikasi dan aspirasi katarak (Phaco)', 'is_favorite' => true],
            ['code' => '13.51', 'description' => 'Ekstraksi katarak ekstrakapsuler (ECCE) — linear extraction', 'is_favorite' => true],
            ['code' => '13.59', 'description' => 'Ekstraksi katarak ekstrakapsuler lainnya (ECCE)', 'is_favorite' => false],
            ['code' => '13.71', 'description' => 'Implantasi lensa intraokular (IOL)', 'is_favorite' => true],

            // Operasi Pterigium
            ['code' => '11.31', 'description' => 'Transposisi pterigium', 'is_favorite' => false],
            ['code' => '11.32', 'description' => 'Eksisi pterigium dengan cangkok kornea', 'is_favorite' => true],
            ['code' => '11.39', 'description' => 'Operasi pterigium lainnya (dengan conjunctival autograft)', 'is_favorite' => true],

            // Glaukoma
            ['code' => '12.14', 'description' => 'Trabekulotomi ab externo', 'is_favorite' => false],
            ['code' => '12.64', 'description' => 'Trabekulektomi', 'is_favorite' => true],
            ['code' => '12.65', 'description' => 'Operasi segmen anterior lainnya', 'is_favorite' => false],

            // Retina
            ['code' => '14.41', 'description' => 'Sirklag sklera dengan implan (operasi ablasio retina)', 'is_favorite' => false],
            ['code' => '14.49', 'description' => 'Sirklag sklera lainnya', 'is_favorite' => false],
            ['code' => '14.74', 'description' => 'Vitrektomi pars plana (PPV)', 'is_favorite' => true],

            // Kelopak Mata
            ['code' => '08.31', 'description' => 'Koreksi ptosis — teknik otot frontalis', 'is_favorite' => false],
            ['code' => '08.36', 'description' => 'Koreksi ptosis — teknik jahit tepi kelopak', 'is_favorite' => false],
            ['code' => '08.74', 'description' => 'Rekonstruksi kelopak mata dengan flap kulit', 'is_favorite' => false],

            // Bola Mata
            ['code' => '16.21', 'description' => 'Eviskerasi bola mata', 'is_favorite' => false],
            ['code' => '16.31', 'description' => 'Enukleasi bola mata', 'is_favorite' => false],
        ];

        foreach ($codes as $code) {
            Icd9Code::updateOrCreate(
                ['code' => $code['code']],
                [
                    'description'    => $code['description'],
                    'is_eye_related' => true,
                    'is_favorite'    => $code['is_favorite'],
                ]
            );
        }
    }
}
