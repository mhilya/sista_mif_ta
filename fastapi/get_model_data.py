import joblib
pipeline = joblib.load('ml_assets/ml_pipeline_internal.pkl')
vec = pipeline.named_steps['tfidf']
clf = pipeline.named_steps['clf']

# Ambil 5 fitur dengan bobot tertinggi untuk kelas Programmer
feats = vec.get_feature_names_out()
prog_idx = list(clf.classes_).index('Programmer')
top5 = sorted(zip(feats, clf.coef_[prog_idx]), key=lambda x: -x[1])[:10]
for w, c in top5:
    print(f"{w}: {c:.4f}")