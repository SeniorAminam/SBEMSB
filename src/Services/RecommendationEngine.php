<?php

declare(strict_types=1);

/**
 * Project: Smart Building Energy Management Bot
 * File: src/Services/RecommendationEngine.php
 * Author: Amin Davodian (Mohammadamin Davodian)
 * Website: https://senioramin.com
 * LinkedIn: https://linkedin.com/in/SudoAmin
 * GitHub: https://github.com/SeniorAminam
 * Created: 2025-12-25
 * 
 * Purpose: Generates smart recommendations and actionable tips for a unit
 * Developed by Amin Davodian
 */

namespace SmartBuilding\Services;

class RecommendationEngine
{
    private DigitalTwinEngine $twin;
    private ForecastEngine $forecast;
    private CarbonEngine $carbon;

    public function __construct()
    {
        $this->twin = new DigitalTwinEngine();
        $this->forecast = new ForecastEngine();
        $this->carbon = new CarbonEngine();
    }

    public function getUnitRecommendations(int $unitId): array
    {
        $state = $this->twin->getState($unitId);
        $forecast = $this->forecast->getUnitMonthlyForecast($unitId);
        $todayCarbon = $this->carbon->getUnitCarbonBreakdown($unitId, 'today');
        $targetDaily = $this->carbon->getDailyTargetKg();

        $items = [];

        if (($forecast['risk'] ?? 'low') !== 'low') {
            $items[] = [
                'key' => 'risk_budget',
                'title' => 'ریسک عبور از بودجه',
                'desc' => ($forecast['risk'] === 'high')
                    ? 'پیش‌بینی می‌شود قبض این ماه از بودجه شما بیشتر شود.'
                    : 'نزدیک به سقف بودجه ماهانه هستید.',
                'action' => 'con_apply_eco',
                'action_text' => 'فعال‌سازی Eco Mode',
            ];
        }

        if ((float)($todayCarbon['total_kg'] ?? 0) > (float)$targetDaily) {
            $items[] = [
                'key' => 'carbon_over',
                'title' => 'مصرف کربن بالاتر از هدف',
                'desc' => 'امروز کربن شما بالاتر از هدف روزانه است.',
                'action' => 'con_apply_heat_down',
                'action_text' => 'کاهش دمای گرمایش',
            ];
        }

        if (($state['eco_mode'] ?? false) === false) {
            $items[] = [
                'key' => 'eco_off',
                'title' => 'Eco Mode خاموش است',
                'desc' => 'با فعال‌سازی حالت اقتصادی، مصرف کلی کاهش می‌یابد.',
                'action' => 'con_apply_eco',
                'action_text' => 'فعال‌سازی Eco Mode',
            ];
        }

        if (($state['lights_on'] ?? true) === true) {
            $items[] = [
                'key' => 'lights_on',
                'title' => 'چراغ‌ها روشن هستند',
                'desc' => 'خاموش کردن چراغ‌ها در زمان عدم حضور، مصرف برق را کم می‌کند.',
                'action' => 'con_apply_lights',
                'action_text' => 'خاموش/روشن چراغ‌ها',
            ];
        }

        $acMode = (string)($state['ac_mode'] ?? 'off');
        if ($acMode !== 'off') {
            $items[] = [
                'key' => 'ac_on',
                'title' => 'کولر فعال است',
                'desc' => 'کاهش سطح کولر در ساعات اوج مصرف باعث کاهش هزینه می‌شود.',
                'action' => 'con_apply_ac_down',
                'action_text' => 'کاهش سطح کولر',
            ];
        }

        $heatingTemp = (int)($state['heating_temp'] ?? 22);
        if ($heatingTemp >= 24) {
            $items[] = [
                'key' => 'heat_high',
                'title' => 'دمای گرمایش بالا است',
                'desc' => 'کاهش ۱ درجه می‌تواند مصرف گاز را پایین بیاورد.',
                'action' => 'con_apply_heat_down',
                'action_text' => 'کاهش دما (-1)',
            ];
        }

        if (empty($items)) {
            $items[] = [
                'key' => 'ok',
                'title' => 'وضعیت خوب است',
                'desc' => 'در حال حاضر پیشنهاد فوری وجود ندارد. عالی ادامه بده!',
                'action' => 'con_forecast',
                'action_text' => 'مشاهده پیش‌بینی',
            ];
        }

        return array_slice($items, 0, 5);
    }
}
