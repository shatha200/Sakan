# 📋 PLAN D'INTÉGRATION INTELLIGENT — Module Contrat

## 🎯 OBJECTIF
Intégrer le module contrat de l'ami dans le projet fusionné (Finance + Authentification) de façon à ce que les interfaces contrat soient **dynamiques et fonctionnelles**, pas hardcodées.

---

## 📊 ANALYSE DE LA SITUATION ACTUELLE

### Votre Projet Actuel (gest_paiment_web)
| Module | Statut | Détail |
|--------|--------|--------|
| **Finance** | ✅ Complet | Loyers, charges, cautions, validation |
| **Authentification** | ✅ Complet | Google Auth, sécurité (ami Ahmed) |
| **Contrat** | ⚠️ Hardcodé | Entité basique, templates statiques |

### Entité Contrat Actuelle
```php
Contrat
├── id (int)
├── annonce (ManyToOne) → lien bien
├── locataire (ManyToOne) → lien user
├── date_debut (string)
├── date_fin (string)
├── montant (string)
└── statut (string)
```

**Problèmes identifiés :**
- ❌ Pas de relation avec paiements (PaiementLoyer)
- ❌ Pas de relation avec charges (ChargesMensuelles)
- ❌ Pas de champ pour documents/contrat PDF
- ❌ Pas de gestion des signatures
- ❌ Pas d'historique des modifications

---

## 🔍 ANALYSE BRANCHE AMI (gest_contrat_web)

### Hypothèses sur le travail de l'ami
Basé sur l'entité Contrat existante, l'ami a probablement travaillé sur :

1. **ContratController** — CRUD contrats
2. **Templates dynamiques** — Liste, détail, création, édition
3. **Workflow de signature** — Processus de validation contrat
4. **Génération PDF** — Contrats générés automatiquement
5. **Relations métier** — Lien avec biens, locataires, propriétaires

---

## 🧠 STRATÉGIE D'INTÉGRATION INTELLIGENTE

### Phase 1: Analyse Pré-Merge (ÉTAT ACTUEL)
```
┌─────────────────────────────────────────────────────────┐
│  VOTRE BRANCHE (gest_paiment_web)                      │
│  ├── Module Finance (Loyers, Charges, Cautions)       │
│  ├── Module Authentification (Google, Sécurité)       │
│  └── Contrat (Entité basique, templates hardcodés)      │
└─────────────────────────────────────────────────────────┘
                           ↓
                    MERGE intelligente
                           ↓
┌─────────────────────────────────────────────────────────┐
│  BRANCHE AMI (gest_contrat_web)                        │
│  ├── ContratController (CRUD dynamique)               │
│  ├── Templates contrat (fonctionnels)                 │
│  ├── Workflow signature                               │
│  └── Génération PDF                                   │
└─────────────────────────────────────────────────────────┘
```

### Phase 2: Plan de Fusion

#### Étape 1: Préparation (AVANT merge)
- [ ] Sauvegarde branche actuelle
- [ ] Identifier fichiers communs (Contrat.php)
- [ ] Planifier enrichissement entité Contrat

#### Étape 2: Enrichissement Entité Contrat
**Ajouter les relations manquantes :**
```php
// Relations avec module Finance
#[OneToMany(mappedBy: 'contrat', targetEntity: PaiementLoyer::class)]
private Collection $paiements;

#[OneToMany(mappedBy: 'contrat', targetEntity: ChargesMensuelles::class)]
private Collection $charges;

#[OneToOne(mappedBy: 'contrat', targetEntity: Caution::class)]
private ?Caution $caution = null;

// Nouveaux champs pour workflow
#[Column(type: 'string', nullable: true)]
private ?string $documentPath = null; // PDF contrat signé

#[Column(type: 'datetime', nullable: true)]
private ?\DateTimeInterface $dateSignature = null;

#[Column(type: 'string', nullable: true)]
private ?string $signataireLocataire = null;

#[Column(type: 'string', nullable: true)]
private ?string $signataireProprietaire = null;
```

#### Étape 3: Merge Sélectif
**Stratégie : Garder le meilleur des deux branches**

| Fichier | Votre version | Version ami | Décision |
|---------|--------------|-------------|----------|
| `Contrat.php` | Basique | Enrichie ? | **Fusion** — Garder structure + ajouter relations finance |
| `ContratController.php` | N'existe pas | CRUD complet | **Prendre version ami** — Adapter aux services existants |
| Templates contrat | Hardcodés | Dynamiques | **Prendre version ami** — Intégrer avec layouts existants |
| `ContratRepository.php` | Basique | Méthodes spécifiques ? | **Fusion** — Combiner méthodes |

#### Étape 4: Intégration avec Modules Existants

**1. Intégration Module Finance :**
```php
// Dans ContratController — Méthode dashboard contrat
public function contratDashboard(int $contratId): array
{
    $contrat = $this->contratRepository->find($contratId);
    
    return [
        'contrat' => $contrat,
        'paiements' => $this->paiementLoyerRepo->findByContrat($contratId),
        'charges' => $this->chargesMensuellesRepo->findByContrat($contratId),
        'caution' => $this->cautionRepo->findOneBy(['contrat' => $contratId]),
        'solde_financier' => $this->calculerSolde($contratId),
    ];
}
```

