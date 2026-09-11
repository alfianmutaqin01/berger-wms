#!/usr/bin/env bash
#
# Jalan pintas pengujian berger-wms.
#
# KENAPA ADA BERKAS INI
# ---------------------
# Menjalankan seluruh 1000+ test untuk memeriksa satu perubahan kecil memakan
# 13 menit sebelum paralel dipasang, dan ~3-4 menit sesudahnya. Terlalu mahal
# untuk dilakukan tiap kali menyimpan berkas — dan yang terjadi kalau terlalu
# mahal bukan "diuji lebih jarang", melainkan TIDAK DIUJI SAMA SEKALI.
#
# Dua kecepatan, untuk dua keperluan yang memang berbeda:
#
#   ./uji.sh ubah          detik        sambil mengerjakan
#   ./uji.sh <Nama>        detik        satu modul yang sedang disentuh
#   ./uji.sh penuh         ~3-4 menit   SEBELUM COMMIT, tanpa kecuali
#
# YANG CEPAT TIDAK MENGGANTIKAN YANG PENUH
# ----------------------------------------
# Menguji "cuma fitur yang baru" terdengar masuk akal sampai diingat apa yang
# sebenarnya ditangkap suite penuh: kerusakan di tempat yang TIDAK disentuh.
# Tiga contoh nyata dari proyek ini, semuanya dalam satu hari kerja:
#
#   - Satu baris Blade di halaman Outstanding mematikan halaman itu untuk SEMUA
#     peran; yang menangkapnya 21 test di modul lain.
#   - Migrasi transfer antar gudang mematikan SmokeRouteTest untuk tiap peran,
#     karena factory-nya belum tahu ada kolom baru. Tidak ada satu pun test
#     transfer yang gagal.
#   - Mengubah arti satu kolom di Master Produk memecahkan 9 test di 4 modul
#     lain: impor, produksi, putaway, verifikasi.
#
# Ketiganya LOLOS kalau yang dijalankan hanya test fitur yang baru ditambahkan.
#
set -euo pipefail

PHP="${UJI_PHP:-docker compose exec -T -u www-data php-fpm}"

warna() { printf '\033[1;36m%s\033[0m\n' "$1"; }
pucat() { printf '\033[0;90m%s\033[0m\n' "$1"; }

pakai() {
    cat <<'HELP'
Pemakaian:

  ./uji.sh                  sama dengan "ubah"
  ./uji.sh ubah             test yang menyangkut berkas yang Anda ubah
  ./uji.sh <Nama>           test yang namanya cocok, mis. ./uji.sh StockTransfer
  ./uji.sh penuh            SELURUH test, 8 proses paralel (~3-4 menit)
  ./uji.sh lambat           seluruh test, satu proses (~13 menit) — dipakai
                            kalau hasil paralel mencurigakan dan perlu dipastikan
  ./uji.sh lihat            hanya menampilkan test apa yang akan dijalankan

Contoh:
  ./uji.sh PalletCapacity           satu modul
  ./uji.sh "Outstanding|Picking"    beberapa modul sekaligus
HELP
}

