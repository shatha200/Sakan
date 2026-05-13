# src/AI/main.py
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
import spacy
import os

app = FastAPI(title="Sakan AI NER Service")

# Chargement du modèle
model_path = os.path.join(os.path.dirname(__file__), "ner_model")
try:
    print(f"🔍 Tentative de chargement du modèle depuis : {model_path}")
    nlp = spacy.load(model_path)
    print("✅ Modèle NER chargé avec succès !")
except Exception as e:
    nlp = None
    print(f"❌ Erreur lors du chargement : {e}")
    print("⚠️ Modèle non trouvé. Lancez d'abord train_ner.py")

class ExtractionRequest(BaseModel):
    text: str

@app.post("/extract")
async def extract_info(request: ExtractionRequest):
    if not nlp:
        raise HTTPException(status_code=500, detail="Model not loaded")
    
    if not request.text.strip():
        return {"problem": "N/A", "location": "N/A", "time": "N/A"}

    doc = nlp(request.text)
    
    results = {
        "problem": [],
        "location": []
    }

    for ent in doc.ents:
        label = ent.label_.lower()
        if label in results:
            results[label].append(ent.text)

    # On retourne la première occurence ou une chaîne vide
    return {
        "problem": ", ".join(results["problem"]) if results["problem"] else "Non détecté",
        "location": ", ".join(results["location"]) if results["location"] else "Non précisé"
    }

# Commande pour lancer : uvicorn main:app --port 8080 --reload
