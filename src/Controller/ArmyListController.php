<?php

namespace App\Controller;

use App\Army\ArmyListComposer;
use App\Army\ArmyListRules;
use App\Army\ArmyListTextFormat;
use App\Army\BattleSize;
use App\Army\UnitCategory;
use App\Entity\ArmyList;
use App\Entity\ArmyUnit;
use App\Entity\FactionUnit;
use App\Entity\User;
use App\Repository\ArmyListRepository;
use App\Repository\ArmyUnitRepository;
use App\Repository\FactionDetachementRepository;
use App\Repository\FactionEnhancementRepository;
use App\Repository\FactionUnitRepository;
use App\Security\Voter\ArmyListVoter;
use App\Service\BsDataFetcher;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/army', name: 'app_army_')]
class ArmyListController extends AbstractController
{
    private const CSRF_FORM = 'army_list';
    private const EXPLORER_PER_PAGE = 12;

    public function __construct(
        private readonly ArmyListComposer $composer,
        private readonly ArmyListRules $rules,
        private readonly EntityManagerInterface $em,
    ) {
    }

    // Liste des armées de l'utilisateur
    #[Route('/', name: 'index')]
    #[IsGranted('ROLE_USER')]
    public function index(ArmyListRepository $armyListRepository): Response
    {
        return $this->render('army/index.html.twig', [
            'lists' => $armyListRepository->findBy(['owner' => $this->getUser()], ['createdAt' => 'DESC']),
        ]);
    }

    /** Public lists of every member, filterable. */
    #[Route('/explorer', name: 'explorer', methods: ['GET'])]
    public function explorer(Request $request, ArmyListRepository $armyListRepository, FactionDetachementRepository $detachmentRepository, PaginatorInterface $paginator): Response
    {
        $faction = (string) $request->query->get('faction', '');
        $faction = array_key_exists($faction, BsDataFetcher::FACTION_FILES) ? $faction : '';
        $detachment = $faction !== '' ? trim((string) $request->query->get('detachment', '')) : '';
        $battleSize = BattleSize::tryFrom($request->query->getInt('format'));
        $search = trim((string) $request->query->get('q', ''));
        $lists = $paginator->paginate(
            $armyListRepository->createPublicSearchQuery($faction, $detachment, $battleSize, $search),
            max(1, $request->query->getInt('page', 1)),
            self::EXPLORER_PER_PAGE,
            [PaginatorInterface::SORT_FIELD_PARAMETER_NAME => null],
        );
        return $this->render('army/explorer.html.twig', [
            'lists' => $lists,
            'factionGroups' => BsDataFetcher::factionGroups(),
            'detachments' => $faction !== '' ? $detachmentRepository->findBy(['faction' => $faction], ['name' => 'ASC']) : [],
            'battleSizes' => BattleSize::cases(),
            'filters' => ['faction' => $faction, 'detachment' => $detachment, 'format' => $battleSize?->value, 'q' => $search],
        ]);
    }

