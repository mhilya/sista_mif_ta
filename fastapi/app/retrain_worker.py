#!/usr/bin/env python3
"""
TRACER STUDY - RE-TRAINING WORKER (SUBPROCESS)
Dipanggil oleh FastAPI sebagai background subprocess.
Alur:
  1. Backup pkl lama → pkl.bak
  2. Merge corpus asli + data manual_override baru
  3. Train model baru (candidate)
  4. Evaluasi: bandingkan weighted F1-score baru vs lama
  5. Promote jika lebih baik; rollback jika tidak
  6. Update retrain_status.json di setiap tahap
"""

import sys
import json
import shutil
import logging
import warnings
import re
import os
import pandas as pd
import numpy as np
import joblib
from pathlib import Path
from datetime import datetime

from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.linear_model import LogisticRegression
from sklearn.model_selection import StratifiedKFold, train_test_split
from sklearn.metrics import classification_report
from sklearn.pipeline import Pipeline
from Sastrawi.Stemmer.StemmerFactory import StemmerFactory

warnings.filterwarnings("ignore")

# ──────────────────────────────────────────────────────────────
# KONFIGURASI
# ──────────────────────────────────────────────────────────────
BASE_DIR = Path(__file__).parent.parent
ML_DIR = BASE_DIR / "ml_assets"
DATA_DIR = BASE_DIR.parent / "data" / "processed"

PIPELINE_PATH      = ML_DIR / "ml_pipeline_internal.pkl"
PIPELINE_BAK_PATH  = ML_DIR / "ml_pipeline_internal.pkl.bak"
CANDIDATE_PATH     = ML_DIR / "ml_pipeline_candidate.pkl"
METRICS_PATH       = ML_DIR / "metrics_internal_only.json"
STATUS_PATH        = ML_DIR / "retrain_status.json"
CORPUS_PATH        = DATA_DIR / "training_corpus_3.csv"
TEST_PATH          = DATA_DIR / "test_set_3.csv"

TARGET_CLASSES = ["Programmer", "Data Analyst", "Wirausaha Informatika", "Non-IT"]

# Threshold: model baru harus lebih baik minimal MIN_IMPROVEMENT dari model lama
MIN_IMPROVEMENT_THRESHOLD = 0.01   # 1% weighted F1
MAX_REGRESSION_ALLOWED    = 0.02   # Toleransi: model baru boleh lebih buruk max 2% (di luar ini = rollback keras)

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

# ──────────────────────────────────────────────────────────────
# LOGGING
# ──────────────────────────────────────────────────────────────
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s | %(levelname)s | %(message)s",
    handlers=[logging.StreamHandler(sys.stdout)]
)
logger = logging.getLogger("retrain_worker")


# ──────────────────────────────────────────────────────────────
# STATUS WRITER
# ──────────────────────────────────────────────────────────────
def write_status(stage: str, message: str, extra: dict = None):
    payload = {
        "stage": stage,
        "message": message,
        "timestamp": datetime.now().isoformat(),
    }
    if extra:
        payload.update(extra)
    STATUS_PATH.write_text(json.dumps(payload, indent=2, ensure_ascii=False))
    logger.info(f"[{stage}] {message}")


# ──────────────────────────────────────────────────────────────
# PREPROCESSING — IDENTIK dengan main.py
# ──────────────────────────────────────────────────────────────
def preprocess_text(text: str) -> str:
    if pd.isna(text) or not isinstance(text, str):
        return ""
    text = text.lower().strip()
    if text in {"nan", "none", "null", "-", "0", "", "tidak diisi"}:
        return ""
    text = text.replace('-', ' ').replace('/', ' ').replace('_', ' ').replace(',', ' ')
    text = re.sub(r'[^\w\s]', '', text)
    text = re.sub(r'\d+', '', text)
    words = [w for w in text.split() if w not in STOPWORDS and len(w) > 2]
    return " ".join([stemmer.stem(w) for w in words])


