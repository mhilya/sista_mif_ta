#!/usr/bin/env python3
"""
TRACER STUDY - RE-TRAINING WORKER FINAL (SUBPROCESS)
Dipanggil oleh FastAPI (main3.py) sebagai background subprocess.

Alur:
  1. Backup pkl lama → pkl.bak
  2. Merge corpus + manual_override (TANPA drop_duplicates)
     Override diberi sample_weight lebih tinggi, bukan menghapus data historis.
  3. Dynamic train/test split dari data gabungan (selalu fresh)
  4. Evaluasi MODEL LAMA pada test set yang sama (fair comparison)
  5. K-Fold + train model final (candidate) dengan sample_weight
  6. Evaluasi candidate pada test set yang sama → delta terhadap old model
  7. Promote jika lebih baik; rollback jika tidak
  8. Setiap write status dilindungi FileLock (atomic)
"""

import sys
import json
import shutil
import logging
import warnings
import re
import math
import pandas as pd
import numpy as np
import joblib
from pathlib import Path
from datetime import datetime
from filelock import FileLock

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
ML_DIR   = BASE_DIR / "ml_assets"
DATA_DIR = BASE_DIR.parent / "data" / "processed"

PIPELINE_PATH     = ML_DIR / "ml_pipeline_internal.pkl"
PIPELINE_BAK_PATH = ML_DIR / "ml_pipeline_internal.pkl.bak"
CANDIDATE_PATH    = ML_DIR / "ml_pipeline_candidate.pkl"
METRICS_PATH      = ML_DIR / "metrics_internal_only.json"
STATUS_PATH       = ML_DIR / "retrain_status.json"
LOCK_PATH         = ML_DIR / "retrain_status.lock"
CORPUS_PATH       = DATA_DIR / "training_corpus.csv"
TEST_PATH         = DATA_DIR / "test_set.csv"

TARGET_CLASSES = ["Programmer", "Data Analyst", "Wirausaha Informatika", "Non-IT"]
MIN_IMPROVEMENT_THRESHOLD = 0.01   # Model baru harus lebih baik minimal +1% weighted F1
MAX_REGRESSION_ALLOWED    = 0.02   # Toleransi degradasi maksimal 2% sebelum rollback keras

# ── KONFIGURASI BOBOT OVERRIDE ──────────────────────────────────────────────
#
# Masalah yang diselesaikan:
#   Pada skala besar (N_corpus >> N_override), bobot statis kehilangan daya
#   akibat dilusi. Formula proporsi murni w = t*N / (M*(1-t)) menyelesaikan
#   dilusi, tapi menghasilkan w=42.85 pada skenario 10k/100 — yang berisiko
#   overfitting ekstrem pada noise override.
#
# Solusi: Logarithmic damping — tanpa tembok statis.
#   w_raw = formula proporsi  (jaminan 30% jika tidak di-damp)
#   w     = MIN + ln(1 + max(0, w_raw - MIN))
#
#   Fase linear (w_raw rendah): w ≈ w_raw  → proporsi terpenuhi
#   Fase log (w_raw tinggi):    w tumbuh tapi melambat → damp alami
#
# Implikasi jujur:
#   Target 30% TIDAK dipertahankan di skala ekstrem. Ini trade-off yang
#   disengaja: degradasi gradual lebih aman daripada overfitting ke 10
#   baris override berbobot 42x. Tanpa tembok statis, redaman terjadi
#   secara natural mengikuti kurva logaritmik, bukan menabrak batas arbitrer.
#
# Perilaku nyata (MIN=2.0, TARGET=0.30):
#   w_raw  │  w_log  │ influence aktual
#   ──────────────────────────────────
#    2.00  │  2.000  │ formula <= MIN, pakai floor
#    4.29  │  3.178  │ (1k corpus, 100 override) → ~24%
#    8.57  │  3.999  │ (100 korpus, 5 override)  → ~17%
#   42.86  │  5.723  │ (10k corpus, 100 override)→  ~5.4%
#  428.60  │  8.063  │ (10k corpus, 10 override) →  ~0.8%
#
# Jika angka influence aktual dianggap terlalu kecil → naikkan TARGET.
# Jika model terlalu sensitif ke override → naikkan MIN agar floor lebih tinggi.
OVERRIDE_INFLUENCE_TARGET = 0.30  # Titik acuan proporsi (valid di skala normal)
OVERRIDE_MIN_WEIGHT       = 2.0   # Lantai: override selalu minimal 2× korpus

