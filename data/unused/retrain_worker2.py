#!/usr/bin/env python3
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
from filelock import FileLock # PENAMBAHAN KUNCI MUTEX

from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.linear_model import LogisticRegression
from sklearn.model_selection import StratifiedKFold, train_test_split
from sklearn.metrics import classification_report
from sklearn.pipeline import Pipeline
from Sastrawi.Stemmer.StemmerFactory import StemmerFactory

warnings.filterwarnings("ignore")

BASE_DIR = Path(__file__).parent.parent
ML_DIR = BASE_DIR / "ml_assets"
DATA_DIR = BASE_DIR.parent / "data" / "processed"

PIPELINE_PATH      = ML_DIR / "ml_pipeline_internal.pkl"
PIPELINE_BAK_PATH  = ML_DIR / "ml_pipeline_internal.pkl.bak"
CANDIDATE_PATH     = ML_DIR / "ml_pipeline_candidate.pkl"
METRICS_PATH       = ML_DIR / "metrics_internal_only.json"
STATUS_PATH        = ML_DIR / "retrain_status.json"
LOCK_PATH          = ML_DIR / "retrain_status.lock"
CORPUS_PATH        = DATA_DIR / "training_corpus_3.csv"

TARGET_CLASSES = ["Programmer", "Data Analyst", "Wirausaha Informatika", "Non-IT"]
MIN_IMPROVEMENT_THRESHOLD = 0.01
MAX_REGRESSION_ALLOWED    = 0.02

STOPWORDS = {
    "yang", "di", "ke", "dari", "dan", "atau", "dengan", "untuk", "pada", "dalam",
    "adalah", "ini", "itu", "tidak", "juga", "sudah", "akan", "bisa", "ada", "oleh"
}
stemmer = StemmerFactory().create_stemmer()

logging.basicConfig(level=logging.INFO, format="%(asctime)s | %(levelname)s | %(message)s", handlers=[logging.StreamHandler(sys.stdout)])
logger = logging.getLogger("retrain_worker")

def write_status(stage: str, message: str, extra: dict = None):
    # MENULIS STATUS SECARA ATOMIK
    lock = FileLock(LOCK_PATH, timeout=10)
    with lock:
        payload = {"stage": stage, "message": message, "timestamp": datetime.now().isoformat()}
        if extra: payload.update(extra)
        STATUS_PATH.write_text(json.dumps(payload, indent=2, ensure_ascii=False))
        logger.info(f"[{stage}] {message}")

def preprocess_raw_text(text: str) -> str:
    # Stemming HANYA untuk data manual override baru
    if pd.isna(text) or not isinstance(text, str): return ""
    text = text.lower().strip()
    text = re.sub(r'[^\w\s]', '', text.replace('-', ' ').replace('/', ' '))
    text = re.sub(r'\d+', '', text)
    words = [w for w in text.split() if w not in STOPWORDS and len(w) > 2]
    return " ".join([stemmer.stem(w) for w in words])

def preprocess_stemmed_text(text: str) -> str:
    # Pembersihan dasar tanpa memanggil Sastrawi untuk korpus lama
    if pd.isna(text) or not isinstance(text, str): return ""
    text = text.lower().strip()
    text = re.sub(r'[^\w\s]', '', text.replace('-', ' ').replace('/', ' '))
    words = [w for w in text.split() if w not in STOPWORDS and len(w) > 2]
    return " ".join(words)

def evaluate_model(model, X_test: pd.Series, y_test: pd.Series) -> dict:
    if len(X_test) == 0: return {"weighted_f1": 0.0, "accuracy": 0.0, "per_class": {}}
    y_pred = model.predict(X_test)
    report = classification_report(y_test, y_pred, output_dict=True, zero_division=0, labels=TARGET_CLASSES)
    return {
        "weighted_f1": round(report.get("weighted avg", {}).get("f1-score", 0.0), 4),
        "accuracy": round(report.get("accuracy", 0.0), 4),
        "per_class": {cls: {"f1": round(report.get(cls, {}).get("f1-score", 0.0), 3)} for cls in TARGET_CLASSES}
    }

def get_baseline_f1() -> float:
    try:
        if METRICS_PATH.exists():
            return float(json.loads(METRICS_PATH.read_text()).get("hold_out_test", {}).get("weighted avg", {}).get("f1-score", 0.0))
    except Exception: pass
    return 0.0

