<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reset the database to a clean "base" state for the rebuild rehearsal (Fase 1).
 *
 *   KEEP  : config master (clinic profile .. Form Registry), the BIOM penunjang
 *           type, and the superadmin user.
 *   EMPTY : the catalog (procedures / medications / bhp / iol / surgery packages /
 *           tariffs / rooms) plus ALL transactional / clinical / demo data and the
 *           transient logs / jobs / sessions.
 *
 * DEV / REHEARSAL ONLY — refuses to run on production or the `arumed_primavision`
 * (live) database. Default is a non-destructive dry-run; pass --force to apply.
 */
class RebuildResetToBase extends Command
{
    protected $signature = 'rebuild:reset-to-base {--force : Apply the deletes (default: dry-run preview only)}';

    protected $description = 'Reset DB to base for rebuild: keep config master + BIOM + superadmin, empty catalog + transactional/clinical/demo (DEV ONLY).';

    /** Config-master tables preserved in full (untouched). */
    private array $keepFull = [
        // Institution & branding
        'clinic_profiles',
        'tv_media_settings', 'tv_branding_settings', 'tv_display_settings', 'tv_audio_settings',
        'module_label_settings',
        // Form Registry
        'document_types', 'document_templates', 'document_number_configs', 'station_document_mappings', 'rm_templates',
        // Billing config
        'billing_categories',
        // Insurers / coverage config
        'insurers', 'insurer_document_requirements', 'bpjs_poli_mappings',
        // Diagnosis / procedure coding
        'icd10_codes', 'icd9_codes',
        // Refraction master options
        'refraction_options',
        // External integrations (SatuSehat / BPJS credentials)
        'integration_configs',
        // RBAC (column-based user->role; pivots kept defensively)
        'roles', 'permissions', 'role_permissions',
        'model_has_roles', 'model_has_permissions', 'role_has_permissions',
    ];

    /** Never touched. */
    private array $system = ['migrations'];

    /** Partially kept / specially handled below (not blanket-emptied). */
    private array $special = ['diagnostic_test_types', 'users', 'employees'];

    public function handle(): int
    {
        $db = DB::connection()->getDatabaseName();
        $env = app()->environment();

        if ($env === 'production' || $db === 'arumed_primavision') {
            $this->error("REFUSED: this command must not run on production / arumed_primavision (db={$db}, env={$env}).");

            return self::FAILURE;
        }

        $all = collect(DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'"))
            ->pluck('tablename')->sort()->values();

        $empty = $all->reject(fn ($t) => in_array($t, $this->keepFull, true)
            || in_array($t, $this->system, true)
            || in_array($t, $this->special, true))->values();

        $missing = collect($this->keepFull)->reject(fn ($t) => $all->contains($t));

        $count = fn ($t) => (int) DB::table($t)->count();
        $force = (bool) $this->option('force');

        $this->info("DB={$db}  ENV={$env}  MODE=" . ($force ? 'FORCE (will delete)' : 'DRY-RUN'));
        if ($missing->isNotEmpty()) {
            $this->warn('Whitelist names not present in DB (ignored): ' . $missing->implode(', '));
        }

        $this->line("\n== KEEP (config master, untouched) ==");
        foreach ($this->keepFull as $t) {
            if ($all->contains($t)) {
                $this->line(sprintf('  keep   %-34s %7d', $t, $count($t)));
            }
        }

        $this->line("\n== KEEP (partial) ==");
        $this->line(sprintf('  keep   %-34s BIOM only (currently %d types)', 'diagnostic_test_types', $count('diagnostic_test_types')));
        $this->line(sprintf('  keep   %-34s superadmin only (currently %d users)', 'users', $count('users')));
        $this->line(sprintf('  EMPTY  %-34s all (currently %d employees)', 'employees', $count('employees')));

        $this->line("\n== EMPTY (catalog + transactional/clinical/demo) ==");
        $total = 0;
        foreach ($empty as $t) {
            $c = $count($t);
            $total += $c;
            if ($c > 0) {
                $this->line(sprintf('  empty  %-34s %7d', $t, $c));
            }
        }
        $this->info("EMPTY set: {$empty->count()} tables, {$total} rows to delete (already-empty tables hidden).");

        if (! $force) {
            $this->warn("\nDRY-RUN — nothing changed. Re-run with --force to apply.");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($empty) {
            DB::statement('SET LOCAL session_replication_role = replica'); // disable FK triggers for this txn only
            foreach ($empty as $t) {
                DB::table($t)->delete();
            }
            DB::table('diagnostic_test_types')->where('code', '<>', 'BIOM')->delete();
            DB::table('employees')->delete();
            $superId = DB::table('roles')->where('name', 'superadmin')->value('id');
            DB::table('users')->whereRaw('role_id IS DISTINCT FROM ?', [$superId])->delete();
        });

        $this->info("\nDONE. Post-cleanup counts:");
        foreach ([
            'users', 'employees', 'diagnostic_test_types', 'patients', 'visits', 'procedures',
            'medications', 'surgery_packages', 'document_templates', 'document_types',
            'billing_categories', 'clinic_profiles', 'insurers',
        ] as $t) {
            $this->line(sprintf('  %-24s %7d', $t, $count($t)));
        }

        return self::SUCCESS;
    }
}
