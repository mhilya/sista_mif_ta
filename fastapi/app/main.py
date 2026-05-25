from fastapi import FastAPI, UploadFile, File, HTTPException, BackgroundTasks, Depends, Security
from fastapi.security import HTTPBearer, HTTPAuthorizationCredentials
from fastapi.responses import JSONResponse
import pandas as pd
import numpy as np
import joblib
import re
import io
import json
import uuid
import shutil
import subprocess
import sys
import os
import logging
from contextlib import asynccontextmanager
from pathlib import Path
from typing import List, Dict, Any
from Sastrawi.Stemmer.StemmerFactory import StemmerFactory


# KONFIGURASI & KONSTANTA

BASE_DIR = Path(__file__).parent.parent
ML_DIR = BASE_DIR / "ml_assets"
PIPELINE_PATH      = ML_DIR / "ml_pipeline_internal.pkl"
STATUS_PATH        = ML_DIR / "retrain_status.json"
RETRAIN_WORKER     = Path(__file__).parent / "retrain_worker.py"
TEMP_DIR           = ML_DIR / "tmp"
TEMP_DIR.mkdir(parents=True, exist_ok=True)
CONFIDENCE_THRESHOLD = 0.50
TARGET_CLASSES = ["Programmer", "Data Analyst", "Wirausaha Informatika", "Non-IT"]

KEYWORD_RULES = {
    "Programmer": ["programmer", "developer", "engineer", "fullstack", "backend", "frontend", "mobile", "android", "ios", "software", "web dev", "coding", "it staff", "teknisi", "sistem informasi", "application", "network", "devops", "qa", "tester", "ui", "ux", "swe"],
    "Data Analyst": ["data analyst", "analis data", "data science", "business analyst", "research", "statistik", "bi analyst", "reporting", "database", "sql", "etl", "data engineer", "big data", "analyst", "data mining", "machine learning", "data visual", "power bi", "tableau", "looker", "business intelligence", "bi developer", "data warehouse"],
    "Wirausaha Informatika": ["founder", "owner", "ceo", "wiraswasta", "startup", "freelance", "freelancer", "wirausaha", "bisnis", "usaha mandiri", "konsultan", "co founder", "entrepreneur", "self employed", "owner toko", "usaha", "dagang online", "tokopedia", "shopee", "dropship", "reseller"]
}

F5C_MAP = {"1": "founder owner wirausaha startup", "2": "co-founder partner wirausaha", "3": "staff karyawan pegawai", "4": "freelance kerja lepas lepasan"}
F1101_MAP = {"1": "instansi pemerintah dinas kementerian", "2": "non-profit lsm yayasan", "3": "perusahaan swasta corporate", "4": "wiraswasta usaha mandiri", "6": "bumn bumd pemerintah", "7": "multilateral internasional"}

logging.basicConfig(level=logging.INFO, format="%(asctime)s | %(levelname)s | %(message)s")
logger = logging.getLogger("tracer_worker")
stemmer = StemmerFactory().create_stemmer()

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


# MODEL LOADING
@asynccontextmanager
async def lifespan(app: FastAPI):
    try:
        app.state.pipeline = joblib.load(PIPELINE_PATH)
        logger.info(f" ML Pipeline loaded successfully from {PIPELINE_PATH}")
    except Exception as e:
        logger.error(f" Failed to load ML pipeline: {e}")
        app.state.pipeline = None
    yield

app = FastAPI(title="Tracer Study Classification Worker", lifespan=lifespan)

# --- SECURITY SETUP ---
security = HTTPBearer()
EXPECTED_TOKEN = os.getenv("FASTAPI_SECRET_KEY", "")

def verify_token(credentials: HTTPAuthorizationCredentials = Security(security)):
    if not EXPECTED_TOKEN:
        return credentials.credentials
    if credentials.credentials != EXPECTED_TOKEN:
        raise HTTPException(status_code=401, detail="Unauthorized - Invalid Token")
    return credentials.credentials
# ----------------------

def clean_text(text: str) -> str:
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

def find_column_by_code(df_columns: list, code: str) -> str | None:
    for col in df_columns:
        if code.lower() in col.lower():
            return col
    return None

