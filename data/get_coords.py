import csv
import json
import time
from geopy.geocoders import Nominatim
from geopy.exc import GeocoderTimedOut

unique_codes = ["52400","52100","51900","16400","51400","56000","316000","52000","56600","52500","40100","52300","50700","16000","26000","46000","130300","51800","50500","52200","56700","56400","51300","50600","51600","56300","50400","16100","16300","96000","16200","270200","51100","26600","50100","56100","50200","20500","160300","999999","166100","50300","56200","166000","250100","52600","21900","36300","220400"]

code_to_name = {}
with open('wilayah_dapodik_final.csv', mode='r', encoding='utf-8') as f:
    reader = csv.DictReader(f)
    for row in reader:
        kab = row['Kabupaten_Kota'].replace('Kab. ', '').replace('Kota Adm. ', '').replace('Kota ', '')
        prov = row['Provinsi'].replace('Prov. ', '')
        code_to_name[row['Kode_Kabupaten']] = kab + ", " + prov + ", Indonesia"

geolocator = Nominatim(user_agent="sista_mif_ta_tracer_study")

coords = {}

for code in unique_codes:
    if code in code_to_name:
        query = code_to_name[code]
        try:
            time.sleep(10)
            location = geolocator.geocode(query)
            if location:
                coords[code] = [location.latitude, location.longitude]
                print(f"Geocoded: {code} -> {query} -> {coords[code]}")
            else:
                print(f"NOT FOUND: {code} -> {query}")
        except GeocoderTimedOut:
            print(f"TIMEOUT: {code} -> {query}")
    else:
        print(f"CODE NOT IN Dapodik: {code}")

with open('kabupaten_coords.json', 'w') as f:
    json.dump(coords, f, indent=4)

print("Done geocoding!")
