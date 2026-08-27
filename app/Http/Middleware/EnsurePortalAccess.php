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
 */
class EnsurePortalAccess
{
    public function handle(Request $request, Closure $next, string $portal): Response
    {
        $isSales = $request->user()?->hasRole(Role::SALES) ?? false;
        $allowed = $portal === 'sales' ? $isSales : ! $isSales;

        abort_unless($allowed, 403);

        return $next($request);
    }
}
