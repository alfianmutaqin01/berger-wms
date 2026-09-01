<?php

namespace App\Http\Requests\Wms;

use App\Models\Role;
use App\Models\User;
use App\Support\CurrentActor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validasi perubahan data user.
 *
 * Beda utama dari StoreUserRequest:
 * - `password` opsional — kosong berarti kata sandi lama dipertahankan.
 * - Aturan unique mengecualikan baris user yang sedang diubah.
 * - Ada pemeriksaan tambahan: tidak boleh menjadikan diri sendiri atasan sendiri.
 */
class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = CurrentActor::get();
        $target = $this->route('user');

        if (! $actor || ! $target instanceof User) {
            return false;
        }

        return $actor->canManage($target);
    }

    public function rules(): array
    {
        $userId = $this->route('user')->id;

        return [
            'employee_id' => ['required', 'string', 'max:50', Rule::unique('users', 'employee_id')->ignore($userId)->whereNull('deleted_at')],
            'full_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($userId)->whereNull('deleted_at')],
            'password' => ['nullable', 'string', 'min:8', 'regex:/[A-Za-z]/', 'regex:/[0-9]/'],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('is_active', true)],
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('is_active', true)],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->whereNull('deleted_at')],
            'manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.regex' => 'Kata sandi harus memuat kombinasi huruf dan angka.',
            'employee_id.unique' => 'NIK ini sudah terdaftar pada karyawan lain.',
            'email.unique' => 'Email ini sudah terdaftar pada karyawan lain.',
        ];
    }

    public function attributes(): array
    {
        return [
            'employee_id' => 'NIK',
            'full_name' => 'nama lengkap',
            'role_id' => 'role akses',
            'department_id' => 'departemen',
            'warehouse_id' => 'lokasi tugas',
            'manager_id' => 'atasan langsung',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $actor = CurrentActor::get();
            $target = $this->route('user');

            // Seorang user tidak boleh menjadi atasan dirinya sendiri — akan
            // membuat penelusuran rantai approval berputar tanpa henti.
            if ($this->input('manager_id') && (int) $this->input('manager_id') === $target->id) {
                $validator->errors()->add('manager_id', 'Seorang karyawan tidak dapat menjadi atasan bagi dirinya sendiri.');
            }

            if ($actor?->isSuperAdmin()) {
                return;
            }

            $role = Role::find($this->input('role_id'));

            if ($role && $role->slug === Role::SUPER_ADMIN) {
                $validator->errors()->add('role_id', 'Anda tidak memiliki wewenang menetapkan role Super Admin.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'warehouse_id' => $this->input('warehouse_id') ?: null,
            'manager_id' => $this->input('manager_id') ?: null,
        ]);
    }
}