**2. Intégration Module Authentification :**
```php
// Sécurité — Vérifier que l'utilisateur a droit au contrat
#[IsGranted('ROLE_LOCATAIRE')]
public function voirContrat(int $id): Response
{
    $user = $this->getUser();
    $contrat = $this->contratService->getContratForUser($id, $user);
    
    // Vérification via UserSecurityStateService
    if (!$this->securityService->canAccessContrat($user, $contrat)) {
        throw $this->createAccessDeniedException();
    }
    
    // ... affichage
}
```

#### Étape 5: Templates Dynamiques

**Structure des templates à créer :**
```
templates/
├── contrat/
│   ├── liste.html.twig          # Liste des contrats (dynamique)
│   ├── detail.html.twig         # Vue détaillée avec onglets
│   ├── _finance_panel.html.twig # Panel finance intégré
│   ├── _signatures.html.twig    # Workflow signature
│   └── pdf.html.twig            # Template génération PDF
```

**Intégration sidebar :**
- Locataire : "Mes Contrats" → Liste + accès finance
- Propriétaire : "Gestion Contrats" → Création, édition, suivi
- Admin : "Tous les contrats" → Vue globale

---

## 🚀 PLAN D'ACTION DÉTAILLÉ

### JOUR 1: Préparation & Analyse
- [ ] 1.1 Créer branche de travail `integration-contrat`
- [ ] 1.2 Comparer entités Contrat (votre vs ami)
- [ ] 1.3 Lister tous les fichiers modifiés par l'ami
- [ ] 1.4 Documenter les dépendances

### JOUR 2: Enrichissement Entité
- [ ] 2.1 Ajouter relations PaiementLoyer, ChargesMensuelles, Caution
- [ ] 2.2 Ajouter champs workflow (signature, documentPath)
- [ ] 2.3 Créer migration Doctrine
- [ ] 2.4 Tester entité enrichie

### JOUR 3: Merge Controller & Services
- [ ] 3.1 Intégrer ContratController de l'ami
- [ ] 3.2 Adapter aux services Finance existants
- [ ] 3.3 Injecter SecurityNotificationService pour alertes
- [ ] 3.4 Créer ContratFinanceService (lien finance-contrat)

### JOUR 4: Templates & UI
- [ ] 4.1 Créer template liste contrats (dynamique)
- [ ] 4.2 Créer template détail avec onglets
- [ ] 4.3 Intégrer panel finance dans vue contrat
- [ ] 4.4 Adapter aux layouts existants (base_locataire, base_owner, base_admin)

### JOUR 5: Tests & Validation
- [ ] 5.1 Test création contrat → génère paiements automatiques
- [ ] 5.2 Test signature workflow
- [ ] 5.3 Test accès sécurisé (différents rôles)
- [ ] 5.4 Test génération PDF

---

## 🔌 POINTS D'INTÉGRATION CLÉS

### 1. Module Finance → Contrat
```php
// Service: ContratFinanceService
class ContratFinanceService
{
    public function creerPaiementsAutomatiques(Contrat $contrat): void
    {
        // Générer les paiements de loyer mensuels
        // Basé sur date_debut, date_fin, montant
    }
    
    public function calculerSoldeContrat(int $contratId): float
    {
        // Total payé - Total dû
        // Inclure loyers + charges - paiements reçus
    }
}
```

### 2. Module Authentification → Contrat
```php
// Vérification droits d'accès
class ContratSecurityService
{
    public function canView(Utilisateur $user, Contrat $contrat): bool
    {
        return $user === $contrat->getLocataire() 
            || $user === $contrat->getAnnonce()->getProprietaire();
    }
    
    public function canSign(Utilisateur $user, Contrat $contrat): bool
    {
        // Vérifier 2FA si activé
        // Vérifier email vérifié
        return $this->userSecurityState->isVerified($user);
    }
}
```

### 3. Sidebar Navigation
```twig
{# base_locataire.html.twig #}
<li class="nav-item">
    <a href="{{ path('locataire_contrats') }}" class="nav-link">
        <i class="fas fa-file-contract"></i> Mes Contrats
    </a>
</li>
```

---

## 📈 BÉNÉFICES ATTENDUS

| Avant | Après |
|-------|-------|
| Templates hardcodés | ✅ Données dynamiques depuis BDD |
| Pas de lien finance | ✅ Vue financière intégrée dans contrat |
| Pas de workflow | ✅ Processus signature électronique |
| Navigation statique | ✅ Menu contrat dynamique selon rôle |
| Contrats isolés | ✅ Intégration complète écosystème |

---

## ⚠️ RISQUES & MITIGATION

| Risque | Mitigation |
|--------|------------|
| Conflits de merge | Utiliser `git merge-tree` pour prévisualiser |
| Pertes données | Backup base de données avant migration |
| Régression finance | Tests automatisés sur paiements/charges |
| Incohérence UI | Vérifier conformité design system existant |

---

## ✅ PROCHAINES ÉTAPES IMMÉDIATES

1. **Voulez-vous que je récupère la branche `gest_contrat_web` et analyse exactement ce que contient le travail de votre ami ?**

2. **Je créerai ensuite la branche d'intégration et commencerai l'enrichissement de l'entité Contrat.**

3. **En parallèle, je mergerai intelligemment les controllers et templates.**

**Dites-moi si vous validez ce plan et je commence l'implémentation immédiatement !**