# Berkas yang kalau berubah membuat SELURUH suite wajib jalan.
#
# Bukan kehati-hatian berlebihan — ketiganya sudah pernah merusak modul yang
# jauh dari tempat perubahannya, dan tidak satu pun test di modul yang disentuh
# ikut gagal:
#
#   factory & migrasi  bentuk data berubah untuk SEMUA test sekaligus
#   Permission, rute   menentukan siapa boleh membuka apa, di seluruh layar
#   layout Blade       satu galat sintaks mematikan tiap halaman yang memakainya
menyeluruh() {
    case "$1" in
        database/factories/*|database/migrations/*|database/seeders/*) return 0 ;;
        app/Support/Permission.php|app/Support/WarehouseScope.php) return 0 ;;
        routes/*) return 0 ;;
        resources/views/layouts/*) return 0 ;;
        app/Models/User.php|app/Models/Role.php) return 0 ;;
        phpunit.xml|composer.json|composer.lock) return 0 ;;
        *) return 1 ;;
    esac
}

# Peta modul: sepotong jalur berkas => test yang menjaganya.
#
# EKSPLISIT, BUKAN DITEBAK. Pencocokan otomatis lewat nama kelas saja tidak
# cukup: WarehouseTransfer.php tidak pernah disebut namanya di test mana pun —
# ia diuji lewat HTTP oleh StockTransferTest. Hanya orang yang tahu.
#
# Kalau ada modul baru, tambahkan barisnya di sini. Yang tidak tercantum jatuh
# ke suite penuh, bukan diam-diam terlewat.
peta() {
    case "$1" in
        *Inbound*|*inbound*)        echo 'ProductionInputTest|ProductionHistoryTest|PutawayTest|VerificationTest|BatchPriorityTest' ;;
        *Picking*|*picking*)        echo 'PickingTest|RepeatPickingTest|StockTransferTest' ;;
        *Shipment*|*Delivery*|*delivery*|*Proof*) echo 'ShipmentTest|ProofOfDeliveryTest|DeliveryNoteImportTest' ;;
        *Outstanding*|*Reshipment*|*outstanding*) echo 'OutstandingHistoryTest|OutstandingReshipmentTest' ;;
        *Approval*|*Cancel*|*Rejection*|*approval*) echo 'OrderApprovalTest|OrderCancellationTest|CustomerRejectionTest' ;;
        *StockTake*|*stocktake*)    echo 'StockTakeTest' ;;
        *Transfer*|*transfer*)      echo 'StockTransferTest|PickingTest' ;;
        *Inventory*|*inventory*)    echo 'InventoryTest|StockQuarantineTest|StockReplenishmentTest|WarehouseMapContentsTest|StockTransferTest' ;;
        *PalletCapacity*|*pallet*)  echo 'PalletCapacityTest|ProductManagementTest|ProductionInputTest|ImportTest' ;;
        *Product*|*product*)        echo 'ProductManagementTest|ImportTest|PalletCapacityTest' ;;
        *Customer*|*customer*)      echo 'CustomerManagementTest|CustomerRejectionTest' ;;
        *Location*|*master*)        echo 'LocationManagementTest|ProductManagementTest|CustomerManagementTest|UserManagementTest' ;;
        *Admin*|*Settings*|*admin*) echo 'SystemSettingsTest|PalletCapacityTest|ActivityLogTest|UserManagementTest' ;;
        *Controllers/Sales/*|*views/sales/*) echo 'SalesOrderTest|ProductBookingTest' ;;
        *Booking*|*booking*)        echo 'ProductBookingTest' ;;
        *Reporting*|*reports*)      echo 'ReportTest|DashboardAdminTest|DashboardPeranTest' ;;
        *Dashboard*|*dashboard*)    echo 'DashboardAdminTest|DashboardPeranTest' ;;
        *Notification*|*notif*)     echo 'NotificationTest' ;;
        *Import*)                   echo 'ImportTest' ;;
        *InternalOrder*)            echo 'InternalOrderTest' ;;
        *ActivityLog*)              echo 'ActivityLogTest' ;;
        *Billing*|*billing*)        echo 'SmokeRouteTest' ;;
        *) return 1 ;;
    esac
}

# Test mana yang menyangkut berkas yang sedang diubah.
#
# Tiga sumber digabung, dari yang paling meyakinkan:
#   1. berkas test yang berubah      — sudah pasti harus jalan
#   2. nama kelas yang berubah       — dicari di dalam berkas test
#   3. peta modul di atas            — untuk yang tidak pernah disebut namanya
#
# Mengembalikan string kosong berarti "tidak tahu" — dan pemanggilnya
# menjalankan suite penuh, bukan melaporkan semuanya hijau.
kelas_terdampak() {
    local berubah nama=() berkas kelas cocok modul

    berubah=$( { git diff --name-only HEAD 2>/dev/null || true; git ls-files --others --exclude-standard 2>/dev/null || true; } | sort -u | sed '/^$/d')

    [ -z "$berubah" ] && return 0

    while IFS= read -r berkas; do
        [ -z "$berkas" ] && continue

        if menyeluruh "$berkas"; then
            echo '__PENUH__'
            return 0
        fi

        case "$berkas" in
            tests/*Test.php)
                nama+=("$(basename "$berkas" .php)")
                continue
                ;;
        esac

        case "$berkas" in
            app/*.php)
                kelas=$(basename "$berkas" .php)
                while IFS= read -r cocok; do
                    [ -n "$cocok" ] && nama+=("$(basename "$cocok" .php)")
                done < <(grep -rl --include='*Test.php' -w "$kelas" tests/ 2>/dev/null || true)
                ;;
        esac

        if modul=$(peta "$berkas"); then
            while IFS='|' read -ra potongan; do
                for cocok in "${potongan[@]}"; do
                    nama+=("$cocok")
                done
            done <<< "$modul"
        fi
    done <<< "$berubah"

    printf '%s\n' "${nama[@]+"${nama[@]}"}" | sort -u | sed '/^$/d' | paste -sd '|' -
}

jalankan_ubah() {
    local hanya_lihat="${1:-tidak}"
    local filter

    filter=$(kelas_terdampak)

    if [ "$filter" = '__PENUH__' ]; then
        warna "Perubahan Anda menyentuh berkas yang berlaku untuk seluruh sistem."
        pucat "Factory, migrasi, rute, hak akses, atau layout — ketiganya sudah pernah"
        pucat "merusak modul yang jauh dari tempat perubahannya. Menjalankan yang penuh."
        [ "$hanya_lihat" = "lihat" ] && return 0
        $PHP php artisan test --parallel
        return $?
    fi

    if [ -z "$filter" ]; then
        warna "Tidak ada perubahan, atau perubahannya tidak terpetakan ke test mana pun."
        pucat "Menjalankan yang penuh — lebih baik menunggu daripada mengira sudah diuji."
        [ "$hanya_lihat" = "lihat" ] && return 0
        $PHP php artisan test --parallel
        return $?
    fi

    warna "Test yang menyangkut perubahan Anda:"
    printf '  %s\n' "${filter//|/, }"
    echo
    pucat "INI BUKAN PENGGANTI ./uji.sh penuh. Kerusakan di modul yang tidak Anda"
    pucat "sentuh tidak akan terlihat di sini."
    echo

    [ "$hanya_lihat" = "lihat" ] && return 0

    $PHP php artisan test --parallel --filter="$filter"
}

perintah="${1:-ubah}"

case "$perintah" in
    -h|--help|bantuan) pakai ;;

    penuh)
        warna "Seluruh test, 8 proses paralel."
        pucat "Inilah yang wajib hijau sebelum commit."
        $PHP php artisan test --parallel
        ;;

    lambat)
        warna "Seluruh test, satu proses (~13 menit)."
        pucat "Dipakai kalau hasil paralel mencurigakan dan perlu dipastikan."
        $PHP php artisan test
        ;;

    lihat) jalankan_ubah lihat ;;
    ubah)  jalankan_ubah ;;

    *)
        warna "Test yang namanya cocok dengan \"$perintah\"."
        $PHP php artisan test --parallel --filter="$perintah"
        ;;
esac
