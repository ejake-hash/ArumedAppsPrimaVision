<?php

namespace App\Http\Controllers;

use App\Models\RmTemplate;
use Illuminate\Http\Request;

class RmTemplateController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama_rm' => 'required|string',
            'schema' => 'required|array', // JSON dari frontend
        ]);

        $template = RmTemplate::create([
            'nama_rm' => $validated['nama_rm'],
            'schema_json' => json_encode($validated['schema']), // Simpan sebagai JSON
            'is_active' => true,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Template berhasil disimpan ke Master Data.',
            'data' => $template
        ]);
    }

    public function index()
    {
        // Untuk mengambil data buat ditampilkan di daftar Master Data Anda
        return response()->json(RmTemplate::all());
    }
}