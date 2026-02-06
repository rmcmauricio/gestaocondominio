<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\Plan;
use App\Models\Promotion;
use App\Models\PlanExtraCondominiumsPricing;
use App\Models\PlanPricingTier;

class HomeController extends Controller
{
    /**
     * Convert plan features from JSON to readable format
     */
    protected function convertFeaturesToReadable(array $plans): array
    {
        $featureLabels = [
            'financas_completas' => 'Finanças Completas',
            'documentos' => 'Gestão de Documentos',
            'ocorrencias' => 'Ocorrências',
            'votacoes_online' => 'Votações Online',
            'reservas_espacos' => 'Reservas de Espaços',
            'gestao_contratos' => 'Gestão de Contratos',
            'gestao_fornecedores' => 'Gestão de Fornecedores'
        ];

        foreach ($plans as &$plan) {
            if (isset($plan['features']) && $plan['features']) {
                $features = json_decode($plan['features'], true);
                $readableFeatures = [];
                
                if (is_array($features)) {
                    foreach ($features as $key => $value) {
                        if ($value && isset($featureLabels[$key])) {
                            $readableFeatures[] = $featureLabels[$key];
                        }
                    }
                }
                
                $plan['features'] = $readableFeatures;
            }
        }
        unset($plan);
        
        return $plans;
    }

    public function index()
    {
        // Load page metadata from Metafiles
        $this->page->setPage('home');
        
        // Get plans for pricing section
        $planModel = new Plan();
        $plans = $planModel->getActivePlans();
        
        // Convert features to readable format
        $plans = $this->convertFeaturesToReadable($plans);
        
        // Get visible promotions for each plan
        $promotionModel = new Promotion();
        $planPromotions = [];
        $businessPlan = null;
        $extraCondominiumsPricing = [];
        $planPricingTiers = []; // Store pricing tiers for license-based plans
        
        foreach ($plans as $plan) {
            $visiblePromotion = $promotionModel->getVisibleForPlan($plan['id']);
            if ($visiblePromotion) {
                $planPromotions[$plan['id']] = $visiblePromotion;
            }
            
            if ($plan['slug'] === 'business') {
                $businessPlan = $plan;
                $extraCondominiumsPricingModel = new PlanExtraCondominiumsPricing();
                $extraCondominiumsPricing = $extraCondominiumsPricingModel->getByPlanId($plan['id']);
            }
            
            // Get pricing tiers for license-based plans (including condominio type)
            if (!empty($plan['plan_type'])) {
                $pricingTierModel = new PlanPricingTier();
                $tiers = $pricingTierModel->getByPlanId($plan['id'], true);
                if (!empty($tiers)) {
                    $planPricingTiers[$plan['id']] = $tiers;
                }
            }
        }
        
        $isRegistrationDisabled = defined('DISABLE_AUTH_REGISTRATION') && DISABLE_AUTH_REGISTRATION;
        
        $this->data += [
            'viewName' => 'pages/home.html.twig',
            'page' => [
                'titulo' => $this->page->titulo,
                'description' => $this->page->description,
                'keywords' => $this->page->keywords
            ],
            'plans' => $plans,
            'plan_promotions' => $planPromotions,
            'business_plan' => $businessPlan,
            'extra_condominiums_pricing' => $extraCondominiumsPricing,
            'plan_pricing_tiers' => $planPricingTiers,
            'auth_disabled_message' => $isRegistrationDisabled ? 'O registo e login estão temporariamente desativados. Por favor, utilize a demonstração para explorar o sistema.' : null,
            'is_registration_disabled' => $isRegistrationDisabled,
            'pilot_signup_error' => $_SESSION['pilot_signup_error'] ?? null,
            'pilot_signup_success' => $_SESSION['pilot_signup_success'] ?? null,
            'csrf_token' => \App\Core\Security::generateCSRFToken()
        ];
        
        // Clear error messages after displaying
        unset($_SESSION['pilot_signup_error']);
        unset($_SESSION['pilot_signup_success']);
        
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }
}

