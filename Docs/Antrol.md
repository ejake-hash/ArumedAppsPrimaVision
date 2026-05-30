





Web Service Antrean - BPJS (Diakses oleh sistem RS)
{BASE URL}/{Service Name}/ref/poli
Fungsi : Melihat referensi poli yang ada pada Aplikasi HFIS
Method : GET
Format : Json
Header :
        x-cons-id: {cons id akses}
        x-timestamp: {timestamp akses}
        x-signature: {signature akses}
        user_key: {userkey akses}
Respon : Perlu dilakukan dekripsi disisi client
                                
                                
{
    "metadata": {
        "code": 1,
        "message": "OK"
    },
    "response": {
        "list": [
            {
                "nmpoli": "AKUPUNTUR MEDIK",
                "nmsubspesialis": "AKUPUNTUR MEDIK",
                "kdsubspesialis": "AKP",
                "kdpoli": "AKP"
            },
            {
                "nmpoli": "ANAK",
                "nmsubspesialis": "ANAK ALERGI IMUNOLOGI",
                "kdsubspesialis": "027",
                "kdpoli": "ANA"
            }
        ]
    }
}                    
Referensi Dokter
{BASE URL}/{Service Name}/ref/dokter
Fungsi : Melihat referensi dokter yang ada pada Aplikasi HFIS
Method : GET
Format : Json
Header :
        x-cons-id: {cons id akses}
        x-timestamp: {timestamp akses}
        x-signature: {signature akses}
        user_key: {userkey akses}
Respon : Perlu dilakukan dekripsi disisi client
                                
{
    "metadata": {
        "code": 1,
        "message": "OK"
    },
    "response": {
        "list": [
            {
                "namadokter": "drg. Kusumawati Sukadi, Sp.BM",
                "kodedokter": 700
            },
            {
                "namadokter": "Dr. Dr. Noer Rachma, Sp.KFR",
                "kodedokter": 854
            }
        ]
    }
}                   
Referensi Jadwal Dokter
{BASE URL}/{Service Name}/jadwaldokter/kodepoli/{Parameter1}/tanggal/{Parameter2}
Fungsi : Melihat referensi jadwal dokter yang ada pada Aplikasi HFIS
Method : GET
Format : Json
Header :
        x-cons-id: {cons id akses}
        x-timestamp: {timestamp akses}
        x-signature: {signature akses}
        user_key: {userkey akses}
Parameter1 : {diisi kode poli BPJS}=> ANA
Parameter2 : {diisi tanggal}=> 2021-08-07
Respon : Perlu dilakukan dekripsi disisi client
                                
{
    "response": {
        "list": [{
                "kodesubspesialis": "ANA",
                "hari": 4,
                "kapasitaspasien": 54,
                "libur": 0,
                "namahari": "KAMIS",
                "jadwal": "08:00 - 12:00",
                "namasubspesialis": "ANAK",
                "namadokter": "DR. OKTORA WAHYU WIJAYANTO, SP.A",
                "kodepoli": "ANA",
                "namapoli": "Anak",
                "kodedokter": 33690
            }, {
                "kodesubspesialis": "ANA",
                "hari": 4,
                "kapasitaspasien": 20,
                "libur": 0,
                "namahari": "KAMIS",
                "jadwal": "13:00 - 17:00",
                "namasubspesialis": "ANAK",
                "namadokter": "DR. OKTORA WAHYU WIJAYANTO, SP.A",
                "kodepoli": "ANA",
                "namapoli": "Anak",
                "kodedokter": 33690
            }
        ]
    },
    "metadata": {
        "message": "Ok",
        "code": 200
    }
}                   
                                 
                                 
Catatan:
hari = 1 (senin), 2 (selasa), 3 (rabu), 4 (kamis), 5 (jumat), 6 (sabtu), 7 (minggu), 8 (hari libur nasional).
Referensi Poli Finger Print New
{BASE URL}/{Service Name}/ref/poli/fp
Fungsi : Melihat referensi poli finger print
Method : GET
Format : Json
Header :
        x-cons-id: {cons id akses}
        x-timestamp: {timestamp akses}
        x-signature: {signature akses}
        user_key: {userkey akses}
Respon : Perlu dilakukan dekripsi disisi client
                                
