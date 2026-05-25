<x-app-layout>
    <x-slot name="header">
        <h2 class="font-bold text-2xl text-slate-800 leading-tight tracking-tight">
            {{ __('Tracer Study - Internal MIF') }}
        </h2>
    </x-slot>

    <div class="py-10 bg-slate-50 min-h-screen" x-data="{ 
        selectedIds: [], 
        selectAll: false, 
        editModalOpen: false, 
        editData: { id: null, predicted_profile: '', nama: '' },
        bulkEditModalOpen: false,
        bulkEditData: { predicted_profile: 'Programmer' },
        toggleAll() { 
            this.selectAll = !this.selectAll; 
            if(this.selectAll) { 
                this.selectedIds = Array.from(document.querySelectorAll('.row-checkbox')).map(cb => cb.value); 
            } else { 
                this.selectedIds = []; 
            } 
        },
        openEdit(id, profile, nama) {
            this.editData.id = id;
            this.editData.predicted_profile = profile;
            this.editData.nama = nama;
            this.editModalOpen = true;
        }
    }">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8">
            
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-white rounded-2xl p-6 shadow-xl shadow-slate-200/50 border border-slate-100 flex items-center space-x-4">
                    <div class="p-3 rounded-xl bg-blue-50 text-blue-600">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-slate-500">Total Data Internal</p>
                        <p class="text-3xl font-bold text-slate-800">{{ $stats['total_internal'] }}</p>
                    </div>
                </div>
                <div class="bg-white rounded-2xl p-6 shadow-xl shadow-slate-200/50 border border-slate-100 flex items-center space-x-4">
                    <div class="p-3 rounded-xl bg-emerald-50 text-emerald-600">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-slate-500">Auto-Classified</p>
                        <p class="text-3xl font-bold text-slate-800">{{ $stats['auto_classified'] }}</p>
                    </div>
                </div>
                <div class="bg-white rounded-2xl p-6 shadow-xl shadow-slate-200/50 border border-slate-100 flex items-center space-x-4">
                    <div class="p-3 rounded-xl bg-amber-50 text-amber-600">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-slate-500">Needs Review</p>
                        <p class="text-3xl font-bold text-slate-800">{{ $stats['needs_review'] }}</p>
                    </div>
                </div>
            </div>

            <!-- Visualisasi Data -->
            @if(isset($charts) && collect($charts['profile'])->count() > 0)
            <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <!-- Baris 1: 3 Grafik -->
                <div class="bg-white rounded-2xl p-6 shadow-xl shadow-slate-200/50 border border-slate-100">
                    <h3 class="text-sm font-bold text-slate-800 mb-4 text-center">Sebaran Profil Lulusan</h3>
                    <div class="relative h-64"><canvas id="chartProfile"></canvas></div>
                </div>
                <div class="bg-white rounded-2xl p-6 shadow-xl shadow-slate-200/50 border border-slate-100">
                    <h3 class="text-sm font-bold text-slate-800 mb-4 text-center">Metode Klasifikasi AI</h3>
                    <div class="relative h-64"><canvas id="chartMethod"></canvas></div>
                </div>
                <div class="bg-white rounded-2xl p-6 shadow-xl shadow-slate-200/50 border border-slate-100">
                    <h3 class="text-sm font-bold text-slate-800 mb-4 text-center">Aktivitas Pencarian Kerja (Rata-rata)</h3>
                    <div class="relative h-64"><canvas id="chartFunnel"></canvas></div>
                </div>
                
                <!-- Baris 2: 2 Grafik (Masa tunggu lebih lebar) -->
                <div class="bg-white rounded-2xl p-6 shadow-xl shadow-slate-200/50 border border-slate-100 lg:col-span-2">
                    <h3 class="text-sm font-bold text-slate-800 mb-4 text-center">Masa Tunggu (Bulan)</h3>
                    <div class="relative h-64"><canvas id="chartWaktuTunggu"></canvas></div>
                </div>
                <div class="bg-white rounded-2xl p-6 shadow-xl shadow-slate-200/50 border border-slate-100">
                    <h3 class="text-sm font-bold text-slate-800 mb-4 text-center">Top 5 Lokasi Perusahaan</h3>
                    <div class="relative h-64"><canvas id="chartLokasi"></canvas></div>
                </div>
                
                <!-- Baris 3: 1 Grafik Peta (Full width) -->
                <div class="bg-white rounded-2xl p-6 shadow-xl shadow-slate-200/50 border border-slate-100 lg:col-span-3">
                    <h3 class="text-sm font-bold text-slate-800 mb-4 text-center">Peta Persebaran</h3>
                    <div id="mapLokasi" class="relative rounded-xl border border-slate-200" style="height: 384px; z-index: 10;"></div>
                </div>
            </div>
            @endif

            <!-- Upload Section -->
            <div class="bg-white rounded-2xl p-8 shadow-xl shadow-slate-200/50 border border-slate-100">
                <div class="mb-6">
                    <h3 class="text-xl font-bold text-slate-800 tracking-tight">Upload Data Internal MIF</h3>
                    <p class="text-sm text-slate-500 mt-1">Unggah file Excel/CSV untuk memproses klasifikasi otomatis menggunakan Machine Learning.</p>
                </div>

                @if(session('success')) 
                <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-xl mb-6 flex items-center space-x-3">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                    <span>{{ session('success') }}</span>
                </div> 
                @endif
                @if(session('error'))   
                <div class="bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-xl mb-6 flex items-center space-x-3">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path></svg>
                    <span>{{ session('error') }}</span>
                </div> 
                @endif
                
                <form action="{{ route('upload.process') }}" method="POST" enctype="multipart/form-data" class="flex flex-col md:flex-row gap-6 items-end">
                    @csrf
                    <input type="hidden" name="source_type" value="internal_mif">
                    
                    <div class="w-full md:w-2/3 relative">
                        <label class="block text-sm font-semibold text-slate-700 mb-2">Pilih File (Excel/CSV)</label>
                        <div class="relative flex items-center border-2 border-dashed border-slate-300 rounded-xl px-4 py-3 bg-slate-50 hover:bg-slate-100 transition-colors cursor-pointer group">
                            <svg class="w-6 h-6 text-slate-400 group-hover:text-indigo-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path></svg>
                            <input type="file" name="file" accept=".xlsx,.xls,.csv" class="w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-600 hover:file:bg-indigo-100 focus:outline-none cursor-pointer absolute inset-0 opacity-0" required onchange="document.getElementById('file-name').textContent = this.files[0] ? this.files[0].name : 'Belum ada file dipilih'">
                            <span id="file-name" class="text-sm text-slate-500 truncate pointer-events-none">Klik untuk memilih file...</span>
                        </div>
                    </div>
                    
                    <div class="w-full md:w-1/3">
                        <button type="submit" class="w-full inline-flex justify-center items-center py-3.5 px-6 border border-transparent shadow-sm text-sm font-semibold rounded-xl text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-all">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                            Proses Data Internal
                        </button>
                    </div>
                </form>
            </div>

            <!-- ═══════════════════════════════════════════════════════ -->
            <!-- RE-TRAINING PANEL                                       -->
            <!-- ═══════════════════════════════════════════════════════ -->
            <div class="bg-white rounded-2xl p-8 shadow-xl shadow-slate-200/50 border border-slate-100"
                 x-data="retrainPanel()" x-init="init()">
                
                <div class="flex items-start justify-between mb-6">
                    <div>
                        <h3 class="text-xl font-bold text-slate-800 tracking-tight flex items-center gap-2">
                            <svg class="w-6 h-6 text-violet-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                            Re-Training Model AI
                        </h3>
                        <p class="text-sm text-slate-500 mt-1">Latih ulang model menggunakan data manual yang sudah diverifikasi oleh admin.</p>
                    </div>
                    <!-- Model Info Badge -->
                    <div class="text-right flex-shrink-0 ml-4">
                        @if($ml_metrics)
                            @php
                                $currentF1 = round(($ml_metrics['hold_out_test']['weighted avg']['f1-score'] ?? 0) * 100, 1);
                                $currentAcc = round(($ml_metrics['hold_out_test']['accuracy'] ?? 0) * 100, 1);
                                $retrainedAt = $ml_metrics['retrained_at'] ?? null;
                            @endphp
                            <div class="bg-violet-50 border border-violet-200 rounded-xl px-4 py-3 text-right">
                                <p class="text-xs font-semibold text-violet-500 uppercase tracking-wide">Model Aktif</p>
                                <p class="text-2xl font-bold text-violet-700">{{ $currentF1 }}% <span class="text-sm font-medium text-violet-400">F1</span></p>
                                <p class="text-xs text-slate-400 mt-0.5">Akurasi: {{ $currentAcc }}%</p>
                                @if($retrainedAt)
                                    <p class="text-xs text-slate-400">Retrained: {{ \Carbon\Carbon::parse($retrainedAt)->diffForHumans() }}</p>
                                @endif
                            </div>
                        @else
                            <div class="bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-right">
                                <p class="text-xs text-slate-400">Metrics tidak tersedia</p>
                            </div>
                        @endif
                    </div>
                </div>

                <!-- Corpus Info -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <div class="flex items-center gap-3 bg-slate-50 rounded-xl p-4 border border-slate-100">
                        <div class="p-2 rounded-lg bg-blue-100 text-blue-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500 font-medium">Corpus Asli</p>
                            <p class="text-base font-bold text-slate-800">~248 sampel <span class="text-xs font-normal text-slate-400">(training_corpus_3.csv)</span></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3 bg-slate-50 rounded-xl p-4 border border-slate-100">
                        <div class="p-2 rounded-lg {{ $manual_override_count > 0 ? 'bg-emerald-100 text-emerald-600' : 'bg-slate-100 text-slate-400' }}">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                            </svg>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500 font-medium">Data Manual Override (baru)</p>
                            <p class="text-base font-bold {{ $manual_override_count > 0 ? 'text-emerald-700' : 'text-slate-400' }}">
                                {{ $manual_override_count }} sampel
                                @if($manual_override_count > 0)
                                    <span class="text-xs font-normal text-emerald-500">siap ditambahkan</span>
                                @else
                                    <span class="text-xs font-normal text-slate-400">belum ada koreksi</span>
                                @endif
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Rollback Safety Info -->
                <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-6">
                    <div class="flex items-start gap-3">
                        <svg class="w-5 h-5 text-amber-600 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                        </svg>
                        <div>
                            <p class="text-sm font-semibold text-amber-800">Mekanisme Keamanan Aktif</p>
                            <p class="text-xs text-amber-700 mt-0.5">Model lama di-backup otomatis sebelum training. Jika model baru lebih buruk atau training gagal, sistem <strong>otomatis rollback</strong> ke model lama. Threshold minimal peningkatan: <strong>+1% weighted F1-score</strong>.</p>
                        </div>
                    </div>
                </div>

                <!-- Trigger Button & Status -->
                <div class="flex flex-col md:flex-row gap-4 items-start">
                    <button id="btn-retrain"
                        @click="startRetrain()"
                        :disabled="isRunning"
                        class="inline-flex items-center gap-2 px-6 py-3 rounded-xl text-sm font-semibold text-white transition-all shadow-sm
                               bg-violet-600 hover:bg-violet-700 focus:ring-2 focus:ring-violet-500 focus:ring-offset-2
                               disabled:opacity-50 disabled:cursor-not-allowed">
                        <svg x-show="!isRunning" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        <svg x-show="isRunning" class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        <span x-text="isRunning ? 'Training berjalan...' : 'Re-train Model'"></span>
                    </button>
                    <p class="text-xs text-slate-400 mt-1 md:mt-3">Proses berlangsung di background (~30-90 detik). Halaman tidak perlu di-reload.</p>
                </div>

                <!-- Progress Section -->
                <div x-show="showProgress" style="display:none" class="mt-6 space-y-4">
                    
                    <!-- Stage Stepper -->
                    <div class="flex items-center gap-1 overflow-x-auto pb-1">
                        <template x-for="(step, idx) in stages" :key="idx">
                            <div class="flex items-center gap-1 flex-shrink-0">
                                <div class="flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold transition-all"
                                     :class="{
                                        'bg-violet-600 text-white': currentStageIdx === idx && !isDone,
                                        'bg-emerald-100 text-emerald-700': currentStageIdx > idx,
                                        'bg-slate-100 text-slate-400': currentStageIdx < idx,
                                        'bg-rose-100 text-rose-700': isDone && result === 'failed' && currentStageIdx === idx
                                     }">
                                    <svg x-show="currentStageIdx > idx" class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                    </svg>
                                    <svg x-show="currentStageIdx === idx && !isDone" class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                    </svg>
                                    <span x-text="step.label"></span>
                                </div>
                                <svg x-show="idx < stages.length - 1" class="w-4 h-4 text-slate-300 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/>
                                </svg>
                            </div>
                        </template>
                    </div>

                    <!-- Status Message -->
                    <p class="text-sm text-slate-600" x-text="statusMessage"></p>

                    <!-- Result Card (shown when done) -->
                    <div x-show="isDone" style="display:none">

                        <!-- PROMOTED -->
                        <div x-show="result === 'promoted'" class="bg-emerald-50 border border-emerald-200 rounded-xl p-5">
                            <div class="flex items-center gap-3 mb-3">
                                <div class="p-2 rounded-full bg-emerald-100">
                                    <svg class="w-6 h-6 text-emerald-600" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                    </svg>
                                </div>
                                <div>
                                    <p class="font-bold text-emerald-800">Model Berhasil Diperbarui!</p>
                                    <p class="text-sm text-emerald-700" x-text="statusMessage"></p>
                                </div>
                            </div>
                            <div class="grid grid-cols-3 gap-3 mt-3">
                                <div class="bg-white rounded-lg p-3 text-center border border-emerald-200">
                                    <p class="text-xs text-slate-500 font-medium">F1 Lama</p>
                                    <p class="text-xl font-bold text-slate-700" x-text="oldF1 + '%'"></p>
                                </div>
                                <div class="bg-white rounded-lg p-3 text-center border border-emerald-200">
                                    <p class="text-xs text-slate-500 font-medium">F1 Baru</p>
                                    <p class="text-xl font-bold text-emerald-700" x-text="newF1 + '%'"></p>
                                </div>
                                <div class="bg-emerald-600 rounded-lg p-3 text-center">
                                    <p class="text-xs text-emerald-200 font-medium">Peningkatan</p>
                                    <p class="text-xl font-bold text-white" x-text="'+' + deltaF1 + '%'"></p>
                                </div>
                            </div>
                        </div>

                        <!-- ROLLED BACK -->
                        <div x-show="result === 'rolled_back' || result === 'failed'" class="bg-amber-50 border border-amber-200 rounded-xl p-5">
                            <div class="flex items-center gap-3 mb-3">
                                <div class="p-2 rounded-full bg-amber-100">
                                    <svg class="w-6 h-6 text-amber-600" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm.75-13a.75.75 0 00-1.5 0v5c0 .414.336.75.75.75h4a.75.75 0 000-1.5h-3.25V5z" clip-rule="evenodd"/>
                                    </svg>
                                </div>
                                <div>
                                    <p class="font-bold text-amber-800" x-text="result === 'failed' ? '⚠️ Training Gagal — Model Lama Dipulihkan' : '⚠️ Model Lama Dipertahankan'"></p>
                                    <p class="text-sm text-amber-700" x-text="rollbackReason"></p>
                                </div>
                            </div>
                            <div x-show="oldF1 > 0 || newF1 > 0" class="grid grid-cols-2 gap-3 mt-3">
                                <div class="bg-white rounded-lg p-3 text-center border border-amber-200">
                                    <p class="text-xs text-slate-500 font-medium">F1 Model Aktif (lama)</p>
                                    <p class="text-xl font-bold text-slate-700" x-text="oldF1 + '%'"></p>
                                </div>
                                <div class="bg-white rounded-lg p-3 text-center border border-amber-200">
                                    <p class="text-xs text-slate-500 font-medium">F1 Candidate (baru)</p>
                                    <p class="text-xl font-bold text-amber-700" x-text="newF1 + '%'"></p>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <!-- Data Table -->
            <div class="bg-white rounded-2xl shadow-xl shadow-slate-200/50 border border-slate-100 overflow-hidden">
                <div class="px-6 py-5 border-b border-slate-100 bg-slate-50/50 flex justify-between items-center">
                    <h3 class="text-lg font-bold text-slate-800">Tabel Hasil Klasifikasi</h3>
                    <div class="flex items-center space-x-3">
                        <a href="{{ route('internal.download_pdf', request()->query()) }}" class="inline-flex items-center px-4 py-2 bg-emerald-600 text-white text-sm font-semibold rounded-lg hover:bg-emerald-700 transition-colors shadow-sm">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                            Download PDF
                        </a>
                        <div x-show="selectedIds.length > 0" style="display: none;" class="flex items-center space-x-2">
                            <span class="text-sm text-slate-600 font-medium mr-2"><span x-text="selectedIds.length"></span> dipilih</span>
                            
                            <button type="button" @click="bulkEditModalOpen = true" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                            Edit
                        </button>

                        <form action="{{ route('internal.bulk_destroy') }}" method="POST" onsubmit="return confirm('Yakin ingin menghapus data yang dipilih?');" class="inline-block">
                            @csrf
                            <template x-for="id in selectedIds" :key="id">
                                <input type="hidden" name="ids[]" :value="id">
                            </template>
                            <button type="submit" class="inline-flex items-center px-4 py-2 bg-rose-600 text-white text-sm font-semibold rounded-lg hover:bg-rose-700 transition-colors">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                Hapus
                            </button>
                        </form>
                    </div>
                </div>
            </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th scope="col" class="px-6 py-4 text-left">
                                    <input type="checkbox" @click="toggleAll()" :checked="selectAll" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                                </th>
                                <th class="px-6 py-4 text-left text-xs font-bold text-slate-500 uppercase tracking-wider">
                                    <a href="{{ request()->fullUrlWithQuery(['sort' => 'nim', 'direction' => request('direction') == 'asc' ? 'desc' : 'asc']) }}" class="flex items-center space-x-1 hover:text-slate-800">
                                        <span>NIM / Nama</span>
                                        @if(request('sort') == 'nim') <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ request('direction') == 'asc' ? 'M5 15l7-7 7 7' : 'M19 9l-7 7-7-7' }}"></path></svg> @endif
                                    </a>
                                </th>
                                <th class="px-6 py-4 text-left text-xs font-bold text-slate-500 uppercase tracking-wider">
                                    <a href="{{ request()->fullUrlWithQuery(['sort' => 'job_text_raw', 'direction' => request('direction') == 'asc' ? 'desc' : 'asc']) }}" class="flex items-center space-x-1 hover:text-slate-800">
                                        <span>Pekerjaan</span>
                                        @if(request('sort') == 'job_text_raw') <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ request('direction') == 'asc' ? 'M5 15l7-7 7 7' : 'M19 9l-7 7-7-7' }}"></path></svg> @endif
                                    </a>
                                </th>
                                <th class="px-6 py-4 text-left text-xs font-bold text-slate-500 uppercase tracking-wider">
                                    <a href="{{ request()->fullUrlWithQuery(['sort' => 'predicted_profile', 'direction' => request('direction') == 'asc' ? 'desc' : 'asc']) }}" class="flex items-center space-x-1 hover:text-slate-800">
                                        <span>Profil Prediksi</span>
                                        @if(request('sort') == 'predicted_profile') <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ request('direction') == 'asc' ? 'M5 15l7-7 7 7' : 'M19 9l-7 7-7-7' }}"></path></svg> @endif
                                    </a>
                                </th>
                                <th class="px-6 py-4 text-left text-xs font-bold text-slate-500 uppercase tracking-wider">
                                    <a href="{{ request()->fullUrlWithQuery(['sort' => 'status', 'direction' => request('direction') == 'asc' ? 'desc' : 'asc']) }}" class="flex items-center space-x-1 hover:text-slate-800">
                                        <span>Status</span>
                                        @if(request('sort') == 'status') <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ request('direction') == 'asc' ? 'M5 15l7-7 7 7' : 'M19 9l-7 7-7-7' }}"></path></svg> @endif
                                    </a>
                                </th>
                                <th class="px-6 py-4 text-right text-xs font-bold text-slate-500 uppercase tracking-wider">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-slate-100">
                            @forelse($internal_data as $row)
                            <tr class="hover:bg-slate-50 transition-colors {{ $row->status === 'needs_review' ? 'bg-amber-50/30' : '' }}">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <input type="checkbox" value="{{ $row->id }}" x-model="selectedIds" class="row-checkbox rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-semibold text-slate-800">{{ $row->nim }}</div>
                                    <div class="text-sm text-slate-500">{{ $row->nama }}</div>
                                </td>
                                <td class="px-6 py-4 text-sm text-slate-600 max-w-xs">
                                    <div class="line-clamp-2" title="{{ $row->job_text_raw }}">
                                        {{ $row->job_text_raw }}
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @php
                                        $colorClass = match($row->predicted_profile) {
                                            'Programmer' => 'bg-emerald-100 text-emerald-700 border-emerald-200',
                                            'Tidak Diketahui' => 'bg-slate-100 text-slate-500 border-slate-200',
                                            'Non-IT' => 'bg-slate-100 text-slate-700 border-slate-200',
                                            default => 'bg-blue-100 text-blue-700 border-blue-200',
                                        };
                                    @endphp
                                    <div class="flex items-center space-x-2">
                                        <span class="px-3 py-1 inline-flex text-xs font-bold rounded-full border {{ $colorClass }}">
                                            {{ $row->predicted_profile ?? '-' }}
                                        </span>
                                        @if($row->confidence_score)
                                        <span class="text-xs text-slate-400 font-medium">{{ number_format($row->confidence_score*100, 1) }}%</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if($row->status === 'auto_classified')
                                    <span class="inline-flex items-center space-x-1 text-emerald-600">
                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                                        <span class="text-xs font-bold">Auto</span>
                                    </span>
                                    @elseif($row->status === 'manual_override')
                                    <span class="inline-flex items-center space-x-1 text-blue-600">
                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"></path></svg>
                                        <span class="text-xs font-bold">Manual</span>
                                    </span>
                                    @else
                                    <span class="inline-flex items-center space-x-1 text-amber-600">
                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>
                                        <span class="text-xs font-bold">Review</span>
                                    </span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-2">
                                    <button @click="openEdit({{ $row->id }}, '{{ $row->predicted_profile }}', '{{ addslashes($row->nama) }}')" class="text-blue-600 hover:text-blue-900 transition-colors">Edit</button>
                                    <form action="{{ route('internal.destroy', $row->id) }}" method="POST" class="inline-block" onsubmit="return confirm('Yakin ingin menghapus data ini?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-rose-600 hover:text-rose-900 transition-colors">Hapus</button>
                                    </form>
                                    <div x-data="{ openDetail: false }" class="inline-block text-left ml-2">
                                        <button @click="openDetail = true" class="text-purple-600 hover:text-purple-900 transition-colors">Detail</button>
                                        
                                        <!-- Modal Detail -->
                                        <div x-show="openDetail" style="display: none;" class="fixed inset-0 z-[70] overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
                                            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                                                <div x-show="openDetail" @click="openDetail = false" x-transition.opacity class="fixed inset-0 bg-slate-900 bg-opacity-50 transition-opacity" aria-hidden="true"></div>
                                                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
                                                <div x-show="openDetail" x-transition class="inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-3xl w-full">
                                                    <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4 border-b border-slate-100">
                                                        <div class="flex justify-between items-center mb-4">
                                                            <h3 class="text-lg leading-6 font-bold text-slate-800" id="modal-title">
                                                                Detail Data Internal - {{ $row->nama }}
                                                            </h3>
                                                            <button @click="openDetail = false" class="text-slate-400 hover:text-slate-600 focus:outline-none">
                                                                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                                            </button>
                                                        </div>
                                                        <div class="max-h-[60vh] overflow-y-auto">
                                                            <table class="min-w-full divide-y divide-slate-200">
                                                                <tbody class="bg-white divide-y divide-slate-100">
                                                                    @php
                                                                        $raw = \App\Models\InternalRawData::where('nim', $row->nim)->first();
                                                                    @endphp
                                                                    @if($raw)
                                                                        @foreach($raw->getAttributes() as $key => $val)
                                                                            @if(!in_array($key, ['id', 'created_at', 'updated_at']) && $val !== null && $val !== '')
                                                                            <tr>
                                                                                <td class="px-4 py-3 text-sm font-medium text-slate-700 w-1/3 capitalize whitespace-normal">{{ str_replace('_', ' ', $key) }}</td>
                                                                                <td class="px-4 py-3 text-sm text-slate-600 w-2/3 whitespace-normal">{{ $val }}</td>
                                                                            </tr>
                                                                            @endif
                                                                        @endforeach
                                                                    @else
                                                                        <tr>
                                                                            <td colspan="2" class="px-4 py-3 text-sm text-slate-500 text-center">Data detail tidak tersedia.</td>
                                                                        </tr>
                                                                    @endif
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    </div>
                                                    <div class="bg-slate-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse border-t border-slate-100">
                                                        <button type="button" @click="openDetail = false" class="mt-3 w-full inline-flex justify-center rounded-xl border border-slate-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-slate-700 hover:bg-slate-50 focus:outline-none sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                                                            Tutup
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center">
                                    <svg class="mx-auto h-12 w-12 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path vector-effect="non-scaling-stroke" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z" /></svg>
                                    <h3 class="mt-2 text-sm font-medium text-slate-900">Belum ada data</h3>
                                    <p class="mt-1 text-sm text-slate-500">Silakan upload data tracer study internal.</p>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                
                @if($internal_data->hasPages())
                <div class="px-6 py-4 border-t border-slate-100 bg-slate-50">
                    {{ $internal_data->links() }}
                </div>
                @endif
            </div>
            
            <!-- Edit Modal -->
            <div x-show="editModalOpen" style="display: none;" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
                <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                    <div x-show="editModalOpen" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-slate-500 bg-opacity-75 transition-opacity" @click="editModalOpen = false"></div>
                    <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
                    <div x-show="editModalOpen" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" class="inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                        <form :action="'/internal/' + editData.id" method="POST">
                            @csrf
                            @method('PUT')
                            <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                                <div class="sm:flex sm:items-start">
                                    <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-blue-100 sm:mx-0 sm:h-10 sm:w-10">
                                        <svg class="h-6 w-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                                    </div>
                                    <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
                                        <h3 class="text-lg leading-6 font-bold text-slate-900" id="modal-title">
                                            Edit Profil Prediksi
                                        </h3>
                                        <div class="mt-2">
                                            <p class="text-sm text-slate-500 mb-4">Mengubah profil untuk: <strong x-text="editData.nama" class="text-slate-800"></strong></p>
                                            
                                            <label class="block text-sm font-semibold text-slate-700 mb-1">Profil Prediksi</label>
                                            <select name="predicted_profile" x-model="editData.predicted_profile" class="w-full border-slate-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                                <option value="Programmer">Programmer</option>
                                                <option value="Data Analyst">Data Analyst</option>
                                                <option value="Wirausaha Informatika">Wirausaha Informatika</option>
                                                <option value="Non-IT">Non-IT</option>
                                                <option value="Tidak Diketahui">Tidak Diketahui</option>
                                            </select>
                                            <p class="mt-2 text-xs text-slate-400">Status akan otomatis berubah menjadi <span class="font-semibold text-blue-600">Manual</span> setelah diperbarui.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-slate-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse rounded-b-2xl">
                                <button type="submit" class="w-full inline-flex justify-center rounded-lg border border-transparent shadow-sm px-4 py-2 bg-blue-600 text-base font-semibold text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 sm:ml-3 sm:w-auto sm:text-sm">
                                    Simpan Perubahan
                                </button>
                                <button type="button" @click="editModalOpen = false" class="mt-3 w-full inline-flex justify-center rounded-lg border border-slate-300 shadow-sm px-4 py-2 bg-white text-base font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-slate-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                                    Batal
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Bulk Edit Modal -->
            <div x-show="bulkEditModalOpen" style="display: none;" class="fixed inset-0 z-[60] overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
                <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                    <div x-show="bulkEditModalOpen" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-slate-500 bg-opacity-75 transition-opacity" @click="bulkEditModalOpen = false"></div>
                    <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
                    <div x-show="bulkEditModalOpen" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" class="inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                        <form action="{{ route('internal.bulk_update') }}" method="POST">
                            @csrf
                            <template x-for="id in selectedIds" :key="id">
                                <input type="hidden" name="ids[]" :value="id">
                            </template>
                            <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                                <div class="sm:flex sm:items-start">
                                    <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-blue-100 sm:mx-0 sm:h-10 sm:w-10">
                                        <svg class="h-6 w-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                                    </div>
                                    <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
                                        <h3 class="text-lg leading-6 font-bold text-slate-900" id="modal-title">
                                            Edit Massal (<span x-text="selectedIds.length"></span> baris)
                                        </h3>
                                        <div class="mt-2">
                                            <p class="text-sm text-slate-500 mb-4">Set profil prediksi baru untuk semua data yang dipilih.</p>
                                            
                                            <label class="block text-sm font-semibold text-slate-700 mb-1">Profil Prediksi</label>
                                            <select name="predicted_profile" x-model="bulkEditData.predicted_profile" class="w-full border-slate-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                                <option value="Programmer">Programmer</option>
                                                <option value="Data Analyst">Data Analyst</option>
                                                <option value="Wirausaha Informatika">Wirausaha Informatika</option>
                                                <option value="Non-IT">Non-IT</option>
                                            </select>
                                            <p class="mt-2 text-xs text-slate-400">Status semua baris terpilih akan otomatis berubah menjadi <span class="font-semibold text-blue-600">Manual</span>.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-slate-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse rounded-b-2xl">
                                <button type="submit" class="w-full inline-flex justify-center rounded-lg border border-transparent shadow-sm px-4 py-2 bg-blue-600 text-base font-semibold text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 sm:ml-3 sm:w-auto sm:text-sm">
                                    Simpan Perubahan
                                </button>
                                <button type="button" @click="bulkEditModalOpen = false" class="mt-3 w-full inline-flex justify-center rounded-lg border border-slate-300 shadow-sm px-4 py-2 bg-white text-base font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-slate-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                                    Batal
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        </div>
    </div>
    @if(isset($charts) && collect($charts['profile'])->count() > 0)
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const chartData = @json($charts);
            
            const pieOptions = { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } } };
            const barOptions = { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } };

            new Chart(document.getElementById('chartProfile'), {
                type: 'doughnut',
                data: {
                    labels: Object.keys(chartData.profile),
                    datasets: [{ data: Object.values(chartData.profile), backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#64748b'] }]
                },
                options: pieOptions
            });

            new Chart(document.getElementById('chartMethod'), {
                type: 'pie',
                data: {
                    labels: Object.keys(chartData.method),
                    datasets: [{ data: Object.values(chartData.method), backgroundColor: ['#10b981', '#3b82f6', '#f59e0b'] }]
                },
                options: pieOptions
            });

            new Chart(document.getElementById('chartWaktuTunggu'), {
                type: 'bar',
                data: {
                    labels: Object.keys(chartData.waktu_tunggu),
                    datasets: [{ label: 'Rata-rata Bulan', data: Object.values(chartData.waktu_tunggu), backgroundColor: '#3b82f6', borderRadius: 4 }]
                },
                options: barOptions
            });

            new Chart(document.getElementById('chartFunnel'), {
                type: 'bar',
                data: {
                    labels: Object.keys(chartData.funnel),
                    datasets: [{ data: Object.values(chartData.funnel), backgroundColor: ['#8b5cf6', '#ec4899', '#10b981'], borderRadius: 4 }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }
                }
            });

            new Chart(document.getElementById('chartLokasi'), {
                type: 'bar',
                data: {
                    labels: Object.keys(chartData.lokasi),
                    datasets: [{ data: Object.values(chartData.lokasi), backgroundColor: '#14b8a6', borderRadius: 4 }]
                },
                options: barOptions
            });

            // Map Initialization
            if (chartData.map_lokasi && Object.keys(chartData.map_lokasi).length > 0) {
                const map = L.map('mapLokasi').setView([-2.5489, 118.0149], 4);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 18,
                    attribution: '© OpenStreetMap'
                }).addTo(map);

                const cityCoords = {
                    'jakarta': [-6.2088, 106.8456], 'surabaya': [-7.2504, 112.7688], 'bandung': [-6.9175, 107.6191],
                    'yogyakarta': [-7.7956, 110.3695], 'semarang': [-6.9667, 110.4167], 'malang': [-7.9839, 112.6214],
                    'denpasar': [-8.6705, 115.2128], 'medan': [3.5952, 98.6722], 'makassar': [-5.1477, 119.4327],
                    'palembang': [-2.9909, 104.7566], 'jember': [-8.1724, 113.6995], 'banyuwangi': [-8.2192, 114.3692],
                    'sidoarjo': [-7.4478, 112.7183], 'kediri': [-7.8228, 112.0119], 'bogor': [-6.5971, 106.7932],
                    'depok': [-6.4025, 106.7942], 'tangerang': [-6.1702, 106.6403], 'bekasi': [-6.2383, 106.9756],
                    'bali': [-8.4095, 115.1889], 'batam': [1.0828, 104.0305], 'pekanbaru': [0.5071, 101.4478],
                    'situbondo': [-7.7028, 113.9995], 'bondowoso': [-7.9254, 113.8214], 'probolinggo': [-7.7569, 113.2163]
                };

                for (const [city, count] of Object.entries(chartData.map_lokasi)) {
                    let c = city.toLowerCase().trim();
                    if(c.includes('jakarta')) c = 'jakarta'; 
                    else if(c.includes('surabaya')) c = 'surabaya';
                    else if(c.includes('bandung')) c = 'bandung';
                    else if(c.includes('malang')) c = 'malang';

                    if (cityCoords[c]) {
                        L.marker(cityCoords[c]).addTo(map)
                         .bindPopup(`<b>${city}</b><br>Jumlah: ${count} lulusan`);
                    }
                }
            }
        });
    </script>
    @endif

    {{-- Alpine.js: Re-training Panel Component --}}
    <script>
    function retrainPanel() {
        return {
            isRunning: false,
            showProgress: false,
            isDone: false,
            statusMessage: '',
            result: null,         // 'promoted' | 'rolled_back' | 'failed'
            rollbackReason: '',
            oldF1: 0,
            newF1: 0,
            deltaF1: 0,
            pollTimer: null,
            currentStageIdx: 0,

            stages: [
                { key: 'started',      label: 'Memulai'    },
                { key: 'backup',       label: 'Backup'     },
                { key: 'loading_data', label: 'Load Data'  },
                { key: 'preprocessing',label: 'Preprocess' },
                { key: 'training',     label: 'Training'   },
                { key: 'evaluating',   label: 'Evaluasi'   },
                { key: 'promoting',    label: 'Promote'    },
            ],

            stageOrder: {
                'started': 0, 'backup': 1, 'loading_data': 2,
                'preprocessing': 3, 'training': 4, 'evaluating': 5,
                'promoting': 6, 'promoted': 6, 'rolled_back': 6, 'failed': 6,
            },

            init() {
                // Cek apakah ada proses yang sedang berjalan saat halaman dibuka
                this.pollStatus(false);
            },

            async startRetrain() {
                if (!confirm('Mulai re-training model? Model lama akan di-backup otomatis sebelum proses dimulai.')) return;

                this.isRunning   = true;
                this.showProgress = true;
                this.isDone      = false;
                this.result      = null;
                this.statusMessage = 'Menghubungi server...';
                this.currentStageIdx = 0;

                try {
                    const res = await fetch('{{ route("retrain.trigger") }}', {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                        },
                    });

                    const data = await res.json();

                    if (!res.ok) {
                        this.isRunning     = false;
                        this.isDone        = true;
                        this.result        = 'failed';
                        this.rollbackReason = data.message || 'Gagal memulai re-training.';
                        return;
                    }

                    this.statusMessage = data.message || 'Training dimulai...';
                    this.startPolling();

                } catch (err) {
                    this.isRunning     = false;
                    this.isDone        = true;
                    this.result        = 'failed';
                    this.rollbackReason = 'Koneksi gagal: ' + err.message;
                }
            },

            startPolling() {
                if (this.pollTimer) clearInterval(this.pollTimer);
                this.pollTimer = setInterval(() => this.pollStatus(true), 3000);
            },

            async pollStatus(fromPolling = true) {
                try {
                    const res  = await fetch('{{ route("retrain.status") }}', {
                        headers: { 'Accept': 'application/json' }
                    });
                    const data = await res.json();
                    const stage = data.stage || 'idle';

                    // Update stepper
                    this.currentStageIdx = this.stageOrder[stage] ?? 0;
                    this.statusMessage   = data.message || '';

                    const terminalStages = ['promoted', 'rolled_back', 'failed'];

                    if (terminalStages.includes(stage)) {
                        // Stop polling
                        if (this.pollTimer) { clearInterval(this.pollTimer); this.pollTimer = null; }
                        this.isRunning    = false;
                        this.isDone       = true;
                        this.showProgress = true;
                        this.result       = stage;

                        // Parse F1 scores
                        this.oldF1   = data.old_f1   ? Math.round(data.old_f1   * 1000) / 10 : 0;
                        this.newF1   = data.new_f1   ? Math.round(data.new_f1   * 1000) / 10 : 0;
                        this.deltaF1 = data.delta    ? Math.round(data.delta    * 1000) / 10 : 0;
                        this.rollbackReason = data.reason || '';

                    } else if (stage !== 'idle' && stage !== 'unknown') {
                        // Running — show progress
                        if (!this.isRunning && fromPolling) {
                            this.isRunning    = true;
                            this.showProgress = true;
                            this.startPolling();
                        }
                    }
                } catch (err) {
                    // Silently ignore poll errors
                }
            },
        };
    }
    </script>
</x-app-layout>