# [FIX v4] STOPWORDS lengkap — versi retrain_worker2 hanya punya 10 kata (bug terpotong)
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
# DYNAMIC WEIGHT CALCULATOR
# ──────────────────────────────────────────────────────────────
def compute_override_weight(n_corpus: int, n_override: int) -> float:
    """
    Hitung bobot override dengan logarithmic damping.

    TIDAK ada OVERRIDE_MAX_WEIGHT — tembok statis menghancurkan jaminan
    pengaruh tepat saat skala besar membutuhkannya. Sebagai gantinya,
    fungsi ln(1+x) menyediakan redaman alami:

        w_raw = (t * n_corpus) / (n_override * (1 - t))   # proporsi murni
        w     = MIN + ln(1 + max(0, w_raw - MIN))          # log damping

    Jaminan matematis:
        - w selalu >= OVERRIDE_MIN_WEIGHT (floor tetap ada)
        - w tidak pernah meledak ke infinity karena ln tumbuh O(log n)
        - Tidak ada tembok statis yang membuat influence kolaps tiba-tiba

    Catatan interaksi:
        LogisticRegression dipanggil dengan class_weight='balanced' DAN
        sample_weight. Keduanya dikalikan oleh sklearn secara internal.
        Artinya sampel override dari kelas minoritas mendapat boost ganda.
        Pantau per-class F1 di metrics JSON untuk mendeteksi efek ini.
    """
    if n_override == 0:
        return 1.0
    t = OVERRIDE_INFLUENCE_TARGET
    w_raw = (t * n_corpus) / (n_override * (1.0 - t))
    # ln damping: linear di zona rendah, melambat secara alami di skala besar
    w_log = OVERRIDE_MIN_WEIGHT + math.log1p(max(0.0, w_raw - OVERRIDE_MIN_WEIGHT))
    return round(w_log, 4)

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
# STATUS WRITER — atomic dengan FileLock (v4)
# ──────────────────────────────────────────────────────────────
def write_status(stage: str, message: str, extra: dict = None):
    lock = FileLock(LOCK_PATH, timeout=10)
    with lock:
        payload = {"stage": stage, "message": message, "timestamp": datetime.now().isoformat()}
        if extra: payload.update(extra)
        STATUS_PATH.write_text(json.dumps(payload, indent=2, ensure_ascii=False))
    logger.info(f"[{stage}] {message}")

# ──────────────────────────────────────────────────────────────
# PREPROCESSING — dua fungsi terpisah (v4)
# ──────────────────────────────────────────────────────────────
def preprocess_stemmed(text: str) -> str:
    """Untuk data korpus yang SUDAH di-stem oleh prepare_corpus — tidak re-stem."""
    if pd.isna(text) or not isinstance(text, str): return ""
    text = text.lower().strip()
    if text in {"nan", "none", "null", "-", "0", "", "tidak diisi"}: return ""
    text = text.replace('-', ' ').replace('/', ' ').replace('_', ' ').replace(',', ' ')
    text = re.sub(r'[^\w\s]', '', text)
    text = re.sub(r'\d+', '', text)
    words = [w for w in text.split() if w not in STOPWORDS and len(w) > 2]
    return " ".join(words)

def preprocess_raw(text: str) -> str:
    """Untuk data manual_override yang BELUM di-stem — jalankan Sastrawi."""
    if pd.isna(text) or not isinstance(text, str): return ""
    text = text.lower().strip()
    if text in {"nan", "none", "null", "-", "0", "", "tidak diisi"}: return ""
    text = text.replace('-', ' ').replace('/', ' ').replace('_', ' ').replace(',', ' ')
    text = re.sub(r'[^\w\s]', '', text)
    text = re.sub(r'\d+', '', text)
    words = [w for w in text.split() if w not in STOPWORDS and len(w) > 2]
    return " ".join([stemmer.stem(w) for w in words])