{
    "response": {
        "list": [{
            "kodesubspesialis": "027",
            "namasubspesialis": "Anak Alergi Imunologi",
            "kodepoli": "ANA",
            "namapoli": "ANAK"
            }
        ]
    },
    "metadata": {
        "message": "Ok",
        "code": 1
    }
}                   
Referensi Pasien Finger Print New
{BASE URL}/{Service Name}/ref/pasien/fp/identitas/{nik/noka}/noidentitas/{noidentitas}
Fungsi : Melihat referensi pasien finger print
Method : GET
Format : Json
Header :
        x-cons-id: {cons id akses}
        x-timestamp: {timestamp akses}
        x-signature: {signature akses}
        user_key: {userkey akses}
Respon : Perlu dilakukan dekripsi disisi client
                                
{
    "response": {
        "nomorkartu": "0000000000031",
        "nik": "6748373747440003",
        "tgllahir": "2000-04-02",
        "daftarfp": 1
    },
    "metadata": {
        "message": "Ok",
        "code": 1
    }
}                   
Update Jadwal Dokter
{BASE URL}/{Service Name}/jadwaldokter/updatejadwaldokter
Fungsi : Update jadwal dokter yang ada pada Aplikasi HFIS
Method : POST
Format : Json
Header :
        x-cons-id: {cons id akses}
        x-timestamp: {timestamp akses}
        x-signature: {signature akses}
        user_key: {userkey akses}
{
   "kodepoli": "ANA",
   "kodesubspesialis": "ANA",
   "kodedokter": 12346,
   "jadwal": [
      {
         "hari": "1",
         "buka": "08:00",
         "tutup": "10:00"
      },
      {
         "hari": "2",
         "buka": "15:00",
         "tutup": "17:00"
      }
   ]
}
                                     
                                     
                                                
{
   "kodepoli": "{kode poli BPJS}",
   "kodesubspesialis": "{kode subspesialis BPJS}",
   "kodedokter": {kode dokter BPJS},
   "jadwal": [
      {
         "hari": "{1 (senin), 2 (selasa), 3 (rabu), 4 (kamis), 5 (jumat), 6 (sabtu), 7 (minggu), 8 (hari libur nasional)}",
         "buka": "{waktu}",
         "tutup": "{waktu}"
      },
      {
         "hari": "{1 (senin), 2 (selasa), 3 (rabu), 4 (kamis), 5 (jumat), 6 (sabtu), 7 (minggu), 8 (hari libur nasional)}",
         "buka": "{waktu}",
         "tutup": "{waktu}"
      }
   ]
}                        
                                            
{
   "metadata": {
      "message": "Ok",
      "code": 200
   }
}                          
Catatan:
- Data yang berhasil disimpan menunggu aproval dari BPJS atau otomatis approve jadwal dokter oleh sistem, misal pengajuan perubahan jadwal oleh RS diantara jam 00.00 - 20.00 , kemudian alokasi approve manual oleh BPJS/cabang di jam 20.01-00.00. Jika pukul 00.00 belum dilakukan aproval oleh kantor cabang, maka otomatis approve by sistem akan dilaksanakan setelah jam 00.00 dan yang berubahnya esoknya (H+1).
Tambah Antrean
{BASE URL}/{Service Name}/antrean/add
Fungsi : Menambah Antrean RS
Method : POST
Format : Json
Request
                                                
{
   "kodebooking": "16032021A001",
   "jenispasien": "JKN",
   "nomorkartu": "00012345678",
   "nik": "3212345678987654",
   "nohp": "085635228888",
   "kodepoli": "ANA",
   "namapoli": "Anak",
   "pasienbaru": 0,
   "norm": "123345",
   "tanggalperiksa": "2021-01-28",
   "kodedokter": 12345,
   "namadokter": "Dr. Hendra",
   "jampraktek": "08:00-16:00",
   "jeniskunjungan": 1,
   "nomorreferensi": "0001R0040116A000001",
   "nomorantrean": "A-12",
   "angkaantrean": 12,
   "estimasidilayani": 1615869169000,
   "sisakuotajkn": 5,
   "kuotajkn": 30,
   "sisakuotanonjkn": 5,
   "kuotanonjkn": 30,
   "keterangan": "Peserta harap 30 menit lebih awal guna pencatatan administrasi."
}
                                     
                                     
                                                
