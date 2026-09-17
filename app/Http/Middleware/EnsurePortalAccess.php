<?php

namespace App\Http\Middleware;

use App\Models\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PRD §5.2: Tim Sales hanya boleh mengakses Portal Sales; seluruh role lain
 * (Super Admin termasuk) TIDAK boleh mengakses Portal Sales sama sekali —
 * "Akses Portal Sales (Buat PO)" bernilai ❌ untuk semua role Warehouse/Admin.
 *
 * BOLEH DISEBUT LEBIH DARI SATU PORTAL (`portal:wms,sales`), dan halaman itu
 * terbuka untuk keduanya. Perlu ada karena beberapa layar memang milik dua
 * portal sekaligus: permintaan material diajukan Produksi DAN Sales, dan
 * keduanya membaca dokumen yang sama persis. Menyalin layarnya ke sisi Sales
 * berarti dua salinan yang harus sepakat selamanya; memagari Sales keluar
 * berarti izin MRF-nya tidak pernah bisa dipakai.
 *
 * Yang tidak berubah: portal Sales tetap tertutup rapat bagi role lain.
 */
class EnsurePortalAccess
{
    public function handle(Request $request, Closure $next, string ...$portals): Response
    {
        $isSales = $request->user()?->hasRole(Role::SALES) ?? false;

        foreach ($portals as $portal) {
            if ($portal === 'sales' ? $isSales : ! $isSales) {
                return $next($request);
            }
        }

        abort(403);
    }
}