def safe_get(row: pd.Series, df_columns: list, code: str, default: str = "") -> str:
    actual_col = find_column_by_code(df_columns, code)
    if actual_col and actual_col in row:
        val = row[actual_col]
        if pd.isna(val) or val is None:
            return default
        return str(val).strip()
    return default


# CLASSIFICATION LOGIC

def classify_rule(job_text: str) -> dict:
    clean = clean_text(job_text)
    if not clean or len(clean) < 3:
        return {"profile": "Tidak Diketahui", "confidence": 0.65, "method": "rule_based_fallback"}
    
    for profile, keywords in KEYWORD_RULES.items():
        if any(kw in clean for kw in keywords):
            return {"profile": profile, "confidence": 0.65, "method": "rule_based"}
    return None

def classify_ml(job_text: str, pipeline) -> dict:
    clean = clean_text(job_text)
    if not clean:
        return {"profile": "Tidak Diketahui", "confidence": 0.0, "method": "ml_empty_input"}
    try:
        proba = pipeline.predict_proba([clean])[0]
        max_conf = float(np.max(proba))
        pred_class = pipeline.classes_[np.argmax(proba)]
        method = "ml_fallback" if max_conf >= CONFIDENCE_THRESHOLD else "manual_review"
        return {"profile": pred_class, "confidence": round(max_conf, 4), "method": method}
    except Exception as e:
        logger.error(f"ML_INFER_ERROR | job_text='{job_text[:50]}' | err={e}")
        raise HTTPException(status_code=500, detail=f"ML inference failed: {str(e)}")

def extract_kemendik_text(row: pd.Series, df_columns: list) -> str:
    f5b_col = find_column_by_code(df_columns, "f5b")
    f5c_col = find_column_by_code(df_columns, "f5c")
    f1101_col = find_column_by_code(df_columns, "f1101")
    f1102_col = find_column_by_code(df_columns, "f1102")
    f5b = clean_text(row.get(f5b_col, "") if f5b_col else "")
    f1102 = clean_text(row.get(f1102_col, "") if f1102_col else "")
    f5c_raw = str(row.get(f5c_col, "")).strip() if f5c_col else ""
    f5c_code = re.match(r'^(\d+)', f5c_raw)
    f5c_text = F5C_MAP.get(f5c_code.group(1), "") if f5c_code else ""
    f1101_raw = str(row.get(f1101_col, "")).strip() if f1101_col else ""
    f1101_code = re.match(r'^(\d+)', f1101_raw)
    f1101_text = F1101_MAP.get(f1101_code.group(1), "") if f1101_code else ""
    
    return " ".join(p for p in [f5b, f5c_text, f1101_text, f1102] if p).strip()


# SOURCE DETECTION

def detect_source(df: pd.DataFrame) -> str:

    cols_lower = [c.lower().strip() for c in df.columns]
    kemendik_codes = ["f5b", "f5c", "f8", "f1101", "nimhsmsmh", "nmmhsmsmh"]
    for col in cols_lower:
        if any(code in col for code in kemendik_codes):
            return "kemendik"
    if any("jabatan" in c for c in cols_lower):
        return "internal_mif"
    
    return "unknown"


# ROUTES
@app.get("/health")
def health_check():
    return {"status": "healthy", "pipeline_loaded": app.state.pipeline is not None}