{
   "kodebooking": "{kodebooking yang dibuat unik}",
   "jenispasien": "{JKN / NON JKN}",
   "nomorkartu": "{noka pasien BPJS,diisi kosong jika NON JKN}",
   "nik": "{nik pasien}",
   "nohp": "{no hp pasien}",
   "kodepoli": "{memakai kode subspesialis BPJS}",
   "namapoli": "{nama poli}",
   "pasienbaru": {1(Ya),0(Tidak)},
   "norm": "{no rekam medis pasien}",
   "tanggalperiksa": "{tanggal periksa}",
   "kodedokter": {kode dokter BPJS},
   "namadokter": "{nama dokter}",
   "jampraktek": "{jam praktek dokter}",
   "jeniskunjungan": {1 (Rujukan FKTP), 2 (Rujukan Internal), 3 (Kontrol), 4 (Rujukan Antar RS)},
   "nomorreferensi": "{norujukan/kontrol pasien JKN,diisi kosong jika NON JKN}",
   "nomorantrean": "{nomor antrean pasien}",
   "angkaantrean": {angka antrean},
   "estimasidilayani": {waktu estimasi dilayani dalam miliseconds},
   "sisakuotajkn": {sisa kuota JKN},
   "kuotajkn": {kuota JKN},
   "sisakuotanonjkn": {sisa kuota non JKN},
   "kuotanonjkn": {kuota non JKN},
   "keterangan": "{informasi untuk pasien}"
}                     
{
   "metadata": {
      "message": "Ok",
      "code": 200
   }
}
{BASE URL}/{Service Name}/antrean/farmasi/add
Fungsi : Menambah Antrean Farmasi RS
Method : POST
Format : Json
Request
                            
{
    "kodebooking": "16032021A001",
    "jenisresep": "racikan" ---> (racikan / non racikan),
    "nomorantrean": 1,
    "keterangan": ""
}
Response
                        
{
    "metadata": {
        "message": "Ok",
        "code": 200
    }
}
Update Waktu Antrean Update
{BASE URL}/{Service Name}/antrean/updatewaktu
Fungsi : Mengirimkan waktu tunggu/waktu layan
Method : POST
Format : Json
Header :
        x-cons-id: {cons id akses}
        x-timestamp: {timestamp akses}
        x-signature: {signature akses}
        user_key: {userkey akses}
                                               
{
   "kodebooking": "16032021A001",
   "taskid": 1,
   "waktu": 1616559330000,
   "jenisresep": "Tidak ada/Racikan/Non racikan" ---> khusus yang sudah implementasi antrean farmasi
}
                                     
                                     
                                                
{
   "kodebooking": "{kodebooking yang didapat dari servis tambah antrean}",
   "taskid": {
                1 (mulai waktu tunggu admisi), 
                2 (akhir waktu tunggu admisi/mulai waktu layan admisi), 
                3 (akhir waktu layan admisi/mulai waktu tunggu poli), 
                4 (akhir waktu tunggu poli/mulai waktu layan poli),  
                5 (akhir waktu layan poli/mulai waktu tunggu farmasi), 
                6 (akhir waktu tunggu farmasi/mulai waktu layan farmasi membuat obat), 
                7 (akhir waktu obat selesai dibuat),
                99 (tidak hadir/batal)
            },
   "waktu": {waktu dalam timestamp milisecond}
}                        
{
   "metadata": {
      "message": "Ok",
      "code": 200
   }
}
Catatan:
- Alur Task Id Pasien Baru: 1-2-3-4-5 (apabila ada obat tambah 6-7)
- Alur Task Id Pasien Lama: 3-4-5 (apabila ada obat tambah 6-7)
- Sisa antrean berkurang pada task 5
- Pemanggilan antrean poli pasien muncul pada task 4
- Cek in/mulai waktu tunggu untuk pasien baru mulai pada task 1
- Cek in/mulai waktu tunggu untuk pasien lama mulai pada task 3
- Agar terdapat validasi pada sistem RS agar alur pengiriman Task Id berurutan dari awal, dan waktu Task Id yang kecil lebih dulu daripada Task Id yang besar (misal task Id 1=08.00, task Id 2= 08.05)
- jenisresep : Tidak ada/Racikan/Non racikan (jenisresep khusus untuk rs yang sudah implementasi antrean farmasi. Jika belum/tidak kolom jenisresep dapat dihilangkan)
{BASE URL}/{Service Name}/antrean/batal
Fungsi : Membatalkan antrean pasien
Method : POST
Format : Json
Header :
        x-cons-id: {cons id akses}
        x-timestamp: {timestamp akses}
        x-signature: {signature akses}
        user_key: {userkey akses}
                                                
{
   "kodebooking": "16032021A001",
   "keterangan": "Terjadi perubahan jadwal dokter, silahkan daftar kembali"
}
                                     
                                     
                                                
{
   "kodebooking": "{kodebooking yang didapat dari servis tambah antrean}",
   "keterangan": "{alasan pembatalan}"
}           
{
   "metadata": {
      "message": "Ok",
      "code": 200
   }
}
{BASE URL}/{Service Name}/antrean/getlisttask
Fungsi : Melihat waktu task id yang telah dikirim ke BPJS
Method : POST
Format : Json
Header :
        x-cons-id: {cons id akses}
        x-timestamp: {timestamp akses}
        x-signature: {signature akses}
        user_key: {userkey akses}
