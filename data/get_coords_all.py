import csv
import json
import time
from geopy.geocoders import Nominatim
from geopy.exc import GeocoderTimedOut

code_to_name = {}
with open('wilayah_dapodik_final.csv', mode='r', encoding='utf-8') as f:
    reader = csv.DictReader(f)
    for row in reader:
        # Bersihkan nama kabupaten/kota dan provinsi agar pencarian lebih akurat
        kab = row['Kabupaten_Kota'].replace('Kab. ', '').replace('Kota Adm. ', '').replace('Kota ', '')
        prov = row['Provinsi'].replace('Prov. ', '')
        
        if prov == 'D.K.I. Jakarta':
            prov = 'Jakarta'
        elif prov == 'D.I. Yogyakarta':
            prov = 'Yogyakarta'
            
        if kab == 'Adm. Kep. Seribu':
            kab = 'Kepulauan Seribu'
            
        # Format query pencarian, contoh: "Malang, Jawa Timur, Indonesia"
        if prov == 'Luar Negeri':
            query = kab
        else:
            query = f"{kab}, {prov}, Indonesia"
        code_to_name[row['Kode_Kabupaten']] = {
            "name": row['Kabupaten_Kota'],
            "query": query
        }

geolocator = Nominatim(user_agent="sista_mif_ta_tracer_study")
coords = {}

print(f"Mulai mencari koordinat untuk {len(code_to_name)} wilayah...")

# Looping melalui semua data yang ada di CSV
for code, info in code_to_name.items():
    query = info['query']
    name = info['name']
    max_retries = 3
    for attempt in range(max_retries):
        try:
            time.sleep(1) # Jeda 1 detik agar tidak terkena limit API Nominatim
            # Menambahkan parameter timeout eksplisit jika diperlukan, default biasanya 1 detik
            location = geolocator.geocode(query, timeout=5)
            if location:
                coords[code] = {"name": name, "coords": [location.latitude, location.longitude]}
                print(f"Geocoded: {code} -> {query} -> {coords[code]['coords']}")
            else:
                print(f"NOT FOUND: {code} -> {query}")
            break # Berhasil diproses (entah ketemu atau tidak ketemu), hentikan loop retry
        except GeocoderTimedOut:
            print(f"TIMEOUT (Percobaan {attempt + 1}/{max_retries}): {code} -> {query}")
            if attempt == max_retries - 1:
                print(f"GAGAL setelah {max_retries} percobaan: {code} -> {query}")
            else:
                time.sleep(2) # Jeda lebih lama sebelum mencoba ulang

# Menyimpan hasil ke file JSON baru
output_file = 'kabupaten_coords.json'
with open(output_file, 'w') as f:
    json.dump(coords, f, indent=4)

print(f"Selesai! Data koordinat berhasil disimpan ke '{output_file}'")
