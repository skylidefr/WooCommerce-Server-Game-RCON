# Steam Server Status SourceQuery PHP - SSSSP

<div align="center">

![WordPress](https://img.shields.io/badge/WordPress-5.0+-21759B?style=for-the-badge&logo=wordpress&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-7.4+-777BB4?style=for-the-badge&logo=php&logoColor=white)
![Version](https://img.shields.io/github/v/release/skylidefr/Steam-Server-Status-SourceQuery-PHP?style=for-the-badge)
![License](https://img.shields.io/badge/License-GPLv2+-4E9A06?style=for-the-badge)

**Un plugin WordPress élégant pour afficher le statut en temps réel de vos serveurs de jeux**

[Installation](#installation) • [Utilisation](#utilisation) • [Fonctionnalités](#fonctionnalités) • [Contribuer](#contribuer)

</div>

---

## Description

**Steam Server Status SourceQuery PHP** est un plugin WordPress moderne qui permet d'afficher facilement le nombre de joueurs connectés sur un ou plusieurs serveurs Steam compatibles SourceQuery. Parfait pour les communautés de joueurs qui souhaitent partager l'activité de leurs serveurs sur leur site web.

## Fonctionnalités

- **Statut en temps réel** - Affichage du statut serveur (en ligne/hors ligne)
- **Compteur de joueurs** - Nombre de joueurs connectés et capacité maximale
- **Multi-serveurs** - Support de plusieurs serveurs configurables
- **Cache intégré** - Système de cache (15s par défaut) pour optimiser les performances
- **Mise à jour automatique** - Système de mise à jour via GitHub releases
- **Personnalisation avancée** - Couleurs, polices et styles configurables
- **Shortcodes flexibles** - Intégration facile dans vos pages et articles
- **Interface responsive** - Adaptée à tous les écrans

## Installation

### Méthode 1 : Via Git

```bash
cd wp-content/plugins/
git clone https://github.com/skylidefr/Steam-Server-Status-SourceQuery-PHP.git
```
### Méthode 2 : Téléversement depuis WordPress

1. Téléchargez le plugin au format ZIP depuis [GitHub](https://github.com/skylidefr/Steam-Server-Status-SourceQuery-PHP/archive/refs/tags/1.3.0.zip) ou depuis WordPress.org.  
2. Connectez-vous à votre WordPress et allez dans **Extensions → Ajouter**.  
3. Cliquez sur **Téléverser une extension**, sélectionnez le fichier ZIP, puis cliquez sur **Installer maintenant**.  
4. Activez le plugin après l’installation.

### Configuration

1. **Activez le plugin** via le menu **Extensions** dans WordPress
2. Allez dans **Réglages → Steam Status** 
3. **Ajoutez vos serveurs** avec l'adresse IP et le port
4. **Configurez l'affichage** selon vos préférences
5. **Testez la connexion** pour vérifier la configuration

## Utilisation

### Shortcodes disponibles

#### Afficher un serveur spécifique
```php
[steam_status id="0" show_name="1"]
```

#### Afficher tous les serveurs
```php
[steam_status_all display="table"]
[steam_status_all display="cards"]
```

### Paramètres des shortcodes

| Paramètre | Description | Valeurs | Défaut |
|-----------|-------------|---------|---------|
| `id` | Identifiant du serveur | `0`, `1`, `2`... | `0` |
| `show_name` | Afficher le nom du serveur | `1` (oui), `0` (non) | `1` |
| `display` | Mode d'affichage (steam_status_all) | `table`, `cards` | `table` |

### Exemples d'utilisation

```php
// Afficher le premier serveur avec son nom
[steam_status id="0" show_name="1"]

// Afficher le deuxième serveur sans nom
[steam_status id="1" show_name="0"]

// Afficher tous les serveurs en tableau
[steam_status_all display="table"]

// Afficher tous les serveurs en cartes
[steam_status_all display="cards"]
```

## Configuration avancée

### Options disponibles dans l'administration

- **Gestion des serveurs** - Ajout/suppression de serveurs
- **Personnalisation des textes** - Messages d'erreur et labels
- **Couleurs et styles** - Personnalisation visuelle complète
- **Polices** - Configuration de la typographie
- **Cache** - Durée de mise en cache des données

### Personnalisation CSS

```css
.steam-status {
    background: linear-gradient(135deg, #171a21, #2a475e);
    border-radius: 10px;
    padding: 20px;
    color: white;
}

.steam-status.online {
    border-left: 4px solid #66c0f4;
}

.steam-status.offline {
    border-left: 4px solid #e74c3c;
}
```

## Prérequis techniques

- **WordPress** 5.0 ou supérieur
- **PHP** 7.4 ou supérieur  
- **Extension PHP Socket** (pour les requêtes SourceQuery)
- **Serveur Steam** compatible SourceQuery

## Jeux compatibles

Ce plugin fonctionne avec tous les jeux Steam utilisant le protocole SourceQuery :

- Counter-Strike: Global Offensive
- Counter-Strike 2
- Team Fortress 2
- Garry's Mod
- Left 4 Dead 2
- Rust
- ARK: Survival Evolved
- Valheim
- DayZ
- Et bien d'autres...

## Mise à jour automatique

Le plugin intègre un système de mise à jour automatique via GitHub :

1. Les nouvelles versions sont détectées automatiquement
2. Les notifications apparaissent dans l'interface WordPress
3. Installation en un clic depuis l'administration
4. Conservation de vos paramètres lors des mises à jour

## Dépannage

### Serveur injoignable
- Vérifiez que le serveur est en ligne
- Contrôlez l'adresse IP et le port
- Assurez-vous que les requêtes SourceQuery sont activées

### Plugin non fonctionnel
- Vérifiez les prérequis PHP
- Activez le mode debug WordPress
- Consultez les logs d'erreurs

## Contribuer

Les contributions sont bienvenues sur le [repository GitHub](https://github.com/skylidefr/Steam-Server-Status-SourceQuery-PHP) !

### Développement

1. Forkez le projet
2. Créez votre branche : `git checkout -b feature/nouvelle-fonctionnalite`
3. Commitez vos changements : `git commit -m 'Ajout nouvelle fonctionnalité'`
4. Pushez sur la branche : `git push origin feature/nouvelle-fonctionnalite`
5. Ouvrez une Pull Request

### Signaler un bug

Pour signaler un bug, ouvrez une [issue](https://github.com/skylidefr/Steam-Server-Status-SourceQuery-PHP/issues) avec :
- Description détaillée du problème
- Version de WordPress et PHP
- Configuration de serveur
- Messages d'erreur le cas échéant

## Historique des versions

### v1.2
- Ajout du système de mise à jour automatique GitHub
- Amélioration de l'interface d'administration
- Correction de bugs mineurs

### v1.1
- Version initiale stable
- Support multi-serveurs
- Système de cache intégré
- Personnalisation avancée

## Licence

Ce plugin WordPress, **Steam Server Status SourceQuery PHP**, est distribué sous la **Licence publique générale GNU (GPL) version 2 ou ultérieure**.

Vous pouvez utiliser, modifier et redistribuer ce plugin à condition de conserver la même licence.  

Pour plus de détails, consultez le site officiel de la GPL : [GPL v2](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)


## Auteur

**Skylide** 
- GitHub: [@skylidefr](https://github.com/skylidefr)
- Repository: [Steam-Server-Status-SourceQuery-PHP](https://github.com/skylidefr/Steam-Server-Status-SourceQuery-PHP)

## Remerciements

- Merci à la communauté Steam pour les retours et suggestions
- Basé sur la librairie SourceQuery PHP de xPaw
- Inspiré par les outils de monitoring de serveurs de jeux existants

---

<div align="center">

**N'hésitez pas à donner une étoile si ce projet vous aide !**

Made with ❤️ for the Steam gaming community

</div>
