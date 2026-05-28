import pandas as pd
import numpy as np
import re
import joblib
import json
import warnings
from pathlib import Path
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.linear_model import LogisticRegression
from sklearn.model_selection import StratifiedKFold
from sklearn.metrics import classification_report, confusion_matrix, ConfusionMatrixDisplay
from sklearn.pipeline import Pipeline
import matplotlib
matplotlib.use('Agg')
import matplotlib.pyplot as plt
warnings.filterwarnings('ignore')

BASE_DIR = Path(__file__).parent
TRAIN_FILE = BASE_DIR / "processed" / "training_corpus_4.csv"
TEST_FILE = BASE_DIR / "processed" / "test_set_4.csv"
ML_DIR = BASE_DIR.parent / "fastapi" / "ml_assets"
ML_DIR.mkdir(parents=True, exist_ok=True)
TARGET_CLASSES = ["Programmer", "Data Analyst", "Wirausaha Informatika", "Non-IT"]
CONFIDENCE_THRESHOLD = 0.50

STOPWORDS = {
    "yang", "di", "ke", "dari", "dan", "atau", "dengan", "untuk", "pada", "dalam",
    "adalah", "ini", "itu", "tidak", "juga", "sudah", "akan", "bisa", "ada", "oleh",
    "karena", "secara", "serta", "sebagai", "bagi", "telah", "maka", "namun", "sehingga",
    "jika", "agar", "ketika", "saat", "sebelum", "sesudah", "hingga", "sampai", "antara",
    "sekitar", "hanya", "saja", "belum", "masih", "lagi", "pun", "justru", "walaupun",
    "meskipun", "bahkan", "cukup", "sangat", "paling", "lebih", "kurang", "lain",
    "macam", "cara", "hal", "tentang", "mengenai", "terhadap", "kepada", "menuju",
    "kecuali", "selain", "tanpa", "demi", "guna", "khususnya", "umumnya", "kebanyakan",
    "sebagian", "beberapa", "semua", "setiap", "tiap", "satu", "dua", "tiga", "empat",
    "lima", "enam", "tujuh", "delapan", "sembilan", "sepuluh", "ratus", "ribu", "juta"
}

def preprocess_text(text):
    # Tidak ada Sastrawi! Teks korpus SUDAH di-stem.
    if pd.isna(text) or not isinstance(text, str): return ""
    text = text.lower().strip()
    if text in {"nan", "none", "null", "-", "0", "", "tidak diisi"}: return ""
    text = text.replace('-', ' ').replace('/', ' ').replace('_', ' ').replace(',', ' ')
    text = re.sub(r'[^\w\s]', '', text)
    text = re.sub(r'\d+', '', text)
    words = [w for w in text.split() if w not in STOPWORDS and len(w) > 2]
    return " ".join(words)

def main():
    if not TRAIN_FILE.exists():
        print("File training_corpus_4.csv belum ada"); return
    df_train = pd.read_csv(TRAIN_FILE, sep=";", dtype=str).dropna(subset=["job_text_raw", "label"])
    df_test = pd.read_csv(TEST_FILE, sep=";", dtype=str).dropna(subset=["job_text_raw", "label"]) if TEST_FILE.exists() else None

    print("Sedang memproses teks (cleaning dasar tanpa stemming redundan)...")
    X_train = df_train["job_text_raw"].apply(preprocess_text)
    y_train = df_train["label"]
    mask = X_train.str.len() > 0
    X_train, y_train = X_train[mask], y_train[mask]
    
    X_test, y_test = pd.Series(dtype=str), pd.Series(dtype=str)
    if df_test is not None:
        X_test = df_test["job_text_raw"].apply(preprocess_text)
        y_test = df_test["label"]
        mask_t = X_test.str.len() > 0
        X_test, y_test = X_test[mask_t], y_test[mask_t]
        
    print(f"Jumlah data latih: {len(X_train)} baris | Data uji: {len(X_test)} baris\n")

    print("Melakukan pengujian K-Fold (5 putaran) pada data latih...")
    skf = StratifiedKFold(n_splits=5, shuffle=True, random_state=42)
    fold_metrics = []
    for i, (tr_idx, te_idx) in enumerate(skf.split(X_train, y_train)):
        model = Pipeline([
            ('tfidf', TfidfVectorizer(max_features=3000, ngram_range=(1,2), sublinear_tf=True, min_df=1)),
            ('clf', LogisticRegression(max_iter=1000, class_weight='balanced', solver='lbfgs'))
        ])
        model.fit(X_train.iloc[tr_idx], y_train.iloc[tr_idx])
        y_pred = model.predict(X_train.iloc[te_idx])
        rep = classification_report(y_train.iloc[te_idx], y_pred, output_dict=True, zero_division=0)
        fold_metrics.append(rep)
        print(f"  Akurasi putaran ke-{i+1}: {rep['accuracy']:.4f}")

    avg_acc = np.mean([f['accuracy'] for f in fold_metrics])
    print(f"\n RATA-RATA PENGUJIAN K-FOLD:")
    print(f"  Akurasi Keseluruhan : {avg_acc:.4f} ± {np.std([f['accuracy'] for f in fold_metrics]):.4f}")

    print("\n Membuat model final dari seluruh data latih yang tersedia...")
    final_model = Pipeline([
        ('tfidf', TfidfVectorizer(max_features=3000, ngram_range=(1,2), sublinear_tf=True, min_df=1)),
        ('clf', LogisticRegression(max_iter=1000, class_weight='balanced', solver='lbfgs'))
    ])
    final_model.fit(X_train, y_train)

    metrics_test = None
    if len(X_test) > 0:
        y_pred = final_model.predict(X_test)
        rep_test = classification_report(y_test, y_pred, output_dict=True, zero_division=0)
        metrics_test = rep_test
        print("\n HASIL PENGUJIAN PADA DATA TEST:")
        print(classification_report(y_test, y_pred, zero_division=0))

    model_path = ML_DIR / "ml_pipeline_internal.pkl"
    joblib.dump(final_model, model_path)
    
    metrics = {"methodology": "Internal MIF only", "k_fold": {"accuracy_mean": float(avg_acc), "accuracy_std": float(np.std([f['accuracy'] for f in fold_metrics])), "folds": fold_metrics}, "threshold_config": CONFIDENCE_THRESHOLD}
    if metrics_test: metrics["hold_out_test"] = metrics_test
    
    with open(ML_DIR / "metrics_internal_only.json", 'w') as f: json.dump(metrics, f, indent=2)
    print(f"\n Model berhasil disimpan di: {model_path}")

if __name__ == "__main__":
    main()