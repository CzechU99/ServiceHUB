<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\Rezerwacje;
use App\Form\RezerwacjaFormType;
use App\Form\WyszukiwanieFormType;
use App\Repository\UserRepository;
use App\Repository\UslugiRepository;
use App\Service\ReservationService;
use App\Service\ObservedService;
use App\Service\ServiceFilterService;
use App\Form\SzybkieSzukanieFormType;
use App\Repository\KategorieRepository;
use App\Repository\RezerwacjeRepository;
use App\Repository\ObserwowaneRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class MainController extends AbstractController
{
    #[Route('/main', name: 'app_main')]
    public function index(
        UserRepository $userRepository,
        UslugiRepository $serviceRepository,
        RezerwacjeRepository $reservationRepository,
    ): Response
    {
        $allServices = $serviceRepository->findAll();
        $users = $userRepository->findAll();
        $reservations = $reservationRepository->findAll();

        $shuffledServices = $serviceRepository->findAll();
        shuffle($shuffledServices);
        $randomServices = array_slice($shuffledServices, 0, 4);

        $quickSearchForm = $this->createForm(SzybkieSzukanieFormType::class);

        return $this->render('main/index.html.twig', [
            'quickSearchForm' => $quickSearchForm->createView(),
            'users' => $users,
            'allServices' => $allServices,
            'reservations' => $reservations,
            'randomServices' => $randomServices
        ]);
    }

    #[Route('/skills_list', name: 'app_skills_list')]
    public function skillsList(
        UslugiRepository $serviceRepository,
        Request $request,
        KategorieRepository $categoryRepository,
        ObserwowaneRepository $observedRepository,
        ServiceFilterService $serviceFilterService,
    ): Response
    {
        $searchForm = $this->createForm(WyszukiwanieFormType::class);

        $quickSearchForm = $this->createForm(SzybkieSzukanieFormType::class);
        $quickSearchForm->handleRequest($request);

        $user = $this->getUser();

        $allCategories = $categoryRepository->findAll();

        if ($quickSearchForm->isSubmitted() && $quickSearchForm->isValid()) {
            $searchTerm = $quickSearchForm->get('nazwaUslugi')->getData();
        } else {
            $searchTerm = '';
        }

        $filteredServices = $serviceFilterService->getServicesForSkillsList(
            $serviceRepository,
            $user instanceof User ? $user : null,
            $searchTerm
        );

        if($user instanceof User){
            $observedByUser = $observedRepository->findBy(['uzytkownik' => $user->getId()]);
        }else{
            $observedByUser = [];
        }

        $searchForm->get('nazwaUslugi')->setData($searchTerm);

        return $this->render('main/services_list.html.twig', [
            'searchForm' => $searchForm->createView(),
            'services' => $filteredServices,
            'searchTerm' => $searchTerm,
            'categories' => $allCategories,
            'observedItems' => $observedByUser,
        ]);
    }

    #[Route('/filtrowane_uslugi_list', name: 'app_filtrowane_uslugi_list')]
    public function filteredServicesList(
        UslugiRepository $serviceRepository,
        Request $request,
        KategorieRepository $categoryRepository,
        ServiceFilterService $serviceFilterService,
    ): Response
    {
        $searchForm = $this->createForm(WyszukiwanieFormType::class);
        $searchForm->handleRequest($request);

        $filteredServices = $serviceRepository->findAll();
        $allCategories = $categoryRepository->findAll();

        if ($searchForm->isSubmitted() && $searchForm->isValid()) {
            $filtersData = $searchForm->getData();
            $filteredServices = $serviceFilterService->getFilteredServices($filtersData, $serviceRepository);
        }

        return $this->render('main/services_list.html.twig', [
            'searchForm' => $searchForm->createView(),
            'services' => $filteredServices,
            'categories' => $allCategories,
            'observedItems' => [],
        ]);
    }

    #[Route('/filter_reset', name: 'app_filter_reset')]
    public function filterReset(
        UslugiRepository $serviceRepository,
        KategorieRepository $categoryRepository
    ): Response
    {
        $allServices = $serviceRepository->findAll();
        $allCategories = $categoryRepository->findAll();

        $searchForm = $this->createForm(WyszukiwanieFormType::class);

        return $this->render('main/services_list.html.twig', [
            'searchForm' => $searchForm->createView(),
            'services' => $allServices,
            'categories' => $allCategories,
            'observedItems' => [],
        ]);
    }

    #[Route('/zarezerwuj_usluge/{idUslugi}', name: 'app_zarezerwuj_usluge')]
    #[IsGranted('ROLE_USER')]
    public function reserveService(
        int $idUslugi,
        UslugiRepository $serviceRepository,
        Request $request,
        ReservationService $reservationService,
    ): Response
    {
        $serviceId = $idUslugi;
        $service = $serviceRepository->find($serviceId);
        $userServices = $serviceRepository->findBy(['uzytkownik' => $this->getUser()]);

        $reservation = new Rezerwacje();

        $reservationForm = $this->createForm(RezerwacjaFormType::class, $reservation, [
            'mojeUslugi' => $userServices,
        ]);
        $reservationForm->handleRequest($request);

        $servicesArray = array_map(function ($service) {
            return [
                'id' => $service->getId(),
                'nazwaUslugi' => $service->getNazwaUslugi(),
                'dataDodania' => $service->getDataDodania()->format('Y-m-d'),
                'cena' => $service->getCena(),
                'czyStawkaGodzinowa' => $service->isCzyStawkaGodzinowa(),
                'glowneZdjecie' => $service->getGlowneZdjecie(),
                'uzytkownik' => [
                    'id' => $service->getUzytkownik()->getId(),
                    'daneUzytkownika' => [
                        'miasto' => $service->getUzytkownik()->getDaneUzytkownika()->getMiasto(),
                    ],
                ],
            ];
        }, $userServices);

        if($reservationForm->get('odKiedy')->getData() == "" && $request->request->has('zarezerwuj')){
            $this->addFlash('error', 'Nie podałeś od kiedy chcesz zarezerować usługę!');
            return $this->redirectToRoute('app_zarezerwuj_usluge', ['idUslugi' => $serviceId]);
        }

        if($reservationForm->isSubmitted() && $reservationForm->isValid()){
            $reservation->setOdKiedy($reservationForm->get('odKiedy')->getData());

            $currentUser = $this->getUser();
            if (!$currentUser instanceof User) {
                throw $this->createAccessDeniedException();
            }

            $isExchange = (bool) $reservationForm->get('wymiana')->getData();
            $hasMessage = (bool) $reservationForm->get('czyWiadomosc')->getData();

            $emails = $reservationService->prepareReservationEmails(
                $reservation,
                $service,
                $currentUser,
                $serviceId,
                $isExchange,
                $hasMessage
            );

            $reservationService->sendEmails($emails['emailToProvider'], $emails['emailToCustomer']);
            $reservationService->saveReservation($reservation, $service, $currentUser);

            $this->addFlash('success', 'Rezerwacja złożona pomyślnie!');
            return $this->redirectToRoute('app_rezerwacje');
        }

        return $this->render('main/reserve_service.html.twig', [
            'reservationForm' => $reservationForm,
            'service' => $service,
            'userServices' => $userServices,
            'userServicesJson' => json_encode($servicesArray)
        ]);
    }

    #[Route('/follow/{idFollow}', name: 'app_follow')]
    #[IsGranted('ROLE_USER')]
    public function follow(
        UslugiRepository $serviceRepository,
        ObserwowaneRepository $observedRepository,
        ObservedService $observedService,
        int $idFollow,
        Request $request,
    ): Response
    {
        $followId = $idFollow;
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $isAdded = $observedService->toggleObservedService($user, $followId, $observedRepository, $serviceRepository);
        if($isAdded){
            $this->addFlash('success', 'Dodano do ulubionych!');
        }else{
            $this->addFlash('success', 'Usunięto z ulubionych!');
        }

        $referer = $request->headers->get('referer');

        if($referer && str_contains($referer, '/obserwowane')){
            return $this->redirectToRoute('app_obserwowane');
        }elseif($referer && str_contains($referer, '/service_view')){
            return $this->redirectToRoute('app_service_view', ['idUslugi' => $followId]);
        }else{
            return $this->redirectToRoute('app_skills_list');
        }
    }
}