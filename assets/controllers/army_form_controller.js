import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */

/*
 * Army list builder (creation and edition), rendered by the Alpine.js component "armyForm".
 *
 * Alpine is loaded from the CDN and starts before the modules: a component declared in the initial HTML would be
 * initialised before "armyForm" is registered. The form therefore sits in a <template>: this controller registers
 * the component (Alpine.data) then inserts the form, which Alpine initialises (MutationObserver, or Alpine.start()
 * when it has not started yet). Same after each Turbo visit; before the Turbo cache snapshot the rendered form is
 * removed, so a restored page starts again from the <template>.
 *
 * Usage:
 *   <div {{ stimulus_controller('army-form', {unitsUrl: …, detachmentsUrl: …, enhancementsUrl: …, datasheetUrl: …,
 *        unitDatasheetUrl: …, faction: …, detachment: …, battleSize: …, initialUnits: […], groups: […], rules: {…}}) }}>
 *     <template data-army-form-target="template"><div x-data="armyForm">…</div></template>
 *   </div>
 *
 * Values: unitsUrl, detachmentsUrl and enhancementsUrl contain "__FACTION__", replaced by the encoded faction;
 * datasheetUrl and unitDatasheetUrl receive the unit id as "?unit=";
 * a non-empty faction means edition (fixed faction, catalogue loaded at start); battleSize = points limit of an
 * official list (0 = free list); groups = ordered unit groups (App\Army\UnitCategory::all());
 * rules = App\Army\ArmyListRules::clientConfig(), the limits and keywords shared with the server.
 *
 * Official list: every change goes through tryApply(), which checks the candidate list with the same rules as the
 * server and shows the reasons in a window when the change is refused. A free list is never checked.
 */

const configs = new WeakMap();

const OTHER_GROUP = 'autres';

