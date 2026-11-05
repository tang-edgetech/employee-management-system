<?php

// Every route here is served under /api/v1 (bootstrap/app.php) and rate limited
// by the "api" limiter. Endpoints arrive with their milestones: auth (M3),
// access roles and settings (M4), files (M5).
