<?php

namespace App\Http\Requests\Wms;

use App\Models\Role;
use App\Support\CurrentActor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validasi pembuatan user baru.
 *
 * DATA CONTRACT (input):
 * - employee_id   : string, unik di tabel users
 * - full_name     : string
 * - email         : email, unik
 * - password      : min 8, wajib mengandung huruf DAN angka (PRD §6.2 F-MASTER-01)
 * - phone_number  : string|null
 * - role_id       : exists:roles,id
 * - department_id : exists:departments,id
 * - warehouse_id  : exists:warehouses,id|null  (null = akses semua gudang)
 * - manager_id    : exists:users,id|null
 * - avatar        : image|null, maks 2MB
 * - is_active     : boolean
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return CurrentActor::get()?->canManageUsers() ?? false;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'string', 'max:50', Rule::unique('users', 'employee_id')->whereNull('deleted_at')],
            'full_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'password' => ['required', 'string', 'min:8', 'regex:/[A-Za-z]/', 'regex:/[0-9]/'],
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

    /**
     * Manager tidak boleh membuat akun ber-role Super Admin (PRD §6.2 F-MASTER-01).
     *
     * Diperiksa di sisi server, bukan sekadar disembunyikan dari dropdown: dropdown
     * hanyalah tampilan, sedangkan request POST dapat dikirim langsung dengan
     * `role_id` apa pun.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $actor = CurrentActor::get();

            if ($actor?->isSuperAdmin()) {
                return;
            }

            $role = Role::find($this->input('role_id'));

            if ($role && $role->slug === Role::SUPER_ADMIN) {
                $validator->errors()->add('role_id', 'Anda tidak memiliki wewenang membuat akun dengan role Super Admin.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            // Select bernilai string kosong berarti "akses semua gudang" atau
            // "tanpa atasan", bukan angka nol.
            'warehouse_id' => $this->input('warehouse_id') ?: null,
            'manager_id' => $this->input('manager_id') ?: null,
        ]);
    }
}
