<?php

namespace Tests\Unit\Services;

use Tests\Helpers\TestCase;
use Tests\Helpers\DatabaseMockHelper;
use App\Services\SubscriptionService;
use App\Services\LicenseService;
use PDO;

/**
 * Acceptance Criteria Tests for License-Based Subscription System
 * 
 * These tests verify the acceptance criteria specified in the plan:
 * - Base: associar condomínio com 6 frações => used_licenses=10 (mínimo)
 * - Base: tentar associar segundo condomínio => erro
 * - Pro: associar 2 condomínios (30+40 frações) => used_licenses=70
 * - Pro: desassociar condomínio de 40 => used_licenses=30
 * - Pro com license_limit=60: tentar exceder => bloqueia (se allow_overage=false)
 * - Desassociar: condomínio fica locked e não permite operações
 */
class SubscriptionAcceptanceCriteriaTest extends TestCase
{
    protected $db;
    protected $subscriptionService;
    protected $licenseService;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Check if SQLite is available before proceeding
        $availableDrivers = PDO::getAvailableDrivers();
        if (!in_array('sqlite', $availableDrivers)) {
            $this->markTestSkipped(
                'PDO SQLite driver is not available. ' .
                'Please install/enable the pdo_sqlite PHP extension. ' .
                'Available PDO drivers: ' . implode(', ', $availableDrivers)
            );
            return;
        }
        
        // Create in-memory database
        $this->db = DatabaseMockHelper::createInMemoryDatabase();
        DatabaseMockHelper::setupSubscriptionTables($this->db);
        
        // Setup global $db for models
        global $db;
        $db = $this->db;
        
