"""
Sakan AI — Serveur FastAPI local pour la détection de dégradations
Modèle : YOLOv8n (sakan_best.pt) — entraîné sur sakan_v2
Port   : 8001  (8000 est réservé au serveur Symfony dev)

Classes détectées par le modèle :
  - crack           : fissure / craquelure
  - stairstep_crack : fissure structurelle en escalier
  - peeling_paint   : peinture écaillée
  - mold            : moisissure
  - water_seepage   : infiltration d'eau

Si le fichier model/sakan_best.pt n'existe pas, le endpoint /detect
retourne {"detections": [], "model_loaded": false} sans crasher.
"""

import os
import base64
import io
import logging

from fastapi import FastAPI
from pydantic import BaseModel

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("sakan_ai")

app = FastAPI(title="Sakan AI Detection Server", version="2.0.0")

# ─── Chargement du modèle ─────────────────────────────────────────────────────

MODEL_PATH = os.path.join(os.path.dirname(__file__), "model", "sakan_best.pt")
model = None

try:
    from ultralytics import YOLO
    from PIL import Image

    if os.path.exists(MODEL_PATH):
        model = YOLO(MODEL_PATH)
        logger.info(f"[Sakan AI] ✅ Modèle chargé : {MODEL_PATH}")
        logger.info(f"[Sakan AI] Classes disponibles : {model.names}")
    else:
        logger.warning(
            f"[Sakan AI] ⚠️  Modèle introuvable : {MODEL_PATH}\n"
            "           Placez sakan_best.pt dans ai_model/model/ pour activer la détection."
        )
except ImportError as e:
    logger.warning(f"[Sakan AI] ⚠️  Dépendances manquantes ({e}). Exécutez : pip install -r requirements.txt")


# ─── Schémas de données ───────────────────────────────────────────────────────

class DetectRequest(BaseModel):
    image: str      # contenu de l'image encodé en base64
    mime_type: str  # ex: "image/jpeg"


class Detection(BaseModel):
    tag: str
    confidence: float


class DetectResponse(BaseModel):
    detections: list[Detection]
    model_loaded: bool


# ─── Endpoint principal /detect ───────────────────────────────────────────────

@app.post("/detect", response_model=DetectResponse)
async def detect(req: DetectRequest) -> DetectResponse:
    """
    Analyse une image en base64 et retourne les dégradations détectées.

    Retour: {"detections": [{"tag": "crack", "confidence": 0.94}, ...], "model_loaded": true}
    """
    if model is None:
        return DetectResponse(detections=[], model_loaded=False)

    try:
        # Décoder l'image base64
        image_bytes = base64.b64decode(req.image)
        image = Image.open(io.BytesIO(image_bytes)).convert("RGB")

        # Inférence YOLOv8 (imgsz=640 correspond à l'entraînement)
        results = model(image, imgsz=640, verbose=False)

        detections = []
        for result in results:
            if result.boxes is None:
                continue
            for box in result.boxes:
                class_id   = int(box.cls[0])
                confidence = float(box.conf[0])
                tag        = model.names.get(class_id, f"class_{class_id}")
                detections.append(
                    Detection(tag=tag, confidence=round(confidence, 4))
                )

        logger.info(f"[Sakan AI] Détections : {[(d.tag, d.confidence) for d in detections]}")
        return DetectResponse(detections=detections, model_loaded=True)

    except Exception as e:
        logger.error(f"[Sakan AI] Erreur inférence : {e}")
        # Fallback silencieux — le serveur ne crashe jamais
        return DetectResponse(detections=[], model_loaded=True)


# ─── Health check ─────────────────────────────────────────────────────────────

@app.get("/health")
async def health():
    """Vérification rapide que le serveur et le modèle sont opérationnels."""
    return {
        "status": "ok",
        "model_loaded": model is not None,
        "model_path": MODEL_PATH,
        "classes": list(model.names.values()) if model is not None else [],
    }


# ─── Démarrage ────────────────────────────────────────────────────────────────

if __name__ == "__main__":
    import uvicorn
    # PORT 8001 — port 8000 est réservé au serveur de développement Symfony
    uvicorn.run(app, host="127.0.0.1", port=8001, log_level="info")
