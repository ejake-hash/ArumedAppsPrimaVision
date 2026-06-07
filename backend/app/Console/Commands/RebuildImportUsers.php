<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Import staff users + employees from the rebuild "data pengguna" spreadsheet.
 *
 * Sheet "Pengguna" columns: name, username, email, role, is_active, profession,
 * nip, sip, str, NIK (Sp.M doctors only).
 *
 * Reuses UserService::create() so the password (model cast 'hashed') and the PIN
 * (plaintext) are stored correctly and doctor employees are auto-linked. Adds the
 * two things the built-in CSV importer cannot: a FIXED default password/PIN (888888)
 * and the employee NIK (for SatuSehat by-NIK).
 *
 * DEV / REHEARSAL ONLY. Dry-run by default; --force to apply. Idempotent: skips rows
 * whose username/email already exist, and flags duplicates within the file.
 */
class RebuildImportUsers extends Command
{
    protected $signature = 'rebuild:import-users
        {file? : Path to the .xlsx}
        {--force : Apply (default: dry-run preview only)}
        {--password=888888 : Default password for every imported user}
        {--pin=888888 : Default signature PIN for every imported user}
        {--gen-missing : Auto-generate username (from name) and email (username@domain) when blank}
        {--email-domain=primavision.local : Domain for generated placeholder emails}';

    protected $description = 'Import staff users+employees from xlsx (reuse UserService::create; pw/pin 888888 + doctor NIK). DEV ONLY.';

    public function handle(): int
    {
        $db = DB::connection()->getDatabaseName();
        if (app()->environment('production') || $db === 'arumed_primavision') {
            $this->error("REFUSED: must not run on production / arumed_primavision (db={$db}).");

            return self::FAILURE;
        }

        $file = $this->argument('file') ?: base_path('../Docs/migrasi data/template-pengguna updated.xlsx');
        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $password = (string) $this->option('password');
        $pin = (string) $this->option('pin');
        $genMissing = (bool) $this->option('gen-missing');
        $emailDomain = (string) $this->option('email-domain');

        $ss = IOFactory::load($file);
        $sheet = $ss->getSheetByName('Pengguna') ?? $ss->getActiveSheet();
        $rows = $sheet->toArray();
        if (count($rows) < 2) {
            $this->error('Spreadsheet kosong / hanya header.');

            return self::FAILURE;
        }

        $hdr = array_map(fn ($h) => strtolower(trim((string) $h)), $rows[0]);
        $ix = array_flip($hdr);
        $nikIdx = -1;
        foreach ($hdr as $j => $h) {
            if (str_contains($h, 'nik')) {
                $nikIdx = $j;
            }
        }
        $col = fn (array $r, string $k) => isset($ix[$k]) ? trim((string) ($r[$ix[$k]] ?? '')) : '';

        foreach (['name', 'username', 'email', 'role'] as $req) {
            if (! isset($ix[$req])) {
                $this->error("Kolom wajib \"{$req}\" tidak ada di header.");

                return self::FAILURE;
            }
        }

        $roleByName = Role::all()->keyBy(fn ($r) => strtolower($r->name));

        $toCreate = [];
        $skipped = [];
        $errors = [];
        $perRole = [];
        $seenUser = [];
        $seenEmail = [];
        $remapped = [];
        $generated = [];

        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $rowNo = $i + 1;
            $name = $col($row, 'name');
            $username = $col($row, 'username');
            $email = $col($row, 'email');
            $roleName = $col($row, 'role');
            $nik = $nikIdx >= 0 ? trim((string) ($row[$nikIdx] ?? '')) : '';

            if ($name === '' && $username === '' && $email === '' && $roleName === '') {
                continue; // fully blank line
            }

            // File "superadmin" → manajemen (tak buat superadmin ke-2; instruksi user).
            if (strtolower($roleName) === 'superadmin') {
                $remapped[] = "row {$rowNo} ({$name}): superadmin → manajemen";
                $roleName = 'manajemen';
            }

            // Auto-isi username/email kosong (opsional --gen-missing). Bisa diedit nanti.
            if ($genMissing) {
                if ($username === '' && $name !== '') {
                    $username = $this->uniqueUsername($name, $seenUser);
                    $generated[] = "row {$rowNo} ({$name}): username → {$username}";
                }
                if ($email === '' && $username !== '') {
                    $email = strtolower($username) . '@' . $emailDomain;
                    $generated[] = "row {$rowNo} ({$username}): email → {$email}";
                }
            }

            if ($name === '' || $username === '' || $email === '' || $roleName === '') {
                $errors[] = "row {$rowNo} ({$name}): name/username/email/role wajib diisi (pakai --gen-missing utk auto-isi)";

                continue;
            }
            $role = $roleByName->get(strtolower($roleName));
            if (! $role) {
                $errors[] = "row {$rowNo} ({$username}): role \"{$roleName}\" tidak dikenal";

                continue;
            }
            $uKey = strtolower($username);
            $eKey = strtolower($email);
            if (isset($seenUser[$uKey]) || isset($seenEmail[$eKey])) {
                $errors[] = "row {$rowNo} ({$username}): duplikat username/email DI DALAM file";

                continue;
            }
            if (User::where('username', $username)->orWhere('email', $email)->exists()) {
                $skipped[] = "row {$rowNo} ({$username}): username/email sudah ada di DB";

                continue;
            }

            $seenUser[$uKey] = true;
            $seenEmail[$eKey] = true;
            $toCreate[] = [
                'name' => $name,
                'username' => $username,
                'email' => $email,
                'role' => $role,
                'is_active' => $col($row, 'is_active'),
                'profession' => $col($row, 'profession'),
                'nip' => $col($row, 'nip'),
                'sip' => $col($row, 'sip'),
                'str' => $col($row, 'str'),
                'nik' => $nik,
            ];
            $perRole[$role->name] = ($perRole[$role->name] ?? 0) + 1;
        }