        $this->subscriptionService = new SubscriptionService();
        $this->licenseService = new LicenseService();
    }
    /**
     * Acceptance Criteria 1: Base plan with 6 fractions => used_licenses = 10 (minimum)
     * 
     * Scenario:
     * - Create Base plan subscription
     * - Associate condominium with 6 active fractions
     * - Minimum licenses = 10
     * 
     * Expected:
     * - used_licenses = 10 (not 6)
     * - charge_minimum = true
     */
    public function testBasePlanAppliesMinimumWithSixFractions(): void
    {
        // Create user
        $userId = DatabaseMockHelper::insertMockData($this->db, 'users', [
            'id' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'hashed',
            'role' => 'admin',
            'status' => 'active'
        ]);

        // Create Base plan
        $planData = DatabaseMockHelper::createMockPlan([
            'plan_type' => 'condominio',
            'license_min' => 10,
            'allow_multiple_condos' => false,
            'slug' => 'condominio'
        ]);
        $planId = DatabaseMockHelper::insertMockData($this->db, 'plans', $planData);

        // Create subscription
        $subscriptionData = DatabaseMockHelper::createMockSubscription($userId, $planId, [
            'condominium_id' => null,
            'used_licenses' => 0,
            'charge_minimum' => true
        ]);
        $subscriptionId = DatabaseMockHelper::insertMockData($this->db, 'subscriptions', $subscriptionData);

        // Create condominium
        $condoData = DatabaseMockHelper::createMockCondominium($userId, [
            'subscription_id' => $subscriptionId
        ]);
        $condoId = DatabaseMockHelper::insertMockData($this->db, 'condominiums', $condoData);

        // Update subscription with condominium_id
        $this->db->prepare("UPDATE subscriptions SET condominium_id = :condo_id WHERE id = :id")
            ->execute([':condo_id' => $condoId, ':id' => $subscriptionId]);

        // Create 6 active fractions
        for ($i = 1; $i <= 6; $i++) {
            $fractionData = DatabaseMockHelper::createMockFraction($condoId, [
                'identifier' => "A{$i}",
                'is_active' => true
            ]);
            DatabaseMockHelper::insertMockData($this->db, 'fractions', $fractionData);
        }

        // Recalculate licenses
        $this->licenseService->recalculateAndUpdate($subscriptionId);

        // Verify used_licenses = 10 (minimum applied)
        $stmt = $this->db->prepare("SELECT used_licenses, charge_minimum FROM subscriptions WHERE id = :id");
        $stmt->execute([':id' => $subscriptionId]);
        $subscription = $stmt->fetch();

        $this->assertEquals(10, (int)$subscription['used_licenses'], 'Base plan should apply minimum of 10 licenses');
        $this->assertTrue((bool)$subscription['charge_minimum'], 'charge_minimum should be true');
    }

    /**
     * Acceptance Criteria 2: Base plan cannot attach second condominium
     * 
     * Scenario:
     * - Base plan subscription already has one condominium
     * - Try to attach second condominium
     * 
     * Expected:
     * - Exception: "Plano Base permite apenas um condomínio"
     */
    public function testBasePlanCannotAttachSecondCondominium(): void
    {
        // Create user
        $userId = DatabaseMockHelper::insertMockData($this->db, 'users', [
            'id' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'hashed',
            'role' => 'admin',
            'status' => 'active'
        ]);

        // Create Base plan
        $planData = DatabaseMockHelper::createMockPlan([
            'plan_type' => 'condominio',
            'license_min' => 10,
            'allow_multiple_condos' => false,
            'slug' => 'condominio'
        ]);
        $planId = DatabaseMockHelper::insertMockData($this->db, 'plans', $planData);

        // Create subscription with first condominium
        $condo1Data = DatabaseMockHelper::createMockCondominium($userId);
        $condo1Id = DatabaseMockHelper::insertMockData($this->db, 'condominiums', $condo1Data);

        $subscriptionData = DatabaseMockHelper::createMockSubscription($userId, $planId, [
            'condominium_id' => $condo1Id
        ]);
        $subscriptionId = DatabaseMockHelper::insertMockData($this->db, 'subscriptions', $subscriptionData);

        // Create second condominium
        $condo2Data = DatabaseMockHelper::createMockCondominium($userId, ['name' => 'Second Condominium']);
        $condo2Id = DatabaseMockHelper::insertMockData($this->db, 'condominiums', $condo2Data);

        // Try to attach second condominium - should throw exception
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Plano Base permite apenas um condomínio');
        
        $this->subscriptionService->attachCondominium($subscriptionId, $condo2Id, $userId);
    }

    /**
     * Acceptance Criteria 3: Pro plan with 2 condominiums (30+40) => used_licenses = 70
     * 
     * Scenario:
     * - Create Professional plan subscription
     * - Attach condominium 1 with 30 active fractions
     * - Attach condominium 2 with 40 active fractions
     * - Minimum licenses = 50
     * 
     * Expected:
     * - used_licenses = 70 (30 + 40)
     */
    public function testProPlanSumsAllCondominiums(): void
    {
        // Create user
        $userId = DatabaseMockHelper::insertMockData($this->db, 'users', [
            'id' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'hashed',
            'role' => 'admin',
            'status' => 'active'
        ]);

        // Create Professional plan
        $planData = DatabaseMockHelper::createMockPlan([
            'plan_type' => 'professional',
            'license_min' => 50,
            'allow_multiple_condos' => true,
            'slug' => 'professional'
        ]);
        $planId = DatabaseMockHelper::insertMockData($this->db, 'plans', $planData);

        // Create subscription
        $subscriptionData = DatabaseMockHelper::createMockSubscription($userId, $planId, [
            'used_licenses' => 0
        ]);
        $subscriptionId = DatabaseMockHelper::insertMockData($this->db, 'subscriptions', $subscriptionData);

        // Create condominium 1 with 30 fractions
        $condo1Data = DatabaseMockHelper::createMockCondominium($userId, ['name' => 'Condo 1']);
        $condo1Id = DatabaseMockHelper::insertMockData($this->db, 'condominiums', $condo1Data);
        
        for ($i = 1; $i <= 30; $i++) {
            $fractionData = DatabaseMockHelper::createMockFraction($condo1Id, [
                'identifier' => "A{$i}",
                'is_active' => true
            ]);
            DatabaseMockHelper::insertMockData($this->db, 'fractions', $fractionData);
        }

        // Create condominium 2 with 40 fractions
        $condo2Data = DatabaseMockHelper::createMockCondominium($userId, ['name' => 'Condo 2']);
        $condo2Id = DatabaseMockHelper::insertMockData($this->db, 'condominiums', $condo2Data);
        
        for ($i = 1; $i <= 40; $i++) {
            $fractionData = DatabaseMockHelper::createMockFraction($condo2Id, [
                'identifier' => "B{$i}",
                'is_active' => true
            ]);
            DatabaseMockHelper::insertMockData($this->db, 'fractions', $fractionData);
        }

        // Attach both condominiums
        $this->subscriptionService->attachCondominium($subscriptionId, $condo1Id, $userId);
        $this->subscriptionService->attachCondominium($subscriptionId, $condo2Id, $userId);

        // Verify used_licenses = 70
        $stmt = $this->db->prepare("SELECT used_licenses FROM subscriptions WHERE id = :id");
        $stmt->execute([':id' => $subscriptionId]);
        $subscription = $stmt->fetch();

        $this->assertEquals(70, (int)$subscription['used_licenses'], 'Professional plan should sum all condominiums: 30 + 40 = 70');
    }

    /**
     * Acceptance Criteria 4: Pro plan detach condominium with 40 => used_licenses = 30
     * 
     * Scenario:
     * - Professional plan subscription with 2 condominiums (30+40 = 70 licenses)
     * - Detach condominium with 40 fractions
     * 
     * Expected:
     * - used_licenses = 30 (after recalculation)
     */
    public function testProPlanRecalculatesAfterDetach(): void
    {
        // Create user
        $userId = DatabaseMockHelper::insertMockData($this->db, 'users', [
            'id' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'hashed',
            'role' => 'admin',
            'status' => 'active'
        ]);

        // Create Professional plan
        $planData = DatabaseMockHelper::createMockPlan([
            'plan_type' => 'professional',
            'license_min' => 50,
            'allow_multiple_condos' => true,
            'slug' => 'professional'
        ]);
        $planId = DatabaseMockHelper::insertMockData($this->db, 'plans', $planData);

        // Create subscription
        $subscriptionData = DatabaseMockHelper::createMockSubscription($userId, $planId);
        $subscriptionId = DatabaseMockHelper::insertMockData($this->db, 'subscriptions', $subscriptionData);

        // Create condominium 1 with 30 fractions
        $condo1Data = DatabaseMockHelper::createMockCondominium($userId, ['name' => 'Condo 1']);
        $condo1Id = DatabaseMockHelper::insertMockData($this->db, 'condominiums', $condo1Data);
        
        for ($i = 1; $i <= 30; $i++) {
            $fractionData = DatabaseMockHelper::createMockFraction($condo1Id, [
                'identifier' => "A{$i}",
                'is_active' => true
            ]);
            DatabaseMockHelper::insertMockData($this->db, 'fractions', $fractionData);
        }

        // Create condominium 2 with 40 fractions
        $condo2Data = DatabaseMockHelper::createMockCondominium($userId, ['name' => 'Condo 2']);
        $condo2Id = DatabaseMockHelper::insertMockData($this->db, 'condominiums', $condo2Data);
        
        for ($i = 1; $i <= 40; $i++) {
            $fractionData = DatabaseMockHelper::createMockFraction($condo2Id, [
                'identifier' => "B{$i}",
                'is_active' => true
            ]);
            DatabaseMockHelper::insertMockData($this->db, 'fractions', $fractionData);
        }

        // Attach both condominiums
        $this->subscriptionService->attachCondominium($subscriptionId, $condo1Id, $userId);
        $this->subscriptionService->attachCondominium($subscriptionId, $condo2Id, $userId);

        // Verify initial count is 70
        $stmt = $this->db->prepare("SELECT used_licenses FROM subscriptions WHERE id = :id");
        $stmt->execute([':id' => $subscriptionId]);
        $subscription = $stmt->fetch();
        $this->assertEquals(70, (int)$subscription['used_licenses'], 'Initial count should be 70');

        // Detach condominium with 40 fractions
        $this->subscriptionService->detachCondominium($subscriptionId, $condo2Id, $userId, 'Test detach');

        // Verify used_licenses = 30 after detach
        $stmt = $this->db->prepare("SELECT used_licenses FROM subscriptions WHERE id = :id");
        $stmt->execute([':id' => $subscriptionId]);
        $subscription = $stmt->fetch();

        $this->assertEquals(30, (int)$subscription['used_licenses'], 'After detaching 40-fraction condominium, should have 30 licenses');
    }

    /**
     * Acceptance Criteria 5: Pro plan with license_limit=60 cannot exceed (allow_overage=false)
     * 
     * Scenario:
     * - Professional plan subscription with license_limit=60
     * - Current licenses = 60
     * - Try to activate new fraction or attach condominium
     * - allow_overage = false
     * 
     * Expected:
     * - Operation blocked
     * - Error message about exceeding limit
     */
    public function testProPlanBlocksExceedingLimitWithoutOverage(): void
    {
        // Create user
        $userId = DatabaseMockHelper::insertMockData($this->db, 'users', [
            'id' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'hashed',
            'role' => 'admin',
            'status' => 'active'
        ]);

        // Create Professional plan
        $planData = DatabaseMockHelper::createMockPlan([
            'plan_type' => 'professional',
            'license_min' => 50,
            'allow_multiple_condos' => true,
            'slug' => 'professional'
        ]);
        $planId = DatabaseMockHelper::insertMockData($this->db, 'plans', $planData);

        // Create subscription with license_limit=60, used_licenses=60, allow_overage=false
        $subscriptionData = DatabaseMockHelper::createMockSubscription($userId, $planId, [
            'used_licenses' => 60,
            'license_limit' => 60,
            'allow_overage' => false
        ]);
        $subscriptionId = DatabaseMockHelper::insertMockData($this->db, 'subscriptions', $subscriptionData);

        // Create condominium with 5 active fractions (would exceed limit)
        $condoData = DatabaseMockHelper::createMockCondominium($userId);
        $condoId = DatabaseMockHelper::insertMockData($this->db, 'condominiums', $condoData);
        
        for ($i = 1; $i <= 5; $i++) {
            $fractionData = DatabaseMockHelper::createMockFraction($condoId, [
                'identifier' => "A{$i}",
                'is_active' => true
            ]);
            DatabaseMockHelper::insertMockData($this->db, 'fractions', $fractionData);
        }

        // Try to attach condominium - should throw exception
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Excederia o limite');
        
        $this->subscriptionService->attachCondominium($subscriptionId, $condoId, $userId);
    }

    /**
     * Acceptance Criteria 6: Detached condominium is locked
     * 
     * Scenario:
     * - Detach condominium from subscription
     * 
     * Expected:
     * - Condominium subscription_status = 'locked'
     * - Condominium locked_at is set
     * - Condominium locked_reason is set
     */
    public function testDetachedCondominiumIsLocked(): void
    {
        // Create user
        $userId = DatabaseMockHelper::insertMockData($this->db, 'users', [
            'id' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'hashed',
            'role' => 'admin',
            'status' => 'active'
        ]);

        // Create Professional plan
        $planData = DatabaseMockHelper::createMockPlan([
            'plan_type' => 'professional',
            'license_min' => 50,
            'allow_multiple_condos' => true,
            'slug' => 'professional'
        ]);
        $planId = DatabaseMockHelper::insertMockData($this->db, 'plans', $planData);

        // Create subscription
        $subscriptionData = DatabaseMockHelper::createMockSubscription($userId, $planId);
        $subscriptionId = DatabaseMockHelper::insertMockData($this->db, 'subscriptions', $subscriptionData);

        // Create two condominiums
        $condo1Data = DatabaseMockHelper::createMockCondominium($userId, ['name' => 'Condo 1']);
        $condo1Id = DatabaseMockHelper::insertMockData($this->db, 'condominiums', $condo1Data);
        
        $condo2Data = DatabaseMockHelper::createMockCondominium($userId, ['name' => 'Condo 2']);
        $condo2Id = DatabaseMockHelper::insertMockData($this->db, 'condominiums', $condo2Data);

        // Create fractions for both
        for ($i = 1; $i <= 10; $i++) {
            DatabaseMockHelper::insertMockData($this->db, 'fractions', 
                DatabaseMockHelper::createMockFraction($condo1Id, ['identifier' => "A{$i}"]));
            DatabaseMockHelper::insertMockData($this->db, 'fractions', 
                DatabaseMockHelper::createMockFraction($condo2Id, ['identifier' => "B{$i}"]));
        }

        // Attach both condominiums
        $this->subscriptionService->attachCondominium($subscriptionId, $condo1Id, $userId);
        $this->subscriptionService->attachCondominium($subscriptionId, $condo2Id, $userId);

        // Detach condominium 2
        $reason = 'Test detach reason';
        $this->subscriptionService->detachCondominium($subscriptionId, $condo2Id, $userId, $reason);

        // Verify condominium is locked
        $stmt = $this->db->prepare("SELECT subscription_status, locked_at, locked_reason FROM condominiums WHERE id = :id");
        $stmt->execute([':id' => $condo2Id]);
        $condominium = $stmt->fetch();

        $this->assertEquals('locked', $condominium['subscription_status'], 'Condominium should be locked after detach');
        $this->assertNotNull($condominium['locked_at'], 'locked_at should be set');
        $this->assertEquals($reason, $condominium['locked_reason'], 'locked_reason should match detach reason');
    }

    /**
     * Acceptance Criteria 7: Enterprise plan allows overage
     * 
     * Scenario:
     * - Enterprise plan subscription with license_limit=200
     * - Current licenses = 200
     * - allow_overage = true
     * - Try to activate new fraction
     * 
     * Expected:
     * - Operation succeeds
     * - used_licenses increases beyond limit
     */
    public function testEnterprisePlanAllowsOverage(): void
    {
        // Create user
        $userId = DatabaseMockHelper::insertMockData($this->db, 'users', [
            'id' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'hashed',
            'role' => 'admin',
            'status' => 'active'
        ]);

        // Create Enterprise plan
        $planData = DatabaseMockHelper::createMockPlan([
            'plan_type' => 'enterprise',
            'license_min' => 200,
            'allow_multiple_condos' => true,
            'slug' => 'enterprise'
        ]);
        $planId = DatabaseMockHelper::insertMockData($this->db, 'plans', $planData);

        // Create subscription with license_limit=200, used_licenses=200, allow_overage=true
        $subscriptionData = DatabaseMockHelper::createMockSubscription($userId, $planId, [
            'used_licenses' => 200,
            'license_limit' => 200,
            'allow_overage' => true
        ]);
        $subscriptionId = DatabaseMockHelper::insertMockData($this->db, 'subscriptions', $subscriptionData);

        // Create condominium with 10 active fractions (would exceed limit but overage is allowed)
        $condoData = DatabaseMockHelper::createMockCondominium($userId);
        $condoId = DatabaseMockHelper::insertMockData($this->db, 'condominiums', $condoData);
        
        for ($i = 1; $i <= 10; $i++) {
            $fractionData = DatabaseMockHelper::createMockFraction($condoId, [
                'identifier' => "A{$i}",
                'is_active' => true
            ]);
            DatabaseMockHelper::insertMockData($this->db, 'fractions', $fractionData);
        }

        // Attach condominium - should succeed because allow_overage=true
        $this->subscriptionService->attachCondominium($subscriptionId, $condoId, $userId);

        // Verify used_licenses > 200 (210 = 200 + 10)
        $stmt = $this->db->prepare("SELECT used_licenses FROM subscriptions WHERE id = :id");
        $stmt->execute([':id' => $subscriptionId]);
        $subscription = $stmt->fetch();

        $this->assertGreaterThan(200, (int)$subscription['used_licenses'], 'Enterprise plan should allow overage beyond limit');
        $this->assertEquals(210, (int)$subscription['used_licenses'], 'Used licenses should be 210 (200 + 10)');
    }
}
