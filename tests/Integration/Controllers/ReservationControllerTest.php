<?php

namespace Tests\Integration\Controllers;

use App\Models\Reservation;
use App\Models\Space;
use App\Models\Condominium;
use App\Models\Fraction;
use Tests\Helpers\TestCase;

class ReservationControllerTest extends TestCase
{
    protected $reservationModel;
    protected $spaceModel;
    protected $condominiumModel;
    protected $fractionModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reservationModel = new Reservation();
        $this->spaceModel = new Space();
        $this->condominiumModel = new Condominium();
        $this->fractionModel = new Fraction();
        
        // Ensure session is started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Test getByUser returns empty array when database is not available
     */
    public function testGetByUserReturnsEmptyArrayWithoutDatabase(): void
    {
        // Create test user (mock data)
        $userData = $this->createUser([
            'email' => 'reservationuser@example.com',
            'name' => 'Reservation User',
            'status' => 'active'
        ]);

        // Since we never use database, getByUser should return empty array
        $reservations = $this->reservationModel->getByUser($userData['id']);
        
        $this->assertIsArray($reservations);
        $this->assertEmpty($reservations);
    }

    /**
     * Test getByCondominium returns empty array when database is not available
     */
    public function testGetByCondominiumReturnsEmptyArrayWithoutDatabase(): void
    {
        // Create test condominium (mock data)
        $condominiumData = $this->createCondominium([
            'name' => 'Reservation Condominium'
        ]);

        // Since we never use database, getByCondominium should return empty array
        $reservations = $this->reservationModel->getByCondominium($condominiumData['id'], []);
        
        $this->assertIsArray($reservations);
        $this->assertEmpty($reservations);
    }
}
