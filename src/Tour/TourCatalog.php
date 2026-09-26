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
                self::step(null, 'Bienvenue sur SprueHub', 'Cette visite présente les repères du site. Utilisez « Suivant » ou les flèches du clavier ; Échap quitte la visite à tout moment.'),
                self::step('.app-nav, .bottom-bar', 'Les grandes rubriques', 'Le forum pour échanger, les groupes pour vos clubs et projets, le classement des membres les plus actifs. Sur mobile, elles sont dans la barre du bas.'),
                self::step('#site-search', 'Rechercher', 'Trouvez un membre, un groupe public, une section ou un sujet du forum : les résultats s\'affichent pendant la saisie.'),
                self::step('[data-tour="publish"]', 'Publier', 'Nouveau sujet, photo, liste d\'armée ou groupe : tout ce que vous créez part d\'ici.'),
                self::step('[data-tour="messages"]', 'Messages privés', 'Vos conversations avec vos amis. Le compteur indique les messages non lus.'),
                self::step('[data-tour="notifications"]', 'Notifications', 'Réponses, mentions @pseudo, invitations, badges : tout arrive ici en temps réel.'),
                self::step('[data-tour="account"]', 'Votre compte', 'Votre profil, vos listes d\'armée, vos tâches, vos amis et vos paramètres.'),
                self::step('[data-tour="feed"]', 'Le fil d\'actualité', 'L\'activité de vos amis, de vos groupes et de la communauté. Les filtres en haut changent ce que vous suivez.'),
                self::step('[data-tour="lost"]', 'Besoin d\'aide ?', 'Le lien « Je suis perdu » en bas de chaque page ramène à ce didacticiel.'),
            ]),
            new Tour('forum', 'Le forum', 'Sections, sujets, réponses, mentions, réactions et solutions.', 'messages-square', 'app_forum_index', [
                self::step('.page-header', 'Le forum', 'Techniques de peinture, listes d\'armée, rapports de bataille : chaque section rassemble les sujets d\'un thème.'),
                self::step('[data-tour="forum-sections"]', 'Les sections', 'Ouvrez une section pour voir ses sujets ; certaines regroupent des sous-sections. Les sections en lecture seule publient les actualités du site.'),
                self::step('[data-tour="publish"]', 'Ouvrir un sujet', '« Publier » puis « Nouveau sujet » : choisissez la section, écrivez en Markdown et ajoutez des images.'),
                self::step(null, 'Participer', 'Répondez, citez, réagissez aux messages et mentionnez un membre avec @pseudo pour le prévenir. L\'auteur d\'une question peut marquer la réponse qui l\'a aidé comme solution.'),
            ]),
            new Tour('groupes', 'Les groupes', 'Invitations, groupes, salons de discussion et tâches partagées.', 'users', 'app_group_index', [
                self::step('#invitations', 'Invitations', 'Les invitations reçues arrivent ici : acceptez-les ou refusez-les.'),
                self::step('[aria-labelledby="my-groups-title"]', 'Vos groupes', 'Triés par activité récente, ou dans votre propre ordre avec l\'interrupteur « Ordre personnalisé » puis un glisser-déposer. La couronne signale les groupes dont vous êtes propriétaire.'),
                self::step('[aria-labelledby="suggestions-title"]', 'Suggestions', 'Des groupes publics où sont déjà vos amis.'),
                self::step('[data-tour="group-create"]', 'Créer un groupe', 'Club, table de jeu ou projet d\'armée : créez un groupe public ou privé et invitez vos amis.'),
                self::step(null, 'Dans un groupe', 'Discutez dans des salons (émoticônes, mentions @pseudo, sourdine), épinglez les messages importants et suivez les tâches du groupe : projets, catégories, tâches, assignations et progression.'),
            ]),
            new Tour('galerie', 'Profil et galerie', 'Votre vitrine : photos, albums, listes d\'armée et badges.', 'image', 'app_profil_show', [
                self::step('[data-tour="profile-edit"]', 'Votre profil', 'Photo, bannière (à recadrer à l\'envoi), présentation et faction favorite.'),
                self::step('#profile-tabs', 'Les onglets', 'Galerie, listes d\'armée publiques, badges et activité : ce que les autres membres voient de vous.'),
                self::step('#gallery-upload-form', 'Ajouter une photo', 'Choisissez une image, ajoutez une description puis publiez-la. Vos photos peuvent être rangées en albums.'),
                self::step('#tab-badges', 'Badges et niveau', 'Votre participation rapporte de l\'XP et des badges ; un badge obtenu peut devenir votre titre.'),
            ], ['username' => '@me']),
            new Tour('listes', 'Les listes d\'armée', 'Composer, importer, partager et explorer des listes Warhammer 40k.', 'swords', 'app_army_index', [
                self::step('[data-tour="army-new"], a[href$="/army/new"]', 'Composer une liste', 'Choisissez une faction et un détachement, ajoutez vos unités : le total de points et les règles se vérifient au fur et à mesure.'),
                self::step('[data-tour="army-import"]', 'Importer', 'Collez une liste au format texte pour la retrouver dans le constructeur.'),
                self::step('[data-tour="army-explorer"]', 'Explorer', 'Les listes publiques de la communauté, à consulter ou à copier dans vos propres listes.'),
                self::step(null, 'Partager', 'Une liste publique s\'affiche sur votre profil ; elle s\'exporte en texte ou s\'imprime en PDF.'),
            ]),
            new Tour('messagerie', 'La messagerie', 'Conversations privées entre amis, en direct.', 'mail', 'app_message_index', [
                self::step('.page-header', 'Vos conversations', 'Les messages privés s\'échangent entre amis ; la conversation la plus récente remonte en haut de la liste.'),
                self::step('#messenger', 'La messagerie flottante', 'Sur ordinateur, discutez sans quitter la page en cours depuis cette barre.'),
                self::step(null, 'Écrire', 'Émoticônes, mentions @pseudo et messages en temps réel. Un membre bloqué ne peut plus vous écrire.'),
            ]),
        ];
    }

    /** @return array{element: ?string, title: string, text: string} */
    private static function step(?string $element, string $title, string $text): array
    {
        return ['element' => $element, 'title' => $title, 'text' => $text];
    }
}
