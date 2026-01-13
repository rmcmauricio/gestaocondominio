<?php

namespace Tests\Unit\Models;

use App\Models\Condominium;
use Tests\Helpers\TestCase;

class CondominiumTest extends TestCase
{
    protected $condominiumModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->condominiumModel = new Condominium();
    }

    /**
     * Test findById returns null when database is not available
     */
    public function testFindByIdReturnsNullWhenNoDatabase(): void
    {
        if ($this->db) {
            $this->markTestSkipped('Database is available');
        }
        
        $result = $this->condominiumModel->findById(1);
        
        $this->assertNull($result);
    }

    /**
     * Test findById returns null when condominium doesn't exist (no database)
     */
    public function testFindByIdReturnsNullWhenCondominiumDoesNotExist(): void
    {
        $result = $this->condominiumModel->findById(99999);
        
        $this->assertNull($result);
    }

    /**
     * Test create throws exception when database is not available
     */
    public function testCreateThrowsExceptionWhenNoDatabase(): void
    {
        if ($this->db) {
            $this->markTestSkipped('Database is available');
        }
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Database connection not available');
        
        $this->condominiumModel->create([
            'user_id' => 1,
            'name' => 'Test Condominium',
            'address' => 'Test Address'
        ]);
    }

    /**
     * Test create throws exception when database is not available (expected behavior)
     */
    public function testCreateThrowsExceptionWithoutDatabase(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Database connection not available');
        
        $this->condominiumModel->create([
            'user_id' => 1,
            'name' => 'New Condominium',
            'address' => '123 Main St'
        ]);
    }

    /**
     * Test getByUserId returns empty array when database is not available
     */
    public function testGetByUserIdReturnsEmptyArrayWhenNoDatabase(): void
    {
        if ($this->db) {
            $this->markTestSkipped('Database is available');
        }
        
        $result = $this->condominiumModel->getByUserId(1);
        
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test update returns false when database is not available
     */
    public function testUpdateReturnsFalseWhenNoDatabase(): void
    {
        $result = $this->condominiumModel->update(1, [
            'name' => 'Updated Name',
            'city' => 'Porto'
        ]);

        $this->assertFalse($result);
    }
}
