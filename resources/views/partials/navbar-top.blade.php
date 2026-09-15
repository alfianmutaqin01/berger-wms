<nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm border-0 py-0">
              <div class="container-fluid px-4">
                  
                  <button type="button" id="sidebarToggle" class="btn btn-light d-lg-none me-3 rounded-circle border-0 text-dark">
                      <i class="bi bi-list"></i>
                  </button>
                  
                  @php
                      $hour = now()->format('H');
                      if ($hour < 11) {
                          $greeting = 'Selamat Pagi';
                          $icon = 'bi-brightness-alt-high text-warning';
                      } elseif ($hour < 15) {
                          $greeting = 'Selamat Siang';
                          $icon = 'bi-brightness-high text-warning';
                      } elseif ($hour < 18) {
                          $greeting = 'Selamat Sore';
                          $icon = 'bi-sunset text-danger';
                      } else {
                          $greeting = 'Selamat Malam';
                          $icon = 'bi-moon-stars text-primary';
                      }
                      // Identitas diambil dari user yang benar-benar login (Fase 1
                      // Autentikasi). Parameter $userName/$userLabel/$userInitials
                      // lama dipertahankan sebagai fallback murni jika suatu saat
                      // partial ini dirender tanpa actor (semestinya tidak terjadi,
                      // karena semua route pemanggilnya sudah di balik middleware auth).
                      $actor = \App\Support\CurrentActor::get();
                      $uName = $actor?->full_name ?? ($userName ?? 'Pengguna');
                      $uLabel = $actor?->role?->name ?? ($userLabel ?? '');
                      $uInitials = $actor?->initials ?? ($userInitials ?? '?');
                  @endphp
                  <h5 class="mb-0 fw-bold text-dark d-none d-md-flex align-items-center" style="letter-spacing: -0.5px;">
                      <i class="bi {{ $icon }} me-2 fs-4"></i> {{ $greeting }}, {{ $uName }}
                  </h5>
                  
                  <div class="ms-auto d-flex align-items-center gap-3">
                      {{-- LONCENG NOTIFIKASI (Fase 9).

                           Isinya dari tabel notifications lewat View Composer di
                           AppServiceProvider, bukan lagi dua kartu karangan
                           dengan titik merah yang menyala selamanya. Titik merah
                           yang tidak pernah padam adalah titik merah yang
                           berhenti dibaca orang. --}}
                      @php
                          $belumDibaca = $loncengBelumDibaca ?? 0;
                          $terbaru = $loncengTerbaru ?? collect();
                      @endphp
                      <div class="dropdown">
                          <button class="btn btn-light rounded-circle position-relative border-0" type="button"
                                  data-bs-toggle="dropdown" style="width: 40px; height: 40px;"
                                  aria-label="Notifikasi{{ $belumDibaca > 0 ? ' ('.$belumDibaca.' belum dibaca)' : '' }}">
                              <i class="bi {{ $belumDibaca > 0 ? 'bi-bell-fill' : 'bi-bell' }}"></i>
                              @if($belumDibaca > 0)
                                  <span class="position-absolute badge rounded-pill bg-danger"
                                        style="top: 2px; left: 60%; font-size: .6rem;">
                                      {{ $belumDibaca > 9 ? '9+' : $belumDibaca }}
                                      <span class="visually-hidden">belum dibaca</span>
                                  </span>
                              @endif
                          </button>
                          <div class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-3 mt-2 p-0" style="width: 320px;">
                              <div class="dropdown-header d-flex justify-content-between align-items-center border-bottom p-3 bg-light" style="border-top-left-radius: 0.5rem; border-top-right-radius: 0.5rem;">
                                  <h6 class="mb-0 fw-bold text-dark">Notifikasi</h6>
                                  @if($belumDibaca > 0)
                                      <span class="badge bg-primary rounded-pill">{{ $belumDibaca }} Baru</span>
                                  @endif
                              </div>
                              <div class="p-2">
                                  @forelse($terbaru as $n)
                                      {{-- Diklik = ditandai dibaca lalu diantar ke
                                           halamannya. Dua hal sekaligus, supaya
                                           loncengnya tidak tetap merah sesudah
                                           pekerjaannya dikerjakan. --}}
                                      <a class="dropdown-item d-flex gap-3 align-items-start rounded px-2 py-2 mb-1 {{ $n->read_at ? 'opacity-75' : '' }}"
                                         href="{{ route('wms.notifications.open', $n) }}" style="white-space: normal;">
                                          <div class="mt-1"><i class="bi {{ $n->ikon }} text-{{ $n->warna }} fs-5"></i></div>
                                          <div class="flex-grow-1">
                                              <small class="fw-bold d-block text-{{ $n->read_at ? 'dark' : $n->warna }} mb-1">{{ $n->title }}</small>
                                              <small class="text-muted text-wrap d-block lh-sm mb-2" style="font-size: 0.8rem;">{{ $n->body }}</small>
                                              <small class="text-muted d-block" style="font-size: 0.7rem;">
                                                  <i class="bi bi-clock me-1"></i>{{ $n->created_at?->diffForHumans() }}
                                              </small>
                                          </div>
                                      </a>
                                  @empty
                                      <div class="text-center text-muted small py-4">
                                          <i class="bi bi-bell-slash fs-4 d-block mb-2 opacity-50"></i>
                                          Belum ada notifikasi.
                                      </div>
                                  @endforelse
                              </div>
                              <div class="dropdown-divider my-0"></div>
                              <a href="{{ route('wms.notifications.index') }}" class="dropdown-item text-center py-2 text-primary fw-bold small bg-light" style="border-bottom-left-radius: 0.5rem; border-bottom-right-radius: 0.5rem;">
                                  Lihat Semua Notifikasi <i class="bi bi-arrow-right ms-1"></i>
                              </a>
                          </div>
                      </div>
                      
                      {{-- Role Switcher dihapus: sejak login sungguhan aktif (Fase 1,
                           rencana pembangunan), peran ditentukan oleh akun
                           yang login, bukan lagi dipilih bebas lewat menu ini. Lihat
                           dokumen arsitektur §5.3. --}}

                      <!-- User Profile -->
                      <div class="dropdown">
                          <button class="btn btn-light rounded-circle p-0 border-0 shadow-sm" type="button" data-bs-toggle="dropdown" style="width: 42px; height: 42px; overflow: hidden; background: linear-gradient(135deg, #123962, #1b528a);">
                              <span class="text-white fw-bold">{{ $uInitials }}</span>
                          </button>
                          <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 mt-2">
                              <li>
                                  <div class="px-4 py-3 text-center border-bottom bg-light rounded-top">
                                      <h6 class="mb-0 fw-bold text-dark">{{ $uName }}</h6>
                                      <small class="text-muted">{{ $uLabel }}</small>
                                  </div>
                              </li>
                              {{-- MILIK SEMUA ROLE. Dulu disembunyikan dari Tim Sales
                                   karena profil hanya ada di Portal WMS, dan Sales
                                   dipagari keluar dari portal itu — sehingga satu-satunya
                                   role yang paling sering berpindah perangkat justru tidak
                                   bisa mengganti sandinya sendiri. Rutenya kini di /profile,
                                   di luar kedua portal. --}}
                              <li><a class="dropdown-item py-2 mt-2" href="{{ route('profile') }}"><i class="bi bi-person me-2 text-secondary"></i>Profil Saya</a></li>

                              <li><hr class="dropdown-divider"></li>
                              <li>
                                  <form action="{{ route('logout') }}" method="POST">
                                      @csrf
                                      <button type="submit" class="dropdown-item py-2 text-danger fw-bold border-0 bg-transparent w-100 text-start">
                                          <i class="bi bi-box-arrow-right me-2"></i>Keluar
                                      </button>
                                  </form>
                              </li>
                          </ul>
                      </div>
                  </div>
              </div>
          </nav>