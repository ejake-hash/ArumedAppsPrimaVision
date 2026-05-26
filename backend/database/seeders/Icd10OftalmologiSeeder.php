<?php

namespace Database\Seeders;

use App\Models\Icd10Code;
use Illuminate\Database\Seeder;

/**
 * ICD-10 kode oftalmologi paling sering dipakai di klinik mata Indonesia.
 * Range: H00-H59 (Chapter VII — Diseases of the eye and adnexa).
 * Semua di-flag is_eye_related=true.
 */
class Icd10OftalmologiSeeder extends Seeder
{
    public function run(): void
    {
        $chapter      = 'VII';
        $chapterLabel = 'Diseases of the eye and adnexa';

        $codes = [
            // --- Konjungtiva & Kornea ---
            ['H10',   'H10',   'Conjunctivitis',                                'Konjungtivitis'],
            ['H10.0', 'H10',   'Mucopurulent conjunctivitis',                   'Konjungtivitis mukopurulen'],
            ['H10.1', 'H10',   'Acute atopic conjunctivitis',                   'Konjungtivitis atopik akut'],
            ['H11.0', 'H11',   'Pterygium',                                     'Pterigium'],
            ['H16',   'H16',   'Keratitis',                                     'Keratitis'],
            ['H16.0', 'H16',   'Corneal ulcer',                                 'Ulkus kornea'],

            // --- Katarak ---
            ['H25',   'H25',   'Age-related cataract',                          'Katarak senilis'],
            ['H25.0', 'H25',   'Senile incipient cataract',                     'Katarak senilis insipien'],
            ['H25.1', 'H25',   'Senile nuclear cataract',                       'Katarak senilis nuklearis'],
            ['H25.2', 'H25',   'Senile cataract, morgagnian type',              'Katarak senilis tipe morgagni'],
            ['H25.9', 'H25',   'Senile cataract, unspecified',                  'Katarak senilis, tidak spesifik'],
            ['H26',   'H26',   'Other cataract',                                'Katarak lainnya'],
            ['H26.0', 'H26',   'Infantile, juvenile and presenile cataract',    'Katarak infantil, juvenil dan presenil'],

            // --- Glaukoma ---
            ['H40',   'H40',   'Glaucoma',                                      'Glaukoma'],
            ['H40.0', 'H40',   'Glaucoma suspect',                              'Suspek glaukoma'],
            ['H40.1', 'H40',   'Primary open-angle glaucoma',                   'Glaukoma sudut terbuka primer'],
            ['H40.2', 'H40',   'Primary angle-closure glaucoma',                'Glaukoma sudut tertutup primer'],
            ['H40.9', 'H40',   'Glaucoma, unspecified',                         'Glaukoma, tidak spesifik'],

            // --- Retina & Vitreous ---
            ['H33',   'H33',   'Retinal detachments and breaks',                'Ablasio retina'],
            ['H35.0', 'H35',   'Background retinopathy and retinal vascular changes', 'Retinopati background dan perubahan vaskular retina'],
            ['H35.3', 'H35',   'Degeneration of macula and posterior pole',     'Degenerasi makula dan kutub posterior (AMD)'],
            ['H36.0', 'H36',   'Diabetic retinopathy',                          'Retinopati diabetik'],

            // --- Refraksi & Akomodasi ---
            ['H52.0', 'H52',   'Hypermetropia',                                 'Hipermetropia'],
            ['H52.1', 'H52',   'Myopia',                                        'Miopia'],
            ['H52.2', 'H52',   'Astigmatism',                                   'Astigmatisme'],
            ['H52.4', 'H52',   'Presbyopia',                                    'Presbiopia'],

            // --- Strabismus & Gangguan Penglihatan ---
            ['H50.0', 'H50',   'Convergent concomitant strabismus',             'Strabismus konvergen konkomitan (esotropia)'],
            ['H50.1', 'H50',   'Divergent concomitant strabismus',              'Strabismus divergen konkomitan (eksotropia)'],
            ['H54.0', 'H54',   'Blindness, both eyes',                          'Kebutaan, kedua mata'],
            ['H54.1', 'H54',   'Blindness, one eye, low vision other eye',      'Kebutaan satu mata, low vision mata lain'],

            // --- Kelopak Mata & Lakrimal ---
            ['H00.0', 'H00',   'Hordeolum and other deep inflammation of eyelid', 'Hordeolum / bintitan'],
            ['H02.4', 'H02',   'Ptosis of eyelid',                              'Ptosis kelopak mata'],
            ['H04.1', 'H04',   'Other disorders of lacrimal gland',             'Gangguan kelenjar lakrimal lainnya'],
        ];

        foreach ($codes as [$code, $category, $desc, $descId]) {
            Icd10Code::updateOrCreate(
                ['code' => $code],
                [
                    'chapter'                => $chapter,
                    'chapter_label'          => $chapterLabel,
                    'category'               => $category,
                    'description'            => $desc,
                    'indonesian_description' => $descId,
                    'is_eye_related'         => true,
                    'is_favorite'            => false,
                ]
            );
        }
    }
}
