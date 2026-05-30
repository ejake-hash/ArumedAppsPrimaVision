# ARUMED — Plan Bridging BPJS Kesehatan (VClaim)

> Status: **PLANNING**. Credential belum turun. Strategi: bangun **semua fondasi teknis + UI** sekarang dengan credential kosong, sehingga begitu `cons_id`/`secretKey`/`user_key` turun dari Trustmark BPJS, tinggal **paste di UI → Test → Aktifkan**, bridging hidup tanpa sentuh kode.
>
> Trustmark = **VClaim BPJS** → `cons_id` + `secretKey` SATU pasang per faskes (dipakai VClaim & Antrean), `user_key` BEDA per layanan.
## 1. Tujuan & Prinsip

- **Zero-code activation**: setelah merge, mengaktifkan BPJS = pekerjaan admin di UI, bukan developer.
- **Aman saat credential kosong**: semua method melempar `503` jelas ("Integrasi belum diaktifkan"), TIDAK crash, TIDAK memblokir flow non-BPJS.
- **Tidak menyentuh flow existing** kecuali titik wiring yang disepakati (Admisi, QueueService, Kasir).
- **Satu sumber auth**: helper HMAC + decrypt dipakai bersama VClaim & Antrean (DRY).
- **Audit penuh**: tiap call tercatat di `bpjs_vclaim_logs` / `bpjs_antrean_logs` (request, response, status, sukses/gagal).
## 2. Kondisi Repo Saat Ini (audit 2026-05-29)

### Sudah ada (kerangka)
| Komponen | Lokasi | Catatan |
|---|---|---|
| Model config | `app/Models/IntegrationConfig.php` | `credentials`/`configuration` cast `array`. ⚠️ belum encrypted. |
| Tabel config | `migrations/2026_05_11_000001_create_integration_configs_table.php` | kolom `system_name`, `is_enabled`, `base_url`, `credentials` (jsonb), `configuration`, `last_test_status`, `last_tested_at`, `notes`. |
| Service VClaim | `app/Services/BpjsVClaimService.php` | semua method `placeholder()`. |
| Service Antrean | `app/Services/BpjsAntreanService.php` | semua method `placeholder()`. |
| Orchestrator | `app/Services/IntegrasiService.php` | config CRUD + test + log readers. |
| Controller | `app/Http/Controllers/IntegrasiController.php` | 25+ endpoint `/integrasi/*` aktif. |
| Routes | `routes/api.php` ~baris 888 | prefix `integrasi`. |
| Tabel log | `bpjs_vclaim_logs`, `bpjs_antrean_logs`, `bpjs_icare_logs` | siap. |
| Tabel data | `bpjs_claims`, `bpjs_referrals_in`, `bpjs_referrals_out`, `bpjs_control_letters` | siap. |

### Belum ada (akan dibangun)
- Helper auth nyata (HMAC signature) + decrypt response (AES-256-CBC + LZ-String).
- Library LZ-String PHP (composer).
- Implementasi HTTP call nyata di tiap method.
- Method Antrean yang lengkap (add/updatewaktu/batal/dashboard wajib).
- Seeder baris `integration_configs` untuk VCLAIM & ANTREAN.
- **UI menu Bridging** (frontend) — belum ada `IntegrasiView.vue`, route, store, item sidebar.
- Cast `encrypted:array` untuk credential.
**Keputusan desain:** buat satu kelas baru `App\Services\Bpjs\BpjsClient` yang menampung auth+http+decrypt. `BpjsVClaimService` & `BpjsAntreanService` meng-inject `BpjsClient`, fokus ke pembentukan path + parse hasil. Ini menghindari duplikasi signature di dua service.

 4.3 Catatan Antrean RS
- Header signature SAMA.
- Sebagian besar endpoint Antrean **response JSON polos (tidak terenkripsi)** — tapi ada pengecualian; akan dikonfirmasi per endpoint via tabel §7.
- `BpjsClient::request()` punya flag `$encrypted` (default true VClaim, false Antrean) → tinggal set per call.

###  Library
- `composer require nullpunkt/lz-string-php` (atau ekuivalen). Akan dikunci versinya saat Poin 1.

###  Sinkronisasi waktu
Server WIB (Asia/Jakarta) sudah benar. `X-timestamp` dihitung sebagai epoch UTC (`time()` di PHP sudah UTC-based). Pastikan NTP host sinkron — drift > beberapa menit → signature ditolak BPJS.


BASE URL
VClaim : https://apijkn-dev.bpjs-kesehatan.go.id/vclaim-rest-dev
Antrean RS : https://apijkn-dev.bpjs-kesehatan.go.id/antreanrs_dev
iCare JKN : https://apijkn-dev.bpjs-kesehatan.go.id/ihs_dev
eRekamMedis : https://apijkn-dev.bpjs-kesehatan.go.id/erekammedis_dev

Create Signature
Secara umum, hampir setiap pemanggilan web-service, harus dicantumkan beberapa variabel yang dibutuhkan untuk menambahkan informasi ataupun untuk proses validasi yang dikirim pada HTTP Header, antara lain:
#	Nama Header	Nilai	Keterangan
1	X-cons-id	743627386	consumer ID dari BPJS Kesehatan
2	X-timestamp	234234234	generated unix-based timestamp
3	X-signature	DogC5UiQurNcigrBdQ3QN5oYvXeUF5E82I/LHUcI9v0=	generated signature dengan pola HMAC-256
4	user_key	d795b04f4a72d74fae727be9da0xxxxx	user_key untuk akses webservice

1. X-cons-id, merupakan kode consumer (pengakses web-service). Kode ini akan diberikan oleh BPJS Kesehatan.
2. X-timestamp, merupakan waktu yang akan di-generate oleh client saat ingin memanggil setiap service. Format waktu ini ditulis dengan format unix-based-time (berisi angka, tidak dalam format tanggal sebagaimana mestinya). Format waktu menggunakan Coordinated Universal Time ( UTC), dalam penggunaannya untuk mendapatkan timestamp, rumus yang digunakan adalah (local time in UTC timezone in seconds) - (1970-01-01 in seconds).
3. X-signature, merupakan hasil dari pembuatan signature yang dibuat oleh client. Signature yang digunakan menggunakan pola HMAC-SHA256.
4. user_key, merupakan key untuk mengakses webservice. Setiap service consumer memiliki user_key masing-masing.

Untuk dapat mengakses web-service dari BPJS Kesehatan (service provider), pemanggil web service (service consumer) akan mendapatkan:
� Consumer ID
� Consumer Secret


Informasi Consumer Secret, hanya disimpan oleh service consumer. Tidak dikirim ke server web-service, hal ini untuk menjaga pengamanan yang lebih baik. Sedangkan kebutuhan Consumer Secret ini adalah untuk men-generate Signature (X-signature).

Contoh:
consumerID : 1234
consumerSecret : pwd
timestamp : 433223232
variabel1 : consumerID&timestamp
variabel1 : 1234&433223232

SIGNATURE

Metode signature yang digunakan adalah menggunakan HMAC-SHA256, dimana paramater saat generate signature dibutuhkan parameter message dan key.
Berikut contoh hasil generate HMAC-SHA256
message : aaa
key : bbb
hasil generate HMAC-SHA256 : 20BKS3PWnD3XU4JbSSZvVlGi2WWnDa8Sv9uHJ+wsELA=
Diatas adalah hasil generate dari server BPJS Kesehatan
Signature : HMAC-256(value : key)
value : variabel1
key : consumerSecret
Signature : HMAC-256(variabel1 : consumerSecret)

Contoh Pembuatan Signature
<?php 
       $data = "testtesttest";
       $secretKey = "secretkey";
             // Computes the timestamp
              date_default_timezone_set('UTC');
              $tStamp = strval(time()-strtotime('1970-01-01 00:00:00'));
               // Computes the signature by hashing the salt with the secret key as the key
       $signature = hash_hmac('sha256', $data."&".$tStamp, $secretKey, true);
     
       // base64 encode�
       $encodedSignature = base64_encode($signature);
     
       // urlencode�
       // $encodedSignature = urlencode($encodedSignature);
     
       echo "X-cons-id: " .$data ." ";
       echo "X-timestamp:" .$tStamp ." ";
       echo "X-signature: " .$encodedSignature;
    ?>
How to Decrypt
esponse kembalian dari web service vclaim sudah dalam bentuk compres dan terenkripsi.

Kompresi service menggunakan metode : Lz-string
Enkripsi menggunakan metode : AES 256 (mode CBC) - SHA256 dan key enkripsi: consid + conspwd + timestamp request (concatenate string)

Langkah proses dalam melakukan decrypt data response sebagai berikut :
1. Dekripsi : AES 256 (mode CBC) - SHA256
2. Dekompresi : Lz-string (decompressFromEncodedURIComponent)
key : consid + conspwd + timestamp request (concatenate string)

Contoh Penggunaan Decrypt Service
<?php 
        require_once 'vendor/autoload.php';
    
        // function decrypt
        function stringDecrypt($key, $string){
            
      
            $encrypt_method = 'AES-256-CBC';
    
            // hash
            $key_hash = hex2bin(hash('sha256', $key));
      
            // iv - encrypt method AES-256-CBC expects 16 bytes - else you will get a warning
            $iv = substr(hex2bin(hash('sha256', $key)), 0, 16);
    
            $output = openssl_decrypt(base64_decode($string), $encrypt_method, $key_hash, OPENSSL_RAW_DATA, $iv);
      
            return $output;
        }
    
        // function lzstring decompress 
        // download libraries lzstring : https://github.com/nullpunkt/lz-string-php
        function decompress($string){
      
            return \LZCompressor\LZString::decompressFromEncodedURIComponent($string);
    
        }
    ?>

Peserta
No.Kartu BPJS
{BASE URL}/{Service Name}/Peserta/nokartu/{parameter 1}/tglSEP/{parameter 2}
Fungsi : Pencarian data peserta BPJS Kesehatan
Method : GET
Format : Json
Content-Type: application/json; charset=utf-8
Parameter 1 : Nomor Kartu
Parameter 2 : Tanggal Pelayanan/SEP - format : yyyy-MM-dd
{
           "metaData":{
              "code":"200",
              "message":"OK"
           },
           "response":{
              "peserta":{
                 "cob":{
                    "nmAsuransi":null,
                    "noAsuransi":null,
                    "tglTAT":null,
                    "tglTMT":null
                 },
                 "hakKelas":{
                    "keterangan":"KELAS I",
                    "kode":"1"
                 },
                 "informasi":{
                    "dinsos":null,
                    "noSKTM":null,
                    "prolanisPRB":null
                 },
                 "jenisPeserta":{
                    "keterangan":"PEGAWAI SWASTA",
                    "kode":"13"
                 },
                 "mr":{
                    "noMR":null,
                    "noTelepon":null
                 },
                 "nama":"TRI M",
                 "nik":"3319022010810007",
                 "noKartu":"0011336526592",
                 "pisa":"1",
                 "provUmum":{
                    "kdProvider":"0138U020",
                    "nmProvider":"KPRJ PALA MEDIKA"
                 },
                 "sex":"L",
                 "statusPeserta":{
                    "keterangan":"AKTIF",
                    "kode":"0"
                 },
                 "tglCetakKartu":"2016-02-12",
                 "tglLahir":"1981-10-10",
                 "tglTAT":"2014-12-31",
                 "tglTMT":"2008-10-01",
                 "umur":{
                    "umurSaatPelayanan":"35 tahun ,1 bulan ,11 hari",
                    "umurSekarang":"35 tahun ,2 bulan ,10 hari"
                 }
              }
           }
        }
NIK
{BASE URL}/{Service Name}/Peserta/nik/{parameter 1}/tglSEP/{parameter 2}
Fungsi : Pencarian data peserta berdasarkan NIK Kependudukan
Method : GET
Format : Json
Content-Type: application/json; charset=utf-8
Parameter 1 : NIK KTP
Parameter 2 : Tanggal Pelayanan/SEP - format : yyyy-MM-dd
{
           "metaData":{
              "code":"200",
              "message":"OK"
           },
           "response":{
              "peserta":{
                 "cob":{
                    "nmAsuransi":null,
                    "noAsuransi":null,
                    "tglTAT":null,
                    "tglTMT":null
                 },
                 "hakKelas":{
                    "keterangan":"KELAS I",
                    "kode":"1"
                 },
                 "informasi":{
                    "dinsos":null,
                    "noSKTM":null,
                    "prolanisPRB":null
                 },
                 "jenisPeserta":{
                    "keterangan":"PEGAWAI SWASTA",
                    "kode":"13"
                 },
                 "mr":{
                    "noMR":null,
                    "noTelepon":null
                 },
                 "nama":"TRI M",
                 "nik":"3319022010810007",
                 "noKartu":"0011336526592",
                 "pisa":"1",
                 "provUmum":{
                    "kdProvider":"0138U020",
                    "nmProvider":"KPRJ PALA MEDIKA"
                 },
                 "sex":"L",
                 "statusPeserta":{
                    "keterangan":"AKTIF",
                    "kode":"0"
                 },
                 "tglCetakKartu":"2016-02-12",
                 "tglLahir":"1981-10-10",
                 "tglTAT":"2014-12-31",
                 "tglTMT":"2008-10-01",
                 "umur":{
                    "umurSaatPelayanan":"35 tahun ,1 bulan ,11 hari",
                    "umurSekarang":"35 tahun ,2 bulan ,10 hari"
                 }
              }
           }
        }                   
Cari Rujukan
{BASE URL}/{Service Name}/Rujukan/RS/{parameter}
Fungsi : Pencarian data rujukan dari rumah sakit berdasarkan nomor rujukan
Method : GET
Format : Json
Content-Type: application/json; charset=utf-8
Parameter : Nomor Rujukan
{
       "metaData": {
          "code": "200",
          "message": "OK"
       },
       "response": {
          "rujukan": {
             "diagnosa": {
                "kode": "I21.9",
                "nama": "Acute myocardial infarction, unspecified"
             },
             "keluhan": "",
             "noKunjungan": "0304R0050217A000079",
             "pelayanan": {
                "kode": "1",
                "nama": "Rawat Inap"
             },
             "peserta": {
                "cob": {
                   "nmAsuransi": null,
                   "noAsuransi": null,
                   "tglTAT": null,
                   "tglTMT": null
                },
                "hakKelas": {
                   "keterangan": "KELAS III",
                   "kode": "3"
                },
                "informasi": {
                   "dinsos": null,
                   "noSKTM": null,
                   "prolanisPRB": null
                },
                "jenisPeserta": {
                   "keterangan": "PBI (APBN)",
                   "kode": "21"
                },
                "mr": {
                   "noMR": "971430",
                   "noTelepon": null
                },
                "nama": "MUHAMMAD JUSAR",
                "nik": "1106081301530001",
                "noKartu": "0105986780439",
                "pisa": "1",
                "provUmum": {
                   "kdProvider": "03050301",
                   "nmProvider": "BASO"
                },
                "sex": "L",
                "statusPeserta": {
                   "keterangan": "AKTIF",
                   "kode": "0"
                },
                "tglCetakKartu": "2017-11-13",
                "tglLahir": "1953-07-01",
                "tglTAT": "2053-07-01",
                "tglTMT": "2013-01-01",
                "umur": {
                   "umurSaatPelayanan": "63 tahun ,7 bulan ,23 hari",
                   "umurSekarang": "64 tahun ,4 bulan ,12 hari"
                }
             },
             "poliRujukan": {
                "kode": "",
                "nama": ""
             },
             "provPerujuk": {
                "kode": "0304R005",
                "nama": "RSI IBNU SINA"
             },
             "tglKunjungan": "2017-02-24"
          }
       }
    }
