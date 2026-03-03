<?php

namespace App\Service;

use App\Entity\Rezerwacje;
use App\Entity\Uslugi;
use App\Entity\User;
use App\Repository\DaneUzytkownikaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class ReservationService
{
  public function __construct(
    private MailerInterface $mailer,
    private EntityManagerInterface $entityManager,
    private DaneUzytkownikaRepository $daneUzytkownikaRepository
  ) {}

  public function saveReservation(Rezerwacje $reservation, Uslugi $service, User $user): void
  {
    $reservation->setUzytkownikId($user);
    $reservation->setCzyAnulowana(false);
    $reservation->setCzyPotwierdzona(false);
    $reservation->setCzyOdrzucona(false);
    $reservation->setUslugaDoRezerwacji($service);
    $reservation->setDataZlozenia(new \DateTime());

    $this->entityManager->persist($reservation);
    $this->entityManager->flush();
  }

  public function prepareReservationEmails(
    Rezerwacje $reservation,
    Uslugi $service,
    User $user,
    int $serviceId,
    bool $isExchange,
    bool $hasMessage
  ): array {
    $userData = $this->daneUzytkownikaRepository->findOneBy(['uzytkownik' => $user]);

    $toDate = '';
    if ($reservation->getDoKiedy()) {
      $toDate = ' do: ' . $reservation->getDoKiedy()->format('Y-m-d');
    }

    $providerEmailMessage = '<h2>Dokonano rezerwacji twojej usługi!</h2>'
      . $userData->getImie() . ' ' . $userData->getNazwisko()
      . ' zarezerwował: <a href="localhost/servicehub/public/index.php/service_view/' . $serviceId . '">'
      . $service->getNazwaUslugi() . '</a><br> W terminie od: '
      . $reservation->getOdKiedy()->format('Y-m-d') . $toDate;

    $customerEmailMessage = '<h2>Dokonałeś rezerwacji!</h2> Zarezerwowałeś: <a href="localhost/servicehub/public/index.php/service_view/' . $serviceId . '">'
      . $service->getNazwaUslugi() . '</a><br> W terminie od: '
      . $reservation->getOdKiedy()->format('Y-m-d') . $toDate;

    if (!$isExchange) {
      $reservation->setUslugaNaWymiane(null);
    } else {
      $providerEmailMessage .= '<br> Zaoferował wymianę w zamian za: <a href="localhost/servicehub/public/index.php/service_view/'
        . $reservation->getUslugaNaWymiane()->getId() . '">'
        . $reservation->getUslugaNaWymiane()->getNazwaUslugi() . '</a>';

      $customerEmailMessage .= '<br> W zamian za: <a href="localhost/servicehub/public/index.php/service_view/'
        . $reservation->getUslugaNaWymiane()->getId() . '">'
        . $reservation->getUslugaNaWymiane()->getNazwaUslugi() . '</a>';
    }

    if ($reservation->isUdostepnijTelefon()) {
      $providerEmailMessage .= '<br>Telefon: ' . $userData->getTelefon();
    }

    if (!$hasMessage) {
      $reservation->setWiadomosc(null);
    } else {
      $providerEmailMessage .= '<br><br><h4>Wiadomość do ciebie: </h4>' . $reservation->getWiadomosc();
    }

    return [
      'emailToProvider' => (new Email())
        ->from('nor-replay@servicehub.com')
        ->to($service->getUzytkownik()->getEmail())
        ->subject('ServiceHub - zarezerwowano twoją usługę!')
        ->text('Rezerwacja usługi')
        ->html($providerEmailMessage),
      'emailToCustomer' => (new Email())
        ->from('nor-replay@servicehub.com')
        ->to($user->getEmail())
        ->subject('ServiceHub - zarezerwołeś usługę!')
        ->text('Rezerwacja usługi')
        ->html($customerEmailMessage),
    ];
  }

  public function sendEmails(Email $providerEmail, Email $customerEmail): void
  {
    $this->mailer->send($providerEmail);
    $this->mailer->send($customerEmail);
  }
}
