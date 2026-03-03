<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\UslugiRepository;
use Doctrine\ORM\EntityManagerInterface;

class ServiceFilterService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private GeocoderService $geocoderService
    ) {
    }

    public function getServicesForSkillsList(UslugiRepository $serviceRepository, ?User $user, string $searchTerm = ''): array
    {
        $queryBuilder = $serviceRepository->createQueryBuilder('u');

        if ($user) {
            $queryBuilder
                ->andWhere('u.uzytkownik != :userId')
                ->setParameter('userId', $user->getId());
        }

        if ($searchTerm !== '') {
            $queryBuilder
                ->andWhere('u.nazwaUslugi LIKE :searchTerm')
                ->setParameter('searchTerm', '%' . $searchTerm . '%');
        }

        return $queryBuilder->getQuery()->getResult();
    }

    public function getFilteredServices(array $filtersData, UslugiRepository $serviceRepository): array
    {
        $servicesQuery = $serviceRepository->createQueryBuilder('u')
            ->leftJoin('u.kategorie', 'k')
            ->leftJoin('u.uzytkownik', 'us')
            ->leftJoin('us.daneUzytkownika', 'd')
            ->where('u.nazwaUslugi LIKE :nazwa')
            ->setParameter('nazwa', '%' . ($filtersData['nazwaUslugi'] ?? '') . '%');

        if (!empty($filtersData['cenaMin'])) {
            $servicesQuery->andWhere('u.cena >= :cenaMin')
                ->setParameter('cenaMin', $filtersData['cenaMin']);
        }

        if (!empty($filtersData['cenaMax'])) {
            $servicesQuery->andWhere('u.cena <= :cenaMax')
                ->setParameter('cenaMax', $filtersData['cenaMax']);
        }

        if (!empty($filtersData['kategorie'])) {
            $servicesQuery->andWhere('k.id = :kategorie')
                ->setParameter('kategorie', $filtersData['kategorie']);
        }

        if (!empty($filtersData['lokalizacja'])) {
            $userIds = $this->findUserIdsByLocation($filtersData);

            if (empty($userIds)) {
                return [];
            }

            $servicesQuery
                ->andWhere('u.uzytkownik IN (:uzytkownikIds)')
                ->setParameter('uzytkownikIds', $userIds);
        }

        return $servicesQuery->getQuery()->getResult();
    }

    private function findUserIdsByLocation(array $filtersData): array
    {
        $location = $filtersData['lokalizacja'];
        $commaPosition = strpos($location, ',');

        if ($commaPosition !== false) {
            $location = substr($location, 0, $commaPosition);
        }

        $connection = $this->entityManager->getConnection();

        if (!empty($filtersData['dystans'])) {
            $coordinates = $this->geocoderService->geocode($location);

            if (!$coordinates) {
                return [];
            }

            $sql = '
                SELECT uzytkownik_id
                FROM dane_uzytkownika
                WHERE ST_Distance_Sphere(POINT(dlugosc_geograficzna, szerokosc_geograficzna), POINT(:longitude, :latitude)) <= :distance
            ';

            $stmt = $connection->prepare($sql);

            $result = $stmt->executeQuery([
                'longitude' => $coordinates['longitude'],
                'latitude' => $coordinates['latitude'],
                'distance' => ($filtersData['dystans'] * 1000) * 0.80,
            ]);
        } else {
            $sql = 'SELECT uzytkownik_id FROM dane_uzytkownika WHERE miasto = :lokalizacja';

            $stmt = $connection->prepare($sql);

            $result = $stmt->executeQuery([
                'lokalizacja' => $location,
            ]);
        }

        $filteredUsers = $result->fetchAllAssociative();

        return array_column($filteredUsers, 'uzytkownik_id');
    }
}