Rujukan Berdasarkan Nomor Kartu (1 Record)
{BASE URL}/{Service Name}/Rujukan/RS/Peserta/{parameter}
Fungsi : Pencarian data rujukan dari rumah sakit berdasarkan nomor kartu
Method : GET
Format : Json
Content-Type: application/json; charset=utf-8
Parameter : Nomor kartu
{
       "metaData": {
          "code": "200",
          "message": "OK"
       },
       "response": {
          "rujukan": {
             "diagnosa": {
                "kode": "I21.9",
                "nama": "Acute myocardial infarction, unspecified"
             },
             "keluhan": "",
             "noKunjungan": "0304R0050217A000079",
             "pelayanan": {
                "kode": "1",
                "nama": "Rawat Inap"
             },
             "peserta": {
                "cob": {
                   "nmAsuransi": null,
                   "noAsuransi": null,
                   "tglTAT": null,
                   "tglTMT": null
                },
                "hakKelas": {
                   "keterangan": "KELAS III",
                   "kode": "3"
                },
                "informasi": {
                   "dinsos": null,
                   "noSKTM": null,
                   "prolanisPRB": null
                },
                "jenisPeserta": {
                   "keterangan": "PBI (APBN)",
                   "kode": "21"
                },
                "mr": {
                   "noMR": "971430",
                   "noTelepon": null
                },
                "nama": "MUHAMMAD JUSAR",
                "nik": "1106081301530001",
                "noKartu": "0105986780439",
                "pisa": "1",
                "provUmum": {
                   "kdProvider": "03050301",
                   "nmProvider": "BASO"
                },
                "sex": "L",
                "statusPeserta": {
                   "keterangan": "AKTIF",
                   "kode": "0"
                },
                "tglCetakKartu": "2017-11-13",
                "tglLahir": "1953-07-01",
                "tglTAT": "2053-07-01",
                "tglTMT": "2013-01-01",
                "umur": {
                   "umurSaatPelayanan": "63 tahun ,7 bulan ,23 hari",
                   "umurSekarang": "64 tahun ,4 bulan ,12 hari"
                }
             },
             "poliRujukan": {
                "kode": "",
                "nama": ""
             },
             "provPerujuk": {
                "kode": "0304R005",
                "nama": "RSI IBNU SINA"
             },
             "tglKunjungan": "2017-02-24"
          }
       }
    }
Rujukan Berdasarkan Nomor Kartu (Multi Record)
{BASE URL}/{Service Name}/Rujukan/RS/List/Peserta/{parameter}
Fungsi : Pencarian data rujukan dari rumah sakit berdasarkan nomor kartu
Method : GET
Format : Json
Content-Type: application/json; charset=utf-8
Parameter : Nomor kartu
{
       "metaData": {
          "code": "200",
          "message": "OK"
       },
       "response": {
          "rujukan": 
            [
            {
             "diagnosa": {
                "kode": "I21.9",
                "nama": "Acute myocardial infarction, unspecified"
             },
             "keluhan": "",
             "noKunjungan": "0304R0050217A000079",
             "pelayanan": {
                "kode": "1",
                "nama": "Rawat Inap"
             },
             "peserta": {
                "cob": {
                   "nmAsuransi": null,
                   "noAsuransi": null,
                   "tglTAT": null,
                   "tglTMT": null
                },
                "hakKelas": {
                   "keterangan": "KELAS III",
                   "kode": "3"
                },
                "informasi": {
                   "dinsos": null,
                   "noSKTM": null,
                   "prolanisPRB": null
                },
                "jenisPeserta": {
                   "keterangan": "PBI (APBN)",
                   "kode": "21"
                },
                "mr": {
                   "noMR": "971430",
                   "noTelepon": null
                },
                "nama": "MUHAMMAD JUSAR",
                "nik": "1106081301530001",
                "noKartu": "0105986780439",
                "pisa": "1",
                "provUmum": {
                   "kdProvider": "03050301",
                   "nmProvider": "BASO"
                },
                "sex": "L",
                "statusPeserta": {
                   "keterangan": "AKTIF",
                   "kode": "0"
                },
                "tglCetakKartu": "2017-11-13",
                "tglLahir": "1953-07-01",
                "tglTAT": "2053-07-01",
                "tglTMT": "2013-01-01",
                "umur": {
                   "umurSaatPelayanan": "63 tahun ,7 bulan ,23 hari",
                   "umurSekarang": "64 tahun ,4 bulan ,12 hari"
                }
             },
             "poliRujukan": {
                "kode": "",
                "nama": ""
             },
             "provPerujuk": {
                "kode": "0304R005",
                "nama": "RSI IBNU SINA"
             },
             "tglKunjungan": "2017-02-24"
          }
          ]
       }
    }
Pembuatan Rujukan
{BASE URL}/{Service Name}/Rujukan/insert
Fungsi : Insert Rujukan
Method : POST
Format : Json
Content-Type: Application/x-www-form-urlencoded
{
       "request": {
          "t_rujukan": {
             "noSep": "0301R0011017V000014",
             "tglRujukan": "2017-11-08",
             "ppkDirujuk": "0301R002",
             "jnsPelayanan": "1",
             "catatan": "test",
             "diagRujukan": "A00.1",
             "tipeRujukan": "1",
             "poliRujukan": "INT",
             "user": "Coba Ws"
          }
       }
    }                  
{
       "request": {
          "t_rujukan": {
             "noSep": "{nomor sep}",
             "tglRujukan": "{tanggal rujukan format : yyyy-mm-dd}",
             "ppkDirujuk": "{faskes dirujuk -> data di referensi faskes}",
             "jnsPelayanan": "{jenis pelayanan -> 1.R.Inap 2.R.Jalan}",
             "catatan": "{catatan rujukan}",
             "diagRujukan": "{kode diagnosa rujukan -> data di referensi diagnosa}",
             "tipeRujukan": "{tipe rujukan -> 0.penuh, 1.Partial 2.rujuk balik}",
             "poliRujukan": "{kode poli rujukan -> data di referensi poli}",
             "user": "{user pemakai}"
          }
       }
    }                     
{
       "metaData": {
          "code": "200",
          "message": "OK"
       },
       "response": {
          "rujukan": {
             "AsalRujukan": {
                "kode": "0301R001",
                "nama": "RSUP DR M JAMIL PADANG"
             },
             "diagnosa": {
                "kode": "A00.1",
                "nama": "A00.1 - Cholera due to Vibrio cholerae 01, biovar eltor"
             },
             "noRujukan": "0301R0011117B001126",
             "peserta": {
                "asuransi": "-",
                "hakKelas": null,
                "jnsPeserta": "PNS PUSAT",
                "kelamin": "Laki-Laki",
                "nama": "ZIYADUL",
                "noKartu": "0000000110156",
                "noMr": "123456",
                "tglLahir": "2008-02-05"
             },
             "poliTujuan": {
                "kode": "INT",
                "nama": "Poli Penyakit Dalam"
             },
             "tglRujukan": "2017-11-08",
             "tujuanRujukan": {
                "kode": "0301R002",
                "nama": "RS JIWA ULU GADUT"
             }
          }
       }
    }
catatan : untuk tipe rujukan 1 maka response adalah null
Update Rujukan
{BASE URL}/{Service Name}/Rujukan/update
Fungsi : Update Rujukan
Method : PUT
Format : Json
Content-Type: Application/x-www-form-urlencoded
Request
                                                    
    {
       "request": {
          "t_rujukan": {
             "noRujukan": "0301R0011117B000014",
             "ppkDirujuk": "0301R002",
             "tipe": "0",
             "jnsPelayanan": "1",
             "catatan": "test 3",
             "diagRujukan": "A00.1",
             "tipeRujukan": "1",
             "poliRujukan": "INT",
             "user": "Coba Ws"
          }
       }
    }             
                                                                              
    {
       "request": {
          "t_rujukan": {
             "noRujukan": "{nomor rujukan}",
             "ppkDirujuk": "{faskes dirujuk -> data di referensi faskes}",
             "tipe": "{tipe rujukan -> 0.penuh, 1.Partial 2.rujuk balik}",
             "jnsPelayanan": "{jenis pelayanan -> 1.R.Inap 2.R.Jalan}",
             "catatan": "{catatan rujukan}",
             "diagRujukan": "{kode diagnosa rujukan -> data di referensi diagnosa}",
             "tipeRujukan": "{tipe rujukan -> 0.penuh, 1.Partial 2.rujuk balik}",
             "poliRujukan": "{kode poli rujukan -> data di referensi poli}",
             "user": "{user pemakai}"
          }
       }
    }             
{
       "metaData": {
          "code": "200",
          "message": "OK"
       },
       "response": 0301R0011117B000014 
    }
Delete Rujukan
{BASE URL}/{Service Name}/Rujukan/delete
Fungsi : Update Rujukan
Method : DELETE
Format : Json
Content-Type: Application/x-www-form-urlencoded
Request
                                                    
    {
        "request": {
            "t_rujukan": {
                "noRujukan": "0301R0011117B000015",
                "user": "Coba Ws"
            }
        }
    }   
                                 
                                 
                                                    
    {
       "request": {
          "t_rujukan": {
             "noRujukan": "{nomor rujukan}",
             "user": "{user pemakai}"
          }
       }
    }                       
{
       "metaData": {
          "code": "200",
          "message": "OK"
       },
       "response": 0301R0011117B000014 
    }