function armyForm() {
    let nextUid = 1;
    let unitsUrl = '';
    let detachmentsUrl = '';
    let enhancementsUrl = '';
    let datasheetUrl = '';
    let unitDatasheetUrl = '';
    let knownGroups = new Set([OTHER_GROUP]); // group keys provided by the server
    let rules = { keywords: {}, copyLimits: { default: 3, extended: 6, epicHero: 1 }, battleSizes: [] };

    return {
        selectedFaction: '',
        selectedDetachment: '',
        battleSize: '', // '' = free list, otherwise the points limit of the official format
        availableUnits: [],
        availableDetachments: [],
        enhancements: [], // every enhancement of the faction: {name, detachment, points, description}
        addedUnits: [],
        loadingUnits: false,
        groups: [], // [{key, plural, icon, order}] sorted: sections of the catalogue and of the list
        battleSizes: [],
        ruleAlert: { title: '', messages: [] },
        datasheet: { title: '', html: '', loading: false },

        init() {
            const config = configs.get(this.$el) || {};
            unitsUrl = config.unitsUrl || '';
            detachmentsUrl = config.detachmentsUrl || '';
            enhancementsUrl = config.enhancementsUrl || '';
            datasheetUrl = config.datasheetUrl || '';
            unitDatasheetUrl = config.unitDatasheetUrl || '';
            rules = { ...rules, ...(config.rules || {}) };
            this.battleSizes = rules.battleSizes || [];
            this.selectedFaction = config.faction || '';
            this.selectedDetachment = config.detachment || '';
            this.battleSize = config.battleSize ? String(config.battleSize) : '';
            // Safe fallback without groups: a single "Autres" section
            const groups = Array.isArray(config.groups) && config.groups.length
                ? config.groups
                : [{ key: OTHER_GROUP, plural: 'Autres', icon: 'layout-grid', order: 0 }];
            this.groups = [...groups].sort((a, b) => (a.order ?? 0) - (b.order ?? 0));
            knownGroups = new Set(this.groups.map(g => g.key));
            this.addedUnits = (config.initialUnits || []).map(u => ({
                warlord: false, enhancement: null, enhancementPoints: 0, modelCount: null, ...u, uid: nextUid++,
            }));
            // Edition: the faction is fixed, its units, detachments and enhancements are loaded right away
            if (this.selectedFaction) {
                return this.loadFactionData();
            }
        },

        // ─── Grouping ────────────────────────────────────────────

        unitGroup(unit) {
            const g = unit && unit.group;
            if (!g || !knownGroups.has(g)) return OTHER_GROUP;
            return g;
        },

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

        groupPoints(group) {
            return (this.groupedAddedUnits[group] || []).reduce((sum, u) => sum + this.unitCost(u), 0);
        },

        groupCount(group) {
            return (this.groupedAddedUnits[group] || []).reduce((sum, u) => sum + u.quantity, 0);
        },

        // ─── Points and rules (mirror of App\Army\ArmyListRules) ─

        get isOfficial() {
            return this.battleSize !== '';
        },

        get pointsLimit() {
            return this.isOfficial ? Number(this.battleSize) : null;
        },

        unitCost(unit) {
            return unit.points * unit.quantity + (unit.enhancementPoints || 0);
        },

        totalOf(units) {
            return units.reduce((sum, u) => sum + this.unitCost(u), 0);
        },

        get totalPoints() {
            return this.totalOf(this.addedUnits);
        },

        get pointsRatio() {
            return this.pointsLimit ? Math.min(100, Math.round(this.totalPoints * 100 / this.pointsLimit)) : 0;
        },

        keywordsOf(unit) {
            return (unit.statsData && Array.isArray(unit.statsData.keywords)) ? unit.statsData.keywords : [];
        },

        hasKeyword(unit, key) {
            return this.keywordsOf(unit).includes(rules.keywords[key]);
        },

        copyLimit(unit) {
            if (this.hasKeyword(unit, 'epicHero')) return rules.copyLimits.epicHero;
            if (this.hasKeyword(unit, 'battleline') || this.hasKeyword(unit, 'dedicatedTransport')) return rules.copyLimits.extended;
            return rules.copyLimits.default;
        },

        canBeWarlord(unit) {
            return this.hasKeyword(unit, 'character');
        },

        canTakeEnhancement(unit) {
            return this.hasKeyword(unit, 'character') && !this.hasKeyword(unit, 'epicHero');
        },

        sizesOf(unit) {
            const options = (unit.statsData && Array.isArray(unit.statsData.pointsOptions)) ? unit.statsData.pointsOptions : [];
            return options.filter(o => o.models > 0);
        },

        get detachmentEnhancements() {
            return this.enhancements.filter(e => e.detachment === this.selectedDetachment);
        },

        // Blocking violations of a candidate list for a points limit (null = free list, nothing to check)
        violations(units, limit, detachment) {
            if (!limit) return [];
            const messages = [];
            const total = this.totalOf(units);
            if (total > limit) {
                messages.push(`La liste ferait ${total} pts : le format choisi est limité à ${limit} pts.`);
            }
            for (const unit of units) {
                if (unit.quantity !== 1) {
                    messages.push(`${unit.name} : en liste officielle, chaque unité est une entrée distincte.`);
                }
            }
            const copies = {};
            for (const unit of units) {
                copies[unit.name] = copies[unit.name] || { count: 0, limit: this.copyLimit(unit) };
                copies[unit.name].count += unit.quantity;
            }
            for (const [name, { count, limit: max }] of Object.entries(copies)) {
                if (count > max) {
                    messages.push(max === rules.copyLimits.epicHero
                        ? `${name} est un personnage épique : un seul exemplaire autorisé.`
                        : `${name} : ${count} exemplaires, ${max} autorisés au maximum.`);
                }
            }
            const warlords = units.filter(u => u.warlord);
            if (warlords.length > 1) {
                messages.push(`Une seule unité peut être Seigneur de guerre (déjà désigné : ${warlords[0].name}).`);
            }
            for (const warlord of warlords) {
                if (!this.canBeWarlord(warlord)) messages.push(`${warlord.name} n'est pas un personnage : il ne peut pas être Seigneur de guerre.`);
            }
            const allowed = new Set(this.enhancements.filter(e => e.detachment === detachment).map(e => e.name));
            const taken = new Set();
            for (const unit of units.filter(u => u.enhancement)) {
                if (!allowed.has(unit.enhancement)) messages.push(`L'amélioration « ${unit.enhancement} » n'appartient pas au détachement choisi.`);
                if (!this.canTakeEnhancement(unit)) messages.push(`${unit.name} ne peut pas recevoir d'amélioration (personnage non épique uniquement).`);
                if (taken.has(unit.enhancement)) messages.push(`L'amélioration « ${unit.enhancement} » est déjà prise par une autre unité.`);
                taken.add(unit.enhancement);
            }
            return [...new Set(messages)];
        },

        get missingWarlord() {
            return this.isOfficial && this.addedUnits.length > 0 && !this.addedUnits.some(u => u.warlord);
        },

        // Applies a change on a copy of the list; an official list refuses it (popup) when a rule would be broken
        tryApply(title, mutate, { limit = this.pointsLimit, detachment = this.selectedDetachment } = {}) {
            const candidate = this.addedUnits.map(u => ({ ...u }));
            mutate(candidate);
            const messages = this.violations(candidate, limit, detachment);
            if (messages.length) {
                this.showRuleAlert(title, messages);
                return false;
            }
            this.addedUnits = candidate;
            return true;
        },

        showRuleAlert(title, messages) {
            this.ruleAlert = { title, messages };
            this.$refs.ruleDialog.showModal();
        },

        // ─── Actions ─────────────────────────────────────────────

        addUnit(unit) {
            const sizes = this.sizesOf(unit);
            this.tryApply(`Impossible d'ajouter ${unit.name}`, units => units.push({
                uid: nextUid++,
                factionUnitId: unit.id,
                name: unit.name,
                points: sizes.length ? sizes[0].points : unit.points,
                quantity: 1,
                modelCount: sizes.length > 1 ? sizes[0].models : null,
                warlord: false,
                enhancement: null,
                enhancementPoints: 0,
                category: unit.category,
                group: unit.group,
                groupLabel: unit.groupLabel,
                groupOrder: unit.groupOrder,
                statsData: unit.statsData,
            }));
        },

        removeUnit(uid) {
            this.addedUnits = this.addedUnits.filter(u => u.uid !== uid);
        },

        setQuantity(unit, event) {
            const quantity = Math.max(1, Math.min(99, parseInt(event.target.value, 10) || 1));
            event.target.value = quantity;
            this.tryApply(`Quantité refusée pour ${unit.name}`, units => { units.find(u => u.uid === unit.uid).quantity = quantity; });
        },

        setSize(unit, event) {
            const models = parseInt(event.target.value, 10);
            const size = this.sizesOf(unit).find(o => o.models === models);
            if (!size) return;
            const applied = this.tryApply(`Taille refusée pour ${unit.name}`, units => {
                const target = units.find(u => u.uid === unit.uid);
                target.modelCount = size.models;
                target.points = size.points;
            });
            if (!applied) event.target.value = unit.modelCount ?? '';
        },

        toggleWarlord(unit) {
            if (unit.warlord) {
                this.addedUnits = this.addedUnits.map(u => (u.uid === unit.uid ? { ...u, warlord: false } : u));
                return;
            }
            // A free list moves the role; an official list refuses a second Warlord
            this.tryApply('Seigneur de guerre refusé', units => {
                for (const u of units) {
                    if (!this.isOfficial) u.warlord = false;
                    if (u.uid === unit.uid) u.warlord = true;
                }
            });
        },

        setEnhancement(unit, event) {
            const name = event.target.value;
            const enhancement = this.detachmentEnhancements.find(e => e.name === name) || null;
            const applied = this.tryApply(`Amélioration refusée pour ${unit.name}`, units => {
                const target = units.find(u => u.uid === unit.uid);
                target.enhancement = enhancement ? enhancement.name : null;
                target.enhancementPoints = enhancement ? enhancement.points : 0;
            });
            if (!applied) event.target.value = unit.enhancement || '';
        },

        onBattleSizeChange(event) {
            const value = event.target.value;
            const limit = value === '' ? null : Number(value);
            // Becoming official: a quantity greater than 1 becomes separate units
            const split = units => {
                if (!limit) return;
                const expanded = [];
                for (const u of units) {
                    for (let copy = 0; copy < u.quantity; copy++) {
                        expanded.push(copy === 0 ? { ...u, quantity: 1 } : { ...u, uid: nextUid++, armyUnitId: undefined, quantity: 1, warlord: false, enhancement: null, enhancementPoints: 0, factionUnitId: u.factionUnitId || this.factionUnitIdOf(u) });
                    }
                }
                units.splice(0, units.length, ...expanded);
            };
            if (this.tryApply('Changement de type de liste impossible', split, { limit })) {
                this.battleSize = value;
                return;
            }
            // Refused: the radio of the current type is checked again
            const current = this.$root.querySelector(`input[name="battleSize"][value="${this.battleSize}"]`);
            if (current) current.checked = true;
        },

        onDetachmentChange() {
            const allowed = new Set(this.detachmentEnhancements.map(e => e.name));
            const removed = this.addedUnits.filter(u => u.enhancement && !allowed.has(u.enhancement));
            if (!removed.length) return;
            this.addedUnits = this.addedUnits.map(u => (u.enhancement && !allowed.has(u.enhancement) ? { ...u, enhancement: null, enhancementPoints: 0 } : u));
            this.showRuleAlert('Améliorations retirées', removed.map(u => `${u.name} : « ${u.enhancement} » n'appartient pas au nouveau détachement.`));
        },

        // Catalogue id of a saved unit (needed to create its copies), found by name
        factionUnitIdOf(unit) {
            const match = this.availableUnits.find(u => u.name === unit.name);
            return match ? match.id : undefined;
        },

        // ─── Datasheet window ────────────────────────────────────

        async openDatasheet(unit, fromCatalogue = false) {
            const url = !fromCatalogue && unit.armyUnitId
                ? `${unitDatasheetUrl}?unit=${encodeURIComponent(unit.armyUnitId)}`
                : `${datasheetUrl}?unit=${encodeURIComponent(fromCatalogue ? unit.id : (unit.factionUnitId || this.factionUnitIdOf(unit)))}`;
            this.datasheet = { title: unit.name, html: '', loading: true };
            this.$refs.datasheetDialog.showModal();
            try {
                const response = await fetch(url, { headers: { Accept: 'text/html' } });
                this.datasheet.html = response.ok ? await response.text() : '<p class="text-muted">Fiche indisponible.</p>';
            } catch (e) {
                this.datasheet.html = '<p class="text-muted">Fiche indisponible (connexion perdue).</p>';
            } finally {
                this.datasheet.loading = false;
            }
        },

        // ─── Payload and loading ─────────────────────────────────

        get unitsPayload() {
            // The server reads names, points and profiles from the database: only ids and choices are sent
            return JSON.stringify(this.addedUnits.map(u => ({
                ...(u.armyUnitId ? { armyUnitId: u.armyUnitId } : { factionUnitId: u.factionUnitId }),
                quantity: u.quantity,
                modelCount: u.modelCount,
                warlord: u.warlord === true,
                enhancement: u.enhancement || null,
            })));
        },

        fetchFaction(faction) {
            const encoded = encodeURIComponent(faction);
            return Promise.all([
                fetch(unitsUrl.replace('__FACTION__', encoded)),
                fetch(detachmentsUrl.replace('__FACTION__', encoded)),
                fetch(enhancementsUrl.replace('__FACTION__', encoded)),
            ]);
        },

        async loadFactionData() {
            this.loadingUnits = true;
            try {
                const [unitsRes, detsRes, enhRes] = await this.fetchFaction(this.selectedFaction);
                this.availableUnits = await unitsRes.json();
                this.availableDetachments = await detsRes.json();
                this.enhancements = await enhRes.json();
                // Current profile and group of each unit already in the list
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

        // Creation: faction change
        async onFactionChange() {
            this.availableUnits = [];
            this.availableDetachments = [];
            this.enhancements = [];
            this.selectedDetachment = '';
            if (!this.selectedFaction) return;
            // Units of the previous faction would be refused by the server
            this.addedUnits = [];
            const faction = this.selectedFaction;
            this.loadingUnits = true;
            try {
                const [unitsRes, detsRes, enhRes] = await this.fetchFaction(faction);
                const units = await unitsRes.json();
                const detachments = await detsRes.json();
                const enhancements = await enhRes.json();
                // Obsolete response: the faction changed during the loading
                if (faction !== this.selectedFaction) return;
                this.availableUnits = units;
                this.availableDetachments = detachments;
                this.enhancements = enhancements;
            } catch (e) {
                console.error('Erreur de chargement', e);
            } finally {
                if (faction === this.selectedFaction) {
                    this.loadingUnits = false;
                }
            }
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
        enhancementsUrl: String,
        datasheetUrl: String,
        unitDatasheetUrl: String,
        faction: String,
        detachment: String,
        battleSize: Number,
        initialUnits: Array,
        groups: Array,
        rules: Object,
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
            enhancementsUrl: this.enhancementsUrlValue,
            datasheetUrl: this.datasheetUrlValue,
            unitDatasheetUrl: this.unitDatasheetUrlValue,
            faction: this.factionValue,
            detachment: this.detachmentValue,
            battleSize: this.battleSizeValue,
            initialUnits: this.initialUnitsValue,
            groups: this.groupsValue,
            rules: this.rulesValue,
        });
        this.templateTarget.after(root);
    }

    removeRendered() {
        [...this.element.children].forEach(child => {
            if (child !== this.templateTarget) child.remove();
        });
    }
}
