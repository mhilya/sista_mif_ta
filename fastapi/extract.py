import joblib
import numpy as np

# Load model
model = joblib.load('ml_assets/ml_pipeline_internal.pkl')

# Ekstrak komponen
vec = model.named_steps['tfidf']
clf = model.named_steps['clf']

# Dapatkan vocabulary
feature_names = vec.get_feature_names_out()

# Dapatkan bobot koefisien
coefficients = clf.coef_
intercepts = clf.intercept_

# Tampilkan bobot untuk kata-kata di korpus mini
target_words = ['web', 'develop', 'analyst']

print("BOBOT KOEFISIEN RIIL:")
print("=" * 60)
for word in target_words:
    if word in feature_names:
        idx = list(feature_names).index(word)
        print(f"\nKata: '{word}'")
        for i, cls in enumerate(clf.classes_):
            print(f"  Kelas {cls}: {coefficients[i][idx]:.4f}")

print("\nINTERSEP (Bias):")
for i, cls in enumerate(clf.classes_):
    print(f"  Kelas {cls}: {intercepts[i]:.4f}")