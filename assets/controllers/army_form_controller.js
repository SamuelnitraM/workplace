import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */

/*
 * Formulaire de liste d'armée (création et modification), rendu par un composant Alpine.js « armyForm ».
 *
 * Alpine est chargé depuis le CDN et démarre avant les modules : un composant déclaré dans le HTML initial
 * serait initialisé avant l'enregistrement de « armyForm ». Le formulaire est donc placé dans un <template> :
 * ce contrôleur enregistre le composant (Alpine.data) puis insère le formulaire, qu'Alpine initialise alors
 * (MutationObserver, ou Alpine.start() s'il n'a pas encore démarré). Même principe après chaque visite Turbo.
 * Avant la mise en cache Turbo, le formulaire rendu est retiré : la page restaurée repart du <template>.
 *
 * Usage :
 *   <div {{ stimulus_controller('army-form', {unitsUrl: …, detachmentsUrl: …, faction: …, detachment: …, initialUnits: […], groups: […]}) }}>
 *     <template data-army-form-target="template"><div x-data="armyForm">…</div></template>
 *   </div>
 *
 * Valeurs : unitsUrl / detachmentsUrl contiennent le paramètre « __FACTION__ », remplacé par la faction (encodée) ;
 * faction non vide = modification (faction fixe, unités et détachements chargés à l'initialisation) ;
 * groups = groupes d'unités ordonnés [{key, plural, order, …}] (App\Army\UnitCategory::all()).
 *
 * Regroupement : chaque unité reçoit du serveur `group` (clé UnitCategory), `groupLabel` et `groupOrder`.
 * Aucune règle de catégorie n'est codée ici : groupe inconnu ou absent → « autres ». Les sections suivent
 * l'ordre de `groups` (UnitCategory), groupes vides masqués.
 */

const configs = new WeakMap();

const OTHER_GROUP = 'autres';