    // Créer une nouvelle liste
    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CSRF_FORM, (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Jeton de sécurité invalide, merci de réessayer.');
                return $this->redirectToRoute('app_army_new');
            }
            $faction = (string) $request->request->get('faction', '');
            if (!array_key_exists($faction, BsDataFetcher::FACTION_FILES)) {
                $this->addFlash('error', 'Faction inconnue.');
                return $this->redirectToRoute('app_army_new');
            }
            /** @var User $user */
            $user = $this->getUser();
            $armyList = (new ArmyList())->setFaction($faction)->setOwner($user);
            $error = $this->composeAndCheck($request, $armyList);
            if ($error !== null) {
                $this->addFlash('error', $error);
                return $this->redirectToRoute('app_army_new');
            }
            $this->em->persist($armyList);
            $this->em->flush();
            $this->addFlash('success', 'Liste d\'armée créée avec succès !');
            return $this->redirectToRoute('app_army_show', ['id' => $armyList->getId()]);
        }
        return $this->render('army/new.html.twig', [
            'unitGroups' => UnitCategory::all(),
            'factionGroups' => BsDataFetcher::factionGroups(),
            'rulesConfig' => ArmyListRules::clientConfig(),
        ]);
    }

    #[Route('/units/{faction}', name: 'units_by_faction', methods: ['GET'])]
    public function unitsByFaction(string $faction, FactionUnitRepository $factionUnitRepository): JsonResponse
    {
        $units = $factionUnitRepository->findBy(['faction' => $faction], ['name' => 'ASC']);
        // Group computed server-side (UnitCategory): the builder only groups and sorts by `group` + `groupOrder`
        return $this->json(array_map(function (FactionUnit $unit) {
            $group = UnitCategory::resolveFromStats($unit->getCategory(), $unit->getStatsData());
            return [
                'id' => $unit->getId(),
                'name' => $unit->getNameFr() ?: $unit->getName(),
                'nameEn' => $unit->getName(),
                'points' => $unit->getPoints(),
                'category' => $unit->getCategory(),
                'group' => $group,
                'groupLabel' => UnitCategory::label($group),
                'groupOrder' => UnitCategory::order($group),
                'statsData' => $unit->getStatsData(),
            ];
        }, $units));
    }

    #[Route('/detachments/{faction}', name: 'detachments_by_faction', methods: ['GET'])]
    public function detachmentsByFaction(string $faction, FactionDetachementRepository $repo): JsonResponse
    {
        return $this->json(array_map(fn ($detachment) => [
            'id' => $detachment->getId(),
            'name' => $detachment->getNameFr() ?: $detachment->getName(),
            'nameEn' => $detachment->getName(),
        ], $repo->findBy(['faction' => $faction], ['name' => 'ASC'])));
    }

    #[Route('/enhancements/{faction}', name: 'enhancements_by_faction', methods: ['GET'])]
    public function enhancementsByFaction(string $faction, FactionEnhancementRepository $repo): JsonResponse
    {
        return $this->json(array_map(fn ($enhancement) => [
            'name' => $enhancement->getName(),
            'detachment' => $enhancement->getDetachment(),
            'points' => $enhancement->getPoints(),
            'description' => $enhancement->getDescription(),
        ], $repo->findForFaction($faction)));
    }

    /** Datasheet of a catalogue unit (HTML fragment shown in the builder window): /army/fiche?unit={id}. */
    #[Route('/fiche', name: 'datasheet', methods: ['GET'])]
    public function datasheet(Request $request, FactionUnitRepository $factionUnitRepository): Response
    {
        $factionUnit = $factionUnitRepository->find($request->query->getInt('unit')) ?? throw $this->createNotFoundException('Unité introuvable');
        return $this->render('army/_datasheet.html.twig', [
            'unit' => $this->composer->createUnit($factionUnit),
        ]);
    }

    /** Datasheet of a unit of a list, as saved in the list: /army/fiche-de-liste?unit={id}. */
    #[Route('/fiche-de-liste', name: 'unit_datasheet', methods: ['GET'])]
    public function unitDatasheet(Request $request, ArmyUnitRepository $armyUnitRepository): Response
    {
        $unit = $armyUnitRepository->find($request->query->getInt('unit')) ?? throw $this->createNotFoundException('Unité introuvable');
        $this->denyAccessUnlessGranted(ArmyListVoter::VIEW, $unit->getArmylist());
        return $this->render('army/_datasheet.html.twig', ['unit' => $unit]);
    }

    // Voir une liste
    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, ArmyListRepository $armyListRepository, FactionUnitRepository $factionUnitRepository, ArmyListTextFormat $textFormat): Response
    {
        $armyList = $armyListRepository->find($id) ?? throw $this->createNotFoundException('Liste introuvable');
        $this->denyAccessUnlessGranted(ArmyListVoter::VIEW, $armyList);
        return $this->render('army/show.html.twig', [
            'armyList' => $armyList,
            'unitGroups' => $this->groupUnits($armyList, $factionUnitRepository),
            'ruleReport' => $this->rules->check($armyList),
            'exportText' => $textFormat->export($armyList),
        ]);
    }

    /** Printable view (roster and every datasheet), to print or save as PDF from the browser. */
    #[Route('/{id}/imprimer', name: 'print', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function print(int $id, ArmyListRepository $armyListRepository, FactionUnitRepository $factionUnitRepository): Response
    {
        $armyList = $armyListRepository->find($id) ?? throw $this->createNotFoundException('Liste introuvable');
        $this->denyAccessUnlessGranted(ArmyListVoter::VIEW, $armyList);
        return $this->render('army/print.html.twig', [
            'armyList' => $armyList,
            'unitGroups' => $this->groupUnits($armyList, $factionUnitRepository),
        ]);
    }

    /** Text export, in the format of the official application. */
    #[Route('/{id}/export.txt', name: 'export_text', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function exportText(int $id, ArmyListRepository $armyListRepository, ArmyListTextFormat $textFormat, SluggerInterface $slugger): Response
    {
        $armyList = $armyListRepository->find($id) ?? throw $this->createNotFoundException('Liste introuvable');
        $this->denyAccessUnlessGranted(ArmyListVoter::VIEW, $armyList);
        $response = new Response($textFormat->export($armyList), Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=UTF-8']);
        $fileName = ($slugger->slug((string) $armyList->getName())->lower()->toString() ?: 'liste') . '.txt';
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $fileName));
        return $response;
    }

    /** Import of a list written in the text format (official application, SprueHub export). */
    #[Route('/import', name: 'import', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function import(Request $request, ArmyListTextFormat $textFormat): Response
    {
        $text = (string) $request->request->get('text', '');
        $faction = (string) $request->request->get('faction', '');
        $unmatched = [];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('army_import', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }
            /** @var User $user */
            $user = $this->getUser();
            $result = $textFormat->import($text, $faction !== '' ? $faction : null, $user);
            if (is_string($result)) {
                $this->addFlash('error', $result);
            } else {
                $unmatched = $result->unmatchedLines;
                $armyList = $result->armyList;
                $report = $this->rules->check($armyList);
                if ($report->hasErrors()) {
                    // Rule violations: the list is kept, as a free list
                    $armyList->setBattleSize(null);
                    $this->addFlash('warning', 'Liste importée en mode libre : ' . implode(' ', $report->getErrors()));
                }
                $this->em->persist($armyList);
                $this->em->flush();
                if ($unmatched === []) {
                    $this->addFlash('success', 'Liste importée ! Vérifie-la avant de la rendre publique.');
                    return $this->redirectToRoute('app_army_show', ['id' => $armyList->getId()]);
                }
                $this->addFlash('warning', sprintf('Liste importée, mais %d ligne(s) n\'ont pas été reconnues : elles sont listées ci-dessous.', count($unmatched)));
                return $this->render('army/import.html.twig', [
                    'factionGroups' => BsDataFetcher::factionGroups(),
                    'text' => $text,
                    'faction' => $faction,
                    'unmatched' => $unmatched,
                    'importedList' => $armyList,
                ]);
            }
        }
        return $this->render('army/import.html.twig', [
            'factionGroups' => BsDataFetcher::factionGroups(),
            'text' => $text,
            'faction' => $faction,
            'unmatched' => $unmatched,
            'importedList' => null,
        ]);
    }

    /** Copies a list (own or public) into the lists of the current member, as a private list. */
    #[Route('/{id}/dupliquer', name: 'duplicate', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function duplicate(int $id, Request $request, ArmyListRepository $armyListRepository): Response
    {
        $source = $armyListRepository->find($id) ?? throw $this->createNotFoundException('Liste introuvable');
        $this->denyAccessUnlessGranted(ArmyListVoter::VIEW, $source);
        if (!$this->isCsrfTokenValid('army_duplicate_' . $source->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
        /** @var User $user */
        $user = $this->getUser();
        $copy = $source->duplicateFor($user, mb_substr('Copie de ' . $source->getName(), 0, ArmyListComposer::NAME_MAX_LENGTH));
        $this->em->persist($copy);
        $this->em->flush();
        $this->addFlash('success', 'Liste copiée dans tes listes d\'armée : elle est privée tant que tu ne la publies pas.');
        return $this->redirectToRoute('app_army_edit', ['id' => $copy->getId()]);
    }

    // Modifier une liste
    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(int $id, Request $request, ArmyListRepository $armyListRepository, FactionUnitRepository $factionUnitRepository): Response
    {
        $armyList = $armyListRepository->find($id) ?? throw $this->createNotFoundException('Liste introuvable');
        $this->denyAccessUnlessGranted(ArmyListVoter::EDIT, $armyList);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CSRF_FORM, (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Jeton de sécurité invalide, merci de réessayer.');
                return $this->redirectToRoute('app_army_edit', ['id' => $armyList->getId()]);
            }
            // The faction cannot change: it is never read from the request
            $error = $this->composeAndCheck($request, $armyList);
            if ($error !== null) {
                // Nothing is flushed: the partial changes in memory are dropped
                $this->addFlash('error', $error);
                return $this->redirectToRoute('app_army_edit', ['id' => $armyList->getId()]);
            }
            $armyList->setUpdatedAt(new \DateTimeImmutable());
            $this->em->flush();
            $this->addFlash('success', 'Liste mise à jour !');
            return $this->redirectToRoute('app_army_show', ['id' => $armyList->getId()]);
        }
        // Units of the list for the builder; armyUnitId lets the server find the saved unit back
        $initialUnits = [];
        foreach ($this->resolveUnitGroups($armyList, $factionUnitRepository) as $entry) {
            $unit = $entry['unit'];
            $initialUnits[] = [
                'armyUnitId' => $unit->getId(),
                'name' => $unit->getName(),
                'points' => $unit->getPoints(),
                'quantity' => $unit->getQuantity(),
                'modelCount' => $unit->getModelCount(),
                'warlord' => $unit->isWarlord(),
                'enhancement' => $unit->getEnhancementName(),
                'enhancementPoints' => $unit->getEnhancementPoints(),
                'category' => $unit->getCategory(),
                'group' => $entry['group'],
                'groupLabel' => UnitCategory::label($entry['group']),
                'groupOrder' => UnitCategory::order($entry['group']),
                'statsData' => $entry['statsData'],
            ];
        }
        return $this->render('army/edit.html.twig', [
            'armyList' => $armyList,
            'initialUnits' => $initialUnits,
            'unitGroups' => UnitCategory::all(),
            'rulesConfig' => ArmyListRules::clientConfig(),
        ]);
    }

    // Supprimer une liste
    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function delete(int $id, Request $request, ArmyListRepository $armyListRepository): Response
    {
        $armyList = $armyListRepository->find($id) ?? throw $this->createNotFoundException('Liste introuvable');
        $this->denyAccessUnlessGranted(ArmyListVoter::DELETE, $armyList);
        if (!$this->isCsrfTokenValid('army_delete_' . $armyList->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide, merci de réessayer.');
            return $this->redirectToRoute('app_army_show', ['id' => $armyList->getId()]);
        }
        $this->em->remove($armyList);
        $this->em->flush();
        $this->addFlash('success', 'Liste supprimée.');
        return $this->redirectToRoute('app_army_index');
    }

    /**
     * Applies the form, then the matched play rules of an official list.
     *
     * @return string|null error message, or null when the list can be saved
     */
    private function composeAndCheck(Request $request, ArmyList $armyList): ?string
    {
        $error = $this->composer->apply($request, $armyList);
        if ($error !== null) {
            return $error;
        }
        $report = $this->rules->check($armyList);
        return $report->hasErrors() ? 'Liste officielle non conforme : ' . implode(' ', $report->getErrors()) : null;
    }

    /**
     * Units of a list grouped by type (UnitCategory), empty groups excluded, in display order.
     *
     * @return list<array<string, mixed>>
     */
    private function groupUnits(ArmyList $armyList, FactionUnitRepository $factionUnitRepository): array
    {
        $unitGroups = [];
        foreach ($this->resolveUnitGroups($armyList, $factionUnitRepository) as $entry) {
            $key = $entry['group'];
            $unit = $entry['unit'];
            $unitGroups[$key] ??= ['key' => $key, 'units' => [], 'count' => 0, 'points' => 0] + UnitCategory::GROUPS[$key];
            $unitGroups[$key]['units'][] = $unit;
            $unitGroups[$key]['count'] += $unit->getQuantity();
            $unitGroups[$key]['points'] += $unit->getTotalPoints();
        }
        uksort($unitGroups, fn (string $a, string $b) => UnitCategory::order($a) <=> UnitCategory::order($b));
        return array_values($unitGroups);
    }

    /**
     * Group (UnitCategory) of each unit of a list, in list order.
     * Keywords come from the statsData copied when saving; older units without statsData are matched to their
     * FactionUnit (English or French name) in ONE query; otherwise the saved category is used.
     *
     * @return list<array{unit: ArmyUnit, group: string, statsData: ?array}>
     */
    private function resolveUnitGroups(ArmyList $armyList, FactionUnitRepository $repo): array
    {
        $missing = [];
        foreach ($armyList->getUnits() as $unit) {
            if (!is_array($unit->getStatsData()['keywords'] ?? null)) {
                $missing[] = $unit->getName();
            }
        }
        $byName = [];
        if ($missing !== [] && $armyList->getFaction() !== null) {
            $matches = $repo->createQueryBuilder('f')
                ->where('f.faction = :faction')
                ->andWhere('f.name IN (:names) OR f.nameFr IN (:names)')
                ->setParameter('faction', $armyList->getFaction())
                ->setParameter('names', array_values(array_unique($missing)))
                ->getQuery()
                ->getResult();
            foreach ($matches as $match) {
                // The exact English name wins over the French one
                if ($match->getNameFr() !== null) {
                    $byName[$match->getNameFr()] ??= $match;
                }
                $byName[$match->getName()] = $match;
            }
        }
        $entries = [];
        foreach ($armyList->getUnits() as $unit) {
            $statsData = $unit->getStatsData();
            $lookupStats = isset($byName[$unit->getName()]) ? $byName[$unit->getName()]->getStatsData() : null;
            $entries[] = [
                'unit' => $unit,
                'group' => UnitCategory::resolveFromStats($unit->getCategory(), is_array($statsData['keywords'] ?? null) ? $statsData : $lookupStats),
                'statsData' => $statsData ?? $lookupStats,
            ];
        }
        return $entries;
    }
}