# ──────────────────────────────────────────────────────────────
# EVALUASI MODEL — weighted F1 pada hold-out test
# ──────────────────────────────────────────────────────────────
def evaluate_model(model, X_test: pd.Series, y_test: pd.Series) -> dict:
    """
    Evaluasi model pada hold-out test set.
    Return: dict berisi weighted F1, accuracy, dan per-class metrics.
    """
    if len(X_test) == 0:
        return {"weighted_f1": 0.0, "accuracy": 0.0, "per_class": {}}

    y_pred = model.predict(X_test)
    report = classification_report(
        y_test, y_pred,
        output_dict=True,
        zero_division=0,
        labels=TARGET_CLASSES
    )
    return {
        "weighted_f1": round(report.get("weighted avg", {}).get("f1-score", 0.0), 4),
        "accuracy": round(report.get("accuracy", 0.0), 4),
        "per_class": {
            cls: {
                "precision": round(report.get(cls, {}).get("precision", 0.0), 3),
                "recall": round(report.get(cls, {}).get("recall", 0.0), 3),
                "f1": round(report.get(cls, {}).get("f1-score", 0.0), 3),
                "support": int(report.get(cls, {}).get("support", 0)),
            }
            for cls in TARGET_CLASSES
        }
    }


# ──────────────────────────────────────────────────────────────
# AMBIL BASELINE DARI METRICS JSON
# ──────────────────────────────────────────────────────────────
def get_baseline_f1() -> float:
    """
    Baca weighted F1 model lama dari metrics_internal_only.json.
    Fallback ke 0 jika file tidak ada.
    """
    try:
        if METRICS_PATH.exists():
            m = json.loads(METRICS_PATH.read_text())
            return float(m.get("hold_out_test", {}).get("weighted avg", {}).get("f1-score", 0.0))
    except Exception as e:
        logger.warning(f"Gagal baca baseline metrics: {e}")
    return 0.0


# ──────────────────────────────────────────────────────────────
# ROLLBACK
# ──────────────────────────────────────────────────────────────
def do_rollback(reason: str, old_f1: float, new_f1: float):
    """Kembalikan pkl aktif ke backup."""
    # Bersihkan candidate jika ada
    if CANDIDATE_PATH.exists():
        CANDIDATE_PATH.unlink()

    # Restore dari backup
    if PIPELINE_BAK_PATH.exists():
        shutil.copy2(PIPELINE_BAK_PATH, PIPELINE_PATH)
        logger.info(f"Rollback berhasil: pkl lama dipulihkan dari .bak")
    else:
        logger.warning("File .bak tidak ditemukan, pkl aktif dibiarkan.")

    write_status(
        stage="rolled_back",
        message=f"Model lama dipertahankan. {reason}",
        extra={
            "result": "rolled_back",
            "reason": reason,
            "old_f1": old_f1,
            "new_f1": new_f1,
        }
    )


