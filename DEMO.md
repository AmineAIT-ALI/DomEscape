# DomEscape — Mode d'emploi, Démonstration & Tests

> Plateforme d'escape game événementielle sur Raspberry Pi  
> Scénario de démonstration : **Protocol Omega**

---

## Table des matières

1. [Prérequis](#1-prérequis)
2. [Mode d'emploi](#2-mode-demploi)
3. [Script de démonstration — Protocol Omega](#3-script-de-démonstration--protocol-omega)
4. [Tests réalisés](#4-tests-réalisés)

---

## 1. Prérequis

### Infrastructure

| Composant | Détail |
|---|---|
| Raspberry Pi | Allumé, connecté au réseau Wi-Fi local |
| Réseau | Se connecter au Wi-Fi du Pi (`TP01` ou réseau local dédié) |
| URL plateforme | `http://192.168.4.1/domescape/public/` |
| URL site vitrine | `http://192.168.4.1/domescape/website/` |
| Domoticz | Actif sur `http://192.168.4.1:8080` |
| Service LCD | Actif (Python, port 5000) |

### Équipements Z-Wave requis

| Équipement | Rôle |
|---|---|
| Fibaro Button | Triple appui / double appui |
| Capteur de porte | Ouverture / fermeture |
| Multisensor | Détection de mouvement |
| Écran LCD PiFace | Affichage des messages joueur |
| Wall Plug | Prise connectée (feedback physique) |
| Z-Stick (USB) | Contrôleur réseau Z-Wave |

### Vérification avant démonstration

```
1. Pi allumé et joignable sur le réseau
2. Domoticz actif : http://192.168.4.1:8080
3. Service LCD actif : systemctl status domescape-lcd
4. Aucune session en cours sur la plateforme
```

---

## 2. Mode d'emploi

### 2.1 Comptes et rôles

La plateforme distingue deux rôles :

| Rôle | Accès | Usage |
|---|---|---|
| **Administrateur** | Toutes les pages + Game Master | Superviser la partie, configurer les scénarios |
| **Joueur** | Interface joueur uniquement | Jouer la partie |

> Les comptes sont créés via la page **Créer un compte** (`/public/inscription.php`) ou directement en base de données.

---

### 2.2 Connexion

1. Ouvrir `http://192.168.4.1/domescape/public/connexion.php`
2. Saisir l'adresse e-mail et le mot de passe
3. Redirection automatique vers le tableau de bord

---

### 2.3 Tableau de bord (Accueil)

Page `index.php` — accessible après connexion.

**Pour un administrateur :**
- Liste des scénarios actifs
- Bouton **Lancer** pour démarrer une session
- Indicateur de session en cours

**Pour un joueur :**
- Accès à l'interface joueur si une session est en cours

---

### 2.4 Lancer une partie (Admin)

1. Se connecter en tant qu'administrateur
2. Depuis le tableau de bord, choisir le scénario **Protocol Omega**
3. Saisir le nom de l'équipe
4. Cliquer sur **Lancer la partie**
5. La session démarre immédiatement — le LCD affiche le message d'introduction

> Règle mono-salle : une seule session peut être en cours à la fois.

---

### 2.5 Interface joueur (`player.php`)

L'interface joueur s'ouvre automatiquement dès qu'une session est active.

Elle affiche :
- Le **timer** (temps écoulé depuis le début)
- La **progression** par étapes (barre de progression)
- La **consigne de l'étape en cours** (texte descriptif)
- L'état de la partie (en cours / terminée / abandonnée)

L'interface se **synchronise automatiquement** toutes les secondes — aucune action manuelle requise.

---

### 2.6 Interface Game Master (`gamemaster.php`)

Réservée à l'administrateur. Accessible depuis la navigation.

Panels disponibles :
- **Session active** — nom d'équipe, timer, statut
- **Timeline des étapes** — étapes validées / en attente
- **Télémétrie** — température et humidité en direct (Multisensor, mise à jour toutes les 5 minutes)
- **Envoi d'indice** — envoyer un message LCD au joueur à tout moment
- **Actions** — Réinitialiser ou abandonner la session

---

### 2.7 Administration des scénarios (`admin/scenarios.php`)

Accessible uniquement en tant qu'administrateur.

Fonctionnalités :
- Lister les scénarios existants
- Créer / modifier un scénario (`scenario_edit.php`)
- Configurer les étapes, les capteurs attendus et les actions associées
- Tout est stocké en base — aucune modification du code nécessaire

---

### 2.8 Déconnexion

Cliquer sur **Déconnexion** dans la barre de navigation → redirection vers la page de connexion.

---

## 3. Script de démonstration — Protocol Omega

**Durée estimée : 5 à 10 minutes**  
**Participants : 1 démonstrateur + 1 joueur (ou le même)**

### Préparation (avant de démarrer)

- [ ] Pi allumé, réseau opérationnel
- [ ] Domoticz actif, équipements Z-Wave détectés
- [ ] Service LCD actif, écran allumé
- [ ] Ouvrir l'interface Game Master sur un écran (admin)
- [ ] Ouvrir l'interface joueur sur un second écran (ou même machine, onglet séparé)
- [ ] Aucune session active en cours

---

### Étape 0 — Lancement de la session

**Action :** Administrateur → tableau de bord → sélectionner **Protocol Omega** → saisir un nom d'équipe → **Lancer la partie**

**Ce qui se passe :**
- La session s'ouvre en base de données
- Le LCD affiche le message d'introduction du scénario
- L'interface joueur passe en mode "partie en cours"
- Le timer démarre

---

### Étape 1 — Triple appui (Fibaro Button)

**Consigne affichée au joueur :** *"Initialisez le système — triple appui sur le déclencheur."*

**Action physique :** Appuyer trois fois rapidement sur le **Fibaro Button**

**Ce qui se passe :**
- Domoticz détecte le triple appui (idx 27 ou 30)
- dzVents envoie un webhook au moteur PHP
- EventManager valide l'événement et transmet à GameEngine
- GameEngine valide l'étape 1
- ActionManager envoie un nouveau message au LCD
- L'interface joueur avance à l'étape suivante

---

### Étape 2 — Ouverture de porte (Capteur de porte)

**Consigne affichée au joueur :** *"Ouvrez le sas de confinement."*

**Action physique :** Ouvrir la porte équipée du **capteur magnétique**

**Ce qui se passe :**
- Domoticz détecte DOOR_OPEN (idx 25)
- Pipeline identique : dzVents → EventManager → GameEngine → ActionManager
- LCD met à jour le message
- Wall Plug s'allume (feedback physique)
- Progression joueur avance

---

### Étape 3 — Détection de mouvement (Multisensor)

**Consigne affichée au joueur :** *"Traversez la zone de détection."*

**Action physique :** Se déplacer devant le **Multisensor**

**Ce qui se passe :**
- Domoticz détecte MOTION_DETECTED (idx 7)
- GameEngine valide l'étape 3
- LCD affiche la consigne suivante

---

### Étape 4 — Double appui (Fibaro Button)

**Consigne affichée au joueur :** *"Confirmez l'accès — double appui."*

**Action physique :** Appuyer deux fois sur le **Fibaro Button**

**Ce qui se passe :**
- GameEngine valide l'étape 4
- ActionManager déclenche le Wall Plug (allumage ou clignotement selon config)
- LCD affiche le message de fin

---

### Étape 5 — Fin de partie

**Ce qui se passe automatiquement :**
- La session passe au statut `terminée`
- L'interface joueur affiche l'écran de victoire avec le temps final
- Le Game Master voit la timeline complète
- Toutes les actions sont tracées en base (audit complet)

---

### Points à montrer au jury pendant la démo

1. **Pipeline en temps réel** — montrer le Game Master pendant qu'une étape est validée physiquement
2. **LCD réactif** — montrer l'écran physique changer de message à chaque étape
3. **Wall Plug** — montrer la prise s'activer physiquement
4. **Télémétrie** — montrer les valeurs température/humidité en direct sur le Game Master
5. **Traçabilité** — après la démo, montrer la timeline complète des événements

---

## 4. Tests réalisés

### 4.1 Tests pipeline événementiel

| Test | Résultat |
|---|---|
| Simple appui Fibaro Button → webhook reçu | ✓ |
| Double appui Fibaro Button → événement distinct | ✓ |
| Triple appui Fibaro Button → événement distinct | ✓ |
| Ouverture porte → DOOR_OPEN détecté | ✓ |
| Fermeture porte → DOOR_CLOSE détecté | ✓ |
| Mouvement → MOTION_DETECTED déclenché | ✓ |
| Debounce 500ms → double événement filtré | ✓ |

### 4.2 Tests moteur de scénarios

| Test | Résultat |
|---|---|
| Étape validée dans l'ordre → avancement correct | ✓ |
| Événement hors séquence → ignoré (étape non avancée) | ✓ |
| Session déjà en cours → blocage nouvelle session | ✓ |
| Réinitialisation session → état remis à zéro | ✓ |
| Abandon de session → statut `abandonnée` en base | ✓ |

### 4.3 Tests actionneurs

| Test | Résultat |
|---|---|
| LCD PiFace → message affiché à chaque étape | ✓ |
| Wall Plug → allumage déclenché par ActionManager | ✓ |
| Envoi d'indice Game Master → message LCD immédiat | ✓ |

### 4.4 Tests interface

| Test | Résultat |
|---|---|
| Interface joueur — synchronisation automatique | ✓ |
| Game Master — mise à jour temps réel | ✓ |
| Timer — décompte précis depuis le début de session | ✓ |
| Connexion / déconnexion joueur | ✓ |
| Connexion / déconnexion admin | ✓ |
| Création de compte joueur | ✓ |

### 4.5 Tests robustesse

| Test | Résultat |
|---|---|
| Perte de connexion joueur → reconnexion automatique | ✓ |
| Événement Z-Wave en doublon (< 500ms) → filtré | ✓ |
| Requête non authentifiée sur API → rejetée (401) | ✓ |
| Webhook sans token valide → rejeté (403) | ✓ |

---

*DomEscape — Projet MIAGE 2025-2026*