Insert Rujukan 2.0
{BASE URL}/{Service Name}/Rujukan/2.0/insert
Fungsi : Insert Rujukan 2.0
Method : POST
Format : Json
Content-Type: Application/x-www-form-urlencoded
Request
                                                    
    {
         "request": {
                        "t_rujukan": {
                                 "noSep": "0301R0010321V000003",
                                 "tglRujukan": "2021-03-18",
                                 "tglRencanaKunjungan":"2021-03-19",
                                 "ppkDirujuk": "03010402",
                                 "jnsPelayanan": "1",
                                 "catatan": "test",
                                 "diagRujukan": "A15",
                                 "tipeRujukan": "2",
                                 "poliRujukan": "",
                                 "user": "Coba Ws"
                        }
         }
    }             
                                 
                                 
                                                    
    {
         "request": {
                "t_rujukan": {
                            "noSep": "{nomor sep}",
                            "tglRujukan": "{tanggal rujukan, format : yyyy-MM-dd}",
                            "tglRencanaKunjungan":"{tanggal rencana kunjungan, format : yyyy-MM-dd}",
                            "ppkDirujuk": "{kode faskes, 8 digit}",
                            "jnsPelayanan": "{1-> rawat inap, 2-> rawat jalan}",
                            "catatan": "{catatan}",
                            "diagRujukan": "{kode diagnosa}",
                            "tipeRujukan": "{0->Penuh, 1->Partial, 2->balik PRB}",
                            "poliRujukan": "{kosong untuk tipe rujukan 2, harus diisi jika 0 atau 1}",
                            "user": "{user ws}"
                }
{
      "metaData": {
        "code": "200",
        "message": "OK"
      },
      "response": {
        "rujukan": {
          "AsalRujukan": {
            "kode": "0301R001d",
            "nama": "RSUP DR M JAMIL PADANG"
          },
          "diagnosa": {
            "kode": "A15",
            "nama": "A15 - Respiratory tuberculosis, bacteriologically and histologically confirmed"
          },
          "noRujukan": "0301R0010321B000012",
          "peserta": {
            "asuransi": "-",
            "hakKelas": null,
            "jnsPeserta": "PBI (APBD)",
            "kelamin": "Laki-Laki",
            "nama": "FADLAN LISMI AZIZ",
            "noKartu": "0001329783085",
            "noMr": "00754610",
            "tglLahir": "2006-02-20"
          },
          "poliTujuan": {
            "kode": "",
            "nama": ""
          },
          "tglBerlakuKunjungan": "2021-06-16",
          "tglRencanaKunjungan": "2021-03-19",
          "tglRujukan": "2021-03-18",
          "tujuanRujukan": {
            "kode": "03010402",
            "nama": "PEGAMBIRAN"
          }
        }
      }
    }
Update Rujukan 2.0
{BASE URL}/{Service Name}/Rujukan/2.0/Update
Fungsi : Update Rujukan 2.0
Method : PUT
Format : Json
Content-Type: Application/x-www-form-urlencoded
Request
                                                    
    {
         "request": {
                "t_rujukan": {
                            "noRujukan": "0301R0010321V000003",
                            "tglRujukan": "2021-03-18",
                            "tglRencanaKunjungan":"2021-03-19",
                            "ppkDirujuk": "03010402",
                            "jnsPelayanan": "1",
                            "catatan": "test",
                            "diagRujukan": "A15",
                            "tipeRujukan": "2", (0 Penuh, 1 Partial, 2 balik PRB)
                            "poliRujukan": "", (kosong untuk tipe rujukan 2)
                            "user": "Coba Ws"
                }
         }
    }            
                                 
                                 
                                                    
    {
         "request": {
                "t_rujukan": {
                            "noRujukan": "{nomor rujukan}",
                            "tglRujukan": "{tanggal rujukan, format : yyyy-MM-dd}",
                            "tglRencanaKunjungan":"{tanggal rencana kunjungan, format : yyyy-MM-dd}",
                            "ppkDirujuk": "{kode faskes, 8 digit}",
                            "jnsPelayanan": "{1-> rawat inap, 2-> rawat jalan}",
                            "catatan": "{catatan}",
                            "diagRujukan": "{kode diagnosa}",
                            "tipeRujukan": "{0->Penuh, 1->Partial, 2->balik PRB}",
                            "poliRujukan": "{kosong untuk tipe rujukan 2, harus diisi jika 0 atau 1}",
                            "user": "{user ws}"
                }
         }
    }              
{
       "metaData": {
          "code": "200",
          "message": "OK"
       },
       "response": "0301R0011117B000014" 
    }
List Spesialistik Rujukan
{BASE URL}/{Service Name}/Rujukan/ListSpesialistik/PPKRujukan/{parameter 1}/TglRujukan/{parameter 2}
Fungsi : Data Spesialistik
Method : GET
Format : Json
Content-Type: Application/x-www-form-urlencoded
Parameter 1: Kode PPK Rujukan : 8 digit
Parameter 2: Tanggal rujukan format : yyyy-MM-dd
{
    "metaData": {
        "code": "200",
        "message": "Ok"
    },
    "response": {
        "list": [
                    {
                        "kodeSpesialis": "005",
                        "namaSpesialis": "Gastroenterologi-Hepatologi ",
                        "kapasitas": "0",
                        "jumlahRujukan": "0",
                        "persentase": "0,00"
                    },
                    {
                        "kodeSpesialis": "006",
                        "namaSpesialis": "Geriatri ",
                        "kapasitas": "0",
                        "jumlahRujukan": "0",
                        "persentase": "0,00"
                    },
                    {
                        "kodeSpesialis": "007",
                        "namaSpesialis": "Ginjal-Hipertensi ",
                        "kapasitas": "0",
                        "jumlahRujukan": "0",
                        "persentase": "0,00"
                    },
                    {
                        "kodeSpesialis": "008",
                        "namaSpesialis": "Hematologi - Onkologi Medik ",
                        "kapasitas": "0",
                        "jumlahRujukan": "0",
                        "persentase": "0,00"
                    },
                    {
                        "kodeSpesialis": "010",
                        "namaSpesialis": "Endokrin-Metabolik-Diabetes",
                        "kapasitas": "0",
                        "jumlahRujukan": "0",
                        "persentase": "0,00"
                    },
                    {
                        "kodeSpesialis": "017",
                        "namaSpesialis": "Bedah Onkologi ",
                        "kapasitas": "0",
                        "jumlahRujukan": "0",
                        "persentase": "0,00"
                    },
                    {
                        "kodeSpesialis": "018",
                        "namaSpesialis": "Bedah Digestif ",
                        "kapasitas": "0",
                        "jumlahRujukan": "0",
                        "persentase": "0,00"
                    },
                    {
                        "kodeSpesialis": "020",
                        "namaSpesialis": "fetomaternal",
                        "kapasitas": "0",
                        "jumlahRujukan": "0",
                        "persentase": "0,00"
                    },
                    {
                        "kodeSpesialis": "021",
                        "namaSpesialis": "onkologi ginekologi",
                        "kapasitas": "0",
                        "jumlahRujukan": "0",
                        "persentase": "0,00"
                    }
                ]
            }
        }
List Sarana 
{BASE URL}/{Service Name}/Rujukan/ListSarana/PPKRujukan/{parameter 1}
Fungsi : Data Sarana
Method : GET
Format : Json
Content-Type: Application/x-www-form-urlencoded
Parameter 1: Kode PPK Rujukan : 8 digit
{
    "metaData": {
        "code": "200",
        "message": "Ok"
    },
    "response": {
        "list": [
                    {
                        "kodeSarana": "1",
                        "namaSarana": "Rekam Medik"
                    },
                    {
                        "kodeSarana": "2",
                        "namaSarana": "Laboratorium"
                    },
                    {
                        "kodeSarana": "3",
                        "namaSarana": "Radiologi"
                    },
                    {
                        "kodeSarana": "4",
                        "namaSarana": "CT Scan"
                    },
                    {
                        "kodeSarana": "12",
                        "namaSarana": "CT Scan Kepala leher"
                    },
                    {
                        "kodeSarana": "5",
                        "namaSarana": "MRI/Magnetic Resonance Imaging"
                    },
                    {
                        "kodeSarana": "25",
                        "namaSarana": "Venografi"
                    },
                    {
                        "kodeSarana": "6",
                        "namaSarana": "Hemodialisa"
                    },
                    {
                        "kodeSarana": "7",
                        "namaSarana": "Farmasi"
                    },
                    {
                        "kodeSarana": "8",
                        "namaSarana": "Pelayanan Darah"
                    },
                    {
                        "kodeSarana": "10",
                        "namaSarana": "Pemulasaran Jenasah"
                    },
                    {
                        "kodeSarana": "13",
                        "namaSarana": "MRI Kepala leher"
                    },
                    {
                        "kodeSarana": "15",
                        "namaSarana": "USG (Doppler) daerah leher "
                    },
                    {
                        "kodeSarana": "58",
                        "namaSarana": "BNO IVP"
                    },
                    {
                        "kodeSarana": "9",
                        "namaSarana": "Ambulan"
                    },
                    {
                        "kodeSarana": "11",
                        "namaSarana": "Radiografi konvensional"
                    },
                    {
                        "kodeSarana": "14",
                        "namaSarana": "Dakriosistografi (kelenjar air mata)"
                    },
                ]
            }
        }
    }
List Rujukan Keluar RS
{BASE URL}/{Service Name}/Rujukan/Keluar/List/tglMulai/{Parameter 1}/tglAkhir/{Parameter 2}
Fungsi : List Data Rujukan Keluar RS
Method : GET
Format : Json
Content-Type: Application/x-www-form-urlencoded
Parameter 1: Tanggal Mulai
Parameter 2: Tanggal Akhir
{
    "metaData": {
        "code": "200",
        "message": "Sukses"
    },
    "response": {
        "list": [
            {
                "noRujukan": "1828R0011221B000001",
                "tglRujukan": "2021-12-06",
                "jnsPelayanan": "2",
                "noSep": "1828r0011221v000001",
                "noKartu": "0002035020396",
                "nama": "SUSANTI",
                "ppkDirujuk": "1820R001",
                "namaPpkDirujuk": "RSUD SAWERIGADING PALOPO"
            },
            {
                "noRujukan": "1828R0011221B000006",
                "tglRujukan": "2021-12-08",
                "jnsPelayanan": "2",
                "noSep": "1828R0011221V000013",
                "noKartu": "0002059334728",
                "nama": "MARINGAN HALOMOAN NAPITUPULU",
                "ppkDirujuk": "0345R001",
                "namaPpkDirujuk": "RSU INCO SOROWAKO"
            },
            {
                "noRujukan": "1828R0011221B000002",
                "tglRujukan": "2021-12-13",
                "jnsPelayanan": "2",
                "noSep": "1828r0011221v000004",
                "noKartu": "0002045650173",
                "nama": "SUTRISNO",
                "ppkDirujuk": "1820R001",
                "namaPpkDirujuk": "RSUD SAWERIGADING PALOPO"
            },
            {
                "noRujukan": "1828R0011221B000003",
                "tglRujukan": "2021-12-15",
                "jnsPelayanan": "1",
                "noSep": "1828R0011221V000011",
                "noKartu": "0002042908222",
                "nama": "SARMAH",
                "ppkDirujuk": "1820R001",
                "namaPpkDirujuk": "RSUD SAWERIGADING PALOPO"
            }
        ]
    }
}
Data Rujukan Keluar RS Berdasarkan Nomor Rujukan N
{BASE URL}/{Service Name}/Rujukan/Keluar/{Parameter 1}
Fungsi : Get Data Detail Rujukan Keluar RS Berdasarkan Nomor Rujukan
Method : GET
Format : Json
Content-Type: Application/x-www-form-urlencoded
Parameter 1: Nomor Rujukan
{
    "metaData": {
        "code": "200",
        "message": "Sukses"
    },
    "response": {
        "rujukan": {
            "noRujukan": "1828R0011221B000001",
            "noSep": "1828r0011221v000001",
            "noKartu": "0002035020396",
            "nama": "SUSANTI",
            "kelasRawat": "3",
            "kelamin": "P",
            "tglLahir": "1988-04-08",
            "tglSep": "2021-12-06",
            "tglRujukan": "2021-12-06",
            "tglRencanaKunjungan": "2021-12-06",
            "ppkDirujuk": "1820R001",
            "namaPpkDirujuk": "RSUD SAWERIGADING PALOPO",
            "jnsPelayanan": "2",
            "catatan": "TES DEVELOPMENT",
            "diagRujukan": "C46.0",
            "namaDiagRujukan": "Kaposi's sarcoma of skin",
            "tipeRujukan": "0",
            "namaTipeRujukan": "Rujukan Penuh",
            "poliRujukan": "OBG",
            "namaPoliRujukan": "OBGYN"
        }
    }
}
Data Jumlah SEP Rujukan
{BASE URL}/{Service Name}/Rujukan/JumlahSEP/{Parameter 1}/{Parameter 2}
Fungsi : Get Data Jumlah SEP yang terbentuk berdasarkan No Rujukan yang masuk ke RS
Method : GET
Format : Json
Content-Type: Application/x-www-form-urlencoded
Parameter 1: Jenis Rujukan 1 -> fktp, 2 -> fkrtl
Parameter 2: No Rujukan
{
    "metaData": {
        "code": "200",
        "message": "OK"
    },
    "response": {
        "jumlahSEP": "1"
    }
}
Insert SEP 2.0
{BASE URL}/{Service Name}/SEP/2.0/insert
Fungsi : Insert SEP versi 2.0
Method : POST
Format : Json
Content-Type: Application/x-www-form-urlencoded
Request
                                                
                                                            
        {
           "request":{
              "t_sep":{
                 "noKartu":"0001105689835",
                 "tglSep":"2021-07-30",
                 "ppkPelayanan":"0301R011",
                 "jnsPelayanan":"1",
                 "klsRawat":{
                    "klsRawatHak":"2",
                    "klsRawatNaik":"1",
                    "pembiayaan":"1",
                    "penanggungJawab":"Pribadi"
                 },
                 "noMR":"MR9835",
                 "rujukan":{
                    "asalRujukan":"2",
                    "tglRujukan":"2021-07-23",
                    "noRujukan":"RJKMR9835001",
                    "ppkRujukan":"0301R011"
                 },
                 "catatan":"testinsert RI",
                 "diagAwal":"E10",
                 "poli":{
                    "tujuan":"",
                    "eksekutif":"0"
                 },
                 "cob":{
                    "cob":"0"
                 },
                 "katarak":{
                    "katarak":"0"
                 },
                 "jaminan":{
                    "lakaLantas":"0",
                    "noLP":"12345",
                    "penjamin":{
                       "tglKejadian":"",
                       "keterangan":"",
                       "suplesi":{
                          "suplesi":"0",
                          "noSepSuplesi":"",
                          "lokasiLaka":{
                             "kdPropinsi":"",
                             "kdKabupaten":"",
                             "kdKecamatan":""
                          }
                       }
                    }
                 },
                 "tujuanKunj":"0",
                 "flagProcedure":"",
                 "kdPenunjang":"",
                 "assesmentPel":"",
                 "skdp":{
                    "noSurat":"0301R0110721K000021",
                    "kodeDPJP":"31574"
                 },
                 "dpjpLayan":"",
                 "noTelp":"081111111101",
                 "user":"Coba Ws"
              }
           }
        }
                    
                                     
                                     
                                                
        {
           "request":{
              "t_sep":{
                 "noKartu":"{nokartu BPJS}",
                 "tglSep":"{tanggal penerbitan sep format yyyy-mm-dd}",
                 "ppkPelayanan":"{kode faskes pemberi pelayanan}",
                 "jnsPelayanan":"{jenis pelayanan = 1. r.inap 2. r.jalan}",
                 "klsRawat":{
                    "klsRawatHak":"{sesuai kelas rawat peserta, 1. Kelas 1, 2. Kelas 2, 3. Kelas 3}",
                    "klsRawatNaik":"{diisi jika naik kelas rawat, 1. VVIP, 2. VIP, 3. Kelas 1, 4. Kelas 2, 5. Kelas 3, 6. ICCU, 7. ICU, 8. Diatas Kelas 1}",
                    "pembiayaan":"{1. Pribadi, 2. Pemberi Kerja, 3. Asuransi Kesehatan Tambahan. diisi jika naik kelas rawat}",
                    "penanggungJawab":"{Contoh: jika pembiayaan 1 maka penanggungJawab=Pribadi. diisi jika naik kelas rawat}"
                 },
                 "noMR":"{nomor medical record RS}",
                 "rujukan":{
                    "asalRujukan":"{asal rujukan ->1.Faskes 1, 2. Faskes 2(RS)}",
                    "tglRujukan":"{tanggal rujukan format: yyyy-mm-dd}",
                    "noRujukan":"{nomor rujukan}",
                    "ppkRujukan":"{kode faskes rujukam -> baca di referensi faskes}"
                 },
                 "catatan":"{catatan peserta}",
                 "diagAwal":"{diagnosa awal ICD10 -> baca di referensi diagnosa}",
                 "poli":{
                    "tujuan":"{kode poli -> baca di referensi poli}",
                    "eksekutif":"{poli eksekutif -> 0. Tidak 1.Ya}""
                 },
                 "cob":{
                    "cob":"{cob -> 0.Tidak 1. Ya}"
                 },
                 "katarak":{
                    "katarak":"{katarak --> 0.Tidak 1.Ya}"
                 },
                 "jaminan":{
                    "lakaLantas":" 0 : Bukan Kecelakaan lalu lintas [BKLL], 1 : KLL dan bukan kecelakaan Kerja [BKK], 2 : KLL dan KK, 3 : KK",
                    "noLP":"{No. LP}",
                    "penjamin":{
                       "tglKejadian":"{tanggal kejadian KLL format: yyyy-mm-dd}",
                       "keterangan":"{Keterangan Kejadian KLL}",
                       "suplesi":{
                          "suplesi":"{Suplesi --> 0.Tidak 1. Ya}",
                          "noSepSuplesi":"{No.SEP yang Jika Terdapat Suplesi}",
                          "lokasiLaka":{
                             "kdPropinsi":"{Kode Propinsi}",
                             "kdKabupaten":"{Kode Kabupaten}",
                             "kdKecamatan":"{Kode Kecamatan}"
                          }
                       }
                    }
                 },
                 "tujuanKunj":{"0": Normal, 
                               "1": Prosedur, 
                               "2": Konsul Dokter},
                 "flagProcedure":{"0": Prosedur Tidak Berkelanjutan, 
                                  "1": Prosedur dan Terapi Berkelanjutan} ==> diisi "" jika tujuanKunj = "0",
                 "kdPenunjang":{"1": Radioterapi, 
                                "2": Kemoterapi, 
                                "3": Rehabilitasi Medik, 
                                "4": Rehabilitasi Psikososial, 
                                "5": Transfusi Darah, 
                                "6": Pelayanan Gigi, 
                                "7": Laboratorium, 
                                "8": USG, 
                                "9": Farmasi, 
                                "10": Lain-Lain, 
                                "11": MRI, 
                                "12": HEMODIALISA} ==> diisi "" jika tujuanKunj = "0",
                 "assesmentPel":{"1": Poli spesialis tidak tersedia pada hari sebelumnya, 
                                 "2": Jam Poli telah berakhir pada hari sebelumnya, 
                                 "3": Dokter Spesialis yang dimaksud tidak praktek pada hari sebelumnya, 
                                 "4": Atas Instruksi RS} ==> diisi jika tujuanKunj = "2" atau "0" (politujuan beda dengan poli rujukan dan hari beda),
                                 "5": Tujuan Kontrol,
                 "skdp":{
                    "noSurat":"{Nomor Surat Kontrol}",
                    "kodeDPJP":"{kode dokter DPJP --> baca di referensi dokter DPJP}"
                 },
                 "dpjpLayan":"000002", (tidak diisi jika jnsPelayanan = "1" (RANAP),
                 "noTelp":"{nomor telepon}",
                 "user":"{user pembuat SEP}"
              }
           }
        }
{
    "metaData": {
        "code": "200",
        "message": "Sukses"
    },
    "response": {
        "sep": {
            "assestmenPel": "1",
            "catatan": "testinsert RJ",
            "diagnosa": "A15 - Respiratory tuberculosis, bacteriologically and histologically confirmed",
            "flagProcedure": "",
            "informasi": {
                "dinsos": null,
                "eSEP": "True",
                "noSKTM": null,
                "prolanisPRB": null
            },
            "jnsPelayanan": "R.Jalan",
            "kdPenunjang": "",
            "kdPoli": "INT",
            "kelasRawat": "-",
            "noRujukan": "0050B1070223P000004",
            "noSep": "0301R0010323V000039",
            "penjamin": "-",
            "peserta": {
                "asuransi": "-",
                "hakKelas": "Kelas 3",
                "jnsPeserta": "PBI (APBN)",
                "kelamin": "Perempuan",
                "nama": "ARSTNUU",
                "noKartu": "0002802875185",
                "noMr": "MR5185",
                "tglLahir": "1944-02-24"
            },
            "poli": "PENYAKIT DALAM",
            "poliEksekutif": "Tidak",
            "tglSep": "2023-03-30",
            "tujuanKunj": "2"
        }
    }
}
Update SEP 2.0
{BASE URL}/{Service Name}/SEP/2.0/update
Fungsi : Update SEP versi 2.0
Method : PUT
Format : Json
Content-Type: Application/x-www-form-urlencoded
Request
                                                
                                                            
                                                    
    {
     "request": {
        "t_sep": {
                "noSep": "0301R0110521V000037",
                "klsRawat":{
                                "klsRawatHak":"3",
                                "klsRawatNaik":"",
                                "pembiayaan":"",
                                "penanggungJawab":""
                              },
                "noMR": "00469120",
                "catatan": "",
                "diagAwal": "E10",
                "poli": {
                        "tujuan": "IGD",
                        "eksekutif": "0"
                },
                "cob": {
                        "cob": "0"
                },
                "katarak": {
                        "katarak": "0"
                },
                "jaminan": {
                        "lakaLantas": "0",
                        "penjamin": {
                                "tglKejadian": "",
                                "keterangan": "",
                                "suplesi": {
                                        "suplesi": "0",
                                        "noSepSuplesi": "",
                                        "lokasiLaka": {
                                                "kdPropinsi": "",
                                                "kdKabupaten": "",
                                                "kdKecamatan": ""
                                        }
                                }
                        }
                },
                "dpjpLayan":"46",
                "noTelp": "08522038363",
                "user": "Cobaws"
        }
      }
    }        
                                 
                                 
                    
                                     
                                     
                                                
                                                    
    {
     "request": {
        "t_sep": {
                "noSep": "{nomor sep}",
                "klsRawat":{
                                "klsRawatHak":"3",
                                "klsRawatNaik":"",
                                "pembiayaan":"",
                                "penanggungJawab":""
                              },
                "noMR": "{nomor medical record RS}",
                "catatan": "{catatan peserta}",
                "diagAwal": "{diagnosa awal ICD10 -> baca di referensi diagnosa}",
                "poli": {
                        "tujuan": "IGD",
                        "eksekutif": "{poli eksekutif -> 0. Tidak 1.Ya}"
                },
                "cob": {
                        "cob": "{cob -> 0.Tidak 1. Ya}"
                },
                "katarak": {
                        "katarak": "{katarak --> 0.Tidak 1.Ya}"
                },
                "jaminan": {
                        "lakaLantas":" 0 : Bukan Kecelakaan lalu lintas [BKLL], 1 : KLL dan bukan kecelakaan Kerja [BKK], 2 : KLL dan KK, 3 : KK",
                        "penjamin": {
                                "tglKejadian": "{tgl kejadian KLL (yyyy-mm-dd)}",
                                "keterangan": "{keterangan kejadian}",
                                "suplesi": {
                                        "suplesi": "0",
                                        "noSepSuplesi": "{no SEP suplesi --> diambil dari Potensi Suplesi Jasa Raharja}",
                                        "lokasiLaka": {
                                                "kdPropinsi": "{kode propinsi}",
                                                "kdKabupaten": "{kode kabupaten}",
                                                "kdKecamatan": "{kode kecamatan}"
                                        }
                                }
                        }
                },
                "dpjpLayan":"46",
                "noTelp": "{nomor telepon peserta/pasien}",
                "user": "{user pembuat SEP}"
        }
      }
    }        
Delete SEP 2.0
{BASE URL}/{Service Name}/SEP/2.0/delete
Fungsi : Hapus SEP versi 2.0
Method : DELETE
Format : Json
Content-Type: Application/x-www-form-urlencoded
Request
                                                
                                                            
                                                    
                                                
    {
       "request": {
          "t_sep": {
             "noSep": "0301R0011017V000007",
             "user": "Coba Ws"
          }
       }
    }
                
                                     
                                     
                                                
                                                    
    {
       "request": {
          "t_sep": {
             "noSep": "{nomor SEP}",
             "user": "{user pengguna SEP}"
          }
       }
    }       
{
            metaData: 
                {
                code: "200"
                message: "OK"
                }
            response: "0301R0011017V000007"
        }
Update Tanggal Pulang 2.0 
{BASE URL}/{Service Name}/SEP/2.0/updtglplg
Fungsi : Update tanggal pulang SEP 2.0
Method : PUT
Format : Json
Content-Type: Application/x-www-form-urlencoded
Request
                                                
            {
                "request":{
                    "t_sep":{
                        "noSep": "0301R0110121V000829",
                        "statusPulang":"4",
                        "noSuratMeninggal":"325/K/KMT/X/2021",
                        "tglMeninggal":"2021-02-10",
                        "tglPulang":"2021-02-14",
                        "noLPManual":"",
                        "user":"coba"
                    }
                }
            }
                                     
                                     
                                                
            {
                "request":{
                    "t_sep":{
                        "noSep": "{nosep}",
                        "statusPulang":"{1:Atas Persetujuan Dokter, 3:Atas Permintaan Sendiri, 4:Meninggal, 5:Lain-lain}",
                        "noSuratMeninggal":"{diisi jika statusPulang 4, selain itu kosong}",
                        "tglMeninggal":"{diisi jika statusPulang 4, selain itu kosong. format yyyy-MM-dd}",
                        "tglPulang":"{format yyyy-MM-dd}",
                        "noLPManual":"{diisi jika SEPnya adalah KLL}",
                        "user":"{user}"
                    }
                }
            }
{
                "metaData": {
                    "code": "200",
                    "message": "Ok"
                },
                "response": null
            }
List Data Update Tanggal Pulang
{BASE URL}/{Service Name}/Sep/updtglplg/list/bulan/{Parameter 1}/tahun/{Parameter 2}/{Parameter 3}
Fungsi : Get List Data Update Tanggal Pulang
Method : GET
Format : Json
Content-Type: Application/x-www-form-urlencoded
Parameter 1: Bulan (1-12)
Parameter 2: Tahun
Parameter 3: Filter (Apabila dikosongkan akan menampilkan semua data pada bulan dan tahun pilihan)

                                
{
    "metaData": {
        "code": "200",
        "message": "Sukses"
    },
    "response": {
        "list": [
            {
                "noSep": "0138R0221221V000032",
                "noSepUpdating": "0112R0761221V000014",
                "jnsPelayanan": "1",
                "ppkTujuan": "0138R022",
                "noKartu": "0002047251712",
                "nama": "SURIP",
                "tglSep": "2021-12-13",
                "tglPulang": "2021-12-15",
                "status": "",
                "tglMeninggal": "",
                "noSurat": "",
                "keterangan": "3.1.Peserta NoKa 0002047251712 telah mendapat Pelayanan R.Inap pada tgl. 13/12/2021 dan belum dipulangkan di RS CITRA MEDIKA DEPOK Dgn No.SEP 0138R0221221V000032",
                "user": "AdminUtam"
            }
        ]
    }
}
Integrasi SEP dan Inacbg
{BASE URL}/{Service Name}/sep/cbg/{parameter}
Fungsi : Pencarian No.SEP untuk Aplikasi Inacbg 4.1
Method : GET
Format : Xml
Content-Type: Application/x-www-form-urlencoded
Parameter: Nomor SEP
{
          "metaData": {
            "code": "200",
            "message": "OK"
          },
          "response": {
            "pesertasep": {
              "kelamin": "P",
              "klsRawat": "3",
              "nama": "ANNA MIKRAD BA.",
              "noKartuBpjs": "0000001112958",
              "noMr": "0",
              "noRujukan": "222",
              "tglLahir": "1960-01-26",
              "tglPelayanan": "2016-09-22",
              "tktPelayanan": "2"
            }
          }
        }        
Data SEP Internal 
{BASE URL}/{Service Name}/SEP/Internal/{parameter 1}
Fungsi : Data SEP Internal
Method : GET
Format : Json
Content-Type: Application/x-www-form-urlencoded
Parameter: Nomor SEP : 19 digit

                                    
{
    "metaData": {
        "code": "200",
        "message": "OK"
    },
    "response": {
        "count": "3",
        "list": [
            {
                "tujuanrujuk": "SAR",
                "nmtujuanrujuk": "SARAF",
                "nmpoliasal": "PENYAKIT DALAM",
                "tglrujukinternal": "2020-11-19",
                "nosep": "0905R0031020V000397",
                "nosepref": "0905R0031120V004160",
                "ppkpelsep": "0905R003",
                "nokapst": "0000038761391",
                "tglsep": "2020-10-02",
                "nosurat": "0905R0031120N000922",
                "flaginternal": "0",
                "kdpoliasal": "0000038761391",
                "kdpolituj": "SAR",
                "kdpenunjang": "0",
                "nmpenunjang": null,
                "diagppk": "I15",
                "kddokter": "24271",
                "nmdokter": "dr. Nurhayana Lubis, Sp.S",
                "flagprosedur": null,
                "opsikonsul": "1",
                "flagsep": "False",
                "fuser": "0905R003_anhar",
                "fdate": "2020-11-19",
                "nmdiag": "Secondary hypertension"
            },
            {
                "tujuanrujuk": "SAR",
                "nmtujuanrujuk": "SARAF",
                "nmpoliasal": "PENYAKIT DALAM",
                "tglrujukinternal": "2020-10-20",
                "nosep": "0905R0031020V000397",
                "nosepref": "0905R0031020V003695",
                "ppkpelsep": "0905R003",
                "nokapst": "0000038761391",
                "tglsep": "2020-10-02",
                "nosurat": "0905R0031020N000912",
                "flaginternal": "0",
                "kdpoliasal": "0000038761391",
                "kdpolituj": "SAR",
                "kdpenunjang": "0",
                "nmpenunjang": null,
                "diagppk": "I15",
                "kddokter": "24271",
                "nmdokter": "dr. Nurhayana Lubis, Sp.S",
                "flagprosedur": null,
                "opsikonsul": "1",
                "flagsep": "False",
                "fuser": "0905R003_ema",
                "fdate": "2020-10-20",
                "nmdiag": "Secondary hypertension"
            },
            {
                "tujuanrujuk": "SAR",
                "nmtujuanrujuk": "SARAF",
                "nmpoliasal": "PENYAKIT DALAM",
                "tglrujukinternal": "2020-10-06",
                "nosep": "0905R0031020V000397",
                "nosepref": "0905R0031020V000874",
                "ppkpelsep": "0905R003",
                "nokapst": "0000038761391",
                "tglsep": "2020-10-02",
                "nosurat": "0905R0031020N000230",
                "flaginternal": "0",
                "kdpoliasal": "0000038761391",
                "kdpolituj": "SAR",
                "kdpenunjang": "0",
                "nmpenunjang": null,
                "diagppk": "I15",
                "kddokter": "24271",
                "nmdokter": "dr. Nurhayana Lubis, Sp.S",
                "flagprosedur": null,
                "opsikonsul": "1",
                "flagsep": "False",
                "fuser": "0905R003_ema",
                "fdate": "2020-10-06",
                "nmdiag": "Secondary hypertension"
            }
        ]
    }
}        

Hapus SEP Internal
{BASE URL}/{Service Name}/SEP/Internal/delete
Fungsi : Hapus SEP Internal
Method : DELETE
Format : Json
Content-Type: Application/x-www-form-urlencoded
Request
                                                
        {
           "request": {
              "t_sep": {
                 "noSep": "0301R0110421V000385",
                 "noSurat": "0301R0110421N000088",
                 "tglRujukanInternal": "2021-04-11",
                 "kdPoliTuj": "PAR",
                 "user": "Coba Ws"
              }
           }
        } 
                    
                                     
                                     
                                                
        {
           "request": {
              "t_sep": {
                 "noSep": "{nosep}",
                 "noSurat": "{nosurat}",
                 "tglRujukanInternal": "{tglRujukanInternal, format : yyyy-MM-dd",
                 "kdPoliTuj": "{kdPoli, 3 digit}",
                 "user": "{user}"
              }
           }
        }
Finger Print
Get Finger Print
{BASE URL}/{Service Name}/SEP/FingerPrint/Peserta/{parameter1}/TglPelayanan/{parameter2}


Fungsi : Pencarian data fingerprint
Method : GET
Format : Json
Content-Type: Application/x-www-form-urlencoded
Parameter1: Nomor Kartu Peserta
Parameter2: Tanggal Pelayanan

                                    
Jika telah dilakukan validasi fingerprint: 
{
    "metaData": {
        "code": "200",
        "message": "Ok"
    },
    "response": {
        "kode": "1",
        "status": "Peserta telah melakukan validasi finger print pada tanggal 2020-01-21"
    }
}

Jika belum dilakukan validasi fingerprint:
{
    "metaData": {
        "code": "200",
        "message": "Ok"
    },
    "response": {
        "kode": "0",
        "status": "Peserta belum melakukan validasi finger print"
    }
}

Get List Finger Print
{BASE URL}/{Service Name}/SEP/FingerPrint/List/Peserta/TglPelayanan/{parameter}
Fungsi : List Finger Print
Method : GET
Format : Json
Content-Type: Application/x-www-form-urlencoded
Parameter: Tanggal Pelayanan
{
    "metaData": {
        "code": "200",
        "message": "Ok"
    },
    "response": {
        "list": [
            {
                "noKartu": "0001244842648",
                "noSEP": "0301R0110120V009210"
            },
            {
                "noKartu": "0001244856813",
                "noSEP": "0301R0110120V009041"
            },
            {
                "noKartu": "0001244957229",
                "noSEP": "0301R0110120V009213"
            }
        ]
    }
}
Get Random Question
{BASE URL}/{Service Name}/SEP/FingerPrint/randomquestion/faskesterdaftar/nokapst/{parameter1}/tglsep/{parameter2}
Fungsi : Menampilkan Random Question
Method : GET
Format : Json
Content-Type: Application/x-www-form-urlencoded
Parameter1: Nomor Kartu Peserta
Parameter2: Tanggal Pelayanan
{
    "metaData": {
        "code": "200",
        "message": "Ok"
    },
    "response": {
        "faskes": [
            {
                "kode": "0177B030",
                "nama": "Klinik Citra Madina"
            },
            {
                "kode": "21061801",
                "nama": "DAMAU"
            },
            {
                "kode": "01031201",
                "nama": "PEUKAN BADA"
            }
        ]
    }
}

Post Random Answer
{BASE URL}/{Service Name}/SEP/FingerPrint/randomanswer
Fungsi : POST Random Answer
Method : POST
Format : Json
Content-Type: Application/x-www-form-urlencoded
Request
                                                
{
  "request": {
    "t_sep": {
      "noKartu": "0002340532179",
      "tglSep": "2023-03-06",
      "jenPel":"1",
      "ppkPelSep": "0301R001",
      "tglLahir": "",
      "ppkPst": "09030300",
      "user": "user"
    }
  }
}
                    
                                     
                                     
                                                
{
  "request": {
    "t_sep": {
      "noKartu": "{nomor kartu}",
      "tglSep": "{tanggal SEP}",
      "jenPel":"{jenis pelayanan}",
      "ppkPelSep": "{ppk pelayanan}",
      "tglLahir": "{tgl lahir}",
      "ppkPst": "{ppk peserta}",
      "user": "{user}"
    }
  }
}
{
    "metaData": {
        "code": "200",
        "message": "Sukses"
    },
    "response": "False" => Jika jawaban benar = True, jika jawaban salah = False
}

Referensi
Diagnosa
{Base URL}/{Service Name}/referensi/diagnosa/{parameter}
Fungsi : Pencarian data diagnosa (ICD-10)
Method : GET
Format : Json
Content-Type: application/json; charset=utf-8
Parameter : Kode atau Nama Diagnosa
                                        
    {
        "metaData": 
            {
                "code": "200",
                "message": "Sukses"
            },
        "response": 
            {
            "diagnosa": 
                [
                    {
                        "kode": "A04",
                        "nama": "A04 - Other bacterial intestinal infections"
                    }
                ],
            }
    }
Poli
{Base URL}/{Service Name}referensi/poli/{Parameter}
Fungsi : Pencarian data poli
Method : GET
Format : Json
Content-Type: application/json; charset=utf-8
Parameter : Kode atau Nama Poli
                                
    {
        "metaData": 
            {
            "code": "200",
            "message": "Sukses"
            },
        "response": 
            {
                "poli": 
                 [
                    {
                        "kode": "ICU",
                        "nama": "Intensive Care Unit"
                    },
                    {
                        "kode": "INT",
                        "nama": "Poli Penyakit Dalam"
                    },
                    {
                        "kode": "IVP",
                        "nama": "Intravena Pydografi"
                    }
                ]
            }
    }                     
Fasilitas Kesehatan
{Base URL}/{Service Name}/referensi/faskes/{Parameter 1}/{Parameter 2}
Fungsi : Pencarian data fasilitas kesehatan
Method : GET
Format : Json
Content-Type: application/json; charset=utf-8
Parameter 1 : nama atau kode faskes
Parameter 2 : Jenis Faskes (1. Faskes 1, 2. Faskes 2/RS)
                                
    {
        "metaData": 
            {
                "code": "200",
                "message": "Sukses"
            },
        "response": 
            {
            "faskes": 
                [
                    {
                        "kode": "00161001",
                        "nama": "PUSKESMAS SANGIRAN - KAB. SIMEULUE"
                    },
                    {
                        "kode": "00161002",
                        "nama": "PUSKESMAS SIMEULUE - KAB. SIMEULUE"
                    }
                ]
            }
    }
Dokter DPJP (Pencarian data dokter DPJP untuk pengisian DPJP Layan)
{Base URL}/{Service Name}/referensi/dokter/pelayanan/{Parameter 1}/tglPelayanan/{Parameter 2}/Spesialis/{Parameter 3}
Fungsi : Pencarian data dokter DPJP untuk pengisian DPJP Layan
Method : GET
Format : Json
Content-Type: application/json; charset=utf-8
Parameter 1 : Jenis Pelayanan (1. Rawat Inap, 2. Rawat Jalan)
Parameter 2 : Tgl.Pelayanan/SEP (yyyy-mm-dd)
Parameter 3 : Kode Spesialis/Subspesialis
                                
        {
           "metaData":{
              "code":"200",
              "message":"Sukses"
           },
           "response":{
              "list":[
                 {
                    "kode":"31486",
                    "nama":"Satro Jadhit, dr"
                 },
                 {
                    "kode":"31492",
                    "nama":"Satroni Lawa, dr"
                 }
              ]
           }
        }
Propinsi
{Base URL}/{Service Name}/referensi/propinsi
Fungsi : Pencarian data propinsi
Method : GET
Format : Json
Content-Type: application/json; charset=utf-8
                                
        {
           "metaData":{
              "code":"200",
              "message":"Sukses"
           },
           "response":{
              "list":[
                 {
                    "kode":"16",
                    "nama":"Bali"
                 },
                 {
                    "kode":"17",
                    "nama":"Banten"
                 }
              ]
           }
        }
Kabupaten
{Base URL}/{Service Name}/referensi/kabupaten/propinsi/{paramater 1}
Fungsi : Pencarian data propinsi
Method : GET
Format : Json
Content-Type: application/json; charset=utf-8
Parameter 1 : Kode Propinsi
                                
        {
           "metaData":{
              "code":"200",
              "message":"Sukses"
           },
           "response":{
              "list":[
                 {
                    "kode":"0227",
                    "nama":"KAB. BADUNG"
                 },
                 {
                    "kode":"0230",
                    "nama":"KAB. BANGLI"
                 }
              ]
           }
        }
Kecamatan
{Base URL}/{Service Name}/referensi/kecamatan/kabupaten/{paramater 1}
Fungsi : Pencarian data kecamatan
Method : GET
Format : Json
Content-Type: application/json; charset=utf-8
Parameter 1 : Kode Kabupaten
                                
        {
           "metaData":{
              "code":"200",
              "message":"Sukses"
           },
           "response":{
              "list":[
                 {
                    "kode":"3139",
                    "nama":"KUTA"
                 },
                 {
                    "kode":"3135",
                    "nama":"KUTA UTARA"
                 }
              ]
           }
        }
Diagnosa Program PRB
{Base URL}/{Service Name}/referensi/diagnosaprb
Fungsi : Pencarian data diagnosa program PRB
Method : GET
Format : Json
Content-Type: application/json; charset=utf-8
                                
    {
      "metaData": {
        "code": "200",
        "message": "Sukses"
      },
      "response": {
        "list": [
          {
            "kode": "01 ",
            "nama": "Diabetes Mellitus"
          },
          {
            "kode": "02 ",
            "nama": "Hypertensi"
          },
          {
            "kode": "03 ",
            "nama": "Asthma"
          },
          {
            "kode": "04 ",
            "nama": "Penyakit Jantung"
          },
          {
            "kode": "05 ",
            "nama": "PPOK (Penyakit Paru Obstruktif Kronik)"
          },
          {
            "kode": "06 ",
            "nama": "Schizophrenia"
          },
          {
            "kode": "07 ",
            "nama": "Stroke"
          },
          {
            "kode": "08 ",
            "nama": "Epilepsi"
          },
          {
            "kode": "09 ",
            "nama": "Systemic Lupus Erythematosus"
          }
        ]
      }
    }
Obat Generik Program PRB
{Base URL}/{Service Name}/referensi/obatprb/{Parameter 1}
Fungsi : Pencarian data obat generik PRB
Method : GET
Format : Json
Content-Type: application/json; charset=utf-8
Parameter 1: nama obat generik
                                
    {
      "metaData": {
        "code": "200",
        "message": "Sukses"
      },
      "response": {
        "list": [
          {
            "kode": "00019100017",
            "nama": "Analog Insulin Long Acting inj 100 UI/ml"
          },
          {
            "kode": "00012300016",
            "nama": "Analog Insulin Mix Acting inj 100 UI/ml"
          }
        ]
      }
    }
Procedure / Tindakan (Hanya Untuk Lembar Pengajuan Klaim)
{Base URL}/{Service Name}/referensi/procedure/{Parameter}
Fungsi : Pencarian data procedure/tindakan
Method : GET
Format : Json
Content-Type: application/json; charset=utf-8
Parameter : nama atau kode procedure
                                
        {  
            "metaData":{  
                "code":"200",
                "message":"Sukses"
            },
            "response":{  
                "procedure":[  
                    {  
                    "kode":"21.05",
                    "nama":"21.05 - Control of epistaxis by (transantral) ligation of the maxillary artery"
                    },
                    {  
                    "kode":"382.2",
                    "nama":"382.2 - Chronic atticoantral suppurative otitis media"
                    }
                ]
            }
        }
Kelas Rawat (Hanya Untuk Lembar Pengajuan Klaim)
{Base URL}/{Service Name}/referensi/kelasrawat
Fungsi : Pencarian data kelas rawat
Method : GET
Format : Json
Content-Type: application/json; charset=utf-8
                                
{
    "metaData": {
        "code": "200",
        "message": "Sukses"
    },
    "response": {
        "list": [
            {
                "kode": "1",
                "nama": "VVIP"
            },
            {
                "kode": "2",
                "nama": "VIP"
            },
            {
                "kode": "3",
                "nama": "Kelas 1"
            },
            {
                "kode": "4",
                "nama": "Kelas 2"
            },
            {
                "kode": "5",
                "nama": "Kelas 3"
            }
        ]
    }
}
Dokter (Hanya Untuk Lembar Pengajuan Klaim, Pencarian data dokter dalam faskes sesuai consid)
{Base URL}/{Service Name}/referensi/dokter/{Parameter}
Fungsi : Pencarian data dokter dalam faskes sesuai consid
Method : GET
Format : Json
Content-Type: application/json; charset=utf-8
Parameter : nama dokter/DPJP
                                
        {
           "metaData":{
              "code":"200",
              "message":"Sukses"
           },
           "response":{
              "list":[
                 {
                    "kode":"3",
                    "nama":"Satro Jadhit, dr"
                 },
                 {
                    "kode":"2",
                    "nama":"Satroni Lawa, dr"
                 }
              ]
           }
        }
Spesialistik (Hanya Untuk Lembar Pengajuan Klaim)
{Base URL}/{Service Name}/referensi/spesialistik
Fungsi : Pencarian data spesialistik
Method : GET
Format : Json
Content-Type: application/json; charset=utf-8
                                
        {
           "metaData":{
              "code":"200",
              "message":"Sukses"
           },
           "response":{
              "list":[
                 {
                    "kode":"11",
                    "nama":"Spesialis Anestesiologi dan Reanimasi"
                 },
                 {
                    "kode":"27",
                    "nama":"Spesialis Forensik"
                 },
                 {
                    "kode":"28",
                    "nama":"Spesialis Onkologi"
                 }
              ]
           }
        }
Ruang Rawat (Hanya Untuk Lembar Pengajuan Klaim)
{Base URL}/{Service Name}/referensi/ruangrawat
Fungsi : Pencarian data ruang rawat
Method : GET
Format : Json
Content-Type: application/json; charset=utf-8
                                
        {
           "metaData":{
              "code":"200",
              "message":"Sukses"
           },
           "response":{
              "list":[
                 {
                    "kode":"3",
                    "nama":"Ruang Melati I"
                 },
                 {
                    "kode":"4",
                    "nama":"Ruang Melati II"
                 },
                 {
                    "kode":"5",
                    "nama":"Ruang Kamboja I"
                 },
                 {
                    "kode":"6",
                    "nama":"Ruang Kamboja II"
                 },
                 {
                    "kode":"9",
                    "nama":"Ruang Bougenvile"
                 }
              ]
           }
        }
Cara Keluar (Hanya Untuk Lembar Pengajuan Klaim)
{Base URL}/{Service Name}/referensi/carakeluar
Fungsi : Pencarian data cara keluar
Method : GET
Format : Json
Content-Type: application/json; charset=utf-8
                                
        {
           "metaData":{
              "code":"200",
              "message":"Sukses"
           },
           "response":{
              "list":[
                 {
                    "kode":"1",
                    "nama":"Atas Persetujuan Dokter"
                 },
                 {
                    "kode":"2",
                    "nama":"Dirujuk"
                 },
                 {
                    "kode":"3",
                    "nama":"Atas Permintaan Sendiri"
                 },
                 {
                    "kode":"4",
                    "nama":"Meninggal"
                 },
                 {
                    "kode":"5",
                    "nama":"Lain-Lain"
                 }
              ]
           }
        }
Pasca Pulang (Hanya Untuk Lembar Pengajuan Klaim)
{Base URL}/{Service Name}/referensi/pascapulang
Fungsi : Pencarian data pasca pulang
Method : GET
Format : Json
Content-Type: application/json; charset=utf-8
                                
        {
           "metaData": {
              "code": "200",
              "message": "Sukses"
           },
           "response": {
              "list": [
                 {
                    "kode": "1",
                    "nama": "Sembuh"
                 },
                 {
                    "kode": "2",
                    "nama": "Dirujuk"
                 },
                 {
                    "kode": "3",
                    "nama": "Pulang Paksa"
                 },
                 {
                    "kode": "4",
                    "nama": "Meninggal"
                 },
                 {
                    "kode": "5",
                    "nama": "Lain-Lain"
                 }
              ]
           }
        }



Lembar Pengajuan Klaim
{BASE URL}/{Service Name}/LPK/insert
Fungsi : mengirim data pelayanan pasien untuk pengajuan klaim ke BPJS, terkait dengan SEP yang sudah diterbitkan.
Method : POST
Format : Json
Content-Type: Application/x-www-form-urlencoded
Request
  {
           "request": {
              "t_lpk": {
                 "noSep": "0301R0011017V000015",
                 "tglMasuk": "2017-10-30",
                 "tglKeluar": "2017-10-30",
                 "jaminan": "1",
                 "poli": {
                    "poli": "INT"
                 },
                 "perawatan": {
                    "ruangRawat": "1",
                    "kelasRawat": "1",
                    "spesialistik": "1",
                    "caraKeluar": "1",
                    "kondisiPulang": "1"
                 },
                 "diagnosa": [
                    {
                       "kode": "N88.0",
                       "level": "1"
                    },
                    {
                       "kode": "A00.1",
                       "level": "2"
                    }
                 ],
                 "procedure": [
                    {
                       "kode": "00.82"
                    },
                    {
                       "kode": "00.83"
                    }
                 ],
                 "rencanaTL": {
                    "tindakLanjut": "1",
                    "dirujukKe": {
                       "kodePPK": ""
                    },
                    "kontrolKembali": {
                       "tglKontrol": "2017-11-10",
                       "poli": ""
                    }
                 },
                 "DPJP": "3",
                 "user": "Coba Ws"
              }
           }
        }               
{
           "request": {
              "t_lpk": {
                 "noSep": "{nomor sep}",
                 "tglMasuk": "{tanggal masuk format yyyy-mm-dd}",
                 "tglKeluar": "{tanggal keluar format yyyy-mm-dd}",
                 "jaminan": "{penjamin -> 1. JKN}",
                 "poli": {
                    "poli": "{kode poli -> data di referensi poli}"
                 },
                 "perawatan": {
                    "ruangRawat": "{ruang rawat -> data di referensi ruang rawat}",
                    "kelasRawat": "{kelas rawat -> data di referensi kelas rawat}",
                    "spesialistik": "{spesialistik -> data di referensi spesialistik}",
                    "caraKeluar": "{cara keluar -> data di referensi cara keluar}",
                    "kondisiPulang": "{kondisi pulang -> data di referensi kondisi pulang}"
                 },
                 "diagnosa": [
                    {
                       "kode": "{kode diagnosa  -> data di referensi diagnosa}",
                       "level": "{level diagnosa -> 1.Primer 2.Sekunder}"
                    },
                    {
                       "kode": "{kode diagnosa  -> data di referensi diagnosa}",
                       "level": "{level diagnosa -> 1.Primer 2.Sekunder}"
                    }
                 ],
                 "procedure": [
                    {
                       "kode": "{kode procedure -> data di referensi procedure/tindakan}"
                    },
                    {
                       "kode": "{kode procedure -> data di referensi procedure/tindakan}"
                    }
                 ],
                 "rencanaTL": {
                    "tindakLanjut": "{tindak lanjut -> 1:Diperbolehkan Pulang, 2:Pemeriksaan Penunjang, 3:Dirujuk Ke, 4:Kontrol Kembali}",
                    "dirujukKe": {
                       "kodePPK": "{kode faskes -> data di referensi faskes}"
                    },
                    "kontrolKembali": {
                       "tglKontrol": "{tanggal kontrol kembali format : yyyy-mm-dd}",
                       "poli": "{kode poli -> data di referensi poli}"
                    }
                 },
                 "DPJP": "{kode dokter dpjp -> data di referensi dokter}",
                 "user": "{user pemakai}"
              }
           }
        }                  
Response
{
           "metaData": {
              "code": "200",
              "message": "OK"
           },
           "response": "0301R0011017V000015"      
        }
Update LPK
{BASE URL}/{Service Name}/LPK/update
Fungsi : Update data pelayanan pasien untuk pengajuan klaim ke BPJS, terkait dengan SEP yang sudah diterbitkan.
Method : PUT
Format : Json
Content-Type: Application/x-www-form-urlencoded
{
           "request": {
              "t_lpk": {
                 "noSep": "0301R0011017V000015",
                 "tglMasuk": "2017-10-30",
                 "tglKeluar": "2017-10-30",
                 "jaminan": "1",
                 "poli": {
                    "poli": "INT"
                 },
                 "perawatan": {
                    "ruangRawat": "1",
                    "kelasRawat": "1",
                    "spesialistik": "1",
                    "caraKeluar": "1",
                    "kondisiPulang": "1"
                 },
                 "diagnosa": [
                    {
                       "kode": "N88.0",
                       "level": "1"
                    },
                    {
                       "kode": "A00.1",
                       "level": "2"
                    }
                 ],
                 "procedure": [
                    {
                       "kode": "00.82"
                    },
                    {
                       "kode": "00.83"
                    }
                 ],
                 "rencanaTL": {
                    "tindakLanjut": "1",
                    "dirujukKe": {
                       "kodePPK": ""
                    },
                    "kontrolKembali": {
                       "tglKontrol": "2017-11-10",
                       "poli": ""
                    }
                 },
                 "DPJP": "3",
                 "user": "Coba Ws"
              }
           }
        }               
{
           "request": {
              "t_lpk": {
                 "noSep": "{nomor sep}",
                 "tglMasuk": "{tanggal masuk format yyyy-mm-dd}",
                 "tglKeluar": "{tanggal keluar format yyyy-mm-dd}",
                 "jaminan": "{penjamin -> 1. JKN}",
                 "poli": {
                    "poli": "{kode poli -> data di referensi poli}"
                 },
                 "perawatan": {
                    "ruangRawat": "{ruang rawat -> data di referensi ruang rawat}",
                    "kelasRawat": "{kelas rawat -> data di referensi kelas rawat}",
                    "spesialistik": "{spesialistik -> data di referensi spesialistik}",
                    "caraKeluar": "{cara keluar -> data di referensi cara keluar}",
                    "kondisiPulang": "{kondisi pulang -> data di referensi kondisi pulang}"
                 },
                 "diagnosa": [
                    {
                       "kode": "{kode diagnosa  -> data di referensi diagnosa}",
                       "level": "{level diagnosa -> 1.Primer 2.Sekunder}"
                    },
                    {
                       "kode": "{kode diagnosa  -> data di referensi diagnosa}",
                       "level": "{level diagnosa -> 1.Primer 2.Sekunder}"
                    }
                 ],
                 "procedure": [
                    {
                       "kode": "{kode procedure -> data di referensi procedure/tindakan}"
                    },
                    {
                       "kode": "{kode procedure -> data di referensi procedure/tindakan}"
                    }
                 ],
                 "rencanaTL": {
                    "tindakLanjut": "{tindak lanjut -> 1:Diperbolehkan Pulang, 2:Pemeriksaan Penunjang, 3:Dirujuk Ke, 4:Kontrol Kembali}",
                    "dirujukKe": {
                       "kodePPK": "{kode faskes -> data di referensi faskes}"
                    },
                    "kontrolKembali": {
                       "tglKontrol": "{tanggal kontrol kembali format : yyyy-mm-dd}",
                       "poli": "{kode poli -> data di referensi poli}"
                    }
                 },
                 "DPJP": "{kode dokter dpjp -> data di referensi dokter}",
                 "user": "{user pemakai}"
              }
           }
        }                
  
Delete LPK
{BASE URL}/{Service Name}/LPK/delete
Fungsi : Delete LPK
Method : DELETE
Format : Json
Content-Type: Application/x-www-form-urlencoded
                                                  
        {
           "request": {
              "t_lpk": {
                 "noSep": "0301R0011017V000015"             
              }
           }
        }               
{
           "request": {
              "t_lpk": {
                 "noSep": "{nomor sep}"
              }
           }
        }                
{
           "metaData": {
              "code": "200",
              "message": "OK"
           },
           "response": "0301R0011017V000015"      
        }
Data Lembar Pengajuan Klaim
{BASE URL}/{Service Name}/LPK/TglMasuk/{Parameter 1}/JnsPelayanan/{Paramater 2}
Fungsi : Data lembar pengajuan klaim
Method : GET
Format : Json
Content-Type: application/json; charset=utf-8
Parameter 1 : Tanggal Masuk - format : yyyy-MM-dd
Parameter 2 : Jenis Pelayanan 1. Inap 2.Jalan
{
           "metaData": {
              "code": "200",
              "message": "OK"
           },
           "response": {
              "lpk": {
                 "list": [
                    {
                       "DPJP": {
                          "dokter": {
                             "kode": "3",
                             "nama": "Satro Jadhit, dr"
                          }
                       },
                       "diagnosa": {
                          "list": [
                             {
                                "level": "1",
                                "list": {
                                   "kode": "N88.1",
                                   "nama": "Old laceration of cervix uteri"
                                }
                             },
                             {
                                "level": "2",
                                "list": {
                                   "kode": "A00.1",
                                   "nama": "Cholera due to Vibrio cholerae 01, biovar eltor"
                                }
                             }
                          ]
                       },
                       "jnsPelayanan": "1",
                       "noSep": "0301R0011017V000014",
                       "perawatan": {
                          "caraKeluar": {
                             "kode": "1",
                             "nama": "Atas Persetujuan Dokter"
                          },
                          "kelasRawat": {
                             "kode": "1",
                             "nama": "VVIP"
                          },
                          "kondisiPulang": {
                             "kode": "1",
                             "nama": "Sembuh"
                          },
                          "ruangRawat": {
                             "kode": "3",
                             "nama": "Ruang Melati I"
                          },
                          "spesialistik": {
                             "kode": "1",
                             "nama": "Spesialis Penyakit dalam"
                          }
                       },
                       "peserta": {
                          "kelamin": "L",
                          "nama": "123456",
                          "noKartu": "0000000001231",
                          "noMR": "123456",
                          "tglLahir": "2008-02-05"
                       },
                       "poli": {
                          "eksekutif": "0",
                          "poli": {
                             "kode": "INT"
                          }
                       },
                       "procedure": {
                          "list": [
                             {
                                "list": {
                                   "kode": "00.82",
                                   "nama": "Revision of knee replacement, femoral component"
                                }
                             },
                             {
                                "list": {
                                   "kode": "00.83",
                                   "nama": "Revision of knee replacement,patellar component"
                                }
                             }
                          ]
                       },
                       "rencanaTL": null,
                       "tglKeluar": "2017-10-30",
                       "tglMasuk": "2017-10-30"
                    }
                 ]
              }
           }
        }             
Monitoring
Data Kunjungan
{Base URL}/{Service Name}/Monitoring/Kunjungan/Tanggal/{Parameter 1}/JnsPelayanan/{Parameter 2}
Fungsi : Data Kunjungan
Method : GET
Format : Json
Content-Type: application/json; charset=utf-8
Parameter 1 : Tanggal SEP format: yyyy-mm-dd
Parameter 2 : Jenis Pelayanan (1. Inap 2. Jalan)
{
           "metaData": {
              "code": "200",
              "message": "Sukses"
           },
           "response": {
              "sep": [
                 {
                    "diagnosa": "K65.0",
                    "jnsPelayanan": "R.Inap",
                    "kelasRawat": "2",
                    "nama": "HANIF ABDURRAHMAN",
                    "noKartu": "0001819122189",
                    "noSep": "0301R00110170000004",
                    "noRujukan": "0301U01108180200084",
                    "poli": null,
                    "tglPlgSep": "2017-10-03",
                    "tglSep": "2017-10-01"
                 },
                 {
                    "diagnosa": "I50.0",
                    "jnsPelayanan": "R.Inap",
                    "kelasRawat": "3",
                    "nama": "ASRIZAL",
                    "noKartu": "0002283324674",
                    "noSep": "0301R00110170000005",
                    "noRujukan": "0301U01108180200184",
                    "poli": null,
                    "tglPlgSep": "2017-10-10",
                    "tglSep": "2017-10-01"
                 }
              ]
           }
        }
Data Klaim
{Base URL}/{Service Name}/Monitoring/Klaim/Tanggal/{Parameter 1}/JnsPelayanan/{Parameter 2}/Status/{Parameter 3}
Fungsi : Data Klaim
Method : GET
Format : Json
Content-Type: application/json; charset=utf-8
Parameter 1 : Tanggal Pulang format: yyyy-mm-dd
Parameter 2 : Jenis Pelayanan (1. Inap 2. Jalan)
Parameter 3 : Status Klaim (1. Proses Verifikasi 2. Pending Verifikasi 3. Klaim)
"metaData": {
          "code": "200",
          "message": "Sukses"
       },
       "response": {
          "klaim": [
             {
                "Inacbg": {
                   "kode": "N-3-15-0",
                   "nama": "DIALYSIS"
                },
                "biaya": {
                   "byPengajuan": "991200",
                   "bySetujui": "0",
                   "byTarifGruper": "991200",
                   "byTarifRS": "1170689",
                   "byTopup": "0"
                },
                "kelasRawat": "3",
                "noFPK": "",
                "noSEP": "0301R00109170001280",
                "peserta": {
                   "nama": "NUR",
                   "noKartu": "0033681422715",
                   "noMR": "974956"
                },
                "poli": "Hemodialisa",
                "status": "Proses Verifikasi",
                "tglPulang": "2017-09-02",
                "tglSep": "2017-09-02"
             },
             {
                "Inacbg": {
                   "kode": "N-3-15-0",
                   "nama": "DIALYSIS"
                },
                "biaya": {
                   "byPengajuan": "991200",
                   "bySetujui": "0",
                   "byTarifGruper": "991200",
                   "byTarifRS": "1015000",
                   "byTopup": "0"
                },
                "kelasRawat": "3",
                "noFPK": "",
                "noSEP": "0301R00109170000094",
                "peserta": {
                   "nama": "YUH",
                   "noKartu": "0223416974628",
                   "noMR": "878410"
                },
                "poli": "Hemodialisa",
                "status": "Proses Verifikasi",
                "tglPulang": "2017-09-02",
                "tglSep": "2017-09-02"
             }
          ]
       }
    }
Data Histori Pelayanan Peserta
{Base URL}/{Service Name}/monitoring/HistoriPelayanan/NoKartu/{Parameter 1}/tglMulai/{Parameter 2}/tglAkhir/{Parameter 3}
Fungsi : Histori Pelayanan Per Peserta
Method : GET
Format : Json
Content-Type: application/json; charset=utf-8
Parameter 1 : No.Kartu Peserta
Parameter 2 : Tgl Mulai Pencarian (yyyy-mmdd)
Parameter 3 : Tgl Akhir Pencarian (yyyy-mmdd)
{
       "metaData": {
          "code": "200",
          "message": "Sukses"
       },
       "response": {
          "histori": [
             {
                "diagnosa": "A00.1 - Cholera due to Vibrio cholerae 01, biovar eltor",
                "jnsPelayanan": "1",
                "kelasRawat": "Kelas 1",
                "namaPeserta": "STAMI",
                "noKartu": "0001160271256",
                "noSep": "0301R0110818V200084",
                "noRujukan": "0301U01108180200084",
                "poli": "",
                "ppkPelayanan": "RS YOS SUDARSO",
                "tglPlgSep": "2018-07-11",
                "tglSep": "2018-07-09"
             },
             {
                "diagnosa": "A00.1 - Cholera due to Vibrio cholerae 01, biovar eltor",
                "jnsPelayanan": "2",
                "kelasRawat": null,
                "namaPeserta": "STAMI",
                "noKartu": "0001160271256",
                "noSep": "0301R0110818V100085",
                "noRujukan": "0301U01108180201084",
                "poli": "",
                "ppkPelayanan": "RS YOS SUDARSO",
                "tglPlgSep": "2018-08-09",
                "tglSep": "2018-08-09"
             }
          ]
       }
    }
Pembuatan Rencana Kontrol/SPRI
Insert Rencana Kontrol v2
{BASE URL}/{Service Name}/RencanaKontrol/v2/Insert
Fungsi : Insert Rencana Kontrol v2
Method : POST
Format : Json
Content-Type: Application/x-www-form-urlencoded
Request
                                            
        {
            "request": {
                "noSEP":"0301R0111018V000006",
                "kodeDokter":"12345",
                "poliKontrol":"INT",
                "tglRencanaKontrol":"2021-03-20",
                "user":"ws",
                "formPRB": {
                  "kdStatusPRB": "07",
                  "data": {
                    "HBA1C": null, 
                    "GDP": 78, 
                    "GD2JPP": null,
                    "eGFR": null,
                    "TD_Sistolik": 90,
                    "TD_Diastolik": 90,
                    "LDL": 20,
                    "Rata_TD_Sistolik": null,
                    "Rata_TD_Diastolik": null,
                    "JantungKoroner": null,
                    "Stroke": null,
                    "VaskularPerifer": null,
                    "Aritmia": null, 
                    "AtrialFibrilasi": null,
                    "NadiIstirahat": null,
                    "SesakNapas3Bulan": null,
                    "NyeriDada3Bulan": null,
                    "SesakNapasAktivitas": null,
                    "NyeriDadaAktivitas": null,
                    "Terkontrol": null,
                    "Gejala2xMinggu": null,
                    "BangunMalam": null,
                    "KeterbatasanFisik": null,
                    "FungsiParu": null,
                    "SkorMMRC": null,
                    "Eksaserbasi1Tahun": null,
                    "MampuAktivitas": null,
                    "Epileptik6Bulan": null,
                    "EfekSampingOAB": null,
                    "HamilMenyusui": null,
                    "Remisi": null,
                    "TerapiRumatan": null,
                    "Usia": null,
                    "AsamUrat": 0.1,
                    "RemisiSLE": null,
                    "Hamil": null 
                  }
                }
            }
        }
                                                
                                 
                                            
        {
            "request": {
                "noSEP":"{nomor SEP}",
                "kodeDokter":"{kode dokter}",
                "poliKontrol":"{kode poli}",
                "tglRencanaKontrol":"{Rawat Jalan: diisi tanggal rencana kontrol, format: yyyy-MM-dd. Rawat Inap: diisi tanggal SPRI, format: yyyy-MM-dd}",
                "user":"{user pembuat rencana kontrol}",
                "formPRB": {
                  "kdStatusPRB": "{kode penyakit PRB}", //(01. Diabetes Melitus,02. Hipertensi, 03. Asma, 04. Penyakit Jantung, 05. PPOK, 06. Skizofrenia, 07. Stroke, 08. Epilepsi, 09. SLE)
                  "data": {
                 /* 01 */   "HBA1C": {diisi null atau angka}, /* 0.1 sd 15 */
                 /* 01/07 */   "GDP": {diisi null atau angka}, /* 10 sd 500 */
                 /* 01 */   "GD2JPP": {diisi null atau angka}, /* 10 sd 500 */
                 /* 01/02 */   "eGFR": {diisi null atau angka}, /* 5 sd 150 */
                 /* 01/07 */    "TD_Sistolik": {diisi null atau angka}, /* 20 sd 200 */
                 /* 01/07 */   "TD_Diastolik": {diisi null atau angka}, /* 20 sd 200 */
                 /* 01/07 */   "LDL": {diisi null atau angka}, /* 20 sd 500 */
                 /* 02/04 */   "Rata_TD_Sistolik": {diisi null atau angka}, /* 20 sd 200 */
                 /* 02/04 */   "Rata_TD_Diastolik": {diisi null atau angka}, /* 20 sd 200 */
                 /* 02 */   "JantungKoroner": {diisi null atau angka}, /* 0 atau 1 */
                 /* 02 */   "Stroke": {diisi null atau angka}, /* 0 atau 1 */
                 /* 02 */   "VaskularPerifer": {diisi null atau angka}, /* 0 atau 1 */
                 /* 02/04 */   "Aritmia": {diisi null atau angka}, /* 0 atau 1 */
                 /* 02 */   "AtrialFibrilasi": {diisi null atau angka}, /* 0 atau 1 */
                 /* 04 */   "NadiIstirahat": {diisi null atau angka}, /* 20 sd 200 */
                 /* 04 */   "SesakNapas3Bulan": {diisi null atau angka}, /* 0 atau 1 */
                 /* 04 */   "NyeriDada3Bulan": {diisi null atau angka}, /* 0 atau 1 */
                 /* 04 */   "SesakNapasAktivitas": {diisi null atau angka}, /* 0 atau 1 */
                 /* 04 */   "NyeriDadaAktivitas": {diisi null atau angka}, /* 0 atau 1 */
                 /* 03 */   "Terkontrol": {diisi null atau angka}, /* 0 atau 1 */
                 /* 03 */   "Gejala2xMinggu": {diisi null atau angka}, /* 0 atau 1 */
                 /* 03 */   "BangunMalam": {diisi null atau angka}, /* 0 atau 1 */
                 /* 03 */   "KeterbatasanFisik": {diisi null atau angka}, /* 0 atau 1 */
                 /* 03 */   "FungsiParu": {diisi null atau angka}, /* 0 sd 100 */
                 /* 05 */   "SkorMMRC": {diisi null atau angka}, /* 0 sd 40 */
                 /* 05 */   "Eksaserbasi1Tahun": {diisi null atau angka}, /* 0 atau 1 */
                 /* 05 */   "MampuAktivitas": {diisi null atau angka}, /* 0 atau 1 */
                 /* 08 */   "Epileptik6Bulan": {diisi null atau angka}, /* 0 atau 1 */
                 /* 08 */   "EfekSampingOAB": {diisi null atau angka}, /* 0 atau 1 */
                 /* 08 */   "HamilMenyusui": {diisi null atau angka}, /* 0 atau 1 */
                 /* 06 */   "Remisi": {diisi null atau angka}, /* 0 sd 100 */
                 /* 06 */   "TerapiRumatan": {diisi null atau angka}, /* 0 atau 1 */
                 /* 06 */   "Usia": {diisi null atau angka}, /* 1 sd 100 */
                 /* 07 */   "AsamUrat": {diisi null atau angka}, /* 0.1 sd 20 */
                 /* 09 */   "RemisiSLE": {diisi null atau angka}, /* 0 sd 100 */
                 /* 09 */   "Hamil": {diisi null atau angka} /* 0 atau 1 */
                  }
                }
            }
        }
Response
                                        
        {
            "metaData": {
                "code": "200",
                "message": "Ok"
            },
            "response": {
                "noSuratKontrol": "0301R0110520K000013",
                "tglRencanaKontrol": "2020-05-15",
                "namaDokter": "Dr. John Wick",
                "noKartu": "0001328186441",
                "nama": "ARIS",
                "kelamin": "Laki-laki",
                "tglLahir": "1947-12-31",
                "namaDiagnosa": "I60 - Subarachnoid haemorrhage",
                "formPRB": {
                    "kdStatusPRB": "07",
                    "data": {
                        "HBA1C": null,
                        "GDP": 78,
                        "GD2JPP": null,
                        "eGFR": null,
                        "TD_Sistolik": 90,
                        "TD_Diastolik": 90,
                        "LDL": 20,
                        "Rata_TD_Sistolik": null,
                        "Rata_TD_Diastolik": null,
                        "JantungKoroner": null,
                        "Stroke": null,
                        "VaskularPerifer": null,
                        "Aritmia": null,
                        "AtrialFibrilasi": null,
                        "SesakNapas3Bulan": null,
                        "NyeriDada3Bulan": null,
                        "Terkontrol": null,
                        "Gejala2xMinggu": null,
                        "BangunMalam": null,
                        "KeterbatasanFisik": null,
                        "FungsiParu": null,
                        "SkorMMRC": null,
                        "Eksaserbasi1Tahun": null,
                        "MampuAktivitas": null,
                        "Epileptik6Bulan": null,
                        "EfekSampingOAB": null,
                        "HamilMenyusui": null,
                        "Remisi": null,
                        "TerapiRumatan": null,
                        "Usia": null,
                        "AsamUrat": 0.1,
                        "RemisiSLE": null,
                        "Hamil": null,
                        "NadiIstirahat": null,
                        "SesakNapasAktivitas": null,
                        "NyeriDadaAktivitas": null
                    }
                }
            }
        }
Update Rencana Kontrol v2
{BASE URL}/{Service Name}/RencanaKontrol/v2/Update
Fungsi : Update Rencana Kontrol v2
Method : PUT
Format : Json
Content-Type: Application/x-www-form-urlencoded
Request
                                            
        {
            "request": {
                "noSuratKontrol":"0301R0110321K000002",
                "noSEP":"0301R0111018V000006",
                "kodeDokter":"11111",
                "poliKontrol":"INT",
                "tglRencanaKontrol":"2021-03-18",
                "user":"coba",
                "formPRB": {
                    "kdStatusPRB": "07",
                    "data": {
                        "HBA1C": null,
                        "GDP": 78,
                        "GD2JPP": null,
                        "eGFR": null,
                        "TD_Sistolik": 90,
                        "TD_Diastolik": 90,
                        "LDL": 20,
                        "Rata_TD_Sistolik": null,
                        "Rata_TD_Diastolik": null,
                        "JantungKoroner": null,
                        "Stroke": null,
                        "VaskularPerifer": null,
                        "Aritmia": null,
                        "AtrialFibrilasi": null,
                        "SesakNapas3Bulan": null,
                        "NyeriDada3Bulan": null,
                        "Terkontrol": null,
                        "Gejala2xMinggu": null,
                        "BangunMalam": null,
                        "KeterbatasanFisik": null,
                        "FungsiParu": null,
                        "SkorMMRC": null,
                        "Eksaserbasi1Tahun": null,
                        "MampuAktivitas": null,
                        "Epileptik6Bulan": null,
                        "EfekSampingOAB": null,
                        "HamilMenyusui": null,
                        "Remisi": null,
                        "TerapiRumatan": null,
                        "Usia": null,
                        "AsamUrat": 0.1,
                        "RemisiSLE": null,
                        "Hamil": null,
                        "NadiIstirahat": null,
                        "SesakNapasAktivitas": null,
                        "NyeriDadaAktivitas": null
                    }
                }
            }
        }
                                                
                                 
                                            
        {
            "request": {
                "noSuratKontrol":"{nomor surat kontrol}",
                "noSEP":"{nomor SEP}",
                "kodeDokter":"{kode dokter}",
                "poliKontrol":"{kode poli}",
                "tglRencanaKontrol":"{tanggal rencana kontrol, format: yyyy-MM-dd}",
                "user":"{user pembuat rencana kontrol}",
                "formPRB": {
                  "kdStatusPRB": "{kode penyakit PRB}", //(01. Diabetes Melitus,02. Hipertensi, 03. Asma, 04. Penyakit Jantung, 05. PPOK, 06. Skizofrenia, 07. Stroke, 08. Epilepsi, 09. SLE)
                  "data": {
                 /* 01 */   "HBA1C": {diisi null atau angka}, /* 0.1 sd 15 */
                 /* 01/07 */   "GDP": {diisi null atau angka}, /* 10 sd 500 */
                 /* 01 */   "GD2JPP": {diisi null atau angka}, /* 10 sd 500 */
                 /* 01/02 */   "eGFR": {diisi null atau angka}, /* 5 sd 150 */
                 /* 01/07 */    "TD_Sistolik": {diisi null atau angka}, /* 20 sd 200 */
                 /* 01/07 */   "TD_Diastolik": {diisi null atau angka}, /* 20 sd 200 */
                 /* 01/07 */   "LDL": {diisi null atau angka}, /* 20 sd 500 */
                 /* 02/04 */   "Rata_TD_Sistolik": {diisi null atau angka}, /* 20 sd 200 */
                 /* 02/04 */   "Rata_TD_Diastolik": {diisi null atau angka}, /* 20 sd 200 */
                 /* 02 */   "JantungKoroner": {diisi null atau angka}, /* 0 atau 1 */
                 /* 02 */   "Stroke": {diisi null atau angka}, /* 0 atau 1 */
                 /* 02 */   "VaskularPerifer": {diisi null atau angka}, /* 0 atau 1 */
                 /* 02/04 */   "Aritmia": {diisi null atau angka}, /* 0 atau 1 */
                 /* 02 */   "AtrialFibrilasi": {diisi null atau angka}, /* 0 atau 1 */
                 /* 04 */   "NadiIstirahat": {diisi null atau angka}, /* 20 sd 200 */
                 /* 04 */   "SesakNapas3Bulan": {diisi null atau angka}, /* 0 atau 1 */
                 /* 04 */   "NyeriDada3Bulan": {diisi null atau angka}, /* 0 atau 1 */
                 /* 04 */   "SesakNapasAktivitas": {diisi null atau angka}, /* 0 atau 1 */
                 /* 04 */   "NyeriDadaAktivitas": {diisi null atau angka}, /* 0 atau 1 */
                 /* 03 */   "Terkontrol": {diisi null atau angka}, /* 0 atau 1 */
                 /* 03 */   "Gejala2xMinggu": {diisi null atau angka}, /* 0 atau 1 */
                 /* 03 */   "BangunMalam": {diisi null atau angka}, /* 0 atau 1 */
                 /* 03 */   "KeterbatasanFisik": {diisi null atau angka}, /* 0 atau 1 */
                 /* 03 */   "FungsiParu": {diisi null atau angka}, /* 0 sd 100 */
                 /* 05 */   "SkorMMRC": {diisi null atau angka}, /* 0 sd 40 */
                 /* 05 */   "Eksaserbasi1Tahun": {diisi null atau angka}, /* 0 atau 1 */
                 /* 05 */   "MampuAktivitas": {diisi null atau angka}, /* 0 atau 1 */
                 /* 08 */   "Epileptik6Bulan": {diisi null atau angka}, /* 0 atau 1 */
                 /* 08 */   "EfekSampingOAB": {diisi null atau angka}, /* 0 atau 1 */
                 /* 08 */   "HamilMenyusui": {diisi null atau angka}, /* 0 atau 1 */
                 /* 06 */   "Remisi": {diisi null atau angka}, /* 0 sd 100 */
                 /* 06 */   "TerapiRumatan": {diisi null atau angka}, /* 0 atau 1 */
                 /* 06 */   "Usia": {diisi null atau angka}, /* 1 sd 100 */
                 /* 07 */   "AsamUrat": {diisi null atau angka}, /* 0.1 sd 20 */
                 /* 09 */   "RemisiSLE": {diisi null atau angka}, /* 0 sd 100 */
                 /* 09 */   "Hamil": {diisi null atau angka} /* 0 atau 1 */
                  }
                }
            }
        }
Response
                                        
        {
            "metaData": {
                "code": "200",
                "message": "Ok"
            },
            "response": {
                "noSuratKontrol": "0301R0110520K000013",
                "tglRencanaKontrol": "2020-05-15",
                "namaDokter": "Dr. John Wick",
                "noKartu": "0001328186441",
                "nama": "ARIS",
                "kelamin": "Laki-laki",
                "tglLahir": "1947-12-31",
                "namaDiagnosa": "I60 - Subarachnoid haemorrhage",
                "formPRB": {
                    "kdStatusPRB": "07",
                    "data": {
                        "HBA1C": null,
                        "GDP": 78,
                        "GD2JPP": null,
                        "eGFR": null,
                        "TD_Sistolik": 90,
                        "TD_Diastolik": 90,
                        "LDL": 20,
                        "Rata_TD_Sistolik": null,
                        "Rata_TD_Diastolik": null,
                        "JantungKoroner": null,
                        "Stroke": null,
                        "VaskularPerifer": null,
                        "Aritmia": null,
                        "AtrialFibrilasi": null,
                        "SesakNapas3Bulan": null,
                        "NyeriDada3Bulan": null,
                        "Terkontrol": null,
                        "Gejala2xMinggu": null,
                        "BangunMalam": null,
                        "KeterbatasanFisik": null,
                        "FungsiParu": null,
                        "SkorMMRC": null,
                        "Eksaserbasi1Tahun": null,
                        "MampuAktivitas": null,
                        "Epileptik6Bulan": null,
                        "EfekSampingOAB": null,
                        "HamilMenyusui": null,
                        "Remisi": null,
                        "TerapiRumatan": null,
                        "Usia": null,
                        "AsamUrat": 0.1,
                        "RemisiSLE": null,
                        "Hamil": null,
                        "NadiIstirahat": null,
                        "SesakNapasAktivitas": null,
                        "NyeriDadaAktivitas": null
                    }
                }
            }
        }
Hapus Rencana Kontrol
{BASE URL}/{Service Name}/RencanaKontrol/Delete
Fungsi : Hapus Data REncana Kontrol
Method : DELETE
Format : Json
Content-Type: Application/x-www-form-urlencoded
Request
                                            
        {
            "request": {
                "t_suratkontrol":{
                "noSuratKontrol": "0301R0010320K000004",
                "user": "xxx"
                }
            }
        }

                                                
                                 
                                            
        {
            "request": {
                "t_suratkontrol":{
                "noSuratKontrol": "0301R0010320K000004",
                "user": "xxx"
                }
            }
        }
Response
                                        
        {
            "metaData": {
                "code": "200",
                "message": "Sukses"
            },
            "response": null
        }
Insert SPRI 
{BASE URL}/{Service Name}/RencanaKontrol/InsertSPRI
Fungsi : Insert SPRI
Method : POST
Format : Json
Content-Type: Application/x-www-form-urlencoded
Request
                                            
        {
            "request":
                {
                    "noKartu":"0001116500714",
                    "kodeDokter":"31537",
                    "poliKontrol":"BED",
                    "tglRencanaKontrol":"2021-04-13",
                    "user":"sss"
                }
        }
                                                
                                 
                                            
        {
            "request":
                {
                    "noKartu":"{nomor Kartu}",
                    "kodeDokter":"{kode dokter}",
                    "poliKontrol":"{poli kontrol}",
                    "tglRencanaKontrol":"{tgl rencana kontrol, format:yyyy-MM-dd}",
                    "user":"{user pembuat spri}"
                }
        }
Response
                                        
        {
            "metaData": {
                "code": "200",
                "message": "Ok"
            },
            "response": {
                "noSPRI": "0301R0110421K000002",
                "tglRencanaKontrol": "2021-04-20",
                "namaDokter": "Dr.Yahya Marpaung,SpB, FINACS",
                "noKartu": "0001116500714",
                "nama": "M AMRU",
                "kelamin": "Laki-Laki",
                "tglLahir": "1997-12-16",
                "namaDiagnosa": null
            }
        }
Update SPRI
{BASE URL}/{Service Name}/RencanaKontrol/UpdateSPRI
Fungsi : Update SPRI
Method : PUT
Format : Json
Content-Type: Application/x-www-form-urlencoded
Request
                                            
        {
    "request":
        {
            "noSPRI":"0301R0110421K000116",
            "kodeDokter":"31537",
            "poliKontrol":"ANA",
            "tglRencanaKontrol":"2021-04-13",
            "user":"cobdda"
        }
}
                                                
                                 
                                            
        {
            "request":
                {
                    "noSPRI":"{nomor SPRI}",
                    "kodeDokter":"{kode dokter}",
                    "poliKontrol":"{poli kontrol}",
                    "tglRencanaKontrol":"{tgl rencana kontrol, format:yyyy-MM-dd}",
                    "user":"{user pembuat spri}"
                }
        }
  {
            "metaData": {
                "code": "200",
                "message": "Ok"
            },
            "response": {
                "noSPRI": "0301R0110421K000002",
                "tglRencanaKontrol": "2021-04-22",
                "namaDokter": "Dr.Yahya Marpaung,SpB, FINACS",
                "noKartu": "0001116500714",
                "nama": "M AMRU",
                "kelamin": "Laki-Laki",
                "tglLahir": "1997-12-16",
                "namaDiagnosa": null
            }
        }
Cari SEP New
{BASE URL}/{Service Name}/RencanaKontrol/nosep/{parameter}
Fungsi : Melihat data SEP untuk keperluan rencana kontrol
Method : GET
Format : Json
Content-Type: Application/x-www-form-urlencoded
Parameter: Nomor SEP Peserta

                                
    {
        "metaData": {
            "code": "200",
            "message": "Sukses"
           },
        "response": {
            "noSep": "0301R0010819V006059",
            "tglSep": "2019-10-17",
            "jnsPelayanan": "Rawat Jalan",
            "poli": "HDL - HEMODIALISA",
            "diagnosa": "Z49.1 - Extracorporeal dialysis",
            "peserta": {
            "noKartu": "0000018965349",
            "nama": "RASBEN",
            "tglLahir": "1957-11-10",
            "kelamin": "L",
            "hakKelas": "-"
        },
        "provUmum": {
            "kdProvider": "03100202",
            "nmProvider": "KAMPUNG TELENG"
        },
        "provPerujuk": {
            "kdProviderPerujuk": "03100202",
            "nmProviderPerujuk": "KAMPUNG TELENG",
            "asalRujukan": "1",
            "noRujukan": "031002020619P000413",
            "tglRujukan": "2019-10-17"
            }
        }
    }
Cari Nomor Surat Kontrol New
{BASE URL}/{Service Name}/RencanaKontrol/noSuratKontrol/{parameter}
Fungsi : Melihat data SEP untuk keperluan rencana kontrol
Method : GET
Format : Json
Content-Type: Application/x-www-form-urlencoded
Parameter: Nomor Surat Kontrol Peserta

                                
    {
	"response": {
		"noSuratKontrol": "0301R0111125K000002",
		"tglRencanaKontrol": "2025-11-25",
		"tglTerbit": "2025-11-18",
		"jnsKontrol": "2",
		"poliTujuan": "BED",
		"namaPoliTujuan": "BEDAH",
		"kodeDokter": "31348",
		"namaDokter": "CIiNatXXAXSkrIrPId,ManFs.SDDMe",
		"flagKontrol": "False",
		"kodeDokterPembuat": "31348",
		"namaDokterPembuat": "CIiNatXXAXSkrIrPId,ManFs.SDDMe",
		"namaJnsKontrol": "Kontrol",
		"sep": {
			"noSep": "0301R0110725V000006",
			"tglSep": "2025-07-30",
			"jnsPelayanan": "Rawat Jalan",
			"poli": "BED - BEDAH",
			"diagnosa": "E10 - Insulin-dependent diabetes mellitus",
			"peserta": {
				"noKartu": "0002482505324",
				"nama": "ARMSTIOFIALR",
				"tglLahir": "1983-09-07",
				"kelamin": "P",
				"hakKelas": "-"
			},
			"provUmum": {
				"kdProvider": "10210901",
				"nmProvider": "KERTASEMAYA"
			},
			"provPerujuk": {
				"kdProviderPerujuk": "0050B107",
				"nmProviderPerujuk": "Klinik Sehat Gajah Mada",
				"asalRujukan": "1",
				"noRujukan": "0050B1070924P000001",
				"tglRujukan": "2025-10-01"
			}
		},
		"formPRB": {
			"kdStatusPRB": null,
			"data": {
				"HBA1C": null,
				"GDP": null,
				"GD2JPP": null,
				"eGFR": null,
				"TD_Sistolik": null,
				"TD_Diastolik": null,
				"LDL": null,
				"Rata_TD_Sistolik": null,
				"Rata_TD_Diastolik": null,
				"JantungKoroner": null,
				"Stroke": null,
				"VaskularPerifer": null,
				"Aritmia": null,
				"AtrialFibrilasi": null,
				"SesakNapas3Bulan": null,
				"NyeriDada3Bulan": null,
				"Terkontrol": null,
				"Gejala2xMinggu": null,
				"BangunMalam": null,
				"KeterbatasanFisik": null,
				"FungsiParu": null,
				"SkorMMRC": null,
				"Eksaserbasi1Tahun": null,
				"MampuAktivitas": null,
				"Epileptik6Bulan": null,
				"EfekSampingOAB": null,
				"HamilMenyusui": null,
				"Remisi": null,
				"TerapiRumatan": null,
				"Usia": null,
				"AsamUrat": null,
				"RemisiSLE": null,
				"Hamil": null,
				"NadiIstirahat": null,
				"SesakNapasAktivitas": null,
				"NyeriDadaAktivitas": null
			}
		}
	},
	"metaData": {
		"code": "200",
		"message": "Sukses"
	}
}
    
Catatan: 
Ketika pembuatan SPRI atau jenis kontrol 1 tidak ada referensi nomor SEP asalnya, jadi field response SEP kosong atau null. 
Sedangkan jika pembuatan surat kontrol atau jenis kontrol 2, akan terisi field response SEP karena terdapat referensi nomor SEP asal ketika pembuatan surat kontrol tersebut.
Data Nomor Surat Kontrol Berdasarkan No Kartu New
{BASE URL}/{Service Name}/RencanaKontrol/ListRencanaKontrol/Bulan/{parameter 1}/Tahun/{parameter 2}/Nokartu/{parameter 3}/filter/{parameter 4}
Fungsi : Data Rencana Kontrol By No Kartu
Method : GET
Format : Json
Content-Type: Application/x-www-form-urlencoded
Parameter 1: Bulan. Contoh: Januari => 01
Parameter 2: Tahun
Parameter 3: Nomor Kartu
Parameter 4: Format filter --> 1: tanggal entri, 2: tanggal rencana kontrol

                                
{
   "metaData":{
      "code":"200",
      "message":"Sukses"
   },
   "response":{
      "list":[
         {
            "noSuratKontrol":"0117R0770122K000004",
            "jnsPelayanan":"Rawat Inap",
            "jnsKontrol":"2",
            "namaJnsKontrol":"Surat Kontrol",
            "tglRencanaKontrol":"2022-01-06",
            "tglTerbitKontrol":"2022-01-05",
            "noSepAsalKontrol":"0117R0770122V000003",
            "poliAsal":"INT",
            "namaPoliAsal":"-",
            "poliTujuan":"INT",
            "namaPoliTujuan":"PENYAKIT DALAM",
            "tglSEP":"2022-01-04",
            "kodeDokter":"296676",
            "namaDokter":"ABD KADIR",
            "noKartu":"0002035874204",
            "nama":"ANI AZKIA",
            "terbitSEP":"Belum"
         }
      ]
   }
}
Data Nomor Surat Kontrol New
{BASE URL}/{Service Name}/RencanaKontrol/ListRencanaKontrol/tglAwal/{parameter 1}/tglAkhir/{parameter 2}/filter/{parameter 3}
Fungsi : Data Rencana Kontrol
Method : GET
Format : Json
Content-Type: Application/x-www-form-urlencoded
Parameter 1: Tanggal awal format : yyyy-MM-dd
Parameter 2: Tanggal akhir format : yyyy-MM-dd
Parameter 3: Format filter --> 1: tanggal entri, 2: tanggal rencana kontrol

                                
    {
        "metaData": {
            "code": "200",
            "message": "Sukses"
        },
        "response": {
            "list": [
                {
                    "noSuratKontrol": "0301R0110321K000002",
                    "jnsPelayanan": "Rawat Jalan",
                    "jnsKontrol": "2",
                    "namaJnsKontrol": "Surat Kontrol",
                    "tglRencanaKontrol": "2021-03-18",
                    "tglTerbitKontrol": "2021-03-16",
                    "noSepAsalKontrol": "0301R0111018V000006",
                    "poliAsal": "INT",
                    "namaPoliAsal": "PENYAKIT DALAM",
                    "poliTujuan": "INT",
                    "namaPoliTujuan": "PENYAKIT DALAM",
                    "tglSEP": "2021-03-16",
                    "kodeDokter": "31479",
                    "namaDokter": "Prof.dr.Yulius,SpPD, KGEH",
                    "noKartu": "0001882053808",
                    "nama": "mela handayani"
                }
            ]
        }
    }
Data Poli/Spesialistik New
{BASE URL}/{Service Name}/RencanaKontrol/ListSpesialistik/JnsKontrol/{parameter 1}/nomor/{parameter 2}/TglRencanaKontrol/{parameter 3}
Fungsi : Data Rencana Kontrol
Method : GET
Format : Json
Content-Type: Application/x-www-form-urlencoded
Parameter 1: Jenis kontrol --> 1: SPRI, 2: Rencana Kontrol
Parameter 2: Nomor --> jika jenis kontrol = 1, maka diisi nomor kartu; jika jenis kontrol = 2, maka diisi nomor SEP
Parameter 3: Tanggal rencana kontrol --> format yyyy-MM-dd

                                
    {
        "metaData": {
            "code": "200",
            "message": "Sukses"
        },
        "response": {
            "list": [
                {
                    "kodePoli": "004",
                    "namaPoli": "Alergi-Immunologi Klinik ",
                    "kapasitas": "30",
                    "jmlRencanaKontroldanRujukan": "0",
                    "persentase": "0.00"
                },
                {
                    "kodePoli": "005",
                    "namaPoli": "Gastroenterologi-Hepatologi ",
                    "kapasitas": "12",
                    "jmlRencanaKontroldanRujukan": "0",
                    "persentase": "0.00"
                },
                {
                    "kodePoli": "008",
                    "namaPoli": "Hematologi - Onkologi Medik ",
                    "kapasitas": "24",
                    "jmlRencanaKontroldanRujukan": "0",
                    "persentase": "0.00"
                },
                {
                    "kodePoli": "013",
                    "namaPoli": "Reumatologi ",
                    "kapasitas": "24",
                    "jmlRencanaKontroldanRujukan": "0",
                    "persentase": "0.00"
                },
                {
                    "kodePoli": "015",
                    "namaPoli": "Kardiovaskular ",
                    "kapasitas": "24",
                    "jmlRencanaKontroldanRujukan": "0",
                    "persentase": "0.00"
                },
                {
                    "kodePoli": "023",
                    "namaPoli": "obstetri ginekologi sosial",
                    "kapasitas": "12",
                    "jmlRencanaKontroldanRujukan": "0",
                    "persentase": "0.00"
                }
            ]
        }
    }
Data Dokter New
{BASE URL}/{Service Name}/RencanaKontrol/JadwalPraktekDokter/JnsKontrol/{parameter 1}/KdPoli/{parameter 2}/TglRencanaKontrol/{parameter 3}
Fungsi : Data Rencana Kontrol
Method : GET
Format : Json
Content-Type: Application/x-www-form-urlencoded
Parameter 1: Jenis kontrol --> 1: SPRI, 2: Rencana Kontrol
Parameter 2: Kode poli
Parameter 3: Tanggal rencana kontrol --> format yyyy-MM-dd

                                
    {
        "metaData": {
            "code": "200",
            "message": "Sukses"
        },
        "response": {
            "list": [
                {
                    "kodeDokter": "31528",
                    "namaDokter": "Dr.John Wick",
                    "jadwalPraktek": "16:00 - 18:00",
                    "kapasitas": "12"
                },
                {
                    "kodeDokter": "31348",
                    "namaDokter": "Dr. Luffy",
                    "jadwalPraktek": "10:00 - 12:00",
                    "kapasitas": "12"
                }
            ]
        }
    }
                                         

