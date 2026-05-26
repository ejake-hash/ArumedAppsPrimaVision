<?php

namespace Database\Seeders;

use App\Models\Icd9Code;
use Illuminate\Database\Seeder;

/**
 * ICD-9-CM kode prosedur oftalmologi paling sering dipakai di klinik mata Indonesia.
 * Range yang umum: 08-16 (Operations on the eye).
 * Semua di-flag is_eye_related=true.
 */
class Icd9OftalmologiSeeder extends Seeder
{
    public function run(): void
    {
        // [code, category, description, indonesian_description]
        $codes = [
            // --- 08: Operations on eyelids ---
            ['08.20', '08', 'Removal of lesion of eyelid, not otherwise specified', 'Pengangkatan lesi kelopak mata, tidak spesifik'],
            ['08.21', '08', 'Excision of chalazion',                                'Eksisi kalazion'],

            // --- 09: Operations on lacrimal system ---
            ['09.41', '09', 'Probing of lacrimal punctum',                          'Probing pungtum lakrimalis'],
            ['09.43', '09', 'Probing of nasolacrimal duct',                         'Probing duktus nasolakrimalis'],
            ['09.81', '09', 'Dacryocystorhinostomy (DCR)',                          'Dakriosistorhinostomi (DCR)'],

            // --- 10: Operations on conjunctiva ---
            ['10.31', '10', 'Excision of lesion or tissue of conjunctiva',          'Eksisi lesi/jaringan konjungtiva'],
            ['10.5',  '10', 'Lysis of adhesions of conjunctiva and eyelid',         'Lisis adhesi konjungtiva dan kelopak mata'],

            // --- 11: Operations on cornea ---
            ['11.41', '11', 'Mechanical removal of corneal epithelium',             'Pengangkatan epitel kornea secara mekanis'],
            ['11.43', '11', 'Removal of foreign body from cornea',                  'Pengangkatan benda asing dari kornea'],
            ['11.59', '11', 'Other repair of cornea',                               'Repair kornea lainnya'],
            ['11.62', '11', 'Other lamellar keratoplasty',                          'Keratoplasti lamelar lainnya'],
            ['11.64', '11', 'Other penetrating keratoplasty',                       'Keratoplasti penetrans lainnya'],

            // --- 12: Operations on iris, ciliary body, sclera, anterior chamber ---
            ['12.51', '12', 'Goniopuncture without goniotomy',                      'Goniopungsi tanpa goniotomi'],
            ['12.52', '12', 'Goniotomy',                                            'Goniotomi'],
            ['12.66', '12', 'Posterior sclerotomy',                                 'Sklerotomi posterior'],

            // --- 13: Operations on lens (KATARAK!) ---
            ['13.11', '13', 'Intracapsular extraction of lens by temporal inferior route', 'Ekstraksi katarak intrakapsular (ICCE)'],
            ['13.19', '13', 'Other intracapsular extraction of lens',               'Ekstraksi katarak intrakapsular lainnya'],
            ['13.41', '13', 'Phacoemulsification and aspiration of cataract',       'Fakoemulsifikasi dan aspirasi katarak (Phaco)'],
            ['13.59', '13', 'Other extracapsular extraction of lens',               'Ekstraksi katarak ekstrakapsular lainnya (SICS/ECCE)'],
            ['13.71', '13', 'Insertion of intraocular lens prosthesis at time of cataract extraction, one-stage', 'Insersi IOL pada saat ekstraksi katarak (one-stage)'],
            ['13.72', '13', 'Secondary insertion of intraocular lens prosthesis',   'Insersi IOL sekunder'],
            ['13.8',  '13', 'Removal of implanted lens',                            'Pengangkatan lensa intraokular'],

            // --- 14: Operations on retina, choroid, vitreous, posterior chamber ---
            ['14.21', '14', 'Destruction of chorioretinal lesion by diathermy',     'Destruksi lesi korioretinal dengan diatermi'],
            ['14.24', '14', 'Destruction of chorioretinal lesion by photocoagulation', 'Fotokoagulasi laser retina'],
            ['14.41', '14', 'Scleral buckling with implant',                        'Scleral buckling dengan implan'],
            ['14.74', '14', 'Other mechanical vitrectomy',                          'Vitrektomi mekanis lainnya'],
            ['14.75', '14', 'Injection of vitreous substitute',                     'Injeksi pengganti vitreous'],

            // --- 15: Operations on extraocular muscles ---
            ['15.11', '15', 'Recession of one extraocular muscle',                  'Reseksi satu otot ekstraokular'],
            ['15.13', '15', 'Resection of one extraocular muscle',                  'Reseksi otot ekstraokular'],

            // --- 16: Operations on orbit and eyeball ---
            ['16.39', '16', 'Other evisceration of eyeball',                        'Eviserasi bola mata lainnya'],
            ['16.49', '16', 'Other enucleation of eyeball',                         'Enukleasi bola mata lainnya'],
            ['16.51', '16', 'Exenteration of orbital contents with temporalis muscle transplant', 'Eksenterasi orbita dengan transplantasi otot temporalis'],

            // --- 95: Ophthalmologic and otologic diagnosis and treatment (refraksi & terapi) ---
            ['95.02', '95', 'Comprehensive eye examination',                        'Pemeriksaan mata komprehensif'],
            ['95.04', '95', 'Eye examination under anesthesia',                     'Pemeriksaan mata dengan anestesi'],
            ['95.11', '95', 'Fundus photography',                                   'Fotografi fundus'],
            ['95.12', '95', 'Fluorescein angiography or angioscopy of eye',         'Fluorescein angiografi (FFA)'],
            ['95.16', '95', 'Visual evoked potential (VEP)',                        'Visual evoked potential (VEP)'],
            ['95.41', '95', 'Audiometry',                                           'Audiometri'],
        ];

        foreach ($codes as [$code, $category, $desc, $descId]) {
            Icd9Code::updateOrCreate(
                ['code' => $code],
                [
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
