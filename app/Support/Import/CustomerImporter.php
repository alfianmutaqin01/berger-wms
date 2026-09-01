<?php

namespace App\Support\Import;

use App\Models\Customer;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;

/**
 * Mengimpor Master Pelanggan dari ekspor ERP Berger.
 *
 * Kolom yang dikenali:
 *   No./id | Ship-to Code | Name | Phone No. | Contact | Email |
 *   Address | Address 2 | Territory Code
 */
class CustomerImporter extends Importer
{
    protected function requiredHeaders(): array
    {
        return ['name'];
    }

    protected function keyColumn(): string
    {
        return 'code';
    }

    protected function table(): string
    {
        return 'customers';
    }

    /** Nama kolom sebagaimana tertulis di berkas ekspor ERP. */
    protected function columnLabels(): array
    {
        return [
            'code' => 'No./id',
            'ship_to_code' => 'Ship-to Code',
            'name' => 'Name',
            'phone' => 'Phone No.',
            'contact_name' => 'Contact',
            'email' => 'Email',
            'territory_code' => 'Territory Code',
        ];
    }

    protected function existingKeys(): array
    {
        return Customer::withTrashed()->pluck('code')->all();
    }

    protected function mapRow(array $row): ?array
    {
        $code = $this->value($row, ['no_id', 'no', 'id', 'code', 'kode']);
        $name = $this->value($row, ['name', 'nama', 'nama_pelanggan']);

        if (blank($name)) {
            $this->fail('Kolom Name kosong.');

            return null;
        }

        if (blank($code)) {
            $this->fail('Kolom No./id kosong — kode pelanggan dipakai sebagai kunci, jadi wajib terisi.');

            return null;
        }

        $address = $this->value($row, ['address', 'alamat']);

        if (blank($address)) {
            $this->fail('Kolom Address kosong.');

            return null;
        }

        return [
            'key' => strtoupper($code),
            'label' => $name,
            'data' => [
                'ship_to_code' => $this->value($row, ['ship_to_code', 'shipto_code']),
                'name' => $name,
                // Satu sel bisa memuat dua nomor dipisah garis miring; lihat
                // App\Support\PhoneNumber untuk aturan pemisahannya.
                'phone' => PhoneNumber::normalize($this->value($row, ['phone_no', 'phone', 'telepon'])),
                'contact_name' => $this->value($row, ['contact', 'kontak', 'pic']),
                'email' => $this->value($row, ['email']),
                'address' => $address,
                'address_2' => $this->value($row, ['address_2', 'address2']),
                'territory_code' => $this->upper($this->value($row, ['territory_code', 'territory'])),
                'is_active' => true,
            ],
        ];
    }

    protected function persist(string $key, array $data): bool
    {
        return DB::transaction(function () use ($key, $data) {
            $customer = Customer::withTrashed()->where('code', $key)->first();

            if ($customer) {
                // Impor ulang tidak menghidupkan kembali pelanggan yang sengaja
                // dinonaktifkan Manager.
                unset($data['is_active']);
                $customer->update($data);

                return false;
            }

            Customer::create($data + ['code' => $key, 'created_by' => $this->actorId]);

            return true;
        });
    }

    private function upper(?string $value): ?string
    {
        return blank($value) ? null : strtoupper(trim($value));
    }
}
