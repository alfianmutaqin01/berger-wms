<?php

namespace App\Http\Controllers;

use App\Jobs\SendArrivalNoticeToSales;
use App\Models\ActivityLog;
use App\Models\DeliveryNote;
use App\Models\Notification;
use App\Support\Activity;
use App\Support\Notifier;
use App\Support\Outbound\ArrivalPhoto;
use App\Support\Outbound\Shipment;
use App\Support\Permission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Konfirmasi penerimaan oleh supir — PRD §6.5 F-OUT-04 #10.
 *
 * SATU-SATUNYA HALAMAN PUBLIK YANG MENGUBAH DATA. Supir tidak punya akun dan
 * tidak akan pernah punya: ia berganti setiap hari dan sebagian besar dari
 * perusahaan jasa lain. Karena itu TOKEN-nya yang menjadi kunci, dan
 * konsekuensinya menentukan seluruh rancangan halaman ini:
 *
 *   - Token dicari sebagai KOLOM, bukan disusun dari id. Tautan yang bisa
 *     ditebak dari nomor urut membuat siapa pun bisa mengonfirmasi kiriman
 *     orang lain.
 *   - Token yang tidak dikenal dijawab 404 POLOS, tanpa menyebut apa pun
 *     tentang dokumen yang ada. Halaman ini terbuka ke internet.
 *   - Yang bisa dilakukan hanya SATU: menyatakan barang sampai. Tidak ada
 *     daftar, tidak ada pencarian, tidak ada data pelanggan lain.
 *   - Yang ditampilkan seperlunya saja untuk supir memastikan ia membuka
 *     kiriman yang benar — bukan seluruh isi pesanan berikut harganya.
 */
class EpodController extends Controller
{
    public function __construct(
        private readonly Shipment $pengiriman,
        private readonly ArrivalPhoto $foto,
    ) {}

    public function show(string $token): View
    {
        return view('driver.epod', [
            'note' => $this->cari($token),
        ]);
    }

