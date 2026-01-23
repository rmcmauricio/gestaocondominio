-- Script para verificar licenças na base de dados
-- Execute este script no MySQL para ver os valores de licenças

-- Ver todas as subscrições com informações de licenças
SELECT 
    s.id,
    s.user_id,
    p.name as plan_name,
    p.plan_type,
    p.license_min,
    s.used_licenses,
    s.license_limit,
    s.extra_licenses,
    s.status,
    s.created_at
FROM subscriptions s
INNER JOIN plans p ON s.plan_id = p.id
WHERE s.status IN ('active', 'trial', 'pending')
ORDER BY s.id DESC;

-- Ver subscrição específica (substitua USER_ID pelo ID do usuário)
-- SELECT 
--     s.id,
--     s.user_id,
--     u.email,
--     p.name as plan_name,
--     p.plan_type,
--     p.license_min,
--     s.used_licenses,
--     s.license_limit,
--     s.extra_licenses,
--     s.status
-- FROM subscriptions s
-- INNER JOIN plans p ON s.plan_id = p.id
-- INNER JOIN users u ON s.user_id = u.id
-- WHERE s.user_id = USER_ID
-- AND s.status IN ('active', 'trial', 'pending');

-- Verificar se license_limit está correto (deveria ser license_min + extra_licenses)
-- SELECT 
--     s.id,
--     p.license_min,
--     s.extra_licenses,
--     s.license_limit,
--     (p.license_min + COALESCE(s.extra_licenses, 0)) as should_be_limit,
--     CASE 
--         WHEN s.license_limit = (p.license_min + COALESCE(s.extra_licenses, 0)) THEN 'OK'
--         ELSE 'INCORRETO'
--     END as status
-- FROM subscriptions s
-- INNER JOIN plans p ON s.plan_id = p.id
-- WHERE s.status IN ('active', 'trial', 'pending')
-- AND p.plan_type IS NOT NULL;
