
import spacy
from spacy.tokens import DocBin
from spacy.util import filter_spans
from spacy.training import Example
import random
import os
from dataset import TRAIN_DATA

# Dataset riche (50+ exemples pour une précision optimale)
# Dataset simplifié (PROBLEM & LOCATION uniquement)


def train_model():
    print("🚀 Initialisation de l'entraînement...")
    nlp = spacy.blank("fr")  # Modèle vierge pour le français
    ner = nlp.add_pipe("ner")
    
    # Ajout des labels
    for _, annotations in TRAIN_DATA:
        for ent in annotations.get("entities"):
            ner.add_label(ent[2])

    # Entraînement
    optimizer = nlp.begin_training()
    for i in range(30): # 30 époques
        random.shuffle(TRAIN_DATA)
        losses = {}
        for text, annotations in TRAIN_DATA:
            example = Example.from_dict(nlp.make_doc(text), annotations)
            nlp.update([example], drop=0.35, losses=losses)
        print(f"Époque {i+1} - Pertes: {losses}")

    # Sauvegarde (Chemin absolu par rapport au script)
    save_path = os.path.join(os.path.dirname(__file__), "ner_model")
    if not os.path.exists(save_path):
        os.makedirs(save_path)
    nlp.to_disk(save_path)
    print(f"✅ Modèle sauvegardé dans '{save_path}'")

if __name__ == "__main__":
    train_model()