    public function confirm(Request $request, string $token): RedirectResponse
    {
        $note = $this->cari($token);

        $data = $request->validate([
            // Tidak wajib: supir sering tidak sempat menanyakan nama
            // penerima, dan menahan konfirmasi karenanya berarti pengiriman
            // yang sudah sampai tidak pernah tercatat sampai.
            'received_by_name' => ['nullable', 'string', 'max:100'],

            // WAJIB, dan inilah perubahan pokok Fase 12. Pesan galatnya
            // ditulis untuk orang yang sedang berdiri di depan gudang
            // pelanggan sambil memegang HP — bukan "The photo field is
            // required".
            'photo' => [
                'required', 'file', 'image',
                'mimetypes:'.implode(',', ArrivalPhoto::MIME_DIIZINKAN),
                'max:'.(int) (ArrivalPhoto::MAKS_BYTE / 1024),
            ],
            'photo_source' => ['nullable', 'string', 'in:camera,file'],
        ], [
            'photo.required' => 'Foto barang di lokasi belum diambil. Arahkan kamera ke barang lalu tekan tombol ambil foto.',
            'photo.image' => 'Berkas yang terkirim bukan gambar. Ulangi pengambilan fotonya.',
            'photo.mimetypes' => 'Berkas yang terkirim bukan gambar. Ulangi pengambilan fotonya.',
            'photo.max' => 'Fotonya terlalu besar. Ulangi pengambilan fotonya.',
        ], ['received_by_name' => 'nama penerima', 'photo' => 'foto barang']);

        // Disimpan DI LUAR transaksi. Kalau transaksinya nanti gagal, yang
        // tertinggal cuma berkas yatim di disk — jauh lebih murah daripada
        // baris basis data yang menunjuk berkas yang gagal ditulis.
        $foto = $this->foto->simpan(
            $request->file('photo'),
            $data['photo_source'] ?? ArrivalPhoto::SUMBER_BERKAS,
        );

        try {
            $this->pengiriman->confirmDelivery($note, $data['received_by_name'] ?? null, $foto);
        } catch (RuntimeException $e) {
            // Konfirmasinya batal, jadi fotonya tidak boleh tertinggal:
            // berkas yatim yang tidak ditunjuk siapa pun akan menumpuk diam-
            // diam sampai disknya penuh, dan tidak ada yang tahu asalnya.
            $this->foto->buang($foto['arrival_photo_path']);

            return back()->with('error', $e->getMessage());
        }

        /*
         * SATU-SATUNYA LOG YANG PELAKUNYA BUKAN PENGGUNA SISTEM. Supir tidak
         * punya akun, jadi user_id/user_name/user_role-nya kosong dan yang
         * tersisa hanyalah IP serta nama penerima yang ia tulis. Tetap
         * dicatat: inilah titik di mana pesanan berpindah dari "di jalan"
         * menjadi "sampai", dan ketiadaan pelaku bernama justru alasan
         * tambahan untuk meninggalkan jejaknya.
         */
        Activity::record(
            ActivityLog::EPOD_CONFIRM,
            sprintf(
                'Supir mengonfirmasi Surat Jalan %s sampai di tujuan%s.',
                $note->document_no,
                filled($data['received_by_name'] ?? null)
                    ? ', diterima '.trim($data['received_by_name'])
                    : '',
            ),
            $note,
            $note->warehouse_id,
            [
                'surat_jalan' => $note->document_no,
                'penerima' => $data['received_by_name'] ?? null,
                'supir' => $note->driver_name,
                // Asal fotonya ikut dicatat karena 'camera' dan 'file' TIDAK
                // sama kuat sebagai bukti — lihat App\Support\Outbound\
                // ArrivalPhoto. Yang menelusuri sengketa pengiriman perlu
                // tahu bedanya tanpa harus membuka barisnya sendiri.
                'foto' => $foto['arrival_photo_source'],
            ],
        );

        // Barang sampai, tapi pesanannya BELUM selesai: fotonya masih harus
        // diunggah. Inilah titik yang paling mudah terlupakan Sales, karena
        // tidak ada apa pun di layarnya yang berubah saat supir menekan
        // tombol di tempat lain.
        Notifier::toUser(
            $note->salesOrder?->user_id,
            Notification::PROOF_NEEDED,
            'Barang sampai — unggah bukti Surat Jalan',
            sprintf(
                'Surat Jalan %s sudah dikonfirmasi sampai. Pesanan %s belum bisa ditutup sebelum foto Surat Jalan bertanda tangan diunggah.',
                $note->document_no,
                $note->salesOrder?->order_number ?? '',
            ),
            $note->sales_order_id ? url('/sales/orders/'.$note->sales_order_id) : null,
            $note->warehouse_id,
            $note,
        );

        /*
         * WHATSAPP KE SALES — permintaan pemilik produk. Lonceng di atas
         * hanya terbaca kalau Sales kebetulan membuka sistemnya, sementara
         * Sales bekerja di jalan dengan HP di saku. Justru saat inilah ia
         * perlu bergerak: Surat Jalan bertanda tangan harus difoto selagi
         * masih di tangan pelanggan.
         *
         * 'pending' ditulis SEBELUM job diantrekan, supaya layar Logistik
         * yang dibuka detik itu juga menyebut "menunggu dikirim" — bukan
         * kosong seolah tidak ada kabar yang akan keluar.
         */
        $note->forceFill(['sales_notify_status' => DeliveryNote::NOTIFY_PENDING])->save();
        SendArrivalNoticeToSales::dispatch($note->id);

        // Logistik cukup lewat lonceng web — keputusan pemilik produk. Mereka
        // bekerja di depan layar sistem sepanjang hari; WhatsApp untuk mereka
        // hanya menambah satu pesan lagi di antara ratusan.
        Notifier::toPermission(
            Permission::OUTBOUND_DELIVERY,
            $note->warehouse_id,
            Notification::DELIVERY_ARRIVED,
            'Barang sampai di tujuan',
            sprintf(
                'Surat Jalan %s untuk %s dikonfirmasi sampai oleh supir%s. Menunggu Sales mengunggah bukti Surat Jalan.',
                $note->document_no,
                $note->customer?->name ?? 'pelanggan',
                filled($data['received_by_name'] ?? null) ? ', diterima '.trim($data['received_by_name']) : '',
            ),
            route('wms.delivery.show', $note),
            $note,
        );

        return redirect()
            ->route('epod.show', $token)
            ->with('success', 'Terima kasih. Pengiriman ini sudah tercatat sampai di tujuan.');
    }

    /**
     * Dokumen pemegang token ini.
     *
     * 404 untuk token yang tidak dikenal MAUPUN dokumen yang belum berangkat:
     * keduanya dijawab sama supaya halaman publik ini tidak bisa dipakai
     * menebak-nebak token mana yang ada.
     */
    private function cari(string $token): DeliveryNote
    {
        $note = DeliveryNote::query()
            ->with(['lines.product:id,sku,name,uom', 'customer:id,name'])
            ->where('epod_token', $token)
            ->whereIn('status', [DeliveryNote::STATUS_SHIPPED, DeliveryNote::STATUS_DELIVERED])
            ->first();

        abort_if($note === null, 404);

        return $note;
    }
}