# RE-TRAINING ENDPOINTS
@app.post("/api/v1/retrain", dependencies=[Depends(verify_token)])
async def trigger_retrain(file: UploadFile = File(None)):
    if STATUS_PATH.exists():
        try:
            current = json.loads(STATUS_PATH.read_text())
            if current.get("stage") in ["started", "backup", "loading_data",
                                         "preprocessing", "training", "evaluating", "promoting"]:
                raise HTTPException(
                    status_code=409,
                    detail="Re-training sedang berjalan. Tunggu hingga selesai."
                )
        except HTTPException:
            raise
        except Exception:
            pass
    extra_csv_path = ""
    if file and file.filename:
        job_id = str(uuid.uuid4())[:8]
        extra_csv_path = str(TEMP_DIR / f"extra_{job_id}.csv")
        contents = await file.read()
        Path(extra_csv_path).write_bytes(contents)
        logger.info(f"Extra CSV disimpan: {extra_csv_path}")

    STATUS_PATH.write_text(json.dumps({
        "stage": "started",
        "message": "Memulai proses re-training...",
        "timestamp": __import__('datetime').datetime.now().isoformat(),
    }, ensure_ascii=False))

    cmd = [sys.executable, str(RETRAIN_WORKER)]
    if extra_csv_path:
        cmd.append(extra_csv_path)

    try:
        subprocess.Popen(
            cmd,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            start_new_session=True
        )
        logger.info(f"Retrain subprocess spawned: {' '.join(cmd)}")
    except Exception as e:
        STATUS_PATH.write_text(json.dumps({
            "stage": "failed",
            "message": f"Gagal spawn subprocess: {str(e)}",
        }, ensure_ascii=False))
        raise HTTPException(status_code=500, detail=f"Gagal memulai training: {str(e)}")

    return JSONResponse(content={"status": "started", "message": "Re-training dimulai di background."})


@app.get("/api/v1/retrain/status", dependencies=[Depends(verify_token)])
def retrain_status():
    if not STATUS_PATH.exists():
        return JSONResponse(content={"stage": "idle", "message": "Belum ada proses re-training."})

    try:
        data = json.loads(STATUS_PATH.read_text())
    except Exception:
        return JSONResponse(content={"stage": "unknown", "message": "Status tidak terbaca."})

    terminal_stages = {"promoted", "rolled_back", "failed"}
    if data.get("stage") in terminal_stages and not data.get("_reloaded"):
        try:
            app.state.pipeline = joblib.load(PIPELINE_PATH)
            data["_reloaded"] = True
            STATUS_PATH.write_text(json.dumps(data, indent=2, ensure_ascii=False))
            logger.info(f"Pipeline di-reload setelah retrain (stage={data['stage']})")
        except Exception as e:
            logger.error(f"Gagal reload pipeline: {e}")

    return JSONResponse(content=data)


@app.post("/api/v1/retrain/reload", dependencies=[Depends(verify_token)])
def reload_model():
    try:
        app.state.pipeline = joblib.load(PIPELINE_PATH)
        logger.info("Pipeline di-reload secara manual.")
        return JSONResponse(content={"status": "ok", "message": "Model berhasil di-reload."})
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Gagal reload model: {str(e)}")

