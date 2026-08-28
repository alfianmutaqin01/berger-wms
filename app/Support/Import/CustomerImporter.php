<?php

namespace App\Support\Import;

use App\Models\Customer;
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
                // Nomor dari ERP ditulis dengan kode negara tanpa tanda plus dan
                // kadang mengandung spasi/strip; disimpan sebagai digit saja.
                'phone' => $this->digits($this->value($row, ['phone_no', 'phone', 'telepon'])),
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

    private function digits(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return preg_replace('/\D/', '', $value) ?: null;
    }

    private function upper(?string $value): ?string
    {
        return blank($value) ? null : strtoupper(trim($value));
    }
}
