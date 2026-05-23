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
from Sastrawi.Stemmer.StemmerFactory import StemmerFactory
import matplotlib
matplotlib.use('Agg')
import matplotlib.pyplot as plt
warnings.filterwarnings('ignore')

BASE_DIR = Path(__file__).parent
TRAIN_FILE = BASE_DIR / "processed" / "training_corpus_3.csv"
TEST_FILE = BASE_DIR / "processed" / "test_set_3.csv"
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
stemmer = StemmerFactory().create_stemmer()

def preprocess_text(text):
    if pd.isna(text) or not isinstance(text, str): return ""
    text = text.lower().strip()
    if text in {"nan", "none", "null", "-", "0", "", "tidak diisi"}: return ""
    text = text.replace('-', ' ').replace('/', ' ').replace('_', ' ').replace(',', ' ')
    text = re.sub(r'[^\w\s]', '', text)
    text = re.sub(r'\d+', '', text)
    words = [w for w in text.split() if w not in STOPWORDS and len(w) > 2]
    return " ".join([stemmer.stem(w) for w in words])

def main():
    if not TRAIN_FILE.exists():
        print("File training_corpus_3.csv belum ada"); return
    df_train = pd.read_csv(TRAIN_FILE, sep=";", dtype=str).dropna(subset=["job_text_raw", "label"])
    df_test = pd.read_csv(TEST_FILE, sep=";", dtype=str).dropna(subset=["job_text_raw", "label"]) if TEST_FILE.exists() else None

    print("Sedang memproses teks (cleaning, hapus kata hubung, dan stemming)...")
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
    for cls in TARGET_CLASSES:
        p = np.mean([f.get(cls, {}).get('precision', 0) for f in fold_metrics])
        r = np.mean([f.get(cls, {}).get('recall', 0) for f in fold_metrics])
        f1 = np.mean([f.get(cls, {}).get('f1-score', 0) for f in fold_metrics])
        print(f"  {cls:25} | P: {p:.3f} | R: {r:.3f} | F1: {f1:.3f}")

    print("\n Membuat model final dari seluruh data latih yang tersedia...")
    final_model = Pipeline([
        ('tfidf', TfidfVectorizer(max_features=3000, ngram_range=(1,2), sublinear_tf=True, min_df=1)),
        ('clf', LogisticRegression(max_iter=1000, class_weight='balanced', solver='lbfgs'))
    ])
    final_model.fit(X_train, y_train)

    if len(X_test) > 0:
        y_pred = final_model.predict(X_test)
        print("\n HASIL PENGUJIAN PADA DATA TEST:")
        rep_test = classification_report(y_test, y_pred, output_dict=True, zero_division=0)
        print(classification_report(y_test, y_pred, zero_division=0))
        for cls in TARGET_CLASSES:
            sup = rep_test[cls]['support'] if cls in rep_test else 0
            if sup < 5: print(f"Catatan: Kelas '{cls}' cuma punya {sup} data uji, skor F1-nya mungkin kurang akurat.")
        cm = confusion_matrix(y_test, y_pred, labels=TARGET_CLASSES)
        disp = ConfusionMatrixDisplay(confusion_matrix=cm, display_labels=TARGET_CLASSES)
        disp.plot(cmap='Blues', values_format='d', xticks_rotation=45, colorbar=False)
        plt.title('Confusion Matrix - Hold-Out Test'); plt.tight_layout()
        plt.savefig(ML_DIR / 'confusion_matrix_test.png', dpi=300); plt.close()
        print("Grafik confusion matrix berhasil disimpan ke confusion_matrix_test.png")
        vec, clf = final_model.named_steps['tfidf'], final_model.named_steps['clf']
        feats, coeffs = vec.get_feature_names_out(), clf.coef_
        print("\n5 KATA PALING BERPENGARUH UNTUK TIAP KELAS:")
        for i, cls in enumerate(TARGET_CLASSES):
            top = [(feats[j], coeffs[i][j]) for j in coeffs[i].argsort()[-5:][::-1] if coeffs[i][j] > 0]
            print(f"  [{cls}]  " + ",  ".join([f"{w}({c:.2f})" for w,c in top]))
        proba = final_model.predict_proba(X_test)
        max_p = np.max(proba, axis=1)
        plt.hist(max_p, bins=15, edgecolor='black', alpha=0.7, color='teal')
        plt.axvline(x=CONFIDENCE_THRESHOLD, color='red', linestyle='--', linewidth=2, label=f'Threshold {CONFIDENCE_THRESHOLD}')
        plt.xlabel('Confidence Score'); plt.ylabel('Count'); plt.title('Distribusi Confidence Score')
        plt.legend(); plt.grid(axis='y', alpha=0.3); plt.tight_layout()
        plt.savefig(ML_DIR / 'confidence_distribution.png', dpi=300); plt.close()
        below = np.sum(max_p < CONFIDENCE_THRESHOLD)
        print(f"\nPengecekan Keyakinan Model: Ada {below} dari {len(max_p)} prediksi ({below/len(max_p)*100:.1f}%) yang nilainya di bawah {CONFIDENCE_THRESHOLD}. Nanti bagian ini perlu dicek manual.")
        mis = np.where(y_test != y_pred)[0]
        if len(mis) > 0:
            print("\n DAFTAR PREDIKSI YANG MELESET:")
            for idx in mis[:min(6, len(mis))]:
                txt = df_test.iloc[idx]['job_text_raw'][:80]
                print(f"  • Seharusnya: {y_test.iloc[idx]:20} | Ditebak: {y_pred[idx]:20} | Teks: '{txt}...'")
        else: print("\n Bagus")
        metrics_test = rep_test
    else: metrics_test = None

    model_path = ML_DIR / "ml_pipeline_internal.pkl"
    joblib.dump(final_model, model_path)
    metrics = {"methodology": "Internal MIF only", "k_fold": {"accuracy_mean": float(avg_acc), "accuracy_std": float(np.std([f['accuracy'] for f in fold_metrics])), "folds": fold_metrics}, "threshold_config": CONFIDENCE_THRESHOLD}
    if metrics_test: metrics["hold_out_test"] = metrics_test
    with open(ML_DIR / "metrics_internal_only.json", 'w') as f: json.dump(metrics, f, indent=2)
    print(f"\n Model berhasil disimpan di: {model_path}")
    print(f"Laporan metrik disimpan di: {ML_DIR / 'metrics_internal_only.json'}")

if __name__ == "__main__":
    main()