function armyForm() {
    let nextUid = 1;
    let unitsUrl = '';
    let detachmentsUrl = '';
    let knownGroups = new Set([OTHER_GROUP]); // clés de groupe connues (fournies par le serveur)

    return {
        selectedFaction: '',
        selectedDetachment: '',
        availableUnits: [],
        availableDetachments: [],
        addedUnits: [],
        loadingUnits: false,
        groups: [], // [{key, plural, icon, order}] triés : sections du catalogue et de « Ma liste »

        init() {
            const config = configs.get(this.$el) || {};
            unitsUrl = config.unitsUrl || '';
            detachmentsUrl = config.detachmentsUrl || '';
            this.selectedFaction = config.faction || '';
            this.selectedDetachment = config.detachment || '';
            // Repli sûr sans groupes fournis : une seule section « Autres »
            const groups = Array.isArray(config.groups) && config.groups.length
                ? config.groups
                : [{ key: OTHER_GROUP, plural: 'Autres', icon: 'layout-grid', order: 0 }];
            this.groups = [...groups].sort((a, b) => (a.order ?? 0) - (b.order ?? 0));
            knownGroups = new Set(this.groups.map(g => g.key));
            this.addedUnits = (config.initialUnits || []).map(u => ({ ...u, uid: nextUid++, showDetails: false }));

            // Modification : la faction est fixe, on charge tout de suite ses unités et détachements
            if (this.selectedFaction) {
                return this.loadFactionData();
            }
        },

        // Clé de groupe d'une unité (fournie par le serveur), « autres » si absente ou inconnue
        unitGroup(unit) {
            const g = unit && unit.group;
            if (!g || !knownGroups.has(g)) return OTHER_GROUP;
            return g;
        },

        // Regroupe des unités par clé de groupe (ordre interne conservé : nom pour le catalogue, ajout pour la liste)
        groupBy(units) {
            const groups = {};
            for (const unit of units) {
                const g = this.unitGroup(unit);
                if (!groups[g]) groups[g] = [];
                groups[g].push(unit);
            }
            return groups;
        },

        get groupedUnits() {
            return this.groupBy(this.availableUnits);
        },

        get groupedAddedUnits() {
            return this.groupBy(this.addedUnits);
        },

        // Sous-total de points d'un groupe de « Ma liste »
        groupPoints(group) {
            return (this.groupedAddedUnits[group] || []).reduce((sum, u) => sum + (u.points * u.quantity), 0);
        },

        // Nombre d'unités (quantités cumulées) d'un groupe de « Ma liste »
        groupCount(group) {
            return (this.groupedAddedUnits[group] || []).reduce((sum, u) => sum + u.quantity, 0);
        },

        get totalPoints() {
            return this.addedUnits.reduce((sum, u) => sum + (u.points * u.quantity), 0);
        },

        get unitsPayload() {
            // Le serveur relit nom/points/stats en base : on n'envoie que les ids et la quantité.
            // armyUnitId = unité déjà enregistrée, factionUnitId = unité ajoutée depuis le catalogue.
            return JSON.stringify(this.addedUnits.map(u => (u.armyUnitId
                ? { armyUnitId: u.armyUnitId, quantity: u.quantity }
                : { factionUnitId: u.factionUnitId, quantity: u.quantity })));
        },

        fetchFaction(faction) {
            const encoded = encodeURIComponent(faction);
            return Promise.all([
                fetch(unitsUrl.replace('__FACTION__', encoded)),
                fetch(detachmentsUrl.replace('__FACTION__', encoded)),
            ]);
        },

        // Modification : chargement initial pour la faction de la liste
        async loadFactionData() {
            this.loadingUnits = true;
            try {
                const [unitsRes, detsRes] = await this.fetchFaction(this.selectedFaction);
                this.availableUnits = await unitsRes.json();
                this.availableDetachments = await detsRes.json();

                // On rattache les stats (et le groupe) actuels à chaque unité déjà présente dans la liste
                for (const added of this.addedUnits) {
                    if (added.statsData && added.group) continue;
                    const match = this.availableUnits.find(u => u.name === added.name);
                    if (match) {
                        added.statsData = added.statsData || match.statsData;
                        if (!added.group) {
                            added.group = match.group;
                            added.groupLabel = match.groupLabel;
                            added.groupOrder = match.groupOrder;
                        }
                    }
                }
            } catch (e) {
                console.error('Erreur de chargement', e);
            } finally {
                this.loadingUnits = false;
            }
        },

        // Création : changement de faction
        async onFactionChange() {
            this.availableUnits = [];
            this.availableDetachments = [];
            this.selectedDetachment = '';
            if (!this.selectedFaction) return;

            // Les unités déjà ajoutées appartiennent à l'ancienne faction : le serveur les refuserait
            this.addedUnits = [];

            const faction = this.selectedFaction;
            this.loadingUnits = true;
            try {
                const [unitsRes, detsRes] = await this.fetchFaction(faction);
                const units = await unitsRes.json();
                const detachments = await detsRes.json();

                // Réponse obsolète : la faction a changé pendant le chargement
                if (faction !== this.selectedFaction) return;

                this.availableUnits = units;
                this.availableDetachments = detachments;
            } catch (e) {
                console.error('Erreur de chargement', e);
            } finally {
                if (faction === this.selectedFaction) {
                    this.loadingUnits = false;
                }
            }
        },

        addUnit(unit) {
            this.addedUnits.push({
                uid: nextUid++,
                factionUnitId: unit.id,
                name: unit.name,
                points: unit.points,
                quantity: 1,
                category: unit.category,
                group: unit.group,
                groupLabel: unit.groupLabel,
                groupOrder: unit.groupOrder,
                statsData: unit.statsData,
                showDetails: false,
            });
        },

        removeUnit(uid) {
            this.addedUnits = this.addedUnits.filter(u => u.uid !== uid);
        },
    };
}

let registered = false;

function register(Alpine) {
    if (registered) return;
    Alpine.data('armyForm', armyForm);
    registered = true;
}

export default class extends Controller {
    static targets = ['template'];
    static values = {
        unitsUrl: String,
        detachmentsUrl: String,
        faction: String,
        detachment: String,
        initialUnits: Array,
        groups: Array,
    };

    connect() {
        // Page restaurée depuis le cache Turbo : on repart du <template>
        this.removeRendered();

        this.onBeforeCache = () => this.removeRendered();
        document.addEventListener('turbo:before-cache', this.onBeforeCache);

        if (window.Alpine) {
            this.mount(window.Alpine);
        } else {
            // Alpine pas encore chargé : on insère le formulaire juste avant son démarrage
            this.onAlpineInit = () => this.mount(window.Alpine);
            document.addEventListener('alpine:init', this.onAlpineInit, { once: true });
        }
    }

    disconnect() {
        document.removeEventListener('turbo:before-cache', this.onBeforeCache);
        if (this.onAlpineInit) document.removeEventListener('alpine:init', this.onAlpineInit);
        this.onAlpineInit = null;
        this.removeRendered();
    }

    mount(Alpine) {
        this.onAlpineInit = null;
        if (!this.element.isConnected) return;
        register(Alpine);

        const root = this.templateTarget.content.firstElementChild.cloneNode(true);
        configs.set(root, {
            unitsUrl: this.unitsUrlValue,
            detachmentsUrl: this.detachmentsUrlValue,
            faction: this.factionValue,
            detachment: this.detachmentValue,
            initialUnits: this.initialUnitsValue,
            groups: this.groupsValue,
        });
        this.templateTarget.after(root);
    }

    removeRendered() {
        [...this.element.children].forEach(child => {
            if (child !== this.templateTarget) child.remove();
        });
    }
}
