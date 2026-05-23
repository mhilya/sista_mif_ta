<!DOCTYPE html>
<html>
<head>
    <title>Hasil Klasifikasi Internal MIF</title>
    <style>
        body {
            font-family: sans-serif;
            font-size: 12px;
        }
        h2 {
            text-align: center;
            margin-bottom: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            border: 1px solid #dddddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
        }
        .status-auto { color: #059669; font-weight: bold; }
        .status-manual { color: #2563eb; font-weight: bold; }
        .status-review { color: #d97706; font-weight: bold; }
    </style>
</head>
<body>
    <h2>Hasil Klasifikasi Internal MIF</h2>
    
    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>NIM</th>
                <th>Nama</th>
                <th>Pekerjaan</th>
                <th>Profil Prediksi</th>
                {{-- <th>Status</th> --}}
            </tr>
        </thead>
        <tbody>
            @foreach($internal_data as $index => $row)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $row->nim }}</td>
                <td>{{ $row->nama }}</td>
                <td>{{ $row->job_text_raw }}</td>
                <td>
                    {{ $row->predicted_profile ?? '-' }}
                    {{-- @if($row->confidence_score)
                        <br><small>({{ number_format($row->confidence_score*100, 1) }}%)</small>
                    @endif --}}
                </td>
                {{-- <td>
                    @if($row->status === 'auto_classified')
                        <span class="status-auto">Auto</span>
                    @elseif($row->status === 'manual_override')
                        <span class="status-manual">Manual</span>
                    @else
                        <span class="status-review">Review</span>
                    @endif
                </td> --}}
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
