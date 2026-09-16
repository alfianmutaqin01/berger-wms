<?php

namespace App\Http\Requests\Concerns;

/**
 * Membaca isian sebagai TEKS di prepareForValidation() — temuan SQA.
 *
 * prepareForValidation() berjalan SEBELUM aturan `string` sempat menolak
 * apa pun. Isian yang dikirim sebagai array (`code[]=x`) langsung sampai ke
 * trim() / strtoupper() / fungsi bertipe ?string dan menjatuhkan formulir
 * master data dengan galat 500 alih-alih pesan validasi.
 *
 * Yang bukan teks dibaca sebagai null: aturan `required`/`string` sesudahnya
 * yang memberi tahu pengguna, seperti isian kosong biasa.
 */
trait MembacaTeks
{
    protected function teks(string $kunci): ?string
    {
        $nilai = $this->input($kunci);

        return is_string($nilai) ? trim($nilai) : null;
    }

    /** Teks yang dirapikan ke huruf besar, atau null bila kosong / bukan teks. */
    protected function teksBesar(string $kunci): ?string
    {
        $nilai = $this->teks($kunci);

        return filled($nilai) ? strtoupper($nilai) : null;
    }
}
