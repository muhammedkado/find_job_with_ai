<?php

return [
    // This app runs as a public demo. Real Gemini/RapidAPI calls cost
    // money, so both are capped by a daily budget shared across every
    // visitor, on top of the per-IP throttle in RouteServiceProvider.
    'gemini_daily_budget' => (int) env('DEMO_GEMINI_DAILY_BUDGET', 150),
    'jsearch_daily_budget' => (int) env('DEMO_JSEARCH_DAILY_BUDGET', 10),
];