Request
                                                
{
   "kodebooking": "Y03-20#1617068533"
}                                                              
{
   "kodebooking": "{kodebooking yang didapat dari servis tambah antrean}",
}                        
Response
Respon : Perlu dilakukan dekripsi disisi client
                                            
{
   "response": {
      "list": [
         {
            "wakturs": "16-03-2021 11:32:49 WIB",
            "waktu": "24-03-2021 12:55:23 WIB",
            "taskname": "mulai waktu tunggu admisi",
            "taskid": 1,
            "kodebooking": "Y03-20#1617068533"
         }
      ]
   },
   "metadata": {
      "code": 200,
      "message": "OK"
   }
}
{Base URL}/{Service Name}/dashboard/waktutunggu/tanggal/{Parameter1}/waktu/{Parameter2}
Fungsi : Dashboard waktu per tanggal
Method : GET
Format : Json
Header :
        x-cons-id: {cons id akses}
        x-timestamp: {timestamp akses}
        x-signature: {signature akses}
        user_key: {userkey akses}
Parameter1 : {diisi tanggal}=> 2021-04-16
Parameter2 : {diisi waktu}=> rs atau server
                                
{
   "metadata": {
      "code": 200,
      "message": "OK"
   },
   "response": {
      "list": [
         {
            "kdppk": "1311R002",
            "waktu_task1": 0,
            "avg_waktu_task4": 0,
            "jumlah_antrean": 1,
            "avg_waktu_task3": 0,
            "namapoli": "BEDAH",
            "avg_waktu_task6": 0,
            "avg_waktu_task5": 0,
            "nmppk": "RSU AISYIYAH",
            "avg_waktu_task2": 0,
            "avg_waktu_task1": 0,
            "kodepoli": "BED",
            "waktu_task5": 0,
            "waktu_task4": 0,
            "waktu_task3": 0,
            "insertdate": 1627873951000,
            "tanggal": "2021-04-16",
            "waktu_task2": 0,
            "waktu_task6": 0
         }
      ]
   }
}                    
                                 
                                 
Catatan:
a) Waktu Task 1 = Waktu tunggu admisi dalam detik
b) Waktu Task 2 = Waktu layan admisi dalam detik
c) Waktu Task 3 = Waktu tunggu poli dalam detik
d) Waktu Task 4 = Waktu layan poli dalam detik
e) Waktu Task 5 = Waktu tunggu farmasi dalam detik
f) Waktu Task 6 = Waktu layan farmasi dalam detik
g) Insertdate = Waktu pengambilan data, timestamp dalam milisecond
h) Waktu server adalah data waktu (task 1-6) yang dicatat oleh server BPJS Kesehatan setelah RS mengimkan data, sedangkan waktu rs adalah data waktu (task 1-6) yang dikirimkan oleh RS
Dashboard Per Bulan
{Base URL}/{Service Name}/dashboard/waktutunggu/bulan/{Parameter1}/tahun/{Parameter2}/waktu/{Parameter3}
Fungsi : Dashboard waktu per bulan
Method : GET
Format : Json
Header :
        x-cons-id: {cons id akses}
        x-timestamp: {timestamp akses}
        x-signature: {signature akses}
        user_key: {userkey akses}
Parameter1 : {diisi bulan}=> 07
Parameter2 : {diisi tahun}=> 2021
Parameter3 : {diisi waktu}=> rs atau server
                                
{
   "metadata": {
      "code": 200,
      "message": "OK"
   },
   "response": {
      "list": [
         {
            "kdppk": "1311R002",
            "waktu_task1": 0,
            "avg_waktu_task4": 0,
            "jumlah_antrean": 1,
            "avg_waktu_task3": 0,
            "namapoli": "BEDAH",
            "avg_waktu_task6": 0,
            "avg_waktu_task5": 0,
            "nmppk": "RSU AISYIYAH",
            "avg_waktu_task2": 0,
            "avg_waktu_task1": 0,
            "kodepoli": "BED",
            "waktu_task5": 0,
            "waktu_task4": 0,
            "waktu_task3": 0,
            "insertdate": 1627873951000,
            "tanggal": "2021-04-16",
            "waktu_task2": 0,
            "waktu_task6": 0
         }
      ]
   }
}                    
                                 
                                 
Catatan:
a) Waktu Task 1 = Waktu tunggu admisi dalam detik
b) Waktu Task 2 = Waktu layan admisi dalam detik
c) Waktu Task 3 = Waktu tunggu poli dalam detik
d) Waktu Task 4 = Waktu layan poli dalam detik
e) Waktu Task 5 = Waktu tunggu farmasi dalam detik
f) Waktu Task 6 = Waktu layan farmasi dalam detik
g) Insertdate = Waktu pengambilan data, timestamp dalam milisecond
h) Waktu server adalah data waktu (task 1-6) yang dicatat oleh server BPJS Kesehatan setelah RS mengimkan data, sedangkan waktu rs adalah data waktu (task 1-6) yang dikirimkan oleh RS





