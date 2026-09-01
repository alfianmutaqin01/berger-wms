<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Support\Import\CustomerImporter;
use App\Support\Import\Importer;
use App\Support\Import\ProductImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

/**
 * Impor master data dari berkas Excel (.xlsx / .xls).
 *
 * Alurnya dua tahap dan disengaja:
 *   1. unggah -> berkas disimpan sementara -> PRATINJAU (tanpa menyentuh DB)
 *   2. konfirmasi -> baru disimpan, lalu berkas sementara dihapus
 *
 * Impor bersifat memperbarui data yang sudah ada (berdasarkan SKU / kode
 * pelanggan), sehingga satu berkas keliru bisa menimpa data yang benar.
 * Pratinjau memberi kesempatan membatalkan sebelum itu terjadi.
 */
class ImportController extends Controller
{
    /** Berkas sementara disimpan di disk lokal, di luar public. */
    private const TEMP_DIR = 'imports';

    public function preview(Request $request, string $type): View|RedirectResponse
    {
        $config = $this->configFor($type);

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ], [], ['file' => 'berkas Excel']);

        // Nama berkas dibangkitkan sendiri, bukan memakai nama asli dari
        // pengguna, agar tidak ada jalur yang bisa diarahkan ke tempat lain.
        $token = Str::uuid()->toString();
        $stored = self::TEMP_DIR.'/'.$token.'.'.$request->file('file')->getClientOriginalExtension();

        $saved = Storage::disk('local')->putFileAs(
            self::TEMP_DIR,
            $request->file('file'),
            basename($stored)
        );

        // Kegagalan MENULIS diperiksa terpisah dari kegagalan MEMBACA. Tanpa
        // ini, folder yang tidak bisa ditulis (mis. kepemilikan berubah jadi
        // root akibat perintah artisan yang dijalankan sebagai root) akan
        // dilaporkan sebagai "berkas tidak dapat dibaca" — menyesatkan, karena
        // berkas penggunanya sebenarnya tidak bermasalah sama sekali.
        if ($saved === false || ! Storage::disk('local')->exists($stored)) {
            return redirect()->route($config['index_route'])->with(
                'error',
                'Berkas gagal disimpan sementara di server. Periksa izin tulis pada folder storage/app/private/imports.'
            );
        }

        try {
            $preview = $this->importerFor($type, $request)->preview(Storage::disk('local')->path($stored));
        } catch (RuntimeException $e) {
            Storage::disk('local')->delete($stored);

            return redirect()->route($config['index_route'])->with('error', $e->getMessage());
        }

        return view('wms.master.import-preview', [
            'type' => $type,
            'title' => $config['title'],
            'indexRoute' => $config['index_route'],
            'token' => $token,
            'extension' => $request->file('file')->getClientOriginalExtension(),
            'originalName' => $request->file('file')->getClientOriginalName(),
            'rows' => $preview['rows'],
            'summary' => $preview['summary'],
        ]);
    }

    public function store(Request $request, string $type): RedirectResponse
    {
        $config = $this->configFor($type);

        $validated = $request->validate([
            'token' => ['required', 'uuid'],
            'extension' => ['required', 'in:xlsx,xls'],
        ]);

        $stored = self::TEMP_DIR.'/'.$validated['token'].'.'.$validated['extension'];

        if (! Storage::disk('local')->exists($stored)) {
            return redirect()->route($config['index_route'])
                ->with('error', 'Berkas sementara sudah tidak tersedia. Silakan unggah ulang.');
        }

        try {
            $summary = $this->importerFor($type, $request)->import(Storage::disk('local')->path($stored));
        } catch (RuntimeException $e) {
            return redirect()->route($config['index_route'])->with('error', $e->getMessage());
        } finally {
            Storage::disk('local')->delete($stored);
        }

        $message = sprintf(
            'Impor selesai: %d ditambahkan, %d diperbarui.',
            $summary['baru'],
            $summary['perbarui']
        );

        if ($summary['gagal'] === 0) {
            return redirect()->route($config['index_route'])->with('success', $message);
        }

        // Baris yang gagal dilaporkan berikut ALASANNYA. Sebelumnya hanya
        // jumlahnya yang disebut, dengan alasan seragam "datanya tidak
        // lengkap" — menyesatkan bila sebabnya lain (mis. isi kolom melampaui
        // panjang maksimum), dan tidak memberi tahu baris mana yang harus
        // diperbaiki di berkas.
        $message .= sprintf(' %d baris dilewati:', $summary['gagal']);

        $shown = array_slice($summary['galat'], 0, 10);
        $message .= ' '.implode(' ', $shown);

        if (count($summary['galat']) > count($shown)) {
            $message .= sprintf(' (dan %d baris lain)', count($summary['galat']) - count($shown));
        }

        return redirect()->route($config['index_route'])->with('warning', $message);
    }

    /** Membatalkan pratinjau: buang berkas sementara agar tidak menumpuk. */
    public function cancel(Request $request, string $type): RedirectResponse
    {
        $config = $this->configFor($type);

        $validated = $request->validate([
            'token' => ['required', 'uuid'],
            'extension' => ['required', 'in:xlsx,xls'],
        ]);

        Storage::disk('local')->delete(self::TEMP_DIR.'/'.$validated['token'].'.'.$validated['extension']);

        return redirect()->route($config['index_route'])->with('success', 'Impor dibatalkan.');
    }

    private function importerFor(string $type, Request $request): Importer
    {
        $actorId = $request->user()?->id;

        return match ($type) {
            'products' => new ProductImporter($actorId),
            'customers' => new CustomerImporter($actorId),
        };
    }

    /** @return array{title: string, index_route: string} */
    private function configFor(string $type): array
    {
        return match ($type) {
            'products' => ['title' => 'Master Produk', 'index_route' => 'wms.products.index'],
            'customers' => ['title' => 'Master Pelanggan', 'index_route' => 'wms.customers.index'],
            default => abort(404),
        };
    }
}