# ──────────────────────────────────────────────────────────────
# MAIN
# ──────────────────────────────────────────────────────────────
def main(extra_csv_path: str = None):
    write_status("started", "Worker dimulai")

    # ── STEP 1: BACKUP ──────────────────────────────────────
    write_status("backup", "Membuat backup model lama...")
    if PIPELINE_PATH.exists():
        shutil.copy2(PIPELINE_PATH, PIPELINE_BAK_PATH)
        logger.info(f"Backup tersimpan: {PIPELINE_BAK_PATH}")
    else:
        write_status("failed", "File pkl aktif tidak ditemukan, tidak bisa backup.")
        sys.exit(1)

    baseline_f1 = get_baseline_f1()
    logger.info(f"Baseline weighted F1 (model lama): {baseline_f1:.4f}")

    try:
        # ── STEP 2: LOAD & MERGE DATA ────────────────────────
        write_status("loading_data", "Memuat dan menggabungkan data training...")

        if not CORPUS_PATH.exists():
            raise FileNotFoundError(f"Corpus asli tidak ditemukan: {CORPUS_PATH}")

        df_corpus = pd.read_csv(CORPUS_PATH, sep=";", dtype=str).dropna(subset=["job_text_raw", "label"])
        logger.info(f"Corpus asli: {len(df_corpus)} baris")

        df_extra = pd.DataFrame()
        if extra_csv_path and Path(extra_csv_path).exists():
            df_extra = pd.read_csv(extra_csv_path, sep=";", dtype=str).dropna(subset=["job_text_raw", "label"])
            # Validasi label
            valid_labels = set(TARGET_CLASSES)
            df_extra = df_extra[df_extra["label"].isin(valid_labels)]
            logger.info(f"Data manual_override: {len(df_extra)} baris valid")

        if len(df_extra) > 0:
            df_all = pd.concat([df_corpus, df_extra], ignore_index=True)
            # Deduplicate: preferensikan data extra (manual override) jika job_text_raw sama
            df_all = df_all.drop_duplicates(subset=["job_text_raw"], keep="last")
        else:
            df_all = df_corpus.copy()
            logger.info("Tidak ada data tambahan — menggunakan corpus asli saja")

        logger.info(f"Total setelah merge & deduplicate: {len(df_all)} baris")

        if len(df_all) < 20:
            raise ValueError(f"Data terlalu sedikit untuk training: {len(df_all)} baris (minimum 20)")

        # ── STEP 3: PREPROCESSING ────────────────────────────
        write_status("preprocessing", "Preprocessing teks...")
        X_all = df_all["job_text_raw"].apply(preprocess_text)
        y_all = df_all["label"]
        mask = X_all.str.len() > 0
        X_all, y_all = X_all[mask], y_all[mask]
        logger.info(f"Setelah filter kosong: {len(X_all)} baris")

        # ── STEP 4: SPLIT TRAIN / TEST ───────────────────────
        # Cek apakah test_set_3.csv ada untuk hold-out
        if TEST_PATH.exists():
            df_test = pd.read_csv(TEST_PATH, sep=";", dtype=str).dropna(subset=["job_text_raw", "label"])
            X_test = df_test["job_text_raw"].apply(preprocess_text)
            y_test = df_test["label"]
            mask_t = X_test.str.len() > 0
            X_test, y_test = X_test[mask_t], y_test[mask_t]
            X_train, y_train = X_all, y_all
            logger.info(f"Hold-out test dari file: {len(X_test)} baris")
        else:
            # Fallback: split 70/30 dari data gabungan
            X_train, X_test, y_train, y_test = train_test_split(
                X_all, y_all, test_size=0.3, random_state=42, stratify=y_all
            )
            logger.info(f"Hold-out test dari split 30%: {len(X_test)} baris")

        # ── STEP 5: TRAINING ─────────────────────────────────
        write_status("training", f"Training model baru... ({len(X_train)} sampel training)")

        skf = StratifiedKFold(n_splits=5, shuffle=True, random_state=42)
        fold_metrics = []
        for i, (tr_idx, te_idx) in enumerate(skf.split(X_train, y_train)):
            m = Pipeline([
                ('tfidf', TfidfVectorizer(max_features=3000, ngram_range=(1, 2), sublinear_tf=True, min_df=1)),
                ('clf', LogisticRegression(max_iter=1000, class_weight='balanced', solver='lbfgs'))
            ])
            m.fit(X_train.iloc[tr_idx], y_train.iloc[tr_idx])
            y_pred = m.predict(X_train.iloc[te_idx])
            rep = classification_report(y_train.iloc[te_idx], y_pred, output_dict=True, zero_division=0)
            fold_metrics.append(rep)
            write_status("training", f"K-Fold selesai: fold {i+1}/5 | acc={rep['accuracy']:.4f}")

        # Train final model on full training set
        write_status("training", "Training model final pada seluruh data training...")
        candidate_model = Pipeline([
            ('tfidf', TfidfVectorizer(max_features=3000, ngram_range=(1, 2), sublinear_tf=True, min_df=1)),
            ('clf', LogisticRegression(max_iter=1000, class_weight='balanced', solver='lbfgs'))
        ])
        candidate_model.fit(X_train, y_train)

        # Simpan sebagai candidate (belum overwrite aktif)
        joblib.dump(candidate_model, CANDIDATE_PATH)
        logger.info(f"Candidate model tersimpan: {CANDIDATE_PATH}")

        # ── STEP 6: EVALUASI & KEPUTUSAN ─────────────────────
        write_status("evaluating", "Mengevaluasi model baru vs model lama...")

        new_metrics = evaluate_model(candidate_model, X_test, y_test)
        new_f1 = new_metrics["weighted_f1"]
        delta = new_f1 - baseline_f1

        logger.info(f"Baseline F1 : {baseline_f1:.4f}")
        logger.info(f"Candidate F1: {new_f1:.4f}  (delta: {delta:+.4f})")

        # ── KEPUTUSAN ────────────────────────────────────────
        if baseline_f1 == 0.0:
            # Tidak ada baseline (model lama belum pernah dievaluasi) → langsung promote
            reason_promote = "Tidak ada baseline metrics → model baru dipromote"
            should_promote = True
        elif delta >= MIN_IMPROVEMENT_THRESHOLD:
            should_promote = True
            reason_promote = f"Model baru lebih baik (+{delta*100:.2f}% weighted F1)"
        elif delta < -MAX_REGRESSION_ALLOWED:
            should_promote = False
            reason_rollback = f"Model baru lebih buruk secara signifikan ({delta*100:.2f}% weighted F1)"
        else:
            should_promote = False
            reason_rollback = (
                f"Peningkatan tidak signifikan ({delta*100:.2f}% weighted F1, "
                f"minimum dibutuhkan: +{MIN_IMPROVEMENT_THRESHOLD*100:.1f}%)"
            )

        if not should_promote:
            do_rollback(reason_rollback, baseline_f1, new_f1)
            return

        # ── STEP 7: PROMOTE ──────────────────────────────────
        write_status("promoting", "Model baru lebih baik — mempromote model baru...")

        # Atomic rename: candidate → aktif
        shutil.move(str(CANDIDATE_PATH), str(PIPELINE_PATH))
        logger.info(f"Model baru dipromote: {PIPELINE_PATH}")

        # Update metrics JSON
        avg_acc = float(np.mean([f["accuracy"] for f in fold_metrics]))
        y_pred_final = candidate_model.predict(X_test)
        new_metrics_full = {
            "methodology": "Internal MIF + manual_override",
            "retrained_at": datetime.now().isoformat(),
            "extra_samples_added": len(df_extra),
            "total_training_samples": len(X_train),
            "k_fold": {
                "accuracy_mean": avg_acc,
                "accuracy_std": float(np.std([f["accuracy"] for f in fold_metrics])),
                "folds": fold_metrics,
            },
            "threshold_config": 0.50,
            "hold_out_test": classification_report(
                y_test,
                y_pred_final,
                output_dict=True,
                zero_division=0
            ),
            "comparison": {
                "old_weighted_f1": baseline_f1,
                "new_weighted_f1": new_f1,
                "delta": round(delta, 4),
            }
        }
        METRICS_PATH.write_text(json.dumps(new_metrics_full, indent=2, ensure_ascii=False))


        write_status(
            stage="promoted",
            message=f"Model baru berhasil dipromote. {reason_promote}",
            extra={
                "result": "promoted",
                "old_f1": baseline_f1,
                "new_f1": new_f1,
                "delta": round(delta, 4),
                "new_accuracy": new_metrics["accuracy"],
                "per_class": new_metrics["per_class"],
                "extra_samples_added": len(df_extra),
                "total_training_samples": len(X_train),
            }
        )

    except Exception as e:
        logger.error(f"ERROR saat training: {type(e).__name__}: {e}", exc_info=True)
        do_rollback(
            reason=f"Training gagal karena error: {type(e).__name__}: {str(e)[:200]}",
            old_f1=baseline_f1,
            new_f1=0.0
        )
        # Override stage ke 'failed' agar UI tahu ini bukan rollback biasa
        status = json.loads(STATUS_PATH.read_text())
        status["stage"] = "failed"
        STATUS_PATH.write_text(json.dumps(status, indent=2, ensure_ascii=False))
        sys.exit(1)


if __name__ == "__main__":
    # Argumen: [extra_csv_path]
    extra_csv = sys.argv[1] if len(sys.argv) > 1 else None
    main(extra_csv)