@app.post("/api/v1/classify", dependencies=[Depends(verify_token)])
async def classify_tracer(file: UploadFile = File(...)):
    if not file.filename:
        raise HTTPException(status_code=400, detail="No file provided")
    
    try:
        contents = await file.read()
        
        if file.filename.endswith(".xlsx") or file.filename.endswith(".xls"):
            try:
                df = pd.read_excel(io.BytesIO(contents), dtype=str, engine="openpyxl")
            except ImportError:
                logger.error("openpyxl not installed")
                raise HTTPException(status_code=500, detail="Server misconfiguration: openpyxl missing")
            except Exception as excel_err:
                logger.error(f"Excel parsing error: {excel_err}")
                try:
                    df = pd.read_csv(io.BytesIO(contents), sep=";", encoding="utf-8-sig", dtype=str)
                except:
                    df = pd.read_csv(io.BytesIO(contents), sep=";", encoding="latin-1", dtype=str)
        else:
            try:
                df = pd.read_csv(io.BytesIO(contents), sep=";", encoding="utf-8-sig", dtype=str)
            except UnicodeDecodeError:
                df = pd.read_csv(io.BytesIO(contents), sep=";", encoding="latin-1", dtype=str)
        
        df.columns = df.columns.str.strip()
        
    except Exception as e:
        logger.error(f"File parsing error: {type(e).__name__}: {str(e)}")
        raise HTTPException(status_code=400, detail=f"Failed to parse file: {type(e).__name__}: {str(e)[:200]}")
    
    source_type = detect_source(df)
    if source_type == "unknown":
        raise HTTPException(status_code=400, detail="Unrecognized file format.")
    
    if source_type == "internal_mif" and app.state.pipeline is None:
        logger.error("ML pipeline not loaded")
        raise HTTPException(status_code=503, detail="ML model not available.")
    
    results = []
    pipeline = app.state.pipeline
    logger.info(f"Processing {len(df)} rows | Source: {source_type}")

    find_col_cached = lambda p: next((c for c in df.columns if p.lower() in c.lower()), None)
    col_f5a1 = find_col_cached("f5a1")
    col_f5c = find_col_cached("f5c")
    col_f1101 = find_col_cached("f1101")
    col_f5b = find_col_cached("f5b")
    col_f1102 = find_col_cached("f1102")
    col_nim_kem = find_col_cached("nimhsmsmh")
    col_nama_kem = find_col_cached("nmmhsmsmh")
    col_tahun_kem = find_col_cached("tahun_lulus")
    col_nim_int = find_col_cached("nim")
    col_nama_int = find_col_cached("nama_lengkap")
    col_tahun_int = find_col_cached("tahun_lulus")

    for _, row in df.iterrows():
        try:
            def safe_str(val):
                if pd.isna(val) or val is None: return ""
                return str(val).strip()

            if source_type == "kemendik":
                nim = safe_str(row.get(col_nim_kem))
                nama = safe_str(row.get(col_nama_kem))
                tahun = safe_str(row.get(col_tahun_kem))
            else:
                nim = safe_str(row.get(col_nim_int))
                nama = safe_str(row.get(col_nama_int))
                tahun = safe_str(row.get(col_tahun_int))

            if source_type == "kemendik":
                job_text = extract_kemendik_text(row, df.columns.tolist())
                raw_data_payload = {str(k): safe_str(v) for k, v in row.to_dict().items()}
                raw_data_payload.update({
                    "F5a1": safe_str(row.get(col_f5a1)),
                    "F5c": safe_str(row.get(col_f5c)),
                    "F1101": safe_str(row.get(col_f1101)),
                    "F5b": safe_str(row.get(col_f5b)),
                    "F1102": safe_str(row.get(col_f1102))
                })
                results.append({
                    "nim": nim, "nama": nama, "tahun_lulus": tahun,
                    "job_text_raw": job_text, "source_type": "kemendik",
                    "predicted_profile": None, "confidence_score": None,
                    "classification_method": "dashboard_only", "status": "processed",
                    "raw_data": raw_data_payload
                })
            else:
                col_jabatan = find_col_cached("jabatan")
                col_perusahaan = find_col_cached("perusahaan")
                col_deskripsi = find_col_cached("deskripsi")
                
                job_text = clean_text(safe_str(row.get(col_jabatan))) if col_jabatan else ""
                if not job_text and col_perusahaan and col_deskripsi:
                    job_text = clean_text(f"{safe_str(row.get(col_perusahaan))} {safe_str(row.get(col_deskripsi))}".strip())
                elif not job_text and col_perusahaan:
                    job_text = clean_text(safe_str(row.get(col_perusahaan)))
                
                rule_res = classify_rule(job_text)
                res = rule_res or (classify_ml(job_text, pipeline) if pipeline else {"profile": "Non-IT", "confidence": 0.0, "method": "ml_unavailable"})
                status = "auto_classified" if res["method"] in ["rule_based", "ml_fallback"] or res["profile"] == "Tidak Diketahui" else "needs_review"
                
                raw_data_payload = {str(k): safe_str(v) for k, v in row.to_dict().items()}
                
                results.append({
                    "nim": nim, "nama": nama, "tahun_lulus": tahun,
                    "job_text_raw": job_text, "source_type": "internal_mif",
                    "predicted_profile": res["profile"], "confidence_score": res["confidence"],
                    "classification_method": res["method"], "status": status,
                    "raw_data": raw_data_payload
                })
        except Exception as row_err:
            logger.warning(f"Row error: {row_err}")
            results.append({"nim": nim if 'nim' in locals() else "", "status": "failed", "error_detail": str(row_err)[:100]})

    return JSONResponse(content={"status": "success", "total_rows": len(df), "processed_rows": len(results), "source_type": source_type, "results": results})

if __name__ == "__main__":
    import uvicorn
    uvicorn.run("app.main:app", host="127.0.0.1", port=8000, reload=True)