<?php

namespace Database\Seeders;

use App\Models\Icd10Code;
use Illuminate\Database\Seeder;

class ICD10Seeder extends Seeder
{
    public function run(): void
    {
        $codes = [
            // Eyelid & Adnexa
            ['code' => 'H00.0', 'description' => 'Hordeolum dan kalazion kelopak mata', 'is_favorite' => false],
            ['code' => 'H02.4', 'description' => 'Ptosis kelopak mata', 'is_favorite' => false],

            // Conjunctiva
            ['code' => 'H10.1', 'description' => 'Konjungtivitis atopik akut', 'is_favorite' => false],
            ['code' => 'H10.4', 'description' => 'Konjungtivitis kronik', 'is_favorite' => false],
            ['code' => 'H11.0', 'description' => 'Pterigium', 'is_favorite' => true],

            // Cornea
            ['code' => 'H16.0', 'description' => 'Ulkus kornea', 'is_favorite' => true],
            ['code' => 'H18.6', 'description' => 'Keratokonus', 'is_favorite' => false],

            // Iris & Ciliary Body
            ['code' => 'H20.0', 'description' => 'Iridosiklitis akut (Uveitis Anterior)', 'is_favorite' => false],

            // Lens — Cataract (most common)
            ['code' => 'H25.0', 'description' => 'Katarak senilis kortikal', 'is_favorite' => false],
            ['code' => 'H25.1', 'description' => 'Katarak senilis nuklear', 'is_favorite' => true],
            ['code' => 'H25.2', 'description' => 'Katarak senilis tipe morgagnian', 'is_favorite' => false],
            ['code' => 'H25.8', 'description' => 'Katarak senilis lainnya', 'is_favorite' => false],
            ['code' => 'H26.0', 'description' => 'Katarak infantil dan juvenil', 'is_favorite' => false],
            ['code' => 'H26.1', 'description' => 'Katarak traumatika', 'is_favorite' => true],
            ['code' => 'H26.2', 'description' => 'Katarak komplikata', 'is_favorite' => false],
            ['code' => 'H27.0', 'description' => 'Afakia', 'is_favorite' => false],
            ['code' => 'H28.0', 'description' => 'Katarak diabetik', 'is_favorite' => false],

            // Retina & Choroid
            ['code' => 'H33.0', 'description' => 'Ablasio retina dengan robekan retina', 'is_favorite' => true],
            ['code' => 'H34.1', 'description' => 'Oklusi arteri retina sentral (CRAO)', 'is_favorite' => false],
            ['code' => 'H35.0', 'description' => 'Retinopati diabetika background', 'is_favorite' => true],
            ['code' => 'H35.1', 'description' => 'Retinopati pre-maturitas', 'is_favorite' => false],
            ['code' => 'H35.3', 'description' => 'Degenerasi makula dan kutub posterior (AMD)', 'is_favorite' => true],

            // Glaucoma
            ['code' => 'H40.0', 'description' => 'Suspek glaukoma', 'is_favorite' => true],
            ['code' => 'H40.1', 'description' => 'Glaukoma sudut terbuka primer', 'is_favorite' => true],
            ['code' => 'H40.2', 'description' => 'Glaukoma sudut tertutup primer', 'is_favorite' => true],
            ['code' => 'H40.3', 'description' => 'Glaukoma sekunder akibat trauma mata', 'is_favorite' => false],

            // Vitreous
            ['code' => 'H43.1', 'description' => 'Perdarahan vitreous', 'is_favorite' => false],

            // Optic Nerve
            ['code' => 'H46',   'description' => 'Neuritis optik', 'is_favorite' => false],

            // Refraction
            ['code' => 'H52.0', 'description' => 'Hipermetropi', 'is_favorite' => false],
            ['code' => 'H52.1', 'description' => 'Miopia', 'is_favorite' => true],
            ['code' => 'H52.2', 'description' => 'Astigmatisma', 'is_favorite' => true],
            ['code' => 'H52.4', 'description' => 'Presbiopia', 'is_favorite' => true],

            // Visual disturbance & Blindness
            ['code' => 'H53.0', 'description' => 'Ambliopia akibat anisometropia', 'is_favorite' => false],
            ['code' => 'H54.0', 'description' => 'Kebutaan kedua mata', 'is_favorite' => false],
            ['code' => 'H54.4', 'description' => 'Kebutaan satu mata, penglihatan normal mata lain', 'is_favorite' => false],
        ];

        foreach ($codes as $code) {
            Icd10Code::updateOrCreate(
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
