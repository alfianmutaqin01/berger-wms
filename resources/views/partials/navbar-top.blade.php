<nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm border-0 py-0 no-print">
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
                      // Use parameter or fallback
                      $uName = $userName ?? 'Budi Santoso';
                      $uLabel = $userLabel ?? 'Sales Representative';
                      $uInitials = $userInitials ?? 'BS';
                  @endphp
                  <h5 class="mb-0 fw-bold text-dark d-none d-md-flex align-items-center" style="letter-spacing: -0.5px;">
                      <i class="bi {{ $icon }} me-2 fs-4"></i> {{ $greeting }}, {{ $uName }}
                  </h5>
                  
                  <div class="ms-auto d-flex align-items-center gap-3">
                      <!-- Notifications -->
                      <div class="dropdown">
                          <button class="btn btn-light rounded-circle position-relative border-0" type="button" data-bs-toggle="dropdown" style="width: 40px; height: 40px;">
                              <i class="bi bi-bell"></i>
                              <span class="position-absolute top-0 start-100 translate-middle p-1 bg-danger border border-light rounded-circle" style="margin-top: 5px; margin-left: -5px;">
                                  <span class="visually-hidden">New alerts</span>
                              </span>
                          </button>
                          <div class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-3 mt-2 p-0" style="width: 320px;">
                              <div class="dropdown-header d-flex justify-content-between align-items-center border-bottom p-3 bg-light" style="border-top-left-radius: 0.5rem; border-top-right-radius: 0.5rem;">
                                  <h6 class="mb-0 fw-bold text-dark">Notifikasi</h6>
                                  <span class="badge bg-primary rounded-pill">1 Baru</span>
                              </div>
                              <div class="p-2">
                                  <a class="dropdown-item d-flex gap-3 align-items-start rounded px-2 py-2 mb-1" href="#" style="white-space: normal;">
                                        <div class="mt-1"><i class="bi bi-bell-fill text-primary fs-5"></i></div>
                                        <div class="flex-grow-1">
                                            <small class="fw-bold d-block text-primary mb-1">Pesanan Baru</small>
                                            <small class="text-muted text-wrap d-block lh-sm mb-2" style="font-size: 0.8rem;">PO-00145 sedang Menunggu Diterima.</small>
                                            <small class="text-muted d-block" style="font-size: 0.7rem;"><i class="bi bi-clock me-1"></i> 21 Ags 2026, 09:30 WIB</small>
                                        </div>
                                    </a>
                                    
                                  <a class="dropdown-item d-flex gap-3 align-items-start rounded px-2 py-2 mb-1 opacity-75" href="#" style="white-space: normal;">
                                        <div class="mt-1"><i class="bi bi-check-circle-fill text-success fs-5"></i></div>
                                        <div class="flex-grow-1">
                                            <small class="fw-bold d-block text-success mb-1">Retur Selesai</small>
                                            <small class="text-muted text-wrap d-block lh-sm mb-2" style="font-size: 0.8rem;">Proses Good Stock untuk RTN-0081 selesai.</small>
                                            <small class="text-muted d-block" style="font-size: 0.7rem;"><i class="bi bi-clock me-1"></i> 20 Ags 2026, 14:15 WIB</small>
                                        </div>
                                    </a>
                              </div>
                              <div class="dropdown-divider my-0"></div>
                              <a href="/wms/notifications" class="dropdown-item text-center py-2 text-primary fw-bold small bg-light" style="border-bottom-left-radius: 0.5rem; border-bottom-right-radius: 0.5rem;">
                                  Lihat Semua Notifikasi <i class="bi bi-arrow-right ms-1"></i>
                              </a>
                          </div>
                      </div>
                      
                      <!-- Role Switcher -->
                      <div class="dropdown">
                          <button class="btn rounded-pill px-3 fw-semibold dropdown-toggle text-dark border bg-light shadow-sm" type="button" data-bs-toggle="dropdown" style="font-size: 0.85rem;">
                              <i class="bi bi-shield-check text-primary me-1"></i> Switch Role
                          </button>
                          <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                              <li><h6 class="dropdown-header">Login Sebagai:</h6></li>
                              <li><a class="dropdown-item" href="/wms/dashboard/admin"><i class="bi bi-person-badge me-2 text-primary"></i>Admin Gudang (WMS)</a></li>
                              <li><a class="dropdown-item" href="/sales/dashboard"><i class="bi bi-briefcase me-2 text-success"></i>Sales Portal</a></li>
                              <li><a class="dropdown-item" href="/wms/dashboard/produksi"><i class="bi bi-tools me-2 text-warning"></i>Tim Produksi</a></li>
                              <li><hr class="dropdown-divider"></li>
                              <li><a class="dropdown-item" href="/wms/outbound/delivery"><i class="bi bi-truck me-2 text-info"></i>Tim Logistik</a></li>
                          </ul>
                      </div>

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
                              <li><a class="dropdown-item py-2 mt-2" href="/wms/profile"><i class="bi bi-person me-2 text-secondary"></i>Profil Saya</a></li>
                              
                              <li><hr class="dropdown-divider"></li>
                              <li><a class="dropdown-item py-2 text-danger fw-bold" href="#"><i class="bi bi-box-arrow-right me-2"></i>Keluar</a></li>
                          </ul>
                      </div>
                  </div>
              </div>
          </nav>