        $this->info("DB={$db}  FILE=" . basename($file) . '  MODE=' . ($force ? 'FORCE' : 'DRY-RUN'));
        $this->info('Akan dibuat: ' . count($toCreate) . '  |  skip(existing): ' . count($skipped) . '  |  error: ' . count($errors));
        ksort($perRole);
        foreach ($perRole as $r => $c) {
            $this->line(sprintf('  %-14s %d', $r, $c));
        }
        foreach ($remapped as $m) {
            $this->line('  REMAP ' . $m);
        }
        foreach ($generated as $m) {
            $this->line('  GEN   ' . $m);
        }
        foreach ($skipped as $s) {
            $this->warn('  SKIP ' . $s);
        }
        foreach ($errors as $e) {
            $this->error('  ERR  ' . $e);
        }

        if (! $force) {
            $this->warn("\nDRY-RUN — belum ada yang dibuat. Jalankan dgn --force untuk eksekusi.");

            return self::SUCCESS;
        }

        $userService = app(UserService::class);
        $created = 0;
        $failed = 0;
        foreach ($toCreate as $rec) {
            try {
                $data = [
                    'name' => $rec['name'],
                    'username' => $rec['username'],
                    'email' => $rec['email'],
                    'role_id' => $rec['role']->id,
                    'password' => $password, // cast 'hashed' → JANGAN Hash::make
                    'pin' => $pin,           // plaintext by design
                    'is_active' => $rec['is_active'] === '' ? true : (bool) (int) $rec['is_active'],
                ];
                foreach (['profession', 'nip', 'sip', 'str'] as $f) {
                    if ($rec[$f] !== '') {
                        $data[$f] = $rec[$f]; // only pass present values (avoid nulling)
                    }
                }
                if (strtolower($rec['role']->name) === 'dokter') {
                    $dt = \App\Models\Employee::resolveDoctorType($rec['profession'] ?? '');
                    if ($dt !== null) {
                        $data['doctor_type'] = $dt; // derive jenis dokter dari profession
                    }
                }

                $res = $userService->create($data);

                if ($rec['nik'] !== '') { // NIK (Sp.M) — UserService tak menanganinya
                    $user = User::find($res['id']);
                    if (! $user->employee_id) {
                        $emp = Employee::create([
                            'name' => $rec['name'],
                            'profession' => $rec['profession'] !== '' ? $rec['profession'] : ($rec['role']->display_name ?? 'Nakes'),
                            'is_active' => true,
                        ]);
                        $user->update(['employee_id' => $emp->id]);
                    }
                    Employee::where('id', $user->employee_id)->update(['nik' => $rec['nik']]);
                }
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $this->error('  GAGAL ' . $rec['username'] . ': ' . $e->getMessage());
            }
        }

        $this->info("\nDONE. Dibuat: {$created}  Gagal: {$failed}");
        $this->line('Total users sekarang: ' . User::count() . '  |  employees: ' . Employee::count());

        return self::SUCCESS;
    }

    /**
     * Buat username unik dari nama (buang gelar, ambil 2 kata pertama), dedup
     * terhadap username yang sudah dipakai di file ($seen) & di DB.
     */
    private function uniqueUsername(string $name, array $seen): string
    {
        $titles = ['dr', 'drg', 'prof', 'h', 'hj', 'ny', 'tn', 'sp', 'm', 'mked', 'aifo', 'k', 'subsp', 'mkm', 'med', 'oph'];
        $parts = preg_split('/[\s.,()]+/', explode(',', $name)[0]);
        $parts = array_values(array_filter($parts, fn ($p) => $p !== '' && ! in_array(strtolower(trim($p, '.')), $titles, true)));
        $slug = strtolower(preg_replace('/[^a-z0-9]/i', '', implode('', array_slice($parts, 0, 2))));
        if ($slug === '') {
            $slug = 'user';
        }
        $slug = substr($slug, 0, 24);

        $cand = $slug;
        $n = 1;
        while (isset($seen[strtolower($cand)]) || User::where('username', $cand)->exists()) {
            $cand = $slug . $n;
            $n++;
        }

        return $cand;
    }
}