Antrean Per Tanggal New
{BASE URL}/{Service Name}/antrean/pendaftaran/tanggal/{tanggal}
Fungsi : Melihat pendaftaran antrean per tanggal
Method : GET
Format : Json
Header :
        x-cons-id: {cons id akses}
        x-timestamp: {timestamp akses}
        x-signature: {signature akses}
        user_key: {userkey akses}
Antrean Per Kode Booking New
{BASE URL}/{Service Name}/antrean/pendaftaran/kodebooking/{kodebooking}
Fungsi : Melihat pendaftaran antrean per kode booking
Method : GET
Format : Json
Header :
        x-cons-id: {cons id akses}
        x-timestamp: {timestamp akses}
        x-signature: {signature akses}
        user_key: {userkey akses}
Response
Respon : Perlu dilakukan dekripsi disisi client
                                        
{
    "response": {
        "list": [
            {
                "kodebooking": "ABC0000001",
                "tanggal": "2021-03-24",
                "kodepoli": "INT",
                "kodedokter": 1234,
                "jampraktek": "08:00-17:00",
                "nik": "2749494383830001",
                "nokapst": "0000000000013",
                "nohp": "081234567890",
                "norekammedis": "654321",
                "jeniskunjungan": 1,
                "nomorreferensi": "1029R0021221K000012",
                "sumberdata": "Mobile JKN",
                "ispeserta": 1,
                "noantrean": "INT-0001",
                "estimasidilayani": 1669278161000,
                "createdtime": 1669278161000,
                "status": "Selesai dilayani"
            }
        ]
    },
    "metadata": {
        "code": 200,
        "message": "OK"
    }
}
Antrean Belum Dilayani New
{BASE URL}/{Service Name}/antrean/pendaftaran/aktif
Fungsi : Melihat pendaftaran antrean belum dilayani
Method : GET
Format : Json
Header :
        x-cons-id: {cons id akses}
        x-timestamp: {timestamp akses}
        x-signature: {signature akses}
        user_key: {userkey akses}
Response
Respon : Perlu dilakukan dekripsi disisi client
                                        
{
    "response": {
        "list": [
            {
                "kodebooking": "ABC0000001",
                "tanggal": "2021-03-24",
                "kodepoli": "INT",
                "kodedokter": 1234,
                "jampraktek": "08:00-17:00",
                "nik": "2749494383830001",
                "nokapst": "0000000000013",
                "nohp": "081234567890",
                "norekammedis": "654321",
                "jeniskunjungan": 1,
                "nomorreferensi": "1029R0021221K000012",
                "sumberdata": "Mobile JKN",
                "ispeserta": 1,
                "noantrean": "INT-0001",
                "estimasidilayani": 1669278161000,
                "createdtime": 1669278161000,
                "status": "Selesai dilayani"
            }
        ]
    },
    "metadata": {
        "code": 200,
        "message": "OK"
    }
}
Antrean Belum Dilayani Per Poli Per Dokter Per Hari Per Jam Praktek New
{BASE URL}/{Service Name}/antrean/pendaftaran/kodepoli/{kodepoli}/kodedokter/{kodedokter}/hari/{hari}/jampraktek/{jampraktek}
Fungsi : Melihat pendaftaran antrean belum dilayani per poli per dokter per hari per jam praktek
Method : GET
Format : Json
Header :
        x-cons-id: {cons id akses}
        x-timestamp: {timestamp akses}
        x-signature: {signature akses}
        user_key: {userkey akses}
Response
Respon : Perlu dilakukan dekripsi disisi client
                                        
