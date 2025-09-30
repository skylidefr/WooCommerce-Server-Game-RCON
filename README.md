# WooCommerce Server Game RCON - WCSCR

<div align="center">

![WordPress](https://img.shields.io/badge/WordPress-5.0+-21759B?style=for-the-badge&logo=wordpress&logoColor=white)
![WooCommerce](https://img.shields.io/badge/WooCommerce-6.0+-96588A?style=for-the-badge&logo=woocommerce&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-7.4+-777BB4?style=for-the-badge&logo=php&logoColor=white)
![Version](https://img.shields.io/github/v/release/skylidefr/WooCommerce-Server-Game-RCON?style=for-the-badge)
![License](https://img.shields.io/badge/License-GPLv2+-4E9A06?style=for-the-badge)

**Plugin WooCommerce pour automatiser l'envoi de commandes RCON aux serveurs de jeux lors des achats**

[Installation](#installation) • [Utilisation](#utilisation) • [Fonctionnalités](#fonctionnalités) • [Configuration](#configuration) • [Contribuer](#contribuer)

</div>

---

## Description

**WooCommerce Server Game RCON** est un plugin WordPress/WooCommerce avancé qui automatise l'envoi de commandes RCON vers des serveurs de jeux lorsqu'une commande est marquée comme "Terminée". Parfait pour les boutiques de jeux qui vendent des objets virtuels, des améliorations ou des récompenses nécessitant une distribution automatique via RCON.

## Fonctionnalités

### 🚀 Automatisation complète
- **Envoi automatique** - Commandes RCON envoyées automatiquement à la validation des commandes
- **Compatible HPOS** - Support complet du nouveau système de commandes WooCommerce
- **Multi-serveurs** - Gestion de plusieurs serveurs RCON simultanément
- **Envoi groupé** - Optimisation des connexions avec envoi groupé des commandes

### 🎯 Gestion par produit
- **Configuration par produit** - Chaque produit peut avoir ses propres commandes RCON
- **Sélection de serveur** - Attribution des commandes à des serveurs spécifiques ou tous
- **Variables dynamiques** - Remplacement automatique des variables dans les commandes

### 🛡️ Robustesse et sécurité
- **Système de retry** - Nouvelle tentative automatique en cas d'échec
- **Historique complet** - Journalisation détaillée de tous les envois
- **Validation des données** - Nettoyage et validation des paramètres utilisateur
- **Gestion d'erreurs** - Traitement avancé des erreurs de connexion

### 🔧 Interface d'administration
- **Interface moderne** - Panneau d'administration intuitif et responsive
- **Test de connexion** - Validation instantanée des paramètres serveur
- **Gestion manuelle** - Possibilité de renvoyer manuellement les commandes
- **Debug avancé** - Logs détaillés pour le débogage

### 🎮 Expérience utilisateur
- **Champ pseudo optionnel** - Collecte du nom d'utilisateur de jeu au checkout
- **Vérification joueur** - Validation optionnelle de l'existence du joueur sur le serveur
- **Colonnes personnalisées** - Statut RCON visible dans la liste des commandes

### 🔄 Mise à jour automatique
- **GitHub Updater** - Système de mise à jour automatique via GitHub releases
- **Cache intelligent** - Mise en cache des informations de mise à jour
- **Notifications** - Alertes de nouvelles versions disponibles

## Installation

### Méthode 1 : Via Git

```bash
cd wp-content/plugins/
git clone https://github.com/skylidefr/WooCommerce-Server-Game-RCON.git
```

### Méthode 2 : Téléversement depuis WordPress

1. Téléchargez le plugin au format ZIP depuis [GitHub](https://github.com/skylidefr/WooCommerce-Server-Game-RCON/archive/refs/heads/main.zip)
2. Connectez-vous à votre WordPress et allez dans **Extensions → Ajouter**
3. Cliquez sur **Téléverser une extension**, sélectionnez le fichier ZIP, puis cliquez sur **Installer maintenant**
4. Activez le plugin après l'installation

## Configuration

### 1. Configuration des serveurs

1. **Activez le plugin** via le menu **Extensions** dans WordPress
2. Allez dans **Réglages → Server Game RCON**
3. **Ajoutez vos serveurs RCON** :
   - **Nom** : Nom descriptif du serveur
   - **Host** : Adresse IP ou nom de domaine
   - **Port** : Port RCON (généralement 28016 pour Rust, 2457 pour Valheim, etc.)
   - **Mot de passe** : Mot de passe RCON du serveur
   - **Timeout** : Délai de connexion en secondes

### 2. Options globales

- **Timeout global** : Délai par défaut pour toutes les connexions
- **Champ pseudo** : Activer la collecte du nom d'utilisateur au checkout
- **Vérification joueur** : Vérifier l'existence du joueur avant validation
- **Retry automatique** : Réessayer automatiquement les commandes échouées
- **Debug** : Activer les logs détaillés pour le débogage

### 3. Configuration par produit

Dans l'édition de chaque produit WooCommerce :

1. **Sélectionnez le serveur cible** :
   - Par défaut (premier serveur)
   - Serveur spécifique
   - Tous les serveurs

2. **Ajoutez les commandes RCON** (une par ligne) :
   ```
   give {game_username} rifle.ak 1
   inventory.giveto {game_username} wood 1000
   oxide.usergroup add {game_username} vip
   ```

## Variables disponibles

| Variable | Description | Exemple |
|----------|-------------|---------|
| `{game_username}` | Nom d'utilisateur de jeu saisi au checkout | `PlayerName` |
| `{billing_first_name}` | Prénom de facturation | `Jean` |
| `{billing_last_name}` | Nom de facturation | `Dupont` |
| `{billing_email}` | Email de facturation | `jean.dupont@example.com` |
| `{order_id}` | Numéro de commande | `1234` |

## Utilisation

### Processus automatique

1. **Client passe commande** avec des produits configurés pour RCON
2. **Optionnel** : Client saisit son nom d'utilisateur de jeu
3. **Commande validée** (statut "Terminée")
4. **Plugin déclenché** automatiquement
5. **Commandes envoyées** aux serveurs configurés
6. **Historique sauvegardé** pour traçabilité

### Gestion manuelle

Depuis l'administration des commandes :
- **Renvoyer les commandes** en cas de problème
- **Réinitialiser le statut** pour permettre un nouvel envoi automatique
- **Consulter l'historique** des tentatives d'envoi

### Colonnes d'administration

Une nouvelle colonne "RCON" apparaît dans la liste des commandes avec :
- ✅ **Succès** : Commandes envoyées avec succès
- ❌ **Échec** : Erreur lors de l'envoi
- — **Aucun** : Aucune commande RCON configurée

## Jeux compatibles

Ce plugin fonctionne avec tous les jeux supportant le protocole RCON :

### Protocole Source RCON
- **Rust** (port 28016 par défaut)
- **Valheim** (port 2457 par défaut)
- **ARK: Survival Evolved**
- **7 Days to Die**
- **Project Zomboid**

### Protocole Minecraft RCON
- **Minecraft Java Edition** (port 25575 par défaut)
- **Minecraft Bedrock** (avec RCON activé)

### Autres protocoles
- **FiveM** (avec ressource RCON)
- **Garry's Mod** (avec addon RCON)
- Et bien d'autres serveurs de jeux supportant RCON...

## Débogage

### Logs disponibles

Le plugin génère des logs détaillés accessibles depuis l'administration :
- Tentatives de connexion
- Authentifications
- Commandes envoyées
- Erreurs rencontrées
- Historique par commande

### Problèmes courants

#### Connexion impossible
- Vérifiez l'adresse IP et le port du serveur
- Assurez-vous que RCON est activé sur le serveur
- Contrôlez le mot de passe RCON
- Vérifiez les règles de pare-feu

#### Commandes non exécutées
- Validez la syntaxe des commandes
- Vérifiez les permissions du serveur
- Contrôlez les variables utilisées
- Consultez les logs du plugin

#### Erreurs de timeout
- Augmentez la valeur du timeout
- Vérifiez la latence réseau
- Contrôlez la charge du serveur

## Prérequis techniques

- **WordPress** 5.0 ou supérieur
- **WooCommerce** 6.0 ou supérieur
- **PHP** 7.4 ou supérieur
- **Extension PHP Socket** (généralement incluse)
- **Serveurs de jeux** avec RCON activé

## Sécurité

### Mesures de protection
- **Validation des entrées** - Nettoyage de toutes les données utilisateur
- **Caractères dangereux** - Suppression automatique des caractères à risque
- **Mots de passe** - Masquage dans les logs et l'interface
- **Accès restreint** - Permissions WordPress requises
- **Nonces** - Protection CSRF pour toutes les actions AJAX

### Bonnes pratiques
- Utilisez des mots de passe RCON complexes
- Limitez l'accès RCON aux adresses IP de confiance
- Surveillez régulièrement les logs d'activité
- Mettez à jour le plugin régulièrement

## Migration depuis Valheim RCON

Si vous utilisez une version antérieure spécifique à Valheim :

1. **Sauvegardez** vos paramètres existants
2. **Installez** la nouvelle version
3. **Reconfigurez** vos serveurs si nécessaire
4. **Mettez à jour** les métadonnées produits (`_valheim_rcon_*` → `_server_game_rcon_*`)
5. **Testez** le fonctionnement sur une commande de test

## Contribuer

Les contributions sont bienvenues sur le [repository GitHub](https://github.com/skylidefr/WooCommerce-Server-Game-RCON) !

### Développement

1. Forkez le projet
2. Créez votre branche : `git checkout -b feature/nouvelle-fonctionnalite`
3. Commitez vos changements : `git commit -m 'Ajout nouvelle fonctionnalité'`
4. Pushez sur la branche : `git push origin feature/nouvelle-fonctionnalite`
5. Ouvrez une Pull Request

### Standards de code

- Respectez les standards de codage WordPress
- Documentez les nouvelles fonctionnalités
- Testez sur plusieurs versions de WordPress/WooCommerce
- Incluez des tests unitaires si possible

### Signaler un bug

Pour signaler un bug, ouvrez une [issue](https://github.com/skylidefr/WooCommerce-Server-Game-RCON/issues) avec :
- Description détaillée du problème
- Version de WordPress, WooCommerce et PHP
- Configuration de serveur de jeu
- Messages d'erreur et logs pertinents
- Étapes pour reproduire le problème

## Historique des versions

### v1.0.0 (Actuelle)
- Version initiale du plugin générique
- Support multi-serveurs RCON
- Compatible HPOS WooCommerce
- Système de retry automatique
- Interface d'administration moderne
- Mise à jour automatique via GitHub

### Versions antérieures
- Versions spécifiques Valheim RCON (archivées)

## Roadmap

### Prochaines fonctionnalités
- Interface de configuration des templates de commandes
- Support d'autres protocoles (Discord webhooks, API REST)
- Statistiques d'utilisation et rapports
- Import/export de configuration
- Mode test avec serveur de développement

## Support

### Communauté
- [Discord](https://discord.gg/votre-serveur) - Support communautaire
- [Forum WordPress](https://wordpress.org/support/plugin/woocommerce-server-game-rcon/) - Support officiel
- [GitHub Issues](https://github.com/skylidefr/WooCommerce-Server-Game-RCON/issues) - Bugs et demandes

### Support professionnel
Pour un support personnalisé ou le développement de fonctionnalités spécifiques, contactez l'auteur via GitHub.

## Licence

Ce plugin WordPress, **WooCommerce Server Game RCON**, est distribué sous la **Licence publique générale GNU (GPL) version 2 ou ultérieure**.

Vous pouvez utiliser, modifier et redistribuer ce plugin à condition de conserver la même licence.

Pour plus de détails, consultez le site officiel de la GPL : [GPL v2](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)

## Auteur

**Skylide**
- GitHub: [@skylidefr](https://github.com/skylidefr)
- Repository: [WooCommerce-Server-Game-RCON](https://github.com/skylidefr/WooCommerce-Server-Game-RCON)
- Autres projets : [Steam-Server-Status-SourceQuery-PHP](https://github.com/skylidefr/Steam-Server-Status-SourceQuery-PHP)

## Remerciements

- Merci à la communauté WooCommerce pour les retours
- Basé sur l'expérience du plugin Steam Server Status
- Inspiré par les besoins des communautés de joueurs
- Contributions de la communauté open source

---

<div align="center">

**N'hésitez pas à donner une étoile si ce projet vous aide !**

Made with ❤️ for gaming communities worldwide

</div>
