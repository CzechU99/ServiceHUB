<?php

namespace App\Controller;

use App\Form\DaneFormType;
use App\Entity\User;
use App\Entity\DaneUzytkownika;
use App\Service\GeocoderService;
use App\Repository\UslugiRepository;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\MailerInterface;
use App\Repository\RezerwacjeRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\DaneUzytkownikaRepository;
use App\Repository\ObserwowaneRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class AccountController extends AbstractController
{
    #[Route('/profile', name: 'app_profile')]
    #[IsGranted('ROLE_USER')]
    public function profile(
      DaneUzytkownikaRepository $userDataRepository,
      Request $request,
      GeocoderService $geocoderService,
      EntityManagerInterface $entityManager
    ): Response
    {
      $userData = $userDataRepository->findOneBy(['uzytkownik' => $this->getUser()]);
      if(!$userData) {
        $userData = new DaneUzytkownika();
        $userData->setUzytkownik($this->getUser());
      }

      $profileForm = $this->createForm(DaneFormType::class, $userData);
      $profileForm->handleRequest($request);

      if ($profileForm->isSubmitted() && $profileForm->isValid()) {
        if(empty($userData->getDlugoscGeograficzna())){
          $address = sprintf('%s %s, %s', 
            $profileForm->get('kod_pocztowy')->getData(),
            $profileForm->get('miasto')->getData(),
            'Poland'
          );

          $coordinates = $geocoderService->geocode($address);

          if ($coordinates) {
            $userData->setSzerokoscGeograficzna($coordinates['latitude']);
            $userData->setDlugoscGeograficzna($coordinates['longitude']);
          }
        }
        
        $entityManager->persist($userData);
        $entityManager->flush();

        $this->addFlash('success', 'DANE ZOSTAŁY ZAPISANE');

        return $this->redirectToRoute('app_profile');
      }

      return $this->render('settings/profile.html.twig', [
        'profileForm' => $profileForm->createView(),
      ]);
    }

    #[Route('/myservices', name: 'app_myservices')]
    #[IsGranted('ROLE_USER')]
    public function myServices(
      UslugiRepository $serviceRepository,
      DaneUzytkownikaRepository $userDataRepository
    ): Response
    {
      $userServices = $serviceRepository->findBy(['uzytkownik' => $this->getUser()]);
      $userData = $userDataRepository->findOneBy(['uzytkownik' => $this->getUser()]);

      return $this->render('settings/my_services.html.twig', [
        'userServices' => $userServices,
        'userData' => $userData,
      ]);
    }

    #[Route('/rezerwacje', name: 'app_rezerwacje')]
    #[IsGranted('ROLE_USER')]
    public function reservations(
      RezerwacjeRepository $reservationRepository,
    ): Response
    {
      $queryBuilder = $reservationRepository->createQueryBuilder('r')
        ->leftJoin('r.uslugaDoRezerwacji', 'u')
        ->where('r.uzytkownikId = :userId')
        ->setParameter('userId', $this->getUser())
        ->orderBy('r.id', 'DESC');

      $queryBuilder->andWhere('r.uslugaNaWymiane IS NULL');
      $queryBuilder->orWhere('r.uslugaNaWymiane IS NOT NULL');
      $queryBuilder->orWhere('u.uzytkownik = :userId');
      $reservations = $queryBuilder->getQuery()->getResult();

      return $this->render('settings/reservations.html.twig', [
        'reservations' => $reservations
      ]);
    }

    #[Route('/rezerwacje_oczekujace', name: 'app_rezerwacje_oczekujace')]
    #[IsGranted('ROLE_USER')]
    public function pendingReservations(
      RezerwacjeRepository $reservationRepository,
    ): Response
    {
      $queryBuilder = $reservationRepository->createQueryBuilder('r')
        ->leftJoin('r.uslugaDoRezerwacji', 'u')
        ->where('r.czyOdrzucona = 0')
        ->andWhere('r.czyAnulowana = 0')
        ->andWhere('r.czyPotwierdzona = 0')
        ->andWhere('(
          r.uzytkownikId = :userId
          OR r.uslugaNaWymiane IS NULL 
          OR r.uslugaNaWymiane IS NOT NULL 
          OR u.uzytkownik = :userId
        )')
        ->setParameter('userId', $this->getUser())
        ->orderBy('r.id', 'DESC');
      
      $reservations = $queryBuilder->getQuery()->getResult();

      return $this->render('settings/reservations.html.twig', [
        'reservations' => $reservations
      ]);
    }

    #[Route('/rezerwacje_odrzucone', name: 'app_rezerwacje_odrzucone')]
    #[IsGranted('ROLE_USER')]
    public function rejectedReservations(
      RezerwacjeRepository $reservationRepository,
    ): Response
    {
      $queryBuilder = $reservationRepository->createQueryBuilder('r')
        ->leftJoin('r.uslugaDoRezerwacji', 'u')
        ->where('r.czyOdrzucona = 1')
        ->andWhere('(
          r.uzytkownikId = :userId
          OR r.uslugaNaWymiane IS NULL 
          OR r.uslugaNaWymiane IS NOT NULL 
          OR u.uzytkownik = :userId
        )')
        ->setParameter('userId', $this->getUser())
        ->orderBy('r.id', 'DESC');
      
      $reservations = $queryBuilder->getQuery()->getResult();

      return $this->render('settings/reservations.html.twig', [
        'reservations' => $reservations
      ]);
    }

    #[Route('/rezerwacje_anulowane', name: 'app_rezerwacje_anulowane')]
    #[IsGranted('ROLE_USER')]
    public function canceledReservations(
      RezerwacjeRepository $reservationRepository,
    ): Response
    {
      $queryBuilder = $reservationRepository->createQueryBuilder('r')
        ->leftJoin('r.uslugaDoRezerwacji', 'u')
        ->where('r.czyAnulowana = 1')
        ->andWhere('(
          r.uzytkownikId = :userId
          OR r.uslugaNaWymiane IS NULL 
          OR r.uslugaNaWymiane IS NOT NULL 
          OR u.uzytkownik = :userId
        )')
        ->setParameter('userId', $this->getUser())
        ->orderBy('r.id', 'DESC');
      
      $reservations = $queryBuilder->getQuery()->getResult();

      return $this->render('settings/reservations.html.twig', [
        'reservations' => $reservations
      ]);
    }

    #[Route('/rezerwacje_potwierdzone', name: 'app_rezerwacje_potwierdzone')]
    #[IsGranted('ROLE_USER')]
    public function confirmedReservations(
      RezerwacjeRepository $reservationRepository,
    ): Response
    {
      $queryBuilder = $reservationRepository->createQueryBuilder('r')
        ->leftJoin('r.uslugaDoRezerwacji', 'u')
        ->where('r.czyPotwierdzona = 1')
        ->andWhere('(
          r.uzytkownikId = :userId
          OR r.uslugaNaWymiane IS NULL 
          OR r.uslugaNaWymiane IS NOT NULL 
          OR u.uzytkownik = :userId
        )')
        ->setParameter('userId', $this->getUser())
        ->orderBy('r.id', 'DESC');
      
      $reservations = $queryBuilder->getQuery()->getResult();

      return $this->render('settings/reservations.html.twig', [
        'reservations' => $reservations
      ]);
    }

    #[Route('/rezerwacje_innych_bez', name: 'app_rezerwacje_innych_bez')]
    #[IsGranted('ROLE_USER')]
    public function othersReservationsWithoutExchange(
      RezerwacjeRepository $reservationRepository,
    ): Response
    {
      $othersReservationsWithoutExchange = $reservationRepository->createQueryBuilder('r')
        ->join('r.uslugaDoRezerwacji', 'u')
        ->where('u.uzytkownik = :userId')
        ->andWhere('r.uslugaNaWymiane IS NULL')
        ->setParameter('userId', $this->getUser())
        ->orderBy('r.id', 'DESC')
        ->getQuery()
        ->getResult();

      return $this->render('settings/reservations.html.twig', [
        'reservations' => $othersReservationsWithoutExchange,
      ]);
    }

    #[Route('/rezerwacje_innych_z', name: 'app_rezerwacje_innych_z')]
    #[IsGranted('ROLE_USER')]
    public function othersReservationsWithExchange(
      RezerwacjeRepository $reservationRepository,
    ): Response
    {
      $othersReservationsWithExchange = $reservationRepository->createQueryBuilder('r')
        ->join('r.uslugaDoRezerwacji', 'u')
        ->where('u.uzytkownik = :userId')
        ->andWhere('r.uslugaNaWymiane IS NOT NULL')
        ->setParameter('userId', $this->getUser())
        ->orderBy('r.id', 'DESC')
        ->getQuery()
        ->getResult();

      return $this->render('settings/reservations.html.twig', [
        'reservations' => $othersReservationsWithExchange,
      ]);
    }

    #[Route('/rezerwacje_ciebie_z', name: 'app_rezerwacje_ciebie_z')]
    #[IsGranted('ROLE_USER')]
    public function yourReservationsWithExchange(
      RezerwacjeRepository $reservationRepository,
    ): Response
    {
      $yourReservationsWithExchange = $reservationRepository->createQueryBuilder('r')
        ->where('r.uzytkownikId = :userId')
        ->andWhere('r.uslugaNaWymiane IS NOT NULL')
        ->setParameter('userId', $this->getUser())
        ->orderBy('r.id', 'DESC')
        ->getQuery()
        ->getResult();

      return $this->render('settings/reservations.html.twig', [
        'reservations' => $yourReservationsWithExchange,
      ]);
    }

    #[Route('/rezerwacje_ciebie_bez', name: 'app_rezerwacje_ciebie_bez')]
    #[IsGranted('ROLE_USER')]
    public function yourReservationsWithoutExchange(
      RezerwacjeRepository $reservationRepository,
    ): Response
    {
      $yourReservationsWithoutExchange = $reservationRepository->createQueryBuilder('r')
        ->where('r.uzytkownikId = :userId')
        ->andWhere('r.uslugaNaWymiane IS NULL')
        ->setParameter('userId', $this->getUser())
        ->orderBy('r.id', 'DESC')
        ->getQuery()
        ->getResult();

      return $this->render('settings/reservations.html.twig', [
        'reservations' => $yourReservationsWithoutExchange,
      ]);
    }

    #[Route('/usun_rezerwacje/{idRezerwacji}', name: 'app_usun_rezerwacje')]
    #[IsGranted('ROLE_USER')]
    public function deleteReservation(
      RezerwacjeRepository $reservationRepository,
      EntityManagerInterface $entityManager,
      int $idRezerwacji,
    ): Response
    {
      $reservationToDelete = $reservationRepository->findOneBy([
        'id' => $idRezerwacji,
        'uzytkownikId' => $this->getUser(), 
      ]);

      $entityManager->remove($reservationToDelete);
      $entityManager->flush();
      
      $this->addFlash('success', 'Rezerwacja została usunięta!');
      return $this->redirectToRoute('app_rezerwacje');
    }

    #[Route('/potwierdz_rezerwacje/{idRezerwacji}', name: 'app_potwierdz_rezerwacje')]
    #[IsGranted('ROLE_USER')]
    public function confirmReservation(
      RezerwacjeRepository $reservationRepository,
      EntityManagerInterface $entityManager,
      int $idRezerwacji,
      MailerInterface $mailer,
    ): Response
    {
      $currentUser = $this->getUser();
      if (!$currentUser instanceof User) {
        throw $this->createAccessDeniedException();
      }

      $reservationToConfirm = $reservationRepository->findOneBy([
        'id' => $idRezerwacji,
      ]);

      if($reservationToConfirm->getDoKiedy()){
        $doKiedy = " do: " . $reservationToConfirm->getDoKiedy()->format('Y-m-d');
      }else{
        $doKiedy = "";
      }

      $emailMsg = '<h2>Potwierdzono twoją rezerwację!</h2> Użytkownik potwierdził rezerwację: <a href="localhost/servicehub/public/index.php/service_view/' . $idRezerwacji . '">' . $reservationToConfirm->getUslugaDoRezerwacji()->getNazwaUslugi() . '</a><br> W terminie od: ' . $reservationToConfirm->getOdKiedy()->format('Y-m-d') . $doKiedy;

      $emailMsg2 = '<h2>Potwierdzono rezerwację!</h2> Potwierdziłeś rezerwację: <a href="localhost/servicehub/public/index.php/service_view/' . $idRezerwacji . '">' . $reservationToConfirm->getUslugaDoRezerwacji()->getNazwaUslugi() . '</a><br> W terminie od: ' . $reservationToConfirm->getOdKiedy()->format('Y-m-d') . $doKiedy;

      if($reservationToConfirm->getUslugaNaWymiane()){
        $emailMsg = $emailMsg . '<br> W zamian za: <a href="localhost/servicehub/public/index.php/service_view/' . $reservationToConfirm->getUslugaNaWymiane()->getId() . '">' . $reservationToConfirm->getUslugaNaWymiane()->getNazwaUslugi() . '</a>';

        $emailMsg2 = $emailMsg2 . '<br> W zamian za: <a href="localhost/servicehub/public/index.php/service_view/' . $reservationToConfirm->getUslugaNaWymiane()->getId() . '">' . $reservationToConfirm->getUslugaNaWymiane()->getNazwaUslugi() . '</a>';
      }

      $emailDoUslugodawcy = (new Email())
        ->from('nor-replay@servicehub.com')
        ->to($reservationToConfirm->getUzytkownikId()->getEmail())
        ->subject('ServiceHub - potwierdzono twoją rezerwację!')
        ->text('Rezerwacja usługi')
        ->html($emailMsg);

      $emailDoCiebie = (new Email())
        ->from('nor-replay@servicehub.com')
        ->to($currentUser->getEmail())
        ->subject('ServiceHub - potwierdziłeś rezerwację!')
        ->text('Rezerwacja usługi')
        ->html($emailMsg2);

      $mailer->send($emailDoUslugodawcy);
      $mailer->send($emailDoCiebie);

      $reservationToConfirm->setCzyPotwierdzona(true);
      $reservationToConfirm->setCzyOdrzucona(false);
      $reservationToConfirm->setCzyAnulowana(false);
      $entityManager->flush();
      
      $this->addFlash('success', 'Rezerwacja została potwierdzona!');
      return $this->redirectToRoute('app_rezerwacje');
    }

    #[Route('/anuluj_rezerwacje/{idRezerwacji}', name: 'app_anuluj_rezerwacje')]
    #[IsGranted('ROLE_USER')]
    public function cancelReservation(
      RezerwacjeRepository $reservationRepository,
      EntityManagerInterface $entityManager,
      MailerInterface $mailer,
      int $idRezerwacji,
    ): Response
    {
      $currentUser = $this->getUser();
      if (!$currentUser instanceof User) {
        throw $this->createAccessDeniedException();
      }

      $reservationToCancel = $reservationRepository->findOneBy([
        'id' => $idRezerwacji,
      ]);

      if($reservationToCancel->getDoKiedy()){
        $doKiedy = " do: " . $reservationToCancel->getDoKiedy()->format('Y-m-d');
      }else{
        $doKiedy = "";
      }

      $emailMsg = '<h2>Anulowano twoją rezerwację!</h2> Użytkownik anulował rezerwację: <a href="localhost/servicehub/public/index.php/service_view/' . $idRezerwacji . '">' . $reservationToCancel->getUslugaDoRezerwacji()->getNazwaUslugi() . '</a><br> W terminie od: ' . $reservationToCancel->getOdKiedy()->format('Y-m-d') . $doKiedy;

      $emailMsg2 = '<h2>Anulowano rezerwację!</h2> Anulowałeś rezerwację: <a href="localhost/servicehub/public/index.php/service_view/' . $idRezerwacji . '">' . $reservationToCancel->getUslugaDoRezerwacji()->getNazwaUslugi() . '</a><br> W terminie od: ' . $reservationToCancel->getOdKiedy()->format('Y-m-d') . $doKiedy;

      if($reservationToCancel->getUslugaNaWymiane()){
        $emailMsg = $emailMsg . '<br> W zamian za: <a href="localhost/servicehub/public/index.php/service_view/' . $reservationToCancel->getUslugaNaWymiane()->getId() . '">' . $reservationToCancel->getUslugaNaWymiane()->getNazwaUslugi() . '</a>';

        $emailMsg2 = $emailMsg2 . '<br> W zamian za: <a href="localhost/servicehub/public/index.php/service_view/' . $reservationToCancel->getUslugaNaWymiane()->getId() . '">' . $reservationToCancel->getUslugaNaWymiane()->getNazwaUslugi() . '</a>';
      }

      $emailDoUslugodawcy = (new Email())
        ->from('nor-replay@servicehub.com')
        ->to($reservationToCancel->getUzytkownikId()->getEmail())
        ->subject('ServiceHub - anulowano twoją rezerwację!')
        ->text('Rezerwacja usługi')
        ->html($emailMsg);

      $emailDoCiebie = (new Email())
        ->from('nor-replay@servicehub.com')
        ->to($currentUser->getEmail())
        ->subject('ServiceHub - anulowałeś rezerwację!')
        ->text('Rezerwacja usługi')
        ->html($emailMsg2);

      $mailer->send($emailDoUslugodawcy);
      $mailer->send($emailDoCiebie);

      $reservationToCancel->setCzyPotwierdzona(false);
      $reservationToCancel->setCzyOdrzucona(false);
      $reservationToCancel->setCzyAnulowana(true);
      $entityManager->flush();
      
      $this->addFlash('success', 'Rezerwacja została anulowana!');
      return $this->redirectToRoute('app_rezerwacje');
    }

    #[Route('/odrzuc_rezerwacje/{idRezerwacji}', name: 'app_odrzuc_rezerwacje')]
    #[IsGranted('ROLE_USER')]
    public function rejectReservation(
      RezerwacjeRepository $reservationRepository,
      EntityManagerInterface $entityManager,
      MailerInterface $mailer,
      int $idRezerwacji,
    ): Response
    {
      $currentUser = $this->getUser();
      if (!$currentUser instanceof User) {
        throw $this->createAccessDeniedException();
      }

      $reservationToReject = $reservationRepository->findOneBy([
        'id' => $idRezerwacji,
      ]);

      if($reservationToReject->getDoKiedy()){
        $doKiedy = " do: " . $reservationToReject->getDoKiedy()->format('Y-m-d');
      }else{
        $doKiedy = "";
      }

      $emailMsg = '<h2>Odrzucono twoją rezerwację!</h2> Użytkownik odrzucił rezerwację: <a href="localhost/servicehub/public/index.php/service_view/' . $idRezerwacji . '">' . $reservationToReject->getUslugaDoRezerwacji()->getNazwaUslugi() . '</a><br> W terminie od: ' . $reservationToReject->getOdKiedy()->format('Y-m-d') . $doKiedy;

      $emailMsg2 = '<h2>Odrzucono rezerwację!</h2> Odrzuciłeś rezerwację: <a href="localhost/servicehub/public/index.php/service_view/' . $idRezerwacji . '">' . $reservationToReject->getUslugaDoRezerwacji()->getNazwaUslugi() . '</a><br> W terminie od: ' . $reservationToReject->getOdKiedy()->format('Y-m-d') . $doKiedy;

      if($reservationToReject->getUslugaNaWymiane()){
        $emailMsg = $emailMsg . '<br> W zamian za: <a href="localhost/servicehub/public/index.php/service_view/' . $reservationToReject->getUslugaNaWymiane()->getId() . '">' . $reservationToReject->getUslugaNaWymiane()->getNazwaUslugi() . '</a>';

        $emailMsg2 = $emailMsg2 . '<br> W zamian za: <a href="localhost/servicehub/public/index.php/service_view/' . $reservationToReject->getUslugaNaWymiane()->getId() . '">' . $reservationToReject->getUslugaNaWymiane()->getNazwaUslugi() . '</a>';
      }

      $emailDoUslugodawcy = (new Email())
        ->from('nor-replay@servicehub.com')
        ->to($reservationToReject->getUzytkownikId()->getEmail())
        ->subject('ServiceHub - odrzucono twoją rezerwację!')
        ->text('Rezerwacja usługi')
        ->html($emailMsg);

      $emailDoCiebie = (new Email())
        ->from('nor-replay@servicehub.com')
        ->to($currentUser->getEmail())
        ->subject('ServiceHub - odrzuciłeś rezerwację!')
        ->text('Rezerwacja usługi')
        ->html($emailMsg2);

      $mailer->send($emailDoUslugodawcy);
      $mailer->send($emailDoCiebie);

      $reservationToReject->setCzyPotwierdzona(false);
      $reservationToReject->setCzyAnulowana(false);
      $reservationToReject->setCzyOdrzucona(true);
      $entityManager->flush();
      
      $this->addFlash('success', 'Rezerwacja została odrzucona!');
      return $this->redirectToRoute('app_rezerwacje');
    }

    #[Route('/obserwowane', name: 'app_obserwowane')]
    #[IsGranted('ROLE_USER')]
    public function observed(
      ObserwowaneRepository $observedRepository,
      UslugiRepository $serviceRepository,
    ): Response
    {
      $user = $this->getUser();
      $observedByUser = $observedRepository->findBy(['uzytkownik' => $user]);
      $serviceIds = array_map(fn($observation) => $observation->getUsluga()->getId(), $observedByUser);
      $observedServices = $serviceRepository->findBy(['id' => $serviceIds]);

      return $this->render('settings/observed.html.twig', [
        'observedServices' => $observedServices,
      ]);
    }
}
