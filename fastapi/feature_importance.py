import joblib

# Load pipeline model
pipeline = joblib.load('ml_assets/ml_pipeline_internal.pkl')
vec = pipeline.named_steps['tfidf']
clf = pipeline.named_steps['clf']

# Ambil semua nama fitur (vocabulary)
feats = vec.get_feature_names_out()

# Looping untuk semua kelas
for class_name in clf.classes_:
    print(f"\n{'='*60}")
    print(f"KELAS: {class_name}")
    print(f"{'='*60}")
    
    class_idx = list(clf.classes_).index(class_name)
    class_coefs = clf.coef_[class_idx]
    
    # 1. Top 5 Fitur POSITIF (Paling tinggi)
    print("\n[+] Top 5 Fitur POSITIF (Paling Mendorong ke Kelas Ini):")
    top_positive = sorted(zip(feats, class_coefs), key=lambda x: -x[1])[:5]
    for word, coef in top_positive:
        print(f"  {word}: {coef:.4f}")
        
    # 2. Top 5 Fitur NEGATIF (Paling rendah)
    print("\n[-] Top 5 Fitur NEGATIF (Paling Menghambat Kelas Ini):")
    top_negative = sorted(zip(feats, class_coefs), key=lambda x: x[1])[:5]
    for word, coef in top_negative:
        print(f"  {word}: {coef:.4f}")