{
    "response": {
        "list": [
            {
                "kodebooking": "ABC0000001",
                "tanggal": "2021-03-24",
                "kodepoli": "INT",
                "kodedokter": 1234,
                "jampraktek": "08:00-17:00",
                "nik": "2749494383830001",
                "nokapst": "0000000000013",
                "nohp": "081234567890",
                "norekammedis": "654321",
                "jeniskunjungan": 1,
                "nomorreferensi": "1029R0021221K000012",
                "sumberdata": "Mobile JKN",
                "ispeserta": 1,
                "noantrean": "INT-0001",
                "estimasidilayani": 1669278161000,
                "createdtime": 1669278161000,
                "status": "Selesai dilayani"
            }
        ]
    },
    "metadata": {
        "code": 200,
        "message": "OK"
    }
}
Web Service Antrean - RS (Diakses oleh Mobile JKN)
Token
URL : RS mengirimkan url masing-masing ws yang sudah dibuat untuk diakses oleh sistem BPJS
Fungsi : Membuat token
Method : GET
Format : Json
Header :
        x-username: {user akses}
        x-password: {password akses}
                                
{
    "response": {
        "token": "1231242353534645645"
    },
    "metadata": {
        "message": "Ok",
        "code": 200
    }
}                                                
Catatan:
User dan password yang diberikan ke BPJS Kesehatan untuk mengakses WS yang dibuat oleh RS.

Status Antrean
URL : RS mengirimkan url masing-masing ws yang sudah dibuat untuk diakses oleh sistem BPJS
Fungsi : Menampilkan status antrean per poli (digunakan untuk perencanaan kedatangan pasien)
Method : POST
Format : Json
Header :
        x-token: {token}
        x-username: {user akses}
Request
                                                
{
   "kodepoli": "ANA",
   "kodedokter": 12346,
   "tanggalperiksa": "2020-01-28",
   "jampraktek": "08:00-16:00"
}
                                                                       
{
   "kodepoli": "{memakai kode subspesialis BPJS}",
   "kodedokter": {kode dokter BPJS},
   "tanggalperiksa": "{tanggal rencana berobat}",
   "jampraktek": "{waktu praktek dokter yang diambil dari Aplikasi HFIS}"
}              

Response
                                            
