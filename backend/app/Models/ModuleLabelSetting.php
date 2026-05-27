<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Override nama tampilan modul (kolom "Modul / Fitur" di matriks RBAC).
 * Berlaku UI-only — tidak mengubah permission key/action, hanya label tampilan.
 * Default ada di PermissionService::defaultLabels(); tabel ini hanya menyimpan
 * modul yang sudah di-rename admin (berlaku untuk semua pengguna).
 */
class ModuleLabelSetting extends Model
{
    protected $fillable = ['module', 'label'];

    /** Map [module => label] dari semua override. */
    public static function overrides(): array
    {
        return static::pluck('label', 'module')->all();
    }
}