# ──────────────────────────────────────────────────────────────
# EVALUASI MODEL
# ──────────────────────────────────────────────────────────────
def evaluate_model(model, X_test: pd.Series, y_test: pd.Series) -> dict:
    if len(X_test) == 0:
        return {"weighted_f1": 0.0, "accuracy": 0.0, "per_class": {}}
    y_pred = model.predict(X_test)
    report = classification_report(y_test, y_pred, output_dict=True, zero_division=0, labels=TARGET_CLASSES)
    return {
        "weighted_f1": round(report.get("weighted avg", {}).get("f1-score", 0.0), 4),
        "accuracy": round(report.get("accuracy", 0.0), 4),
        "per_class": {
            cls: {
                "precision": round(report.get(cls, {}).get("precision", 0.0), 3),
                "recall":    round(report.get(cls, {}).get("recall", 0.0), 3),
                "f1":        round(report.get(cls, {}).get("f1-score", 0.0), 3),
                "support":   int(report.get(cls, {}).get("support", 0)),
            }
            for cls in TARGET_CLASSES
        }
    }

# ──────────────────────────────────────────────────────────────
# BASELINE F1 — re-evaluasi model lama pada test set yang sama
# ──────────────────────────────────────────────────────────────
def get_baseline_f1_on_testset(X_test: pd.Series, y_test: pd.Series) -> float:
    """
    Evaluasi model lama (backup) pada X_test/y_test yang SAMA dengan yang
    akan digunakan mengevaluasi candidate. Ini satu-satunya cara yang adil
    (apples-to-apples) karena distribusi test set berubah setiap retrain.

    Membandingkan F1 baru dengan angka JSON lama (dihitung di distribusi berbeda)
    adalah perbandingan apel vs jeruk — tidak valid untuk keputusan promote/rollback.
    """
    if not PIPELINE_BAK_PATH.exists():
        logger.warning("Backup .bak tidak ditemukan — baseline F1 diasumsikan 0.0")
        return 0.0
    try:
        old_model = joblib.load(PIPELINE_BAK_PATH)
        result = evaluate_model(old_model, X_test, y_test)
        logger.info(f"Baseline F1 (old model, same test set): {result['weighted_f1']:.4f}")
        return result["weighted_f1"]
    except Exception as e:
        logger.warning(f"Gagal load/evaluasi model lama untuk baseline: {e}")
        return 0.0

# ──────────────────────────────────────────────────────────────
# ROLLBACK
# ──────────────────────────────────────────────────────────────
def do_rollback(reason: str, old_f1: float, new_f1: float):
    if CANDIDATE_PATH.exists(): CANDIDATE_PATH.unlink()
    if PIPELINE_BAK_PATH.exists():
        shutil.copy2(PIPELINE_BAK_PATH, PIPELINE_PATH)
        logger.info("Rollback berhasil: pkl lama dipulihkan dari .bak")
    else:
        logger.warning("File .bak tidak ditemukan — pkl aktif dibiarkan.")
    write_status(
        stage="rolled_back",
        message=f"Model lama dipertahankan. {reason}",
        extra={"result": "rolled_back", "reason": reason, "old_f1": old_f1, "new_f1": new_f1}
    )