def do_rollback(reason: str, old_f1: float, new_f1: float):
    if CANDIDATE_PATH.exists(): CANDIDATE_PATH.unlink()
    if PIPELINE_BAK_PATH.exists():
        shutil.copy2(PIPELINE_BAK_PATH, PIPELINE_PATH)
    write_status("rolled_back", f"Model lama dipertahankan. {reason}", {"result": "rolled_back", "old_f1": old_f1, "new_f1": new_f1})

def main(extra_csv_path: str = None):
    write_status("started", "Worker dimulai")
    write_status("backup", "Membuat backup model lama...")
    if PIPELINE_PATH.exists(): shutil.copy2(PIPELINE_PATH, PIPELINE_BAK_PATH)

    baseline_f1 = get_baseline_f1()

    try:
        write_status("loading_data", "Memuat dan menggabungkan data training...")
        if not CORPUS_PATH.exists(): raise FileNotFoundError(f"Corpus asli tidak ditemukan: {CORPUS_PATH}")

        df_corpus = pd.read_csv(CORPUS_PATH, sep=";", dtype=str).dropna(subset=["job_text_raw", "label"])
        df_corpus["features"] = df_corpus["job_text_raw"].apply(preprocess_stemmed_text)

        df_extra = pd.DataFrame()
        if extra_csv_path and Path(extra_csv_path).exists():
            df_extra = pd.read_csv(extra_csv_path, sep=";", dtype=str).dropna(subset=["job_text_raw", "label"])
            df_extra = df_extra[df_extra["label"].isin(TARGET_CLASSES)]
            df_extra["features"] = df_extra["job_text_raw"].apply(preprocess_raw_text)

        # Gabungkan tanpa drop_duplicates kasar. Model butuh tahu frekuensi/probabilitas kelas untuk teks yang bias.
        df_all = pd.concat([df_corpus, df_extra], ignore_index=True)
        mask = df_all["features"].str.len() > 0
        df_all = df_all[mask]

        if len(df_all) < 20: raise ValueError("Data terlalu sedikit (minimum 20 baris).")

        X_all = df_all["features"]
        y_all = df_all["label"]

        # SPLIT DINAMIS BARU: Tidak ada lagi static test-set.
        X_train, X_test, y_train, y_test = train_test_split(X_all, y_all, test_size=0.3, random_state=42, stratify=y_all)

        write_status("training", "Training model final pada data gabungan dinamis...")
        candidate_model = Pipeline([
            ('tfidf', TfidfVectorizer(max_features=3000, ngram_range=(1, 2), sublinear_tf=True, min_df=1)),
            ('clf', LogisticRegression(max_iter=1000, class_weight='balanced', solver='lbfgs'))
        ])
        candidate_model.fit(X_train, y_train)
        joblib.dump(candidate_model, CANDIDATE_PATH)

        write_status("evaluating", "Mengevaluasi model baru vs model lama...")
        new_metrics = evaluate_model(candidate_model, X_test, y_test)
        new_f1 = new_metrics["weighted_f1"]
        delta = new_f1 - baseline_f1

        if baseline_f1 == 0.0 or delta >= MIN_IMPROVEMENT_THRESHOLD:
            should_promote, reason = True, "Model baru dipromote (Peningkatan Signifikan)"
        elif delta < -MAX_REGRESSION_ALLOWED:
            should_promote, reason = False, "Regresi skor terlalu tinggi. Rollback."
        else:
            should_promote, reason = False, "Peningkatan tidak memenuhi threshold minimal. Rollback."

        if not should_promote:
            do_rollback(reason, baseline_f1, new_f1)
            return

        write_status("promoting", "Mem-promote model baru...")
        shutil.move(str(CANDIDATE_PATH), str(PIPELINE_PATH))

        new_metrics_full = {
            "methodology": "Dynamic Split Retrain",
            "comparison": {"old_weighted_f1": baseline_f1, "new_weighted_f1": new_f1, "delta": round(delta, 4)},
            "hold_out_test": classification_report(y_test, candidate_model.predict(X_test), output_dict=True, zero_division=0)
        }
        METRICS_PATH.write_text(json.dumps(new_metrics_full, indent=2, ensure_ascii=False))
        write_status("promoted", f"Selesai. {reason}")

    except Exception as e:
        logger.error(f"ERROR: {e}", exc_info=True)
        do_rollback(f"Error sistem: {str(e)[:100]}", baseline_f1, 0.0)
        lock = FileLock(LOCK_PATH, timeout=10)
        with lock:
            status = json.loads(STATUS_PATH.read_text())
            status["stage"] = "failed"
            STATUS_PATH.write_text(json.dumps(status, indent=2, ensure_ascii=False))
        sys.exit(1)

if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else None)