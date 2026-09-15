<?php

namespace App\Http\Requests\Wms;

use App\Models\StockTransfer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Menerima kiriman antar gudang di gudang TUJUAN (F-INV-05).
 *
 * Yang diperiksa di sini hanya bentuk isiannya. Aturan yang butuh tahu isi
 * dokumennya — qty tidak melebihi yang dikirim, alasan wajib bila kurang,
 * rak harus milik gudang tujuan — ada di App\Support\Inventory\
 * WarehouseTransfer, karena semuanya harus berjalan DI DALAM kunci baris.
 * Memeriksanya di sini juga berarti dua tempat yang harus selalu sepakat.
 */
class ReceiveStockTransferRequest extends FormRequest
{
    /**
     * Hanya gudang TUJUAN yang boleh menerima.
     *
     * Diperiksa di authorize(), bukan di controller: validasi berjalan lebih
     * dulu, sehingga penerimaan oleh gudang lain dengan isian tak lengkap
     * akan dijawab "isian kurang" alih-alih "bukan wewenang Anda".
     */
    public function authorize(): bool
    {
        $transfer = $this->route('transfer');

        if (! $transfer instanceof StockTransfer) {
            return false;
        }

        $gudang = $this->user()?->warehouse_id;

        // NULL = akun lintas gudang (Super Admin), boleh menutup di mana pun.
        return $gudang === null || $gudang === $transfer->to_warehouse_id;
    }

    public function rules(): array
    {
        return [
            'baris' => ['required', 'array', 'min:1'],
            'baris.*.qty' => ['required', 'integer', 'min:0', 'max:1000000'],
            'baris.*.location_code' => ['nullable', 'string', 'max:20'],
            'baris.*.reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return [
            'baris' => 'rincian penerimaan',
        ];
    }

    /**
     * Isian penerimaan, dikunci nomor id baris detail.
     *
     * @return array<int, array{qty:int, location_code:string, reason:?string}>
     */
    public function barisData(): array
    {
        $hasil = [];

        foreach ($this->validated('baris') as $id => $baris) {
            $hasil[(int) $id] = [
                'qty' => (int) $baris['qty'],
                'location_code' => (string) ($baris['location_code'] ?? ''),
                'reason' => $baris['reason'] ?? null,
            ];
        }

        return $hasil;
    }
}