# ──────────────────────────────────────────────────────────────
# MAIN
# ──────────────────────────────────────────────────────────────
def main(extra_csv_path: str = None):
    write_status("started", "Worker dimulai")

    # ── STEP 1: BACKUP ──────────────────────────────────────
    write_status("backup", "Membuat backup model lama...")
    if not PIPELINE_PATH.exists():
        write_status("failed", "File pkl aktif tidak ditemukan, tidak bisa backup.")
        sys.exit(1)
    shutil.copy2(PIPELINE_PATH, PIPELINE_BAK_PATH)
    logger.info(f"Backup tersimpan: {PIPELINE_BAK_PATH}")

    # baseline_f1 akan dihitung setelah split dinamis terbentuk
    # (evaluasi model lama pada test set yang sama dengan candidate)
    baseline_f1 = 0.0

    try:
        # ── STEP 2: LOAD & MERGE DATA ────────────────────────
        write_status("loading_data", "Memuat dan menggabungkan data training...")

        if not CORPUS_PATH.exists():
            raise FileNotFoundError(f"Corpus asli tidak ditemukan: {CORPUS_PATH}")

        df_corpus = pd.read_csv(CORPUS_PATH, sep=";", dtype=str).dropna(subset=["job_text_raw", "label"])
        df_corpus["features"] = df_corpus["job_text_raw"].apply(preprocess_stemmed)
        df_corpus["_weight"] = 1.0   # Bobot normal untuk data historis
        logger.info(f"Corpus asli: {len(df_corpus)} baris")

        df_extra = pd.DataFrame()
        if extra_csv_path and Path(extra_csv_path).exists():
            df_extra = pd.read_csv(extra_csv_path, sep=";", dtype=str).dropna(subset=["job_text_raw", "label"])
            df_extra = df_extra[df_extra["label"].isin(set(TARGET_CLASSES))]
            df_extra["features"] = df_extra["job_text_raw"].apply(preprocess_raw)
            override_w = compute_override_weight(len(df_corpus), len(df_extra))
            df_extra["_weight"] = override_w

        # Pre-compute total bobot sekali — dipakai di logging & metrics JSON.
        # .sum() lebih benar daripada len() * .iloc[0] karena tidak mengasumsikan
        # homogenitas bobot di seluruh baris (future-proof jika bobot per-baris ditambahkan).
        total_corpus_weight   = df_corpus["_weight"].sum()
        total_override_weight = df_extra["_weight"].sum() if len(df_extra) > 0 else 0.0
        actual_influence_pct  = (
            total_override_weight / (total_corpus_weight + total_override_weight) * 100
            if total_override_weight > 0 else 0.0
        )

        if len(df_extra) > 0:
            logger.info(
                f"Data manual_override: {len(df_extra)} baris | "
                f"weight={override_w:.4f}x (log-damped) | "
                f"influence aktual={actual_influence_pct:.1f}% "
                f"(target nominal {OVERRIDE_INFLUENCE_TARGET*100:.0f}%)"
            )
        else:
            logger.info("Tidak ada data tambahan — menggunakan corpus asli saja")

        # Concat tanpa deduplication — semua frekuensi historis dipertahankan
        df_all = pd.concat([df_corpus, df_extra], ignore_index=True) if len(df_extra) > 0 else df_corpus.copy()

        mask = df_all["features"].str.len() > 0
        df_all = df_all[mask]
        logger.info(f"Total setelah merge (tanpa deduplicate): {len(df_all)} baris")

        if len(df_all) < 20:
            raise ValueError(f"Data terlalu sedikit untuk training: {len(df_all)} baris (minimum 20)")

        X_all = df_all["features"]
        y_all = df_all["label"]
        w_all = df_all["_weight"]

        # ── STEP 3: DYNAMIC SPLIT — selalu dari data gabungan terkini ────
        # Test set statis (test_set.csv) tidak digunakan karena:
        # (a) tidak mencerminkan pola baru dari data override
        # (b) baseline_f1 dari JSON dihitung pada distribusi berbeda →
        #     perbandingan apel vs jeruk, tidak valid untuk keputusan promote.
        # Solusi: split dinamis, lalu evaluasi model LAMA pada test set YANG SAMA.
        X_train, X_test, y_train, y_test, w_train, _ = train_test_split(
            X_all, y_all, w_all, test_size=0.3, random_state=42, stratify=y_all
        )
        logger.info(f"Dynamic split: {len(X_train)} train | {len(X_test)} test")

        # ── STEP 4: BASELINE — evaluasi model LAMA pada test set yang sama ──
        # Ini satu-satunya cara perbandingan yang jujur (apples-to-apples).
        write_status("evaluating", "Mengevaluasi model lama pada test set baru...")
        baseline_f1 = get_baseline_f1_on_testset(X_test, y_test)

        # ── STEP 5: K-FOLD VALIDATION ────────────────────────
        write_status("training", f"K-Fold validation & training... ({len(X_train)} sampel)")
        skf = StratifiedKFold(n_splits=5, shuffle=True, random_state=42)
        fold_metrics = []
        for i, (tr_idx, te_idx) in enumerate(skf.split(X_train, y_train)):
            m = Pipeline([
                ('tfidf', TfidfVectorizer(max_features=3000, ngram_range=(1, 2), sublinear_tf=True, min_df=1)),
                ('clf', LogisticRegression(max_iter=1000, class_weight='balanced', solver='lbfgs'))
            ])
            # sample_weight diteruskan ke step 'clf' via Pipeline naming convention
            m.fit(
                X_train.iloc[tr_idx], y_train.iloc[tr_idx],
                clf__sample_weight=w_train.iloc[tr_idx].values
            )
            y_pred = m.predict(X_train.iloc[te_idx])
            rep = classification_report(y_train.iloc[te_idx], y_pred, output_dict=True, zero_division=0)
            fold_metrics.append(rep)
            write_status("training", f"K-Fold selesai: fold {i+1}/5 | acc={rep['accuracy']:.4f}")

        # ── STEP 6: TRAINING MODEL FINAL ─────────────────────
        write_status("training", "Training model final pada seluruh data training...")
        candidate_model = Pipeline([
            ('tfidf', TfidfVectorizer(max_features=3000, ngram_range=(1, 2), sublinear_tf=True, min_df=1)),
            ('clf', LogisticRegression(max_iter=1000, class_weight='balanced', solver='lbfgs'))
        ])
        candidate_model.fit(X_train, y_train, clf__sample_weight=w_train.values)
        joblib.dump(candidate_model, CANDIDATE_PATH)
        logger.info(f"Candidate model tersimpan: {CANDIDATE_PATH}")

        # ── STEP 7: EVALUASI & KEPUTUSAN ─────────────────────
        write_status("evaluating", "Mengevaluasi model baru vs model lama (test set sama)...")
        new_metrics = evaluate_model(candidate_model, X_test, y_test)
        new_f1  = new_metrics["weighted_f1"]
        delta   = new_f1 - baseline_f1

        logger.info(f"Baseline F1 (old model, same test) : {baseline_f1:.4f}")
        logger.info(f"Candidate F1 (new model, same test): {new_f1:.4f}  (delta: {delta:+.4f})")

        if baseline_f1 == 0.0:
            should_promote = True
            reason = "Model lama tidak bisa dievaluasi (backup tidak ada) → model baru dipromote"
        elif delta >= MIN_IMPROVEMENT_THRESHOLD:
            should_promote = True
            reason = f"Model baru lebih baik (+{delta*100:.2f}% weighted F1)"
        elif delta < -MAX_REGRESSION_ALLOWED:
            should_promote = False
            reason = f"Model baru lebih buruk secara signifikan ({delta*100:.2f}% weighted F1)"
        else:
            should_promote = False
            reason = (
                f"Peningkatan tidak signifikan ({delta*100:.2f}% weighted F1, "
                f"minimum dibutuhkan: +{MIN_IMPROVEMENT_THRESHOLD*100:.1f}%)"
            )

        if not should_promote:
            do_rollback(reason, baseline_f1, new_f1)
            return

        # ── STEP 7: PROMOTE ──────────────────────────────────
        write_status("promoting", "Model baru lebih baik — mempromote model baru...")
        shutil.move(str(CANDIDATE_PATH), str(PIPELINE_PATH))
        logger.info(f"Model baru dipromote: {PIPELINE_PATH}")

        avg_acc = float(np.mean([f["accuracy"] for f in fold_metrics]))
        new_metrics_full = {
            "methodology": "Internal MIF + manual_override (sample_weight, dynamic split)",
            "retrained_at": datetime.now().isoformat(),
            "extra_samples_added": len(df_extra),
            "override_weight_used": round(df_extra["_weight"].iloc[0], 4) if len(df_extra) > 0 else 1.0,
            "override_influence_pct_actual": round(actual_influence_pct, 2),
            "override_influence_target_pct": OVERRIDE_INFLUENCE_TARGET * 100,
            "total_training_samples": len(X_train),
            "k_fold": {
                "accuracy_mean": avg_acc,
                "accuracy_std": float(np.std([f["accuracy"] for f in fold_metrics])),
                "folds": fold_metrics,
            },
            "threshold_config": 0.50,
            "hold_out_test": classification_report(
                y_test, candidate_model.predict(X_test),
                output_dict=True, zero_division=0
            ),
            # Perbandingan fair: kedua model dievaluasi pada test set yang SAMA
            "comparison": {
                "note": "Both models evaluated on identical dynamic test set",
                "old_weighted_f1": baseline_f1,
                "new_weighted_f1": new_f1,
                "delta": round(delta, 4),
            }
        }
        METRICS_PATH.write_text(json.dumps(new_metrics_full, indent=2, ensure_ascii=False))

        write_status(
            stage="promoted",
            message=f"Model baru berhasil dipromote. {reason}",
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
        lock = FileLock(LOCK_PATH, timeout=10)
        with lock:
            status = json.loads(STATUS_PATH.read_text())
            status["stage"] = "failed"
            STATUS_PATH.write_text(json.dumps(status, indent=2, ensure_ascii=False))
        sys.exit(1)


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else None)
