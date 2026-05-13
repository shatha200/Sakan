
import spacy
from spacy.training import Example
from dataset import TEST_DATA
import os

def evaluate_model():
    # 1. Charger le modèle entraîné
    model_path = os.path.join(os.path.dirname(__file__), "ner_model")
    if not os.path.exists(model_path):
        print("❌ Erreur : Le modèle n'existe pas. Lancez d'abord train_ner.py")
        return

    print(f"🚀 Chargement du modèle depuis : {model_path}")
    nlp = spacy.load(model_path)

    # 2. Préparer l'évaluation
    examples = []
    for text, annots in TEST_DATA:
        doc = nlp.make_doc(text)
        example = Example.from_dict(doc, annots)
        examples.append(example)

    # 3. Calculer les scores
    results = nlp.evaluate(examples)

    # 4. Afficher les résultats
    print("\n" + "="*40)
    print("📈 RÉSULTATS DE L'ÉVALUATION (Accuracy)")
    print("="*40)
    
    # Scores globaux
    print(f"Global Precision : {results['ents_p']:.2%}")
    print(f"Global Recall    : {results['ents_r']:.2%}")
    print(f"Global F-Score   : {results['ents_f']:.2%}")
    print("-" * 20)

    # Scores par entité
    for label, scores in results['ents_per_type'].items():
        print(f"Label [{label}]:")
        print(f"  - Precision : {scores['p']:.2%}")
        print(f"  - Recall    : {scores['r']:.2%}")
        print(f"  - F-Score   : {scores['f']:.2%}")
    
    print("="*40)
    print("💡 Interprétation : Si le F-score est > 90%, votre modèle est excellent.")

if __name__ == "__main__":
    evaluate_model()
