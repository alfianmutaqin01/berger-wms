<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wms\StoreUserRequest;
use App\Http\Requests\Wms\UpdateUserRequest;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentActor;
use App\Support\WarehouseScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Manajemen User (PRD §6.2 F-MASTER-01).
 *
 * Akses: Super Admin dan Manager. Manager tidak dapat menyentuh akun Super Admin —
 * aturan itu ditegakkan di User::canManage() dan di Form Request, bukan hanya
 * dengan menyembunyikan tombol di tampilan.
 */
class UserController extends Controller
{
    /**
     * Daftar user.
     *
     * DATA CONTRACT untuk Frontend:
     * - $users        : LengthAwarePaginator<User> (15/halaman), eager-load role,
     *                   department, warehouse, manager
     * - $roles        : Collection<Role>       — hanya role yang boleh ditetapkan aktor
     * - $departments  : Collection<Department> — departemen aktif
     * - $warehouses   : Collection<Warehouse>  — gudang aktif
     * - $managers     : Collection<User>       — kandidat atasan langsung
     * - $actor        : User|null              — user yang sedang bertindak
     * - $stats        : array{total,active,inactive}
     * - $filters      : array{search,role_id,warehouse_id,status}
     */
    public function index(Request $request): View
    {
        $actor = CurrentActor::get();

        abort_unless($actor?->canManageUsers(), 403, 'Anda tidak memiliki akses ke Manajemen User.');

        $filters = [
            'search' => $request->query('search'),
            'role_id' => $request->query('role_id'),
            'warehouse_id' => WarehouseScope::resolveFilter($request, $actor),
            'status' => $request->query('status'),
        ];

        // Manager hanya melihat akun gudangnya. Akun lintas gudang (Super
        // Admin, warehouse_id NULL) ikut tersaring keluar — itu memang benar:
        // ia bukan akun yang boleh disentuh Manager mana pun.
        $terlihat = fn () => WarehouseScope::apply(User::query(), $actor);

        $users = $terlihat()
            // Eager loading mencegah N+1: tanpa ini, tabel 15 baris memicu
            // 60+ query tambahan untuk role, departemen, gudang, dan atasan.
            ->with(['role', 'department', 'warehouse', 'manager'])
            ->search($filters['search'])
            ->when($filters['role_id'], fn ($q, $roleId) => $q->where('role_id', $roleId))
            ->when($filters['warehouse_id'], fn ($q, $wh) => $q->where('warehouse_id', $wh))
            ->when($filters['status'] === 'active', fn ($q) => $q->where('is_active', true))
            ->when($filters['status'] === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('full_name')
            ->paginate(15)
            ->withQueryString();

        return view('wms.admin.users', [
            'users' => $users,
            'roles' => Role::query()->assignableBy($actor)->get(),
            'departments' => Department::active()->orderBy('name')->get(),
            'warehouses' => WarehouseScope::options($actor)->where('is_active', true)->values(),
            'managers' => $terlihat()->active()->orderBy('full_name')->get(['id', 'full_name', 'employee_id']),
            'actor' => $actor,
            'stats' => [
                'total' => $terlihat()->count(),
                'active' => $terlihat()->where('is_active', true)->count(),
                'inactive' => $terlihat()->where('is_active', false)->count(),
            ],
            'filters' => $filters,
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $actor = CurrentActor::get();

        // Akun baru harus lahir di dalam kewenangan pembuatnya. Tanpa ini,
        // Manager Karawang bisa membuat akun Manager Surabaya lalu memakainya.
        WarehouseScope::assert($request->validated('warehouse_id'), $actor);

        $data = $request->validated();
        $data['created_by'] = $actor?->id;

        if ($request->hasFile('avatar')) {
            $data['avatar_path'] = $request->file('avatar')->store('avatars', 'public');
        }
        unset($data['avatar']);

        $user = User::create($data);

        return redirect()
            ->route('wms.users.index')
            ->with('success', "Akun {$user->full_name} ({$user->employee_id}) berhasil dibuat.");
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        // Gudang TUJUAN diperiksa terpisah dari akun yang disunting: tanpa
        // ini, Manager bisa memindahkan akun keluar dari gudangnya sendiri
        // dan kehilangan kendali atasnya (atau menanam akun di gudang lain).
        WarehouseScope::assert($request->validated('warehouse_id'), CurrentActor::get());

        $data = $request->validated();

        // Password kosong berarti pengelola tidak bermaksud menggantinya.
        // Tanpa pemeriksaan ini, setiap penyuntingan profil akan menimpa kata
        // sandi dengan string kosong dan mengunci user dari akunnya sendiri.
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        if ($request->hasFile('avatar')) {
            if ($user->avatar_path) {
                Storage::disk('public')->delete($user->avatar_path);
            }
            $data['avatar_path'] = $request->file('avatar')->store('avatars', 'public');
        }
        unset($data['avatar']);

        $user->update($data);

        return redirect()
            ->route('wms.users.index')
            ->with('success', "Data {$user->full_name} berhasil diperbarui.");
    }

    /**
     * Menonaktifkan / mengaktifkan akun.
     *
     * Sengaja TIDAK menghapus data: riwayat kerja user (input produksi, put-away,
     * approval) harus tetap dapat ditelusuri meski karyawan sudah resign.
     */
    public function toggleStatus(User $user): RedirectResponse
    {
        $actor = CurrentActor::get();

        abort_unless($actor?->canManage($user), 403, 'Anda tidak memiliki wewenang mengubah akun ini.');

        // Mencegah pengelola mengunci dirinya sendiri keluar dari sistem.
        if ($actor->id === $user->id) {
            return back()->with('error', 'Anda tidak dapat menonaktifkan akun Anda sendiri.');
        }

        // Super Admin terakhir yang masih aktif harus tetap ada, kalau tidak
        // sistem kehilangan satu-satunya akun yang bisa memulihkan keadaan.
        if ($user->is_active && $user->isSuperAdmin() && $this->activeSuperAdminCount() <= 1) {
            return back()->with('error', 'Tidak dapat menonaktifkan Super Admin terakhir yang masih aktif.');
        }

        $user->update(['is_active' => ! $user->is_active]);

        $state = $user->is_active ? 'diaktifkan' : 'dinonaktifkan';

        return back()->with('success', "Akun {$user->full_name} berhasil {$state}.");
    }

    private function activeSuperAdminCount(): int
    {
        return User::where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('slug', Role::SUPER_ADMIN))
            ->count();
    }
}
