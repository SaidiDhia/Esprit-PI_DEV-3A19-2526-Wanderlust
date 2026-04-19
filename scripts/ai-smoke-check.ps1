$ErrorActionPreference = "Stop"

Write-Host "[1/7] Starting AI services..." -ForegroundColor Cyan
docker compose up -d redis ai_anomaly ai_toxicity ai_rules | Out-Host

Write-Host "[2/7] Waiting for AI health endpoints..." -ForegroundColor Cyan
$services = @(
    @{ Name = "ai_anomaly"; Url = "http://127.0.0.1:8101/health" },
    @{ Name = "ai_toxicity"; Url = "http://127.0.0.1:8102/health" },
    @{ Name = "ai_rules"; Url = "http://127.0.0.1:8103/health" }
)

foreach ($svc in $services) {
    $ok = $false
    for ($i = 0; $i -lt 90; $i++) {
        try {
            $null = Invoke-RestMethod -Uri $svc.Url -TimeoutSec 5
            $ok = $true
            break
        } catch {
            Start-Sleep -Seconds 2
        }
    }

    if (-not $ok) {
        throw "Service $($svc.Name) is not healthy at $($svc.Url)"
    }

    Write-Host "  - $($svc.Name): ok" -ForegroundColor Green
}

Write-Host "[3/7] Running AI smoke checks..." -ForegroundColor Cyan
$anomalyBody = @{ 
    features = @{ 
        clicks_per_minute = 90; 
        time_between_actions_seconds = 0.5; 
        booking_frequency = 12; 
        cancel_booking_ratio = 0.45; 
        session_duration_minutes = 180 
    } 
} | ConvertTo-Json -Depth 4
$anomalyResult = Invoke-RestMethod -Uri "http://127.0.0.1:8101/anomaly/score" -Method Post -ContentType "application/json" -Body $anomalyBody
Write-Host "  - anomaly:" ($anomalyResult | ConvertTo-Json -Compress)

$toxicityBody = @{ text = "I hate everyone and I will ruin this app" } | ConvertTo-Json
$toxicityResult = Invoke-RestMethod -Uri "http://127.0.0.1:8102/toxicity/score" -Method Post -ContentType "application/json" -Body $toxicityBody
Write-Host "  - toxicity:" ($toxicityResult | ConvertTo-Json -Compress)

$rulesBody = @{ 
    user_id = "smoke-user";
    metrics = @{ 
        clicks_per_minute = 80; 
        booking_frequency = 8; 
        failed_logins_2m = 4; 
        cancel_booking_ratio = 0.60; 
        new_device = $true; 
        geo_jump = $true 
    }
} | ConvertTo-Json -Depth 5
$rulesResult = Invoke-RestMethod -Uri "http://127.0.0.1:8103/rules/score" -Method Post -ContentType "application/json" -Body $rulesBody
Write-Host "  - rules:" ($rulesResult | ConvertTo-Json -Compress)

Write-Host "[4/7] Linting Symfony container..." -ForegroundColor Cyan
php bin/console lint:container | Out-Host

Write-Host "[5/7] Recomputing risk scores..." -ForegroundColor Cyan
php bin/console app:risk:recompute | Out-Host

Write-Host "[6/7] Sampling risk assessment rows..." -ForegroundColor Cyan
php bin/console doctrine:query:sql "SELECT user_id, risk_score, risk_band, recommended_action, updated_at FROM risk_assessment ORDER BY risk_score DESC LIMIT 8" | Out-Host

Write-Host "[7/7] Docker service status..." -ForegroundColor Cyan
docker compose ps | Out-Host

Write-Host "AI health + smoke checks completed successfully." -ForegroundColor Green
