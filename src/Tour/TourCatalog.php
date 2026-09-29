<?php

namespace App\Tour;

/**
 * Every guided tour of the tutorial, in the order they are offered (/didacticiel and « Visite suivante »).
 * Steps point at stable hooks of the templates: ids, landmarks and data-tour attributes.
 */
final class TourCatalog
{
    public const QUERY_PARAMETER = 'visite';

    /** @var array<string, Tour>|null */
    private ?array $tours = null;

    /** @return array<string, Tour> */
    public function all(): array
    {
        if ($this->tours === null) {
            $this->tours = [];
            foreach ($this->build() as $tour) {
                $this->tours[$tour->key] = $tour;
            }
        }
        return $this->tours;
    }

    public function find(string $key): ?Tour
    {
        return $this->all()[$key] ?? null;
    }

    public function next(Tour $tour): ?Tour
    {
        $keys = array_keys($this->all());
        $position = array_search($tour->key, $keys, true);
        return $position === false ? null : $this->find($keys[$position + 1] ?? '');
    }

    /** @return list<Tour> */
    private function build(): array
    {
        return [
            new Tour('site', 'Premiers pas', 'Se repérer : navigation, recherche, publication, messages, notifications et fil d\'actualité.', 'compass', 'app_home', [
                self::step(null, 'Bienvenue sur SprueHub', 'Cette visite présente les repères du site. Utilise « Suivant » ou les flèches du clavier ; Échap quitte la visite à tout moment.'),
                self::step('.app-nav, .bottom-bar', 'Les grandes rubriques', 'Le forum pour échanger, les groupes pour tes clubs et projets, le classement des membres les plus actifs. Sur mobile, elles sont dans la barre du bas.'),
                self::step('#site-search', 'Rechercher', 'Trouve un membre, un groupe public, une section ou un sujet du forum : les résultats s\'affichent pendant la saisie.'),
                self::step('[data-tour="publish"]', 'Publier', 'Nouveau sujet, photo, liste d\'armée ou groupe : tout ce que tu crées part d\'ici.'),
                self::step('[data-tour="messages"]', 'Messages privés', 'Tes conversations avec tes amis. Le compteur indique les messages non lus.'),
                self::step('[data-tour="notifications"]', 'Notifications', 'Réponses, mentions @pseudo, invitations, badges : tout arrive ici en temps réel.'),
                self::step('[data-tour="account"]', 'Ton compte', 'Ton profil, tes listes d\'armée, tes tâches, tes amis et tes paramètres.'),
                self::step('[data-tour="feed"]', 'Le fil d\'actualité', 'L\'activité de tes amis, de tes groupes et de la communauté. Les filtres en haut changent ce que tu suis.'),
                self::step('[data-tour="lost"]', 'Besoin d\'aide ?', 'Le lien « Je suis perdu » en bas de chaque page ramène à ce didacticiel.'),
            ]),
            new Tour('forum', 'Le forum', 'Sections, sujets, réponses, mentions, réactions et solutions.', 'messages-square', 'app_forum_index', [
                self::step('.page-header', 'Le forum', 'Techniques de peinture, listes d\'armée, rapports de bataille : chaque section rassemble les sujets d\'un thème.'),
                self::step('[data-tour="forum-sections"]', 'Les sections', 'Ouvre une section pour voir ses sujets ; certaines regroupent des sous-sections. Les sections en lecture seule publient les actualités du site.'),
                self::step('[data-tour="publish"]', 'Ouvrir un sujet', '« Publier » puis « Nouveau sujet » : choisis la section, écris en Markdown et ajoute des images.'),
                self::step(null, 'Participer', 'Réponds, cite, réagis aux messages et mentionne un membre avec @pseudo pour le prévenir. L\'auteur d\'une question peut marquer la réponse qui l\'a aidé comme solution.'),
            ]),
            new Tour('groupes', 'Les groupes', 'Invitations, groupes, salons de discussion et tâches partagées.', 'users', 'app_group_index', [
                self::step('#invitations', 'Invitations', 'Les invitations reçues arrivent ici : accepte-les ou refuse-les.'),
                self::step('[aria-labelledby="my-groups-title"]', 'Tes groupes', 'Triés par activité récente, ou dans ton propre ordre avec l\'interrupteur « Ordre personnalisé » puis un glisser-déposer. La couronne signale les groupes dont tu es propriétaire.'),
                self::step('[aria-labelledby="suggestions-title"]', 'Suggestions', 'Des groupes publics où sont déjà tes amis.'),
                self::step('[data-tour="group-create"]', 'Créer un groupe', 'Club, table de jeu ou projet d\'armée : crée un groupe public ou privé et invite tes amis.'),
                self::step(null, 'Dans un groupe', 'Discute dans des salons (émoticônes, mentions @pseudo, sourdine), épingle les messages importants et suis les tâches du groupe : projets, catégories, tâches, assignations et progression.'),
            ]),
            new Tour('galerie', 'Profil et galerie', 'Ta vitrine : photos, albums, listes d\'armée et badges.', 'image', 'app_profil_show', [
                self::step('[data-tour="profile-edit"]', 'Ton profil', 'Photo, bannière (à recadrer à l\'envoi), présentation et faction favorite.'),
                self::step('#profile-tabs', 'Les onglets', 'Galerie, listes d\'armée publiques, badges et activité : ce que les autres membres voient de toi.'),
                self::step('#gallery-upload-form', 'Ajouter une photo', 'Choisis une image, ajoute une description puis publie-la. Tes photos peuvent être rangées en albums.'),
                self::step('#tab-badges', 'Badges et niveau', 'Ta participation rapporte de l\'XP et des badges ; un badge obtenu peut devenir ton titre.'),
            ], ['username' => '@me']),
            new Tour('listes', 'Les listes d\'armée', 'Composer, importer, partager et explorer des listes Warhammer 40k.', 'swords', 'app_army_index', [
                self::step('[data-tour="army-new"], a[href$="/army/new"]', 'Composer une liste', 'Choisis une faction et un détachement, ajoute tes unités : le total de points et les règles se vérifient au fur et à mesure.'),
                self::step('[data-tour="army-import"]', 'Importer', 'Colle une liste au format texte pour la retrouver dans le constructeur.'),
                self::step('[data-tour="army-explorer"]', 'Explorer', 'Les listes publiques de la communauté, à consulter ou à copier dans tes propres listes.'),
                self::step(null, 'Partager', 'Une liste publique s\'affiche sur ton profil ; elle s\'exporte en texte ou s\'imprime en PDF.'),
            ]),
            new Tour('messagerie', 'La messagerie', 'Conversations privées entre amis, en direct.', 'mail', 'app_message_index', [
                self::step('.page-header', 'Tes conversations', 'Les messages privés s\'échangent entre amis ; la conversation la plus récente remonte en haut de la liste.'),
                self::step('#messenger', 'La messagerie flottante', 'Sur ordinateur, discute sans quitter la page en cours depuis cette barre.'),
                self::step(null, 'Écrire', 'Émoticônes, mentions @pseudo et messages en temps réel. Un membre bloqué ne peut plus t\'écrire.'),
            ]),
        ];
    }

    /** @return array{element: ?string, title: string, text: string} */
    private static function step(?string $element, string $title, string $text): array
    {
        return ['element' => $element, 'title' => $title, 'text' => $text];
    }
}
