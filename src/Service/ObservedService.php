<?php

namespace App\Service;

use App\Entity\Obserwowane;
use App\Entity\User;
use App\Repository\ObserwowaneRepository;
use App\Repository\UslugiRepository;
use Doctrine\ORM\EntityManagerInterface;

class ObservedService
{
  public function __construct(private EntityManagerInterface $entityManager) {}

  public function toggleObservedService(
    User $user,
    int $serviceId,
    ObserwowaneRepository $observedRepository,
    UslugiRepository $serviceRepository
  ): bool {
    $observed = $observedRepository->findOneBy([
      'uzytkownik' => $user->getId(),
      'usluga' => $serviceId,
    ]);

    if ($observed) {
      $this->entityManager->remove($observed);
      $this->entityManager->flush();

      return false;
    }

    $observed = new Obserwowane();
    $service = $serviceRepository->findOneBy(['id' => $serviceId]);

    $observed->setUzytkownik($user);
    $observed->setUsluga($service);

    $this->entityManager->persist($observed);
    $this->entityManager->flush();

    return true;
  }
}
