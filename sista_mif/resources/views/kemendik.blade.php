<x-app-layout>
    <x-slot name="header">
        <h2 class="font-bold text-2xl text-slate-800 leading-tight tracking-tight">
            {{ __('Tracer Study - Kemendikti') }}
        </h2>
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    </x-slot>

    <div class="py-10 bg-slate-50 min-h-screen">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8">
            
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-1 gap-6">
                <div class="bg-white rounded-2xl p-6 shadow-xl shadow-purple-100/50 border border-purple-50 flex items-center space-x-4">
                    <div class="p-3 rounded-xl bg-purple-50 text-purple-600">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-slate-500">Total Data Kemendik</p>
                        <p class="text-3xl font-bold text-slate-800">{{ $stats['total_kemendik'] }}</p>
                    </div>
                </div>
            </div>

            <!-- Upload Section -->
            <div class="bg-white rounded-2xl p-8 shadow-xl shadow-purple-100/50 border border-purple-50">
                <div class="mb-6">
                    <h3 class="text-xl font-bold text-slate-800 tracking-tight">Upload Data Kemendiktisaintek</h3>
                    <p class="text-sm text-slate-500 mt-1">Unggah file Excel/CSV data Kemendik untuk agregasi dan mapping (tanpa proses NLP klasifikasi).</p>
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
                    <input type="hidden" name="source_type" value="kemendik">
                    
                    <div class="w-full md:w-2/3 relative">
                        <label class="block text-sm font-semibold text-slate-700 mb-2">Pilih File (Excel/CSV)</label>
                        <div class="relative flex items-center border-2 border-dashed border-purple-200 rounded-xl px-4 py-3 bg-purple-50/30 hover:bg-purple-50 transition-colors cursor-pointer group">
                            <svg class="w-6 h-6 text-purple-300 group-hover:text-purple-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path></svg>
                            <input type="file" name="file" accept=".xlsx,.xls,.csv" class="w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-purple-100 file:text-purple-700 hover:file:bg-purple-200 focus:outline-none cursor-pointer absolute inset-0 opacity-0" required onchange="document.getElementById('file-name-kemendik').textContent = this.files[0] ? this.files[0].name : 'Belum ada file dipilih'">
                            <span id="file-name-kemendik" class="text-sm text-slate-500 truncate pointer-events-none">Klik untuk memilih file...</span>
                        </div>
                    </div>
                    
                    <div class="w-full md:w-1/3">
                        <button type="submit" class="w-full inline-flex justify-center items-center py-3.5 px-6 border border-transparent shadow-sm text-sm font-semibold rounded-xl text-white bg-purple-600 hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 transition-all">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                            Proses Data Kemendik
                        </button>
                    </div>
                </form>
            </div>

            @if(isset($charts) && collect($charts['status'])->count() > 0)
            <!-- Charts Section -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Status Pekerjaan (Pie) -->
                <div class="bg-white rounded-2xl shadow-xl shadow-purple-100/50 border border-purple-50 p-6 flex flex-col">
                    <h3 class="text-lg font-bold text-slate-800 mb-4 border-b border-purple-50 pb-2">Status Pekerjaan</h3>
                    <div class="relative flex-1 min-h-[300px]">
                        <canvas id="chartStatus"></canvas>
                    </div>
                </div>

                <!-- Kesesuaian (Doughnut) -->
                <div class="bg-white rounded-2xl shadow-xl shadow-purple-100/50 border border-purple-50 p-6 flex flex-col">
                    <h3 class="text-lg font-bold text-slate-800 mb-4 border-b border-purple-50 pb-2">Kesesuaian Bidang Studi</h3>
                    <div class="relative flex-1 min-h-[300px]">
                        <canvas id="chartKesesuaian"></canvas>
                    </div>
                </div>

                <!-- Tingkat Tempat Kerja -->
                <div class="bg-white rounded-2xl shadow-xl shadow-purple-100/50 border border-purple-50 p-6 flex flex-col">
                    <h3 class="text-lg font-bold text-slate-800 mb-4 border-b border-purple-50 pb-2">Tingkat Instansi Tempat Kerja</h3>
                    <div class="relative flex-1 min-h-[300px]">
                        <canvas id="chartLevel"></canvas>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Jenis Instansi -->
                <div class="bg-white rounded-2xl shadow-xl shadow-purple-100/50 border border-purple-50 p-6 flex flex-col">
                    <h3 class="text-lg font-bold text-slate-800 mb-4 border-b border-purple-50 pb-2">Jenis Perusahaan / Instansi</h3>
                    <div class="relative flex-1 min-h-[300px]">
                        <canvas id="chartInstansi"></canvas>
                    </div>
                </div>

                <!-- Rata-rata Pendapatan -->
                <div class="bg-white rounded-2xl shadow-xl shadow-purple-100/50 border border-purple-50 p-6 flex flex-col">
                    <h3 class="text-lg font-bold text-slate-800 mb-4 border-b border-purple-50 pb-2">Rata-rata Pendapatan</h3>
                    <div class="relative flex-1 min-h-[300px]">
                        <canvas id="chartPendapatan"></canvas>
                    </div>
                </div>
            </div>

            <!-- Map Section -->
            <div class="bg-white rounded-2xl shadow-xl shadow-purple-100/50 border border-purple-50 p-6">
                <h3 class="text-lg font-bold text-slate-800 mb-4 border-b border-purple-50 pb-2 flex items-center">
                    <svg class="w-5 h-5 text-purple-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    Peta Persebaran Alumni (Kemendikti)
                </h3>
                <div id="mapLokasi" class="w-full h-[500px] rounded-xl border border-slate-200 z-10"></div>
            </div>
            @endif

            <!-- Data Table -->
            <div class="bg-white rounded-2xl shadow-xl shadow-purple-100/50 border border-purple-50 overflow-hidden">
                <div class="px-6 py-5 border-b border-purple-50 bg-purple-50/20 flex justify-between items-center">
                    <h3 class="text-lg font-bold text-slate-800">Tabel Data Kemendiktisaintek</h3>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-100">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-6 py-4 text-left text-xs font-bold text-slate-500 uppercase tracking-wider">NIM / Nama</th>
                                <th class="px-6 py-4 text-left text-xs font-bold text-slate-500 uppercase tracking-wider">Lulus</th>
                                <th class="px-6 py-4 text-left text-xs font-bold text-slate-500 uppercase tracking-wider">Provinsi</th>
                                <th class="px-6 py-4 text-left text-xs font-bold text-slate-500 uppercase tracking-wider">Pekerjaan</th>
                                <th class="px-6 py-4 text-left text-xs font-bold text-slate-500 uppercase tracking-wider">Instansi</th>
                                <th class="px-6 py-4 text-right text-xs font-bold text-slate-500 uppercase tracking-wider">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-slate-100">
                            @forelse($kemendik_data as $row)
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-semibold text-slate-800">{{ $row->nimhsmsmh }}</div>
                                    <div class="text-sm text-slate-500">{{ $row->nmmhsmsmh }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-800">
                                    {{ $row->tahun_lulus ?? '-' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-800">
                                    @php
                                        $provinsiVal = $row->raw_data['F5a1'] ?? '-';
                                        $provinsiMapped = $keterangan['F5a1 (Dimana lokasi provinsi tempat Anda bekerja?)'][$provinsiVal] ?? $provinsiVal;
                                    @endphp
                                    {{ $provinsiMapped }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-slate-700">{{ $row->f5c_jabatan_text ?? '-' }}</div>
                                    <div class="text-xs text-slate-500">{{ $row->raw_data['F5b'] ?? '-' }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-3 py-1 inline-flex text-xs font-semibold rounded-full bg-slate-100 text-slate-700 border border-slate-200">
                                        {{ $row->f1101_jenis_instansi_text ?? '-' }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <div x-data="{ openDetail: false }">
                                        <button @click="openDetail = true" class="text-purple-600 hover:text-purple-900 font-semibold transition-colors">Detail</button>
                                        
                                        <!-- Modal -->
                                        <div x-show="openDetail" style="display: none;" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
                                            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                                                <div x-show="openDetail" @click="openDetail = false" x-transition.opacity class="fixed inset-0 bg-slate-900 bg-opacity-50 transition-opacity" aria-hidden="true"></div>
                                                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
                                                <div x-show="openDetail" x-transition class="inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-3xl w-full">
                                                    <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4 border-b border-slate-100">
                                                        <div class="flex justify-between items-center mb-4">
                                                            <h3 class="text-lg leading-6 font-bold text-slate-800" id="modal-title">
                                                                Detail Data Kemendik - {{ $row->nmmhsmsmh }}
                                                            </h3>
                                                            <button @click="openDetail = false" class="text-slate-400 hover:text-slate-600 focus:outline-none">
                                                                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                                            </button>
                                                        </div>
                                                        <div class="max-h-[60vh] overflow-y-auto">
                                                            <table class="min-w-full divide-y divide-slate-200">
                                                                <tbody class="bg-white divide-y divide-slate-100">
                                                                    @foreach($row->raw_data ?? [] as $key => $val)
                                                                        @if($val !== '' && !in_array($key, ['F5a1', 'F5c', 'F1101', 'F5b', 'F1102']))
                                                                        <tr>
                                                                            <td class="px-4 py-3 text-sm font-medium text-slate-700 w-1/2">{{ $key }}</td>
                                                                            <td class="px-4 py-3 text-sm text-slate-600 w-1/2">
                                                                                @php
                                                                                    // Check strict mapped value
                                                                                    $mapped = $val;
                                                                                    $trimmedKey = trim($key);
                                                                                    if (isset($keterangan[$trimmedKey])) {
                                                                                        // Look up by exact value or regex matched value
                                                                                        if (isset($keterangan[$trimmedKey][$val])) {
                                                                                            $mapped = $keterangan[$trimmedKey][$val];
                                                                                        }
                                                                                    }
                                                                                @endphp
                                                                                {{ $mapped }}
                                                                            </td>
                                                                        </tr>
                                                                        @endif
                                                                    @endforeach
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
                                <td colspan="4" class="px-6 py-12 text-center">
                                    <svg class="mx-auto h-12 w-12 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path vector-effect="non-scaling-stroke" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                                    <h3 class="mt-2 text-sm font-medium text-slate-900">Belum ada data</h3>
                                    <p class="mt-1 text-sm text-slate-500">Silakan upload data tracer study Kemendiktisaintek.</p>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                
                @if($kemendik_data->hasPages())
                <div class="px-6 py-4 border-t border-slate-100 bg-slate-50">
                    {{ $kemendik_data->links() }}
                </div>
                @endif
            </div>

        </div>
    </div>

    @if(isset($charts) && collect($charts['status'])->count() > 0)
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const chartData = @json($charts);
            
            const pieOptions = { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } } };
            const barOptions = { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } };

            new Chart(document.getElementById('chartStatus'), {
                type: 'pie',
                data: {
                    labels: Object.keys(chartData.status),
                    datasets: [{ data: Object.values(chartData.status), backgroundColor: ['#8b5cf6', '#10b981', '#f59e0b', '#ef4444', '#3b82f6', '#64748b'] }]
                },
                options: pieOptions
            });

            new Chart(document.getElementById('chartKesesuaian'), {
                type: 'doughnut',
                data: {
                    labels: Object.keys(chartData.kesesuaian),
                    datasets: [{ data: Object.values(chartData.kesesuaian), backgroundColor: ['#14b8a6', '#f43f5e', '#facc15', '#6366f1', '#a855f7'] }]
                },
                options: pieOptions
            });

            new Chart(document.getElementById('chartLevel'), {
                type: 'bar',
                data: {
                    labels: Object.keys(chartData.level),
                    datasets: [{ data: Object.values(chartData.level), backgroundColor: '#3b82f6', borderRadius: 4 }]
                },
                options: barOptions
            });

            new Chart(document.getElementById('chartInstansi'), {
                type: 'bar',
                data: {
                    labels: Object.keys(chartData.instansi),
                    datasets: [{ data: Object.values(chartData.instansi), backgroundColor: ['#ec4899', '#8b5cf6', '#06b6d4', '#10b981', '#f59e0b'], borderRadius: 4 }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }
                }
            });

            new Chart(document.getElementById('chartPendapatan'), {
                type: 'bar',
                data: {
                    labels: Object.keys(chartData.pendapatan),
                    datasets: [{ data: Object.values(chartData.pendapatan), backgroundColor: '#10b981', borderRadius: 4 }]
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

                const coordsDict = chartData.coords || {};

                for (const [kode, count] of Object.entries(chartData.map_lokasi)) {
                    if (coordsDict[kode] && coordsDict[kode].coords) {
                        const cityData = coordsDict[kode];
                        L.marker(cityData.coords).addTo(map)
                         .bindPopup(`<b>${cityData.name}</b><br>Jumlah: ${count} alumni`);
                    }
                }
            }
        });
    </script>
    @endif
</x-app-layout>