{
   "response": {
      "namapoli": "Anak",
      "namadokter": "Dr. Hendra",
      "totalantrean": 25,
      "sisaantrean": 4,
      "antreanpanggil": "A-21",
      "sisakuotajkn": 5,
      "kuotajkn": 30,
      "sisakuotanonjkn": 5,
      "kuotanonjkn": 30,
      "keterangan": ""
   },
   "metadata": {
      "message": "Ok",
      "code": 200
   }
                          
Catatan:
Metadata code:
200: Sukses
201: Gagal
Selain metadata code 200, agar message pada metadata diisi sesuai dengan kondisi di lapangan

Ambil Antrean
URL : RS mengirimkan url masing-masing ws yang sudah dibuat untuk diakses oleh sistem BPJS
Fungsi : Mengambil antrean
Method : POST
Format : Json
Header :
        x-token: {token}
        x-username: {user akses}
Request
                                                
{
    "nomorkartu": "00012345678",
    "nik": "3212345678987654",
    "nohp": "085635228888",
    "kodepoli": "ANA",
    "norm": "123345",
    "tanggalperiksa": "2021-01-28",
    "kodedokter": 12345,
    "jampraktek": "08:00-16:00",
    "jeniskunjungan": 1,
    "nomorreferensi": "0001R0040116A000001"
}
                                     
                                     
                                                
{
    "nomorkartu": "{noka pasien BPJS,diisi kosong jika NON JKN}",
    "nik": "{nika pasien}",
    "nohp": "{no hp pasien}",
    "kodepoli": "{memakai kode subspesialis BPJS}",
    "norm": "{no rekam medis pasien}",
    "tanggalperiksa": "{tanggal periksa}",
    "kodedokter": {kode dokter BPJS},
    "jampraktek": "{jam praktek dokter}",
    "jeniskunjungan": {1 (Rujukan FKTP), 2 (Rujukan Internal), 3 (Kontrol), 4 (Rujukan Antar RS)},
    "nomorreferensi": "{norujukan/kontrol pasien JKN,diisi kosong jika NON JKN}"
}                        
Response
                                            
{
   "response": {
      "nomorantrean": "A-12",
      "angkaantrean": 12,
      "kodebooking": "16032021A001",
      "norm": "123345",
      "namapoli": "Anak",
      "namadokter": "Dr. Hendra",
      "estimasidilayani": 1615869169000,
      "sisakuotajkn": 5,
      "kuotajkn": 30,
      "sisakuotanonjkn": 5,
      "kuotanonjkn": 30,
      "keterangan": "Peserta harap 60 menit lebih awal guna pencatatan administrasi."
   },
   "metadata": {
      "message": "Ok",
      "code": 200
   }
}
                                     
                                     
Catatan:
estimasidilayani : format dalam milisecond

Metadata code:
200: Sukses
201: Gagal
202: Pasien Baru
Ketika RS merespon code 202, mobile JKN akan mengirimkan data pasien baru (hit WS Info Pasien Baru).
Sisa Antrean
URL : RS mengirimkan url masing-masing ws yang sudah dibuat untuk diakses oleh sistem BPJS
Fungsi : Melihat sisa antrean di hari H pelayanan
Method : POST
Format : Json
Header :
        x-token: {token}
        x-username: {user akses}




Request                                    
{
    "kodebooking": "{kodebooking yang unik yang diambil dari WS Ambil Antrean}"
}
{
    "kodebooking": "16032021A001"
}
Response
                                            
{
   "response": {
      "nomorantrean": "A20",
      "namapoli": "Anak",
      "namadokter": "Dr. Hendra",
      "sisaantrean": 12,
      "antreanpanggil": "A-8",
      "waktutunggu": 9000,
      "keterangan": ""
   },
   "metadata": {
      "message": "Ok",
      "code": 200
   }
}
Catatan:
- Format waktu dalam detik dengan formula: SPM * (sisa antrean-1)
- Metadata code:
200: Sukses
201: Gagal
Selain metadata code 200, agar message pada metadata diisi sesuai dengan kondisi di lapangan
Batal Antrean
{BASE URL}/antrean/batal
Fungsi : Membatalkan antrean pasien
Method : POST
Format : Json
Header :
        x-token: {token}
        x-username: {user akses}
Request
                                                
{
   "kodebooking": "16032021A001",
   "keterangan": "Ada kebutuhan mendadak"
}                                                
{
   "kodebooking": "{kodebooking yang didapat dari WS Ambil Antrean}",
   "keterangan": "{alasan pembatalan}"
}         
Response                                    
{
   "metadata": {
      "message": "Ok",
      "code": 200
   }
}
Catatan:
- Format waktu dalam detik dengan formula: SPM * (sisa antrean-1)
- Metadata code:
200: Sukses
201: Gagal
Selain metadata code 200, agar message pada metadata diisi sesuai dengan kondisi di lapangan
Check In
URL : RS mengirimkan url masing-masing ws yang sudah dibuat untuk diakses oleh sistem BPJS
Fungsi : Memastikan pasien sudah datang di RS
Method : POST
Format : Json
Header :
        x-token: {token}
        x-username: {user akses}
Request
                                                
{
    "kodebooking": "16032021A001",
    "waktu": 1616559330000
}
{
    "kodebooking": "{kodebooking yang didapat dari WS Ambil Antren}",
    "waktu": {waktu pasien checkin format timestamp dalam milisecond}
}          
Response
   "metadata": {
      "code": 200,
      "message": "OK"
   }
Catatan:
Metadata code:
200: Sukses
201: Gagal
Selain metadata code 200, agar message pada metadata diisi sesuai dengan kondisi di lapangan.
Info Pasien Baru
URL : RS mengirimkan url masing-masing ws yang sudah dibuat untuk diakses oleh sistem BPJS
Fungsi : Informasi identitas pasien baru yang belum punya rekam medis (tidak ada norm di Aplikasi VClaim)
Method : POST
Format : Json
Header :
        x-token: {token}
        x-username: {user akses}
Request
                                                
{
   "nomorkartu": "00012345678",
   "nik": "3212345678987654",
   "nomorkk": "3212345678987654",
   "nama": "sumarsono",
   "jeniskelamin": "L",
   "tanggallahir": "1985-03-01",
   "nohp": "085635228888",
   "alamat": "alamat yang muncul merupakan alamat lengkap",
   "kodeprop": "11",
   "namaprop": "Jawa Barat",
   "kodedati2": "0120",
   "namadati2": "Kab. Bandung",
   "kodekec": "1319",
   "namakec": "Soreang",
   "kodekel": "D2105",
   "namakel": "Cingcin",
   "rw": "001",
   "rt": "013"
}
                                     
                                     
                                                
{
   "nomorkartu": "{no kartu pasien JKN}",
   "nik": "{nika pasien}",
   "nomorkk": "{no kk pasien}",
   "nama": "{nama pasien}",
   "jeniskelamin": "{jenis kelamin pasien",
   "tanggallahir": "{tanggal lahir pasien}",
   "nohp": "{no hp pasien}",
   "alamat": "{alamat pasien}",
   "kodeprop": "{kode propinsi BPJS}",
   "namaprop": "{nama propinsi}",
   "kodedati2": "{kode kota/kab BPJS}",
   "namadati2": "{nama kota/kab}",
   "kodekec": "{kode kecamatan BPJS}",
   "namakec": "{nama kecamatan}",
   "kodekel": "{kode kelurahan BPJS}",
   "namakel": "{nama kelurahan}",
   "rw": "{no RT}",
   "rt": "{no RW}"
}                        
Response
                                            

{
   "response": {
      "norm": "123456"
   },
   "metadata": {
      "message": "Harap datang ke admisi untuk melengkapi data rekam medis",
      "code": 200
   }
}
Catatan:
Metadata code:
200: Sukses
201: Gagal
Selain metadata code 200, agar message pada metadata diisi sesuai dengan kondisi di lapangan.
Jadwal Operasi RS
URL : RS mengirimkan url masing-masing ws yang sudah dibuat untuk diakses oleh sistem BPJS
Fungsi : Informasi jadwal operasi di rumah sakit
Method : POST
Format : Json
Header :
        x-token: {token}
        x-username: {user akses}
Request
                                                
{
    "tanggalawal": "2019-12-11",
    "tanggalakhir": "2019-12-13"
}
{
    "tanggalawal": "{tanggal awal pencarian}",
    "tanggalakhir": "{tanggal akhir pencarian}"
}         
Response
                                            

{
    "response": {
        "list" : [{
             "kodebooking": "123456ZXC",
             "tanggaloperasi": "2019-12-11",
             "jenistindakan": "operasi gigi",
             "kodepoli": "001",
             "namapoli": "Poli Bedah Mulut",
             "terlaksana": 1,
             "nopeserta": "0000000924782",
             "lastupdate": 1577417743000 
        },
        {
             "kodebooking": "67890QWE",
             "tanggaloperasi": "2019-12-11",
             "jenistindakan": "operasi mulut",
             "kodepoli": "001",
             "namapoli": "Poli Bedah Mulut",
             "terlaksana": 0,
             "nopeserta": "",
             "lastupdate": 1577417743000
        }]
    },
    "metadata": {
        "message": "Ok",
        "code": 200
    }
}
Catatan:
- Kode poli memakai kode subspesialis BPJS
- Metadata code:
200: Sukses
201: Gagal
Selain metadata code 200, agar message pada metadata diisi sesuai dengan kondisi di lapangan.
Jadwal Operasi Pasien
URL : RS mengirimkan url masing-masing ws yang sudah dibuat untuk diakses oleh sistem BPJS
Fungsi : Informasi jadwal operasi per pasien
Method : POST
Format : Json
Header :
        x-token: {token}
        x-username: {user akses}
Request
                                                
{
    "nopeserta": "0000000000123"}                                                
{
    "nopeserta": "{no kartu pasien JKN}"
}            
Response
                                            

{
    "response": {
        "list" : [{
             "kodebooking": "123456ZXC",
             "tanggaloperasi": "2019-12-11",
             "jenistindakan": "operasi gigi",
             "kodepoli": "001",
             "namapoli": "Poli Bedah Mulut",
             "terlaksana": 0 
        }]
    },
    "metadata": {
        "message": "Ok",
        "code": 200
    }
}
                                     
                                     
Catatan:
- Kode poli memakai kode subspesialis BPJS
- Metadata code:
200: Sukses
201: Gagal
Selain metadata code 200, agar message pada metadata diisi sesuai dengan kondisi di lapangan.
Ambil Antrean Farmasi New
URL : RS mengirimkan url masing-masing ws yang sudah dibuat untuk diakses oleh sistem BPJS
Fungsi : Mengambil antrean farmasi
Method : POST
Format : Json
Header :
        x-token: {token}
        x-username: {user akses}
Request
                            
{
    "kodebooking": "00012345678"
}
Response
                        
{
    "response": {
        "jenisresep": "Racikan/Non Racikan",
        "nomorantrean": 1,
        "keterangan": ""
    },
    "metadata": {
        "message": "Ok",
        "code": 200
    }
}
                 
Status Antrean Farmasi New
URL : RS mengirimkan url masing-masing ws yang sudah dibuat untuk diakses oleh sistem BPJS
Fungsi : Mengetahui status antrean farmasi
Method : POST
Format : Json
Header :
        x-token: {token}
        x-username: {user akses}
Request
                            
{
    "kodebooking": "00012345678"
}
Response
                        
{
    "response": {
        "jenisresep": "Racikan/Non Racikan",
        "totalantrean": 10,
        "sisaantrean": 8,
        "antreanpanggil": 2,
        "keterangan": ""
    },
    "metadata": {
        "message": "Ok",
        "code": 200
    }
}

	



