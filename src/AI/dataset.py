import random

problems = [
    "fuite d’eau", "panne électrique", "problème de chauffage",
    "bruit", "problème de plomberie", "chauffage en panne",
    "robinet cassé", "pas d’eau chaude", "sol sale", "plafond humide"
]

locations = [
    "cuisine", "chambre", "salon",
    "salle de bain", "appartement", "entrée"
]



def generate_sentence(problem, location):
    structures = [
        f"J'ai un {problem} dans la {location}",
        f"Il y a un {problem} au niveau de la {location}",
        f"Ma {location} a un {problem}",
        f"Urgent : {problem} {location}",
        f"Je vous contacte pour un {problem} situé dans ma {location}",
        f"C'est la catastrophe, {problem} dans ma {location} !",
        f"Pouvez-vous réparer le {problem} de la {location} ?",
        f"Le {problem} se trouve dans la {location}.",
        f"Merci d'intervenir pour {problem} {location}."
    ]
    return random.choice(structures)

def find_span(text, substring):
    start = text.find(substring)
    if start == -1: return None
    end = start + len(substring)
    return (start, end)

TRAIN_DATA = []

for _ in range(300):
    problem = random.choice(problems)
    location = random.choice(locations)
    text = generate_sentence(problem, location)
    p_span = find_span(text, problem)
    l_span = find_span(text, location)
    if p_span and l_span:
        TRAIN_DATA.append((text, {"entities": [
            (p_span[0], p_span[1], "PROBLEM"),
            (l_span[0], l_span[1], "LOCATION")
        ]}))

TEST_DATA = []
for _ in range(50):
    problem = random.choice(problems)
    location = random.choice(locations)
    text = generate_sentence(problem, location)
    p_span = find_span(text, problem)
    l_span = find_span(text, location)
    if p_span and l_span:
        TEST_DATA.append((text, {"entities": [
            (p_span[0], p_span[1], "PROBLEM"),
            (l_span[0], l_span[1], "LOCATION")
        ]}))